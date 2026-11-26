<?php

namespace App\Http\Controllers;

use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\Student;
use App\Services\AssessmentAccessService;
use App\Services\QuizAvailabilityService;
use App\Services\QuizGradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Student quiz workflow.
 * Identity comes strictly from the authenticated user's token; attempt limits,
 * deadlines, overrides, incomplete-submission blocking, and grading run server-side.
 */
class StudentQuizController extends Controller
{
    private const GRACE_SECONDS = 60;

    public function __construct(
        protected AssessmentAccessService $access,
        protected QuizGradingService $grading,
        protected QuizAvailabilityService $availability,
    ) {}

    protected function student(Request $request): Student
    {
        $student = $request->user()?->student;
        if (! $student) {
            abort(response()->json(['success' => false, 'message' => 'Student profile not found.'], 404));
        }

        return $student;
    }

    protected function isSubmissionRevoked(int $quizId, int $studentId): bool
    {
        $override = \App\Models\AssessmentStudentOverride::forAssessment('quiz', $quizId)
            ->where('student_id', $studentId)
            ->active()
            ->first();

        return $override !== null && ! $override->allow_submission;
    }

    /** Quiz that this student is allowed to open, or a 404 response. */
    protected function accessibleQuiz(Student $student, int $quizId): Quiz|JsonResponse
    {
        $quiz = Quiz::find($quizId);
        if (! $quiz || ! $this->access->studentCanAccess($student, $quiz)) {
            return response()->json(['success' => false, 'message' => 'Quiz not found or you are not enrolled in this course.'], 404);
        }

        $override = $this->availability->getActiveOverride($quiz, $student);
        $visible = $this->access->isVisibleStatus('quiz', $quiz->status)
            || ($quiz->status === 'closed' && $override !== null);

        if (! $visible) {
            return response()->json(['success' => false, 'message' => 'Quiz not found or you are not enrolled in this course.'], 404);
        }

        return $quiz;
    }

    protected function ownedAttempt(Student $student, int $attemptId): QuizAttempt|JsonResponse
    {
        $attempt = QuizAttempt::with('quiz')->find($attemptId);
        if (! $attempt || (int) $attempt->student_id !== (int) $student->id || ! $attempt->quiz) {
            return response()->json(['success' => false, 'message' => 'Attempt not found.'], 404);
        }

        return $attempt;
    }

    /** Format questions for taking a quiz (never leaks correct_answer or grading_guidance). */
    protected function formatQuestionsForTaking(array $snapshots): array
    {
        return collect($snapshots)->map(function (array $q) {
            $qType = strtolower((string) ($q['question_type'] ?? 'multiple_choice'));
            $options = [];
            foreach (['A' => 'option_a', 'B' => 'option_b', 'C' => 'option_c', 'D' => 'option_d'] as $key => $col) {
                $val = $q[$col] ?? null;
                if ($val !== null && trim((string) $val) !== '') {
                    $options[] = ['key' => $key, 'text' => (string) $val];
                }
            }
            if ($qType === 'true_false') {
                $options = [['key' => 'true', 'text' => 'True'], ['key' => 'false', 'text' => 'False']];
            }

            return [
                'id' => (int) ($q['id'] ?? 0),
                'number' => (int) ($q['question_order'] ?? 1),
                'question_text' => (string) ($q['question_text'] ?? ''),
                'question_type' => $qType,
                'marks' => (float) ($q['marks'] ?? 1),
                'is_required' => array_key_exists('is_required', $q) ? (bool) $q['is_required'] : true,
                'options' => $options,
            ];
        })->values()->all();
    }

    /** Result payload returned to student, hiding score while manual grading is pending. */
    protected function resultPayload(QuizAttempt $attempt, Quiz $quiz, ?array $access = null): array
    {
        $pending = $attempt->isPendingManualGrading();
        $graded = $attempt->isFinalized();
        $totalMarks = (float) ($attempt->total_marks_snapshot
            ?: QuizQuestion::where('quiz_id', $quiz->id)->sum('marks')
            ?: $quiz->total_score);

        $windowState = $access['window_state'] ?? $this->availability->windowState($quiz);
        $attemptsRemaining = $access['attempts_remaining'] ?? null;
        $correctAnswersVisible = $windowState === 'closed'
            || ($graded && $attemptsRemaining === 0);

        return [
            'id' => $attempt->id,
            'status' => $attempt->status,
            'grading_status' => $pending ? 'pending_grading' : ($attempt->grading_status ?: ($graded ? 'auto_graded' : 'not_submitted')),
            'attempt_number' => $attempt->attempt_number,
            'started_at' => $attempt->started_at,
            'submitted_at' => $attempt->submitted_at,
            'graded_at' => $graded ? $attempt->graded_at : null,
            'score' => $graded ? $attempt->score : null,
            'auto_graded_score' => $graded ? $attempt->auto_graded_score : null,
            'manual_graded_score' => $graded ? $attempt->manual_graded_score : null,
            'total_marks' => $totalMarks,
            'is_late' => (bool) $attempt->is_late,
            'is_reset' => (bool) $attempt->is_reset,
            'score_released' => $graded,
            'awaiting_manual_grading' => $pending,
            'review_allowed' => $attempt->status !== 'in_progress',
            'correct_answers_visible' => $correctAnswersVisible,
        ];
    }

