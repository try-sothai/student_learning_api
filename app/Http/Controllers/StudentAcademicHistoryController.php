<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentProgressionHistory;
use App\Services\AcademicProgressionService;
use App\Services\GpaCalculationService;
use App\Services\RetakeManagementService;
use App\Services\StudentAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentAcademicHistoryController extends Controller
{
    public function __construct(
        protected StudentAuthorizationService $authService,
        protected GpaCalculationService $gpaService,
        protected AcademicProgressionService $progressionService,
        protected RetakeManagementService $retakeService
    ) {}

    protected function resolveTargetStudent(Request $request): ?Student
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        if (in_array($user->role, ['admin', 'teacher'], true) && $request->filled('student_id')) {
            return Student::find((int) $request->input('student_id'));
        }

        return $this->authService->resolveStudent($user);
    }

    /**
     * GET /api/student/gpa-overview
     * GET /api/student/module/gpa-overview
     *
     * Returns the student's current academic semester GPA, current semester courses,
     * scores, grades, semester average, and academic profile.
     */
    public function gpaOverview(Request $request): JsonResponse
    {
        $student = $this->resolveTargetStudent($request);
        if (! $student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $history = $this->gpaService->buildFullAcademicHistory($student);
        $currentSem = $history['current_semester'];

        return response()->json([
            'success' => true,
            'message' => 'Student current semester GPA overview retrieved successfully.',
            'data' => [
                'student' => $history['student'],
                'summary' => $history['summary'],
                'current_semester' => $currentSem,
                'courses' => $currentSem['courses'] ?? [],
                'semester_average' => $currentSem['semester_average'] ?? null,
                'semester_average_display' => $currentSem['semester_average_display'] ?? 'N/A',
                'semester_gpa' => $currentSem['semester_gpa'] ?? null,
                'semester_gpa_display' => $currentSem['semester_gpa_display'] ?? 'N/A',
            ],
        ], 200);
    }

    /**
     * GET /api/student/gpa-history
     * GET /api/student/module/gpa-history
     *
     * Returns full Year 1 - Year 4 GPA Academic History including:
     *  - Completed academic years (real historical scores, grades, semester averages, semester GPAs)
     *  - Current academic year (enrolled subjects, available official scores, N/A for pending)
     *  - Future academic years (planned Major curriculum subjects with N/A score/grade/average/GPA)
     *  - Separate original and retake attempts
     */
    public function gpaHistory(Request $request): JsonResponse
    {
        $student = $this->resolveTargetStudent($request);
        if (! $student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $history = $this->gpaService->buildFullAcademicHistory($student);
        $retakeOverview = $this->retakeService->getStudentRetakeOverview($student);

        if ($request->filled('study_year') || $request->filled('year_level')) {
            $filterYear = \App\Support\YearLevel::parse($request->input('study_year', $request->input('year_level')));
            if ($filterYear !== null) {
                $history['selected_year'] = collect($history['years'])->firstWhere('study_year', $filterYear);
            }
        }

        $history['retakes'] = $retakeOverview;

        return response()->json([
            'success' => true,
            'message' => 'Student GPA academic history retrieved successfully.',
            'data' => $history,
        ], 200);
    }

    /**
     * GET /api/student/progression-status
     * GET /api/student/module/progression-status
     */
    public function progressionStatus(Request $request): JsonResponse
    {
        $student = $this->resolveTargetStudent($request);
        if (! $student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $currentPeriod = $this->gpaService->resolveCurrentPeriod($student);
        $semSummary = $currentPeriod['semester_id']
            ? $this->gpaService->calculateSemesterSummary($student, (int) $currentPeriod['semester_id'], $currentPeriod['study_year'])
            : null;

        $histories = StudentProgressionHistory::with(['fromSemester.academicYear', 'toSemester.academicYear'])
            ->where('student_id', $student->id)
            ->orderByDesc('id')
            ->get();

        $retakeOverview = $this->retakeService->getStudentRetakeOverview($student);

        return response()->json([
            'success' => true,
            'message' => 'Student progression status retrieved successfully.',
            'data' => [
                'student_id' => (int) $student->id,
                'current_period' => $currentPeriod,
                'progression_status' => (string) ($student->progression_status ?: 'in_progress'),
                'last_progressed_at' => $student->last_progressed_at?->toIso8601String(),
                'current_semester_completion' => [
                    'is_complete' => $semSummary['is_complete'] ?? false,
                    'enrolled_count' => $semSummary['enrolled_count'] ?? 0,
                    'finalized_count' => $semSummary['finalized_count'] ?? 0,
                    'pending_count' => $semSummary['pending_count'] ?? 0,
                    'passed_count' => $semSummary['passed_count'] ?? 0,
                    'failed_count' => $semSummary['failed_count'] ?? 0,
                ],
                'progression_history' => $histories,
                'retakes' => $retakeOverview,
            ],
        ], 200);
    }

    /**
     * GET /api/student/retakes
     * GET /api/student/module/retakes
     */
    public function retakes(Request $request): JsonResponse
    {
        $student = $this->resolveTargetStudent($request);
        if (! $student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Student retake records retrieved successfully.',
            'data' => $this->retakeService->getStudentRetakeOverview($student),
        ], 200);
    }

    /**
     * POST /api/student/retakes/enroll
     * POST /api/admin/students/{student}/retakes/enroll
     */
    public function enrollRetake(Request $request, ?Student $student = null): JsonResponse
    {
        $targetStudent = $student ?: $this->resolveTargetStudent($request);
        if (! $targetStudent) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $validated = $request->validate([
            'course_offering_id' => 'required|integer|exists:course_offerings,id',
        ]);

        $result = $this->retakeService->enrollInRetakeOffering($targetStudent, (int) $validated['course_offering_id']);

        return response()->json([
            'success' => true,
            'message' => 'Enrolled in retake course offering successfully.',
            'data' => $result,
        ], 201);
    }
}
