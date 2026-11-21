<?php

namespace App\Http\Controllers;

use App\Models\Grade;
use App\Services\TeacherAuthorizationService;
use Illuminate\Http\Request;

class GradeController extends Controller
{
    public function index(Request $request)
    {
        $query = Grade::with(['student.user', 'course', 'semester']);

        $user = $request->user();
        if ($user && $user->role === 'student') {
            if (! $user->student) {
                return response()->json(['message' => 'Student profile not found.'], 404);
            }
            $query->where('student_id', $user->student->id);
        } elseif ($user && $user->role === 'teacher') {
            $authService = app(TeacherAuthorizationService::class);
            $teacher = $authService->resolveTeacher($user);
            if (! $teacher) {
                return response()->json(['message' => 'Teacher profile not found.'], 404);
            }
            if ($request->filled('course_id') && ! $authService->isCourseAuthorized($teacher, (int) $request->course_id)) {
                return response()->json(['message' => 'Unauthorized access to course grades.'], 403);
            }
            $courseIds = $authService->getAuthorizedCourseIds($teacher);
            $offeringIds = $authService->getAuthorizedOfferingIds($teacher);
            $query->where(function ($q) use ($courseIds, $offeringIds) {
                $q->whereRaw('1 = 0');
                if (! empty($courseIds)) {
                    $q->orWhereIn('course_id', $courseIds);
                }
                if (! empty($offeringIds)) {
                    $q->orWhereIn('course_offering_id', $offeringIds);
                }
            });
            if ($request->has('student_id')) {
                $query->where('student_id', $request->student_id);
            }
        } elseif ($request->has('student_id')) {
            $query->where('student_id', $request->student_id);
        }

        if ($request->has('course_id')) {
            $query->where('course_id', $request->course_id);
        }

        if ($request->has('semester_id')) {
            $query->where('semester_id', $request->semester_id);
        }

        $grades = $query->latest()->get();

        return response()->json([
            'message' => 'Grades retrieved successfully.',
            'data' => $grades,
        ], 200);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'teacher'])) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        $validated = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'course_id' => 'required|integer|exists:courses,id',
            'course_offering_id' => 'nullable|integer|exists:course_offerings,id',
            'semester_id' => 'required|integer|exists:semesters,id',
            'assignment_score' => 'nullable|numeric|min:0|max:100',
            'quiz_score' => 'nullable|numeric|min:0|max:100',
            'attendance_score' => 'nullable|numeric|min:0|max:100',
            'midterm_score' => 'nullable|numeric|min:0|max:100',
            'final_exam_score' => 'nullable|numeric|min:0|max:100',
            'total_score' => 'nullable|numeric|min:0|max:100',
            'letter_grade' => 'nullable|string|max:5',
            'grade_point' => 'nullable|numeric|min:0|max:4.0',
            'status' => 'nullable|string|in:draft,submitted,finalized',
        ]);

        if ($user->role === 'teacher') {
            $authService = app(TeacherAuthorizationService::class);
            $teacher = $authService->resolveTeacher($user);
            if (! $teacher || ! $authService->isCourseAuthorized($teacher, (int) $validated['course_id'])) {
                return response()->json(['message' => 'Unauthorized to record grades for this course.'], 403);
            }
            if (! empty($validated['course_offering_id']) && ! $authService->isOfferingAuthorized($teacher, (int) $validated['course_offering_id'])) {
                return response()->json(['message' => 'Unauthorized to record grades for this course offering.'], 403);
            }
        }

        $grade = Grade::updateOrCreate(
            [
                'student_id' => $validated['student_id'],
                'course_id' => $validated['course_id'],
                'semester_id' => $validated['semester_id'],
            ],
            $validated
        );

        return response()->json([
            'message' => 'Grade recorded successfully.',
            'data' => $grade->load(['student', 'course', 'semester']),
        ], 201);
    }

    public function show(Grade $grade)
    {
        $user = request()->user();
        if ($user && $user->role === 'student') {
            if (! $user->student) {
                return response()->json(['message' => 'Student profile not found.'], 404);
            }
            if ((int) $grade->student_id !== (int) $user->student->id) {
                return response()->json(['message' => 'Unauthorized access.'], 403);
            }
        } elseif ($user && $user->role === 'teacher') {
            $authService = app(TeacherAuthorizationService::class);
            $teacher = $authService->resolveTeacher($user);
            if (! $teacher || ! $authService->isCourseAuthorized($teacher, (int) $grade->course_id)) {
                return response()->json(['message' => 'Unauthorized access.'], 403);
            }
        }

        return response()->json([
            'message' => 'Grade retrieved successfully.',
            'data' => $grade->load(['student.user', 'course', 'semester']),
        ], 200);
    }

    public function update(Request $request, Grade $grade)
    {
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'teacher'])) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        if ($user->role === 'teacher') {
            $authService = app(TeacherAuthorizationService::class);
            $teacher = $authService->resolveTeacher($user);
            if (! $teacher || ! $authService->isCourseAuthorized($teacher, (int) $grade->course_id)) {
                return response()->json(['message' => 'Unauthorized to modify grades for this course.'], 403);
            }
        }

        $validated = $request->validate([
            'assignment_score' => 'nullable|numeric|min:0|max:100',
            'quiz_score' => 'nullable|numeric|min:0|max:100',
            'attendance_score' => 'nullable|numeric|min:0|max:100',
            'midterm_score' => 'nullable|numeric|min:0|max:100',
            'final_exam_score' => 'nullable|numeric|min:0|max:100',
            'total_score' => 'nullable|numeric|min:0|max:100',
            'letter_grade' => 'nullable|string|max:5',
            'grade_point' => 'nullable|numeric|min:0|max:4.0',
            'status' => 'nullable|string|in:draft,submitted,finalized',
        ]);

        $grade->update($validated);

        return response()->json([
            'message' => 'Grade updated successfully.',
            'data' => $grade->fresh()->load(['student', 'course', 'semester']),
        ], 200);
    }

    public function destroy(Grade $grade)
    {
        $user = request()->user();
        if (! $user || ! in_array($user->role, ['admin', 'teacher'])) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        if ($user->role === 'teacher') {
            $authService = app(TeacherAuthorizationService::class);
            $teacher = $authService->resolveTeacher($user);
            if (! $teacher || ! $authService->isCourseAuthorized($teacher, (int) $grade->course_id)) {
                return response()->json(['message' => 'Unauthorized to delete grades for this course.'], 403);
            }
        }

        $grade->delete();

        return response()->json([
            'message' => 'Grade deleted successfully.',
        ], 200);
    }
}
