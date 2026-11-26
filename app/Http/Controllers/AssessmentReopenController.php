<?php

namespace App\Http\Controllers;

use App\Models\AssessmentAuditLog;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\AssessmentAccessService;
use App\Services\AssessmentReopenService;
use App\Services\QuizAvailabilityService;
use App\Services\QuizGradingService;
use App\Services\SubmissionFileService;
use App\Services\TeacherAuthorizationService;
use App\Support\PersonNameHelper;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssessmentReopenController extends Controller
{
    public function __construct(
        protected AssessmentReopenService $reopen,
        protected AssessmentAccessService $access,
        protected TeacherAuthorizationService $teacherAuth,
        protected SubmissionFileService $submissionFiles,
        protected QuizGradingService $quizGrading,
        protected QuizAvailabilityService $quizAvailability,
    ) {}

    protected function teacher(Request $request): Teacher
    {
        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated.');

        $teacher = $this->teacherAuth->resolveTeacher($user);
        if (! $teacher && $user->role === 'admin') {
            $teacher = Teacher::first();
        }
        if (! $teacher) {
            abort(response()->json([
                'success' => false,
                'message' => 'Teacher profile not found.',
            ], 404));
        }

        return $teacher;
    }

    protected function authorizeTeacherForAssessment(Request $request, Model $assessment): Teacher
    {
        $teacher = $this->teacher($request);
        if ($request->user()?->role !== 'admin' && ! $this->access->teacherCanManage($teacher, $assessment)) {
            abort(response()->json([
                'success' => false,
                'message' => 'Unauthorized to manage this assessment.',
            ], 403));
        }

        return $teacher;
    }

    protected function currentStudent(Request $request): Student
    {
        $student = $request->user()?->student;
        if (! $student) {
            abort(response()->json([
                'success' => false,
                'message' => 'Student profile not found.',
            ], 404));
        }

        return $student;
    }

    protected function authorizeStudentForAssessment(Student $student, string $type, Model $assessment): void
    {
        if (! $this->access->studentCanAccess($student, $assessment)) {
            abort(response()->json([
                'success' => false,
                'message' => 'Assessment not found or you are not enrolled in this course.',
            ], 404));
        }

        $status = strtolower((string) $assessment->status);
        $hasOverride = $this->reopen->getActiveOverride($type, $assessment->id, $student->id) !== null;
        if ($type === 'quiz') {
            $visible = $this->access->isVisibleStatus('quiz', $status) || ($status === 'closed' && $hasOverride);
        } else {
            $visible = $this->access->isVisibleStatus($type, $status) || ($status === 'closed' && $hasOverride);
        }

        if (! $visible) {
            abort(response()->json([
                'success' => false,
                'message' => 'Assessment not found or not available to students.',
            ], 404));
        }
    }

    /**
     * C.1 (Teacher/Admin): GET /api/teacher/assessments/{assessmentType}/{assessmentId}/reopen-status
     */
    public function getReopenStatus(Request $request, string $assessmentType, int $assessmentId): JsonResponse
    {
        $normType = $this->reopen->normalizeType($assessmentType);
        $assessment = $this->reopen->resolveAssessment($normType, $assessmentId);
        $this->authorizeTeacherForAssessment($request, $assessment);

        $eligibleIds = $this->reopen->eligibleStudentIds($assessment);
        $filterStudentId = $request->filled('student_id') ? (int) $request->query('student_id') : null;
        if ($filterStudentId !== null) {
            if (! in_array($filterStudentId, $eligibleIds, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Student is not enrolled in this assessment.',
                ], 404);
            }
            $eligibleIds = [$filterStudentId];
        }

        $students = Student::with('user')
            ->whereIn('id', $eligibleIds ?: [0])
            ->orderBy('english_name')
            ->orderBy('first_name')
            ->get();

        $rows = $students->map(function (Student $st) use ($assessment, $normType) {
            $state = $this->reopen->evaluateAccess($assessment, $st);

            $latestScore = null;
            if ($normType === 'quiz') {
                /** @var Quiz $assessment */
                $latestScore = $this->quizGrading->calculateStudentQuizScore($assessment, $state['quiz_access']['attempts']);
            } else {
                $latestSub = $state['latest_submission'] ?? null;
                $latestScore = $latestSub && $latestSub->score !== null ? (float) $latestSub->score : null;
            }

            $name = PersonNameHelper::displayName(
                $st->english_name ?: trim(($st->first_name ?? '').' '.($st->last_name ?? '')),
                $st->khmer_name,
                $st->user?->name ?? 'Student'
            );

            unset($state['quiz_access'], $state['latest_submission'], $state['submissions'], $state['override_model']);

            return array_merge($state, [
                'student_code' => $st->student_code ?? ('STU'.$st->id),
                'student_name' => $name,
                'email' => $st->user?->email,
                'latest_score' => $latestScore,
            ]);
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Reopen status retrieved successfully.',
            'data' => [
                'assessment' => $this->assessmentSummary($assessment, $normType),
                'students' => $rows,
            ],
        ]);
    }

    /**
     * C.1 (Student): GET /api/student/assessments/{assessmentType}/{assessmentId}/reopen-status
     */
    public function studentReopenStatus(Request $request, string $assessmentType, int $assessmentId): JsonResponse
    {
        $student = $this->currentStudent($request);
        $normType = $this->reopen->normalizeType($assessmentType);
        $assessment = $this->reopen->resolveAssessment($normType, $assessmentId);
        $this->authorizeStudentForAssessment($student, $normType, $assessment);

        $state = $this->reopen->evaluateAccess($assessment, $student);
        unset($state['quiz_access'], $state['latest_submission'], $state['submissions'], $state['override_model']);

        return response()->json([
            'success' => true,
            'message' => 'Student reopen status retrieved successfully.',
            'data' => array_merge($state, [
                'assessment' => $this->assessmentSummary($assessment, $normType),
            ]),
        ]);
    }

    /**
     * C.2: POST /api/teacher/assessments/reopen
     */
    public function reopen(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'assessment_type' => ['required', Rule::in(AssessmentReopenService::VALID_TYPES)],
            'assessment_id' => 'required|integer|min:1',
            'student_ids' => 'nullable|array',
            'student_ids.*' => 'integer|exists:students,id',
            'student_id' => 'nullable|integer|exists:students,id',
            'scope' => ['nullable', Rule::in(['students', 'assessment'])],
            'allow_submission' => 'nullable|boolean',
            'allow_resubmission' => 'nullable|boolean',
            'allow_late_submission' => 'nullable|boolean',
            'additional_attempts' => 'nullable|integer|min:0|max:50',
            'target_attempt_limit' => 'nullable|integer|min:1|max:100',
            'reset_attempts' => 'nullable|boolean',
            'new_deadline' => 'nullable|date',
            'reason' => 'nullable|string|max:1000',
            'teacher_notes' => 'nullable|string|max:2000',
        ]);

        $normType = $this->reopen->normalizeType($validated['assessment_type']);
        $assessment = $this->reopen->resolveAssessment($normType, (int) $validated['assessment_id']);
        $teacher = $this->authorizeTeacherForAssessment($request, $assessment);

        $studentIds = $this->extractStudentIds($validated);
        if (empty($studentIds) && ($validated['scope'] ?? 'students') !== 'assessment') {
            return response()->json([
                'success' => false,
                'message' => 'Provide at least one student_id in student_ids, or set scope=assessment.',
                'errors' => ['student_ids' => ['Select at least one student to reopen.']],
            ], 422);
        }

        if (! empty($validated['new_deadline'])) {
            $start = $assessment instanceof Quiz ? $assessment->start_at : $assessment->starts_at;
            $newDt = Carbon::parse((string) $validated['new_deadline']);
            if ($start && $newDt->lt($start)) {
                return response()->json([
                    'success' => false,
                    'message' => 'New deadline must be after the assessment start time.',
                    'errors' => ['new_deadline' => ['New deadline must be after the assessment start time.']],
                ], 422);
            }
        }

        $updated = $this->reopen->reopenAssessment(
            $assessment,
            $normType,
            $studentIds,
            $teacher,
            $request->user(),
            $validated
        );

        $sanitized = collect($updated)->map(function (array $row) {
            unset($row['quiz_access'], $row['latest_submission'], $row['submissions'], $row['override_model']);

            return $row;
        })->values();

        return response()->json([
            'success' => true,
            'message' => empty($studentIds)
                ? 'Assessment reopened successfully.'
                : 'Assessment reopened for '.count($studentIds).' student(s).',
            'data' => [
                'assessment' => $this->assessmentSummary($assessment->fresh(), $normType),
                'students' => $sanitized,
            ],
        ]);
    }

    /**
     * C.3: POST /api/teacher/assessments/extend-deadline
     */
    public function extendDeadline(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'assessment_type' => ['required', Rule::in(AssessmentReopenService::VALID_TYPES)],
            'assessment_id' => 'required|integer|min:1',
            'student_ids' => 'nullable|array',
            'student_ids.*' => 'integer|exists:students,id',
            'student_id' => 'nullable|integer|exists:students,id',
            'new_deadline' => 'required|date',
            'reason' => 'nullable|string|max:1000',
            'teacher_notes' => 'nullable|string|max:2000',
        ]);

        $normType = $this->reopen->normalizeType($validated['assessment_type']);
        $assessment = $this->reopen->resolveAssessment($normType, (int) $validated['assessment_id']);
        $teacher = $this->authorizeTeacherForAssessment($request, $assessment);

        $studentIds = $this->extractStudentIds($validated);
        if (empty($studentIds)) {
            return response()->json([
                'success' => false,
                'message' => 'Select at least one student.',
                'errors' => ['student_ids' => ['Select at least one student.']],
            ], 422);
        }

        $newDeadline = Carbon::parse((string) $validated['new_deadline']);
        $start = $assessment instanceof Quiz ? $assessment->start_at : $assessment->starts_at;
        if ($start && $newDeadline->lt($start)) {
            return response()->json([
                'success' => false,
                'message' => 'Extended deadline must be after the assessment start time.',
                'errors' => ['new_deadline' => ['Extended deadline must be after the assessment start time.']],
            ], 422);
        }

        $updated = $this->reopen->extendDeadline(
            $assessment,
            $normType,
            $studentIds,
            $newDeadline,
            $teacher,
            $request->user(),
            $validated['reason'] ?? null,
            $validated['teacher_notes'] ?? null
        );

        $sanitized = collect($updated)->map(function (array $row) {
            unset($row['quiz_access'], $row['latest_submission'], $row['submissions'], $row['override_model']);

            return $row;
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Deadline extended for '.count($studentIds).' student(s).',
            'data' => [
                'assessment' => $this->assessmentSummary($assessment, $normType),
                'students' => $sanitized,
            ],
        ]);
    }

    /**
     * C.4: POST /api/teacher/assessments/grant-attempts
     */
    public function grantAttempts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'assessment_type' => ['required', Rule::in(AssessmentReopenService::VALID_TYPES)],
            'assessment_id' => 'required|integer|min:1',
            'student_id' => 'nullable|integer|exists:students,id',
            'student_ids' => 'nullable|array',
            'student_ids.*' => 'integer|exists:students,id',
            'additional_attempts' => 'required|integer|min:1|max:50',
            'reason' => 'nullable|string|max:1000',
            'teacher_notes' => 'nullable|string|max:2000',
        ]);

        $normType = $this->reopen->normalizeType($validated['assessment_type']);
        $assessment = $this->reopen->resolveAssessment($normType, (int) $validated['assessment_id']);
        $teacher = $this->authorizeTeacherForAssessment($request, $assessment);

        $studentIds = $this->extractStudentIds($validated);
        if (empty($studentIds)) {
            return response()->json([
                'success' => false,
                'message' => 'Provide student_id or student_ids.',
                'errors' => ['student_id' => ['Select at least one student.']],
            ], 422);
        }

        $updated = $this->reopen->grantAdditionalAttempts(
            $assessment,
            $normType,
            $studentIds,
            (int) $validated['additional_attempts'],
            $teacher,
            $request->user(),
            $validated['reason'] ?? null,
            $validated['teacher_notes'] ?? null
        );

        $sanitized = collect($updated)->map(function (array $row) {
            unset($row['quiz_access'], $row['latest_submission'], $row['submissions'], $row['override_model']);

            return $row;
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Additional attempts granted successfully.',
            'data' => [
                'assessment' => $this->assessmentSummary($assessment, $normType),
                'students' => $sanitized,
            ],
        ]);
    }

    /**
     * C.5: POST /api/teacher/assessments/revoke-reopen
     */
    public function revokeReopen(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'assessment_type' => ['required', Rule::in(AssessmentReopenService::VALID_TYPES)],
            'assessment_id' => 'required|integer|min:1',
            'student_ids' => 'nullable|array',
            'student_ids.*' => 'integer|exists:students,id',
            'student_id' => 'nullable|integer|exists:students,id',
            'reason' => 'nullable|string|max:1000',
        ]);

        $normType = $this->reopen->normalizeType($validated['assessment_type']);
        $assessment = $this->reopen->resolveAssessment($normType, (int) $validated['assessment_id']);
        $teacher = $this->authorizeTeacherForAssessment($request, $assessment);

        $studentIds = $this->extractStudentIds($validated);
        if (empty($studentIds)) {
            return response()->json([
                'success' => false,
                'message' => 'Select at least one student to revoke.',
                'errors' => ['student_ids' => ['Select at least one student.']],
            ], 422);
        }

        $res = $this->reopen->revokeReopenPermission(
            $assessment,
            $normType,
            $studentIds,
            $teacher,
            $request->user(),
            $validated['reason'] ?? null
        );

        if (empty($res['revoked_student_ids'])) {
            return response()->json([
                'success' => false,
                'message' => 'No active reopen permissions found for the selected student(s).',
            ], 404);
        }

        $sanitized = collect($res['students'])->map(function (array $row) {
            unset($row['quiz_access'], $row['latest_submission'], $row['submissions'], $row['override_model']);

            return $row;
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Reopen permission revoked while preserving all historical records.',
            'data' => [
                'revoked_student_ids' => $res['revoked_student_ids'],
                'students' => $sanitized,
            ],
        ]);
    }

    /**
     * C.5 convenience DELETE route: DELETE /api/teacher/assessments/{assessmentType}/{assessmentId}/overrides/{studentId}
     */
    public function revokeStudentOverride(
        Request $request,
        string $assessmentType,
        int $assessmentId,
        int $studentId
    ): JsonResponse {
        $request->merge([
            'assessment_type' => $assessmentType,
            'assessment_id' => $assessmentId,
            'student_ids' => [$studentId],
        ]);

        return $this->revokeReopen($request);
    }

    /**
     * C.6 (Teacher/Admin): GET /api/teacher/assessments/{assessmentType}/{assessmentId}/submission-history
     */
    public function submissionHistory(Request $request, string $assessmentType, int $assessmentId): JsonResponse
    {
        $normType = $this->reopen->normalizeType($assessmentType);
        if ($normType === 'quiz') {
            return $this->quizAttemptHistory($request, $assessmentId);
        }

        /** @var Assignment $assignment */
        $assignment = $this->reopen->resolveAssessment($normType, $assessmentId);
        $this->authorizeTeacherForAssessment($request, $assignment);

        $query = AssignmentSubmission::with(['student.user', 'assignment.course', 'assignment.courseOffering.classRoom'])
            ->where('assignment_id', $assignment->id)
            ->orderByDesc('version_number')
            ->orderByDesc('id');

        if ($request->filled('student_id')) {
            $query->where('student_id', (int) $request->query('student_id'));
        }

        $items = $query->get()->map(fn (AssignmentSubmission $s) => $this->presentSubmissionHistoryItem($s, $assignment, 'teacher'))->values();

        return response()->json([
            'success' => true,
            'message' => 'Submission history retrieved successfully.',
            'data' => [
                'assessment' => $this->assessmentSummary($assignment, $normType),
                'submissions' => $items,
            ],
        ]);
    }

    /**
     * C.6 (Student): GET /api/student/assessments/{assessmentType}/{assessmentId}/submission-history
     */
    public function studentSubmissionHistory(Request $request, string $assessmentType, int $assessmentId): JsonResponse
    {
        $student = $this->currentStudent($request);
        $normType = $this->reopen->normalizeType($assessmentType);
        if ($normType === 'quiz') {
            return $this->studentQuizAttemptHistory($request, $assessmentId);
        }

        /** @var Assignment $assignment */
        $assignment = $this->reopen->resolveAssessment($normType, $assessmentId);
        $this->authorizeStudentForAssessment($student, $normType, $assignment);

        $items = AssignmentSubmission::with(['student.user', 'assignment.course', 'assignment.courseOffering.classRoom'])
            ->where('assignment_id', $assignment->id)
            ->where('student_id', $student->id)
            ->orderByDesc('version_number')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AssignmentSubmission $s) => $this->presentSubmissionHistoryItem($s, $assignment, 'student'))
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Student submission history retrieved successfully.',
            'data' => [
                'assessment' => $this->assessmentSummary($assignment, $normType),
                'submissions' => $items,
            ],
        ]);
    }

    /**
     * C.7 (Teacher/Admin): GET /api/teacher/assessments/quiz/{assessmentId}/attempt-history
     */
    public function quizAttemptHistory(Request $request, int $assessmentId): JsonResponse
    {
        /** @var Quiz $quiz */
        $quiz = $this->reopen->resolveAssessment('quiz', $assessmentId);
        $this->authorizeTeacherForAssessment($request, $quiz);

        $query = QuizAttempt::with(['student.user'])
            ->where('quiz_id', $quiz->id)
            ->orderByDesc('attempt_number')
            ->orderByDesc('id');

        if ($request->filled('student_id')) {
            $query->where('student_id', (int) $request->query('student_id'));
        }

        $attempts = $query->get();
        $defaultMax = (float) (QuizQuestion::where('quiz_id', $quiz->id)->sum('marks') ?: $quiz->total_score);

        $studentSummaries = $attempts->groupBy('student_id')->map(function ($group, $sid) use ($quiz) {
            $first = $group->first();
            $st = $first?->student;
            $name = PersonNameHelper::displayName(
                $st?->english_name ?: trim(($st?->first_name ?? '').' '.($st?->last_name ?? '')),
                $st?->khmer_name,
                $st?->user?->name ?? 'Student'
            );

            return [
                'student_id' => (int) $sid,
                'student_code' => $st?->student_code,
                'student_name' => $name,
                'scoring_method' => $quiz->scoring_method ?: 'highest',
                'effective_score' => $this->quizGrading->calculateStudentQuizScore($quiz, $group),
                'attempts_used' => $group->filter(fn (QuizAttempt $a) => ! $a->is_reset && $a->status !== 'in_progress')->count(),
                'total_attempts_recorded' => $group->count(),
            ];
        })->values();

        $formatted = $attempts->map(fn (QuizAttempt $a) => $this->presentQuizAttemptHistoryItem($a, $quiz, $defaultMax, true, true))->values();

        return response()->json([
            'success' => true,
            'message' => 'Quiz attempt history retrieved successfully.',
            'data' => [
                'quiz_id' => $quiz->id,
                'scoring_method' => $quiz->scoring_method ?: 'highest',
                'assessment' => $this->assessmentSummary($quiz, 'quiz'),
                'student_summaries' => $studentSummaries,
                'attempts' => $formatted,
            ],
        ]);
    }

    /**
     * C.7 (Student): GET /api/student/assessments/quiz/{assessmentId}/attempt-history
     */
    public function studentQuizAttemptHistory(Request $request, int $assessmentId): JsonResponse
    {
        $student = $this->currentStudent($request);
        /** @var Quiz $quiz */
        $quiz = $this->reopen->resolveAssessment('quiz', $assessmentId);
        $this->authorizeStudentForAssessment($student, 'quiz', $quiz);

        $access = $this->quizAvailability->evaluateStudentAccess($quiz, $student);
        $attempts = $access['attempts'];
        $defaultMax = (float) (QuizQuestion::where('quiz_id', $quiz->id)->sum('marks') ?: $quiz->total_score);
        $effectiveScore = $this->quizGrading->calculateStudentQuizScore($quiz, $attempts);
        $hasPending = $access['active_attempts']->contains(fn (QuizAttempt $a) => $a->isPendingManualGrading());

        $showCorrect = $access['window_state'] === 'closed' || $access['attempts_remaining'] === 0;
        $formatted = $attempts->map(fn (QuizAttempt $a) => $this->presentQuizAttemptHistoryItem($a, $quiz, $defaultMax, false, $showCorrect))->values();

        return response()->json([
            'success' => true,
            'message' => 'Student quiz attempt history retrieved successfully.',
            'data' => [
                'quiz_id' => $quiz->id,
                'scoring_method' => $quiz->scoring_method ?: 'highest',
                'effective_score' => ($hasPending && ($quiz->scoring_method === 'latest' || $effectiveScore === null)) ? null : $effectiveScore,
                'has_pending_grading' => $hasPending,
                'assessment' => $this->assessmentSummary($quiz, 'quiz'),
                'attempts' => $formatted,
            ],
        ]);
    }

    /**
     * C.8: GET /api/teacher/assessments/audit-logs
     */
    public function auditLogs(Request $request): JsonResponse
    {
        $user = $request->user();
        $teacher = $this->teacher($request);

        $validated = $request->validate([
            'student_id' => 'nullable|integer|exists:students,id',
            'performed_by' => 'nullable|integer|exists:users,id',
            'teacher_id' => 'nullable|integer|exists:teachers,id',
            'assessment_type' => ['nullable', Rule::in(AssessmentReopenService::VALID_TYPES)],
            'assessment_id' => 'nullable|integer|min:1',
            'action_type' => 'nullable|string|max:50',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        $query = AssessmentAuditLog::with(['student.user', 'performer', 'teacher.user'])
            ->orderByDesc('id');

        if ($user?->role !== 'admin') {
            $offeringIds = $this->teacherAuth->getAuthorizedOfferingIds($teacher);
            $courseIds = $this->teacherAuth->getAuthorizedCourseIds($teacher);

            $allowedQuizIds = Quiz::query()
                ->where(function ($q) use ($offeringIds, $courseIds) {
                    $q->whereIn('course_offering_id', $offeringIds ?: [0])
                        ->orWhere(function ($sq) use ($courseIds) {
                            $sq->whereNull('course_offering_id')->whereIn('course_id', $courseIds ?: [0]);
                        });
                })
                ->pluck('id')
                ->all();

            $allowedAssignmentIds = Assignment::query()
                ->where(function ($q) use ($offeringIds, $courseIds) {
                    $q->whereIn('course_offering_id', $offeringIds ?: [0])
                        ->orWhere(function ($sq) use ($courseIds) {
                            $sq->whereNull('course_offering_id')->whereIn('course_id', $courseIds ?: [0]);
                        });
                })
                ->pluck('id')
                ->all();

            $query->where(function ($q) use ($allowedQuizIds, $allowedAssignmentIds) {
                $q->where(fn ($sq) => $sq->where('assessment_type', 'quiz')->whereIn('assessment_id', $allowedQuizIds ?: [0]))
                    ->orWhere(fn ($sq) => $sq->whereIn('assessment_type', ['assignment', 'homework'])->whereIn('assessment_id', $allowedAssignmentIds ?: [0]));
            });
        }

        if (! empty($validated['student_id'])) {
            $query->where('student_id', (int) $validated['student_id']);
        }
        if (! empty($validated['performed_by'])) {
            $query->where('performed_by', (int) $validated['performed_by']);
        }
        if (! empty($validated['teacher_id'])) {
            $query->where('teacher_id', (int) $validated['teacher_id']);
        }
        if (! empty($validated['assessment_type'])) {
            $query->where('assessment_type', strtolower((string) $validated['assessment_type']));
        }
        if (! empty($validated['assessment_id'])) {
            $query->where('assessment_id', (int) $validated['assessment_id']);
        }
        if (! empty($validated['action_type'])) {
            $query->where('action_type', $validated['action_type']);
        }
        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', Carbon::parse((string) $validated['date_from'])->toDateString());
        }
        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', Carbon::parse((string) $validated['date_to'])->toDateString());
        }

        $logs = $query->limit(200)->get();

        $quizIds = $logs->where('assessment_type', 'quiz')->pluck('assessment_id')->unique()->all();
        $asnIds = $logs->whereIn('assessment_type', ['assignment', 'homework'])->pluck('assessment_id')->unique()->all();
        $quizTitles = ! empty($quizIds) ? Quiz::withTrashed()->whereIn('id', $quizIds)->pluck('title', 'id') : collect();
        $asnTitles = ! empty($asnIds) ? Assignment::whereIn('id', $asnIds)->pluck('title', 'id') : collect();

        $items = $logs->map(function (AssessmentAuditLog $log) use ($quizTitles, $asnTitles) {
            $st = $log->student;
            $studentName = $st ? PersonNameHelper::displayName(
                $st->english_name ?: trim(($st->first_name ?? '').' '.($st->last_name ?? '')),
                $st->khmer_name,
                $st->user?->name ?? 'Student'
            ) : null;

            $title = $log->assessment_type === 'quiz'
                ? $quizTitles->get($log->assessment_id)
                : $asnTitles->get($log->assessment_id);

            return [
                'audit_id' => $log->id,
                'id' => $log->id,
                'performed_by' => $log->performed_by,
                'performed_by_name' => $log->performer?->name ?? 'System',
                'teacher_id' => $log->teacher_id,
                'student_id' => $log->student_id,
                'student_code' => $st?->student_code,
                'student_name' => $studentName,
                'class_id' => $log->class_id,
                'assessment_type' => $log->assessment_type,
                'assessment_id' => $log->assessment_id,
                'assessment_title' => $title,
                'action_type' => $log->action_type,
                'previous_deadline' => $log->previous_deadline?->toIso8601String(),
                'new_deadline' => $log->new_deadline?->toIso8601String(),
                'previous_attempt_limit' => $log->previous_attempt_limit,
                'new_attempt_limit' => $log->new_attempt_limit,
                'previous_settings' => $log->previous_settings,
                'new_settings' => $log->new_settings,
                'reason' => $log->reason,
                'teacher_notes' => $log->teacher_notes,
                'created_at' => $log->created_at?->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Assessment audit logs retrieved successfully.',
            'data' => $items,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function assessmentSummary(Model $assessment, string $normType): array
    {
        $origDeadline = $this->reopen->originalDeadline($assessment);
        $origLimit = $this->reopen->originalAttemptLimit($assessment);
        $course = $assessment->course;
        $classRoom = $assessment->courseOffering?->classRoom;

        return [
            'assessment_type' => $normType,
            'assessment_id' => $assessment->id,
            'class_id' => $this->reopen->resolveClassId($assessment),
            'class_name' => $classRoom?->name ?? $assessment->courseOffering?->section_name,
            'course_id' => $assessment->course_id,
            'course_code' => $course?->code ?? $course?->course_code,
            'course_name' => $course?->name ?? $course?->course_name,
            'title' => $assessment->title,
            'status' => $assessment->status,
            'original_deadline' => $origDeadline?->toIso8601String(),
            'reopened_at' => $assessment->reopened_at?->toIso8601String(),
            'reopened_until' => $assessment->reopened_until?->toIso8601String(),
            'original_attempt_limit' => $origLimit,
            'allow_late_submission' => (bool) ($assessment->allow_late_submission ?? false),
            'allow_resubmission' => $assessment instanceof Quiz
                ? ($origLimit === null || $origLimit > 1)
                : ((bool) $assessment->allow_resubmission || (bool) $assessment->allow_retakes),
            'scoring_method' => $assessment instanceof Quiz ? ($assessment->scoring_method ?: 'highest') : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSubmissionHistoryItem(
        AssignmentSubmission $s,
        Assignment $assignment,
        string $audience
    ): array {
        $fileMeta = $this->submissionFiles->describeForApi($s, $audience);
        $rawStatus = strtolower((string) ($s->status ?? 'submitted'));
        $isPublished = $s->score !== null && $rawStatus === 'graded';
        $hideScore = $audience === 'student' && ! $isPublished;

        $student = $s->student;
        $studentName = PersonNameHelper::displayName(
            $student?->english_name ?: trim(($student?->first_name ?? '').' '.($student?->last_name ?? '')),
            $student?->khmer_name,
            $student?->user?->name ?? 'Student'
        );

        $uploadedFiles = [];
        if (! empty($fileMeta['has_file'])) {
            $uploadedFiles[] = [
                'file_name' => $fileMeta['file_name'],
                'file_extension' => $fileMeta['file_extension'],
                'file_type' => $fileMeta['file_type'],
                'mime_type' => $fileMeta['mime_type'],
                'file_size' => $fileMeta['file_size'],
                'file_size_label' => $fileMeta['file_size_label'],
                'preview_type' => $fileMeta['preview_type'],
                'file_exists' => $fileMeta['file_exists'],
                'view_url' => $fileMeta['view_url'],
                'download_url' => $fileMeta['download_url'],
            ];
        }

        return array_merge([
            'submission_id' => $s->id,
            'id' => $s->id,
            'assessment_type' => $assignment->canonicalAssessmentType(),
            'assessment_id' => $s->assignment_id,
            'assignment_id' => $s->assignment_id,
            'student_id' => $s->student_id,
            'student_code' => $student?->student_code ?? ('STU'.$s->student_id),
            'student_name' => $studentName,
            'submission_status' => $s->status ?? 'submitted',
            'submitted_at' => $s->submitted_at?->toIso8601String(),
            'submitted_at_display' => $s->submitted_at ? $s->submitted_at->format('M d, Y h:i A') : '-',
            'is_late' => (bool) ($s->is_late || $rawStatus === 'late'),
            'is_latest' => (bool) ($s->is_latest ?? true),
            'version_number' => (int) ($s->version_number ?: 1),
            'uploaded_files' => $uploadedFiles,
            'submission_text' => $s->submission_text,
            'is_published' => $isPublished,
            'score' => $hideScore ? null : ($s->score !== null ? (float) $s->score : null),
            'max_score' => (float) ($assignment->total_score ?? 100),
            'feedback' => $hideScore ? '' : ($s->feedback ?? ''),
        ], $fileMeta);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentQuizAttemptHistoryItem(
        QuizAttempt $attempt,
        Quiz $quiz,
        float $defaultMax,
        bool $isTeacher,
        bool $showCorrect
    ): array {
        $pending = $attempt->isPendingManualGrading();
        $finalized = $attempt->isFinalized();
        $totalMarks = (float) ($attempt->total_marks_snapshot ?: $defaultMax);

        $snapshots = $this->quizGrading->resolveAttemptQuestions($attempt);
        $answers = QuizAnswer::where('quiz_attempt_id', $attempt->id)->get()->keyBy('question_id');

        $answerItems = collect($snapshots)->map(function (array $q) use ($answers, $isTeacher, $showCorrect) {
            $qId = (int) ($q['id'] ?? 0);
            $a = $answers->get($qId);
            $qType = strtolower((string) ($q['question_type'] ?? 'multiple_choice'));
            $ansPending = $a !== null && $a->grading_status === 'pending_grading';

            return [
                'question_id' => $qId,
                'number' => (int) ($q['question_order'] ?? 1),
                'question_text' => (string) ($q['question_text'] ?? ''),
                'question_type' => $qType,
                'marks' => (float) ($q['marks'] ?? 1),
                'student_answer' => $a?->answer,
                'grading_status' => $a ? ($a->grading_status ?: ($a->is_correct === null ? 'pending_grading' : 'auto_graded')) : 'unanswered',
                'is_correct' => ($ansPending && ! $isTeacher) ? null : $a?->is_correct,
                'marks_obtained' => ($ansPending && ! $isTeacher) ? null : ($a?->marks_obtained !== null ? (float) $a->marks_obtained : null),
                'teacher_feedback' => $a?->teacher_feedback,
                'correct_answer' => ($isTeacher || $showCorrect) ? ($q['correct_answer'] ?? null) : null,
            ];
        })->values()->all();

        $st = $attempt->student;
        $studentName = PersonNameHelper::displayName(
            $st?->english_name ?: trim(($st?->first_name ?? '').' '.($st?->last_name ?? '')),
            $st?->khmer_name,
            $st?->user?->name ?? 'Student'
        );

        return [
            'attempt_id' => $attempt->id,
            'id' => $attempt->id,
            'quiz_id' => $attempt->quiz_id,
            'student_id' => $attempt->student_id,
            'student_code' => $st?->student_code,
            'student_name' => $studentName,
            'attempt_number' => (int) $attempt->attempt_number,
            'started_at' => $attempt->started_at?->toIso8601String(),
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            'score' => ($finalized || ($isTeacher && ! $pending)) ? $attempt->score : null,
            'auto_graded_score' => ($finalized || $isTeacher) ? $attempt->auto_graded_score : null,
            'manual_graded_score' => ($finalized || $isTeacher) ? $attempt->manual_graded_score : null,
            'total_marks' => $totalMarks,
            'attempt_status' => $attempt->status,
            'grading_status' => $pending ? 'pending_grading' : ($attempt->grading_status ?: ($finalized ? 'auto_graded' : 'not_submitted')),
            'is_late' => (bool) $attempt->is_late,
            'is_reset' => (bool) $attempt->is_reset,
            'answers' => $answerItems,
        ];
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<int>
     */
    private function extractStudentIds(array $validated): array
    {
        $ids = [];
        if (! empty($validated['student_ids']) && is_array($validated['student_ids'])) {
            $ids = array_merge($ids, $validated['student_ids']);
        }
        if (! empty($validated['student_id'])) {
            $ids[] = $validated['student_id'];
        }

        return collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
    }
}
