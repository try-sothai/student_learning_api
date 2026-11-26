<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDetail;
use Illuminate\Http\Request;

class AttendanceDetailController extends Controller
{
    public function index(Request $request)
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $query = AttendanceDetail::with(['attendance.course', 'student.user']);

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
        } elseif ($user->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        if ($request->has('attendance_id')) {
            $query->where('attendance_id', $request->attendance_id);
        }

        if ($request->has('student_id') && $user->role !== 'student') {
            $query->where('student_id', $request->student_id);
        }

        $details = $query->latest()->get();

        return response()->json([
            'message' => 'Attendance details retrieved successfully.',
            'data' => $details,
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'attendance_id' => 'required|integer|exists:attendance,id',
            'student_id' => 'required|integer|exists:students,id',
            'status' => 'required|string|in:present,absent,late,excused',
            'remarks' => 'nullable|string|max:255',
        ]);

        $attendance = \App\Models\Attendance::find($validated['attendance_id']);
        if ($denied = $this->authorizeStudentScopedAccess(
            $request,
            (int) $validated['student_id'],
            $attendance?->course_id ? (int) $attendance->course_id : null,
            false
        )) {
            return $denied;
        }

        $detail = AttendanceDetail::updateOrCreate(
            [
                'attendance_id' => $validated['attendance_id'],
                'student_id' => $validated['student_id'],
            ],
            [
                'status' => $validated['status'],
                'remarks' => $validated['remarks'] ?? null,
            ]
        );

        return response()->json([
            'message' => 'Attendance detail saved successfully.',
            'data' => $detail->load(['attendance', 'student']),
        ], 201);
    }

    public function show(Request $request, AttendanceDetail $attendanceDetail)
    {
        if ($denied = $this->authorizeStudentScopedAccess($request, (int) $attendanceDetail->student_id)) {
            return $denied;
        }

        return response()->json([
            'message' => 'Attendance detail retrieved successfully.',
            'data' => $attendanceDetail->load(['attendance.course', 'student.user']),
        ], 200);
    }

    public function update(Request $request, AttendanceDetail $attendanceDetail)
    {
        if ($denied = $this->authorizeStudentScopedAccess(
            $request,
            (int) $attendanceDetail->student_id,
            $attendanceDetail->attendance?->course_id ? (int) $attendanceDetail->attendance->course_id : null,
            false
        )) {
            return $denied;
        }

        $validated = $request->validate([
            'status' => 'required|string|in:present,absent,late,excused',
            'remarks' => 'nullable|string|max:255',
        ]);

        $attendanceDetail->update($validated);

        return response()->json([
            'message' => 'Attendance detail updated successfully.',
            'data' => $attendanceDetail->fresh()->load(['attendance', 'student']),
        ], 200);
    }

    public function destroy(Request $request, AttendanceDetail $attendanceDetail)
    {
        if ($denied = $this->authorizeStudentScopedAccess(
            $request,
            (int) $attendanceDetail->student_id,
            $attendanceDetail->attendance?->course_id ? (int) $attendanceDetail->attendance->course_id : null,
            false
        )) {
            return $denied;
        }

        $attendanceDetail->delete();

        return response()->json([
            'message' => 'Attendance detail deleted successfully.',
        ], 200);
    }
}
