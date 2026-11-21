<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Normalizes and validates one import row. All reference data (departments,
 * programs, academic years, semesters) comes from the database.
 */
class StudentImportValidationService
{
    public const COLUMNS = [
        'khmer_name', 'english_name', 'email', 'gender', 'date_of_birth', 'place_of_birth', 'current_address',
        'student_identifier', 'password',
        'father_khmer_name', 'father_english_name', 'father_phone',
        'mother_khmer_name', 'mother_english_name', 'mother_phone',
        'emergency_contact_name', 'emergency_relationship', 'emergency_contact_phone', 'emergency_contact_address',
        'department_code', 'program_code', 'year_level', 'academic_year_code', 'semester_code',
    ];

    public const REQUIRED = [
        'gender', 'student_identifier',
        'department_code', 'program_code', 'year_level', 'academic_year_code',
    ];

    private array $departments = [];
    private array $departmentCodesById = [];
    private array $programs = [];
    private array $academicYears = [];
    private array $semesters = [];

    public function __construct()
    {
        $this->loadReferences();
    }

    public function loadReferences(): void
    {
        $this->departments = [];
        $this->departmentCodesById = [];
        foreach (DB::table('departments')->get(['id', 'code', 'name']) as $d) {
            $codeKey = mb_strtolower(trim($d->code));
            $nameKey = mb_strtolower(trim($d->name));
            $this->departments[$codeKey] = $d->id;
            $this->departments[$nameKey] = $d->id;
            $this->departmentCodesById[(int) $d->id] = strtoupper(trim($d->code));
        }

        // Standard institutional department code aliases mapped to actual DB departments
        if (isset($this->departments['ba'])) {
            $this->departments['bus'] ??= $this->departments['ba'];
            $this->departments['business'] ??= $this->departments['ba'];
            $this->departments['bba'] ??= $this->departments['ba'];
        }
        if (isset($this->departments['cs'])) {
            $this->departments['comp'] ??= $this->departments['cs'];
            $this->departments['bscs'] ??= $this->departments['cs'];
        }
        if (isset($this->departments['it'])) {
            $this->departments['info'] ??= $this->departments['it'];
            $this->departments['bsit'] ??= $this->departments['it'];
        }

        $this->programs = [];
        foreach (DB::table('programs')->get(['id', 'code', 'name', 'department_id', 'duration_years']) as $p) {
            $this->programs[mb_strtolower(trim($p->code))] = $p;
            $this->programs[mb_strtolower(trim($p->name))] = $p;
        }

        // Standard institutional program code aliases mapped to actual DB programs
        $programAliases = [
            'cs' => ['bscs', 'computer science', 'bachelor of science in computer science'],
            'bscs' => ['cs', 'computer science'],
            'se' => ['bse', 'software engineering', 'bachelor of software engineering'],
            'bse' => ['se', 'software engineering'],
            'it' => ['bsit', 'information technology', 'bachelor of science in information technology'],
            'bsit' => ['it', 'information technology'],
            'ba' => ['bba', 'business administration', 'bachelor of business administration'],
            'bba' => ['ba', 'business administration'],
            'bus' => ['bba', 'ba', 'business administration'],
        ];
        foreach ($programAliases as $alias => $targets) {
            if (! isset($this->programs[$alias])) {
                foreach ($targets as $target) {
                    if (isset($this->programs[$target])) {
                        $this->programs[$alias] = $this->programs[$target];
                        break;
                    }
                }
            }
        }

        $this->academicYears = DB::table('academic_years')->get(['id', 'name'])
            ->mapWithKeys(fn ($a) => [mb_strtolower(trim($a->name)) => $a->id])->all();

        $this->semesters = DB::table('semesters')->get(['id', 'academic_year_id', 'semester_number'])
            ->groupBy('academic_year_id')->map(fn ($g) => $g->pluck('id', 'semester_number')->all())->all();
    }

