<?php

namespace App\Services;

use App\Exceptions\DuplicateStudentException;
use App\Exceptions\ImportFileException;
use App\Models\ClassRoom;
use App\Models\Student;
use App\Models\StudentEmergencyContact;
use App\Models\StudentFamilyInformation;
use App\Models\StudentImport;
use App\Models\StudentImportRow;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class StudentImportService
{
    public function __construct(private StudentImportValidationService $validator)
    {
    }

    /**
     * Stores the upload, validates EVERY row and persists the result as an
     * import in "pending" state. No student or user is created here.
     */
    public function createPreview(UploadedFile $file, User $admin): StudentImport
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $disk = Storage::disk(config('student_import.disk'));
        $path = $file->storeAs(
            config('student_import.directory'),
            Str::uuid()->toString() . '.' . $ext,
            config('student_import.disk')
        );
        $full = $disk->path($path);

        $import = StudentImport::create([
            'user_id' => $admin->id,
            'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'stored_path' => $path,
            'status' => StudentImport::PENDING,
        ]);

        try {
            $this->scan($import, $full, $ext);
        } catch (\Throwable $e) {
            $disk->delete($path);
            $import->delete();
            throw $e;
        }

        return $import->fresh();
    }

    private function scan(StudentImport $import, string $fullPath, string $ext): void
    {
        $maxRows = config('student_import.max_rows');
        $chunk = config('student_import.chunk_size');
        $seen = [];
        $counts = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'duplicate' => 0];
        $buffer = [];

        $flush = function () use (&$buffer, &$counts, $import) {
            if (! $buffer) {
                return;
            }
            $existing = $this->validator->existingIdentifiers(array_column($buffer, 'identifier'));
            $now = now();
            $insert = [];
            foreach ($buffer as $b) {
                $status = $b['status'];
                $errors = $b['errors'];
                if ($status === 'valid' && isset($existing[mb_strtolower($b['identifier'])])) {
                    $status = 'duplicate';
                    $errors[] = 'Student identifier already exists in the database; existing records are never overwritten';
                }
                $counts[$status]++;
                $insert[] = [
                    'student_import_id' => $import->id,
                    'row_number' => $b['row_number'],
                    'student_identifier' => mb_substr($b['identifier'], 0, 60) ?: null,
                    'status' => $status,
                    'data' => json_encode($b['data'], JSON_UNESCAPED_UNICODE),
                    'errors' => $errors ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null,
                    'temp_password_encrypted' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('student_import_rows')->insert($insert);
            $buffer = [];
        };

        foreach ($this->readRows($fullPath, $ext) as $rowNumber => $raw) {
            $counts['total']++;
            if ($counts['total'] > $maxRows) {
                throw new ImportFileException("File exceeds the maximum of {$maxRows} rows.");
            }

            $row = $this->validator->normalize($raw);
            $result = $this->validator->validate($row);
            $errors = $result['errors'];
            $status = $errors ? 'invalid' : 'valid';

            $key = mb_strtolower($row['student_identifier']);
            if ($key !== '') {
                if (isset($seen[$key])) {
                    $status = 'duplicate';
                    $errors[] = "Duplicate student_identifier inside the file (first seen on row {$seen[$key]})";
                } else {
                    $seen[$key] = $rowNumber;
                }
            }

            $buffer[] = [
                'row_number' => $rowNumber,
                'identifier' => $row['student_identifier'],
                'status' => $status,
                'errors' => $errors,
                'data' => $result['data'],
                'password' => $row['password'],
            ];
            // counters for non-valid statuses are applied in flush()
            if (count($buffer) >= $chunk) {
                $flush();
            }
        }
        $flush();

        if ($counts['total'] === 0) {
            throw new ImportFileException('The file contains no data rows.');
        }

        $import->update([
            'total_rows' => $counts['total'],
            'valid_rows' => $counts['valid'],
            'invalid_rows' => $counts['invalid'],
            'duplicate_rows' => $counts['duplicate'],
        ]);
    }

    /**
     * Yields [rowNumber => [header => value]] without loading the whole file.
     * Row numbers match the spreadsheet (header is row 1).
     */
    public function readRows(string $fullPath, string $ext): \Generator
    {
        if ($ext === 'csv') {
            $rawContent = @file_get_contents($fullPath);
            if ($rawContent === false) {
                throw new ImportFileException('Unable to read the uploaded file.');
            }

            // Detect UTF-16 BOM and transcode to UTF-8 if exported from legacy Excel
            if (str_starts_with($rawContent, "\xFF\xFE")) {
                $rawContent = mb_convert_encoding(substr($rawContent, 2), 'UTF-8', 'UTF-16LE');
            } elseif (str_starts_with($rawContent, "\xFE\xFF")) {
                $rawContent = mb_convert_encoding(substr($rawContent, 2), 'UTF-8', 'UTF-16BE');
            } elseif (str_starts_with($rawContent, "\xEF\xBB\xBF")) {
                $rawContent = substr($rawContent, 3);
            }

            // Normalize Mac/Windows line endings so fgetcsv reads every row reliably
            $rawContent = str_replace(["\r\n", "\r"], "\n", $rawContent);

            $h = fopen('php://temp', 'r+b');
            if (! $h) {
                throw new ImportFileException('Unable to read the uploaded file.');
            }
            fwrite($h, $rawContent);
            rewind($h);

            $first = fgets($h);
            rewind($h);
            $delimiter = ',';
            if ($first !== false) {
                $counts = [
                    ',' => substr_count($first, ','),
                    ';' => substr_count($first, ';'),
                    "\t" => substr_count($first, "\t"),
                ];
                arsort($counts);
                $best = array_key_first($counts);
                if ($counts[$best] > 0) {
                    $delimiter = $best;
                }
            }

            $headers = null;
            $n = 0;
            while (($line = fgetcsv($h, 0, $delimiter)) !== false) {
                $n++;
                if ($headers === null) {
                    if ($this->blank($line)) {
                        continue;
                    }
                    $headers = $this->headers($line);
                    continue;
                }
                if ($this->blank($line)) {
                    continue;
                }
                yield $n => $this->combine($headers, $line);
            }
            fclose($h);

            return;
        }

        try {
            $readerType = $ext === 'xls' ? 'Xls' : 'Xlsx';
            $reader = IOFactory::createReader($readerType);
            $reader->setReadDataOnly(true);
            $chunkSize = 500;
            $headers = null;
            $start = 1;
            $sheetName = $this->firstSheetName($fullPath, $readerType);
            do {
                $filter = new class($start, $chunkSize) implements IReadFilter {
                    public function __construct(private int $start, private int $size)
                    {
                    }

                    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                    {
                        return $row === 1 || ($row >= $this->start && $row < $this->start + $this->size);
                    }
                };
                $reader->setReadFilter($filter);
                $reader->setLoadSheetsOnly([$sheetName]);
                $sheet = $reader->load($fullPath)->getActiveSheet();
                $highest = $sheet->getHighestDataRow();
                $rows = $sheet->toArray(null, false, false, true);
                foreach ($rows as $rowNo => $cells) {
                    $cells = array_values($cells);
                    if ($rowNo === 1) {
                        $headers = $headers ?? $this->headers($cells);
                        continue;
                    }
                    if ($rowNo < $start || $this->blank($cells)) {
                        continue;
                    }
                    yield $rowNo => $this->combine($headers, $cells);
                }
                $start += $chunkSize;
                $sheet->getParent()->disconnectWorksheets();
            } while ($start <= $highest);
        } catch (ImportFileException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ImportFileException('The Excel file could not be read. Make sure it is a valid .xlsx or .xls workbook.');
        }
    }

    private function firstSheetName(string $fullPath, string $readerType = 'Xlsx'): string
    {
        return IOFactory::createReader($readerType)->listWorksheetNames($fullPath)[0];
    }

    private function headers(array $cells): array
    {
        $aliases = [
            'student_id' => 'student_identifier',
            'student_code' => 'student_identifier',
            'username' => 'student_identifier',
            'login_id' => 'student_identifier',
            'full_name' => 'english_name',
            'name' => 'english_name',
            'student_name' => 'english_name',
            'dob' => 'date_of_birth',
            'pob' => 'place_of_birth',
            'address' => 'current_address',
            'department' => 'department_code',
            'dept' => 'department_code',
            'dept_code' => 'department_code',
            'department_name' => 'department_code',
            'faculty' => 'department_code',
            'faculty_code' => 'department_code',
            'faculty_name' => 'department_code',
            'program' => 'program_code',
            'prog' => 'program_code',
            'prog_code' => 'program_code',
            'program_name' => 'program_code',
            'major_code' => 'program_code',
            'major_name' => 'program_code',
            'major' => 'program_code',
            'academic_year' => 'academic_year_code',
            'academic_year_name' => 'academic_year_code',
            'semester' => 'semester_code',
            'student_year_level' => 'year_level',
            'study_year' => 'year_level',
            'emergency_contact_relationship' => 'emergency_relationship',
        ];
        $headers = array_map(function ($h) use ($aliases) {
            $clean = preg_replace('/^\xEF\xBB\xBF|\x{FEFF}/u', '', (string) $h);
            $s = Str::snake(trim($clean));
            return $aliases[$s] ?? $s;
        }, $cells);
        $missing = array_diff(StudentImportValidationService::REQUIRED, $headers);
        if ($missing) {
            throw new ImportFileException('Missing required column(s): ' . implode(', ', $missing)
                . '. Download the template for the exact column names.');
        }
        if (! in_array('khmer_name', $headers, true) && ! in_array('english_name', $headers, true)) {
            throw new ImportFileException('Missing required name column: provide khmer_name, english_name, or both.');
        }

        return $headers;
    }

    private function combine(array $headers, array $cells): array
    {
        $out = [];
        foreach ($headers as $i => $h) {
            if ($h !== '') {
                $out[$h] = $cells[$i] ?? null;
            }
        }

        return $out;
    }

    private function blank(array $cells): bool
    {
        foreach ($cells as $c) {
            if ($c !== null && trim((string) $c) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Creates user + student + family + emergency contact atomically.
     * The institutional identifier is stored in students.student_code, which is
     * what AuthController::findUserByLoginId uses for login.
     *
     * @param array $data      normalized row incl. `_ids`
     * @param ?string $password ignored under standardized initial password policy (`STD@12345`)
     * @return array{student: Student, temp_password: string}
     */
    public function registerStudent(array $data, ?string $password = null): array
    {
        $identifier = $data['student_identifier'];
        $ids = $data['_ids'];

        return DB::transaction(function () use ($data, $identifier, $ids) {
            $exists = Student::withTrashed()->whereRaw('LOWER(student_code) = ?', [mb_strtolower($identifier)])->exists();
            if ($exists) {
                throw new DuplicateStudentException("Student identifier '{$identifier}' already exists.");
            }

            $plain = (string) config('student_import.default_initial_password', 'STD@12345');

            $khmerName = \App\Support\PersonNameHelper::clean($data['khmer_name'] ?? null);
            $englishName = \App\Support\PersonNameHelper::clean($data['english_name'] ?? null);
            $displayName = \App\Support\PersonNameHelper::displayName($englishName, $khmerName, null, null, $identifier);
            $emailRaw = isset($data['email']) ? strtolower(trim((string) $data['email'])) : '';
            $email = $emailRaw !== '' ? $emailRaw : null;

            $user = new User();
            $user->forceFill([
                'name' => $displayName,
                'email' => $email,
                'password' => Hash::make($plain),
                'role' => 'student',
                'status' => 'active',
            ])->save();

            [$first, $last] = \App\Support\PersonNameHelper::splitFirstLast($displayName);

            $program = $ids['program_id'] ? DB::table('programs')->where('id', $ids['program_id'])->value('name') : null;

            $student = Student::create([
                'user_id' => $user->id,
                'student_code' => $identifier,
                'khmer_name' => $khmerName,
                'english_name' => $englishName,
                'first_name' => $first,
                'last_name' => $last,
                'date_of_birth' => ! empty($data['date_of_birth']) ? $data['date_of_birth'] : null,
                'place_of_birth' => $data['place_of_birth'] ?: null,
                'gender' => $data['gender'],
                'address' => $data['current_address'] ?: null,
                'department_id' => $ids['department_id'],
                'program_id' => $ids['program_id'],
                'class_id' => $this->resolveClass($ids)?->id,
                'major' => $program,
                'year_level' => \App\Support\YearLevel::storageValue($ids['year_level']),
                'enrollment_date' => now()->toDateString(),
                'status' => 'enrolled',
            ]);

            if ($data['father_khmer_name'] . $data['father_english_name'] . $data['father_phone']
                . $data['mother_khmer_name'] . $data['mother_english_name'] . $data['mother_phone'] !== '') {
                StudentFamilyInformation::create([
                    'student_id' => $student->id,
                    'father_khmer_name' => $data['father_khmer_name'] ?: null,
                    'father_english_name' => $data['father_english_name'] ?: null,
                    'father_phone' => $data['father_phone'] ?: null,
                    'mother_khmer_name' => $data['mother_khmer_name'] ?: null,
                    'mother_english_name' => $data['mother_english_name'] ?: null,
                    'mother_phone' => $data['mother_phone'] ?: null,
                ]);
            }

            if ($data['emergency_contact_name'] !== '') {
                StudentEmergencyContact::create([
                    'student_id' => $student->id,
                    'name' => $data['emergency_contact_name'],
                    'relationship' => $data['emergency_relationship'] ?: null,
                    'phone' => $data['emergency_contact_phone'] ?: null,
                    'address' => $data['emergency_contact_address'] ?: null,
                ]);
            }

            return ['student' => $student, 'temp_password' => $plain];
        });
    }

    /** Same class convention as the original SLAS migration: {dept}-Y{level}-{academic year}. */
    private function resolveClass(array $ids): ?ClassRoom
    {
        if (! $ids['department_id'] || ! $ids['academic_year_id'] || ! $ids['year_level']) {
            return null;
        }
        $dept = DB::table('departments')->find($ids['department_id']);
        $year = DB::table('academic_years')->find($ids['academic_year_id']);
        $code = substr("{$dept->code}-Y{$ids['year_level']}-{$year->name}", 0, 40);

        return ClassRoom::firstOrCreate(['code' => $code], [
            'department_id' => $dept->id,
            'academic_year_id' => $year->id,
            'name' => substr("{$dept->name} Year {$ids['year_level']} ({$year->name})", 0, 100),
            'year_level' => $ids['year_level'],
            'status' => 'active',
        ]);
    }

    /** Recomputes counters from row states (safe to call repeatedly / after retry). */
    public function refreshCounters(StudentImport $import): void
    {
        $c = StudentImportRow::where('student_import_id', $import->id)
            ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        $imported = (int) ($c['imported'] ?? 0);
        $failed = (int) ($c['failed'] ?? 0);
        $import->update([
            'success_rows' => $imported,
            'failed_rows' => (int) ($c['invalid'] ?? 0) + $failed,
            'skipped_rows' => (int) ($c['duplicate'] ?? 0) + (int) ($c['skipped'] ?? 0),
            'processed_rows' => $imported + $failed,
        ]);
    }

    public function deleteStoredFile(StudentImport $import): void
    {
        if ($import->stored_path) {
            Storage::disk(config('student_import.disk'))->delete($import->stored_path);
            $import->update(['stored_path' => null]);
        }
    }
}
