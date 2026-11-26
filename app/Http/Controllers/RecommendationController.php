<?php

namespace App\Http\Controllers;

use App\Models\Recommendation;
use Illuminate\Http\Request;

class RecommendationController extends Controller
{
    public function index(Request $request)
    {
        $apiUser = $this->resolveApiUser($request);
        if (! $apiUser) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $query = Recommendation::with(['student.user', 'student.classRoom.academicYear', 'course', 'semester.academicYear']);

        if ($apiUser->role === 'student') {
            if (! $apiUser->student) {
                return response()->json(['message' => 'Student profile not found.'], 404);
            }
            $query->where('student_id', $apiUser->student->id);
        } elseif ($apiUser->role === 'teacher') {
            $teacher = $this->resolveTeacherForUser($apiUser);
            $authorizedIds = $teacher ? $this->teacherAuthService()->getAuthorizedStudentIds($teacher) : [];
            if (empty($authorizedIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('student_id', $authorizedIds);
            }
            if ($request->has('student_id')) {
                $query->where('student_id', (int) $request->student_id);
            }
        } elseif ($apiUser->role === 'admin') {
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

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $recommendations = $query->latest()->get();

        return response()->json([
            'message' => 'Recommendations retrieved successfully.',
            'data' => $recommendations,
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'course_id' => 'nullable|integer|exists:courses,id',
            'semester_id' => 'nullable|integer|exists:semesters,id',
            'type' => 'nullable|string|max:30',
            'title' => 'required|string|max:200',
            'message' => 'required|string',
            'priority' => 'nullable|string|in:low,medium,high',
            'generated_by' => 'nullable|string|max:20',
        ]);

        if ($denied = $this->authorizeStudentScopedAccess(
            $request,
            (int) $validated['student_id'],
            ! empty($validated['course_id']) ? (int) $validated['course_id'] : null,
            false
        )) {
            return $denied;
        }

        $recommendation = Recommendation::create([
            'student_id' => $validated['student_id'],
            'course_id' => $validated['course_id'] ?? null,
            'semester_id' => $validated['semester_id'] ?? null,
            'type' => $validated['type'] ?? 'general',
            'title' => $validated['title'],
            'message' => $validated['message'],
            'priority' => $validated['priority'] ?? 'medium',
            'status' => 'pending',
            'generated_by' => $validated['generated_by'] ?? 'system',
        ]);

        return response()->json([
            'message' => 'Recommendation created successfully.',
            'data' => $recommendation->load(['student', 'course']),
        ], 201);
    }

    public function show(Request $request, Recommendation $recommendation)
    {
        if ($denied = $this->authorizeStudentScopedAccess($request, (int) $recommendation->student_id)) {
            return $denied;
        }

        return response()->json([
            'message' => 'Recommendation retrieved successfully.',
            'data' => $recommendation->load(['student.user', 'course', 'semester']),
        ], 200);
    }

    public function update(Request $request, Recommendation $recommendation)
    {
        if ($denied = $this->authorizeStudentScopedAccess(
            $request,
            (int) $recommendation->student_id,
            $recommendation->course_id ? (int) $recommendation->course_id : null,
            true
        )) {
            return $denied;
        }

        $validated = $request->validate([
            'status' => 'required|string|in:pending,viewed,completed,dismissed',
        ]);

        $recommendation->update($validated);

        return response()->json([
            'message' => 'Recommendation updated successfully.',
            'data' => $recommendation->fresh()->load(['student', 'course']),
        ], 200);
    }

    public function destroy(Request $request, Recommendation $recommendation)
    {
        if ($denied = $this->authorizeStudentScopedAccess(
            $request,
            (int) $recommendation->student_id,
            $recommendation->course_id ? (int) $recommendation->course_id : null,
            false
        )) {
            return $denied;
        }

        $recommendation->delete();

        return response()->json([
            'message' => 'Recommendation deleted successfully.',
        ], 200);
    }
}
