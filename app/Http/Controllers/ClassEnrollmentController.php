<?php

namespace App\Http\Controllers;

use App\Models\ClassRoom;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin Bulk Class Enrollment & CSV/Excel Enrollment Import.
 * Reuses existing `classes` and `students` tables without creating duplicate tables or student accounts.
 */
class ClassEnrollmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Student::query()
            ->with(['department', 'program', 'classRoom.academicYear'])
            ->whereNotNull('class_id');

        if ($request->filled('class_id')) {
            $query->where('class_id', (int) $request->input('class_id'));
        }

        if ($request->filled('search')) {
            $q = trim((string) $request->input('search'));
            $query->where(function ($sub) use ($q) {
                $sub->where('student_code', 'like', "%{$q}%")
                    ->orWhere('english_name', 'like', "%{$q}%")
                    ->orWhere('khmer_name', 'like', "%{$q}%")
                    ->orWhere('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhereHas('classRoom', function ($cq) use ($q) {
                        $cq->where('code', 'like', "%{$q}%")
                            ->orWhere('name', 'like', "%{$q}%");
                    });
            });
        }

        $students = $query->latest('updated_at')->limit(200)->get();

        $data = $students->map(function (Student $s) {
            $fullName = $this->resolveStudentName($s);

            return [
                'id' => $s->id,
                'student_id' => $s->student_code,
                'student_code' => $s->student_code,
                'student_name' => $fullName,
                'class_id' => $s->class_id,
                'class_code' => $s->classRoom?->code ?? '',
                'class_name' => $s->classRoom?->name ?? '',
                'academic_year' => $s->classRoom?->academicYear?->name ?? '',
                'department' => $s->department?->name ?? '',
                'program' => $s->program?->name ?? ($s->major ?? ''),
                'year_level' => $s->year_level,
                'enrollment_date' => $s->enrollment_date?->toDateString() ?? $s->updated_at?->toDateString(),
                'status' => ucfirst((string) ($s->status ?: 'enrolled')),
            ];
        })->values();

        return response()->json([
            'message' => 'Class enrollments retrieved successfully.',
            'data' => $data,
        ]);
    }

    public function lookups(): JsonResponse
    {
        $classes = ClassRoom::with(['department', 'academicYear', 'building', 'floor', 'course', 'teacher'])
            ->withCount('students')
            ->orderByRaw("CASE WHEN code IN ('CLASS-A','CLASS-B','CLASS-C','CLASS-D','CLASS-E') THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN code IN ('CLASS-A','CLASS-B','CLASS-C','CLASS-D','CLASS-E') THEN code ELSE '' END")
            ->orderByDesc('id')
            ->get()
            ->map(function (ClassRoom $c) {
                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'code' => $c->code,
                    'year_level' => $c->year_level,
                    'capacity' => $c->capacity ?? 40,
                    'status' => $c->status ?? 'active',
                    'department_id' => $c->department_id,
                    'department_name' => $c->department?->name ?? '',
                    'academic_year_id' => $c->academic_year_id,
                    'academic_year' => $c->academicYear?->name ?? '',
                    'building_id' => $c->building_id,
                    'building_name' => $c->building?->name ?? '',
                    'floor_id' => $c->floor_id,
                    'floor_name' => $c->floor?->name ?? '',
                    'course_id' => $c->course_id,
                    'course_name' => $c->course?->name ?? '',
                    'teacher_id' => $c->teacher_id,
                    'teacher_name' => $c->teacher ? trim(($c->teacher->first_name ?? '') . ' ' . ($c->teacher->last_name ?? '')) : '',
                    'students_count' => $c->students_count ?? 0,
                ];
            })->values();

        return response()->json([
            'message' => 'Enrollment lookups retrieved successfully.',
            'data' => [
                'classes' => $classes,
                'departments' => DB::table('departments')->orderBy('name')->get(['id', 'code', 'name']),
                'programs' => DB::table('programs')->where('status', 'active')->orderBy('name')
                    ->get(['id', 'department_id', 'code', 'name']),
                'academic_years' => DB::table('academic_years')->orderByDesc('id')->get(['id', 'name', 'status']),
                'semesters' => DB::table('semesters')->orderBy('academic_year_id')->orderBy('semester_number')
                    ->get(['id', 'academic_year_id', 'name', 'semester_number']),
            ],
        ]);
    }

    public function studentsForClass(Request $request, ClassRoom $classRoom): JsonResponse
    {
        $query = Student::query()
            ->with(['department', 'program', 'classRoom']);

        if ($request->filled('search')) {
            $q = trim((string) $request->input('search'));
            $query->where(function ($sub) use ($q) {
                $sub->where('student_code', 'like', "%{$q}%")
                    ->orWhere('english_name', 'like', "%{$q}%")
                    ->orWhere('khmer_name', 'like', "%{$q}%")
                    ->orWhere('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('major', 'like', "%{$q}%")
                    ->orWhereHas('department', fn ($dq) => $dq->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%"))
                    ->orWhereHas('program', fn ($pq) => $pq->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%"));
            });
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', (int) $request->input('department_id'));
        }

        if ($request->filled('program_id')) {
            $query->where('program_id', (int) $request->input('program_id'));
        }

        if ($request->filled('year_level')) {
            $query->where('year_level', (int) $request->input('year_level'));
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 50)));
        $paginator = $query->orderBy('student_code')->paginate($perPage);

        $classActive = strtolower((string) ($classRoom->status ?? 'active')) === 'active';

        $items = $paginator->getCollection()->map(function (Student $s) use ($classRoom, $classActive) {
            $alreadyInSelected = (int) $s->class_id === (int) $classRoom->id;
            $statusLower = strtolower((string) ($s->status ?? 'enrolled'));
            $studentActive = ! in_array($statusLower, ['inactive', 'suspended', 'graduated', 'dropped'], true);

            $ineligibleReason = null;
            if (! $classActive) {
                $ineligibleReason = 'Selected class is inactive.';
            } elseif ($alreadyInSelected) {
                $ineligibleReason = 'Already enrolled in this class.';
            } elseif (! $studentActive) {
                $ineligibleReason = 'Student status is ' . ($s->status ?: 'inactive') . '.';
            }

            $isEligible = $classActive && ! $alreadyInSelected && $studentActive;

            return [
                'id' => $s->id,
                'student_code' => $s->student_code,
                'full_name' => $this->resolveStudentName($s),
                'khmer_name' => $s->khmer_name ?? '',
                'english_name' => $s->english_name ?? '',
                'department_id' => $s->department_id,
                'department_name' => $s->department?->name ?? '',
                'program_id' => $s->program_id,
                'program_name' => $s->program?->name ?? ($s->major ?? ''),
                'year_level' => $s->year_level,
                'status' => $s->status ?? 'enrolled',
                'current_class_id' => $s->class_id,
                'current_class_code' => $s->classRoom?->code ?? '',
                'current_class_name' => $s->classRoom?->name ?? '',
                'already_enrolled_in_selected' => $alreadyInSelected,
                'is_eligible' => $isEligible,
                'ineligible_reason' => $ineligibleReason,
            ];
        })->values();

        return response()->json([
            'message' => 'Students retrieved successfully.',
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function bulkEnroll(Request $request, ClassRoom $classRoom): JsonResponse
    {
        if (strtolower((string) ($classRoom->status ?? 'active')) !== 'active') {
            return response()->json([
                'message' => 'Cannot enroll students into an inactive class.',
                'errors' => ['class_id' => ['Selected class is inactive.']],
            ], 422);
        }

        $validated = $request->validate([
            'student_ids' => 'required|array|min:1',
            'student_ids.*' => 'required',
        ]);

        $rawIds = array_values(array_unique($validated['student_ids']));
        $enrolled = [];
        $skipped = [];
        $failed = [];

        DB::transaction(function () use ($rawIds, $classRoom, &$enrolled, &$skipped, &$failed) {
            foreach ($rawIds as $identifier) {
                $student = is_numeric($identifier)
                    ? Student::find((int) $identifier)
                    : Student::where('student_code', trim((string) $identifier))->first();

                if (! $student) {
                    $failed[] = [
                        'identifier' => (string) $identifier,
                        'reason' => 'Student record not found.',
                    ];
                    continue;
                }

                if ((int) $student->class_id === (int) $classRoom->id) {
                    $skipped[] = [
                        'id' => $student->id,
                        'student_code' => $student->student_code,
                        'full_name' => $this->resolveStudentName($student),
                        'reason' => 'Already enrolled in ' . $classRoom->code . '.',
                    ];
                    continue;
                }

                $statusLower = strtolower((string) ($student->status ?? 'enrolled'));
                if (in_array($statusLower, ['inactive', 'suspended', 'graduated', 'dropped'], true)) {
                    $failed[] = [
                        'id' => $student->id,
                        'student_code' => $student->student_code,
                        'full_name' => $this->resolveStudentName($student),
                        'reason' => 'Student status (' . $student->status . ') is not eligible for enrollment.',
                    ];
                    continue;
                }

                $student->class_id = $classRoom->id;
                if (empty($student->enrollment_date)) {
                    $student->enrollment_date = now()->toDateString();
                }
                if (empty($student->status)) {
                    $student->status = 'enrolled';
                }
                $student->save();

                // Sync course_enrollments for all active course offerings of this class
                $offerings = CourseOffering::where('class_id', $classRoom->id)->get();
                $fallbackSemesterId = Semester::where('status', 'active')->value('id') ?? Semester::orderByDesc('id')->value('id');
                foreach ($offerings as $offering) {
                    $semId = $offering->semester_id ?? $fallbackSemesterId;
                    if (! $semId || ! $offering->course_id) {
                        continue;
                    }
                    CourseEnrollment::updateOrCreate(
                        [
                            'student_id' => $student->id,
                            'course_offering_id' => $offering->id,
                        ],
                        [
                            'course_id' => $offering->course_id,
                            'semester_id' => $semId,
                            'enrollment_date' => $student->enrollment_date?->toDateString() ?? now()->toDateString(),
                            'status' => 'enrolled',
                        ]
                    );
                }

                $enrolled[] = [
                    'id' => $student->id,
                    'student_code' => $student->student_code,
                    'full_name' => $this->resolveStudentName($student),
                    'class_code' => $classRoom->code,
                ];
            }
        });

        return response()->json([
            'message' => count($enrolled) . ' student(s) enrolled into ' . $classRoom->code . '.',
            'data' => [
                'class_id' => $classRoom->id,
                'class_code' => $classRoom->code,
                'class_name' => $classRoom->name,
                'enrolled_count' => count($enrolled),
                'skipped_count' => count($skipped),
                'failed_count' => count($failed),
                'enrolled' => $enrolled,
                'skipped' => $skipped,
                'failed' => $failed,
            ],
        ]);
    }

    public function templateCsv(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['student_id', 'class_code']);
            fputcsv($out, ['STD001', 'BSIT-4A']);
            fputcsv($out, ['STD002', 'BSIT-4A']);
            fclose($out);
        }, 'class_enrollment_template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function importPreview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'extensions:csv,xlsx,xls', 'mimes:csv,txt,xlsx,xls'],
        ]);

        try {
            $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
            $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Unable to read enrollment file: ' . $e->getMessage()], 422);
        }

        if (count($rows) < 2) {
            return response()->json(['message' => 'The uploaded file has no data rows.'], 422);
        }

        $header = array_map(fn ($v) => strtolower(trim((string) $v)), $rows[0]);
        $studentCol = array_search('student_id', $header, true);
        if ($studentCol === false) {
            $studentCol = array_search('student_code', $header, true);
        }
        $classCol = array_search('class_code', $header, true);

        if ($studentCol === false || $classCol === false) {
            return response()->json([
                'message' => 'Missing required columns. Template must include student_id and class_code headers.',
            ], 422);
        }

        $validRows = [];
        $invalidRows = [];
        $seenPairs = [];

        foreach (array_slice($rows, 1) as $idx => $row) {
            $rowNumber = $idx + 2;
            $studentCode = trim((string) ($row[$studentCol] ?? ''));
            $classCode = strtoupper(trim((string) ($row[$classCol] ?? '')));

            if ($studentCode === '' && $classCode === '') {
                continue;
            }

            if ($studentCode === '' || $classCode === '') {
                $invalidRows[] = [
                    'row_number' => $rowNumber,
                    'student_id' => $studentCode,
                    'class_code' => $classCode,
                    'error' => 'Both student_id and class_code are required.',
                ];
                continue;
            }

            $pairKey = strtolower($studentCode) . '|' . strtolower($classCode);
            if (isset($seenPairs[$pairKey])) {
                $invalidRows[] = [
                    'row_number' => $rowNumber,
                    'student_id' => $studentCode,
                    'class_code' => $classCode,
                    'error' => 'Duplicate enrollment row within the uploaded file.',
                ];
                continue;
            }
            $seenPairs[$pairKey] = true;

            $student = Student::where('student_code', $studentCode)->first();
            if (! $student) {
                $invalidRows[] = [
                    'row_number' => $rowNumber,
                    'student_id' => $studentCode,
                    'class_code' => $classCode,
                    'error' => 'Invalid Student ID (student does not exist).',
                ];
                continue;
            }

            $classRoom = ClassRoom::whereRaw('UPPER(code) = ?', [$classCode])->first();
            if (! $classRoom) {
                $invalidRows[] = [
                    'row_number' => $rowNumber,
                    'student_id' => $studentCode,
                    'class_code' => $classCode,
                    'error' => 'Invalid Class Code (class does not exist).',
                ];
                continue;
            }

            if (strtolower((string) ($classRoom->status ?? 'active')) !== 'active') {
                $invalidRows[] = [
                    'row_number' => $rowNumber,
                    'student_id' => $studentCode,
                    'class_code' => $classCode,
                    'error' => 'Cannot enroll into an inactive class.',
                ];
                continue;
            }

            if ((int) $student->class_id === (int) $classRoom->id) {
                $invalidRows[] = [
                    'row_number' => $rowNumber,
                    'student_id' => $studentCode,
                    'class_code' => $classCode,
                    'error' => 'Student is already enrolled in ' . $classRoom->code . '.',
                ];
                continue;
            }

            $statusLower = strtolower((string) ($student->status ?? 'enrolled'));
            if (in_array($statusLower, ['inactive', 'suspended', 'graduated', 'dropped'], true)) {
                $invalidRows[] = [
                    'row_number' => $rowNumber,
                    'student_id' => $studentCode,
                    'class_code' => $classCode,
                    'error' => 'Student status (' . $student->status . ') is not eligible.',
                ];
                continue;
            }

            $validRows[] = [
                'row_number' => $rowNumber,
                'student_db_id' => $student->id,
                'student_id' => $student->student_code,
                'student_name' => $this->resolveStudentName($student),
                'class_id' => $classRoom->id,
                'class_code' => $classRoom->code,
                'class_name' => $classRoom->name,
            ];
        }

        return response()->json([
            'message' => 'Enrollment file validated.',
            'data' => [
                'total_rows' => count($validRows) + count($invalidRows),
                'valid_count' => count($validRows),
                'invalid_count' => count($invalidRows),
                'valid_rows' => $validRows,
                'invalid_rows' => $invalidRows,
            ],
        ]);
    }

    public function importConfirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.student_id' => 'required|string',
            'rows.*.class_code' => 'required|string',
        ]);

        $enrolled = [];
        $failed = [];

        DB::transaction(function () use ($validated, &$enrolled, &$failed) {
            foreach ($validated['rows'] as $row) {
                $studentCode = trim((string) $row['student_id']);
                $classCode = strtoupper(trim((string) $row['class_code']));

                $student = Student::where('student_code', $studentCode)->first();
                $classRoom = ClassRoom::whereRaw('UPPER(code) = ?', [$classCode])->first();

                if (! $student || ! $classRoom) {
                    $failed[] = [
                        'student_id' => $studentCode,
                        'class_code' => $classCode,
                        'reason' => 'Student or Class not found.',
                    ];
                    continue;
                }

                if (strtolower((string) ($classRoom->status ?? 'active')) !== 'active') {
                    $failed[] = [
                        'student_id' => $studentCode,
                        'class_code' => $classCode,
                        'reason' => 'Class is inactive.',
                    ];
                    continue;
                }

                if ((int) $student->class_id === (int) $classRoom->id) {
                    $failed[] = [
                        'student_id' => $studentCode,
                        'class_code' => $classCode,
                        'reason' => 'Already enrolled in ' . $classRoom->code . '.',
                    ];
                    continue;
                }

                $student->class_id = $classRoom->id;
                if (empty($student->enrollment_date)) {
                    $student->enrollment_date = now()->toDateString();
                }
                if (empty($student->status)) {
                    $student->status = 'enrolled';
                }
                $student->save();

                // Sync course_enrollments for all active course offerings of this class
                $offerings = CourseOffering::where('class_id', $classRoom->id)->get();
                $fallbackSemesterId = Semester::where('status', 'active')->value('id') ?? Semester::orderByDesc('id')->value('id');
                foreach ($offerings as $offering) {
                    $semId = $offering->semester_id ?? $fallbackSemesterId;
                    if (! $semId || ! $offering->course_id) {
                        continue;
                    }
                    CourseEnrollment::updateOrCreate(
                        [
                            'student_id' => $student->id,
                            'course_offering_id' => $offering->id,
                        ],
                        [
                            'course_id' => $offering->course_id,
                            'semester_id' => $semId,
                            'enrollment_date' => $student->enrollment_date?->toDateString() ?? now()->toDateString(),
                            'status' => 'enrolled',
                        ]
                    );
                }

                $enrolled[] = [
                    'student_id' => $student->student_code,
                    'student_name' => $this->resolveStudentName($student),
                    'class_code' => $classRoom->code,
                ];
            }
        });

        return response()->json([
            'message' => count($enrolled) . ' student(s) enrolled successfully.',
            'data' => [
                'enrolled_count' => count($enrolled),
                'failed_count' => count($failed),
                'enrolled' => $enrolled,
                'failed' => $failed,
            ],
        ]);
    }

    private function resolveStudentName(Student $s): string
    {
        $eng = trim((string) ($s->english_name ?? ''));
        if ($eng !== '') {
            return $eng;
        }
        $fl = trim(($s->first_name ?? '') . ' ' . ($s->last_name ?? ''));
        if ($fl !== '') {
            return $fl;
        }

        return (string) ($s->khmer_name ?: $s->student_code);
    }
}
