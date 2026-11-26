<?php

namespace App\Services;

use App\Models\AssessmentAuditLog;
use App\Models\AssessmentStudentOverride;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizOverrideLog;
use App\Models\QuizQuestion;
use App\Models\QuizStudentOverride;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Enforces server-side quiz availability, deadlines, attempt limits,
 * quiz reopening, and student-specific overrides with full audit logging.
 */
class QuizAvailabilityService
{
    public function getActiveOverride(Quiz $quiz, Student $student): ?QuizStudentOverride
    {
        return QuizStudentOverride::where('quiz_id', $quiz->id)
            ->where('student_id', $student->id)
            ->where('is_active', true)
            ->first();
    }

    public function effectiveDeadline(Quiz $quiz, ?QuizStudentOverride $override = null): ?Carbon
    {
        $quizDeadline = $quiz->end_at?->copy();
        if ($quiz->reopened_until && (! $quizDeadline || $quiz->reopened_until->gt($quizDeadline))) {
            $quizDeadline = $quiz->reopened_until->copy();
        }

        if ($override && $override->is_active && $override->extended_end_at) {
            $overrideDeadline = $override->extended_end_at->copy();
            if (! $quizDeadline || $overrideDeadline->gt($quizDeadline)) {
                return $overrideDeadline;
            }
        }

        return $quizDeadline;
    }

    public function effectiveAllowLate(Quiz $quiz, ?QuizStudentOverride $override = null): bool
    {
        if ($override && $override->is_active && $override->allow_late_submission) {
            return true;
        }

        return (bool) $quiz->allow_late_submission;
    }

    public function effectiveMaxAttempts(Quiz $quiz, ?QuizStudentOverride $override = null): ?int
    {
        $rawMax = $quiz->max_attempts;
        if ($rawMax === null || (int) $rawMax <= 0) {
            return null; // Unlimited attempts
        }

        $base = (int) $rawMax;
        $extra = ($override && $override->is_active) ? max(0, (int) $override->extra_attempts) : 0;

        return $base + $extra;
    }

    public function windowState(Quiz $quiz, ?QuizStudentOverride $override = null, ?Carbon $now = null): string
    {
        $now = $now ?? now();
        $status = strtolower((string) $quiz->status);

        if ($quiz->trashed() || in_array($status, ['draft', 'archived'], true)) {
            return 'closed';
        }

        $hasActiveOverride = $override && $override->is_active;
        if ($hasActiveOverride) {
            if ($override->extended_end_at) {
                if ($now->lte($override->extended_end_at)) {
                    return 'open';
                }
                if ($override->allow_late_submission) {
                    return 'late_open';
                }
            } elseif ($override->allow_late_submission) {
                if ($status === 'closed' || ($quiz->end_at && $now->gt($quiz->end_at))) {
                    return 'late_open';
                }

                return 'open';
            }
        }

        if ($status === 'closed') {
            return 'closed';
        }

        if ($quiz->start_at && $now->lt($quiz->start_at)) {
            return 'upcoming';
        }

        $deadline = $this->effectiveDeadline($quiz, $override);
        if ($deadline && $now->gt($deadline)) {
            return $this->effectiveAllowLate($quiz, $override) ? 'late_open' : 'closed';
        }

        return 'open';
    }

    public function attemptExpiresAt(QuizAttempt $attempt, Quiz $quiz, ?QuizStudentOverride $override = null): Carbon
    {
        $anchor = $attempt->started_at?->copy() ?? now();
        if ($override && $override->is_active && $override->updated_at && $override->updated_at->gt($anchor)) {
            $anchor = $override->updated_at->copy();
        } elseif ($quiz->reopened_at && $quiz->reopened_at->gt($anchor)) {
            $anchor = $quiz->reopened_at->copy();
        }

        $limit = $anchor->addMinutes((int) ($quiz->duration_minutes ?: 30));
        $deadline = $this->effectiveDeadline($quiz, $override);

        if (! $this->effectiveAllowLate($quiz, $override) && $deadline && $deadline->lt($limit)) {
            return $deadline->copy();
        }

        return $limit;
    }

