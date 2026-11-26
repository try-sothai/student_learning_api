<?php

namespace App\Services;

use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Server-side quiz grading, snapshotting, manual Short Answer grading, and attempt scoring engine.
 *
 *  - multiple_choice / mcq / true_false: auto-graded strictly server-side against snapshotted answer keys.
 *  - short_answer: submitted responses are always marked pending_grading for manual teacher review
 *    (supports expected answer/grading guidance, partial marks 0..max, teacher feedback, and audit fields).
 *  - Final score is never exposed on an attempt while any manual grading remains pending.
 */
class QuizGradingService
{
    /**
     * Build immutable question snapshots for a quiz at attempt creation or prior to editing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function snapshotQuizQuestions(Quiz $quiz): array
    {
        return QuizQuestion::where('quiz_id', $quiz->id)
            ->orderBy('question_order')
            ->get()
            ->each->makeVisible(['correct_answer', 'grading_guidance'])
            ->map(fn (QuizQuestion $q) => $q->toSnapshot())
            ->values()
            ->all();
    }

    /**
     * Ensure existing attempts preserve a question snapshot before any quiz edits occur.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ensureAttemptSnapshot(QuizAttempt $attempt): array
    {
        $snapshot = $attempt->questions_snapshot;
        if (is_array($snapshot) && ! empty($snapshot)) {
            return $snapshot;
        }

        $questions = QuizQuestion::withTrashed()
            ->where('quiz_id', $attempt->quiz_id)
            ->orderBy('question_order')
            ->get()
            ->each->makeVisible(['correct_answer', 'grading_guidance']);

        $snapshot = $questions->map(fn (QuizQuestion $q) => $q->toSnapshot())->values()->all();
        $totalMarks = round((float) collect($snapshot)->sum('marks'), 2);

        $attempt->update([
            'questions_snapshot' => $snapshot,
            'total_marks_snapshot' => $attempt->total_marks_snapshot ?? $totalMarks,
        ]);

        return $snapshot;
    }

    /**
     * Resolve question descriptors for an attempt (prefers snapshotted version).
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolveAttemptQuestions(QuizAttempt $attempt): array
    {
        $snapshot = $attempt->questions_snapshot;
        if (is_array($snapshot) && ! empty($snapshot)) {
            return collect($snapshot)
                ->sortBy(fn ($q) => (int) ($q['question_order'] ?? 0))
                ->values()
                ->all();
        }

        return QuizQuestion::withTrashed()
            ->where('quiz_id', $attempt->quiz_id)
            ->orderBy('question_order')
            ->get()
            ->each->makeVisible(['correct_answer', 'grading_guidance'])
            ->map(fn (QuizQuestion $q) => $q->toSnapshot())
            ->values()
            ->all();
    }

    public function grade(QuizAttempt $attempt): QuizAttempt
    {
        return DB::transaction(function () use ($attempt) {
            $lockedAttempt = QuizAttempt::with('quiz')->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $questions = $this->ensureAttemptSnapshot($lockedAttempt);
            $answers = QuizAnswer::where('quiz_attempt_id', $lockedAttempt->id)->get()->keyBy('question_id');

            foreach ($questions as $q) {
                $qId = (int) ($q['id'] ?? 0);
                $qType = strtolower((string) ($q['question_type'] ?? 'multiple_choice'));
                $qMarks = (float) ($q['marks'] ?? 1);
                $isRequired = array_key_exists('is_required', $q) ? (bool) $q['is_required'] : true;
                $answer = $answers->get($qId);

                if ($qType === 'short_answer') {
                    $givenText = $answer ? trim((string) $answer->answer) : '';
                    if ($answer === null && ! $isRequired) {
                        continue; // Optional unanswered short answer contributes 0 automatically
                    }

                    if ($givenText === '' && ! $isRequired) {
                        $answer->update([
                            'is_correct' => false,
                            'marks_obtained' => 0.0,
                            'grading_status' => 'auto_graded',
                            'question_snapshot' => $q,
                        ]);

                        continue;
                    }

                    // All submitted (or required) short-answer responses go to Pending Grading for manual teacher evaluation.
                    if ($answer) {
                        $answer->update([
                            'is_correct' => null,
                            'marks_obtained' => null,
                            'grading_status' => 'pending_grading',
                            'question_snapshot' => $q,
                        ]);
                    } else {
                        QuizAnswer::create([
                            'quiz_attempt_id' => $lockedAttempt->id,
                            'question_id' => $qId,
                            'answer' => '',
                            'is_correct' => null,
                            'marks_obtained' => null,
                            'grading_status' => 'pending_grading',
                            'question_snapshot' => $q,
                        ]);
                    }

                    continue;
                }

                if (! $answer) {
                    continue; // unanswered objective question scores 0
                }

                $correct = $this->isObjectiveAnswerCorrect($q, (string) $answer->answer);
                $answer->update([
                    'is_correct' => $correct,
                    'marks_obtained' => $correct ? $qMarks : 0.0,
                    'grading_status' => 'auto_graded',
                    'question_snapshot' => $q,
                ]);
            }

            return $this->recalculate($lockedAttempt);
        });
    }

    /**
     * Manually grade one Short Answer (or adjust any answer's marks) for a single attempt.
     */
    public function gradeAnswer(QuizAnswer $answer, float $marksObtained, ?string $feedback, int $graderUserId): QuizAnswer
    {
        return DB::transaction(function () use ($answer, $marksObtained, $feedback, $graderUserId) {
            $lockedAttempt = QuizAttempt::with('quiz')->whereKey($answer->quiz_attempt_id)->lockForUpdate()->firstOrFail();
            $lockedAnswer = QuizAnswer::whereKey($answer->id)->lockForUpdate()->firstOrFail();

            $snapshot = is_array($lockedAnswer->question_snapshot) ? $lockedAnswer->question_snapshot : null;
            if (! $snapshot) {
                $question = QuizQuestion::withTrashed()->find($lockedAnswer->question_id);
                if ($question) {
                    $question->makeVisible(['correct_answer', 'grading_guidance']);
                    $snapshot = $question->toSnapshot();
                }
            }

            $maxMarks = (float) ($snapshot['marks'] ?? $lockedAnswer->question?->marks ?? 0);
            if ($marksObtained < 0 || ($maxMarks > 0 && $marksObtained > $maxMarks)) {
                throw ValidationException::withMessages([
                    'marks_obtained' => ["Marks obtained must be between 0 and {$maxMarks}."],
                ]);
            }

            $rounded = round($marksObtained, 2);
            $lockedAnswer->update([
                'marks_obtained' => $rounded,
                'is_correct' => $maxMarks > 0 ? ($rounded >= $maxMarks) : ($rounded > 0),
                'grading_status' => 'graded',
                'teacher_feedback' => $feedback !== null ? (trim($feedback) !== '' ? trim($feedback) : null) : $lockedAnswer->teacher_feedback,
                'graded_by' => $graderUserId,
                'graded_at' => now(),
                'question_snapshot' => $snapshot ?? $lockedAnswer->question_snapshot,
            ]);

            $this->recalculate($lockedAttempt, $graderUserId);

            return $lockedAnswer->fresh();
        });
    }