    /** GET /api/student/quizzes/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $student = $this->student($request);
        $quiz = $this->accessibleQuiz($student, $id);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }
        $quiz->load(['courseOffering.course', 'courseOffering.classRoom', 'courseOffering.semester.academicYear']);

        $access = $this->availability->evaluateStudentAccess($quiz, $student);
        $universal = app(\App\Services\AssessmentReopenService::class)->evaluateQuizAccess($quiz, $student);
        $latest = $access['active_attempts']->first() ?? $access['attempts']->first();
        $totalMarks = (float) QuizQuestion::where('quiz_id', $quiz->id)->sum('marks') ?: (float) $quiz->total_score;
        $effectiveScore = $this->grading->calculateStudentQuizScore($quiz, $access['attempts']);
        $hasPending = $access['active_attempts']->contains(fn (QuizAttempt $a) => $a->isPendingManualGrading());

        $history = $access['attempts']->map(fn (QuizAttempt $a) => $this->resultPayload($a, $quiz, $access))->values()->all();

        $override = $access['override'];

        return response()->json(['success' => true, 'data' => [
            'id' => $quiz->id,
            'assessment_type' => 'quiz',
            'assessment_id' => $quiz->id,
            'class_id' => $universal['class_id'],
            'title' => $quiz->title,
            'instructions' => $quiz->description,
            'course_name' => $quiz->courseOffering?->course?->name ?? $quiz->course?->name,
            'course_code' => $quiz->courseOffering?->course?->code ?? $quiz->course?->code,
            'class_name' => $quiz->courseOffering?->classRoom?->name,
            'semester_name' => $quiz->courseOffering?->semester?->name ?? $quiz->semester?->name,
            'academic_year_name' => $quiz->courseOffering?->semester?->academicYear?->name,
            'status' => $quiz->status,
            'window_state' => $access['window_state'],
            'reopen_status' => $universal['reopen_status'],
            'student_submission_status' => $universal['student_submission_status'],
            'total_questions' => $access['question_count'],
            'total_marks' => $totalMarks,
            'duration_minutes' => $quiz->duration_minutes,
            'start_at' => $quiz->start_at,
            'end_at' => $quiz->end_at,
            'effective_end_at' => $access['effective_end_at'],
            'original_deadline' => $universal['original_deadline'],
            'extended_deadline' => $universal['extended_deadline'],
            'effective_deadline' => $universal['effective_deadline'],
            'max_attempts' => $access['max_attempts'],
            'base_max_attempts' => $access['base_max_attempts'],
            'extra_attempts' => $access['extra_attempts'],
            'original_attempt_limit' => $universal['original_attempt_limit'],
            'additional_attempts' => $universal['additional_attempts'],
            'attempts_used' => $access['attempts_used'],
            'attempts_remaining' => $access['attempts_remaining'],
            'remaining_attempts' => $universal['remaining_attempts'],
            'scoring_method' => $quiz->scoring_method ?: 'highest',
            'allow_submission' => $universal['allow_submission'],
            'allow_late_submission' => $access['allow_late_submission'],
            'allow_resubmission' => $universal['allow_resubmission'],
            'effective_score' => ($hasPending && ($quiz->scoring_method === 'latest' || $effectiveScore === null)) ? null : $effectiveScore,
            'has_pending_grading' => $hasPending,
            'can_start' => $universal['can_start'],
            'can_continue' => $universal['can_continue'],
            'can_submit' => $universal['can_submit'],
            'can_resubmit' => $universal['can_resubmit'],
            'has_active_override' => $universal['has_active_override'],
            'blocked_reason' => $universal['blocked_reason'],
            'server_time' => $access['server_time'],
            'override' => $override ? [
                'extra_attempts' => (int) $override->extra_attempts,
                'extended_end_at' => $override->extended_end_at,
                'allow_late_submission' => (bool) $override->allow_late_submission,
                'reason' => $override->reason,
            ] : null,
            'attempt' => $latest ? $this->resultPayload($latest, $quiz, $access) : null,
            'attempts_history' => $history,
        ]]);
    }

    /** POST /api/student/quizzes/{id}/start */
    public function start(Request $request, int $id): JsonResponse
    {
        $student = $this->student($request);
        $quiz = $this->accessibleQuiz($student, $id);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }

