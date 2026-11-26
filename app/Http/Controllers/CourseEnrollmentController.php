<?php

namespace App\Http\Controllers;

use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Student;
use App\Rules\ValidYearLevel;
use App\Support\YearLevel;
use Illuminate\Http\Request;

class CourseEnrollmentController extends Controller
{
    public function index(Request $request)
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $query = CourseEnrollment::with(['student.user', 'course', 'courseOffering', 'semester.academicYear']);

        if ($user->role === 'student') {
            if (! $user->student) {
                return response()->json(['message' => 'Student profile not found.'], 404);
            }
            $query->where('student_id', $user->student->id);
        } elseif ($user->role === 'teacher') {
            $teacher = $this->resolveTeacherForUser($user);
            $offeringIds = $teacher ? $this->teacherAuthService()->getAuthorizedOfferingIds($teacher) : [];
            $courseIds = $teacher ? $this->teacherAuthService()->getAuthorizedCourseIds($teacher) : [];
            if (empty($offeringIds) && empty($courseIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where(function ($q) use ($offeringIds, $courseIds) {
                    if (! empty($offeringIds)) {
                        $q->orWhereIn('course_offering_id', $offeringIds);
                    }
                    if (! empty($courseIds)) {
                        $q->orWhereIn('course_id', $courseIds);
                    }
                });
            }
        } elseif ($user->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        if ($request->filled('student_id')) {
            $query->where('student_id', (int) $request->student_id);
        }

        if ($request->filled('course_id')) {
            $query->where('course_id', (int) $request->course_id);
        }

        if ($request->filled('semester_id')) {
            $query->where('semester_id', (int) $request->semester_id);
        }

        if ($request->filled('academic_year_id')) {
            $ayId = (int) $request->academic_year_id;
            $query->whereHas('semester', fn ($q) => $q->where('academic_year_id', $ayId));
        }

        if ($request->filled('year_level') && strtolower((string) $request->year_level) !== 'all') {
            $query->forYearLevel($request->year_level);
        }

        $enrollments = $query->latest()->get();

        return response()->json([
            'message' => 'Course enrollments retrieved successfully.',
            'data' => $enrollments,
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'course_id' => 'required|integer|exists:courses,id',
            'course_offering_id' => 'nullable|integer|exists:course_offerings,id',
            'semester_id' => 'required|integer|exists:semesters,id',
            'year_level' => ['nullable', new ValidYearLevel()],
            'enrollment_date' => 'nullable|date',
            'status' => 'nullable|string|in:enrolled,completed,dropped,failed',
        ]);

        $yearLevel = YearLevel::parse($validated['year_level'] ?? null);
        if ($yearLevel === null && ! empty($validated['course_offering_id'])) {
            $yearLevel = YearLevel::parse(CourseOffering::whereKey($validated['course_offering_id'])->value('study_year'));
        }
        if ($yearLevel === null) {
            $yearLevel = YearLevel::parse(Student::whereKey($validated['student_id'])->value('year_level'));
        }

        $enrollment = CourseEnrollment::create([
            'student_id' => $validated['student_id'],
            'course_id' => $validated['course_id'],
            'course_offering_id' => $validated['course_offering_id'] ?? null,
            'semester_id' => $validated['semester_id'],
            'year_level' => $yearLevel,
            'enrollment_date' => $validated['enrollment_date'] ?? now()->toDateString(),
            'status' => $validated['status'] ?? 'enrolled',
        ]);

        return response()->json([
            'message' => 'Student enrolled successfully.',
            'data' => $enrollment->load(['student', 'course', 'courseOffering', 'semester.academicYear']),
        ], 201);
    }

    public function show(Request $request, CourseEnrollment $courseEnrollment)
    {
        if ($denied = $this->authorizeStudentScopedAccess(
            $request,
            (int) $courseEnrollment->student_id,
            $courseEnrollment->course_id ? (int) $courseEnrollment->course_id : null
        )) {
            return $denied;
        }

        return response()->json([
            'message' => 'Course enrollment retrieved successfully.',
            'data' => $courseEnrollment->load(['student.user', 'course', 'semester']),
        ], 200);
    }

    public function update(Request $request, CourseEnrollment $courseEnrollment)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:enrolled,completed,dropped,failed',
            'final_score' => 'nullable|numeric|min:0|max:100',
            'grade' => 'nullable|string|max:10',
        ]);

        $courseEnrollment->update($validated);

        return response()->json([
            'message' => 'Course enrollment updated successfully.',
            'data' => $courseEnrollment->fresh()->load(['student', 'course', 'semester']),
        ], 200);
    }

    public function destroy(CourseEnrollment $courseEnrollment)
    {
        $courseEnrollment->delete();

        return response()->json([
            'message' => 'Course enrollment deleted successfully.',
        ], 200);
    }
}
