<?php

namespace App\Http\Controllers;

use App\Models\PerformanceRecord;
use Illuminate\Http\Request;

class PerformanceRecordController extends Controller
{
    public function index(Request $request)
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $query = PerformanceRecord::with(['student.user', 'student.classRoom.academicYear', 'course', 'semester.academicYear']);

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

        if ($request->has('course_id')) {
            $query->where('course_id', $request->course_id);
        }

        if ($request->has('semester_id')) {
            $query->where('semester_id', $request->semester_id);
        }

        $records = $query->latest()->get();

        return response()->json([
            'message' => 'Performance records retrieved successfully.',
            'data' => $records,
        ], 200);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user || !in_array($user->role, ['admin', 'teacher'])) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        $validated = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'course_id' => 'required|integer|exists:courses,id',
            'semester_id' => 'required|integer|exists:semesters,id',
            'period_type' => 'nullable|string|in:weekly,monthly,semester,yearly',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'attendance_percentage' => 'nullable|numeric|min:0|max:100',
            'assignment_percentage' => 'nullable|numeric|min:0|max:100',
            'quiz_percentage' => 'nullable|numeric|min:0|max:100',
            'exam_percentage' => 'nullable|numeric|min:0|max:100',
            'overall_score' => 'nullable|numeric|min:0|max:100',
            'performance_level' => 'nullable|string|max:50',
        ]);

        if ($denied = $this->authorizeStudentScopedAccess($request, (int) $validated['student_id'], (int) $validated['course_id'], false)) {
            return $denied;
        }

        $record = PerformanceRecord::create([
            'student_id' => $validated['student_id'],
            'course_id' => $validated['course_id'],
            'semester_id' => $validated['semester_id'],
            'period_type' => $validated['period_type'] ?? 'semester',
            'period_start' => $validated['period_start'],
            'period_end' => $validated['period_end'],
            'attendance_percentage' => $validated['attendance_percentage'] ?? null,
            'assignment_percentage' => $validated['assignment_percentage'] ?? null,
            'quiz_percentage' => $validated['quiz_percentage'] ?? null,
            'exam_percentage' => $validated['exam_percentage'] ?? null,
            'overall_score' => $validated['overall_score'] ?? null,
            'performance_level' => $validated['performance_level'] ?? null,
        ]);

        return response()->json([
            'message' => 'Performance record created successfully.',
            'data' => $record->load(['student', 'course', 'semester']),
        ], 201);
    }

    public function show(Request $request, PerformanceRecord $performanceRecord)
    {
        if ($denied = $this->authorizeStudentScopedAccess($request, (int) $performanceRecord->student_id)) {
            return $denied;
        }

        return response()->json([
            'message' => 'Performance record retrieved successfully.',
            'data' => $performanceRecord->load(['student.user', 'course', 'semester']),
        ], 200);
    }

    public function update(Request $request, PerformanceRecord $performanceRecord)
    {
        if ($denied = $this->authorizeStudentScopedAccess(
            $request,
            (int) $performanceRecord->student_id,
            $performanceRecord->course_id ? (int) $performanceRecord->course_id : null,
            false
        )) {
            return $denied;
        }

        $validated = $request->validate([
            'attendance_percentage' => 'nullable|numeric|min:0|max:100',
            'assignment_percentage' => 'nullable|numeric|min:0|max:100',
            'quiz_percentage' => 'nullable|numeric|min:0|max:100',
            'exam_percentage' => 'nullable|numeric|min:0|max:100',
            'overall_score' => 'nullable|numeric|min:0|max:100',
            'performance_level' => 'nullable|string|max:50',
        ]);

        $performanceRecord->update($validated);

        return response()->json([
            'message' => 'Performance record updated successfully.',
            'data' => $performanceRecord->fresh()->load(['student', 'course', 'semester']),
        ], 200);
    }

    public function destroy(Request $request, PerformanceRecord $performanceRecord)
    {
        if ($denied = $this->authorizeStudentScopedAccess(
            $request,
            (int) $performanceRecord->student_id,
            $performanceRecord->course_id ? (int) $performanceRecord->course_id : null,
            false
        )) {
            return $denied;
        }

        $performanceRecord->delete();

        return response()->json([
            'message' => 'Performance record deleted successfully.',
        ], 200);
    }
}
