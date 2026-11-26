<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\ClassRoom;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Program;
use App\Models\Semester;
use App\Rules\ValidYearLevel;
use App\Services\StudentImportValidationService;
use App\Support\YearLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
                'category',
                'programs.department:id,code,name',
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
            'program_ids' => 'nullable|array',
            'program_ids.*' => 'integer|exists:programs,id',
            'course_category_id' => 'nullable|integer|exists:course_categories,id',
            'teacher_id' => 'nullable|integer|exists:teachers,id',
            'academic_year_id' => 'nullable|integer|exists:academic_years,id',
            'semester_id' => 'nullable|integer|exists:semesters,id',
            'academic_year' => 'nullable|string|max:50',
            'semester' => 'nullable|string|max:50',
            'year_level' => ['nullable', new ValidYearLevel()],
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
        ], [], [
            'department_id' => 'faculty',
            'program_id' => 'major',
            'program_ids' => 'majors',
            'course_category_id' => 'course category',
        ])->validate();

        $this->assertCompatibleFacultyAndMajor(
            (int) $validated['department_id'],
            isset($validated['program_id']) ? (int) $validated['program_id'] : null
        );

        $resolvedProgramIds = $this->resolveProgramIds($validated);

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

        $course = DB::transaction(function () use ($validated, $dept, $ayName, $semName, $codeUpper, $courseName, $creditVal, $statusVal, $resolvedProgramIds) {
            $course = Course::create([
                'department_id' => $validated['department_id'],
                'department' => $dept?->name,
                'course_category_id' => $validated['course_category_id'] ?? null,
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
                'year_level' => array_key_exists('year_level', $validated) && filled($validated['year_level'])
                    ? YearLevel::parse($validated['year_level'])
                    : null,
                'status' => $statusVal,
            ]);

            if ($resolvedProgramIds !== null && \Illuminate\Support\Facades\Schema::hasTable('course_program')) {
                $course->programs()->sync($resolvedProgramIds);
            }

            $this->syncCourseOffering($course, $validated);

            return $course;
        });

        $course->load([
            'departmentRelation',
            'category',
            'programs.department:id,code,name',
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
            'category',
            'programs.department:id,code,name',
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
            'program_ids' => 'nullable|array',
            'program_ids.*' => 'integer|exists:programs,id',
            'course_category_id' => 'nullable|integer|exists:course_categories,id',
            'teacher_id' => 'nullable|integer|exists:teachers,id',
            'academic_year_id' => 'nullable|integer|exists:academic_years,id',
            'semester_id' => 'nullable|integer|exists:semesters,id',
            'academic_year' => 'nullable|string|max:50',
            'semester' => 'nullable|string|max:50',
            'year_level' => ['nullable', new ValidYearLevel()],
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
        ], [], [
            'department_id' => 'faculty',
            'program_id' => 'major',
            'program_ids' => 'majors',
            'course_category_id' => 'course category',
        ])->validate();

        $this->assertCompatibleFacultyAndMajor(
            (int) $validated['department_id'],
            isset($validated['program_id']) ? (int) $validated['program_id'] : null
        );

        $resolvedProgramIds = $this->resolveProgramIds($validated, $request->hasAny(['program_id', 'major_id', 'program_ids', 'major_ids']));

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

        DB::transaction(function () use ($course, $validated, $dept, $ayName, $semName, $codeUpper, $courseName, $creditVal, $statusVal, $request, $resolvedProgramIds) {
            $course->update([
                'department_id' => $validated['department_id'],
                'department' => $dept?->name ?? $course->getAttributes()['department'],
                'course_category_id' => array_key_exists('course_category_id', $validated)
                    ? $validated['course_category_id']
                    : $course->course_category_id,
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
                'year_level' => array_key_exists('year_level', $validated)
                    ? (filled($validated['year_level']) ? YearLevel::parse($validated['year_level']) : null)
                    : $course->year_level,
                'status' => $statusVal,
            ]);

            if ($resolvedProgramIds !== null && \Illuminate\Support\Facades\Schema::hasTable('course_program')) {
                $course->programs()->sync($resolvedProgramIds);
            }

            if ($request->hasAny(['program_id', 'major_id', 'program_ids', 'major_ids', 'academic_year_id', 'semester_id'])) {
                $this->syncCourseOffering($course, $validated);
            }
        });

        $fresh = $course->fresh()->load([
            'departmentRelation',
            'category',
            'programs.department:id,code,name',
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

    public function assignMajors(Request $request, Course $course)
    {
        $rawIds = $request->input('program_ids', $request->input('major_ids'));
        if ($rawIds === null && ($request->filled('program_id') || $request->filled('major_id'))) {
            $rawIds = [(int) ($request->input('program_id') ?? $request->input('major_id'))];
        }

        $validated = validator(['program_ids' => $rawIds], [
            'program_ids' => 'present|array',
            'program_ids.*' => 'integer|exists:programs,id',
        ], [], [
            'program_ids' => 'majors',
        ])->validate();

        $ids = array_values(array_unique(array_map('intval', $validated['program_ids'])));
        $course->programs()->sync($ids);

        if (! empty($ids) && ! $course->department_id) {
            $firstProg = Program::with('department')->find($ids[0]);
            if ($firstProg && $firstProg->department_id) {
                $course->update([
                    'department_id' => $firstProg->department_id,
                    'department' => $firstProg->department?->name,
                ]);
            }
        }

        $fresh = $course->fresh()->load([
            'departmentRelation',
            'category',
            'programs.department:id,code,name',
            'teacher.user',
            'offerings.program',
            'offerings.academicYear',
            'offerings.semester',
            'offerings.classRoom',
            'classes',
        ])->loadCount(['enrollments', 'offerings', 'assignments', 'quizzes', 'exams', 'grades']);

        $programsByDept = Program::where('status', 'active')->orderBy('id')->get()->groupBy('department_id');

        return response()->json([
            'message' => 'Course majors assigned successfully.',
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
            'category',
            'programs.department:id,code,name',
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
                    ->orWhereHas('category', function (Builder $cq) use ($q) {
                        $cq->where('name', 'like', "%{$q}%")
                            ->orWhere('code', 'like', "%{$q}%");
                    })
                    ->orWhereHas('programs', function (Builder $pq) use ($q) {
                        $pq->where('programs.name', 'like', "%{$q}%")
                            ->orWhere('programs.code', 'like', "%{$q}%");
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

        if ($request->filled('department_id') || $request->filled('faculty_id')) {
            $query->where('department_id', (int) ($request->input('department_id') ?? $request->input('faculty_id')));
        } elseif (($deptRaw = $request->input('department') ?? $request->input('faculty')) !== null
            && ! in_array(strtolower(trim((string) $deptRaw)), ['', 'all', 'all departments', 'all faculties'], true)) {
            $deptFilter = trim((string) $deptRaw);
            $query->where(function (Builder $sub) use ($deptFilter) {
                $sub->where('department', 'like', "%{$deptFilter}%")
                    ->orWhereHas('departmentRelation', function (Builder $dq) use ($deptFilter) {
                        $dq->where('name', 'like', "%{$deptFilter}%")
                            ->orWhere('code', 'like', "%{$deptFilter}%");
                    });
            });
        }

        if ($request->filled('course_category_id') || $request->filled('category_id')) {
            $query->where('course_category_id', (int) ($request->input('course_category_id') ?? $request->input('category_id')));
        }

        if ($request->filled('program_id') || $request->filled('major_id')) {
            $progId = (int) ($request->input('program_id') ?? $request->input('major_id'));
            $hasPivotRows = \Illuminate\Support\Facades\Schema::hasTable('course_program')
                && DB::table('course_program')->where('program_id', $progId)->exists();
            $prog = Program::find($progId);
            $query->where(function (Builder $sub) use ($progId, $prog, $hasPivotRows) {
                $sub->whereHas('programs', fn (Builder $pq) => $pq->where('programs.id', $progId))
                    ->orWhereHas('offerings', fn (Builder $oq) => $oq->where('program_id', $progId));
                if (! $hasPivotRows && $prog && $prog->department_id) {
                    $sub->orWhere(function (Builder $dSub) use ($prog) {
                        $dSub->where('department_id', $prog->department_id)
                            ->whereDoesntHave('programs');
                    });
                }
            });
        } elseif (($progRaw = $request->input('program') ?? $request->input('major')) !== null
            && ! in_array(strtolower(trim((string) $progRaw)), ['', 'all', 'all programs', 'all majors'], true)) {
            $progFilter = trim((string) $progRaw);
            $query->where(function (Builder $sub) use ($progFilter) {
                $sub->whereHas('programs', function (Builder $pq) use ($progFilter) {
                    $pq->where('programs.name', 'like', "%{$progFilter}%")
                        ->orWhere('programs.code', 'like', "%{$progFilter}%");
                })->orWhereHas('offerings.program', function (Builder $pq) use ($progFilter) {
                    $pq->where('name', 'like', "%{$progFilter}%")
                        ->orWhere('code', 'like', "%{$progFilter}%");
                });
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

        if ($request->filled('year_level') && strtolower((string) $request->input('year_level')) !== 'all') {
            $query->forYearLevel($request->input('year_level'));
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
        if (! isset($data['credit']) && isset($data['credits'])) {
            $data['credit'] = $data['credits'];
        }
        if (empty($data['department_id']) && ! empty($data['faculty_id'])) {
            $data['department_id'] = $data['faculty_id'];
        }
        if (empty($data['department']) && ! empty($data['faculty'])) {
            $data['department'] = $data['faculty'];
        }
        if (empty($data['program_id']) && ! empty($data['major_id'])) {
            $data['program_id'] = $data['major_id'];
        }
        if (! isset($data['program_ids']) && isset($data['major_ids'])) {
            $data['program_ids'] = $data['major_ids'];
        }
        if (isset($data['program_ids']) && is_string($data['program_ids'])) {
            $trimmedIds = trim($data['program_ids']);
            $data['program_ids'] = $trimmedIds === ''
                ? []
                : array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', $trimmedIds) ?: [])));
        }
        if (empty($data['program_id']) && ! empty($data['program_ids']) && is_array($data['program_ids'])) {
            $firstProg = reset($data['program_ids']);
            if ($firstProg) {
                $data['program_id'] = (int) $firstProg;
            }
        }
        if (! array_key_exists('course_category_id', $data) && array_key_exists('category_id', $data)) {
            $data['course_category_id'] = $data['category_id'];
        }
        if (array_key_exists('course_category_id', $data) && ($data['course_category_id'] === '' || $data['course_category_id'] === 0 || $data['course_category_id'] === '0')) {
            $data['course_category_id'] = null;
        }
        if (empty($data['department_id']) && !empty($data['department']) && is_string($data['department'])) {
            $dept = Department::where('name', $data['department'])
                ->orWhere('code', $data['department'])
                ->first();
            if ($dept) {
                $data['department_id'] = $dept->id;
            }
        }
        if (empty($data['department_id']) && ! empty($data['program_id'])) {
            $progDeptId = Program::where('id', (int) $data['program_id'])->value('department_id');
            if ($progDeptId) {
                $data['department_id'] = (int) $progDeptId;
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

    /**
     * @return int[]|null  null means do not touch pivot (for updates where no major fields were sent)
     */
    private function resolveProgramIds(array $validated, bool $explicitlyProvided = true): ?array
    {
        if (array_key_exists('program_ids', $validated) && is_array($validated['program_ids'])) {
            return array_values(array_unique(array_map('intval', $validated['program_ids'])));
        }
        if (! empty($validated['program_id'])) {
            return [(int) $validated['program_id']];
        }

        return $explicitlyProvided ? null : null;
    }

    private function assertCompatibleFacultyAndMajor(int $departmentId, ?int $programId): void
    {
        if (! $programId) {
            return;
        }
        $program = Program::find($programId);
        if ($program && $program->department_id
            && ! StudentImportValidationService::areDepartmentAndProgramCompatible((int) $program->department_id, $departmentId)) {
            throw ValidationException::withMessages([
                'program_id' => ['Selected major does not belong to the selected faculty.'],
            ]);
        }
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

        $classRoomQuery = ClassRoom::where('department_id', $course->department_id);
        if ($course->year_level !== null) {
            $classRoomQuery->where('year_level', (int) $course->year_level);
        }
        $classRoom = $classRoomQuery->orderByDesc('id')->first()
            ?? ClassRoom::where('department_id', $course->department_id)->orderByDesc('id')->first();

        $studyYear = YearLevel::parse($validated['year_level'] ?? $course->year_level) ?? $classRoom?->year_level ?? 1;

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
                    'study_year' => $studyYear,
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

        $assignedPrograms = $course->relationLoaded('programs') ? $course->programs : collect();
        $program = $assignedPrograms->first() ?? $activeOffering?->program;
        if ((!$program || ($course->department_id && $program->department_id != $course->department_id && $assignedPrograms->isEmpty()))
            && $course->department_id && isset($programsByDept[$course->department_id])) {
            $program = $programsByDept[$course->department_id]->first();
        }
        $programId = $program?->id;
        $programName = $program?->name ?? '';
        $programCode = $program?->code ?? '';

        $majorsList = $assignedPrograms->map(function (Program $p) {
            $pDept = $p->relationLoaded('department') ? $p->department : null;

            return [
                'id' => (int) $p->id,
                'code' => (string) $p->code,
                'name' => (string) $p->name,
                'department_id' => (int) $p->department_id,
                'faculty_id' => (int) $p->department_id,
                'faculty_name' => $pDept?->name,
                'faculty_code' => $pDept?->code,
            ];
        })->values()->all();

        if (empty($majorsList) && $program) {
            $majorsList = [[
                'id' => (int) $program->id,
                'code' => (string) $program->code,
                'name' => (string) $program->name,
                'department_id' => (int) $program->department_id,
                'faculty_id' => (int) $program->department_id,
                'faculty_name' => $deptName,
                'faculty_code' => $deptCode,
            ]];
        }
        $programIds = array_values(array_map(fn (array $m) => (int) $m['id'], $majorsList));
        $majorsSummary = implode(', ', array_map(fn (array $m) => $m['name'], $majorsList));

        $category = $course->relationLoaded('category') ? $course->getRelation('category') : null;

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
        $yearLevelInt = YearLevel::parse($course->year_level ?? $activeOffering?->study_year);

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
            'year_level' => $yearLevelInt,
            'year_level_code' => YearLevel::code($yearLevelInt),
            'year_level_name' => YearLevel::name($yearLevelInt),
            'year_level_khmer' => YearLevel::khmer($yearLevelInt),
            'year_level_label' => YearLevel::label($yearLevelInt),
            'study_years' => $offerings->pluck('study_year')->filter()->unique()->sort()->values()->all(),
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
            'major_id' => $programId,
            'major_name' => $programName,
            'major_code' => $programCode,
            'program_ids' => $programIds,
            'major_ids' => $programIds,
            'programs' => $majorsList,
            'majors' => $majorsList,
            'majors_summary' => $majorsSummary,
            'course_category_id' => $course->course_category_id !== null ? (int) $course->course_category_id : null,
            'course_category_name' => $category?->name,
            'course_category_code' => $category?->code,
            'course_category' => $category ? [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
                'code' => (string) ($category->code ?? ''),
            ] : null,
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