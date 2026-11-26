<?php

namespace App\Http\Controllers;

use App\Models\AcademicCalendarAudit;
use App\Models\MajorAcademicPeriod;
use App\Models\Semester;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AcademicCalendarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MajorAcademicPeriod::with(['program.department:id,code,name', 'academicYear', 'semester']);

        $progId = $request->input('program_id', $request->input('major_id'));
        if ($progId !== null && $progId !== '') {
            $query->where('program_id', (int) $progId);
        }

        if ($request->filled('academic_year_id')) {
            $query->where('academic_year_id', (int) $request->input('academic_year_id'));
        }

        if ($request->filled('study_year') || $request->filled('year_level')) {
            $yl = \App\Support\YearLevel::parse($request->input('study_year', $request->input('year_level')));
            if ($yl !== null) {
                $query->where('study_year', $yl);
            }
        }

        if ($request->filled('status')) {
            $query->where('status', strtolower(trim((string) $request->input('status'))));
        }

        $periods = $query->orderBy('program_id')
            ->orderBy('study_year')
            ->orderBy('sequence_order')
            ->orderBy('semester_number')
            ->orderBy('start_date')
            ->get()
            ->map(fn (MajorAcademicPeriod $p) => $this->formatPeriod($p))
            ->values();

        return response()->json([
            'message' => 'Academic calendar periods retrieved successfully.',
            'data' => $periods,
        ], 200);
    }

    public function show(MajorAcademicPeriod $period): JsonResponse
    {
        $period->load(['program.department:id,code,name', 'academicYear', 'semester', 'audits.changedByUser:id,name,email']);

        return response()->json([
            'message' => 'Academic calendar period retrieved successfully.',
            'data' => $this->formatPeriod($period, true),
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->filled('program_id') && $request->filled('major_id')) {
            $request->merge(['program_id' => $request->input('major_id')]);
        }
        if (! $request->filled('study_year') && $request->filled('year_level')) {
            $request->merge(['study_year' => \App\Support\YearLevel::parse($request->input('year_level'))]);
        }

        $validated = $request->validate([
            'program_id' => 'required|integer|exists:programs,id',
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'semester_id' => 'nullable|integer|exists:semesters,id',
            'study_year' => 'required|integer|min:1|max:6',
            'semester_number' => 'required|integer|min:1|max:6',
            'semester_name' => 'nullable|string|max:100',
            'sequence_order' => 'nullable|integer|min:1|max:50',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'status' => 'nullable|string|in:upcoming,active,completed,inactive',
            'notes' => 'nullable|string|max:1000',
            'reason' => 'nullable|string|max:500',
        ]);

        $semId = $validated['semester_id'] ?? null;
        if (! $semId) {
            $semId = Semester::where('academic_year_id', (int) $validated['academic_year_id'])
                ->where('semester_number', (int) $validated['semester_number'])
                ->value('id');
        }

        $period = DB::transaction(function () use ($validated, $semId, $request) {
            $existing = MajorAcademicPeriod::where('program_id', (int) $validated['program_id'])
                ->where('academic_year_id', (int) $validated['academic_year_id'])
                ->where('study_year', (int) $validated['study_year'])
                ->where('semester_number', (int) $validated['semester_number'])
                ->first();

            $prevStart = $existing?->start_date?->toDateString();
            $prevEnd = $existing?->end_date?->toDateString();
            $prevStatus = $existing?->status;

            $period = MajorAcademicPeriod::updateOrCreate(
                [
                    'program_id' => (int) $validated['program_id'],
                    'academic_year_id' => (int) $validated['academic_year_id'],
                    'study_year' => (int) $validated['study_year'],
                    'semester_number' => (int) $validated['semester_number'],
                ],
                [
                    'semester_id' => $semId,
                    'semester_name' => $validated['semester_name'] ?? ('Semester ' . $validated['semester_number']),
                    'sequence_order' => (int) ($validated['sequence_order'] ?? ((($validated['study_year'] - 1) * 10) + $validated['semester_number'])),
                    'start_date' => $validated['start_date'],
                    'end_date' => $validated['end_date'],
                    'status' => strtolower($validated['status'] ?? 'upcoming'),
                    'notes' => $validated['notes'] ?? null,
                ]
            );

            AcademicCalendarAudit::create([
                'major_academic_period_id' => $period->id,
                'semester_id' => $period->semester_id,
                'program_id' => $period->program_id,
                'previous_start_date' => $prevStart,
                'new_start_date' => $period->start_date?->toDateString(),
                'previous_end_date' => $prevEnd,
                'new_end_date' => $period->end_date?->toDateString(),
                'previous_status' => $prevStatus,
                'new_status' => $period->status,
                'changed_by' => $request->user()?->id,
                'reason' => $validated['reason'] ?? ($existing ? 'Calendar period updated' : 'Calendar period created'),
            ]);

            return $period;
        });

        $period->load(['program.department:id,code,name', 'academicYear', 'semester']);

        return response()->json([
            'message' => 'Academic calendar period configured successfully.',
            'data' => $this->formatPeriod($period),
        ], 201);
    }

    public function update(Request $request, MajorAcademicPeriod $period): JsonResponse
    {
        $validated = $request->validate([
            'semester_id' => 'nullable|integer|exists:semesters,id',
            'semester_name' => 'sometimes|required|string|max:100',
            'sequence_order' => 'sometimes|integer|min:1|max:50',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'sometimes|required|date',
            'status' => 'sometimes|required|string|in:upcoming,active,completed,inactive',
            'notes' => 'nullable|string|max:1000',
            'reason' => 'nullable|string|max:500',
        ]);

        $newStart = $validated['start_date'] ?? $period->start_date?->toDateString();
        $newEnd = $validated['end_date'] ?? $period->end_date?->toDateString();

        if ($newStart && $newEnd && Carbon::parse($newEnd)->lte(Carbon::parse($newStart))) {
            return response()->json([
                'message' => 'The end date must be a date after start date.',
                'errors' => ['end_date' => ['The end date must be a date after start date.']],
            ], 422);
        }

        DB::transaction(function () use ($period, $validated, $request, $newStart, $newEnd) {
            $prevStart = $period->start_date?->toDateString();
            $prevEnd = $period->end_date?->toDateString();
            $prevStatus = $period->status;

            $period->update(collect($validated)->except('reason')->all());

            if ($prevStart !== $newStart || $prevEnd !== $newEnd || $prevStatus !== $period->status) {
                AcademicCalendarAudit::create([
                    'major_academic_period_id' => $period->id,
                    'semester_id' => $period->semester_id,
                    'program_id' => $period->program_id,
                    'previous_start_date' => $prevStart,
                    'new_start_date' => $period->start_date?->toDateString(),
                    'previous_end_date' => $prevEnd,
                    'new_end_date' => $period->end_date?->toDateString(),
                    'previous_status' => $prevStatus,
                    'new_status' => $period->status,
                    'changed_by' => $request->user()?->id,
                    'reason' => $validated['reason'] ?? 'Academic calendar dates adjusted by administrator.',
                ]);
            }
        });

        $period->load(['program.department:id,code,name', 'academicYear', 'semester', 'audits.changedByUser:id,name,email']);

        return response()->json([
            'message' => 'Academic calendar period updated successfully.',
            'data' => $this->formatPeriod($period, true),
        ], 200);
    }

    public function destroy(MajorAcademicPeriod $period): JsonResponse
    {
        $period->delete();

        return response()->json([
            'message' => 'Academic calendar period deleted successfully.',
        ], 200);
    }

    public function audits(Request $request): JsonResponse
    {
        $query = AcademicCalendarAudit::with(['period', 'program:id,code,name', 'semester:id,name', 'changedByUser:id,name,email']);

        if ($request->filled('program_id') || $request->filled('major_id')) {
            $query->where('program_id', (int) ($request->input('program_id') ?? $request->input('major_id')));
        }
        if ($request->filled('major_academic_period_id')) {
            $query->where('major_academic_period_id', (int) $request->input('major_academic_period_id'));
        }
        if ($request->filled('semester_id')) {
            $query->where('semester_id', (int) $request->input('semester_id'));
        }

        $audits = $query->latest('id')->limit(200)->get();

        return response()->json([
            'message' => 'Academic calendar change history retrieved successfully.',
            'data' => $audits,
        ], 200);
    }

    private function formatPeriod(MajorAcademicPeriod $p, bool $includeAudits = false): array
    {
        $start = $p->start_date ? Carbon::parse($p->start_date) : null;
        $end = $p->end_date ? Carbon::parse($p->end_date) : null;
        $durationDays = ($start && $end) ? (int) $start->diffInDays($end) : null;
        $durationMonths = ($start && $end) ? round($start->floatDiffInMonths($end), 1) : null;

        $out = [
            'id' => (int) $p->id,
            'program_id' => (int) $p->program_id,
            'major_id' => (int) $p->program_id,
            'major_code' => $p->program?->code,
            'major_name' => $p->program?->name,
            'faculty_id' => $p->program?->department_id,
            'faculty_name' => $p->program?->department?->name,
            'academic_year_id' => (int) $p->academic_year_id,
            'academic_year_name' => $p->academicYear?->name,
            'semester_id' => $p->semester_id ? (int) $p->semester_id : null,
            'study_year' => (int) $p->study_year,
            'year_level_code' => \App\Support\YearLevel::code($p->study_year),
            'year_level_name' => \App\Support\YearLevel::name($p->study_year),
            'semester_number' => (int) $p->semester_number,
            'semester_name' => (string) $p->semester_name,
            'sequence_order' => (int) $p->sequence_order,
            'start_date' => $start?->toDateString(),
            'end_date' => $end?->toDateString(),
            'duration_days' => $durationDays,
            'duration_months' => $durationMonths,
            'status' => (string) $p->status,
            'notes' => $p->notes,
        ];

        if ($includeAudits && $p->relationLoaded('audits')) {
            $out['audits'] = $p->audits;
        }

        return $out;
    }
}
