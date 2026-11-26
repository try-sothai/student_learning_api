<?php

namespace App\Http\Controllers;

use App\Models\Prediction;
use Illuminate\Http\Request;

class PredictionController extends Controller
{
    public function index(Request $request)
    {
        $apiUser = $this->resolveApiUser($request);
        if (! $apiUser) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $query = Prediction::with(['student.user', 'student.classRoom.academicYear', 'course', 'semester.academicYear']);

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

        $predictions = $query->latest('prediction_date')->get();

        return response()->json([
            'message' => 'Predictions retrieved successfully.',
            'data' => $predictions,
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'course_id' => 'nullable|integer|exists:courses,id',
            'semester_id' => 'required|integer|exists:semesters,id',
            'prediction_type' => 'required|string|max:30',
            'predicted_score' => 'required|numeric|min:0|max:100',
            'confidence_score' => 'nullable|numeric|min:0|max:1.0',
            'model_version' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        if ($denied = $this->authorizeStudentScopedAccess(
            $request,
            (int) $validated['student_id'],
            ! empty($validated['course_id']) ? (int) $validated['course_id'] : null,
            false
        )) {
            return $denied;
        }

        $prediction = Prediction::create([
            'student_id' => $validated['student_id'],
            'course_id' => $validated['course_id'] ?? null,
            'semester_id' => $validated['semester_id'],
            'prediction_type' => $validated['prediction_type'],
            'predicted_score' => $validated['predicted_score'],
            'confidence_score' => $validated['confidence_score'] ?? 0.85,
            'prediction_date' => now()->toDateString(),
            'model_version' => $validated['model_version'] ?? 'v1.0.0',
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'message' => 'Prediction generated/saved successfully.',
            'data' => $prediction->load(['student', 'course', 'semester']),
        ], 201);
    }

    public function show(Request $request, Prediction $prediction)
    {
        if ($denied = $this->authorizeStudentScopedAccess($request, (int) $prediction->student_id)) {
            return $denied;
        }

        return response()->json([
            'message' => 'Prediction retrieved successfully.',
            'data' => $prediction->load(['student.user', 'course', 'semester']),
        ], 200);
    }

    public function update(Request $request, Prediction $prediction)
    {
        if ($denied = $this->authorizeStudentScopedAccess(
            $request,
            (int) $prediction->student_id,
            $prediction->course_id ? (int) $prediction->course_id : null,
            false
        )) {
            return $denied;
        }

        $validated = $request->validate([
            'predicted_score' => 'required|numeric|min:0|max:100',
            'confidence_score' => 'nullable|numeric|min:0|max:1.0',
            'notes' => 'nullable|string',
        ]);

        $prediction->update($validated);

        return response()->json([
            'message' => 'Prediction updated successfully.',
            'data' => $prediction->fresh()->load(['student', 'course', 'semester']),
        ], 200);
    }

    public function destroy(Request $request, Prediction $prediction)
    {
        if ($denied = $this->authorizeStudentScopedAccess(
            $request,
            (int) $prediction->student_id,
            $prediction->course_id ? (int) $prediction->course_id : null,
            false
        )) {
            return $denied;
        }

        $prediction->delete();

        return response()->json([
            'message' => 'Prediction deleted successfully.',
        ], 200);
    }
}
