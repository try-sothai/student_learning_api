<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Incident::with(['student.user', 'student.classRoom', 'department', 'reporter', 'assignee']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($priority = $request->query('priority')) {
            $query->where('priority', $priority);
        }
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        if ($departmentId = $request->query('department_id')) {
            $query->where('department_id', $departmentId);
        }
        if ($assignedTo = $request->query('assigned_to')) {
            $query->where('assigned_to', $assignedTo);
        }
        if ($dateFrom = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('incident_code', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%")
                  ->orWhere('category', 'like', "%{$search}%")
                  ->orWhereHas('student', function ($sq) use ($search) {
                      $sq->where('student_code', 'like', "%{$search}%")
                         ->orWhere('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%")
                         ->orWhere('english_name', 'like', "%{$search}%");
                  });
            });
        }

        $incidents = $query->latest()->get()->map(fn (Incident $inc) => $this->present($inc));

        $summary = [
            'open' => Incident::where('status', 'Open')->count(),
            'high_priority' => Incident::where('priority', 'High Priority')
                ->whereNotIn('status', ['Resolved', 'Closed'])
                ->count(),
            'in_progress' => Incident::whereIn('status', ['In Progress', 'Under Review'])->count(),
            'resolved' => Incident::whereIn('status', ['Resolved', 'Closed'])->count(),
        ];

        return response()->json([
            'message' => 'Incidents retrieved successfully.',
            'data' => $incidents,
            'summary' => $summary,
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'category' => 'required|string|max:60',
            'priority' => 'nullable|string|in:Low Priority,Medium Priority,High Priority',
            'severity' => 'nullable|string|in:low,medium,high,critical',
            'status' => 'nullable|string|in:Open,In Progress,Under Review,Resolved,Closed',
            'student_id' => 'nullable|integer|exists:students,id',
            'department_id' => 'nullable|integer|exists:departments,id',
            'assigned_to' => 'nullable|integer|exists:users,id',
        ]);

        $year = now()->format('Y');
        $nextNum = (Incident::withTrashed()->max('id') ?? 0) + 1;
        $code = sprintf('INC-%s-%03d', $year, $nextNum);

        $incident = Incident::create([
            'incident_code' => $code,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'category' => $validated['category'],
            'priority' => $validated['priority'] ?? 'Medium Priority',
            'severity' => $validated['severity'] ?? 'medium',
            'status' => $validated['status'] ?? 'Open',
            'student_id' => $validated['student_id'] ?? null,
            'department_id' => $validated['department_id'] ?? null,
            'reporter_id' => $request->user()?->id,
            'assigned_to' => $validated['assigned_to'] ?? null,
        ]);

        return response()->json([
            'message' => 'Incident created successfully.',
            'data' => $this->present($incident->load(['student.user', 'student.classRoom', 'department', 'reporter', 'assignee'])),
        ], 201);
    }

    public function show(Incident $incident): JsonResponse
    {
        return response()->json([
            'message' => 'Incident retrieved successfully.',
            'data' => $this->present($incident->load(['student.user', 'student.classRoom', 'department', 'reporter', 'assignee'])),
        ], 200);
    }

    public function update(Request $request, Incident $incident): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|string|max:200',
            'description' => 'nullable|string',
            'category' => 'sometimes|string|max:60',
            'priority' => 'sometimes|string|in:Low Priority,Medium Priority,High Priority',
            'severity' => 'sometimes|string|in:low,medium,high,critical',
            'status' => 'sometimes|string|in:Open,In Progress,Under Review,Resolved,Closed',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'resolution_notes' => 'nullable|string',
        ]);

        if (isset($validated['status']) && in_array($validated['status'], ['Resolved', 'Closed'], true) && ! $incident->resolved_at) {
            $validated['resolved_at'] = now();
        }

        $incident->update($validated);

        return response()->json([
            'message' => 'Incident updated successfully.',
            'data' => $this->present($incident->fresh()->load(['student.user', 'student.classRoom', 'department', 'reporter', 'assignee'])),
        ], 200);
    }

    public function destroy(Incident $incident): JsonResponse
    {
        $incident->delete();

        return response()->json([
            'message' => 'Incident deleted successfully.',
        ], 200);
    }

    private function present(Incident $inc): array
    {
        $studentName = $inc->student
            ? ($inc->student->english_name ?: trim($inc->student->first_name . ' ' . $inc->student->last_name))
            : null;

        return [
            'id' => $inc->id,
            'incident_code' => $inc->incident_code,
            'title' => $inc->title,
            'description' => $inc->description,
            'category' => $inc->category,
            'priority' => $inc->priority,
            'severity' => $inc->severity,
            'status' => $inc->status,
            'student_id' => $inc->student_id,
            'student_user_id' => $inc->student?->user_id,
            'student_code' => $inc->student?->student_code,
            'student_name' => $studentName ?? 'Unassigned Student',
            'student_email' => $inc->student?->user?->email,
            'class_name' => $inc->student?->classRoom?->name ?? 'N/A',
            'department_id' => $inc->department_id,
            'department_name' => $inc->department?->name,
            'reporter_name' => $inc->reporter?->name,
            'assigned_to' => $inc->assigned_to,
            'assigned_staff' => $inc->assignee?->name ?? 'Unassigned',
            'resolution_notes' => $inc->resolution_notes,
            'logged_date' => $inc->created_at?->format('M d, Y'),
            'resolved_at' => $inc->resolved_at?->toIso8601String(),
        ];
    }
}
