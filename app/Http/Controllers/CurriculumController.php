<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CurriculumItem;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CurriculumController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CurriculumItem::with(['program.department:id,code,name', 'course.category', 'category']);

        $progId = $request->input('program_id', $request->input('major_id'));
        if ($progId !== null && $progId !== '') {
            $query->where('program_id', (int) $progId);
        }

        $studyYear = $request->input('study_year', $request->input('year_level'));
        if ($studyYear !== null && $studyYear !== '') {
            $parsedYl = \App\Support\YearLevel::parse($studyYear);
            if ($parsedYl !== null) {
                $query->where('study_year', $parsedYl);
            }
        }

        if ($request->filled('semester_number') || $request->filled('semester')) {
            $semNum = (int) preg_replace('/\D/', '', (string) ($request->input('semester_number') ?? $request->input('semester')));
            if ($semNum > 0) {
                $query->where('semester_number', $semNum);
            }
        }

        if ($request->filled('curriculum_version')) {
            $query->where('curriculum_version', trim((string) $request->input('curriculum_version')));
        }

        if ($request->filled('status')) {
            $query->where('status', strtolower(trim((string) $request->input('status'))));
        } elseif (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        $items = $query->orderBy('program_id')
            ->orderBy('study_year')
            ->orderBy('semester_number')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->map(fn (CurriculumItem $ci) => $this->formatItem($ci))
            ->values();

        return response()->json([
            'message' => 'Curriculum items retrieved successfully.',
            'data' => $items,
        ], 200);
    }

    public function byProgram(Request $request, Program $program): JsonResponse
    {
        $program->load('department:id,code,name');

        $query = CurriculumItem::with(['course.category', 'category'])
            ->where('program_id', $program->id);

        if ($request->filled('study_year') || $request->filled('year_level')) {
            $parsedYl = \App\Support\YearLevel::parse($request->input('study_year', $request->input('year_level')));
            if ($parsedYl !== null) {
                $query->where('study_year', $parsedYl);
            }
        }

        if ($request->filled('semester_number') || $request->filled('semester')) {
            $semNum = (int) preg_replace('/\D/', '', (string) ($request->input('semester_number') ?? $request->input('semester')));
            if ($semNum > 0) {
                $query->where('semester_number', $semNum);
            }
        }

        if ($request->filled('curriculum_version')) {
            $query->where('curriculum_version', trim((string) $request->input('curriculum_version')));
        }

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        $items = $query->orderBy('study_year')
            ->orderBy('semester_number')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        $formatted = $items->map(fn (CurriculumItem $ci) => $this->formatItem($ci))->values();

        // Also build structured Year -> Semester hierarchy
        $maxYear = max((int) ($program->duration_years ?: 4), (int) ($items->max('study_year') ?: 4));
        $yearsTree = [];
        for ($yr = 1; $yr <= $maxYear; $yr++) {
            $yrItems = $items->where('study_year', $yr);
            $semGroups = $yrItems->groupBy('semester_number')->map(function ($sItems, $semNum) {
                $first = $sItems->first();
                $courses = $sItems->map(fn (CurriculumItem $ci) => $this->formatItem($ci))->values();

                return [
                    'semester_number' => (int) $semNum,
                    'semester_name' => $first?->semester_name ?: ('Semester ' . $semNum),
                    'total_credits' => round((float) $courses->sum('credits'), 1),
                    'courses_count' => $courses->count(),
                    'courses' => $courses,
                ];
            })->sortKeys()->values();

            $yearsTree[] = [
                'study_year' => $yr,
                'year_level_code' => \App\Support\YearLevel::code($yr),
                'year_level_name' => \App\Support\YearLevel::name($yr),
                'semesters' => $semGroups,
            ];
        }

        return response()->json([
            'message' => 'Major study curriculum retrieved successfully.',
            'data' => $formatted,
            'years' => $yearsTree,
            'meta' => [
                'program_id' => (int) $program->id,
                'major_id' => (int) $program->id,
                'major_code' => (string) $program->code,
                'major_name' => (string) $program->name,
                'faculty_id' => (int) $program->department_id,
                'faculty_name' => $program->department?->name,
                'duration_years' => (int) ($program->duration_years ?? 4),
                'count' => $formatted->count(),
            ],
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
            'course_id' => 'required|integer|exists:courses,id',
            'course_category_id' => 'nullable|integer|exists:course_categories,id',
            'curriculum_version' => 'nullable|string|max:30',
            'study_year' => 'required|integer|min:1|max:6',
            'semester_number' => 'required|integer|min:1|max:6',
            'semester_name' => 'nullable|string|max:80',
            'credits' => 'nullable|numeric|min:0.5|max:15',
            'is_required' => 'nullable|boolean',
            'display_order' => 'nullable|integer|min:1|max:500',
            'status' => 'nullable|string|in:active,inactive',
        ]);

        $course = Course::findOrFail((int) $validated['course_id']);
        $version = trim((string) ($validated['curriculum_version'] ?? '2026')) ?: '2026';
        $studyYear = (int) $validated['study_year'];
        $semNum = (int) $validated['semester_number'];

        $item = DB::transaction(function () use ($validated, $course, $version, $studyYear, $semNum) {
            $item = CurriculumItem::updateOrCreate(
                [
                    'program_id' => (int) $validated['program_id'],
                    'curriculum_version' => $version,
                    'study_year' => $studyYear,
                    'semester_number' => $semNum,
                    'course_id' => $course->id,
                ],
                [
                    'course_category_id' => $validated['course_category_id'] ?? $course->course_category_id,
                    'semester_name' => $validated['semester_name'] ?? ('Semester ' . $semNum),
                    'credits' => $validated['credits'] ?? ($course->credit ?? $course->credits ?? 3.0),
                    'is_required' => array_key_exists('is_required', $validated) ? (bool) $validated['is_required'] : true,
                    'display_order' => (int) ($validated['display_order'] ?? 1),
                    'status' => strtolower($validated['status'] ?? 'active'),
                ]
            );

            // Also ensure course_program pivot link exists
            if (\Illuminate\Support\Facades\Schema::hasTable('course_program')) {
                DB::table('course_program')->insertOrIgnore([
                    'course_id' => $course->id,
                    'program_id' => (int) $validated['program_id'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $item;
        });

        $item->load(['program.department:id,code,name', 'course.category', 'category']);

        return response()->json([
            'message' => 'Course added to study curriculum successfully.',
            'data' => $this->formatItem($item),
        ], 201);
    }

    public function update(Request $request, CurriculumItem $curriculum): JsonResponse
    {
        if (! $request->filled('program_id') && $request->filled('major_id')) {
            $request->merge(['program_id' => $request->input('major_id')]);
        }

        $validated = $request->validate([
            'program_id' => 'sometimes|required|integer|exists:programs,id',
            'course_id' => 'sometimes|required|integer|exists:courses,id',
            'course_category_id' => 'nullable|integer|exists:course_categories,id',
            'curriculum_version' => 'sometimes|string|max:30',
            'study_year' => 'sometimes|required|integer|min:1|max:6',
            'semester_number' => 'sometimes|required|integer|min:1|max:6',
            'semester_name' => 'nullable|string|max:80',
            'credits' => 'nullable|numeric|min:0.5|max:15',
            'is_required' => 'nullable|boolean',
            'display_order' => 'nullable|integer|min:1|max:500',
            'status' => 'sometimes|required|string|in:active,inactive',
        ]);

        $curriculum->update($validated);
        $curriculum->load(['program.department:id,code,name', 'course.category', 'category']);

        return response()->json([
            'message' => 'Curriculum item updated successfully.',
            'data' => $this->formatItem($curriculum),
        ], 200);
    }

    public function destroy(CurriculumItem $curriculum): JsonResponse
    {
        $curriculum->delete();

        return response()->json([
            'message' => 'Curriculum item removed successfully.',
        ], 200);
    }

    private function formatItem(CurriculumItem $ci): array
    {
        $course = $ci->course;
        $cat = $ci->category ?? $course?->category;
        $prog = $ci->program;

        return [
            'id' => (int) $ci->id,
            'program_id' => (int) $ci->program_id,
            'major_id' => (int) $ci->program_id,
            'major_code' => $prog?->code,
            'major_name' => $prog?->name,
            'faculty_id' => $prog?->department_id,
            'faculty_name' => $prog?->department?->name,
            'course_id' => (int) $ci->course_id,
            'course_code' => (string) ($course?->course_code ?? $course?->code ?? ''),
            'course_name' => (string) ($course?->course_name ?? $course?->name ?? ''),
            'course_category_id' => $cat?->id ? (int) $cat->id : null,
            'course_category_name' => $cat?->name,
            'course_category_code' => $cat?->code,
            'curriculum_version' => (string) ($ci->curriculum_version ?? '2026'),
            'study_year' => (int) $ci->study_year,
            'year_level' => (int) $ci->study_year,
            'year_level_code' => \App\Support\YearLevel::code($ci->study_year),
            'year_level_name' => \App\Support\YearLevel::name($ci->study_year),
            'semester_number' => (int) $ci->semester_number,
            'semester_name' => $ci->semester_name ?: ('Semester ' . $ci->semester_number),
            'credits' => (float) ($ci->credits ?? $course?->credit ?? $course?->credits ?? 3.0),
            'is_required' => (bool) $ci->is_required,
            'requirement_type' => $ci->is_required ? 'Required' : 'Elective',
            'display_order' => (int) ($ci->display_order ?? 1),
            'status' => (string) ($ci->status ?? 'active'),
        ];
    }
}