    /** Maps a raw header=>value row to the supported columns, trimmed, supporting university multi-column address/alias formats. */
    public function normalize(array $raw): array
    {
        if (empty($raw['student_identifier'])) {
            $raw['student_identifier'] = $raw['student_id'] ?? $raw['student_code'] ?? $raw['username'] ?? $raw['login_id'] ?? '';
        }
        if (empty($raw['english_name'])) {
            $raw['english_name'] = $raw['full_name'] ?? $raw['name'] ?? $raw['student_name'] ?? '';
        }
        if (empty($raw['date_of_birth']) && ! empty($raw['dob'])) {
            $raw['date_of_birth'] = $raw['dob'];
        }
        if (empty($raw['place_of_birth'])) {
            $pobParts = array_filter([
                trim((string) ($raw['place_of_birth_village'] ?? '')),
                trim((string) ($raw['place_of_birth_commune'] ?? '')),
                trim((string) ($raw['place_of_birth_district'] ?? '')),
                trim((string) ($raw['place_of_birth_province'] ?? '')),
            ], fn ($v) => $v !== '');
            if ($pobParts) {
                $raw['place_of_birth'] = implode(', ', array_unique($pobParts));
            } elseif (! empty($raw['pob'])) {
                $raw['place_of_birth'] = $raw['pob'];
            }
        }
        if (empty($raw['current_address'])) {
            $addrParts = array_filter([
                trim((string) ($raw['current_address_village'] ?? '')),
                trim((string) ($raw['current_address_commune'] ?? '')),
                trim((string) ($raw['current_address_district'] ?? '')),
                trim((string) ($raw['current_address_province'] ?? '')),
            ], fn ($v) => $v !== '');
            if ($addrParts) {
                $raw['current_address'] = implode(', ', array_unique($addrParts));
            } elseif (! empty($raw['address'])) {
                $raw['current_address'] = $raw['address'];
            }
        }
        if (empty($raw['father_khmer_name']) && empty($raw['father_english_name']) && ! empty($raw['father_name'])) {
            $father = trim((string) $raw['father_name']);
            if (preg_match('/\p{Khmer}/u', $father)) {
                $raw['father_khmer_name'] = $father;
            } else {
                $raw['father_english_name'] = $father;
            }
        }
        if (empty($raw['mother_khmer_name']) && empty($raw['mother_english_name']) && ! empty($raw['mother_name'])) {
            $mother = trim((string) $raw['mother_name']);
            if (preg_match('/\p{Khmer}/u', $mother)) {
                $raw['mother_khmer_name'] = $mother;
            } else {
                $raw['mother_english_name'] = $mother;
            }
        }
        if (empty($raw['department_code'])) {
            $raw['department_code'] = $raw['department'] ?? $raw['dept_code'] ?? $raw['dept'] ?? $raw['department_name'] ?? '';
        }
        if (empty($raw['program_code'])) {
            $raw['program_code'] = $raw['program'] ?? $raw['prog_code'] ?? $raw['prog'] ?? $raw['program_name'] ?? $raw['major'] ?? '';
        }
        if (empty($raw['emergency_relationship']) && ! empty($raw['emergency_contact_relationship'])) {
            $raw['emergency_relationship'] = $raw['emergency_contact_relationship'];
        }
        if (empty($raw['academic_year_code']) && ! empty($raw['academic_year'])) {
            $raw['academic_year_code'] = $raw['academic_year'];
        }
        if (empty($raw['semester_code']) && ! empty($raw['semester'])) {
            $raw['semester_code'] = $raw['semester'];
        }

        $row = [];
        foreach (self::COLUMNS as $col) {
            $v = $raw[$col] ?? null;
            $row[$col] = is_string($v) ? trim($v) : (is_null($v) ? '' : trim((string) $v));
        }

        if ($row['year_level'] !== '' && preg_match('/^year\s*(\d+)$/i', $row['year_level'], $m)) {
            $row['year_level'] = $m[1];
        }

        return $row;
    }

