<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendanceDetail;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $query = Attendance::with(['course', 'courseOffering.classRoom', 'teacher', 'semester.academicYear']);

        if ($user->role === 'student') {
            if (! $user->student) {
                return response()->json(['message' => 'Student profile not found.'], 404);
            }
            $studentId = $user->student->id;
            $enrolledOfferingIds = \App\Models\CourseEnrollment::where('student_id', $studentId)
                ->whereNotNull('course_offering_id')->pluck('course_offering_id')->all();
            $enrolledCourseIds = \App\Models\CourseEnrollment::where('student_id', $studentId)
                ->whereNotNull('course_id')->pluck('course_id')->all();
            $query->where(function ($q) use ($enrolledOfferingIds, $enrolledCourseIds) {
                $q->whereRaw('1 = 0');
                if (! empty($enrolledOfferingIds)) {
                    $q->orWhereIn('course_offering_id', $enrolledOfferingIds);
                }
                if (! empty($enrolledCourseIds)) {
                    $q->orWhereIn('course_id', $enrolledCourseIds);
                }
            });
        } elseif ($user->role === 'teacher') {
            $teacher = $this->resolveTeacherForUser($user);
            if (! $teacher) {
                $query->whereRaw('1 = 0');
            } else {
                $offeringIds = $this->teacherAuthService()->getAuthorizedOfferingIds($teacher);
                $courseIds = $this->teacherAuthService()->getAuthorizedCourseIds($teacher);
                $query->where(function ($q) use ($teacher, $offeringIds, $courseIds) {
                    $q->where('teacher_id', $teacher->id);
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

        if ($request->has('course_id')) {
            $query->where('course_id', $request->course_id);
        }

        if ($request->filled('academic_year_id')) {
            $ayId = (int) $request->academic_year_id;
            $query->whereHas('semester', fn ($q) => $q->where('academic_year_id', $ayId));
        }

        if ($request->filled('year_level') && strtolower((string) $request->query('year_level')) !== 'all') {
            $yl = \App\Support\YearLevel::parse($request->query('year_level'));
            if ($yl !== null) {
                $query->where(function ($sub) use ($yl) {
                    $sub->whereHas('courseOffering', fn ($oq) => $oq->where('study_year', $yl))
                        ->orWhereHas('course', fn ($cq) => $cq->forYearLevel($yl));
                });
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($request->has('teacher_id')) {
            $query->where('teacher_id', $request->teacher_id);
        }

        if ($request->has('semester_id')) {
            $query->where('semester_id', $request->semester_id);
        }

        $sessions = $query->latest()->get();

        return response()->json([
            'message' => 'Attendance sessions retrieved successfully.',
            'data' => $sessions,
        ], 200);
    }

    public function store(Request $request)
    {
        $user = $this->resolveApiUser($request);
        if (! $user || ! in_array($user->role, ['admin', 'teacher'], true)) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        $validated = $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
            'teacher_id' => 'required|integer|exists:teachers,id',
            'semester_id' => 'required|integer|exists:semesters,id',
            'attendance_date' => 'required|date',
            'start_time' => 'nullable|string',
            'end_time' => 'nullable|string',
            'topic' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:scheduled,completed,cancelled',
            'notes' => 'nullable|string',
        ]);

        if ($user->role === 'teacher') {
            $teacher = $this->resolveTeacherForUser($user);
            if (! $teacher || (int) $validated['teacher_id'] !== (int) $teacher->id
                || ! $this->teacherAuthService()->isCourseAuthorized($teacher, (int) $validated['course_id'])) {
                return response()->json(['message' => 'Unauthorized access to course attendance.'], 403);
            }
        }

        $attendance = Attendance::create($validated);

        return response()->json([
            'message' => 'Attendance session created successfully.',
            'data' => $attendance->load(['course', 'teacher', 'semester']),
        ], 201);
    }

    public function show(Request $request, Attendance $attendance)
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->role === 'student') {
            if (! $user->student) {
                return response()->json(['message' => 'Student profile not found.'], 404);
            }
            $studentId = $user->student->id;
            $enrolled = \App\Models\CourseEnrollment::where('student_id', $studentId)
                ->where(function ($q) use ($attendance) {
                    if ($attendance->course_offering_id) {
                        $q->where('course_offering_id', $attendance->course_offering_id);
                    }
                    if ($attendance->course_id) {
                        $q->orWhere('course_id', $attendance->course_id);
                    }
                })
                ->exists();
            if (! $enrolled) {
                return response()->json(['message' => 'Unauthorized access.'], 403);
            }
            $attendance->load(['course', 'teacher', 'semester']);
            $attendance->setRelation(
                'details',
                $attendance->details()->where('student_id', $studentId)->with('student.user')->get()
            );

            return response()->json([
                'message' => 'Attendance session retrieved successfully.',
                'data' => $attendance,
            ], 200);
        }

        if ($user->role === 'teacher') {
            $teacher = $this->resolveTeacherForUser($user);
            $authorized = $teacher && (
                (int) $attendance->teacher_id === (int) $teacher->id
                || ($attendance->course_offering_id && $this->teacherAuthService()->isOfferingAuthorized($teacher, (int) $attendance->course_offering_id))
                || ($attendance->course_id && $this->teacherAuthService()->isCourseAuthorized($teacher, (int) $attendance->course_id))
            );
            if (! $authorized) {
                return response()->json(['message' => 'Unauthorized access.'], 403);
            }
        } elseif ($user->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized access.'], 403);
        }

        return response()->json([
            'message' => 'Attendance session retrieved successfully.',
            'data' => $attendance->load(['course', 'teacher', 'semester', 'details.student.user']),
        ], 200);
    }

    public function update(Request $request, Attendance $attendance)
    {
        $user = $this->resolveApiUser($request);
        if (! $user || ! in_array($user->role, ['admin', 'teacher'], true)) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }
        if ($user->role === 'teacher') {
            $teacher = $this->resolveTeacherForUser($user);
            $authorized = $teacher && (
                (int) $attendance->teacher_id === (int) $teacher->id
                || ($attendance->course_offering_id && $this->teacherAuthService()->isOfferingAuthorized($teacher, (int) $attendance->course_offering_id))
                || ($attendance->course_id && $this->teacherAuthService()->isCourseAuthorized($teacher, (int) $attendance->course_id))
            );
            if (! $authorized) {
                return response()->json(['message' => 'Unauthorized action.'], 403);
            }
        }

        $validated = $request->validate([
            'attendance_date' => 'nullable|date',
            'start_time' => 'nullable|string',
            'end_time' => 'nullable|string',
            'topic' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:scheduled,completed,cancelled',
            'notes' => 'nullable|string',
        ]);

        $attendance->update($validated);

        return response()->json([
            'message' => 'Attendance session updated successfully.',
            'data' => $attendance->fresh()->load(['course', 'teacher', 'semester']),
        ], 200);
    }

    public function destroy(Request $request, Attendance $attendance)
    {
        $user = $this->resolveApiUser($request);
        if (! $user || ! in_array($user->role, ['admin', 'teacher'], true)) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }
        if ($user->role === 'teacher') {
            $teacher = $this->resolveTeacherForUser($user);
            $authorized = $teacher && (
                (int) $attendance->teacher_id === (int) $teacher->id
                || ($attendance->course_offering_id && $this->teacherAuthService()->isOfferingAuthorized($teacher, (int) $attendance->course_offering_id))
                || ($attendance->course_id && $this->teacherAuthService()->isCourseAuthorized($teacher, (int) $attendance->course_id))
            );
            if (! $authorized) {
                return response()->json(['message' => 'Unauthorized action.'], 403);
            }
        }

        $attendance->delete();

        return response()->json([
            'message' => 'Attendance session deleted successfully.',
        ], 200);
    }
}