    public function recalculate(QuizAttempt $attempt, ?int $graderUserId = null): QuizAttempt
    {
        $answers = QuizAnswer::where('quiz_attempt_id', $attempt->id)->with('question')->get();

        $pending = $answers->contains(function (QuizAnswer $a) {
            if ($a->grading_status === 'pending_grading') {
                return true;
            }
            $type = strtolower((string) ($a->question_snapshot['question_type'] ?? $a->question?->question_type ?? ''));

            return $type === 'short_answer' && $a->marks_obtained === null && $a->is_correct === null;
        });

        $autoScore = 0.0;
        $manualScore = 0.0;
        $hasManualGraded = false;

        foreach ($answers as $ans) {
            if ($ans->grading_status === 'pending_grading' && $ans->marks_obtained === null) {
                continue;
            }
            if ($ans->grading_status === 'graded') {
                $manualScore += (float) ($ans->marks_obtained ?? 0);
                $hasManualGraded = true;
            } else {
                $autoScore += (float) ($ans->marks_obtained ?? 0);
            }
        }

        $autoScore = round($autoScore, 2);
        $manualScore = round($manualScore, 2);

        $totalMarks = (float) ($attempt->total_marks_snapshot
            ?: collect($this->resolveAttemptQuestions($attempt))->sum('marks')
            ?: $attempt->quiz?->total_score
            ?: 0);

        if ($pending) {
            $attempt->update([
                'auto_graded_score' => $autoScore,
                'manual_graded_score' => $manualScore,
                'total_marks_snapshot' => $attempt->total_marks_snapshot ?: $totalMarks,
                'score' => null, // Never expose final score while manual grading is pending
                'status' => 'submitted',
                'grading_status' => 'pending_grading',
            ]);

            return $attempt->fresh();
        }

        $combined = round($autoScore + $manualScore, 2);
        if ($totalMarks > 0 && $combined > $totalMarks) {
            $combined = $totalMarks;
        }

        $attempt->update([
            'auto_graded_score' => $autoScore,
            'manual_graded_score' => $manualScore,
            'total_marks_snapshot' => $attempt->total_marks_snapshot ?: $totalMarks,
            'score' => $combined,
            'status' => 'completed',
            'grading_status' => $hasManualGraded ? 'graded' : 'auto_graded',
            'graded_by' => $hasManualGraded ? ($graderUserId ?? $attempt->graded_by) : $attempt->graded_by,
            'graded_at' => $hasManualGraded ? now() : ($attempt->graded_at ?? now()),
        ]);

        return $attempt->fresh();
    }

