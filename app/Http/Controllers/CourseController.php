<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\ClassRoom;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Program;
use App\Models\Semester;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CourseController extends Controller
{
    public function index(Request $request)
    {
        $baseQuery = Course::query();

        // Apply non-status filters to a shared base query so statistics reflect active filter scope
        $this->applyScopeFilters($baseQuery, $request);

        // Calculate accurate statistics from the scoped course dataset (no duplicate rows)
        $statsCourses = (clone $baseQuery)->get(['id', 'status', 'teacher_id']);
        $totalCourses = $statsCourses->count();
        $activeCourses = $statsCourses->filter(fn (Course $c) => strtolower((string) ($c->status ?: 'active')) === 'active')->count();
        $inactiveCourses = $totalCourses - $activeCourses;
        $missingTeacherCourses = $statsCourses->filter(fn (Course $c) => empty($c->teacher_id))->count();

        // Apply status filter if requested
        $listQuery = clone $baseQuery;
        if ($request->filled('status')) {
            $statusFilter = strtolower(trim((string) $request->input('status')));
            if (in_array($statusFilter, ['active', 'inactive'], true)) {
                $listQuery->whereRaw('LOWER(COALESCE(status, "active")) = ?', [$statusFilter]);
            }
        }

        $courses = $listQuery
            ->with([
                'departmentRelation',
                'teacher.user',
                'offerings.program',
                'offerings.academicYear',
                'offerings.semester',
                'offerings.classRoom',
                'classes',
            ])
            ->withCount([
                'enrollments',
                'offerings',
                'assignments',
                'quizzes',
                'exams',
                'grades',
            ])
            ->orderBy('course_code')
            ->orderBy('id')
            ->get();

        $programsByDept = Program::where('status', 'active')
            ->orderBy('id')
            ->get()
            ->groupBy('department_id');

        $activeAy = AcademicYear::where('status', 'active')->first()
            ?? AcademicYear::orderByDesc('id')->first();
        $activeSem = Semester::where('status', 'active')->first()
            ?? ($activeAy ? Semester::where('academic_year_id', $activeAy->id)->orderBy('semester_number')->first() : null);

        $formatted = $courses->map(fn (Course $c) => $this->formatCourse($c, $programsByDept, $activeAy, $activeSem))->values();

        return response()->json([
            'message' => 'Courses retrieved successfully.',
            'statistics' => [
                'total_courses' => $totalCourses,
                'active_courses' => $activeCourses,
                'inactive_courses' => $inactiveCourses,
                'missing_teacher_courses' => $missingTeacherCourses,
            ],
            'data' => $formatted,
        ], 200);
    }

    public function store(Request $request)
    {
        $payload = $this->normalizeCourseInput($request);

        $validated = validator($payload, [
            'department_id' => 'required|integer|exists:departments,id',
            'program_id' => 'nullable|integer|exists:programs,id',
            'teacher_id' => 'nullable|integer|exists:teachers,id',
            'academic_year_id' => 'nullable|integer|exists:academic_years,id',
            'semester_id' => 'nullable|integer|exists:semesters,id',
            'academic_year' => 'nullable|string|max:50',
            'semester' => 'nullable|string|max:50',
            'course_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('courses', 'course_code')->whereNull('deleted_at'),
            ],
            'course_name' => 'required|string|max:200',
            'description' => 'nullable|string',
            'credit' => 'required|numeric|min:0.5|max:10',
            'status' => 'nullable|string|in:active,inactive',
        ])->validate();

        $dept = Department::find($validated['department_id']);
        $ayName = $validated['academic_year'] ?? null;
        if (!$ayName && !empty($validated['academic_year_id'])) {
            $ayName = AcademicYear::where('id', $validated['academic_year_id'])->value('name');
        }
        $semName = $validated['semester'] ?? null;
        if (!$semName && !empty($validated['semester_id'])) {
            $semName = Semester::where('id', $validated['semester_id'])->value('name');
        }

        $codeUpper = strtoupper(trim($validated['course_code']));
        $courseName = trim($validated['course_name']);
        $creditVal = (float) $validated['credit'];
        $statusVal = strtolower($validated['status'] ?? 'active');

        $course = DB::transaction(function () use ($validated, $dept, $ayName, $semName, $codeUpper, $courseName, $creditVal, $statusVal) {
            $course = Course::create([
                'department_id' => $validated['department_id'],
                'department' => $dept?->name,
                'teacher_id' => $validated['teacher_id'] ?? null,
                'course_code' => $codeUpper,
                'code' => $codeUpper,
                'course_name' => $courseName,
                'name' => $courseName,
                'description' => $validated['description'] ?? null,
                'credit' => $creditVal,
                'credits' => (int) round($creditVal),
                'academic_year' => $ayName,
                'semester' => $semName,
                'status' => $statusVal,
            ]);

            $this->syncCourseOffering($course, $validated);

            return $course;
        });

        $course->load([
            'departmentRelation',
            'teacher.user',
            'offerings.program',
            'offerings.academicYear',
            'offerings.semester',
            'offerings.classRoom',
            'classes',
        ])->loadCount(['enrollments', 'offerings', 'assignments', 'quizzes', 'exams', 'grades']);

        $programsByDept = Program::where('status', 'active')->orderBy('id')->get()->groupBy('department_id');

        return response()->json([
            'message' => 'Course created successfully.',
            'data' => $this->formatCourse($course, $programsByDept, null, null),
        ], 201);
    }

    public function show(Course $course)
    {
        $course->load([
            'departmentRelation',
            'teacher.user',
            'offerings.program',
            'offerings.academicYear',
            'offerings.semester',
            'offerings.classRoom',
            'classes',
            'enrollments.student',
        ])->loadCount(['enrollments', 'offerings', 'assignments', 'quizzes', 'exams', 'grades']);

        $programsByDept = Program::where('status', 'active')->orderBy('id')->get()->groupBy('department_id');

        return response()->json([
            'message' => 'Course retrieved successfully.',
            'data' => $this->formatCourse($course, $programsByDept, null, null),
        ], 200);
    }

    public function update(Request $request, Course $course)
    {
        $payload = $this->normalizeCourseInput($request, $course);

        $validated = validator($payload, [
            'department_id' => 'required|integer|exists:departments,id',
            'program_id' => 'nullable|integer|exists:programs,id',
            'teacher_id' => 'nullable|integer|exists:teachers,id',
            'academic_year_id' => 'nullable|integer|exists:academic_years,id',
            'semester_id' => 'nullable|integer|exists:semesters,id',
            'academic_year' => 'nullable|string|max:50',
            'semester' => 'nullable|string|max:50',
            'course_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('courses', 'course_code')->ignore($course->id)->whereNull('deleted_at'),
            ],
            'course_name' => 'required|string|max:200',
            'description' => 'nullable|string',
            'credit' => 'required|numeric|min:0.5|max:10',
            'status' => 'nullable|string|in:active,inactive',
        ])->validate();

        $dept = Department::find($validated['department_id']);
        $ayName = $validated['academic_year'] ?? $course->academic_year;
        if (!empty($validated['academic_year_id'])) {
            $ayName = AcademicYear::where('id', $validated['academic_year_id'])->value('name') ?? $ayName;
        }
        $semName = $validated['semester'] ?? $course->semester;
        if (!empty($validated['semester_id'])) {
            $semName = Semester::where('id', $validated['semester_id'])->value('name') ?? $semName;
        }

        $codeUpper = strtoupper(trim($validated['course_code']));
        $courseName = trim($validated['course_name']);
        $creditVal = (float) $validated['credit'];
        $statusVal = strtolower($validated['status'] ?? ($course->status ?: 'active'));

        DB::transaction(function () use ($course, $validated, $dept, $ayName, $semName, $codeUpper, $courseName, $creditVal, $statusVal, $request) {
            $course->update([
                'department_id' => $validated['department_id'],
                'department' => $dept?->name ?? $course->getAttributes()['department'],
                'teacher_id' => array_key_exists('teacher_id', $validated) ? $validated['teacher_id'] : $course->teacher_id,
                'course_code' => $codeUpper,
                'code' => $codeUpper,
                'course_name' => $courseName,
                'name' => $courseName,
                'description' => array_key_exists('description', $validated) ? $validated['description'] : $course->description,
                'credit' => $creditVal,
                'credits' => (int) round($creditVal),
                'academic_year' => $ayName,
                'semester' => $semName,
                'status' => $statusVal,
            ]);

            if ($request->hasAny(['program_id', 'academic_year_id', 'semester_id'])) {
                $this->syncCourseOffering($course, $validated);
            }
        });

        $fresh = $course->fresh()->load([
            'departmentRelation',
            'teacher.user',
            'offerings.program',
            'offerings.academicYear',
            'offerings.semester',
            'offerings.classRoom',
            'classes',
        ])->loadCount(['enrollments', 'offerings', 'assignments', 'quizzes', 'exams', 'grades']);

        $programsByDept = Program::where('status', 'active')->orderBy('id')->get()->groupBy('department_id');

        return response()->json([
            'message' => 'Course updated successfully.',
            'data' => $this->formatCourse($fresh, $programsByDept, null, null),
        ], 200);
    }

    public function updateStatus(Request $request, Course $course)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:active,inactive,Active,Inactive',
        ]);

        $course->update([
            'status' => strtolower($validated['status']),
        ]);

        $fresh = $course->fresh()->load([
            'departmentRelation',
            'teacher.user',
            'offerings.program',
            'offerings.academicYear',
            'offerings.semester',
            'offerings.classRoom',
            'classes',
        ])->loadCount(['enrollments', 'offerings', 'assignments', 'quizzes', 'exams', 'grades']);

        $programsByDept = Program::where('status', 'active')->orderBy('id')->get()->groupBy('department_id');

        return response()->json([
            'message' => 'Course status updated successfully.',
            'data' => $this->formatCourse($fresh, $programsByDept, null, null),
        ], 200);
    }

    public function destroy(Course $course)
    {
        $classesCount = ClassRoom::where('course_id', $course->id)->count();
        $offeringsCount = CourseOffering::where('course_id', $course->id)->count();
        $enrollmentsCount = $course->enrollments()->count();
        $assignmentsCount = $course->assignments()->count();
        $quizzesCount = $course->quizzes()->count();
        $examsCount = $course->exams()->count();
        $gradesCount = $course->grades()->count();

        if ($classesCount > 0 || $offeringsCount > 0 || $enrollmentsCount > 0 || $assignmentsCount > 0 || $quizzesCount > 0 || $examsCount > 0 || $gradesCount > 0) {
            return response()->json([
                'message' => "Cannot delete course {$course->course_code} because it is referenced by existing classes, schedules, or academic records ({$offeringsCount} offerings, {$enrollmentsCount} enrollments). Please set its status to Inactive instead.",
                'errors' => [
                    'course' => ['Course has linked academic records and cannot be safely deleted.'],
                ],
            ], 422);
        }

        $course->delete();

        return response()->json([
            'message' => 'Course deleted successfully.',
        ], 200);
    }

    private function applyScopeFilters(Builder $query, Request $request): void
    {
        if ($request->filled('search')) {
            $q = trim((string) $request->input('search'));
            $query->where(function (Builder $sub) use ($q) {
                $sub->where('course_code', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhere('course_name', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%")
                    ->orWhere('department', 'like', "%{$q}%")
                    ->orWhereHas('departmentRelation', function (Builder $dq) use ($q) {
                        $dq->where('name', 'like', "%{$q}%")
                            ->orWhere('code', 'like', "%{$q}%");
                    })
                    ->orWhereHas('offerings.program', function (Builder $pq) use ($q) {
                        $pq->where('name', 'like', "%{$q}%")
                            ->orWhere('code', 'like', "%{$q}%");
                    })
                    ->orWhereHas('teacher', function (Builder $tq) use ($q) {
                        $tq->where('first_name', 'like', "%{$q}%")
                            ->orWhere('last_name', 'like', "%{$q}%")
                            ->orWhereHas('user', fn (Builder $uq) => $uq->where('name', 'like', "%{$q}%"));
                    });
            });
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', (int) $request->input('department_id'));
        } elseif ($request->filled('department') && strtolower((string) $request->input('department')) !== 'all') {
            $deptFilter = trim((string) $request->input('department'));
            $query->where(function (Builder $sub) use ($deptFilter) {
                $sub->where('department', 'like', "%{$deptFilter}%")
                    ->orWhereHas('departmentRelation', function (Builder $dq) use ($deptFilter) {
                        $dq->where('name', 'like', "%{$deptFilter}%")
                            ->orWhere('code', 'like', "%{$deptFilter}%");
                    });
            });
        }

        if ($request->filled('program_id')) {
            $progId = (int) $request->input('program_id');
            $prog = Program::find($progId);
            $query->where(function (Builder $sub) use ($progId, $prog) {
                if ($prog && $prog->department_id) {
                    $sub->where('department_id', $prog->department_id);
                } else {
                    $sub->whereHas('offerings', fn (Builder $oq) => $oq->where('program_id', $progId));
                }
            });
        } elseif ($request->filled('program') && strtolower((string) $request->input('program')) !== 'all') {
            $progFilter = trim((string) $request->input('program'));
            $query->whereHas('offerings.program', function (Builder $pq) use ($progFilter) {
                $pq->where('name', 'like', "%{$progFilter}%")
                    ->orWhere('code', 'like', "%{$progFilter}%");
            });
        }

        if ($request->filled('academic_year_id')) {
            $ayId = (int) $request->input('academic_year_id');
            $ayName = AcademicYear::where('id', $ayId)->value('name');
            $query->where(function (Builder $sub) use ($ayId, $ayName) {
                $sub->whereHas('offerings', fn (Builder $oq) => $oq->where('academic_year_id', $ayId));
                if ($ayName) {
                    $sub->orWhere('academic_year', $ayName);
                }
            });
        } elseif ($request->filled('academic_year') && strtolower((string) $request->input('academic_year')) !== 'all') {
            $ayFilter = trim((string) $request->input('academic_year'));
            $query->where(function (Builder $sub) use ($ayFilter) {
                $sub->where('academic_year', $ayFilter)
                    ->orWhereHas('offerings.academicYear', fn (Builder $aq) => $aq->where('name', $ayFilter));
            });
        }

        if ($request->filled('semester_id')) {
            $semId = (int) $request->input('semester_id');
            $sem = Semester::find($semId);
            $query->where(function (Builder $sub) use ($semId, $sem) {
                $sub->whereHas('offerings', fn (Builder $oq) => $oq->where('semester_id', $semId));
                if ($sem) {
                    $sub->orWhere('semester', $sem->name)
                        ->orWhereHas('offerings.semester', fn (Builder $sq) => $sq->where('semester_number', $sem->semester_number));
                }
            });
        } elseif ($request->filled('semester') && strtolower((string) $request->input('semester')) !== 'all') {
            $semFilter = trim((string) $request->input('semester'));
            $query->where(function (Builder $sub) use ($semFilter) {
                $sub->where('semester', 'like', "%{$semFilter}%")
                    ->orWhereHas('offerings.semester', fn (Builder $sq) => $sq->where('name', 'like', "%{$semFilter}%"));
            });
        }
    }

    private function normalizeCourseInput(Request $request, ?Course $existing = null): array
    {
        $data = $request->all();

        if (empty($data['course_code']) && !empty($data['code'])) {
            $data['course_code'] = $data['code'];
        }
        if (empty($data['course_name']) && !empty($data['name'])) {
            $data['course_name'] = $data['name'];
        }
        if (!isset($data['credit']) && isset($data['credits'])) {
            $data['credit'] = $data['credits'];
        }
        if (empty($data['department_id']) && !empty($data['department']) && is_string($data['department'])) {
            $dept = Department::where('name', $data['department'])
                ->orWhere('code', $data['department'])
                ->first();
            if ($dept) {
                $data['department_id'] = $dept->id;
            }
        }
        if (empty($data['department_id']) && $existing && $existing->department_id) {
            $data['department_id'] = $existing->department_id;
        }
        if (isset($data['status']) && is_string($data['status'])) {
            $data['status'] = strtolower(trim($data['status']));
        }

        return $data;
    }

    private function syncCourseOffering(Course $course, array $validated): void
    {
        $progId = $validated['program_id'] ?? null;
        $ayId = $validated['academic_year_id'] ?? null;
        $semId = $validated['semester_id'] ?? null;

        if (!$progId && !$ayId && !$semId) {
            return;
        }

        if (!$ayId) {
            $ayId = AcademicYear::where('status', 'active')->value('id')
                ?? AcademicYear::orderByDesc('id')->value('id');
        }
        if (!$semId && $ayId) {
            $semId = Semester::where('academic_year_id', $ayId)->where('status', 'active')->value('id')
                ?? Semester::where('academic_year_id', $ayId)->orderBy('semester_number')->value('id');
        }
        if (!$progId && $course->department_id) {
            $progId = Program::where('department_id', $course->department_id)->where('status', 'active')->value('id');
        }

        $classRoom = ClassRoom::where('department_id', $course->department_id)
            ->orderByDesc('id')
            ->first();

        if ($ayId && $semId) {
            $offering = CourseOffering::firstOrCreate(
                [
                    'course_id' => $course->id,
                    'academic_year_id' => $ayId,
                    'semester_id' => $semId,
                ],
                [
                    'class_id' => $classRoom?->id,
                    'program_id' => $progId,
                    'study_year' => $classRoom?->year_level ?? 1,
                    'section_name' => $classRoom?->name ?? ($course->course_code . ' Section A'),
                    'status' => $course->status ?: 'active',
                ]
            );

            if ($progId && $offering->program_id != $progId) {
                $offering->update(['program_id' => $progId]);
            }
        }
    }

    private function formatCourse(Course $course, $programsByDept, ?AcademicYear $defaultAy, ?Semester $defaultSem): array
    {
        $dept = $course->relationLoaded('departmentRelation') ? $course->getRelation('departmentRelation') : $course->department()->first();
        $deptName = $dept?->name ?? ($course->getAttributes()['department'] ?? '');
        $deptCode = $dept?->code ?? '';

        $teacher = $course->relationLoaded('teacher') ? $course->getRelation('teacher') : $course->teacher()->with('user')->first();
        $teacherName = '';
        if ($teacher) {
            $teacherName = trim(($teacher->first_name ?? '') . ' ' . ($teacher->last_name ?? ''));
            if ($teacherName === '' && $teacher->relationLoaded('user') && $teacher->user) {
                $teacherName = $teacher->user->name ?? '';
            }
        }

        $offerings = $course->relationLoaded('offerings') ? $course->offerings : collect();
        $activeOffering = $offerings->first(function (CourseOffering $o) use ($defaultAy, $defaultSem) {
            if ($defaultAy && $defaultSem) {
                return $o->academic_year_id == $defaultAy->id && $o->semester_id == $defaultSem->id;
            }
            return $o->status === 'active';
        }) ?? $offerings->sortByDesc('id')->first();

        $program = $activeOffering?->program;
        if ((!$program || ($course->department_id && $program->department_id != $course->department_id))
            && $course->department_id && isset($programsByDept[$course->department_id])) {
            $program = $programsByDept[$course->department_id]->first();
        }
        $programId = $program?->id;
        $programName = $program?->name ?? '';
        $programCode = $program?->code ?? '';

        $ayId = $activeOffering?->academic_year_id ?? $defaultAy?->id;
        $ayName = $course->academic_year ?: ($activeOffering?->academicYear?->name ?? ($defaultAy?->name ?? ''));

        $semId = $activeOffering?->semester_id ?? $defaultSem?->id;
        $semName = $course->semester ?: ($activeOffering?->semester?->name ?? ($defaultSem?->name ?? ''));

        $classCodes = $offerings
            ->map(fn (CourseOffering $o) => $o->classRoom?->code ?: $o->section_name)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($classCodes) && $course->relationLoaded('classes')) {
            $classCodes = $course->classes->pluck('code')->filter()->unique()->values()->all();
        }

        $code = $course->course_code ?? $course->code ?? '';
        $name = $course->course_name ?? $course->name ?? '';
        $creditsFloat = (float) ($course->credit ?? $course->credits ?? 3.0);
        $statusLower = strtolower((string) ($course->status ?: 'active'));

        return [
            'id' => $course->id,
            'code' => $code,
            'course_code' => $code,
            'name' => $name,
            'course_name' => $name,
            'description' => (string) ($course->description ?? ''),
            'credit' => $creditsFloat,
            'credits' => (int) round($creditsFloat),
            'status' => $statusLower,
            'department_id' => $course->department_id,
            'department_name' => $deptName,
            'department_code' => $deptCode,
            'department' => $dept ? [
                'id' => $dept->id,
                'name' => $dept->name,
                'code' => $dept->code,
            ] : ($deptName !== '' ? [
                'id' => $course->department_id,
                'name' => $deptName,
                'code' => $deptCode,
            ] : null),
            'program_id' => $programId,
            'program_name' => $programName,
            'program_code' => $programCode,
            'teacher_id' => $course->teacher_id,
            'teacher_name' => $teacherName,
            'teacher' => $teacher ? [
                'id' => $teacher->id,
                'teacher_id' => $teacher->teacher_id ?? $teacher->teacher_code ?? null,
                'first_name' => $teacher->first_name,
                'last_name' => $teacher->last_name,
                'name' => $teacherName,
            ] : null,
            'academic_year_id' => $ayId,
            'academic_year' => $ayName,
            'semester_id' => $semId,
            'semester' => $semName,
            'day_of_week' => $course->day_of_week,
            'start_time' => $course->start_time,
            'end_time' => $course->end_time,
            'room' => $course->room,
            'classes_summary' => implode(', ', $classCodes),
            'offerings_count' => (int) ($course->offerings_count ?? $offerings->count()),
            'enrollments_count' => (int) ($course->enrollments_count ?? 0),
            'studentCount' => (int) ($course->enrollments_count ?? 0),
            'assessments_count' => (int) (($course->assignments_count ?? 0) + ($course->quizzes_count ?? 0) + ($course->exams_count ?? 0)),
            'created_at' => $course->created_at,
            'updated_at' => $course->updated_at,
        ];
    }
}