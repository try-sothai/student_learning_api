<?php

namespace App\Services;

use App\Models\AssessmentAuditLog;
use App\Models\AssessmentStudentOverride;
use App\Models\Assignment;
use App\Models\AssignmentAttempt;
use App\Models\AssignmentSubmission;
use App\Models\CourseEnrollment;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizOverrideLog;
use App\Models\QuizStudentOverride;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Single source of truth for Assignment, Homework, and Quiz reopen,
 * deadline extension, attempt grants, revocations, and audit logging.
 */
class AssessmentReopenService
{
    public const VALID_TYPES = ['assignment', 'homework', 'quiz'];

    public function __construct(
        protected QuizAvailabilityService $quizAvailability,
    ) {}

    public function normalizeType(string $type): string
    {
        $norm = strtolower(trim($type));
        if (! in_array($norm, self::VALID_TYPES, true)) {
            throw ValidationException::withMessages([
                'assessment_type' => ['assessment_type must be one of: assignment, homework, quiz.'],
            ]);
        }

        return $norm;
    }

    /**
     * Resolve an Assignment, Homework, or Quiz strictly matching the requested assessment_type.
     */
    public function resolveAssessment(string $type, int $id): Assignment|Quiz
    {
        $norm = $this->normalizeType($type);

        if ($norm === 'quiz') {
            return Quiz::with(['course', 'courseOffering.classRoom', 'semester'])->findOrFail($id);
        }

        $assignment = Assignment::with(['course', 'courseOffering.classRoom', 'semester'])->findOrFail($id);
        $actualType = $assignment->canonicalAssessmentType();

        if ($norm === 'homework' && $actualType !== 'homework') {
            abort(response()->json([
                'success' => false,
                'message' => 'Requested assessment is not a homework item.',
                'errors' => ['assessment_type' => ['The specified assignment is not a homework assessment.']],
            ], 422));
        }

        if ($norm === 'assignment' && $actualType === 'homework') {
            abort(response()->json([
                'success' => false,
                'message' => 'Requested assessment is a homework item; pass assessment_type=homework.',
                'errors' => ['assessment_type' => ['The specified ID belongs to a homework assessment.']],
            ], 422));
        }

        return $assignment;
    }

    public function resolveClassId(Model $assessment, ?Student $student = null): ?int
    {
        if ($assessment instanceof Assignment || $assessment instanceof Quiz) {
            $classId = $assessment->courseOffering?->class_id;
            if ($classId) {
                return (int) $classId;
            }
        }

        return $student?->class_id ? (int) $student->class_id : null;
    }