    /**
     * Evaluate full access state for a student on a quiz using server time.
     *
     * @return array<string, mixed>
     */
    public function evaluateStudentAccess(Quiz $quiz, Student $student): array
    {
        $now = now();
        $override = $this->getActiveOverride($quiz, $student);
        $attempts = QuizAttempt::where('quiz_id', $quiz->id)
            ->where('student_id', $student->id)
            ->orderByDesc('attempt_number')
            ->orderByDesc('id')
            ->get();

        $activeAttempts = $attempts->filter(fn (QuizAttempt $a) => ! $a->is_reset);
        $inProgress = $activeAttempts->first(fn (QuizAttempt $a) => $a->status === 'in_progress');
        $usedCount = $activeAttempts->filter(fn (QuizAttempt $a) => $a->status !== 'in_progress')->count();

        $baseMax = ($quiz->max_attempts === null || (int) $quiz->max_attempts <= 0)
            ? null
            : (int) $quiz->max_attempts;
        $extraAttempts = ($override && $override->is_active) ? max(0, (int) $override->extra_attempts) : 0;
        $maxAttempts = $this->effectiveMaxAttempts($quiz, $override);
        $remaining = $maxAttempts === null ? null : max(0, $maxAttempts - $usedCount);

        $windowState = $this->windowState($quiz, $override, $now);
        $windowOpen = in_array($windowState, ['open', 'late_open'], true);
        $questionCount = QuizQuestion::where('quiz_id', $quiz->id)->count();

        $canContinue = (bool) $inProgress && $windowOpen;
        $canStart = ! $inProgress && $windowOpen && $questionCount > 0 && ($remaining === null || $remaining > 0);

        $blockedReason = null;
        $univOverride = AssessmentStudentOverride::forAssessment('quiz', $quiz->id)
            ->where('student_id', $student->id)
            ->active()
            ->first();
        if ($univOverride && ! $univOverride->allow_submission) {
            $canContinue = false;
            $canStart = false;
            $blockedReason = 'Submission permission for this quiz has been restricted by your instructor.';
        }

        if (! $canStart && ! $canContinue && $blockedReason === null) {
            if ($windowState === 'upcoming') {
                $blockedReason = 'This quiz has not opened yet.';
            } elseif ($windowState === 'closed') {
                $blockedReason = 'This quiz is closed.';
            } elseif ($questionCount <= 0) {
                $blockedReason = 'This quiz has no questions yet.';
            } elseif ($remaining === 0) {
                $blockedReason = "You have used all allowed attempts ({$usedCount}/{$maxAttempts}).";
            }
        }

        return [
            'override' => $override,
            'attempts' => $attempts,
            'active_attempts' => $activeAttempts,
            'in_progress_attempt' => $inProgress,
            'attempts_used' => $usedCount,
            'base_max_attempts' => $baseMax,
            'extra_attempts' => $extraAttempts,
            'max_attempts' => $maxAttempts,
            'attempts_remaining' => $remaining,
            'window_state' => $windowState,
            'effective_end_at' => $this->effectiveDeadline($quiz, $override),
            'allow_late_submission' => $this->effectiveAllowLate($quiz, $override),
            'question_count' => $questionCount,
            'can_continue' => $canContinue,
            'can_start' => $canStart,
            'blocked_reason' => $blockedReason,
            'server_time' => $now,
        ];
    }

    public function reopenQuiz(
        Quiz $quiz,
        Teacher $teacher,
        User $actor,
        ?Carbon $newEndAt = null,
        ?bool $allowLate = null,
        ?string $reason = null,
    ): Quiz {
        return DB::transaction(function () use ($quiz, $teacher, $actor, $newEndAt, $allowLate, $reason) {
            $previous = [
                'status' => $quiz->status,
                'end_at' => $quiz->end_at?->toIso8601String(),
                'reopened_until' => $quiz->reopened_until?->toIso8601String(),
                'allow_late_submission' => (bool) $quiz->allow_late_submission,
            ];

            $updates = [
                'status' => 'published',
                'reopened_at' => now(),
                'reopened_by' => $actor->id,
            ];

            if ($newEndAt !== null) {
                $updates['end_at'] = $newEndAt;
                $updates['reopened_until'] = $newEndAt;
            }

            if ($allowLate !== null) {
                $updates['allow_late_submission'] = $allowLate;
            } elseif ($newEndAt === null && $quiz->end_at && $quiz->end_at->isPast()) {
                // Reopening an already-expired quiz without a new deadline allows late submission.
                $updates['allow_late_submission'] = true;
            }

            $quiz->update($updates);
            $fresh = $quiz->fresh();

            QuizOverrideLog::create([
                'quiz_id' => $quiz->id,
                'student_id' => null,
                'teacher_id' => $teacher->id,
                'acted_by' => $actor->id,
                'action' => 'reopen_quiz',
                'reason' => $reason,
                'previous_setting' => $previous,
                'new_setting' => [
                    'status' => $fresh->status,
                    'end_at' => $fresh->end_at?->toIso8601String(),
                    'reopened_until' => $fresh->reopened_until?->toIso8601String(),
                    'allow_late_submission' => (bool) $fresh->allow_late_submission,
                ],
            ]);

            AssessmentAuditLog::create([
                'assessment_type' => 'quiz',
                'assessment_id' => $quiz->id,
                'student_id' => null,
                'class_id' => $quiz->courseOffering?->class_id,
                'teacher_id' => $teacher->id,
                'performed_by' => $actor->id,
                'action_type' => 'reopen_quiz',
                'previous_deadline' => $previous['end_at'] ? Carbon::parse($previous['end_at']) : null,
                'new_deadline' => $fresh->reopened_until ?? $fresh->end_at,
                'previous_attempt_limit' => $quiz->max_attempts,
                'new_attempt_limit' => $fresh->max_attempts,
                'previous_settings' => $previous,
                'new_settings' => [
                    'status' => $fresh->status,
                    'end_at' => $fresh->end_at?->toIso8601String(),
                    'reopened_until' => $fresh->reopened_until?->toIso8601String(),
                    'allow_late_submission' => (bool) $fresh->allow_late_submission,
                ],
                'reason' => $reason,
            ]);

            return $fresh;
        });
    }

