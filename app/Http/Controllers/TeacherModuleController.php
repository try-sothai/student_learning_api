<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Attendance;
use App\Models\AttendanceDetail;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\GpaRecord;
use App\Models\Grade;
use App\Models\Prediction;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\Recommendation;
use App\Models\RiskAssessment;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\AssessmentAccessService;
use App\Services\SubmissionFileService;
use App\Services\TeacherAnalyticsService;
use App\Services\TeacherAuthorizationService;
use App\Services\TeacherDashboardService;
use App\Support\PersonNameHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class TeacherModuleController extends Controller
{
    public function __construct(
        protected TeacherAuthorizationService $authService,
        protected TeacherDashboardService $dashboardService,
        protected TeacherAnalyticsService $analyticsService,
        protected AssessmentAccessService $accessService,
        protected SubmissionFileService $submissionFiles
    ) {}

    /**
     * Resolve the authenticated teacher or abort with standard JSON.
     */
    protected function resolveTeacher(Request $request): Teacher
    {
        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        $teacher = $this->authService->resolveTeacher($user);
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

    /**
     * GET /api/teacher/dashboard
     */
    public function dashboard(Request $request): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);

        $filters = $request->only([
            'academic_year_id',
            'semester_id',
            'course_id',
            'class_id',
            'program_id',
            'study_year',
        ]);

        $summary = $this->dashboardService->getSummaryMetrics($teacher, $filters);

        return response()->json([
            'success' => true,
            'message' => 'Teacher dashboard summary retrieved successfully.',
            'data' => [
                'teacher' => [
                    'id' => $teacher->id,
                    'teacher_code' => $teacher->teacher_code,
                    'name' => trim($teacher->first_name . ' ' . $teacher->last_name),
                    'department' => $teacher->department?->name ?? 'Academic Faculty',
                    'email' => $teacher->user?->email,
                ],
                'summary' => $summary,
            ],
        ], 200);
    }

    /**
     * GET /api/teacher/filters
     */
    public function filters(Request $request): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);
        $filters = $this->dashboardService->getAuthorizedFilters($teacher);

        return response()->json([
            'success' => true,
            'message' => 'Teacher filters retrieved successfully.',
            'data' => $filters,
        ], 200);
    }

    /**
     * GET /api/teacher/analytics
     */
    public function analytics(Request $request): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);

        $filters = $request->only([
            'academic_year_id',
            'semester_id',
            'course_id',
            'class_id',
            'program_id',
            'study_year',
        ]);

        if (! empty($filters['course_id']) && ! $this->authService->isCourseAuthorized($teacher, (int) $filters['course_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to requested course.',
            ], 403);
        }

        if (! empty($filters['class_id']) && ! $this->authService->isClassAuthorized($teacher, (int) $filters['class_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to requested class.',
            ], 403);
        }

        $analytics = $this->analyticsService->calculateEngagement($teacher, $filters);

        return response()->json([
            'success' => true,
            'message' => 'Teacher analytics retrieved successfully.',
            'data' => $analytics,
        ], 200);
    }

    /**
     * GET /api/teacher/course-offerings
     */
    public function courseOfferings(Request $request): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);

        $offerings = $this->authService->getAuthorizedOfferingsQuery($teacher)
            ->with(['course.department', 'classRoom', 'semester.academicYear', 'program'])
            ->withCount('enrollments')
            ->get();

        $offeringIds = $offerings->pluck('id')->all();
        $courseIds = $offerings->pluck('course_id')->filter()->unique()->all();

        $gradesByCourseSem = Grade::whereIn('course_id', $courseIds)
            ->get()
            ->groupBy(fn ($g) => $g->course_id . ':' . $g->semester_id);

        $riskByCourseSem = RiskAssessment::whereIn('course_id', $courseIds)
            ->whereIn('risk_level', ['medium', 'high'])
            ->get()
            ->groupBy(fn ($r) => $r->course_id . ':' . $r->semester_id);

        $offerings->each(function (CourseOffering $off) use ($gradesByCourseSem, $riskByCourseSem) {
            $key = $off->course_id . ':' . $off->semester_id;
            $grades = $gradesByCourseSem->get($key, collect());
            $risks = $riskByCourseSem->get($key, collect());

            $avgScore = $grades->isNotEmpty() ? round((float) $grades->avg('total_score'), 1) : 0.0;
            $passRate = $grades->isNotEmpty()
                ? round(($grades->filter(fn ($g) => (float) $g->total_score >= 50)->count() / $grades->count()) * 100, 1)
                : 0.0;
            $atRiskCount = $risks->pluck('student_id')->unique()->count();

            $riskStatus = 'Good';
            if ($atRiskCount >= 5 || ($avgScore > 0 && $avgScore < 60)) {
                $riskStatus = 'At Risk';
            } elseif ($atRiskCount >= 2 || ($avgScore > 0 && $avgScore < 75)) {
                $riskStatus = 'Medium';
            }

            $off->setAttribute('average_score', $avgScore);
            $off->setAttribute('pass_rate', $passRate);
            $off->setAttribute('at_risk_count', $atRiskCount);
            $off->setAttribute('risk_status', $riskStatus);
        });

        return response()->json([
            'success' => true,
            'message' => 'Authorized course offerings retrieved successfully.',
            'data' => $offerings,
        ], 200);
    }

    /**
     * GET /api/teacher/course-offerings/{id}
     */
    public function courseOfferingDetail(Request $request, int $id): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);

        if (! $this->authService->isOfferingAuthorized($teacher, $id)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to course offering records.',
            ], 403);
        }

        $offering = CourseOffering::with([
            'course',
            'classRoom',
            'semester.academicYear',
            'program',
            'activeTeachers',
        ])->withCount('enrollments')->findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'Course offering details retrieved successfully.',
            'data' => $offering,
        ], 200);
    }

    /**
     * GET /api/teacher/course-offerings/{id}/students
     */
    public function courseOfferingStudents(Request $request, int $id): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);

        if (! $this->authService->isOfferingAuthorized($teacher, $id)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to course offering records.',
            ], 403);
        }

        $offering = CourseOffering::findOrFail($id);

        $students = CourseEnrollment::where(function (Builder $q) use ($offering) {
                $q->where('course_offering_id', $offering->id)
                  ->orWhere(function (Builder $sq) use ($offering) {
                      $sq->whereNull('course_offering_id')
                         ->where('course_id', $offering->course_id)
                         ->where('semester_id', $offering->semester_id);
                  });
            })
            ->with(['student.user', 'student.classRoom', 'student.department', 'student.program'])
            ->get()
            ->map(fn ($e) => [
                'enrollment_id' => $e->id,
                'student_id' => $e->student?->id,
                'user_id' => $e->student?->user_id,
                'student_code' => $e->student?->student_code,
                'name' => trim(($e->student?->first_name ?? '') . ' ' . ($e->student?->last_name ?? '')),
                'email' => $e->student?->user?->email,
                'class' => $e->student?->classRoom?->name,
                'status' => $e->status,
                'final_score' => $e->final_score,
                'grade' => $e->grade,
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Enrolled students retrieved successfully.',
            'data' => $students,
        ], 200);
    }

    /**
     * GET /api/teacher/students
     * Scoped list of all students enrolled in the teacher's authorized offerings.
     */
    public function students(Request $request): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);

        if ($request->filled('course_id') && ! $this->authService->isCourseAuthorized($teacher, (int) $request->query('course_id'))) {
            return response()->json(['success' => false, 'message' => 'Unauthorized access to requested course.'], 403);
        }

        $offeringsQuery = $this->authService->getAuthorizedOfferingsQuery($teacher)
            ->with(['course.department', 'semester.academicYear', 'classRoom']);

        if ($request->filled('course_id')) {
            $offeringsQuery->where('course_id', (int) $request->query('course_id'));
        }
        if ($request->filled('class_id')) {
            $offeringsQuery->where('class_id', (int) $request->query('class_id'));
        }
        if ($request->filled('semester_id')) {
            $offeringsQuery->where('semester_id', (int) $request->query('semester_id'));
        }
        if ($request->filled('academic_year_id')) {
            $offeringsQuery->where('academic_year_id', (int) $request->query('academic_year_id'));
        }

        $offerings = $offeringsQuery->get();
        $offeringIds = $offerings->pluck('id')->all();
        $courseIds = $offerings->pluck('course_id')->filter()->unique()->all();

        $enrollments = CourseEnrollment::query()
            ->where(function (Builder $q) use ($offeringIds, $courseIds) {
                $q->whereRaw('1 = 0');
                if (! empty($offeringIds)) {
                    $q->orWhereIn('course_offering_id', $offeringIds);
                }
                if (! empty($courseIds)) {
                    $q->orWhereIn('course_id', $courseIds);
                }
            })
            ->with(['student.user', 'student.department', 'student.program', 'student.classRoom', 'course', 'semester'])
            ->get();

        $studentIds = $enrollments->pluck('student_id')->filter()->unique()->all();

        $latestGpa = GpaRecord::whereIn('student_id', $studentIds)
            ->orderByDesc('semester_id')
            ->get()
            ->groupBy('student_id')
            ->map(fn ($rows) => (float) ($rows->first()->cumulative_gpa ?? $rows->first()->gpa ?? 0.0));

        $latestRisk = RiskAssessment::whereIn('student_id', $studentIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy('student_id')
            ->map(fn ($rows) => $rows->first());

        $latestPred = Prediction::whereIn('student_id', $studentIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy('student_id')
            ->map(fn ($rows) => (float) $rows->first()->predicted_score);

        $attByStudent = AttendanceDetail::whereIn('student_id', $studentIds)
            ->selectRaw("student_id, COUNT(*) as total, SUM(CASE WHEN status IN ('present','late') THEN 1 ELSE 0 END) as attended")
            ->groupBy('student_id')
            ->get()
            ->keyBy('student_id');

        $search = strtolower(trim((string) $request->query('search', '')));
        $riskFilter = strtolower(trim((string) $request->query('risk_level', '')));

        $studentsList = $enrollments
            ->groupBy('student_id')
            ->map(function ($studentEnrollments) use ($latestGpa, $latestRisk, $latestPred, $attByStudent) {
                $first = $studentEnrollments->first();
                $s = $first->student;
                if (! $s) {
                    return null;
                }
                $sid = $s->id;
                $name = $s->english_name ?: trim(($s->first_name ?? '') . ' ' . ($s->last_name ?? ''));
                $gpa = round($latestGpa->get($sid, 0.0), 2);
                $riskObj = $latestRisk->get($sid);
                $rawRisk = strtolower((string) ($riskObj?->risk_level ?? 'low'));
                $uiRisk = match ($rawRisk) {
                    'high' => 'At Risk',
                    'medium' => 'Need Attention',
                    default => 'Good',
                };
                $att = $attByStudent->get($sid);
                $attRate = ($att && $att->total > 0) ? (int) round(($att->attended / $att->total) * 100) : 0;
                $courseCode = $first->course?->code ?? $first->course?->course_code ?? 'N/A';
                $courseName = $first->course?->name ?? $first->course?->course_name ?? 'Course';

                return [
                    'id' => $sid,
                    'user_id' => $s->user_id,
                    'student_code' => $s->student_code ?? ('STU' . $sid),
                    'name' => $name,
                    'email' => $s->user?->email ?? '',
                    'department' => $s->department?->name ?? 'Academic Department',
                    'program' => $s->program?->code ?? $s->major ?? $courseCode,
                    'class_name' => $s->classRoom?->name ?? 'N/A',
                    'year_level' => 'Year ' . ($s->year_level ?? 1),
                    'course_id' => $first->course_id,
                    'course_code' => $courseCode,
                    'course_name' => $courseName,
                    'gpa' => $gpa,
                    'attendance_rate' => $attRate,
                    'risk_level' => $uiRisk,
                    'raw_risk_level' => $rawRisk,
                    'predicted_score' => $latestPred->get($sid, 0.0),
                    'status' => $s->status ?? 'active',
                ];
            })
            ->filter()
            ->filter(function ($item) use ($search, $riskFilter) {
                if ($search !== '') {
                    $haystack = strtolower($item['name'] . ' ' . $item['student_code'] . ' ' . $item['program'] . ' ' . $item['course_code'] . ' ' . $item['class_name']);
                    if (! str_contains($haystack, $search)) {
                        return false;
                    }
                }
                if ($riskFilter !== '' && $riskFilter !== 'all') {
                    if ($item['raw_risk_level'] !== $riskFilter && strtolower($item['risk_level']) !== $riskFilter) {
                        return false;
                    }
                }
                return true;
            })
            ->values();

        $allCourses = $this->authService->getAuthorizedOfferingsQuery($teacher)
            ->with('course')
            ->get()
            ->pluck('course')
            ->filter()
            ->unique('id')
            ->map(fn ($c) => [
                'id' => $c->id,
                'code' => $c->code ?? $c->course_code,
                'name' => $c->name ?? $c->course_name,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Teacher students retrieved successfully.',
            'data' => [
                'teacher' => [
                    'id' => $teacher->id,
                    'name' => trim($teacher->first_name . ' ' . $teacher->last_name),
                    'department' => $teacher->department?->name ?? 'Academic Department',
                ],
                'courses' => $allCourses,
                'students' => $studentsList,
            ],
        ], 200);
    }

    /**
     * GET /api/teacher/students/{studentId}
     * Complete teacher-authorized student detail & academic report.
     */
    public function studentDetail(Request $request, int $studentId): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);

        $student = Student::with(['user', 'department', 'program', 'classRoom'])
            ->where('id', $studentId)
            ->orWhere('user_id', $studentId)
            ->first();

        if (! $student) {
            return response()->json(['success' => false, 'message' => 'Student not found.'], 404);
        }

        if ($request->user()?->role !== 'admin' && ! $this->authService->isStudentAuthorized($teacher, $student->id)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized access to student record.'], 403);
        }

        $courseIds = $this->authService->getAuthorizedCourseIds($teacher);

        $attDetails = AttendanceDetail::join('attendance', 'attendance_details.attendance_id', '=', 'attendance.id')
            ->where('attendance_details.student_id', $student->id)
            ->whereIn('attendance.course_id', $courseIds)
            ->select('attendance.attendance_date', 'attendance_details.status', 'attendance.course_id')
            ->orderByDesc('attendance.attendance_date')
            ->get();

        $totalAtt = $attDetails->count();
        $presentAtt = $attDetails->where('status', 'present')->count();
        $lateAtt = $attDetails->where('status', 'late')->count();
        $absentAtt = $attDetails->where('status', 'absent')->count();
        $excusedAtt = $attDetails->where('status', 'excused')->count();
        $attRate = $totalAtt > 0 ? round((($presentAtt + $lateAtt) / $totalAtt) * 100, 1) : 0.0;

        $monthlyAtt = $attDetails->groupBy(fn ($r) => \Carbon\Carbon::parse($r->attendance_date)->format('M'))
            ->map(function ($rows, $month) {
                $tot = $rows->count();
                $att = $rows->whereIn('status', ['present', 'late'])->count();
                return [
                    'month' => $month,
                    'rate' => $tot > 0 ? round(($att / $tot) * 100, 1) : 0.0,
                ];
            })->values();

        $submissions = AssignmentSubmission::join('assignments', 'assignment_submissions.assignment_id', '=', 'assignments.id')
            ->where('assignment_submissions.student_id', $student->id)
            ->whereIn('assignments.course_id', $courseIds)
            ->select('assignments.title', 'assignments.assignment_type', 'assignments.total_score', 'assignment_submissions.score', 'assignment_submissions.status', 'assignment_submissions.submitted_at')
            ->orderByDesc('assignment_submissions.submitted_at')
            ->get();

        $quizAttempts = QuizAttempt::join('quizzes', 'quiz_attempts.quiz_id', '=', 'quizzes.id')
            ->where('quiz_attempts.student_id', $student->id)
            ->whereIn('quizzes.course_id', $courseIds)
            ->select('quizzes.title', 'quizzes.total_score', 'quiz_attempts.score', 'quiz_attempts.status', 'quiz_attempts.submitted_at')
            ->orderByDesc('quiz_attempts.submitted_at')
            ->get();

        $examResults = ExamResult::join('exams', 'exam_results.exam_id', '=', 'exams.id')
            ->where('exam_results.student_id', $student->id)
            ->whereIn('exams.course_id', $courseIds)
            ->select('exams.title', 'exams.exam_type', 'exams.total_score', 'exam_results.score', 'exams.exam_date')
            ->orderByDesc('exams.exam_date')
            ->get();

        $scores = collect();
        foreach ($submissions as $sub) {
            if ($sub->score !== null) {
                $scores->push([
                    'title' => $sub->title,
                    'type' => ucfirst($sub->assignment_type ?? 'Assignment'),
                    'score' => (float) $sub->score,
                    'total_score' => (float) ($sub->total_score ?? 100),
                ]);
            }
        }
        foreach ($quizAttempts as $qa) {
            if ($qa->score !== null) {
                $scores->push([
                    'title' => $qa->title,
                    'type' => 'Quiz',
                    'score' => (float) $qa->score,
                    'total_score' => (float) ($qa->total_score ?? 100),
                ]);
            }
        }
        foreach ($examResults as $ex) {
            if ($ex->score !== null) {
                $scores->push([
                    'title' => $ex->title,
                    'type' => ucfirst($ex->exam_type ?? 'Exam'),
                    'score' => (float) $ex->score,
                    'total_score' => (float) ($ex->total_score ?? 100),
                ]);
            }
        }

        $grades = Grade::with(['course', 'semester'])
            ->where('student_id', $student->id)
            ->whereIn('course_id', $courseIds)
            ->get();

        $gpaRecord = GpaRecord::where('student_id', $student->id)->orderByDesc('semester_id')->first();
        $risk = RiskAssessment::where('student_id', $student->id)->orderByDesc('id')->first();

        return response()->json([
            'success' => true,
            'message' => 'Student details retrieved successfully.',
            'data' => [
                'student' => [
                    'id' => $student->id,
                    'user_id' => $student->user_id,
                    'student_code' => $student->student_code,
                    'name' => $student->english_name ?: trim($student->first_name . ' ' . $student->last_name),
                    'email' => $student->user?->email,
                    'phone' => $student->phone,
                    'department' => $student->department?->name ?? 'Academic Department',
                    'program' => $student->program?->name ?? $student->major ?? 'Program',
                    'class_name' => $student->classRoom?->name ?? 'N/A',
                    'year_level' => 'Year ' . ($student->year_level ?? 1),
                    'gpa' => round((float) ($gpaRecord?->cumulative_gpa ?? $gpaRecord?->gpa ?? 0.0), 2),
                    'risk_level' => $risk?->risk_level ?? 'low',
                    'risk_score' => $risk?->risk_score ?? 0,
                    'risk_factors' => $risk?->risk_factors,
                    'status' => $student->status ?? 'active',
                ],
                'attendance' => [
                    'total' => $totalAtt,
                    'present' => $presentAtt,
                    'late' => $lateAtt,
                    'absent' => $absentAtt,
                    'excused' => $excusedAtt,
                    'rate' => $attRate,
                    'monthly' => $monthlyAtt,
                ],
                'scores' => $scores->values(),
                'grades' => $grades,
            ],
        ], 200);
    }

    /**
     * GET /api/teacher/course-offerings/{id}/attendance
     */
    public function courseOfferingAttendance(Request $request, int $id): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);

        if (! $this->authService->isOfferingAuthorized($teacher, $id)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to course offering records.',
            ], 403);
        }

        $offering = CourseOffering::findOrFail($id);
        $sessions = Attendance::where(function (Builder $q) use ($offering) {
                $q->where('course_offering_id', $offering->id)
                  ->orWhere(function (Builder $sq) use ($offering) {
                      $sq->whereNull('course_offering_id')
                         ->where('course_id', $offering->course_id)
                         ->where('semester_id', $offering->semester_id);
                  });
            })
            ->with(['details.student'])
            ->latest('attendance_date')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Attendance sessions retrieved successfully.',
            'data' => $sessions,
        ], 200);
    }

    /**
     * GET /api/teacher/attendance-overview
     * Complete teacher-scoped attendance overview, roster, sessions, and student breakdown.
     */
    public function attendanceOverview(Request $request): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);

        $offerings = $this->authService->getAuthorizedOfferingsQuery($teacher)
            ->with(['course', 'classRoom', 'semester'])
            ->get();

        if ($offerings->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No course offerings assigned.',
                'data' => [
                    'offerings' => [],
                    'summary' => ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'total' => 0, 'rate' => 0.0],
                    'roster' => [],
                    'sessions' => [],
                    'student_breakdown' => [],
                ],
            ]);
        }

        $selectedOfferingId = $request->filled('course_offering_id')
            ? (int) $request->query('course_offering_id')
            : $offerings->first()->id;

        $selectedOffering = $offerings->firstWhere('id', $selectedOfferingId) ?? $offerings->first();
        $offeringIds = $request->filled('course_offering_id') ? [$selectedOffering->id] : $offerings->pluck('id')->all();
        $courseIds = $request->filled('course_offering_id') ? [$selectedOffering->course_id] : $offerings->pluck('course_id')->filter()->unique()->all();

        $sessions = Attendance::with(['course', 'details'])
            ->where(function (Builder $q) use ($offeringIds, $courseIds) {
                $q->whereIn('course_offering_id', $offeringIds)
                  ->orWhereIn('course_id', $courseIds);
            })
            ->orderByDesc('attendance_date')
            ->take(30)
            ->get();

        $sessionIds = $sessions->pluck('id')->all();
        $statusCounts = AttendanceDetail::whereIn('attendance_id', $sessionIds)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $present = (int) ($statusCounts['present'] ?? 0);
        $absent = (int) ($statusCounts['absent'] ?? 0);
        $late = (int) ($statusCounts['late'] ?? 0);
        $excused = (int) ($statusCounts['excused'] ?? 0);
        $total = $present + $absent + $late + $excused;
        $rate = $total > 0 ? round((($present + $late) / $total) * 100, 1) : 0.0;

        $enrollments = CourseEnrollment::with(['student.classRoom'])
            ->where(function (Builder $q) use ($selectedOffering) {
                $q->where('course_offering_id', $selectedOffering->id)
                  ->orWhere(function (Builder $sq) use ($selectedOffering) {
                      $sq->whereNull('course_offering_id')
                         ->where('course_id', $selectedOffering->course_id)
                         ->where('semester_id', $selectedOffering->semester_id);
                  });
            })
            ->get();

        $roster = $enrollments->map(fn ($e) => [
            'student_id' => $e->student?->id,
            'student_code' => $e->student?->student_code ?? ('STU' . $e->student_id),
            'name' => $e->student?->english_name ?: trim(($e->student?->first_name ?? '') . ' ' . ($e->student?->last_name ?? '')),
            'class_name' => $e->student?->classRoom?->name ?? ($selectedOffering->classRoom?->name ?? 'Class'),
            'status' => 'P',
        ])->filter(fn ($r) => $r['student_id'] !== null)->unique('student_id')->values();

        $sessionRecords = $sessions->map(function (Attendance $s) use ($selectedOffering) {
            $det = $s->details;
            $p = $det->where('status', 'present')->count();
            $a = $det->where('status', 'absent')->count();
            $l = $det->where('status', 'late')->count();
            $tot = $det->count();
            $pct = $tot > 0 ? round((($p + $l) / $tot) * 100) : 0;
            return [
                'id' => $s->id,
                'date' => \Carbon\Carbon::parse($s->attendance_date)->format('M d, Y'),
                'course_code' => $s->course?->code ?? $s->course?->course_code ?? 'COURSE',
                'course_name' => $s->course?->name ?? $s->course?->course_name ?? 'Course',
                'class_name' => $selectedOffering->classRoom?->name ?? 'Section',
                'present' => $p,
                'absent' => $a,
                'late' => $l,
                'total' => $tot,
                'summary' => "Present: {$p} • Absent: {$a} • Late: {$l}",
                'rate_badge' => "{$pct}% Present",
                'status' => ucfirst($s->status ?? 'completed'),
            ];
        });

        $studentBreakdown = AttendanceDetail::whereIn('attendance_id', $sessionIds)
            ->with('student.classRoom')
            ->get()
            ->groupBy('student_id')
            ->map(function ($rows) {
                $st = $rows->first()->student;
                $tot = $rows->count();
                $p = $rows->where('status', 'present')->count();
                $a = $rows->where('status', 'absent')->count();
                $l = $rows->where('status', 'late')->count();
                $pct = $tot > 0 ? round((($p + $l) / $tot) * 100) : 0;
                return [
                    'student_id' => $st?->id,
                    'student_code' => $st?->student_code ?? 'STU',
                    'name' => $st?->english_name ?: trim(($st?->first_name ?? '') . ' ' . ($st?->last_name ?? '')),
                    'class_name' => $st?->classRoom?->name ?? 'Class',
                    'present' => $p,
                    'absent' => $a,
                    'late' => $l,
                    'rate' => $pct,
                ];
            })->values();

        return response()->json([
            'success' => true,
            'message' => 'Teacher attendance overview retrieved successfully.',
            'data' => [
                'selected_offering_id' => $selectedOffering->id,
                'offerings' => $offerings->map(fn ($o) => [
                    'id' => $o->id,
                    'course_id' => $o->course_id,
                    'course_code' => $o->course?->code ?? $o->course?->course_code,
                    'course_name' => $o->course?->name ?? $o->course?->course_name,
                    'class_name' => $o->classRoom?->name ?? $o->section_name ?? 'All',
                ]),
                'summary' => [
                    'present' => $present,
                    'absent' => $absent,
                    'late' => $late,
                    'excused' => $excused,
                    'total' => $total,
                    'rate' => $rate,
                ],
                'roster' => $roster,
                'sessions' => $sessionRecords,
                'student_breakdown' => $studentBreakdown,
            ],
        ]);
    }

    /**
     * POST /api/teacher/course-offerings/{id}/attendance
     * Record attendance for an authorized course offering inside a DB transaction.
     */
    public function storeAttendance(Request $request, int $id): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);

        if (! $this->authService->isOfferingAuthorized($teacher, $id)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to course offering records.',
            ], 403);
        }

        $offering = CourseOffering::findOrFail($id);

        $validated = $request->validate([
            'attendance_date' => 'required|date',
            'topic' => 'nullable|string|max:200',
            'records' => 'required|array|min:1',
            'records.*.student_id' => 'required|integer|exists:students,id',
            'records.*.status' => 'required|string|in:present,absent,late,excused,P,A,L,E',
            'records.*.remarks' => 'nullable|string|max:255',
        ]);

        $session = DB::transaction(function () use ($offering, $teacher, $validated) {
            $attendance = Attendance::updateOrCreate(
                [
                    'course_offering_id' => $offering->id,
                    'course_id' => $offering->course_id,
                    'semester_id' => $offering->semester_id,
                    'attendance_date' => $validated['attendance_date'],
                ],
                [
                    'teacher_id' => $teacher->id,
                    'topic' => $validated['topic'] ?? 'Regular Class Session',
                    'status' => 'completed',
                ]
            );

            foreach ($validated['records'] as $rec) {
                $normStatus = match (strtoupper($rec['status'])) {
                    'P', 'PRESENT' => 'present',
                    'A', 'ABSENT' => 'absent',
                    'L', 'LATE' => 'late',
                    'E', 'EXCUSED' => 'excused',
                    default => 'present',
                };

                AttendanceDetail::updateOrCreate(
                    [
                        'attendance_id' => $attendance->id,
                        'student_id' => $rec['student_id'],
                    ],
                    [
                        'status' => $normStatus,
                        'remarks' => $rec['remarks'] ?? null,
                    ]
                );
            }

            return $attendance->load('details.student');
        });

        return response()->json([
            'success' => true,
            'message' => 'Attendance recorded successfully.',
            'data' => $session,
        ], 201);
    }

    /**
     * GET /api/teacher/course-offerings/{id}/assignments
     */
    public function courseOfferingAssignments(Request $request, int $id): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);

        if (! $this->authService->isOfferingAuthorized($teacher, $id)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to course offering records.',
            ], 403);
        }

        $offering = CourseOffering::findOrFail($id);
        $assignments = Assignment::where(function (Builder $q) use ($offering) {
                $q->where('course_offering_id', $offering->id)
                  ->orWhere(function (Builder $sq) use ($offering) {
                      $sq->whereNull('course_offering_id')
                         ->where('course_id', $offering->course_id)
                         ->where('semester_id', $offering->semester_id);
                  });
            })
            ->withCount(['submissions as total_submissions', 'submissions as graded_submissions' => function ($q) {
                $q->where('status', 'graded');
            }])
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Course assignments retrieved successfully.',
            'data' => $assignments,
        ], 200);
    }

    /**
     * POST /api/teacher/course-offerings/{id}/assignments
     */
    public function storeAssignment(Request $request, int $id): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);

        if (! $this->authService->isOfferingAuthorized($teacher, $id)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized access to course offering records.'], 403);
        }

        $offering = CourseOffering::findOrFail($id);

        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'assignment_type' => 'nullable|string|in:assignment,homework,project,lab,file_upload',
            'total_score' => 'nullable|numeric|min:1|max:1000',
            'due_date' => 'required|date',
            'status' => 'nullable|string|in:draft,published,closed',
        ]);

        $assignment = Assignment::create([
            'course_id' => $offering->course_id,
            'course_offering_id' => $offering->id,
            'teacher_id' => $teacher->id,
            'semester_id' => $offering->semester_id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'assignment_type' => $validated['assignment_type'] ?? 'assignment',
            'total_score' => $validated['total_score'] ?? 100,
            'due_date' => $validated['due_date'],
            'status' => $validated['status'] ?? 'published',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Assignment created successfully.',
            'data' => $assignment,
        ], 201);
    }

    /**
     * PUT /api/teacher/assignments/{assignmentId}
     */
    public function updateAssignment(Request $request, int $assignmentId): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);
        $assignment = Assignment::findOrFail($assignmentId);

        if (! app(\App\Services\AssessmentAccessService::class)->teacherCanManage($teacher, $assignment)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized to modify this assignment.'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:200',
            'description' => 'nullable|string',
            'total_score' => 'sometimes|numeric|min:1|max:1000',
            'due_date' => 'sometimes|date',
            'status' => 'sometimes|string|in:draft,published,closed',
        ]);

        $assignment->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Assignment updated successfully.',
            'data' => $assignment->fresh(),
        ], 200);
    }

    /**
     * DELETE /api/teacher/assignments/{assignmentId}
     */
    public function deleteAssignment(Request $request, int $assignmentId): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);
        $assignment = Assignment::findOrFail($assignmentId);

        if (! app(\App\Services\AssessmentAccessService::class)->teacherCanManage($teacher, $assignment)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized to delete this assignment.'], 403);
        }
        if ($assignment->submissions()->exists()) {
            return response()->json(['success' => false, 'message' => 'Students have already submitted work for this assignment. Close it instead of deleting it.'], 409);
        }

        $assignment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Assignment deleted successfully.',
        ], 200);
    }

    /**
     * Authorize teacher or admin access to a specific student assignment submission.
     */
    protected function authorizeTeacherSubmission(Request $request, int $submissionId): AssignmentSubmission
    {
        $submission = AssignmentSubmission::with([
            'student.user',
            'student.classRoom',
            'assignment.course',
            'assignment.courseOffering.classRoom',
        ])->findOrFail($submissionId);

        if ($request->user()?->role !== 'admin') {
            $teacher = $this->resolveTeacher($request);
            if (! $submission->assignment || ! $this->accessService->teacherCanManage($teacher, $submission->assignment)) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to access this submission.',
                ], 403));
            }
        }

        return $submission;
    }

    /**
     * Format a submission consistently with safe file metadata (never exposing raw server paths).
     */
    protected function presentSubmission(AssignmentSubmission $s): array
    {
        $fileMeta = $this->submissionFiles->describeForApi($s);
        $student = $s->student;
        $assignment = $s->assignment;
        $course = $assignment?->course;

        $studentName = PersonNameHelper::displayName(
            $student?->english_name ?: trim(($student?->first_name ?? '') . ' ' . ($student?->last_name ?? '')),
            $student?->khmer_name,
            $student?->user?->name ?? 'Student'
        );

        $rawStatus = strtolower((string) ($s->status ?? 'submitted'));
        $displayStatus = match (true) {
            $rawStatus === 'graded' => 'Graded',
            $s->score !== null => 'Saved (Draft)',
            $rawStatus === 'late' => 'Late',
            $rawStatus === 'submitted' => 'Submitted On Time',
            default => ucfirst($rawStatus !== '' ? $rawStatus : 'Pending'),
        };

        return array_merge([
            'id' => $s->id,
            'student_id' => $s->student_id,
            'student_code' => $student?->student_code ?? ('STU' . $s->student_id),
            'student_name' => $studentName,
            'class_name' => $student?->classRoom?->name ?? $assignment?->courseOffering?->classRoom?->name ?? 'Class',
            'assignment_id' => $s->assignment_id,
            'assignment_title' => $assignment?->title ?? 'Assignment',
            'assessment_type' => ucfirst(str_replace('_', ' ', (string) ($assignment?->assignment_type ?? 'assignment'))),
            'course_code' => $course?->code ?? $course?->course_code ?? 'COURSE',
            'course_name' => $course?->name ?? $course?->course_name ?? 'Course',
            'submitted_at' => $s->submitted_at ? \Carbon\Carbon::parse($s->submitted_at)->format('M d, Y h:i A') : '-',
            'score' => $s->score !== null ? (float) $s->score : null,
            'total_score' => (float) ($assignment?->total_score ?? 100),
            'max_score' => (float) ($assignment?->total_score ?? 100),
            'feedback' => $s->feedback ?? '',
            'raw_status' => $s->status ?? 'submitted',
            'status' => $displayStatus,
            'is_published' => $rawStatus === 'graded',
        ], $fileMeta);
    }

    /**
     * GET /api/teacher/submissions/{submissionId}
     */
    public function showSubmission(Request $request, int $submissionId): JsonResponse
    {
        $submission = $this->authorizeTeacherSubmission($request, $submissionId);

        return response()->json([
            'success' => true,
            'message' => 'Submission retrieved successfully.',
            'data' => $this->presentSubmission($submission),
        ], 200);
    }

    /**
     * GET /api/teacher/submissions/{submissionId}/file
     */
    public function streamSubmissionFile(Request $request, int $submissionId): HttpResponse
    {
        $submission = $this->authorizeTeacherSubmission($request, $submissionId);

        return $this->submissionFiles->streamFileResponse($submission, $request->boolean('download'));
    }

    /**
     * POST /api/teacher/submissions/{submissionId}/grade
     */
    public function gradeSubmission(Request $request, int $submissionId): JsonResponse
    {
        $submission = $this->authorizeTeacherSubmission($request, $submissionId);

        $maxScore = (float) ($submission->assignment->total_score ?? 100);
        $validated = $request->validate([
            'score' => "required|numeric|min:0|max:{$maxScore}",
            'feedback' => 'nullable|string',
            'publish' => 'nullable|boolean',
        ]);

        $publish = $request->has('publish') ? $request->boolean('publish') : true;
        $nextStatus = $publish
            ? 'graded'
            : ($submission->status === 'graded' ? 'graded' : 'returned');

        $submission->update([
            'score' => $validated['score'],
            'feedback' => array_key_exists('feedback', $validated) ? $validated['feedback'] : $submission->feedback,
            'status' => $nextStatus,
        ]);

        $fresh = $submission->fresh([
            'student.user',
            'student.classRoom',
            'assignment.course',
            'assignment.courseOffering.classRoom',
        ]);

        return response()->json([
            'success' => true,
            'message' => $nextStatus === 'graded' ? 'Submission graded and published successfully.' : 'Draft score saved successfully.',
            'data' => $this->presentSubmission($fresh),
        ], 200);
    }

    /**
     * GET /api/teacher/course-offerings/{id}/quizzes
     */
    public function courseOfferingQuizzes(Request $request, int $id): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);

        if (! $this->authService->isOfferingAuthorized($teacher, $id)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to course offering records.',
            ], 403);
        }

        $offering = CourseOffering::findOrFail($id);
        $quizzes = Quiz::where(function (Builder $q) use ($offering) {
                $q->where('course_offering_id', $offering->id)
                  ->orWhere(function (Builder $sq) use ($offering) {
                      $sq->whereNull('course_offering_id')
                         ->where('course_id', $offering->course_id)
                         ->where('semester_id', $offering->semester_id);
                  });
            })
            ->withCount(['attempts', 'questions'])
            ->withSum('questions as questions_total_marks', 'marks')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Course quizzes retrieved successfully.',
            'data' => $quizzes,
        ], 200);
    }

    /**
     * POST /api/teacher/course-offerings/{id}/quizzes
     */
    public function storeQuiz(Request $request, int $id): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);

        if (! $this->authService->isOfferingAuthorized($teacher, $id)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized access to course offering records.'], 403);
        }

        $offering = CourseOffering::findOrFail($id);

        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'duration_minutes' => 'nullable|integer|min:1|max:300',
            'total_score' => 'nullable|numeric|min:1|max:1000',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date',
            'status' => 'nullable|string|in:draft,published,closed',
        ]);

        if (($validated['status'] ?? 'draft') === 'published') {
            return response()->json([
                'success' => false,
                'message' => 'Save the quiz as a draft, add its questions, then publish it.',
            ], 422);
        }
        if (! empty($validated['start_at']) && ! empty($validated['end_at']) && strtotime($validated['end_at']) < strtotime($validated['start_at'])) {
            return response()->json(['success' => false, 'message' => 'The end time must be after the start time.'], 422);
        }

        $quiz = Quiz::create([
            'course_id' => $offering->course_id,
            'course_offering_id' => $offering->id,
            'teacher_id' => $teacher->id,
            'semester_id' => $offering->semester_id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'duration_minutes' => $validated['duration_minutes'] ?? 30,
            'total_score' => $validated['total_score'] ?? 0,
            'start_at' => $validated['start_at'] ?? now(),
            'end_at' => $validated['end_at'] ?? now()->addDays(7),
            'status' => 'draft',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Quiz created successfully.',
            'data' => $quiz,
        ], 201);
    }

    /**
     * GET /api/teacher/assessments-overview
     * Unified teacher-scoped assignments, quizzes, submissions, exams, and gradebook.
     */
    public function assessmentsOverview(Request $request): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);

        if ($request->filled('course_id') && ! $this->authService->isCourseAuthorized($teacher, (int) $request->query('course_id'))) {
            return response()->json(['success' => false, 'message' => 'Unauthorized access to requested course.'], 403);
        }

        $offeringsQuery = $this->authService->getAuthorizedOfferingsQuery($teacher)->with(['course', 'classRoom']);
        if ($request->filled('course_id')) {
            $offeringsQuery->where('course_id', (int) $request->query('course_id'));
        }
        $offerings = $offeringsQuery->get();
        $offeringIds = $offerings->pluck('id')->all();
        $courseIds = $offerings->pluck('course_id')->filter()->unique()->all();

        $assignments = Assignment::with(['course', 'courseOffering.classRoom'])
            ->whereIn('course_offering_id', $offeringIds)
            ->withCount([
                'submissions as total_submissions',
                'submissions as graded_submissions' => fn ($q) => $q->where('status', 'graded'),
                'submissions as pending_submissions' => fn ($q) => $q->whereNull('score'),
            ])
            ->latest('due_date')
            ->get()
            ->map(fn (Assignment $a) => [
                'id' => $a->id,
                'code' => 'ASN-' . $a->id,
                'title' => $a->title,
                'assignment_type' => $a->assignment_type,
                'course_offering_id' => $a->course_offering_id,
                'class_name' => $a->courseOffering?->classRoom?->name,
                'raw_status' => $a->status,
                'course_id' => $a->course_id,
                'course_code' => $a->course?->code ?? $a->course?->course_code ?? 'COURSE',
                'course_name' => $a->course?->name ?? $a->course?->course_name ?? 'Course',
                'due_date' => $a->due_date ? \Carbon\Carbon::parse($a->due_date)->format('M d, Y') : '-',
                'total_score' => (float) ($a->total_score ?? 100),
                'status' => ucfirst($a->status ?? 'published'),
                'total_submissions' => $a->total_submissions,
                'graded_submissions' => $a->graded_submissions,
                'pending_submissions' => $a->pending_submissions,
            ]);

        $quizzes = Quiz::with(['course', 'courseOffering.classRoom'])
            ->whereIn('course_offering_id', $offeringIds)
            ->withCount(['questions', 'attempts'])
            ->withSum('questions as questions_total_marks', 'marks')
            ->latest('start_at')
            ->get()
            ->map(fn (Quiz $qz) => [
                'id' => $qz->id,
                'code' => 'QZ-' . $qz->id,
                'title' => $qz->title,
                'course_offering_id' => $qz->course_offering_id,
                'class_name' => $qz->courseOffering?->classRoom?->name,
                'raw_status' => $qz->status,
                'total_marks' => (float) ($qz->questions_total_marks ?? 0),
                'course_id' => $qz->course_id,
                'course_code' => $qz->course?->code ?? $qz->course?->course_code ?? 'COURSE',
                'course_name' => $qz->course?->name ?? $qz->course?->course_name ?? 'Course',
                'questions_count' => $qz->questions_count,
                'attempts_count' => $qz->attempts_count,
                'duration_minutes' => $qz->duration_minutes ?? 30,
                'total_score' => (float) ($qz->total_score ?? 100),
                'status' => ucfirst($qz->status ?? 'published'),
            ]);

        $submissions = AssignmentSubmission::with([
                'student.user',
                'student.classRoom',
                'assignment.course',
                'assignment.courseOffering.classRoom',
            ])
            ->whereHas('assignment', fn ($q) => $q->whereIn('course_offering_id', $offeringIds))
            ->latest('submitted_at')
            ->take(50)
            ->get()
            ->map(fn (AssignmentSubmission $s) => $this->presentSubmission($s));

        $exams = Exam::with(['course', 'courseOffering.classRoom'])
            ->whereIn('course_offering_id', $offeringIds)
            ->withCount('results')
            ->latest('exam_date')
            ->get()
            ->map(fn (Exam $ex) => [
                'id' => $ex->id,
                'code' => 'EX-' . $ex->id,
                'title' => $ex->title,
                'course_offering_id' => $ex->course_offering_id,
                'class_name' => $ex->courseOffering?->classRoom?->name,
                'raw_status' => $ex->status,
                'venue' => $ex->venue,
                'duration_minutes' => $ex->duration_minutes,
                'exam_type' => ucfirst($ex->exam_type ?? 'Final'),
                'course_code' => $ex->course?->code ?? $ex->course?->course_code ?? 'COURSE',
                'course_name' => $ex->course?->name ?? $ex->course?->course_name ?? 'Course',
                'exam_date' => $ex->exam_date ? \Carbon\Carbon::parse($ex->exam_date)->format('M d, Y') : '-',
                'total_score' => (float) ($ex->total_score ?? 100),
                'results_count' => $ex->results_count,
            ]);

        $grades = Grade::with(['student.classRoom', 'course'])
            ->whereIn('course_offering_id', $offeringIds)
            ->latest()
            ->take(100)
            ->get()
            ->map(fn (Grade $g) => [
                'id' => $g->id,
                'student_id' => $g->student_id,
                'student_code' => $g->student?->student_code ?? ('STU' . $g->student_id),
                'student_name' => $g->student?->english_name ?: trim(($g->student?->first_name ?? '') . ' ' . ($g->student?->last_name ?? '')),
                'course_code' => $g->course?->code ?? $g->course?->course_code ?? 'COURSE',
                'assignment_score' => (float) ($g->assignment_score ?? 0),
                'quiz_score' => (float) ($g->quiz_score ?? 0),
                'attendance_score' => (float) ($g->attendance_score ?? 0),
                'midterm_score' => (float) ($g->midterm_score ?? 0),
                'final_exam_score' => (float) ($g->final_exam_score ?? 0),
                'total_score' => (float) ($g->total_score ?? 0),
                'letter_grade' => $g->letter_grade ?? 'N/A',
                'grade_point' => (float) ($g->grade_point ?? 0),
            ]);

        $allCourses = $this->authService->getAuthorizedOfferingsQuery($teacher)
            ->with('course')
            ->get()
            ->map(fn ($o) => [
                'offering_id' => $o->id,
                'course_id' => $o->course_id,
                'code' => $o->course?->code ?? $o->course?->course_code,
                'name' => $o->course?->name ?? $o->course?->course_name,
            ])
            ->unique('course_id')
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Teacher assessments overview retrieved successfully.',
            'data' => [
                'courses' => $allCourses,
                'summary' => [
                    'total_assignments' => $assignments->count(),
                    'total_quizzes' => $quizzes->count(),
                    'total_exams' => $exams->count(),
                    'pending_submissions' => $submissions->where('score', null)->count(),
                ],
                'assignments' => $assignments,
                'quizzes' => $quizzes,
                'submissions' => $submissions,
                'exams' => $exams,
                'gradebook' => $grades,
            ],
        ]);
    }

    /**
     * GET /api/teacher/course-offerings/{id}/scores
     */
    public function courseOfferingScores(Request $request, int $id): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);

        if (! $this->authService->isOfferingAuthorized($teacher, $id)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to course offering records.',
            ], 403);
        }

        $offering = CourseOffering::findOrFail($id);
        $grades = Grade::where(function (Builder $q) use ($offering) {
                $q->where('course_offering_id', $offering->id)
                  ->orWhere(function (Builder $sq) use ($offering) {
                      $sq->whereNull('course_offering_id')
                         ->where('course_id', $offering->course_id)
                         ->where('semester_id', $offering->semester_id);
                  });
            })
            ->with('student')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Course grades retrieved successfully.',
            'data' => $grades,
        ], 200);
    }

    /**
     * POST /api/teacher/course-offerings/{id}/scores
     */
    public function storeScore(Request $request, int $id): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);

        if (! $this->authService->isOfferingAuthorized($teacher, $id)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized access to course offering records.'], 403);
        }

        $offering = CourseOffering::findOrFail($id);

        $validated = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'assignment_score' => 'nullable|numeric|min:0|max:100',
            'quiz_score' => 'nullable|numeric|min:0|max:100',
            'attendance_score' => 'nullable|numeric|min:0|max:100',
            'midterm_score' => 'nullable|numeric|min:0|max:100',
            'final_exam_score' => 'nullable|numeric|min:0|max:100',
            'total_score' => 'nullable|numeric|min:0|max:100',
        ]);

        $total = $validated['total_score'] ?? round(
            ((float) ($validated['assignment_score'] ?? 0) * 0.20) +
            ((float) ($validated['quiz_score'] ?? 0) * 0.15) +
            ((float) ($validated['attendance_score'] ?? 0) * 0.10) +
            ((float) ($validated['midterm_score'] ?? 0) * 0.25) +
            ((float) ($validated['final_exam_score'] ?? 0) * 0.30),
            2
        );

        [$letter, $gp] = match (true) {
            $total >= 90 => ['A', 4.0],
            $total >= 85 => ['A-', 3.7],
            $total >= 80 => ['B+', 3.3],
            $total >= 75 => ['B', 3.0],
            $total >= 70 => ['B-', 2.7],
            $total >= 65 => ['C+', 2.3],
            $total >= 60 => ['C', 2.0],
            $total >= 50 => ['D', 1.0],
            default => ['F', 0.0],
        };

        $grade = Grade::updateOrCreate(
            [
                'student_id' => $validated['student_id'],
                'course_id' => $offering->course_id,
                'semester_id' => $offering->semester_id,
            ],
            array_merge($validated, [
                'course_offering_id' => $offering->id,
                'total_score' => $total,
                'letter_grade' => $letter,
                'grade_point' => $gp,
                'status' => 'finalized',
            ])
        );

        return response()->json([
            'success' => true,
            'message' => 'Student score saved and published successfully.',
            'data' => $grade->load('student'),
        ], 201);
    }

    /**
     * GET /api/teacher/risk-predictions
     * Teacher-scoped risk monitoring, predictions, model evaluations, and recommendations.
     */
    public function riskPredictions(Request $request): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);
        $courseIds = $this->authService->getAuthorizedCourseIds($teacher);

        $latestRiskIds = RiskAssessment::whereIn('course_id', $courseIds)
            ->selectRaw('MAX(id) as id')
            ->groupBy('student_id')
            ->pluck('id');

        $risks = RiskAssessment::with(['student.user', 'student.classRoom', 'course'])
            ->whereIn('id', $latestRiskIds)
            ->get();

        $lowCount = $risks->where('risk_level', 'low')->count();
        $medCount = $risks->where('risk_level', 'medium')->count();
        $highCount = $risks->where('risk_level', 'high')->count();

        $atRiskStudents = $risks->whereIn('risk_level', ['medium', 'high'])
            ->map(function (RiskAssessment $r) {
                $s = $r->student;
                $factors = is_string($r->risk_factors) ? json_decode($r->risk_factors, true) : $r->risk_factors;
                $factorText = is_array($factors) ? implode(', ', $factors) : 'Academic & Attendance Risk';
                return [
                    'student_id' => $s?->id,
                    'user_id' => $s?->user_id,
                    'student_code' => $s?->student_code ?? ('STU' . $r->student_id),
                    'name' => $s?->english_name ?: trim(($s?->first_name ?? '') . ' ' . ($s?->last_name ?? '')),
                    'email' => $s?->user?->email ?? '',
                    'class_name' => $s?->classRoom?->name ?? 'Class',
                    'course_code' => $r->course?->code ?? $r->course?->course_code ?? 'COURSE',
                    'risk_level' => ucfirst($r->risk_level) . ' Risk',
                    'risk_score' => (float) $r->risk_score,
                    'risk_factors' => $factorText,
                ];
            })->values();

        $grades = Grade::whereIn('course_id', $courseIds)->pluck('total_score')->filter();
        $scoreBuckets = [
            '90-100' => $grades->filter(fn ($s) => $s >= 90)->count(),
            '80-89' => $grades->filter(fn ($s) => $s >= 80 && $s < 90)->count(),
            '70-79' => $grades->filter(fn ($s) => $s >= 70 && $s < 80)->count(),
            '60-69' => $grades->filter(fn ($s) => $s >= 60 && $s < 70)->count(),
            '<60' => $grades->filter(fn ($s) => $s < 60)->count(),
        ];

        $predictions = Prediction::with(['student', 'course'])
            ->whereIn('course_id', $courseIds)
            ->latest('prediction_date')
            ->get()
            ->map(fn (Prediction $p) => [
                'id' => $p->id,
                'student_code' => $p->student?->student_code ?? ('STU' . $p->student_id),
                'student_name' => $p->student?->english_name ?: trim(($p->student?->first_name ?? '') . ' ' . ($p->student?->last_name ?? '')),
                'course_code' => $p->course?->code ?? $p->course?->course_code ?? 'COURSE',
                'predicted_score' => (float) $p->predicted_score,
                'confidence' => round(((float) ($p->confidence_score ?? 0.85)) * 100),
                'model_version' => $p->model_version ?? 'v1.0',
                'date' => (string) $p->prediction_date,
            ]);

        $courseRisks = \App\Models\Course::whereIn('id', $courseIds)->get()->map(function ($c) {
            $cRisks = RiskAssessment::where('course_id', $c->id)->get();
            $high = $cRisks->where('risk_level', 'high')->pluck('student_id')->unique()->count();
            $med = $cRisks->where('risk_level', 'medium')->pluck('student_id')->unique()->count();
            $avgScore = Grade::where('course_id', $c->id)->avg('total_score') ?? 0;
            return [
                'course_id' => $c->id,
                'course_code' => $c->code ?? $c->course_code,
                'course_name' => $c->name ?? $c->course_name,
                'high_risk_count' => $high,
                'medium_risk_count' => $med,
                'at_risk_total' => $high + $med,
                'average_score' => round((float) $avgScore, 1),
            ];
        });

        $recommendations = Recommendation::with(['student', 'course'])
            ->whereIn('course_id', $courseIds)
            ->latest()
            ->get()
            ->map(fn (Recommendation $rec) => [
                'id' => $rec->id,
                'student_id' => $rec->student_id,
                'student_code' => $rec->student?->student_code ?? ('STU' . $rec->student_id),
                'student_name' => $rec->student?->english_name ?: trim(($rec->student?->first_name ?? '') . ' ' . ($rec->student?->last_name ?? '')),
                'course_code' => $rec->course?->code ?? $rec->course?->course_code ?? 'COURSE',
                'title' => $rec->title,
                'message' => $rec->message,
                'priority' => ucfirst($rec->priority ?? 'medium'),
                'status' => ucfirst($rec->status ?? 'pending'),
                'date' => $rec->created_at?->format('M d, Y') ?? '-',
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Teacher risk and prediction analytics retrieved successfully.',
            'data' => [
                'risk_distribution' => [
                    'low' => $lowCount,
                    'medium' => $medCount,
                    'high' => $highCount,
                    'total' => $lowCount + $medCount + $highCount,
                ],
                'score_buckets' => $scoreBuckets,
                'at_risk_students' => $atRiskStudents,
                'predictions' => $predictions,
                'has_predictions' => $predictions->isNotEmpty(),
                'course_risks' => $courseRisks,
                'recommendations' => $recommendations,
            ],
        ]);
    }

    /**
     * POST /api/teacher/recommendations
     */
    public function storeRecommendation(Request $request): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);

        $validated = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'course_id' => 'nullable|integer|exists:courses,id',
            'title' => 'required|string|max:200',
            'message' => 'required|string',
            'priority' => 'nullable|string|in:low,medium,high',
        ]);

        if (! $this->authService->isStudentAuthorized($teacher, (int) $validated['student_id'])) {
            return response()->json(['success' => false, 'message' => 'Unauthorized to create recommendation for this student.'], 403);
        }

        $courseIds = $this->authService->getAuthorizedCourseIds($teacher);
        $courseId = $validated['course_id'] ?? ($courseIds[0] ?? null);

        $rec = Recommendation::create([
            'student_id' => $validated['student_id'],
            'course_id' => $courseId,
            'type' => 'intervention',
            'title' => $validated['title'],
            'message' => $validated['message'],
            'priority' => strtolower($validated['priority'] ?? 'medium'),
            'status' => 'pending',
            'generated_by' => 'teacher',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Intervention plan created successfully.',
            'data' => $rec->load(['student', 'course']),
        ], 201);
    }

    /**
     * PATCH /api/teacher/recommendations/{id}
     */
    public function updateRecommendation(Request $request, int $id): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);
        $rec = Recommendation::findOrFail($id);

        if ($rec->course_id && ! $this->authService->isCourseAuthorized($teacher, (int) $rec->course_id)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized to update this recommendation.'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:pending,viewed,completed,dismissed',
        ]);

        $rec->update(['status' => strtolower($validated['status'])]);

        return response()->json([
            'success' => true,
            'message' => 'Recommendation updated successfully.',
            'data' => $rec->fresh(),
        ], 200);
    }
}