    /**
     * Compute a student's official quiz score across multiple finalized non-reset attempts
     * using the quiz's configured scoring_method (highest | latest | average).
     *
     * @param Collection<int, QuizAttempt> $attempts
     */
    public function calculateStudentQuizScore(Quiz $quiz, Collection $attempts): ?float
    {
        $finalized = $attempts->filter(function (QuizAttempt $a) {
            return ! $a->is_reset && $a->isFinalized() && $a->score !== null;
        });

        if ($finalized->isEmpty()) {
            return null;
        }

        $method = strtolower((string) ($quiz->scoring_method ?: 'highest'));

        return match ($method) {
            'latest' => round((float) $finalized
                ->sortByDesc(fn (QuizAttempt $a) => [(int) $a->attempt_number, (int) $a->id])
                ->first()->score, 2),
            'average' => round((float) $finalized->avg(fn (QuizAttempt $a) => (float) $a->score), 2),
            default => round((float) $finalized->max(fn (QuizAttempt $a) => (float) $a->score), 2),
        };
    }

    /**
     * Compare an objective response (multiple_choice, mcq, true_false) against the stored key.
     * Supports both option key ('A'..'D') and matching option text for legacy data compatibility.
     *
     * @param array<string, mixed> $question
     */
    protected function isObjectiveAnswerCorrect(array $question, string $rawGiven): bool
    {
        $given = strtolower(trim($rawGiven));
        $key = strtolower(trim((string) ($question['correct_answer'] ?? '')));

        if ($given === '' || $key === '') {
            return false;
        }

        if ($given === $key) {
            return true;
        }

        $qType = strtolower((string) ($question['question_type'] ?? 'multiple_choice'));
        if (in_array($qType, ['multiple_choice', 'mcq'], true)) {
            $optMap = [
                'a' => strtolower(trim((string) ($question['option_a'] ?? ''))),
                'b' => strtolower(trim((string) ($question['option_b'] ?? ''))),
                'c' => strtolower(trim((string) ($question['option_c'] ?? ''))),
                'd' => strtolower(trim((string) ($question['option_d'] ?? ''))),
            ];

            if (isset($optMap[$key]) && $optMap[$key] !== '' && $given === $optMap[$key]) {
                return true;
            }
            if (isset($optMap[$given]) && $optMap[$given] !== '' && $optMap[$given] === $key) {
                return true;
            }
        }

        return false;
    }
}