    /**
     * Grant extra attempt(s), extend deadline, allow late submission, or reset attempts for a single student.
     *
     * @param array<string, mixed> $input
     */
    public function applyStudentOverride(
        Quiz $quiz,
        Student $student,
        Teacher $teacher,
        User $actor,
        array $input,
    ): QuizStudentOverride {
        return DB::transaction(function () use ($quiz, $student, $teacher, $actor, $input) {
            $override = QuizStudentOverride::where('quiz_id', $quiz->id)
                ->where('student_id', $student->id)
                ->lockForUpdate()
                ->first();

            $previous = $override ? [
                'extra_attempts' => (int) $override->extra_attempts,
                'extended_end_at' => $override->extended_end_at?->toIso8601String(),
                'allow_late_submission' => (bool) $override->allow_late_submission,
                'attempts_reset_at' => $override->attempts_reset_at?->toIso8601String(),
                'is_active' => (bool) $override->is_active,
            ] : [
                'extra_attempts' => 0,
                'extended_end_at' => $quiz->end_at?->toIso8601String(),
                'allow_late_submission' => (bool) $quiz->allow_late_submission,
                'attempts_reset_at' => null,
                'is_active' => false,
            ];

            if (! $override) {
                $override = new QuizStudentOverride([
                    'quiz_id' => $quiz->id,
                    'student_id' => $student->id,
                    'extra_attempts' => 0,
                    'allow_late_submission' => false,
                ]);
            }

            $actions = [];

            if (! empty($input['grant_extra_attempts'])) {
                $delta = max(0, (int) $input['grant_extra_attempts']);
                $currentExtra = $override->is_active ? (int) $override->extra_attempts : 0;
                $override->extra_attempts = $currentExtra + $delta;
                $actions[] = 'grant_extra_attempt';
            } elseif (array_key_exists('extra_attempts', $input) && $input['extra_attempts'] !== null) {
                $override->extra_attempts = max(0, (int) $input['extra_attempts']);
                $actions[] = 'set_extra_attempts';
            }

            if (array_key_exists('extended_end_at', $input)) {
                $override->extended_end_at = ! empty($input['extended_end_at'])
                    ? Carbon::parse((string) $input['extended_end_at'])
                    : null;
                $actions[] = 'extend_deadline';
            }

            if (array_key_exists('allow_late_submission', $input) && $input['allow_late_submission'] !== null) {
                $override->allow_late_submission = (bool) $input['allow_late_submission'];
                $actions[] = 'allow_late_submission';
            }

            $resetCount = 0;
            if (! empty($input['reset_attempts'])) {
                $resetCount = QuizAttempt::where('quiz_id', $quiz->id)
                    ->where('student_id', $student->id)
                    ->where('is_reset', false)
                    ->update(['is_reset' => true]);
                $override->attempts_reset_at = now();
                $actions[] = 'reset_attempts';
            }

            $override->teacher_id = $teacher->id;
            $override->granted_by = $actor->id;
            $override->is_active = true;
            if (array_key_exists('reason', $input) && $input['reason'] !== null) {
                $override->reason = trim((string) $input['reason']) ?: null;
            }
            $override->save();

            $actionLabel = count($actions) === 1 ? $actions[0] : (empty($actions) ? 'student_override' : implode('+', $actions));

            QuizOverrideLog::create([
                'quiz_id' => $quiz->id,
                'student_id' => $student->id,
                'teacher_id' => $teacher->id,
                'acted_by' => $actor->id,
                'action' => $actionLabel,
                'reason' => $override->reason,
                'previous_setting' => $previous,
                'new_setting' => [
                    'extra_attempts' => (int) $override->extra_attempts,
                    'extended_end_at' => $override->extended_end_at?->toIso8601String(),
                    'allow_late_submission' => (bool) $override->allow_late_submission,
                    'attempts_reset_at' => $override->attempts_reset_at?->toIso8601String(),
                    'attempts_marked_reset' => $resetCount,
                    'is_active' => true,
                ],
            ]);

            $univ = AssessmentStudentOverride::updateOrCreate(
                [
                    'assessment_type' => 'quiz',
                    'assessment_id' => $quiz->id,
                    'student_id' => $student->id,
                ],
                [
                    'class_id' => $quiz->courseOffering?->class_id ?? $student->class_id,
                    'teacher_id' => $teacher->id,
                    'granted_by' => $actor->id,
                    'allow_submission' => true,
                    'allow_resubmission' => ((int) $override->extra_attempts) > 0 || $override->attempts_reset_at !== null,
                    'allow_late_submission' => (bool) $override->allow_late_submission,
                    'additional_attempts' => (int) $override->extra_attempts,
                    'extended_deadline' => $override->extended_end_at,
                    'attempts_reset_at' => $override->attempts_reset_at,
                    'reason' => $override->reason,
                    'is_active' => true,
                    'revoked_at' => null,
                    'revoked_by' => null,
                    'revoke_reason' => null,
                ]
            );

            $baseMax = ($quiz->max_attempts === null || (int) $quiz->max_attempts <= 0) ? null : (int) $quiz->max_attempts;
            AssessmentAuditLog::create([
                'assessment_type' => 'quiz',
                'assessment_id' => $quiz->id,
                'student_id' => $student->id,
                'class_id' => $univ->class_id,
                'teacher_id' => $teacher->id,
                'performed_by' => $actor->id,
                'action_type' => $actionLabel,
                'previous_deadline' => ! empty($previous['extended_end_at']) ? Carbon::parse((string) $previous['extended_end_at']) : null,
                'new_deadline' => $override->extended_end_at,
                'previous_attempt_limit' => $baseMax !== null ? $baseMax + (int) ($previous['extra_attempts'] ?? 0) : null,
                'new_attempt_limit' => $baseMax !== null ? $baseMax + (int) $override->extra_attempts : null,
                'previous_settings' => $previous,
                'new_settings' => [
                    'extra_attempts' => (int) $override->extra_attempts,
                    'extended_end_at' => $override->extended_end_at?->toIso8601String(),
                    'allow_late_submission' => (bool) $override->allow_late_submission,
                    'attempts_reset_at' => $override->attempts_reset_at?->toIso8601String(),
                    'is_active' => true,
                ],
                'reason' => $override->reason,
            ]);

            return $override->fresh();
        });
    }

