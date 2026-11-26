<?php

namespace App\Http\Controllers;

use App\Models\AssessmentAuditLog;
use App\Models\AssessmentStudentOverride;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\QuizStudentOverride;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\AssessmentAccessService;
use App\Services\AssessmentReopenService;
use App\Services\QuizGradingService;
use App\Services\TeacherAuthorizationService;
use App\Support\PersonNameHelper;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TeacherQuizReopenWebController extends Controller
{
    public const STATUS_OPTIONS = [
        'Not Submitted',
        'In Progress',
        'Submitted',
        'Late Submitted',
        'Reopened',
        'Extended',
        'Expired',
    ];

    public function __construct(
        protected AssessmentReopenService $reopen,
        protected AssessmentAccessService $access,
        protected TeacherAuthorizationService $teacherAuth,
        protected QuizGradingService $quizGrading,
    ) {}

    /**
     * List quizzes from the `quizzes` table scoped to the teacher's authorized offerings/legacy courses.
     */
    public function listModernQuizzes(?Teacher $teacher): Collection
    {
        if (! $teacher) {
            return collect();
        }

        $offeringIds = $this->teacherAuth->getAuthorizedOfferingIds($teacher);
        $courseIds = $this->teacherAuth->getAuthorizedCourseIds($teacher);

        $quizzes = Quiz::with(['course', 'courseOffering.classRoom', 'semester'])
            ->withCount(['questions', 'attempts'])
            ->where(function ($query) use ($offeringIds, $courseIds, $teacher) {
                $query->whereIn('course_offering_id', $offeringIds ?: [0])
                    ->orWhere(function ($legacy) use ($courseIds, $teacher) {
                        $legacy->whereNull('course_offering_id')
                            ->where('teacher_id', $teacher->id)
                            ->whereIn('course_id', $courseIds ?: [0]);
                    });
            })
            ->latest('id')
            ->get();

        if ($quizzes->isEmpty()) {
            return $quizzes;
        }

        $quizIds = $quizzes->pluck('id')->all();

        $univByQuiz = AssessmentStudentOverride::where('assessment_type', 'quiz')
            ->whereIn('assessment_id', $quizIds)
            ->where('is_active', true)
            ->get(['assessment_id', 'student_id'])
            ->groupBy('assessment_id');

        $legacyByQuiz = QuizStudentOverride::whereIn('quiz_id', $quizIds)
            ->where('is_active', true)
            ->get(['quiz_id', 'student_id'])
            ->groupBy('quiz_id');

        return $quizzes->map(function (Quiz $quiz) use ($univByQuiz, $legacyByQuiz) {
            $uIds = $univByQuiz->get($quiz->id, collect())->pluck('student_id')->all();
            $lIds = $legacyByQuiz->get($quiz->id, collect())->pluck('student_id')->all();
            $activeCount = count(array_unique(array_merge($uIds, $lIds)));
            $quiz->setAttribute('active_overrides_count', $activeCount);

            return $quiz;
        });
    }

    /**
     * GET /teacher/quizzes/{quiz}/manage
     */
    public function manage(Request $request, int $quiz): View
    {
        [, $quizModel] = $this->authorizeTeacherAndQuiz($request, $quiz);

        $tab = in_array($request->query('tab'), ['students', 'attempts', 'audit-logs'], true)
            ? (string) $request->query('tab')
            : 'students';
        $search = trim((string) $request->query('search', ''));
        $statusFilter = trim((string) $request->query('status', ''));
        $studentFilterId = $request->filled('student_id') ? (int) $request->query('student_id') : null;
        $actionFilter = trim((string) $request->query('action_type', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));

        $eligibleIds = $this->reopen->eligibleStudentIds($quizModel);
        $students = Student::with('user')
            ->whereIn('id', $eligibleIds ?: [0])
            ->orderBy('english_name')
            ->orderBy('first_name')
            ->get();

        $allStudentRows = $students->map(function (Student $st) use ($quizModel) {
            $state = $this->reopen->evaluateQuizAccess($quizModel, $st);
            $latestScore = $this->quizGrading->calculateStudentQuizScore($quizModel, $state['quiz_access']['attempts']);

            unset($state['quiz_access'], $state['latest_submission'], $state['submissions'], $state['override_model']);

            return array_merge($state, [
                'student_code' => $st->student_code ?? ('STU'.$st->id),
                'student_name' => $this->resolveStudentDisplayName($st),
                'email' => $st->user?->email ?? '',
                'latest_score' => $latestScore,
            ]);
        })->values();

        $filteredStudentRows = $allStudentRows->filter(function (array $row) use ($search, $statusFilter) {
            if ($statusFilter !== '' && strcasecmp((string) $row['reopen_status'], $statusFilter) !== 0) {
                return false;
            }
            if ($search !== '') {
                $needle = mb_strtolower($search);
                $haystack = mb_strtolower(implode(' ', [
                    (string) ($row['student_name'] ?? ''),
                    (string) ($row['student_code'] ?? ''),
                    (string) ($row['email'] ?? ''),
                ]));
                if (! str_contains($haystack, $needle)) {
                    return false;
                }
            }

            return true;
        })->values();

        // Attempt history (supports optional student filter; preserves every historical attempt/answer)
        $attemptsQuery = QuizAttempt::with(['student.user', 'answers'])
            ->where('quiz_id', $quizModel->id)
            ->orderByDesc('attempt_number')
            ->orderByDesc('id');
        if ($studentFilterId !== null && $studentFilterId > 0) {
            $attemptsQuery->where('student_id', $studentFilterId);
        }
        $attempts = $attemptsQuery->get();

        $totalQuestions = QuizQuestion::where('quiz_id', $quizModel->id)->count();
        $defaultMax = (float) (QuizQuestion::where('quiz_id', $quizModel->id)->sum('marks') ?: $quizModel->total_score);

        $studentSummaries = $attempts->groupBy('student_id')->map(function ($group, $sid) use ($quizModel) {
            $st = $group->first()?->student;

            return [
                'student_id' => (int) $sid,
                'student_code' => $st?->student_code ?? ('STU'.$sid),
                'student_name' => $this->resolveStudentDisplayName($st),
                'scoring_method' => $quizModel->scoring_method ?: 'highest',
                'effective_score' => $this->quizGrading->calculateStudentQuizScore($quizModel, $group),
                'attempts_used' => $group->filter(fn (QuizAttempt $a) => ! $a->is_reset && $a->status !== 'in_progress')->count(),
                'total_attempts_recorded' => $group->count(),
            ];
        })->values();

        $attemptRows = $attempts->map(function (QuizAttempt $attempt) use ($defaultMax) {
            $pending = $attempt->isPendingManualGrading();
            $finalized = $attempt->isFinalized();
            $totalMarks = (float) ($attempt->total_marks_snapshot ?: $defaultMax);
            $snapshots = $this->quizGrading->resolveAttemptQuestions($attempt);
            $answers = $attempt->answers->keyBy('question_id');

            $answerItems = collect($snapshots)->map(function (array $q) use ($answers) {
                $qId = (int) ($q['id'] ?? 0);
                $ans = $answers->get($qId);

                return [
                    'question_id' => $qId,
                    'number' => (int) ($q['question_order'] ?? 1),
                    'question_text' => (string) ($q['question_text'] ?? ''),
                    'question_type' => strtolower((string) ($q['question_type'] ?? 'multiple_choice')),
                    'marks' => (float) ($q['marks'] ?? 1),
                    'student_answer' => $ans?->answer,
                    'correct_answer' => $q['correct_answer'] ?? null,
                    'grading_status' => $ans ? ($ans->grading_status ?: ($ans->is_correct === null ? 'pending_grading' : 'auto_graded')) : 'unanswered',
                    'is_correct' => $ans?->is_correct,
                    'marks_obtained' => $ans?->marks_obtained !== null ? (float) $ans->marks_obtained : null,
                    'teacher_feedback' => $ans?->teacher_feedback,
                ];
            })->values()->all();

            $st = $attempt->student;

            return [
                'attempt_id' => $attempt->id,
                'student_id' => $attempt->student_id,
                'student_code' => $st?->student_code ?? ('STU'.$attempt->student_id),
                'student_name' => $this->resolveStudentDisplayName($st),
                'attempt_number' => (int) $attempt->attempt_number,
                'started_at' => $attempt->started_at,
                'submitted_at' => $attempt->submitted_at,
                'attempt_status' => $attempt->status,
                'grading_status' => $pending ? 'pending_grading' : ($attempt->grading_status ?: ($finalized ? 'auto_graded' : 'not_submitted')),
                'score' => ($finalized || ! $pending) ? $attempt->score : null,
                'auto_graded_score' => $attempt->auto_graded_score,
                'manual_graded_score' => $attempt->manual_graded_score,
                'total_marks' => $totalMarks,
                'is_late' => (bool) $attempt->is_late,
                'is_reset' => (bool) $attempt->is_reset,
                'answers' => $answerItems,
            ];
        })->values();

        // Audit logs
        $logsQuery = AssessmentAuditLog::with(['student.user', 'performer'])
            ->where('assessment_type', 'quiz')
            ->where('assessment_id', $quizModel->id)
            ->orderByDesc('id');

        if ($studentFilterId !== null && $studentFilterId > 0) {
            $logsQuery->where('student_id', $studentFilterId);
        }
        if ($actionFilter !== '') {
            $logsQuery->where('action_type', $actionFilter);
        }
        if ($dateFrom !== '') {
            try {
                $logsQuery->whereDate('created_at', '>=', Carbon::parse($dateFrom)->toDateString());
            } catch (\Throwable) {
            }
        }
        if ($dateTo !== '') {
            try {
                $logsQuery->whereDate('created_at', '<=', Carbon::parse($dateTo)->toDateString());
            } catch (\Throwable) {
            }
        }

        $auditLogs = $logsQuery->limit(200)->get();
        $actionTypes = AssessmentAuditLog::where('assessment_type', 'quiz')
            ->where('assessment_id', $quizModel->id)
            ->distinct()
            ->pluck('action_type')
            ->filter()
            ->sort()
            ->values();

        $totalRecordedAttempts = QuizAttempt::where('quiz_id', $quizModel->id)->count();
        $totalAuditLogs = AssessmentAuditLog::where('assessment_type', 'quiz')
            ->where('assessment_id', $quizModel->id)
            ->count();

        return view('pages.teacher.quizzes.manage', [
            'quiz' => $quizModel,
            'tab' => $tab,
            'search' => $search,
            'statusFilter' => $statusFilter,
            'studentFilterId' => $studentFilterId,
            'actionFilter' => $actionFilter,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'statusOptions' => self::STATUS_OPTIONS,
            'allStudents' => $allStudentRows,
            'studentRows' => $filteredStudentRows,
            'activeOverridesCount' => $allStudentRows->where('has_active_override', true)->count(),
            'totalQuestions' => $totalQuestions,
            'totalMarks' => $defaultMax,
            'studentSummaries' => $studentSummaries,
            'attemptRows' => $attemptRows,
            'totalRecordedAttempts' => $totalRecordedAttempts,
            'auditLogs' => $auditLogs,
            'totalAuditLogs' => $totalAuditLogs,
            'actionTypes' => $actionTypes,
        ]);
    }

    /**
     * POST /teacher/quizzes/{quiz}/reopen
     */
    public function reopen(Request $request, int $quiz): RedirectResponse
    {
        [$teacher, $quizModel] = $this->authorizeTeacherAndQuiz($request, $quiz);

        $validated = $request->validate([
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

        $scope = (string) ($validated['scope'] ?? 'students');
        $studentIds = $this->extractStudentIds($validated);

        if (empty($studentIds) && $scope !== 'assessment') {
            throw ValidationException::withMessages([
                'student_ids' => ['Select at least one student to reopen this quiz.'],
            ]);
        }

        if (! empty($validated['new_deadline'])) {
            $newDt = Carbon::parse((string) $validated['new_deadline']);
            if ($quizModel->start_at && $newDt->lt($quizModel->start_at)) {
                throw ValidationException::withMessages([
                    'new_deadline' => ['New deadline must be after the quiz start time.'],
                ]);
            }
        }

        $this->reopen->reopenAssessment(
            $quizModel,
            'quiz',
            $scope === 'assessment' ? [] : $studentIds,
            $teacher,
            $request->user(),
            $validated
        );

        $message = $scope === 'assessment'
            ? 'Quiz reopened globally with audit trail recorded.'
            : 'Quiz reopen & permission override applied for '.count($studentIds).' student(s).';

        return redirect()
            ->route('teacher.quizzes.manage', ['quiz' => $quizModel->id, 'tab' => 'students'])
            ->with('status', $message);
    }

    /**
     * POST /teacher/quizzes/{quiz}/extend-deadline
     */
    public function extendDeadline(Request $request, int $quiz): RedirectResponse
    {
        [$teacher, $quizModel] = $this->authorizeTeacherAndQuiz($request, $quiz);

        $validated = $request->validate([
            'student_ids' => 'nullable|array',
            'student_ids.*' => 'integer|exists:students,id',
            'student_id' => 'nullable|integer|exists:students,id',
            'new_deadline' => 'required|date',
            'reason' => 'nullable|string|max:1000',
            'teacher_notes' => 'nullable|string|max:2000',
        ]);

        $studentIds = $this->extractStudentIds($validated);
        if (empty($studentIds)) {
            throw ValidationException::withMessages([
                'student_ids' => ['Select at least one student to extend the deadline.'],
            ]);
        }

        $newDeadline = Carbon::parse((string) $validated['new_deadline']);
        if ($quizModel->start_at && $newDeadline->lt($quizModel->start_at)) {
            throw ValidationException::withMessages([
                'new_deadline' => ['Extended deadline must be after the quiz start time.'],
            ]);
        }

        $this->reopen->extendDeadline(
            $quizModel,
            'quiz',
            $studentIds,
            $newDeadline,
            $teacher,
            $request->user(),
            $validated['reason'] ?? null,
            $validated['teacher_notes'] ?? null
        );

        return redirect()
            ->route('teacher.quizzes.manage', ['quiz' => $quizModel->id, 'tab' => 'students'])
            ->with('status', 'Deadline extended for '.count($studentIds).' student(s) without changing the original quiz deadline.');
    }

    /**
     * POST /teacher/quizzes/{quiz}/grant-attempts
     */
    public function grantAttempts(Request $request, int $quiz): RedirectResponse
    {
        [$teacher, $quizModel] = $this->authorizeTeacherAndQuiz($request, $quiz);

        $validated = $request->validate([
            'student_ids' => 'nullable|array',
            'student_ids.*' => 'integer|exists:students,id',
            'student_id' => 'nullable|integer|exists:students,id',
            'additional_attempts' => 'required|integer|min:1|max:50',
            'reason' => 'nullable|string|max:1000',
            'teacher_notes' => 'nullable|string|max:2000',
        ]);

        $studentIds = $this->extractStudentIds($validated);
        if (empty($studentIds)) {
            throw ValidationException::withMessages([
                'student_ids' => ['Select at least one student to grant additional attempts.'],
            ]);
        }

        $added = (int) $validated['additional_attempts'];
        $this->reopen->grantAdditionalAttempts(
            $quizModel,
            'quiz',
            $studentIds,
            $added,
            $teacher,
            $request->user(),
            $validated['reason'] ?? null,
            $validated['teacher_notes'] ?? null
        );

        return redirect()
            ->route('teacher.quizzes.manage', ['quiz' => $quizModel->id, 'tab' => 'students'])
            ->with('status', "Granted +{$added} additional attempt(s) for ".count($studentIds).' student(s).');
    }

    /**
     * POST /teacher/quizzes/{quiz}/revoke
     */
    public function revoke(Request $request, int $quiz): RedirectResponse
    {
        [$teacher, $quizModel] = $this->authorizeTeacherAndQuiz($request, $quiz);

        $validated = $request->validate([
            'student_ids' => 'nullable|array',
            'student_ids.*' => 'integer|exists:students,id',
            'student_id' => 'nullable|integer|exists:students,id',
            'reason' => 'nullable|string|max:1000',
        ]);

        $studentIds = $this->extractStudentIds($validated);
        if (empty($studentIds)) {
            throw ValidationException::withMessages([
                'student_ids' => ['Select at least one student to revoke reopen permissions.'],
            ]);
        }

        $res = $this->reopen->revokeReopenPermission(
            $quizModel,
            'quiz',
            $studentIds,
            $teacher,
            $request->user(),
            $validated['reason'] ?? null
        );

        if (empty($res['revoked_student_ids'])) {
            return redirect()
                ->route('teacher.quizzes.manage', ['quiz' => $quizModel->id, 'tab' => 'students'])
                ->with('error', 'No active reopen permissions found for the selected student(s).');
        }

        return redirect()
            ->route('teacher.quizzes.manage', ['quiz' => $quizModel->id, 'tab' => 'students'])
            ->with('status', 'Reopen permission revoked for '.count($res['revoked_student_ids']).' student(s); historical quiz attempts and answers remain preserved.');
    }

    /**
     * @return array{0: Teacher, 1: Quiz}
     */
    private function authorizeTeacherAndQuiz(Request $request, int $quizId): array
    {
        $user = $request->user();
        $teacher = $user ? $this->teacherAuth->resolveTeacher($user) : null;
        abort_unless($teacher, 403, 'Teacher profile not found.');

        $quiz = Quiz::with(['course', 'courseOffering.classRoom', 'semester'])->findOrFail($quizId);
        abort_unless($this->access->teacherCanManage($teacher, $quiz), 403, 'Unauthorized to manage this quiz.');

        return [$teacher, $quiz];
    }

    private function resolveStudentDisplayName(?Student $student): string
    {
        if (! $student) {
            return 'Student';
        }

        return PersonNameHelper::displayName(
            $student->english_name ?: trim(($student->first_name ?? '').' '.($student->last_name ?? '')),
            $student->khmer_name,
            $student->user?->name ?? 'Student'
        );
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