    /**
     * @return array{errors: string[], data: array}  data has resolved ids and canonical values
     */
    public function validate(array $row): array
    {
        $errors = [];

        foreach (self::REQUIRED as $col) {
            if ($row[$col] === '') {
                $errors[] = "Missing required field: {$col}";
            }
        }

        if ($row['khmer_name'] === '' && $row['english_name'] === '') {
            $errors[] = 'At least one of khmer_name or english_name is required.';
        }

        if ($row['email'] !== '' && ! filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format.';
        }

        $gender = null;
        if ($row['gender'] !== '') {
            $gender = match (mb_strtolower($row['gender'])) {
                'male', 'm', 'ប្រុស' => 'Male',
                'female', 'f', 'ស្រី' => 'Female',
                default => null,
            };
            if (! $gender) {
                $errors[] = 'Invalid gender (use Male or Female)';
            }
        }

        $dob = null;
        if ($row['date_of_birth'] !== '') {
            $dob = $this->parseDate($row['date_of_birth']);
            if (! $dob) {
                $errors[] = 'Invalid date_of_birth (use YYYY-MM-DD)';
            } elseif ($dob->isFuture() || $dob->year < 1900) {
                $errors[] = 'date_of_birth is out of range';
                $dob = null;
            }
        }

        if ($row['student_identifier'] !== '' && ! preg_match('/^[A-Za-z0-9._-]{3,50}$/', $row['student_identifier'])) {
            $errors[] = 'Invalid student_identifier (3-50 letters, digits, . _ -)';
        }

        foreach (['father_phone', 'mother_phone', 'emergency_contact_phone'] as $col) {
            if ($row[$col] !== '' && ! preg_match('/^\+?[0-9][0-9\s-]{6,18}$/', $row[$col])) {
                $errors[] = "Invalid phone number in {$col}";
            }
        }

        // Password column is optional and ignored under standardized initial password policy (STD@12345).
        if ($row['password'] !== '' && mb_strlen($row['password']) < 8) {
            // Do not fail valid student imports over legacy password cells.
        }

        $departmentId = $programId = $academicYearId = $semesterId = null;

        if ($row['department_code'] !== '') {
            $departmentId = $this->departments[mb_strtolower(trim($row['department_code']))] ?? null;
            if (! $departmentId) {
                $errors[] = "Unknown department_code '{$row['department_code']}'";
            }
        }

        $program = null;
        if ($row['program_code'] !== '') {
            $program = $this->programs[mb_strtolower(trim($row['program_code']))] ?? null;
            if (! $program) {
                $errors[] = "Unknown program_code '{$row['program_code']}'";
            } elseif ($departmentId && ! $this->isProgramCompatibleWithDepartment($program, (int) $departmentId)) {
                $errors[] = 'program_code does not belong to the given department_code';
            } else {
                $programId = $program->id;
            }
        }

        if ($row['academic_year_code'] !== '') {
            $academicYearId = $this->academicYears[mb_strtolower(trim($row['academic_year_code']))] ?? null;
            if (! $academicYearId) {
                $errors[] = "Unknown academic_year_code '{$row['academic_year_code']}'";
            }
        }

        $yearLevel = null;
        if ($row['year_level'] !== '') {
            if (! ctype_digit($row['year_level']) || (int) $row['year_level'] < 1) {
                $errors[] = 'Invalid year_level (positive whole number)';
            } else {
                $yearLevel = (int) $row['year_level'];
                $max = $program->duration_years ?? 8;
                if ($yearLevel > $max) {
                    $errors[] = "year_level exceeds program duration ({$max} years)";
                    $yearLevel = null;
                }
            }
        }

        if ($row['semester_code'] !== '') {
            $number = (int) preg_replace('/\D/', '', $row['semester_code']);
            $semesterId = $academicYearId ? ($this->semesters[$academicYearId][$number] ?? null) : null;
            if ($academicYearId && ! $semesterId) {
                $errors[] = "Unknown semester_code '{$row['semester_code']}' for the academic year";
            }
        }

        $data = $row;
        unset($data['password']);
        $data['gender'] = $gender ?? $row['gender'];
        $data['date_of_birth'] = $dob?->toDateString() ?? $row['date_of_birth'];
        $data['_ids'] = [
            'department_id' => $departmentId,
            'program_id' => $programId,
            'academic_year_id' => $academicYearId,
            'semester_id' => $semesterId,
            'year_level' => $yearLevel,
        ];

        return ['errors' => $errors, 'data' => $data];
    }

    private function isProgramCompatibleWithDepartment(object $program, int $departmentId): bool
    {
        if ((int) $program->department_id === $departmentId) {
            return true;
        }

        // Computing faculty departments (CS & IT) share computing degree programs in university rosters
        $deptCode = $this->departmentCodesById[$departmentId] ?? '';
        $progDeptCode = $this->departmentCodesById[(int) $program->department_id] ?? '';
        $computingDepts = ['CS', 'IT'];

        if (in_array($deptCode, $computingDepts, true) && in_array($progDeptCode, $computingDepts, true)) {
            return true;
        }

        return false;
    }

    public function existingIdentifiers(array $identifiers): array
    {
        if (! $identifiers) {
            return [];
        }

        $lowered = array_values(array_filter(array_map(fn ($c) => mb_strtolower(trim((string) $c)), $identifiers)));
        if (! $lowered) {
            return [];
        }

        $studentCodes = Student::withTrashed()
            ->whereIn(DB::raw('LOWER(student_code)'), $lowered)
            ->pluck('student_code')
            ->map(fn ($c) => mb_strtolower($c))
            ->flip()
            ->all();

        $userEmails = User::whereIn(DB::raw('LOWER(email)'), $lowered)
            ->pluck('email')
            ->filter()
            ->map(fn ($e) => mb_strtolower($e))
            ->flip()
            ->all();

        return $studentCodes + $userEmails;
    }

    private function parseDate(string $value): ?Carbon
    {
        if (is_numeric($value) && (float) $value > 1000 && (float) $value < 80000) {
            return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value))->startOfDay();
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'j/n/Y', 'Y/m/d'] as $format) {
            try {
                $d = Carbon::createFromFormat('!' . $format, $value);
            } catch (\Throwable) {
                continue;
            }
            if ($d) {
                $parts = preg_split('/[-\/]/', $value);
                if (count($parts) !== 3) {
                    continue;
                }
                [$day, $month, $year] = in_array($format, ['Y-m-d', 'Y/m/d'], true)
                    ? [(int) $parts[2], (int) $parts[1], $parts[0]]
                    : [(int) $parts[0], (int) $parts[1], $parts[2]];
                // reject overflow such as 31/02 and two-digit years
                if (strlen((string) $year) === 4 && $d->day === $day && $d->month === $month) {
                    return $d;
                }
            }
        }

        return null;
    }
}
