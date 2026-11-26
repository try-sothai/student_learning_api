<?php

namespace App\Http\Controllers;

use App\Models\GpaRecord;
use App\Support\YearLevel;
use Illuminate\Http\Request;

class GpaRecordController extends Controller
{
    public function index(Request $request)
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $query = GpaRecord::with(['student.user', 'student.classRoom.academicYear', 'semester.academicYear']);

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

        $records = $query->latest()->get();

        return response()->json([
            'message' => 'GPA records retrieved successfully.',
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
            'semester_id' => 'required|integer|exists:semesters,id',
            'gpa' => 'required|numeric|min:0|max:4.0',
            'total_credits' => 'required|numeric|min:0',
            'earned_credits' => 'required|numeric|min:0',
            'academic_standing' => 'nullable|string|max:50',
        ]);

        if ($denied = $this->authorizeStudentScopedAccess($request, (int) $validated['student_id'], null, false)) {
            return $denied;
        }

        $record = GpaRecord::updateOrCreate(
            [
                'student_id' => $validated['student_id'],
                'semester_id' => $validated['semester_id'],
            ],
            $validated
        );

        return response()->json([
            'message' => 'GPA record saved successfully.',
            'data' => $record->load(['student', 'semester']),
        ], 201);
    }

    public function show(Request $request, GpaRecord $gpaRecord)
    {
        if ($denied = $this->authorizeStudentScopedAccess($request, (int) $gpaRecord->student_id)) {
            return $denied;
        }

        return response()->json([
            'message' => 'GPA record retrieved successfully.',
            'data' => $gpaRecord->load(['student.user', 'semester']),
        ], 200);
    }

    public function update(Request $request, GpaRecord $gpaRecord)
    {
        if ($denied = $this->authorizeStudentScopedAccess($request, (int) $gpaRecord->student_id, null, false)) {
            return $denied;
        }

        $validated = $request->validate([
            'gpa' => 'required|numeric|min:0|max:4.0',
            'total_credits' => 'required|numeric|min:0',
            'earned_credits' => 'required|numeric|min:0',
            'academic_standing' => 'nullable|string|max:50',
        ]);

        $gpaRecord->update($validated);

        return response()->json([
            'message' => 'GPA record updated successfully.',
            'data' => $gpaRecord->fresh()->load(['student', 'semester']),
        ], 200);
    }

    public function destroy(Request $request, GpaRecord $gpaRecord)
    {
        if ($denied = $this->authorizeStudentScopedAccess($request, (int) $gpaRecord->student_id, null, false)) {
            return $denied;
        }

        $gpaRecord->delete();

        return response()->json([
            'message' => 'GPA record deleted successfully.',
        ], 200);
    }
}
