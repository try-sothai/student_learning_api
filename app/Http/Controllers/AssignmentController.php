<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use Illuminate\Http\Request;

class AssignmentController extends Controller
{
    use \App\Http\Controllers\Concerns\ScopesAssessmentVisibility;

    public function index(Request $request)
    {
        $query = $this->scopeVisible(Assignment::with(['course', 'teacher', 'semester']), $request, 'assignment');

        if ($request->has('course_id')) {
            $query->where('course_id', $request->course_id);
        }

        if ($request->has('teacher_id')) {
            $query->where('teacher_id', $request->teacher_id);
        }

        if ($request->has('semester_id')) {
            $query->where('semester_id', $request->semester_id);
        }

        $assignments = $query->latest()->get();

        return response()->json([
            'message' => 'Assignments retrieved successfully.',
            'data' => $assignments,
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
            'teacher_id' => 'required|integer|exists:teachers,id',
            'semester_id' => 'required|integer|exists:semesters,id',
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'total_score' => 'required|numeric|min:0',
            'due_date' => 'required|date',
            'status' => 'nullable|string|in:draft,published,closed,archived',
        ]);

        $assignment = Assignment::create([
            'course_id' => $validated['course_id'],
            'teacher_id' => $validated['teacher_id'],
            'semester_id' => $validated['semester_id'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'total_score' => $validated['total_score'],
            'due_date' => $validated['due_date'],
            'status' => $validated['status'] ?? 'published',
        ]);

        return response()->json([
            'message' => 'Assignment created successfully.',
            'data' => $assignment->load(['course', 'teacher', 'semester']),
        ], 201);
    }

    public function show(Request $request, Assignment $assignment)
    {
        $this->authorizeVisible($assignment, $request, 'assignment');
        if ($request->user()->role === 'student') {
            $assignment->load([
                'course',
                'teacher',
                'semester',
                'submissions' => fn ($q) => $q->where('student_id', $request->user()->student->id),
            ]);
            $fileService = app(\App\Services\SubmissionFileService::class);
            $assignment->submissions->each(fn ($sub) => $fileService->decorateStudentSubmissionModel($sub));
        } else {
            $assignment->load(['course', 'teacher', 'semester', 'submissions.student.user']);
        }

        return response()->json([
            'message' => 'Assignment retrieved successfully.',
            'data' => $assignment,
        ], 200);
    }

    public function update(Request $request, Assignment $assignment)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'total_score' => 'required|numeric|min:0',
            'due_date' => 'required|date',
            'status' => 'nullable|string|in:draft,published,closed,archived',
        ]);

        $assignment->update($validated);

        return response()->json([
            'message' => 'Assignment updated successfully.',
            'data' => $assignment->fresh()->load(['course', 'teacher', 'semester']),
        ], 200);
    }

    public function destroy(Assignment $assignment)
    {
        $assignment->delete();

        return response()->json([
            'message' => 'Assignment deleted successfully.',
        ], 200);
    }
}