        $result = DB::transaction(function () use ($quiz, $student) {
            $lockedQuiz = Quiz::whereKey($quiz->id)->lockForUpdate()->firstOrFail();
            if ($this->isSubmissionRevoked($lockedQuiz->id, $student->id)) {
                return ['error' => 'Submission permission for this quiz has been revoked by your instructor.', 'status' => 403];
            }
            $override = $this->availability->getActiveOverride($lockedQuiz, $student);
            $windowState = $this->availability->windowState($lockedQuiz, $override);

            if ($windowState === 'upcoming') {
                return ['error' => 'This quiz has not opened yet.', 'status' => 422];
            }
            if ($windowState === 'closed') {
                return ['error' => 'This quiz is closed.', 'status' => 422];
            }

            $existing = QuizAttempt::where('quiz_id', $lockedQuiz->id)
                ->where('student_id', $student->id)
                ->lockForUpdate()
                ->orderByDesc('attempt_number')
                ->orderByDesc('id')
                ->get();

            $active = $existing->filter(fn (QuizAttempt $a) => ! $a->is_reset);
            $inProgress = $active->first(fn (QuizAttempt $a) => $a->status === 'in_progress');
            if ($inProgress) {
                $this->grading->ensureAttemptSnapshot($inProgress);

                return ['attempt' => $inProgress->fresh(), 'override' => $override];
            }

            $questionsSnapshot = $this->grading->snapshotQuizQuestions($lockedQuiz);
            if (empty($questionsSnapshot)) {
                return ['error' => 'This quiz has no questions yet.', 'status' => 422];
            }

            $usedCount = $active->filter(fn (QuizAttempt $a) => $a->status !== 'in_progress')->count();
            $maxAttempts = $this->availability->effectiveMaxAttempts($lockedQuiz, $override);
            if ($maxAttempts !== null && $usedCount >= $maxAttempts) {
                return [
                    'error' => "You have already used all {$maxAttempts} allowed attempt(s) for this quiz.",
                    'status' => 409,
                ];
            }

            $nextAttemptNumber = ((int) $existing->max('attempt_number')) + 1;
            $totalMarks = round((float) collect($questionsSnapshot)->sum('marks'), 2);

            $attempt = QuizAttempt::create([
                'quiz_id' => $lockedQuiz->id,
                'student_id' => $student->id,
                'attempt_number' => $nextAttemptNumber,
                'started_at' => now(),
                'status' => 'in_progress',
                'grading_status' => 'not_submitted',
                'total_marks_snapshot' => $totalMarks,
                'quiz_version' => (int) ($lockedQuiz->version ?: 1),
                'questions_snapshot' => $questionsSnapshot,
                'is_late' => $windowState === 'late_open',
                'is_reset' => false,
                'override_id' => $override?->id,
            ]);

            return ['attempt' => $attempt, 'override' => $override];
        });

        if (isset($result['error'])) {
            return response()->json(['success' => false, 'message' => $result['error']], $result['status']);
        }

        /** @var QuizAttempt $attempt */
        $attempt = $result['attempt'];
        $attempt->setRelation('quiz', $quiz);
        $snapshots = $this->grading->resolveAttemptQuestions($attempt);