    /**
     * All students enrolled in the assessment's offering (plus anyone with existing submissions/attempts/overrides).
     *
     * @return array<int>
     */
    public function eligibleStudentIds(Model $assessment): array
    {
        $enrolledQuery = CourseEnrollment::query()->where('status', 'enrolled');

        if (! empty($assessment->course_offering_id)) {
            $enrolledQuery->where(function ($q) use ($assessment) {
                $q->where('course_offering_id', $assessment->course_offering_id)
                    ->orWhere(function ($sq) use ($assessment) {
                        $sq->whereNull('course_offering_id')
                            ->where('course_id', $assessment->course_id)
                            ->where('semester_id', $assessment->semester_id);
                    });
            });
        } else {
            $enrolledQuery->where('course_id', $assessment->course_id)
                ->when($assessment->semester_id, fn ($q) => $q->where('semester_id', $assessment->semester_id));
        }

        $enrolledIds = $enrolledQuery->pluck('student_id')->all();

        if ($assessment instanceof Quiz) {
            $activityIds = QuizAttempt::where('quiz_id', $assessment->id)->pluck('student_id')->all();
            $legacyOvIds = QuizStudentOverride::where('quiz_id', $assessment->id)->pluck('student_id')->all();
            $univOvIds = AssessmentStudentOverride::forAssessment('quiz', $assessment->id)->pluck('student_id')->all();

            return collect(array_merge($enrolledIds, $activityIds, $legacyOvIds, $univOvIds))
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        $type = $assessment->canonicalAssessmentType();
        $subIds = AssignmentSubmission::where('assignment_id', $assessment->id)->pluck('student_id')->all();
        $attIds = AssignmentAttempt::where('assignment_id', $assessment->id)->pluck('student_id')->all();
        $ovIds = AssessmentStudentOverride::forAssessment($type, $assessment->id)->pluck('student_id')->all();

        return collect(array_merge($enrolledIds, $subIds, $attIds, $ovIds))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function getActiveOverride(string $type, int $assessmentId, int $studentId): ?AssessmentStudentOverride
    {
        return AssessmentStudentOverride::forAssessment($type, $assessmentId)
            ->where('student_id', $studentId)
            ->active()
            ->first();
    }

    public function originalDeadline(Model $assessment): ?Carbon
    {
        if ($assessment instanceof Quiz) {
            return $assessment->end_at?->copy();
        }

        /** @var Assignment $assessment */
        if ($assessment->ends_at !== null) {
            return $assessment->ends_at->copy();
        }

        if ($assessment->due_date === null) {
            return null;
        }

        $due = $assessment->due_date->copy();

        return $due->format('H:i:s') === '00:00:00' ? $due->endOfDay() : $due;
    }

    public function originalAttemptLimit(Model $assessment): ?int
    {
        if ($assessment instanceof Quiz) {
            $raw = $assessment->max_attempts;

            return ($raw === null || (int) $raw <= 0) ? null : (int) $raw;
        }

        /** @var Assignment $assessment */
        if ($assessment->allow_retakes) {
            return max(1, (int) ($assessment->max_attempts ?: 1));
        }

        return max(1, (int) ($assessment->max_attempts ?: 1));
    }

    /**
     * Evaluate unified access state for any assessment type (assignment | homework | quiz) and student.
     *
     * @return array<string, mixed>
     */
    public function evaluateAccess(Model $assessment, Student $student, ?Carbon $now = null): array
    {
        if ($assessment instanceof Quiz) {
            return $this->evaluateQuizAccess($assessment, $student, $now);
        }

        /** @var Assignment $assessment */
        return $this->evaluateAssignmentAccess($assessment, $student, $now);
    }

    /**
     * Evaluate complete permissions and status for an Assignment or Homework.
     *
     * @return array<string, mixed>
     */
    public function evaluateAssignmentAccess(Assignment $assignment, Student $student, ?Carbon $now = null): array
    {
        $now = $now ?? now();
        $type = $assignment->canonicalAssessmentType();
        $override = $this->getActiveOverride($type, $assignment->id, $student->id);

        $submissions = AssignmentSubmission::where('assignment_id', $assignment->id)
            ->where('student_id', $student->id)
            ->orderByDesc('version_number')
            ->orderByDesc('id')
            ->get();

        $webAttempts = AssignmentAttempt::where('assignment_id', $assignment->id)
            ->where('student_id', $student->id)
            ->orderByDesc('attempt_number')
            ->orderByDesc('id')
            ->get();

        $latestSubmission = $submissions->first();
        $latestWebAttempt = $webAttempts->first();

        $usedAttempts = $submissions->count() + $webAttempts->whereNotNull('submitted_at')->count();
        $inProgressAttempt = $webAttempts->first(fn (AssignmentAttempt $a) => $a->submitted_at === null);

        $originalLimit = $this->originalAttemptLimit($assignment) ?? 1;
        $additionalAttempts = ($override && $override->is_active) ? max(0, (int) $override->additional_attempts) : 0;
        $effectiveLimit = $originalLimit + $additionalAttempts;
        $remainingAttempts = max(0, $effectiveLimit - $usedAttempts);

        $originalDeadline = $this->originalDeadline($assignment);
        $reopenedUntil = $assignment->reopened_until?->copy();
        $extendedDeadline = ($override && $override->is_active) ? $override->extended_deadline?->copy() : null;

        $effectiveDeadline = null;
        foreach ([$originalDeadline, $reopenedUntil, $extendedDeadline] as $candidate) {
            if ($candidate && (! $effectiveDeadline || $candidate->gt($effectiveDeadline))) {
                $effectiveDeadline = $candidate->copy();
            }
        }

        $allowSubmission = ($override && $override->is_active) ? (bool) $override->allow_submission : true;
        $allowLate = ($override && $override->is_active && $override->allow_late_submission)
            || (bool) $assignment->allow_late_submission;
        $allowResubmission = ($override && $override->is_active && $override->allow_resubmission)
            || (bool) $assignment->allow_resubmission
            || (bool) $assignment->allow_retakes;

        $status = strtolower((string) $assignment->status);
        $windowState = $this->resolveAssignmentWindowState(
            $assignment,
            $status,
            $override,
            $originalDeadline,
            $effectiveDeadline,
            $allowLate,
            $allowSubmission,
            $now
        );
        $windowOpen = in_array($windowState, ['open', 'late_open'], true);

        $latestIsGraded = $latestSubmission !== null
            && strtolower((string) $latestSubmission->status) === 'graded';
        $overrideAllowsResubmit = $override && $override->is_active
            && ($override->allow_resubmission || $override->additional_attempts > 0);

        if ($usedAttempts === 0) {
            $canSubmit = $windowOpen && $allowSubmission && $remainingAttempts > 0;
            $canResubmit = false;
        } else {
            $canResubmit = $windowOpen
                && $allowSubmission
                && $allowResubmission
                && $remainingAttempts > 0
                && (! $latestIsGraded || $overrideAllowsResubmit);
            $canSubmit = $canResubmit;
        }

        $blockedReason = null;
        if (! $canSubmit) {
            if ($windowState === 'upcoming') {
                $blockedReason = 'This assessment has not opened yet.';
            } elseif (! $allowSubmission) {
                $blockedReason = 'Submission permission has been revoked for your account.';
            } elseif ($usedAttempts > 0 && $latestIsGraded && ! $overrideAllowsResubmit) {
                $blockedReason = 'This submission has already been graded. Request teacher authorization to resubmit.';
            } elseif ($usedAttempts > 0 && ! $allowResubmission) {
                $blockedReason = 'Resubmission requires explicit teacher authorization.';
            } elseif ($remainingAttempts <= 0) {
                $blockedReason = "You have used all allowed submission attempts ({$usedAttempts}/{$effectiveLimit}).";
            } elseif ($windowState === 'closed') {
                $blockedReason = $status === 'closed'
                    ? 'This assessment is closed.'
                    : 'The deadline for this assessment has passed.';
            }
        }

        $latestWasLate = ($latestSubmission && ($latestSubmission->is_late || strtolower((string) $latestSubmission->status) === 'late'))
            || ($latestWebAttempt && $originalDeadline && $latestWebAttempt->submitted_at && $latestWebAttempt->submitted_at->gt($originalDeadline));

        if ($inProgressAttempt) {
            $studentSubmissionStatus = 'In Progress';
        } elseif ($usedAttempts > 0) {
            $studentSubmissionStatus = $latestWasLate ? 'Late Submitted' : 'Submitted';
        } else {
            $studentSubmissionStatus = 'Not Submitted';
        }

        $hasActiveOverride = $override !== null && $override->is_active;
        $extensionOnly = $hasActiveOverride
            && $override->extended_deadline !== null
            && ! $override->allow_late_submission
            && ! $override->allow_resubmission
            && (int) $override->additional_attempts === 0;

        $hasGlobalReopen = $assignment->reopened_at !== null && $windowOpen;

        if ($inProgressAttempt && $canSubmit) {
            $reopenStatus = $hasActiveOverride
                ? ($extensionOnly ? 'Extended' : 'Reopened')
                : 'In Progress';
        } elseif ($canSubmit) {
            if ($hasActiveOverride) {
                $reopenStatus = $extensionOnly ? 'Extended' : 'Reopened';
            } elseif ($hasGlobalReopen) {
                $reopenStatus = 'Reopened';
            } elseif ($usedAttempts > 0) {
                $reopenStatus = $latestWasLate ? 'Late Submitted' : 'Submitted';
            } else {
                $reopenStatus = 'Not Submitted';
            }
        } else {
            if ($usedAttempts > 0) {
                $reopenStatus = $latestWasLate ? 'Late Submitted' : 'Submitted';
            } elseif ($windowState === 'closed') {
                $reopenStatus = 'Expired';
            } else {
                $reopenStatus = 'Not Submitted';
            }
        }

        return [
            'assessment_type' => $type,
            'assessment_id' => $assignment->id,
            'class_id' => $this->resolveClassId($assignment, $student),
            'student_id' => $student->id,
            'original_deadline' => $originalDeadline?->toIso8601String(),
            'extended_deadline' => $extendedDeadline?->toIso8601String(),
            'effective_deadline' => $effectiveDeadline?->toIso8601String(),
            'student_submission_status' => $studentSubmissionStatus,
            'reopen_status' => $reopenStatus,
            'window_state' => $windowState,
            'original_attempt_limit' => $originalLimit,
            'additional_attempts' => $additionalAttempts,
            'effective_attempt_limit' => $effectiveLimit,
            'used_attempts' => $usedAttempts,
            'remaining_attempts' => $remainingAttempts,
            'allow_submission' => $allowSubmission,
            'allow_late_submission' => $allowLate,
            'allow_resubmission' => $allowResubmission,
            'can_submit' => $canSubmit,
            'can_resubmit' => $canResubmit,
            'can_start' => $canSubmit,
            'can_continue' => (bool) $inProgressAttempt && $canSubmit,
            'has_active_override' => $hasActiveOverride,
            'blocked_reason' => $blockedReason,
            'server_time' => $now->toIso8601String(),
            'latest_submission' => $latestSubmission,
            'submissions' => $submissions,
            'override_model' => $override,
            'override' => $override ? $this->presentOverride($override) : null,
        ];
    }

    /**
     * Evaluate unified permissions and status for a Quiz.
     *
     * @return array<string, mixed>
     */
    public function evaluateQuizAccess(Quiz $quiz, Student $student, ?Carbon $now = null): array
    {
        $now = $now ?? now();
        $quizAccess = $this->quizAvailability->evaluateStudentAccess($quiz, $student);
        $univOverride = $this->getActiveOverride('quiz', $quiz->id, $student->id);
        /** @var QuizStudentOverride|null $legacyOverride */
        $legacyOverride = $quizAccess['override'];

        $originalDeadline = $quiz->end_at?->copy();
        $extendedDeadline = $univOverride?->extended_deadline?->copy()
            ?? $legacyOverride?->extended_end_at?->copy();
        $effectiveDeadline = $quizAccess['effective_end_at']?->copy();

        $inProgressAttempt = $quizAccess['in_progress_attempt'];
        $usedAttempts = (int) $quizAccess['attempts_used'];
        $baseLimit = $quizAccess['base_max_attempts'];
        $additionalAttempts = (int) ($univOverride?->additional_attempts ?? $quizAccess['extra_attempts'] ?? 0);
        $effectiveLimit = $quizAccess['max_attempts'];
        $remaining = $quizAccess['attempts_remaining'];

        $allowSubmission = $univOverride ? (bool) $univOverride->allow_submission : true;
        $allowLate = (bool) $quizAccess['allow_late_submission'];
        $allowResubmission = ($univOverride && $univOverride->allow_resubmission)
            || ($effectiveLimit === null)
            || ($effectiveLimit > 1)
            || $additionalAttempts > 0;

        $canStart = (bool) $quizAccess['can_start'] && $allowSubmission;
        $canContinue = (bool) $quizAccess['can_continue'] && $allowSubmission;
        $canSubmit = $canStart || $canContinue;
        $blockedReason = $quizAccess['blocked_reason'];
        if (! $allowSubmission) {
            $blockedReason = 'Submission permission for this quiz has been revoked by your instructor.';
        }

        /** @var QuizAttempt|null $latestSubmitted */
        $latestSubmitted = $quizAccess['active_attempts']->first(fn (QuizAttempt $a) => $a->status !== 'in_progress');
        $hasSubmitted = $usedAttempts > 0;

        if ($inProgressAttempt) {
            $studentSubmissionStatus = 'In Progress';
        } elseif ($hasSubmitted) {
            $studentSubmissionStatus = $latestSubmitted?->is_late ? 'Late Submitted' : 'Submitted';
        } else {
            $studentSubmissionStatus = 'Not Submitted';
        }

        $hasActiveOverride = ($univOverride && $univOverride->is_active) || ($legacyOverride && $legacyOverride->is_active);
        $extensionOnly = $hasActiveOverride
            && $extendedDeadline !== null
            && ! ($univOverride?->allow_late_submission ?? $legacyOverride?->allow_late_submission ?? false)
            && ! ($univOverride?->allow_resubmission ?? false)
            && $additionalAttempts === 0;
        $hasGlobalReopen = $quiz->reopened_at !== null && in_array($quizAccess['window_state'], ['open', 'late_open'], true);

        if ($inProgressAttempt && $canContinue) {
            $overriddenAfterStart = $hasActiveOverride && (
                ($univOverride?->updated_at && $inProgressAttempt->started_at && $univOverride->updated_at->gt($inProgressAttempt->started_at))
                || ($legacyOverride?->updated_at && $inProgressAttempt->started_at && $legacyOverride->updated_at->gt($inProgressAttempt->started_at))
            );
            $reopenStatus = $overriddenAfterStart
                ? ($extensionOnly ? 'Extended' : 'Reopened')
                : 'In Progress';
        } elseif ($canSubmit) {
            if ($hasActiveOverride) {
                $reopenStatus = $extensionOnly ? 'Extended' : 'Reopened';
            } elseif ($hasGlobalReopen) {
                $reopenStatus = 'Reopened';
            } elseif ($hasSubmitted) {
                $reopenStatus = $latestSubmitted?->is_late ? 'Late Submitted' : 'Submitted';
            } else {
                $reopenStatus = 'Not Submitted';
            }
        } else {
            if ($hasSubmitted) {
                $reopenStatus = $latestSubmitted?->is_late ? 'Late Submitted' : 'Submitted';
            } elseif ($quizAccess['window_state'] === 'closed') {
                $reopenStatus = 'Expired';
            } else {
                $reopenStatus = 'Not Submitted';
            }
        }

        return [
            'assessment_type' => 'quiz',
            'assessment_id' => $quiz->id,
            'class_id' => $this->resolveClassId($quiz, $student),
            'student_id' => $student->id,
            'original_deadline' => $originalDeadline?->toIso8601String(),
            'extended_deadline' => $extendedDeadline?->toIso8601String(),
            'effective_deadline' => $effectiveDeadline?->toIso8601String(),
            'student_submission_status' => $studentSubmissionStatus,
            'reopen_status' => $reopenStatus,
            'window_state' => $quizAccess['window_state'],
            'original_attempt_limit' => $baseLimit,
            'additional_attempts' => $additionalAttempts,
            'effective_attempt_limit' => $effectiveLimit,
            'used_attempts' => $usedAttempts,
            'remaining_attempts' => $remaining,
            'allow_submission' => $allowSubmission,
            'allow_late_submission' => $allowLate,
            'allow_resubmission' => $allowResubmission,
            'can_submit' => $canSubmit,
            'can_resubmit' => $canStart && $hasSubmitted,
            'can_start' => $canStart,
            'can_continue' => $canContinue,
            'has_active_override' => $hasActiveOverride,
            'blocked_reason' => $blockedReason,
            'server_time' => $now->toIso8601String(),
            'quiz_access' => $quizAccess,
            'override_model' => $univOverride,
            'override' => $univOverride
                ? $this->presentOverride($univOverride)
                : ($legacyOverride ? [
                    'id' => $legacyOverride->id,
                    'allow_submission' => true,
                    'allow_resubmission' => $legacyOverride->extra_attempts > 0 || $legacyOverride->attempts_reset_at !== null,
                    'allow_late_submission' => (bool) $legacyOverride->allow_late_submission,
                    'additional_attempts' => (int) $legacyOverride->extra_attempts,
                    'extended_deadline' => $legacyOverride->extended_end_at?->toIso8601String(),
                    'reason' => $legacyOverride->reason,
                    'teacher_notes' => null,
                    'is_active' => (bool) $legacyOverride->is_active,
                ] : null),
        ];
    }

    private function resolveAssignmentWindowState(
        Assignment $assignment,
        string $status,
        ?AssessmentStudentOverride $override,
        ?Carbon $originalDeadline,
        ?Carbon $effectiveDeadline,
        bool $allowLate,
        bool $allowSubmission,
        Carbon $now
    ): string {
        if ($status === 'draft' || $status === 'archived') {
            return 'closed';
        }

        if ($override && $override->is_active) {
            if (! $allowSubmission) {
                return 'closed';
            }
            if ($override->extended_deadline) {
                if ($now->lte($override->extended_deadline)) {
                    return ($originalDeadline && $now->gt($originalDeadline)) ? 'late_open' : 'open';
                }
                if ($override->allow_late_submission) {
                    return 'late_open';
                }

                return 'closed';
            }
            if ($override->allow_late_submission) {
                if ($status === 'closed' || ($originalDeadline && $now->gt($originalDeadline))) {
                    return 'late_open';
                }

                return 'open';
            }
            // Active override without new deadline/late flag on a closed assessment -> keep open if allow_resubmission or additional_attempts?
            // Only if original deadline hasn't passed or global reopen is still valid.
            if ($status === 'closed') {
                return 'closed';
            }
        } elseif ($status === 'closed') {
            return 'closed';
        }

        if ($assignment->starts_at && $now->lt($assignment->starts_at)) {
            return 'upcoming';
        }

        if ($effectiveDeadline && $now->gt($effectiveDeadline)) {
            return $allowLate ? 'late_open' : 'closed';
        }

        return 'open';
    }

    /**
     * C.2: Reopen assessment for selected student(s) or globally for the assessment.
     *
     * @param array<int> $studentIds
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    public function reopenAssessment(
        Model $assessment,
        string $type,
        array $studentIds,
        Teacher $teacher,
        User $actor,
        array $payload
    ): array {
        $normType = $this->normalizeType($type);
        $newDeadline = ! empty($payload['new_deadline']) ? Carbon::parse((string) $payload['new_deadline']) : null;
        $reason = isset($payload['reason']) ? (trim((string) $payload['reason']) ?: null) : null;
        $teacherNotes = isset($payload['teacher_notes']) ? (trim((string) $payload['teacher_notes']) ?: null) : null;

        if (empty($studentIds) && ($payload['scope'] ?? null) === 'assessment') {
            return $this->reopenAssessmentGlobally($assessment, $normType, $teacher, $actor, $newDeadline, $payload, $reason, $teacherNotes);
        }

        $this->assertStudentsEligible($assessment, $studentIds);

        return DB::transaction(function () use ($assessment, $normType, $studentIds, $teacher, $actor, $payload, $newDeadline, $reason, $teacherNotes) {
            $results = [];

            foreach ($studentIds as $studentId) {
                $student = Student::findOrFail($studentId);
                $beforeState = $this->evaluateAccess($assessment, $student);

                $override = AssessmentStudentOverride::forAssessment($normType, $assessment->id)
                    ->where('student_id', $studentId)
                    ->lockForUpdate()
                    ->first();

                $prevDeadline = $override && $override->is_active && $override->extended_deadline
                    ? $override->extended_deadline->copy()
                    : $this->originalDeadline($assessment);
                $prevLimit = $beforeState['effective_attempt_limit'];

                $prevSettings = $override ? $this->presentOverride($override) : [
                    'is_active' => false,
                    'allow_submission' => true,
                    'allow_resubmission' => (bool) ($assessment->allow_resubmission ?? false),
                    'allow_late_submission' => (bool) ($assessment->allow_late_submission ?? false),
                    'additional_attempts' => 0,
                    'extended_deadline' => null,
                ];

                if (! $override) {
                    $override = new AssessmentStudentOverride([
                        'assessment_type' => $normType,
                        'assessment_id' => $assessment->id,
                        'student_id' => $studentId,
                    ]);
                }

                $override->class_id = $this->resolveClassId($assessment, $student);
                $override->teacher_id = $teacher->id;
                $override->granted_by = $actor->id;

                $allowSubmission = array_key_exists('allow_submission', $payload) && $payload['allow_submission'] !== null
                    ? (bool) $payload['allow_submission']
                    : true;
                $allowResubmission = array_key_exists('allow_resubmission', $payload) && $payload['allow_resubmission'] !== null
                    ? (bool) $payload['allow_resubmission']
                    : (bool) ($override->is_active ? $override->allow_resubmission : ($beforeState['used_attempts'] > 0));
                $allowLate = array_key_exists('allow_late_submission', $payload) && $payload['allow_late_submission'] !== null
                    ? (bool) $payload['allow_late_submission']
                    : (bool) ($override->is_active ? $override->allow_late_submission : true);

                $currentExtra = $override->is_active ? (int) $override->additional_attempts : 0;
                $added = max(0, (int) ($payload['additional_attempts'] ?? 0));
                $newExtra = $currentExtra + $added;

                $hasTargetLimit = array_key_exists('target_attempt_limit', $payload)
                    && $payload['target_attempt_limit'] !== null
                    && $payload['target_attempt_limit'] !== '';
                if ($hasTargetLimit) {
                    $baseLim = $this->originalAttemptLimit($assessment);
                    if ($baseLim !== null) {
                        $targetLimit = (int) $payload['target_attempt_limit'];
                        if ($targetLimit < $baseLim) {
                            throw ValidationException::withMessages([
                                'target_attempt_limit' => ["Student attempt limit must be at least the base limit ({$baseLim})."],
                            ]);
                        }
                        $newExtra = $targetLimit - $baseLim;
                        if ($targetLimit > $baseLim && (! array_key_exists('allow_resubmission', $payload) || $payload['allow_resubmission'] === null)) {
                            $allowResubmission = true;
                        }
                    }
                }

                $resetAttempts = ! empty($payload['reset_attempts']);
                if ($normType === 'quiz' && $resetAttempts) {
                    QuizAttempt::where('quiz_id', $assessment->id)
                        ->where('student_id', $studentId)
                        ->where('is_reset', false)
                        ->update(['is_reset' => true]);
                    $override->attempts_reset_at = now();
                }

                // If teacher enabled resubmission for a student who exhausted attempts without specifying additional_attempts,
                // automatically grant +1 attempt so resubmission actually works.
                if (! $hasTargetLimit && $added === 0 && $allowResubmission && ! $resetAttempts) {
                    $baseLim = $this->originalAttemptLimit($assessment);
                    if ($baseLim !== null && ($baseLim + $newExtra) <= (int) $beforeState['used_attempts']) {
                        $newExtra = ((int) $beforeState['used_attempts'] - $baseLim) + 1;
                    }
                }

                $override->allow_submission = $allowSubmission;
                $override->allow_resubmission = $allowResubmission;
                $override->allow_late_submission = $allowLate;
                $override->additional_attempts = $newExtra;
                if ($newDeadline !== null) {
                    $override->extended_deadline = $newDeadline;
                }
                if ($reason !== null) {
                    $override->reason = $reason;
                }
                if ($teacherNotes !== null) {
                    $override->teacher_notes = $teacherNotes;
                }
                $override->is_active = true;
                $override->revoked_at = null;
                $override->revoked_by = null;
                $override->revoke_reason = null;
                $override->updated_at = now();
                $override->save();

                if ($normType === 'quiz') {
                    $this->syncQuizOverrideRow($assessment, $student, $teacher, $actor, $override, 'reopen', $prevSettings);
                }

                $afterState = $this->evaluateAccess($assessment, $student);

                AssessmentAuditLog::create([
                    'assessment_type' => $normType,
                    'assessment_id' => $assessment->id,
                    'student_id' => $studentId,
                    'class_id' => $override->class_id,
                    'teacher_id' => $teacher->id,
                    'performed_by' => $actor->id,
                    'action_type' => 'reopen',
                    'previous_deadline' => $prevDeadline,
                    'new_deadline' => $override->extended_deadline,
                    'previous_attempt_limit' => $prevLimit,
                    'new_attempt_limit' => $afterState['effective_attempt_limit'],
                    'previous_settings' => $prevSettings,
                    'new_settings' => $this->presentOverride($override),
                    'reason' => $reason,
                    'teacher_notes' => $teacherNotes,
                ]);

                $results[] = $afterState;
            }

            return $results;
        });
    }

    private function reopenAssessmentGlobally(
        Model $assessment,
        string $normType,
        Teacher $teacher,
        User $actor,
        ?Carbon $newDeadline,
        array $payload,
        ?string $reason,
        ?string $teacherNotes
    ): array {
        return DB::transaction(function () use ($assessment, $normType, $teacher, $actor, $newDeadline, $payload, $reason, $teacherNotes) {
            $prevDeadline = $this->originalDeadline($assessment);
            $prevLimit = $this->originalAttemptLimit($assessment);

            if ($assessment instanceof Quiz) {
                $prevSettings = [
                    'status' => $assessment->status,
                    'end_at' => $assessment->end_at?->toIso8601String(),
                    'reopened_until' => $assessment->reopened_until?->toIso8601String(),
                    'allow_late_submission' => (bool) $assessment->allow_late_submission,
                ];
                $updates = [
                    'status' => 'published',
                    'reopened_at' => now(),
                    'reopened_by' => $actor->id,
                ];
                if ($newDeadline !== null) {
                    $updates['reopened_until'] = $newDeadline;
                }
                if (array_key_exists('allow_late_submission', $payload) && $payload['allow_late_submission'] !== null) {
                    $updates['allow_late_submission'] = (bool) $payload['allow_late_submission'];
                } elseif ($newDeadline === null && $assessment->end_at && $assessment->end_at->isPast()) {
                    $updates['allow_late_submission'] = true;
                }
                $assessment->update($updates);
                $fresh = $assessment->fresh();

                $newSettings = [
                    'status' => $fresh->status,
                    'end_at' => $fresh->end_at?->toIso8601String(),
                    'reopened_until' => $fresh->reopened_until?->toIso8601String(),
                    'allow_late_submission' => (bool) $fresh->allow_late_submission,
                ];

                QuizOverrideLog::create([
                    'quiz_id' => $fresh->id,
                    'student_id' => null,
                    'teacher_id' => $teacher->id,
                    'acted_by' => $actor->id,
                    'action' => 'reopen_quiz',
                    'reason' => $reason,
                    'previous_setting' => $prevSettings,
                    'new_setting' => $newSettings,
                ]);
            } else {
                /** @var Assignment $assessment */
                $prevSettings = [
                    'status' => $assessment->status,
                    'due_date' => $assessment->due_date?->toIso8601String(),
                    'reopened_until' => $assessment->reopened_until?->toIso8601String(),
                    'allow_late_submission' => (bool) $assessment->allow_late_submission,
                    'allow_resubmission' => (bool) $assessment->allow_resubmission,
                ];
                $updates = [
                    'status' => 'published',
                    'reopened_at' => now(),
                    'reopened_by' => $actor->id,
                ];
                if ($newDeadline !== null) {
                    $updates['reopened_until'] = $newDeadline;
                }
                if (array_key_exists('allow_late_submission', $payload) && $payload['allow_late_submission'] !== null) {
                    $updates['allow_late_submission'] = (bool) $payload['allow_late_submission'];
                } elseif ($newDeadline === null && $prevDeadline && $prevDeadline->isPast()) {
                    $updates['allow_late_submission'] = true;
                }
                if (array_key_exists('allow_resubmission', $payload) && $payload['allow_resubmission'] !== null) {
                    $updates['allow_resubmission'] = (bool) $payload['allow_resubmission'];
                }
                $assessment->update($updates);
                $fresh = $assessment->fresh();

                $newSettings = [
                    'status' => $fresh->status,
                    'due_date' => $fresh->due_date?->toIso8601String(),
                    'reopened_until' => $fresh->reopened_until?->toIso8601String(),
                    'allow_late_submission' => (bool) $fresh->allow_late_submission,
                    'allow_resubmission' => (bool) $fresh->allow_resubmission,
                ];
            }

            AssessmentAuditLog::create([
                'assessment_type' => $normType,
                'assessment_id' => $assessment->id,
                'student_id' => null,
                'class_id' => $this->resolveClassId($assessment),
                'teacher_id' => $teacher->id,
                'performed_by' => $actor->id,
                'action_type' => 'reopen_assessment',
                'previous_deadline' => $prevDeadline,
                'new_deadline' => $fresh->reopened_until,
                'previous_attempt_limit' => $prevLimit,
                'new_attempt_limit' => $prevLimit,
                'previous_settings' => $prevSettings,
                'new_settings' => $newSettings,
                'reason' => $reason,
                'teacher_notes' => $teacherNotes,
            ]);

            return [];
        });
    }

    /**
     * C.3: Extend deadline for one or multiple students without mutating original assessment deadline.
     *
     * @param array<int> $studentIds
     * @return array<int, array<string, mixed>>
     */
    public function extendDeadline(
        Model $assessment,
        string $type,
        array $studentIds,
        Carbon $newDeadline,
        Teacher $teacher,
        User $actor,
        ?string $reason = null,
        ?string $teacherNotes = null
    ): array {
        $normType = $this->normalizeType($type);
        $this->assertStudentsEligible($assessment, $studentIds);

        return DB::transaction(function () use ($assessment, $normType, $studentIds, $newDeadline, $teacher, $actor, $reason, $teacherNotes) {
            $results = [];

            foreach ($studentIds as $studentId) {
                $student = Student::findOrFail($studentId);
                $beforeState = $this->evaluateAccess($assessment, $student);

                $override = AssessmentStudentOverride::forAssessment($normType, $assessment->id)
                    ->where('student_id', $studentId)
                    ->lockForUpdate()
                    ->first();

                $prevDeadline = $override && $override->is_active && $override->extended_deadline
                    ? $override->extended_deadline->copy()
                    : $this->originalDeadline($assessment);
                $prevLimit = $beforeState['effective_attempt_limit'];
                $prevSettings = $override ? $this->presentOverride($override) : ['is_active' => false];

                if (! $override) {
                    $override = new AssessmentStudentOverride([
                        'assessment_type' => $normType,
                        'assessment_id' => $assessment->id,
                        'student_id' => $studentId,
                        'allow_submission' => true,
                        'allow_resubmission' => false,
                        'allow_late_submission' => false,
                        'additional_attempts' => 0,
                    ]);
                }

                $override->class_id = $this->resolveClassId($assessment, $student);
                $override->teacher_id = $teacher->id;
                $override->granted_by = $actor->id;
                $override->allow_submission = true;
                $override->extended_deadline = $newDeadline;
                if ($reason !== null) {
                    $override->reason = $reason;
                }
                if ($teacherNotes !== null) {
                    $override->teacher_notes = $teacherNotes;
                }
                $override->is_active = true;
                $override->revoked_at = null;
                $override->revoked_by = null;
                $override->revoke_reason = null;
                $override->updated_at = now();
                $override->save();

                if ($normType === 'quiz') {
                    $this->syncQuizOverrideRow($assessment, $student, $teacher, $actor, $override, 'extend_deadline', $prevSettings);
                }

                $afterState = $this->evaluateAccess($assessment, $student);

                AssessmentAuditLog::create([
                    'assessment_type' => $normType,
                    'assessment_id' => $assessment->id,
                    'student_id' => $studentId,
                    'class_id' => $override->class_id,
                    'teacher_id' => $teacher->id,
                    'performed_by' => $actor->id,
                    'action_type' => 'extend_deadline',
                    'previous_deadline' => $prevDeadline,
                    'new_deadline' => $newDeadline,
                    'previous_attempt_limit' => $prevLimit,
                    'new_attempt_limit' => $afterState['effective_attempt_limit'],
                    'previous_settings' => $prevSettings,
                    'new_settings' => $this->presentOverride($override),
                    'reason' => $reason,
                    'teacher_notes' => $teacherNotes,
                ]);

                $results[] = $afterState;
            }

            return $results;
        });
    }

    /**
     * C.4: Grant additional attempts to one or more students while preserving existing submissions/attempts.
     *
     * @param array<int> $studentIds
     * @return array<int, array<string, mixed>>
     */
    public function grantAdditionalAttempts(
        Model $assessment,
        string $type,
        array $studentIds,
        int $additionalAttempts,
        Teacher $teacher,
        User $actor,
        ?string $reason = null,
        ?string $teacherNotes = null
    ): array {
        $normType = $this->normalizeType($type);
        $this->assertStudentsEligible($assessment, $studentIds);

        return DB::transaction(function () use ($assessment, $normType, $studentIds, $additionalAttempts, $teacher, $actor, $reason, $teacherNotes) {
            $results = [];

            foreach ($studentIds as $studentId) {
                $student = Student::findOrFail($studentId);
                $beforeState = $this->evaluateAccess($assessment, $student);

                $override = AssessmentStudentOverride::forAssessment($normType, $assessment->id)
                    ->where('student_id', $studentId)
                    ->lockForUpdate()
                    ->first();

                $prevLimit = $beforeState['effective_attempt_limit'];
                $prevDeadline = $override && $override->is_active && $override->extended_deadline
                    ? $override->extended_deadline->copy()
                    : $this->originalDeadline($assessment);
                $prevSettings = $override ? $this->presentOverride($override) : ['is_active' => false];

                if (! $override) {
                    $override = new AssessmentStudentOverride([
                        'assessment_type' => $normType,
                        'assessment_id' => $assessment->id,
                        'student_id' => $studentId,
                        'allow_submission' => true,
                        'allow_resubmission' => true,
                        'allow_late_submission' => false,
                        'additional_attempts' => 0,
                    ]);
                }

                $currentExtra = $override->is_active ? (int) $override->additional_attempts : 0;
                $override->class_id = $this->resolveClassId($assessment, $student);
                $override->teacher_id = $teacher->id;
                $override->granted_by = $actor->id;
                $override->allow_submission = true;
                $override->allow_resubmission = true;
                $override->additional_attempts = $currentExtra + max(1, $additionalAttempts);
                if ($reason !== null) {
                    $override->reason = $reason;
                }
                if ($teacherNotes !== null) {
                    $override->teacher_notes = $teacherNotes;
                }
                $override->is_active = true;
                $override->revoked_at = null;
                $override->revoked_by = null;
                $override->revoke_reason = null;
                $override->updated_at = now();
                $override->save();

                if ($normType === 'quiz') {
                    $this->syncQuizOverrideRow($assessment, $student, $teacher, $actor, $override, 'grant_extra_attempt', $prevSettings);
                }

                $afterState = $this->evaluateAccess($assessment, $student);

                AssessmentAuditLog::create([
                    'assessment_type' => $normType,
                    'assessment_id' => $assessment->id,
                    'student_id' => $studentId,
                    'class_id' => $override->class_id,
                    'teacher_id' => $teacher->id,
                    'performed_by' => $actor->id,
                    'action_type' => 'grant_additional_attempts',
                    'previous_deadline' => $prevDeadline,
                    'new_deadline' => $override->extended_deadline,
                    'previous_attempt_limit' => $prevLimit,
                    'new_attempt_limit' => $afterState['effective_attempt_limit'],
                    'previous_settings' => $prevSettings,
                    'new_settings' => $this->presentOverride($override),
                    'reason' => $reason,
                    'teacher_notes' => $teacherNotes,
                ]);

                $results[] = $afterState;
            }

            return $results;
        });
    }

    /**
     * C.5: Revoke previously granted reopen/extension/attempt overrides for selected student(s).
     * Never deletes submission or attempt history.
     *
     * @param array<int> $studentIds
     * @return array<int, array<string, mixed>>
     */
    public function revokeReopenPermission(
        Model $assessment,
        string $type,
        array $studentIds,
        Teacher $teacher,
        User $actor,
        ?string $reason = null
    ): array {
        $normType = $this->normalizeType($type);
        $this->assertStudentsEligible($assessment, $studentIds);

        return DB::transaction(function () use ($assessment, $normType, $studentIds, $teacher, $actor, $reason) {
            $revokedIds = [];
            $states = [];

            foreach ($studentIds as $studentId) {
                $student = Student::findOrFail($studentId);
                $beforeState = $this->evaluateAccess($assessment, $student);

                $override = AssessmentStudentOverride::forAssessment($normType, $assessment->id)
                    ->where('student_id', $studentId)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                $legacyQuizOv = $normType === 'quiz'
                    ? QuizStudentOverride::where('quiz_id', $assessment->id)
                        ->where('student_id', $studentId)
                        ->where('is_active', true)
                        ->lockForUpdate()
                        ->first()
                    : null;

                if (! $override && ! $legacyQuizOv) {
                    continue;
                }

                $prevDeadline = $override?->extended_deadline?->copy()
                    ?? $legacyQuizOv?->extended_end_at?->copy()
                    ?? $this->originalDeadline($assessment);
                $prevLimit = $beforeState['effective_attempt_limit'];
                $prevSettings = $override ? $this->presentOverride($override) : [
                    'extra_attempts' => (int) ($legacyQuizOv?->extra_attempts ?? 0),
                    'extended_end_at' => $legacyQuizOv?->extended_end_at?->toIso8601String(),
                    'allow_late_submission' => (bool) ($legacyQuizOv?->allow_late_submission ?? false),
                    'is_active' => true,
                ];

                if ($override) {
                    $override->update([
                        'is_active' => false,
                        'revoked_at' => now(),
                        'revoked_by' => $actor->id,
                        'revoke_reason' => $reason,
                    ]);
                }

                if ($legacyQuizOv) {
                    $legacyQuizOv->update([
                        'is_active' => false,
                        'reason' => $reason ?: $legacyQuizOv->reason,
                    ]);
                    QuizOverrideLog::create([
                        'quiz_id' => $assessment->id,
                        'student_id' => $studentId,
                        'teacher_id' => $teacher->id,
                        'acted_by' => $actor->id,
                        'action' => 'revoke_override',
                        'reason' => $reason,
                        'previous_setting' => $prevSettings,
                        'new_setting' => ['is_active' => false],
                    ]);
                }

                $afterState = $this->evaluateAccess($assessment, $student);

                AssessmentAuditLog::create([
                    'assessment_type' => $normType,
                    'assessment_id' => $assessment->id,
                    'student_id' => $studentId,
                    'class_id' => $this->resolveClassId($assessment, $student),
                    'teacher_id' => $teacher->id,
                    'performed_by' => $actor->id,
                    'action_type' => 'revoke_reopen',
                    'previous_deadline' => $prevDeadline,
                    'new_deadline' => $this->originalDeadline($assessment),
                    'previous_attempt_limit' => $prevLimit,
                    'new_attempt_limit' => $afterState['effective_attempt_limit'],
                    'previous_settings' => $prevSettings,
                    'new_settings' => ['is_active' => false],
                    'reason' => $reason,
                ]);

                $revokedIds[] = $studentId;
                $states[] = $afterState;
            }

            return ['revoked_student_ids' => $revokedIds, 'students' => $states];
        });
    }

    /**
     * Ensure quiz_student_overrides and quiz_override_logs mirror universal changes.
     *
     * @param array<string, mixed> $prevSettings
     */
    private function syncQuizOverrideRow(
        Model $quiz,
        Student $student,
        Teacher $teacher,
        User $actor,
        AssessmentStudentOverride $univ,
        string $legacyAction,
        array $prevSettings
    ): void {
        $legacy = QuizStudentOverride::where('quiz_id', $quiz->id)
            ->where('student_id', $student->id)
            ->lockForUpdate()
            ->first();

        if (! $legacy) {
            $legacy = new QuizStudentOverride([
                'quiz_id' => $quiz->id,
                'student_id' => $student->id,
            ]);
        }

        $legacy->teacher_id = $teacher->id;
        $legacy->granted_by = $actor->id;
        $legacy->extra_attempts = (int) $univ->additional_attempts;
        $legacy->extended_end_at = $univ->extended_deadline;
        $legacy->allow_late_submission = (bool) $univ->allow_late_submission;
        $legacy->attempts_reset_at = $univ->attempts_reset_at;
        $legacy->reason = $univ->reason;
        $legacy->is_active = (bool) $univ->is_active;
        $legacy->updated_at = now();
        $legacy->save();

        QuizOverrideLog::create([
            'quiz_id' => $quiz->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'acted_by' => $actor->id,
            'action' => $legacyAction,
            'reason' => $univ->reason,
            'previous_setting' => $prevSettings,
            'new_setting' => [
                'extra_attempts' => (int) $legacy->extra_attempts,
                'extended_end_at' => $legacy->extended_end_at?->toIso8601String(),
                'allow_late_submission' => (bool) $legacy->allow_late_submission,
                'attempts_reset_at' => $legacy->attempts_reset_at?->toIso8601String(),
                'is_active' => (bool) $legacy->is_active,
            ],
        ]);
    }

    /**
     * @param array<int> $studentIds
     */
    private function assertStudentsEligible(Model $assessment, array $studentIds): void
    {
        if (empty($studentIds)) {
            throw ValidationException::withMessages([
                'student_ids' => ['Select at least one student.'],
            ]);
        }

        $eligible = $this->eligibleStudentIds($assessment);
        $invalid = array_diff(array_map('intval', $studentIds), $eligible);

        if (! empty($invalid)) {
            throw ValidationException::withMessages([
                'student_ids' => ['One or more selected students are not enrolled in this assessment\'s class or course offering.'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function presentOverride(AssessmentStudentOverride $override): array
    {
        return [
            'id' => $override->id,
            'assessment_type' => $override->assessment_type,
            'assessment_id' => $override->assessment_id,
            'student_id' => $override->student_id,
            'class_id' => $override->class_id,
            'allow_submission' => (bool) $override->allow_submission,
            'allow_resubmission' => (bool) $override->allow_resubmission,
            'allow_late_submission' => (bool) $override->allow_late_submission,
            'additional_attempts' => (int) $override->additional_attempts,
            'extended_deadline' => $override->extended_deadline?->toIso8601String(),
            'attempts_reset_at' => $override->attempts_reset_at?->toIso8601String(),
            'reason' => $override->reason,
            'teacher_notes' => $override->teacher_notes,
            'is_active' => (bool) $override->is_active,
            'updated_at' => $override->updated_at?->toIso8601String(),
        ];
    }
}