    public function revokeStudentOverride(
        Quiz $quiz,
        Student $student,
        Teacher $teacher,
        User $actor,
        ?string $reason = null,
    ): bool {
        return DB::transaction(function () use ($quiz, $student, $teacher, $actor, $reason) {
            $override = QuizStudentOverride::where('quiz_id', $quiz->id)
                ->where('student_id', $student->id)
                ->where('is_active', true)
                ->first();

            if (! $override) {
                return false;
            }

            $previous = [
                'extra_attempts' => (int) $override->extra_attempts,
                'extended_end_at' => $override->extended_end_at?->toIso8601String(),
                'allow_late_submission' => (bool) $override->allow_late_submission,
                'is_active' => true,
            ];

            $override->update([
                'is_active' => false,
                'reason' => $reason ?: $override->reason,
            ]);

            QuizOverrideLog::create([
                'quiz_id' => $quiz->id,
                'student_id' => $student->id,
                'teacher_id' => $teacher->id,
                'acted_by' => $actor->id,
                'action' => 'revoke_override',
                'reason' => $reason,
                'previous_setting' => $previous,
                'new_setting' => ['is_active' => false],
            ]);

            AssessmentStudentOverride::forAssessment('quiz', $quiz->id)
                ->where('student_id', $student->id)
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'revoked_at' => now(),
                    'revoked_by' => $actor->id,
                    'revoke_reason' => $reason,
                ]);

            $baseMax = ($quiz->max_attempts === null || (int) $quiz->max_attempts <= 0) ? null : (int) $quiz->max_attempts;
            AssessmentAuditLog::create([
                'assessment_type' => 'quiz',
                'assessment_id' => $quiz->id,
                'student_id' => $student->id,
                'class_id' => $quiz->courseOffering?->class_id ?? $student->class_id,
                'teacher_id' => $teacher->id,
                'performed_by' => $actor->id,
                'action_type' => 'revoke_reopen',
                'previous_deadline' => ! empty($previous['extended_end_at']) ? Carbon::parse((string) $previous['extended_end_at']) : null,
                'new_deadline' => $quiz->end_at,
                'previous_attempt_limit' => $baseMax !== null ? $baseMax + (int) ($previous['extra_attempts'] ?? 0) : null,
                'new_attempt_limit' => $baseMax,
                'previous_settings' => $previous,
                'new_settings' => ['is_active' => false],
                'reason' => $reason,
            ]);

            return true;
        });
    }
}
