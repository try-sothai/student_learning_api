<?php

namespace App\Http\Controllers;

use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Teacher;
use App\Services\TeacherAnalyticsService;
use App\Services\TeacherAuthorizationService;
use App\Services\TeacherDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherModuleController extends Controller
{
    public function __construct(
        protected TeacherAuthorizationService $authService,
        protected TeacherDashboardService $dashboardService,
        protected TeacherAnalyticsService $analyticsService
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
     * Summary cards for the teacher dashboard.
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
     * Dynamic authorized filters strictly from the teacher's offerings.
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
     * Student Learning Engagement Overview comparing Attendance, Assignment, Homework, Quiz.
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

        // Security check: If specific course_id or class_id is passed, verify authorization
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
            ->with(['course', 'classRoom', 'semester.academicYear', 'program'])
            ->withCount('enrollments')
            ->get();

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

        $students = CourseEnrollment::where('course_offering_id', $offering->id)
            ->with(['student.user', 'student.classRoom'])
            ->get()
            ->map(fn ($e) => [
                'enrollment_id' => $e->id,
                'student_id' => $e->student?->id,
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
        $sessions = $offering->attendanceSessions()
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
        $assignments = $offering->assignments()
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
        $quizzes = $offering->quizzes()
            ->withCount('attempts')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Course quizzes retrieved successfully.',
            'data' => $quizzes,
        ], 200);
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
        $grades = $offering->grades()
            ->with('student')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Course grades retrieved successfully.',
            'data' => $grades,
        ], 200);
    }
}
