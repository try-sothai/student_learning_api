<?php

namespace App\Http\Controllers;

use App\Models\LearningAnalytic;
use App\Services\StudentAnalyticsCalculator;
use Illuminate\Http\Request;
use Carbon\Carbon;

class LearningAnalyticController extends Controller
{
    public function myAnalytics(Request $request, StudentAnalyticsCalculator $calculator)
    {
        $user = $request->user();
        $student = $user?->student;

        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $period = $request->query('period_type', 'weekly');
        $semesterId = $request->filled('semester_id') ? (int) $request->query('semester_id') : null;

        $analytics = $calculator->buildTimeSeries($student, $period, $semesterId);

        if (empty($analytics)) {
            $storedQuery = LearningAnalytic::where('student_id', $student->id);
            if ($semesterId) {
                $storedQuery->where('semester_id', $semesterId);
            }
            $stored = $storedQuery->orderBy('period_start')->get();
            foreach ($stored as $row) {
                $analytics[] = [
                    'id' => $row->id,
                    'student_id' => $row->student_id,
                    'period_type' => $row->period_type ?? $period,
                    'period_start' => $row->period_start ? Carbon::parse($row->period_start)->format('Y-m-d') : null,
                    'attendance_rate' => $row->attendance_rate !== null ? (float) $row->attendance_rate : null,
                    'assignment_average' => $row->assignment_average !== null ? (float) $row->assignment_average : null,
                    'quiz_average' => $row->quiz_average !== null ? (float) $row->quiz_average : null,
                    'exam_average' => $row->exam_average !== null ? (float) $row->exam_average : null,
                    'overall_performance' => $row->overall_performance !== null ? (float) $row->overall_performance : null,
                ];
            }
        }

        return response()->json([
            'message' => 'Student analytics retrieved successfully.',
            'data' => $analytics,
        ], 200);
    }

    public function index(Request $request)
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $query = LearningAnalytic::with(['student.user', 'student.classRoom.academicYear', 'semester.academicYear']);

        if ($user->role === 'student') {
            if (! $user->student) {
                return response()->json(['message' => 'Student profile not found.'], 404);
            }
            $query->where('student_id', $user->student->id);
        } elseif ($user->role === 'teacher') {
            $teacher = $this->resolveTeacherForUser($user);
            $authorizedIds = $teacher ? $this->teacherAuthService()->getAuthorizedStudentIds($teacher) : [];
            if (empty($authorizedIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('student_id', $authorizedIds);
            }
            if ($request->has('student_id')) {
                $query->where('student_id', (int) $request->student_id);
            }
        } elseif ($user->role === 'admin') {
            if ($request->has('student_id')) {
                $query->where('student_id', $request->student_id);
            }
        } else {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        if ($request->filled('academic_year_id')) {
            $ayId = (int) $request->academic_year_id;
            $query->whereHas('semester', fn ($q) => $q->where('academic_year_id', $ayId));
        }

        if ($request->filled('year_level') && strtolower((string) $request->query('year_level')) !== 'all') {
            $yl = (string) $request->query('year_level');
            $query->whereHas('student', fn ($sq) => strtolower($yl) === 'missing' ? $sq->missingYearLevel() : $sq->forYearLevel($yl));
        }

        if ($request->has('semester_id')) {
            $query->where('semester_id', $request->semester_id);
        }

        $analytics = $query->latest('calculated_at')->get();

        return response()->json([
            'message' => 'Learning analytics retrieved successfully.',
            'data' => $analytics,
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'semester_id' => 'nullable|integer|exists:semesters,id',
            'period_type' => 'nullable|string|in:weekly,monthly,semester,yearly',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'attendance_rate' => 'nullable|numeric|min:0|max:100',
            'assignment_average' => 'nullable|numeric|min:0|max:100',
            'quiz_average' => 'nullable|numeric|min:0|max:100',
            'exam_average' => 'nullable|numeric|min:0|max:100',
            'overall_performance' => 'nullable|numeric|min:0|max:100',
            'performance_change' => 'nullable|numeric',
            'learning_trend' => 'nullable|string|in:improving,stable,declining',
            'strengths' => 'nullable|array',
            'weaknesses' => 'nullable|array',
            'analytics_summary' => 'nullable|string',
        ]);

        if ($denied = $this->authorizeStudentScopedAccess($request, (int) $validated['student_id'], null, false)) {
            return $denied;
        }

        $analytic = LearningAnalytic::create([
            'student_id' => $validated['student_id'],
            'semester_id' => $validated['semester_id'] ?? null,
            'period_type' => $validated['period_type'] ?? 'semester',
            'period_start' => $validated['period_start'],
            'period_end' => $validated['period_end'],
            'attendance_rate' => $validated['attendance_rate'] ?? null,
            'assignment_average' => $validated['assignment_average'] ?? null,
            'quiz_average' => $validated['quiz_average'] ?? null,
            'exam_average' => $validated['exam_average'] ?? null,
            'overall_performance' => $validated['overall_performance'] ?? null,
            'performance_change' => $validated['performance_change'] ?? 0.0,
            'learning_trend' => $validated['learning_trend'] ?? 'stable',
            'strengths' => isset($validated['strengths']) ? json_encode($validated['strengths']) : null,
            'weaknesses' => isset($validated['weaknesses']) ? json_encode($validated['weaknesses']) : null,
            'analytics_summary' => $validated['analytics_summary'] ?? null,
            'calculated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Learning analytic record created successfully.',
            'data' => $analytic->load(['student', 'semester']),
        ], 201);
    }

    public function show(Request $request, LearningAnalytic $learningAnalytic)
    {
        if ($denied = $this->authorizeStudentScopedAccess($request, (int) $learningAnalytic->student_id)) {
            return $denied;
        }

        return response()->json([
            'message' => 'Learning analytic retrieved successfully.',
            'data' => $learningAnalytic->load(['student.user', 'semester']),
        ], 200);
    }

    public function update(Request $request, LearningAnalytic $learningAnalytic)
    {
        if ($denied = $this->authorizeStudentScopedAccess($request, (int) $learningAnalytic->student_id, null, false)) {
            return $denied;
        }

        $validated = $request->validate([
            'attendance_rate' => 'nullable|numeric|min:0|max:100',
            'assignment_average' => 'nullable|numeric|min:0|max:100',
            'quiz_average' => 'nullable|numeric|min:0|max:100',
            'exam_average' => 'nullable|numeric|min:0|max:100',
            'overall_performance' => 'nullable|numeric|min:0|max:100',
            'learning_trend' => 'nullable|string|in:improving,stable,declining',
            'analytics_summary' => 'nullable|string',
        ]);

        $learningAnalytic->update($validated);

        return response()->json([
            'message' => 'Learning analytic updated successfully.',
            'data' => $learningAnalytic->fresh()->load(['student', 'semester']),
        ], 200);
    }

    public function destroy(Request $request, LearningAnalytic $learningAnalytic)
    {
        if ($denied = $this->authorizeStudentScopedAccess($request, (int) $learningAnalytic->student_id, null, false)) {
            return $denied;
        }

        $learningAnalytic->delete();

        return response()->json([
            'message' => 'Learning analytic deleted successfully.',
        ], 200);
    }
}
