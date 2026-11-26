<?php

namespace App\Http\Controllers;

use App\Models\ClassRoom;
use App\Models\ClassSchedule;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Semester;
use App\Models\Student;
use App\Services\TeacherAuthorizationService;
use App\Support\YearLevel;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClassScheduleController extends Controller
{
    private const DAY_MAP = [
        'mon' => 'Monday',
        'monday' => 'Monday',
        'tue' => 'Tuesday',
        'tues' => 'Tuesday',
        'tuesday' => 'Tuesday',
        'wed' => 'Wednesday',
        'wednesday' => 'Wednesday',
        'thu' => 'Thursday',
        'thur' => 'Thursday',
        'thurs' => 'Thursday',
        'thursday' => 'Thursday',
        'fri' => 'Friday',
        'friday' => 'Friday',
        'sat' => 'Saturday',
        'saturday' => 'Saturday',
        'sun' => 'Sunday',
        'sunday' => 'Sunday',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = ClassSchedule::with([
            'academicYear',
            'semester',
            'department',
            'classRoom.building',
            'classRoom.floor',
            'building',
            'floor',
            'course',
            'courseOffering',
            'teacher.user',
        ]);

        if ($request->filled('academic_year_id')) {
            $query->where('academic_year_id', (int) $request->academic_year_id);
        }
        if ($request->filled('semester_id')) {
            $query->where('semester_id', (int) $request->semester_id);
        }
        if ($request->filled('class_id')) {
            $query->where('class_id', (int) $request->class_id);
        }
        if ($request->filled('year_level') && strtolower((string) $request->year_level) !== 'all') {
            $yearLevelInput = $request->year_level;
            $query->where(function ($sub) use ($yearLevelInput) {
                $sub->whereHas('classRoom', fn ($c) => $c->forYearLevel($yearLevelInput))
                    ->orWhereHas('course', fn ($c) => $c->forYearLevel($yearLevelInput));
            });
        }
        if ($request->filled('course_id')) {
            $query->where('course_id', (int) $request->course_id);
        }
        if ($request->filled('teacher_id')) {
            $query->where('teacher_id', (int) $request->teacher_id);
        }
        if ($request->filled('building_id')) {
            $query->where('building_id', (int) $request->building_id);
        }
        if ($request->filled('floor_id')) {
            $query->where('floor_id', (int) $request->floor_id);
        }
        if ($request->filled('day_of_week')) {
            $day = $this->normalizeDayList((string) $request->day_of_week)[0] ?? $request->day_of_week;
            $query->where('day_of_week', 'like', "%{$day}%");
        }
        if ($request->filled('status') && strtolower($request->status) !== 'all') {
            $query->where('status', strtolower($request->status));
        }
        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('day_of_week', 'like', "%{$s}%")
                    ->orWhereHas('classRoom', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
                    ->orWhereHas('course', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
                    ->orWhereHas('teacher', fn ($t) => $t->where('first_name', 'like', "%{$s}%")->orWhere('last_name', 'like', "%{$s}%"));
            });
        }

        $schedules = $this->sortSchedulesChronologically($query->get())->map(function (ClassSchedule $s) {
            $lvl = YearLevel::parse($s->classRoom?->year_level ?? $s->courseOffering?->study_year ?? $s->course?->year_level);
            $s->setAttribute('year_level', $lvl);
            $s->setAttribute('year_level_code', YearLevel::code($lvl));
            $s->setAttribute('year_level_name', YearLevel::name($lvl));
            $s->setAttribute('year_level_label', YearLevel::label($lvl));

            return $s;
        });

        return response()->json([
            'message' => 'Class schedules retrieved successfully.',
            'data' => $schedules,
        ], 200);
    }

    public function mySchedules(Request $request, TeacherAuthorizationService $authService): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $query = ClassSchedule::with([
            'academicYear',
            'semester',
            'department',
            'classRoom.building',
            'classRoom.floor',
            'building',
            'floor',
            'course',
            'teacher.user',
        ])->where('status', 'active');

        if ($user->role === 'teacher') {
            $teacher = $authService->resolveTeacher($user);
            if (! $teacher) {
                return response()->json(['message' => 'Teacher profile not found.'], 404);
            }
            $offeringIds = $authService->getAuthorizedOfferingIds($teacher);
            $query->where(function ($q) use ($teacher, $offeringIds) {
                $q->where('teacher_id', $teacher->id);
                if (! empty($offeringIds)) {
                    $q->orWhereIn('course_offering_id', $offeringIds);
                }
            });
        } elseif ($user->role === 'student') {
            $student = $user->student;
            if (! $student) {
                return response()->json(['message' => 'Student profile not found.'], 404);
            }
            $enrollments = CourseEnrollment::where('student_id', $student->id)
                ->whereIn('status', ['enrolled', 'active'])
                ->get(['course_id', 'course_offering_id']);
            $courseIds = $enrollments->pluck('course_id')->filter()->unique()->values()->all();
            $offeringIds = $enrollments->pluck('course_offering_id')->filter()->unique()->values()->all();

            $query->where(function ($q) use ($student, $courseIds, $offeringIds) {
                $q->whereRaw('1 = 0');
                if ($student->class_id) {
                    $q->orWhere('class_id', $student->class_id);
                }
                if (! empty($offeringIds)) {
                    $q->orWhereIn('course_offering_id', $offeringIds);
                }
                if (! empty($courseIds)) {
                    $q->orWhereIn('course_id', $courseIds);
                }
            });
        }

        if ($request->filled('semester_id')) {
            $query->where('semester_id', (int) $request->semester_id);
        }
        if ($request->filled('year_level') && strtolower((string) $request->year_level) !== 'all') {
            $yearLevelInput = $request->year_level;
            $query->where(function ($sub) use ($yearLevelInput) {
                $sub->whereHas('classRoom', fn ($c) => $c->forYearLevel($yearLevelInput))
                    ->orWhereHas('course', fn ($c) => $c->forYearLevel($yearLevelInput));
            });
        }

        $schedules = $this->sortSchedulesChronologically($query->get())->map(function (ClassSchedule $s) {
            $lvl = YearLevel::parse($s->classRoom?->year_level ?? $s->courseOffering?->study_year ?? $s->course?->year_level);
            $s->setAttribute('year_level', $lvl);
            $s->setAttribute('year_level_code', YearLevel::code($lvl));
            $s->setAttribute('year_level_name', YearLevel::name($lvl));
            $s->setAttribute('year_level_label', YearLevel::label($lvl));

            return $s;
        });

        return response()->json([
            'message' => 'My schedules retrieved successfully.',
            'data' => $schedules,
        ], 200);
    }

    /**
     * @param \Illuminate\Support\Collection<int, ClassSchedule> $schedules
     */
    protected function sortSchedulesChronologically($schedules): \Illuminate\Support\Collection
    {
        $dayOrder = [
            'Monday' => 1,
            'Tuesday' => 2,
            'Wednesday' => 3,
            'Thursday' => 4,
            'Friday' => 5,
            'Saturday' => 6,
            'Sunday' => 7,
        ];

        return $schedules->sortBy(function (ClassSchedule $s) use ($dayOrder) {
            $firstDay = $this->normalizeDayList((string) $s->day_of_week)[0] ?? $s->day_of_week;
            $rank = $dayOrder[$firstDay] ?? 99;
            return sprintf('%02d_%s_%06d', $rank, (string) $s->start_time, $s->id);
        })->values();
    }

    public function store(Request $request): JsonResponse
    {
        return $this->saveSchedule($request);
    }

    public function update(Request $request, ClassSchedule $classSchedule): JsonResponse
    {
        return $this->saveSchedule($request, $classSchedule);
    }

    public function destroy(ClassSchedule $classSchedule): JsonResponse
    {
        $classSchedule->delete();

        return response()->json([
            'message' => 'Class schedule deleted successfully.',
        ], 200);
    }

    protected function saveSchedule(Request $request, ?ClassSchedule $existing = null): JsonResponse
    {
        $reqRule = $existing ? 'sometimes|required' : 'required';

        $validated = $request->validate([
            'academic_year_id' => 'nullable|integer|exists:academic_years,id',
            'semester_id' => 'nullable|integer|exists:semesters,id',
            'department_id' => 'nullable|integer|exists:departments,id',
            'class_id' => "{$reqRule}|integer|exists:classes,id",
            'course_id' => "{$reqRule}|integer|exists:courses,id",
            'course_offering_id' => 'nullable|integer|exists:course_offerings,id',
            'teacher_id' => 'nullable|integer|exists:teachers,id',
            'day_of_week' => "{$reqRule}|string|max:30",
            'start_time' => "{$reqRule}|string|max:20",
            'end_time' => "{$reqRule}|string|max:20",
            'status' => 'nullable|string|in:active,cancelled',
            'notes' => 'nullable|string|max:1000',
        ]);

        $rawStart = (string) ($validated['start_time'] ?? ($existing?->start_time ?? ''));
        $rawEnd = (string) ($validated['end_time'] ?? ($existing?->end_time ?? ''));
        $startTime = $this->normalizeTime($rawStart);
        $endTime = $this->normalizeTime($rawEnd);
        if (! $startTime || ! $endTime) {
            return response()->json([
                'message' => 'Invalid time format. Use HH:MM (e.g. 18:00 or 06:00 PM).',
                'errors' => ['time' => ['Invalid start or end time format.']],
            ], 422);
        }

        if ($startTime >= $endTime) {
            return response()->json([
                'message' => 'End time must be after start time.',
                'errors' => ['end_time' => ['End time must be strictly after start time.']],
            ], 422);
        }

        $rawDay = (string) ($validated['day_of_week'] ?? ($existing?->day_of_week ?? ''));
        $days = $this->normalizeDayList($rawDay);
        if (empty($days)) {
            return response()->json([
                'message' => 'Invalid day_of_week provided.',
                'errors' => ['day_of_week' => ['Specify a valid day such as Monday..Sunday.']],
            ], 422);
        }
        $normalizedDayString = implode(' / ', $days);

        $status = $validated['status'] ?? ($existing?->status ?? 'active');
        $resolvedClassId = (int) ($validated['class_id'] ?? $existing?->class_id);
        $resolvedCourseId = (int) ($validated['course_id'] ?? $existing?->course_id);
        $classRoom = ClassRoom::with(['building', 'floor'])->findOrFail($resolvedClassId);
        $course = Course::findOrFail($resolvedCourseId);
        $teacherId = array_key_exists('teacher_id', $validated)
            ? ($validated['teacher_id'] !== null ? (int) $validated['teacher_id'] : null)
            : $existing?->teacher_id;
        $semesterId = array_key_exists('semester_id', $validated)
            ? ($validated['semester_id'] !== null ? (int) $validated['semester_id'] : null)
            : $existing?->semester_id;

        if ($status === 'active') {
            $conflictError = $this->detectConflict(
                (int) $classRoom->id,
                $teacherId,
                $semesterId,
                $days,
                $startTime,
                $endTime,
                $existing?->id
            );
            if ($conflictError !== null) {
                return response()->json([
                    'message' => $conflictError,
                    'errors' => ['conflict' => [$conflictError]],
                ], 422);
            }
        }

        $schedule = DB::transaction(function () use (
            $validated,
            $existing,
            $classRoom,
            $course,
            $teacherId,
            $semesterId,
            $normalizedDayString,
            $startTime,
            $endTime,
            $status
        ) {
            $academicYearId = $validated['academic_year_id'] ?? $classRoom->academic_year_id;
            $departmentId = $validated['department_id'] ?? $classRoom->department_id ?? $course->department_id;

            $offeringId = $validated['course_offering_id'] ?? null;
            $effectiveSemesterId = $semesterId
                ?: Semester::where('status', 'active')->value('id')
                ?: Semester::orderByDesc('id')->value('id');
            if (! $offeringId && $effectiveSemesterId) {
                $offering = CourseOffering::firstOrCreate(
                    [
                        'course_id' => $course->id,
                        'class_id' => $classRoom->id,
                        'semester_id' => $effectiveSemesterId,
                    ],
                    [
                        'academic_year_id' => $academicYearId,
                        'status' => 'active',
                    ]
                );
                $offeringId = $offering->id;
            }

            if ($offeringId && $teacherId) {
                DB::table('course_offering_teachers')->updateOrInsert(
                    ['course_offering_id' => $offeringId, 'teacher_id' => $teacherId],
                    ['role' => 'primary', 'status' => 'active', 'assigned_at' => now(), 'updated_at' => now(), 'created_at' => now()]
                );
            }

            if ($offeringId && $effectiveSemesterId) {
                $classStudents = Student::where('class_id', $classRoom->id)->get(['id', 'enrollment_date', 'status']);
                foreach ($classStudents as $stu) {
                    if (in_array(strtolower((string) $stu->status), ['inactive', 'suspended', 'graduated', 'dropped'], true)) {
                        continue;
                    }
                    CourseEnrollment::updateOrCreate(
                        ['student_id' => $stu->id, 'course_offering_id' => $offeringId],
                        [
                            'course_id' => $course->id,
                            'semester_id' => $effectiveSemesterId,
                            'enrollment_date' => $stu->enrollment_date?->toDateString() ?? now()->toDateString(),
                            'status' => 'enrolled',
                        ]
                    );
                }
            }

            if ($teacherId) {
                DB::table('class_teacher')->updateOrInsert(
                    ['class_id' => $classRoom->id, 'teacher_id' => $teacherId, 'course_id' => $course->id],
                    ['updated_at' => now(), 'created_at' => now()]
                );
            }

            $payload = [
                'academic_year_id' => $academicYearId,
                'semester_id' => $semesterId,
                'department_id' => $departmentId,
                'class_id' => $classRoom->id,
                'building_id' => $classRoom->building_id,
                'floor_id' => $classRoom->floor_id,
                'course_id' => $course->id,
                'course_offering_id' => $offeringId,
                'teacher_id' => $teacherId,
                'day_of_week' => $normalizedDayString,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'status' => $status,
                'notes' => $validated['notes'] ?? null,
            ];

            if ($existing) {
                $existing->update($payload);
                return $existing->fresh();
            }

            return ClassSchedule::create($payload);
        });

        return response()->json([
            'message' => $existing ? 'Class schedule updated successfully.' : 'Class schedule created successfully.',
            'data' => $schedule->load([
                'academicYear',
                'semester',
                'department',
                'classRoom.building',
                'classRoom.floor',
                'building',
                'floor',
                'course',
                'courseOffering',
                'teacher.user',
            ]),
        ], $existing ? 200 : 201);
    }

    protected function detectConflict(
        int $classId,
        ?int $teacherId,
        ?int $semesterId,
        array $days,
        string $startTime,
        string $endTime,
        ?int $ignoreId = null
    ): ?string {
        $candidates = ClassSchedule::with(['classRoom', 'course', 'teacher'])
            ->where('status', 'active')
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where(function ($q) use ($classId, $teacherId) {
                $q->where('class_id', $classId);
                if ($teacherId) {
                    $q->orWhere('teacher_id', $teacherId);
                }
            })
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->get();

        foreach ($candidates as $cand) {
            if ($semesterId && $cand->semester_id && (int) $cand->semester_id !== $semesterId) {
                continue;
            }
            $candDays = $this->normalizeDayList((string) $cand->day_of_week);
            $sharedDays = array_values(array_intersect($days, $candDays));
            if (empty($sharedDays)) {
                continue;
            }

            $slotLabel = $sharedDays[0] . ' ' . substr((string) $cand->start_time, 0, 5) . '-' . substr((string) $cand->end_time, 0, 5);
            if ((int) $cand->class_id === $classId) {
                $className = $cand->classRoom?->name ?? 'selected class';
                $courseName = $cand->course?->name ?? 'another course';
                return "Schedule conflict: {$className} is already booked for {$courseName} on {$slotLabel}.";
            }
            if ($teacherId && (int) $cand->teacher_id === $teacherId) {
                $teacherName = trim(($cand->teacher?->first_name ?? '') . ' ' . ($cand->teacher?->last_name ?? ''));
                $className = $cand->classRoom?->name ?? 'another class';
                return "Schedule conflict: Teacher {$teacherName} is already scheduled in {$className} on {$slotLabel}.";
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeDayList(string $raw): array
    {
        $parts = preg_split('/[,\/&]+/', strtolower(trim($raw))) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $key = trim($part);
            if ($key !== '' && isset(self::DAY_MAP[$key])) {
                $canonical = self::DAY_MAP[$key];
                if (! in_array($canonical, $out, true)) {
                    $out[] = $canonical;
                }
            }
        }
        return $out;
    }

    protected function normalizeTime(string $raw): ?string
    {
        $trimmed = trim($raw);
        foreach (['H:i:s', 'H:i', 'g:i A', 'h:i A', 'g:i a', 'h:i a'] as $fmt) {
            try {
                $dt = Carbon::createFromFormat($fmt, $trimmed);
                if ($dt !== false) {
                    return $dt->format('H:i:s');
                }
            } catch (\Throwable) {
                // try next format
            }
        }
        return null;
    }
}
