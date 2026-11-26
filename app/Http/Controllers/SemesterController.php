<?php

namespace App\Http\Controllers;

use App\Models\Semester;
use Illuminate\Http\Request;

class SemesterController extends Controller
{
    public function index()
    {
        $semesters = Semester::with('academicYear')->latest()->get();

        return response()->json([
            'message' => 'Semesters retrieved successfully.',
            'data' => $semesters,
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'name' => 'required|string|max:100',
            'semester_number' => 'required|integer|min:1|max:10',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'status' => 'nullable|string|in:active,upcoming,completed,inactive',
        ]);

        $semester = Semester::create([
            'academic_year_id' => $validated['academic_year_id'],
            'name' => $validated['name'],
            'semester_number' => $validated['semester_number'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'status' => $validated['status'] ?? 'upcoming',
        ]);

        return response()->json([
            'message' => 'Semester created successfully.',
            'data' => $semester->load('academicYear'),
        ], 201);
    }

    public function show(Semester $semester)
    {
        return response()->json([
            'message' => 'Semester retrieved successfully.',
            'data' => $semester->load('academicYear'),
        ], 200);
    }

    public function update(Request $request, Semester $semester)
    {
        $validated = $request->validate([
            'academic_year_id' => 'sometimes|required|integer|exists:academic_years,id',
            'name' => 'sometimes|required|string|max:100',
            'semester_number' => 'sometimes|required|integer|min:1|max:10',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'sometimes|required|date|after:start_date',
            'status' => 'nullable|string|in:active,upcoming,completed,inactive',
            'reason' => 'nullable|string|max:500',
        ]);

        $prevStart = $semester->start_date?->toDateString();
        $prevEnd = $semester->end_date?->toDateString();
        $prevStatus = $semester->status;

        $semester->update(collect($validated)->except('reason')->all());

        if (\Illuminate\Support\Facades\Schema::hasTable('academic_calendar_audits')) {
            $newStart = $semester->start_date?->toDateString();
            $newEnd = $semester->end_date?->toDateString();
            if ($prevStart !== $newStart || $prevEnd !== $newEnd || $prevStatus !== $semester->status) {
                \App\Models\AcademicCalendarAudit::create([
                    'semester_id' => $semester->id,
                    'previous_start_date' => $prevStart,
                    'new_start_date' => $newStart,
                    'previous_end_date' => $prevEnd,
                    'new_end_date' => $newEnd,
                    'previous_status' => $prevStatus,
                    'new_status' => $semester->status,
                    'changed_by' => $request->user()?->id,
                    'reason' => $validated['reason'] ?? 'Semester calendar dates updated.',
                ]);
            }
        }

        return response()->json([
            'message' => 'Semester updated successfully.',
            'data' => $semester->fresh()->load('academicYear'),
        ], 200);
    }

    public function destroy(Semester $semester)
    {
        $semester->delete();

        return response()->json([
            'message' => 'Semester deleted successfully.',
        ], 200);
    }
}
