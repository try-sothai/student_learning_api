<?php

namespace App\Http\Controllers;

use App\Models\ClassRoom;
use App\Models\CourseOffering;
use App\Models\Floor;
use App\Models\User;
use App\Rules\ValidYearLevel;
use App\Support\YearLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClassRoomController extends Controller
{
    public function index(Request $request)
    {
        $query = ClassRoom::with([
            'department',
            'academicYear',
            'building',
            'floor',
            'course',
            'teacher',
            'offerings.course',
            'offerings.activeTeachers.user',
        ])
            ->withCount(['students', 'schedules']);

        if ($request->filled('building_id')) {
            $query->where('building_id', (int) $request->building_id);
        }
        if ($request->filled('floor_id')) {
            $query->where('floor_id', (int) $request->floor_id);
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', (int) $request->department_id);
        }
        if ($request->filled('academic_year_id')) {
            $query->where('academic_year_id', (int) $request->academic_year_id);
        }
        if ($request->filled('year_level') && strtolower((string) $request->year_level) !== 'all') {
            $query->forYearLevel($request->year_level);
        }
        if ($request->filled('status') && strtolower((string) $request->status) !== 'all') {
            $query->where('status', strtolower((string) $request->status));
        }
        if ($request->filled('search')) {
            $s = trim((string) $request->search);
            $query->where(function ($sub) use ($s) {
                $sub->where('name', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%")
                    ->orWhere('room', 'like', "%{$s}%");
            });
        }

        $classes = $query
            ->orderByRaw("CASE WHEN code IN ('CLASS-A','CLASS-B','CLASS-C','CLASS-D','CLASS-E') THEN 0 ELSE 1 END")
            ->orderBy('code')
            ->latest('id')
            ->get()
            ->map(function (ClassRoom $c) {
                $courseNames = $c->offerings
                    ->map(fn ($o) => $o->course?->course_name ?? $o->course?->name)
                    ->filter()
                    ->unique()
                    ->values();
                $teacherNames = $c->offerings
                    ->flatMap(fn ($o) => $o->activeTeachers->map(fn ($t) => $t->user?->name ?: trim(($t->first_name ?? '').' '.($t->last_name ?? ''))))
                    ->filter()
                    ->unique()
                    ->values();

                if ($courseNames->isEmpty() && $c->course) {
                    $courseNames->push($c->course->course_name ?? $c->course->name);
                }
                if ($teacherNames->isEmpty() && $c->teacher) {
                    $teacherNames->push($c->teacher->user?->name ?: trim(($c->teacher->first_name ?? '').' '.($c->teacher->last_name ?? '')));
                }

                $c->setAttribute('building_name', $c->building?->name);
                $c->setAttribute('floor_name', $c->floor?->name);
                $locationParts = array_filter([$c->building?->name, $c->floor?->name]);
                $c->setAttribute('location_label', ! empty($locationParts) ? implode(' • ', $locationParts) : 'Unassigned location');
                $c->setAttribute('courses_summary', $courseNames->isNotEmpty() ? $courseNames->implode(', ') : 'No courses assigned');
                $c->setAttribute('teachers_summary', $teacherNames->isNotEmpty() ? $teacherNames->implode(', ') : 'Unassigned');
                $c->setAttribute('offerings_count', $c->offerings->count());
                $c->setAttribute('is_empty', $c->students_count === 0 && $c->offerings->isEmpty());
                $c->unsetRelation('offerings');

                return $c;
            });

        return response()->json([
            'message' => 'Classes retrieved successfully.',
            'data' => $classes,
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'department_id' => 'required|integer|exists:departments,id',
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'building_id' => 'nullable|integer|exists:buildings,id',
            'floor_id' => 'nullable|integer|exists:floors,id',
            'course_id' => 'nullable|integer|exists:courses,id',
            'teacher_id' => 'nullable|integer|exists:teachers,id',
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50|unique:classes,code',
            'year_level' => ['required', new ValidYearLevel()],
            'day_of_week' => 'nullable|string|max:50',
            'start_time' => 'nullable|string|max:20',
            'end_time' => 'nullable|string|max:20',
            'room' => 'nullable|string|max:100',
            'capacity' => 'nullable|integer|min:1|max:500',
            'status' => 'nullable|string|in:active,inactive',
        ]);

        $buildingId = $validated['building_id'] ?? null;
        $floorId = $validated['floor_id'] ?? null;
        if ($floorId) {
            $floor = Floor::findOrFail($floorId);
            if ($buildingId && (int) $floor->building_id !== (int) $buildingId) {
                return response()->json([
                    'message' => 'Selected floor does not belong to the selected building.',
                ], 422);
            }
            $buildingId = $floor->building_id;
        }

        $classRoom = ClassRoom::create([
            'department_id' => $validated['department_id'],
            'academic_year_id' => $validated['academic_year_id'],
            'building_id' => $buildingId,
            'floor_id' => $floorId,
            'course_id' => $validated['course_id'] ?? null,
            'teacher_id' => $validated['teacher_id'] ?? null,
            'name' => $validated['name'],
            'code' => strtoupper($validated['code']),
            'year_level' => YearLevel::parse($validated['year_level']),
            'day_of_week' => $validated['day_of_week'] ?? null,
            'start_time' => $validated['start_time'] ?? null,
            'end_time' => $validated['end_time'] ?? null,
            'room' => $validated['room'] ?? $validated['name'],
            'capacity' => $validated['capacity'] ?? 40,
            'status' => $validated['status'] ?? 'active',
        ]);

        return response()->json([
            'message' => 'Class created successfully.',
            'data' => $classRoom->load(['department', 'academicYear', 'building', 'floor', 'course', 'teacher']),
        ], 201);
    }

    public function show(ClassRoom $classRoom)
    {
        $classRoom->load([
            'department',
            'academicYear',
            'students.user:id,name,email',
            'offerings' => fn ($q) => $q->with(['course', 'semester.academicYear', 'activeTeachers.user'])->withCount(['enrollments as enrolled_count' => fn ($eq) => $eq->where('status', 'enrolled')]),
        ]);

        return response()->json([
            'message' => 'Class retrieved successfully.',
            'data' => $classRoom,
        ], 200);
    }

    /**
     * Admin-only structured academic overview: Classes -> Courses -> Teachers -> Students.
     * Never exposes user passwords.
     */
    public function academicStructure(): JsonResponse
    {
        $admins = User::where('role', 'admin')
            ->with('admin')
            ->get()
            ->map(fn (User $u) => [
                'name' => $u->name,
                'login_id' => $u->admin?->admin_code ?? $u->email,
                'email' => $u->email,
            ])
            ->values();

        $buildings = \App\Models\Building::with([
            'floors' => fn ($fq) => $fq->orderBy('floor_number')->with(['classes' => fn ($cq) => $cq->orderBy('code')]),
        ])->orderBy('name')->get();

        $classes = ClassRoom::with([
            'department',
            'academicYear',
            'building',
            'floor',
            'students' => fn ($q) => $q->orderBy('student_code'),
            'offerings' => fn ($q) => $q->with(['course', 'semester.academicYear', 'activeTeachers.user'])
                ->withCount(['enrollments as enrolled_count' => fn ($eq) => $eq->where('status', 'enrolled')]),
        ])
            ->orderByRaw("CASE WHEN code IN ('CLASS-A','CLASS-B','CLASS-C','CLASS-D','CLASS-E') THEN 0 ELSE 1 END")
            ->orderBy('code')
            ->get()
            ->map(function (ClassRoom $c) {
                $courses = $c->offerings->map(function (CourseOffering $o) {
                    $t = $o->activeTeachers->first();

                    return [
                        'course_offering_id' => $o->id,
                        'course_id' => $o->course_id,
                        'course_code' => $o->course?->course_code ?? $o->course?->code,
                        'course_name' => $o->course?->course_name ?? $o->course?->name,
                        'teacher_id' => $t?->id,
                        'teacher_code' => $t?->teacher_code,
                        'teacher_name' => $t?->user?->name ?: ($t ? trim(($t->first_name ?? '').' '.($t->last_name ?? '')) : null),
                        'semester_name' => $o->semester?->name,
                        'academic_year_name' => $o->semester?->academicYear?->name,
                        'enrolled_students_count' => (int) ($o->enrolled_count ?? 0),
                    ];
                })->values();

                $students = $c->students->map(fn ($s) => [
                    'id' => $s->id,
                    'student_code' => $s->student_code,
                    'name' => $s->khmer_name ?: ($s->english_name ?: trim(($s->first_name ?? '').' '.($s->last_name ?? ''))),
                    'year_level' => $s->year_level,
                    'year_level_code' => $s->year_level_code,
                    'year_level_label' => $s->year_level_label,
                    'status' => $s->status,
                ])->values();

                return [
                    'id' => $c->id,
                    'code' => $c->code,
                    'name' => $c->name,
                    'year_level' => $c->year_level,
                    'year_level_code' => $c->year_level_code,
                    'year_level_name' => $c->year_level_name,
                    'year_level_label' => $c->year_level_label,
                    'department' => $c->department?->name,
                    'academic_year' => $c->academicYear?->name,
                    'building_id' => $c->building_id,
                    'building_name' => $c->building?->name,
                    'floor_id' => $c->floor_id,
                    'floor_name' => $c->floor?->name,
                    'capacity' => (int) ($c->capacity ?? 40),
                    'students_count' => $students->count(),
                    'courses_count' => $courses->count(),
                    'is_empty' => $students->isEmpty() && $courses->isEmpty(),
                    'courses' => $courses,
                    'students' => $students,
                ];
            })->values();

        $teacherAssignments = CourseOffering::with(['classRoom', 'course.departmentRelation', 'semester.academicYear', 'activeTeachers.user'])
            ->whereHas('activeTeachers')
            ->withCount(['enrollments as enrolled_count' => fn ($eq) => $eq->where('status', 'enrolled')])
            ->orderByRaw("CASE WHEN EXISTS (SELECT 1 FROM classes WHERE classes.id = course_offerings.class_id AND classes.code = 'CLASS-A') THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get()
            ->map(function (CourseOffering $o) {
                $t = $o->activeTeachers->first();

                return [
                    'course_offering_id' => $o->id,
                    'class_id' => $o->class_id,
                    'class_code' => $o->classRoom?->code ?? $o->section_name,
                    'class_name' => $o->classRoom?->name ?? $o->section_name,
                    'course_code' => $o->course?->course_code ?? $o->course?->code,
                    'course_name' => $o->course?->course_name ?? $o->course?->name,
                    'teacher_code' => $t?->teacher_code,
                    'teacher_name' => $t?->user?->name ?: trim(($t?->first_name ?? '').' '.($t?->last_name ?? '')),
                    'department_term' => trim(($o->course?->departmentRelation?->name ?? 'Academic').' • '.($o->semester?->academicYear?->name ?? '').' '.($o->semester?->name ?? '')),
                    'enrolled_count' => (int) ($o->enrolled_count ?? 0),
                    'status' => 'Assigned',
                ];
            })->values();

        return response()->json([
            'message' => 'Academic structure retrieved successfully.',
            'data' => [
                'admins' => $admins,
                'buildings' => $buildings,
                'classes' => $classes,
                'teacher_assignments' => $teacherAssignments,
            ],
        ]);
    }

    public function update(Request $request, ClassRoom $classRoom)
    {
        $validated = $request->validate([
            'department_id' => 'required|integer|exists:departments,id',
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'building_id' => 'nullable|integer|exists:buildings,id',
            'floor_id' => 'nullable|integer|exists:floors,id',
            'name' => 'required|string|max:100',
            'code' => ['required', 'string', 'max:50', Rule::unique('classes', 'code')->ignore($classRoom->id)],
            'year_level' => ['required', new ValidYearLevel()],
            'room' => 'nullable|string|max:100',
            'capacity' => 'nullable|integer|min:1|max:500',
            'status' => 'nullable|string|in:active,inactive',
        ]);

        $buildingId = array_key_exists('building_id', $validated) ? $validated['building_id'] : $classRoom->building_id;
        $floorId = array_key_exists('floor_id', $validated) ? $validated['floor_id'] : $classRoom->floor_id;
        if ($floorId) {
            $floor = Floor::findOrFail($floorId);
            if ($buildingId && (int) $floor->building_id !== (int) $buildingId) {
                return response()->json([
                    'message' => 'Selected floor does not belong to the selected building.',
                ], 422);
            }
            $buildingId = $floor->building_id;
        }

        $classRoom->update([
            'department_id' => $validated['department_id'],
            'academic_year_id' => $validated['academic_year_id'],
            'building_id' => $buildingId,
            'floor_id' => $floorId,
            'name' => $validated['name'],
            'code' => strtoupper($validated['code']),
            'year_level' => YearLevel::parse($validated['year_level']),
            'room' => $validated['room'] ?? $validated['name'],
            'capacity' => $validated['capacity'] ?? $classRoom->capacity ?? 40,
            'status' => $validated['status'] ?? $classRoom->status,
        ]);

        // Keep existing class schedules synced with the updated Building / Floor location
        \App\Models\ClassSchedule::where('class_id', $classRoom->id)->update([
            'building_id' => $buildingId,
            'floor_id' => $floorId,
            'department_id' => $validated['department_id'],
            'academic_year_id' => $validated['academic_year_id'],
        ]);

        return response()->json([
            'message' => 'Class updated successfully.',
            'data' => $classRoom->fresh()->load(['department', 'academicYear', 'building', 'floor']),
        ], 200);
    }

    public function destroy(ClassRoom $classRoom)
    {
        $classRoom->delete();

        return response()->json([
            'message' => 'Class deleted successfully.',
        ], 200);
    }
}