        return response()->json(['success' => true, 'data' => [
            'attempt_id' => $attempt->id,
            'attempt_number' => $attempt->attempt_number,
            'started_at' => $attempt->started_at,
            'expires_at' => $this->availability->attemptExpiresAt($attempt, $quiz, $result['override']),
            'server_time' => now(),
            'is_late' => (bool) $attempt->is_late,
            'questions' => $this->formatQuestionsForTaking($snapshots),
            'answers' => QuizAnswer::where('quiz_attempt_id', $attempt->id)->pluck('answer', 'question_id'),
        ]]);
    }

    /** PUT /api/student/quiz-attempts/{attemptId}/answers  body: { answers: [{question_id, answer}] } */
    public function saveAnswers(Request $request, int $attemptId): JsonResponse
    {
        $student = $this->student($request);
        $attempt = $this->ownedAttempt($student, $attemptId);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
        }
        if ($attempt->status !== 'in_progress') {
            return response()->json(['success' => false, 'message' => 'This attempt has already been submitted.'], 409);
        }

        if ($this->isSubmissionRevoked($attempt->quiz_id, $student->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Submission permission for this quiz has been revoked by your instructor.',
            ], 403);
        }

        $override = $this->availability->getActiveOverride($attempt->quiz, $student);
        if (now()->gt($this->availability->attemptExpiresAt($attempt, $attempt->quiz, $override)->addSeconds(self::GRACE_SECONDS))) {
            return response()->json(['success' => false, 'message' => 'Time is up. Submit the quiz to finish.', 'time_up' => true], 422);
        }

        $this->storeAnswers($request, $attempt);
        $unansweredRequired = $this->findUnansweredRequiredQuestions($attempt);
        $snapshots = $this->grading->resolveAttemptQuestions($attempt);
        $answeredCount = $this->countNonBlankAnswers($attempt);

        return response()->json([
            'success' => true,
            'message' => 'Answers saved.',
            'data' => [
                'answered' => $answeredCount,
                'total_questions' => count($snapshots),
                'unanswered_required_count' => count($unansweredRequired),
                'unanswered_question_numbers' => array_column($unansweredRequired, 'number'),
            ],
        ]);
    }

    protected function storeAnswers(Request $request, QuizAttempt $attempt): void
    {
        $data = $request->validate([
            'answers' => 'sometimes|array',
            'answers.*.question_id' => 'required|integer',
            'answers.*.answer' => 'nullable|string|max:5000',
        ]);

        $snapshotsById = collect($this->grading->resolveAttemptQuestions($attempt))
            ->keyBy(fn ($q) => (int) ($q['id'] ?? 0));

        foreach ($data['answers'] ?? [] as $row) {
            $qId = (int) $row['question_id'];
            if (! $snapshotsById->has($qId)) {
                continue; // ignore questions not in this attempt's snapshot
            }

            $text = $row['answer'] ?? null;
            if ($text === null || trim((string) $text) === '') {
                QuizAnswer::where('quiz_attempt_id', $attempt->id)->where('question_id', $qId)->delete();

                continue;
            }

            QuizAnswer::updateOrCreate(
                ['quiz_attempt_id' => $attempt->id, 'question_id' => $qId],
                [
                    'answer' => trim((string) $text),
                    'is_correct' => null,
                    'marks_obtained' => null,
                    'grading_status' => 'pending_grading',
                    'question_snapshot' => $snapshotsById->get($qId),
                ]
            );
        }
    }

    /**
     * @return array<int, array{id: int, number: int}>
     */
    protected function findUnansweredRequiredQuestions(QuizAttempt $attempt): array
    {
        $snapshots = $this->grading->resolveAttemptQuestions($attempt);
        $answers = QuizAnswer::where('quiz_attempt_id', $attempt->id)->get()->keyBy('question_id');

        $missing = [];
        foreach ($snapshots as $q) {
            $isRequired = array_key_exists('is_required', $q) ? (bool) $q['is_required'] : true;
            if (! $isRequired) {
                continue;
            }
            $qId = (int) ($q['id'] ?? 0);
            $ans = $answers->get($qId);
            if (! $ans || trim((string) $ans->answer) === '') {
                $missing[] = [
                    'id' => $qId,
                    'number' => (int) ($q['question_order'] ?? 0),
                ];
            }
        }

        return $missing;
    }

    protected function countNonBlankAnswers(QuizAttempt $attempt): int
    {
        return QuizAnswer::where('quiz_attempt_id', $attempt->id)
            ->get()
            ->filter(fn (QuizAnswer $a) => trim((string) $a->answer) !== '')
            ->count();
    }

    /** POST /api/student/quiz-attempts/{attemptId}/submit  body: { answers?: [...] } */
    public function submit(Request $request, int $attemptId): JsonResponse
    {
        $student = $this->student($request);
        $attempt = $this->ownedAttempt($student, $attemptId);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
        }
        if ($attempt->status !== 'in_progress') {
            $access = $this->availability->evaluateStudentAccess($attempt->quiz, $student);

            return response()->json([
                'success' => false,
                'message' => 'This quiz was already submitted.',
                'data' => $this->resultPayload($attempt, $attempt->quiz, $access),
            ], 409);
        }

        if ($this->isSubmissionRevoked($attempt->quiz_id, $student->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Submission permission for this quiz has been revoked by your instructor.',
            ], 403);
        }

        $override = $this->availability->getActiveOverride($attempt->quiz, $student);
        $expiresAt = $this->availability->attemptExpiresAt($attempt, $attempt->quiz, $override);
        $lateTimeout = now()->gt($expiresAt->copy()->addSeconds(self::GRACE_SECONDS));

        if (! $lateTimeout) {
            $this->storeAnswers($request, $attempt);

            $missingRequired = $this->findUnansweredRequiredQuestions($attempt);
            if (! empty($missingRequired)) {
                $snapshots = $this->grading->resolveAttemptQuestions($attempt);
                $answered = $this->countNonBlankAnswers($attempt);
                $numbers = array_values(array_map(fn ($m) => $m['number'], $missingRequired));
                $ids = array_values(array_map(fn ($m) => $m['id'], $missingRequired));
                $labels = implode(', ', array_map(fn ($n) => 'Q' . $n, $numbers));

                return response()->json([
                    'success' => false,
                    'message' => "Please answer all required questions before submitting (Unanswered: {$labels}).",
                    'errors' => [
                        'answers' => ["Please answer all required questions ({$labels})."],
                    ],
                    'unanswered_question_ids' => $ids,
                    'unanswered_question_numbers' => $numbers,
                    'answered' => $answered,
                    'unanswered' => count($missingRequired),
                    'total_questions' => count($snapshots),
                ], 422);
            }
        }

        $effectiveDeadline = $this->availability->effectiveDeadline($attempt->quiz, $override);
        $isLate = (bool) $attempt->is_late || ($effectiveDeadline !== null && now()->gt($effectiveDeadline));

        $attempt->update([
            'submitted_at' => now(),
            'is_late' => $isLate,
        ]);

        $attempt = $this->grading->grade($attempt);
        $answered = $this->countNonBlankAnswers($attempt);
        $total = count($this->grading->resolveAttemptQuestions($attempt));
        $access = $this->availability->evaluateStudentAccess($attempt->quiz, $student);

        return response()->json([
            'success' => true,
            'message' => $lateTimeout
                ? 'Time expired; your saved answers were submitted.'
                : ($attempt->isPendingManualGrading()
                    ? 'Quiz submitted successfully. Short-answer questions are awaiting teacher grading.'
                    : 'Quiz submitted successfully.'),
            'data' => $this->resultPayload($attempt, $attempt->quiz, $access) + [
                'answered' => $answered,
                'unanswered' => max(0, $total - $answered),
            ],
        ]);
    }

    /** GET /api/student/quiz-attempts/{attemptId}/review */
    public function review(Request $request, int $attemptId): JsonResponse
    {
        $student = $this->student($request);
        $attempt = $this->ownedAttempt($student, $attemptId);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
        }
        if ($attempt->status === 'in_progress') {
            return response()->json(['success' => false, 'message' => 'Submit this attempt before reviewing answers.'], 403);
        }

        $access = $this->availability->evaluateStudentAccess($attempt->quiz, $student);
        $result = $this->resultPayload($attempt, $attempt->quiz, $access);
        $showCorrect = (bool) ($result['correct_answers_visible'] ?? false);

        $snapshots = $this->grading->resolveAttemptQuestions($attempt);
        $answers = QuizAnswer::where('quiz_attempt_id', $attempt->id)->get()->keyBy('question_id');

        $items = collect($snapshots)->map(function (array $q) use ($answers, $showCorrect) {
            $qId = (int) ($q['id'] ?? 0);
            $a = $answers->get($qId);
            $qType = strtolower((string) ($q['question_type'] ?? 'multiple_choice'));
            $isPending = $a !== null && $a->grading_status === 'pending_grading';

            $options = [];
            foreach (['A' => 'option_a', 'B' => 'option_b', 'C' => 'option_c', 'D' => 'option_d'] as $k => $col) {
                if (! empty($q[$col])) {
                    $options[] = ['key' => $k, 'text' => (string) $q[$col]];
                }
            }

            return [
                'question_id' => $qId,
                'number' => (int) ($q['question_order'] ?? 1),
                'question_text' => (string) ($q['question_text'] ?? ''),
                'question_type' => $qType,
                'marks' => (float) ($q['marks'] ?? 1),
                'is_required' => array_key_exists('is_required', $q) ? (bool) $q['is_required'] : true,
                'options' => $options,
                'your_answer' => $a?->answer,
                'grading_status' => $a ? ($a->grading_status ?: ($a->is_correct === null ? 'pending_grading' : 'auto_graded')) : 'unanswered',
                'is_correct' => $isPending ? null : $a?->is_correct,
                'marks_obtained' => $isPending ? null : $a?->marks_obtained,
                'teacher_feedback' => $a?->teacher_feedback,
                'graded_at' => $isPending ? null : $a?->graded_at,
                'correct_answer' => $showCorrect ? ($q['correct_answer'] ?? null) : null,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'result' => $result,
                'questions' => $items,
            ],
        ]);
    }
}
