<?php

namespace App\Http\Controllers;

use App\Models\Report;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $query = Report::with(['student.user', 'student.classRoom.academicYear', 'teacher.user', 'semester.academicYear']);

        if ($user->role === 'student') {
            if (! $user->student) {
                return response()->json(['message' => 'Student profile not found.'], 404);
            }
            $query->where('student_id', $user->student->id);
        } elseif ($user->role === 'teacher') {
            $teacher = $this->resolveTeacherForUser($user);
            if (! $teacher) {
                $query->whereRaw('1 = 0');
            } else {
                $authorizedIds = $this->teacherAuthService()->getAuthorizedStudentIds($teacher);
                $query->where(function ($q) use ($teacher, $authorizedIds) {
                    $q->where('teacher_id', $teacher->id);
                    if (! empty($authorizedIds)) {
                        $q->orWhereIn('student_id', $authorizedIds);
                    }
                });
            }
        } elseif ($user->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        if ($request->has('report_type')) {
            $query->where('report_type', $request->report_type);
        }

        if ($request->filled('academic_year_id')) {
            $ayId = (int) $request->academic_year_id;
            $query->whereHas('semester', fn ($q) => $q->where('academic_year_id', $ayId));
        }

        if ($request->filled('year_level') && strtolower((string) $request->query('year_level')) !== 'all') {
            $yl = (string) $request->query('year_level');
            $query->whereHas('student', fn ($sq) => strtolower($yl) === 'missing' ? $sq->missingYearLevel() : $sq->forYearLevel($yl));
        }

        if ($request->has('student_id') && $user->role !== 'student') {
            $query->where('student_id', $request->student_id);
        }

        if ($request->has('teacher_id') && $user->role === 'admin') {
            $query->where('teacher_id', $request->teacher_id);
        }

        $reports = $query->latest()->get();

        return response()->json([
            'message' => 'Reports retrieved successfully.',
            'data' => $reports,
        ], 200);
    }

    private function canAccessReport(Request $request, Report $report, bool $forWrite = false): ?\Illuminate\Http\JsonResponse
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        if ($user->role === 'admin') {
            return null;
        }
        if ($user->role === 'student') {
            if ($forWrite || ! $user->student || (int) $report->student_id !== (int) $user->student->id) {
                return response()->json(['message' => 'Unauthorized access.'], 403);
            }

            return null;
        }
        if ($user->role === 'teacher') {
            $teacher = $this->resolveTeacherForUser($user);
            if (! $teacher) {
                return response()->json(['message' => 'Unauthorized access.'], 403);
            }
            $matchesTeacher = $report->teacher_id && (int) $report->teacher_id === (int) $teacher->id;
            $matchesStudent = $report->student_id && $this->teacherAuthService()->isStudentAuthorized($teacher, (int) $report->student_id);
            if (! $matchesTeacher && ! $matchesStudent) {
                return response()->json(['message' => 'Unauthorized access.'], 403);
            }

            return null;
        }

        return response()->json(['message' => 'Unauthorized action.'], 403);
    }

    public function store(Request $request)
    {
        $user = $this->resolveApiUser($request);
        if (! $user || ! in_array($user->role, ['admin', 'teacher'], true)) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        $validated = $request->validate([
            'report_type' => 'required|string|max:30',
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'student_id' => 'nullable|integer|exists:students,id',
            'teacher_id' => 'nullable|integer|exists:teachers,id',
            'semester_id' => 'nullable|integer|exists:semesters,id',
        ]);

        if ($user->role === 'teacher') {
            $teacher = $this->resolveTeacherForUser($user);
            if (! $teacher) {
                return response()->json(['message' => 'Teacher profile not found.'], 403);
            }
            if (! empty($validated['teacher_id']) && (int) $validated['teacher_id'] !== (int) $teacher->id) {
                return response()->json(['message' => 'Unauthorized action.'], 403);
            }
            if (! empty($validated['student_id']) && ! $this->teacherAuthService()->isStudentAuthorized($teacher, (int) $validated['student_id'])) {
                return response()->json(['message' => 'Unauthorized access to student records.'], 403);
            }
            $validated['teacher_id'] = $validated['teacher_id'] ?? $teacher->id;
        }

        $report = Report::create([
            'report_type' => $validated['report_type'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'student_id' => $validated['student_id'] ?? null,
            'teacher_id' => $validated['teacher_id'] ?? null,
            'semester_id' => $validated['semester_id'] ?? null,
            'generated_by' => $user->id,
            'generated_at' => now(),
            'status' => 'generated',
        ]);

        return response()->json([
            'message' => 'Report generated successfully.',
            'data' => $report->load(['student', 'teacher', 'semester']),
        ], 201);
    }

    public function show(Request $request, Report $report)
    {
        if ($denied = $this->canAccessReport($request, $report, false)) {
            return $denied;
        }

        return response()->json([
            'message' => 'Report retrieved successfully.',
            'data' => $report->load(['student.user', 'teacher.user', 'semester']),
        ], 200);
    }

    public function update(Request $request, Report $report)
    {
        if ($denied = $this->canAccessReport($request, $report, true)) {
            return $denied;
        }

        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:pending,generated,failed',
        ]);

        $report->update($validated);

        return response()->json([
            'message' => 'Report updated successfully.',
            'data' => $report->fresh(),
        ], 200);
    }

    public function destroy(Request $request, Report $report)
    {
        if ($denied = $this->canAccessReport($request, $report, true)) {
            return $denied;
        }

        $report->delete();

        return response()->json([
            'message' => 'Report deleted successfully.',
        ], 200);
    }
}
