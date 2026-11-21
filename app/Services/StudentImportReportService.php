<?php

namespace App\Services;

use App\Models\StudentImport;
use App\Models\StudentImportRow;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Templates and downloadable reports. None of them ever contain password hashes. */
class StudentImportReportService
{
    /** Neutralises spreadsheet formula injection. */
    public static function safe(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }
        if (preg_match('/^[=@\t\r]/', $value)
            || (preg_match('/^[+-]/', $value) && ! preg_match('/^[+-]?[0-9][0-9\s-]*$/', $value))) {
            return "'" . $value;
        }

        return $value;
    }

    private function sampleRows(): array
    {
        $dept = \Illuminate\Support\Facades\Schema::hasTable('departments')
            ? DB::table('departments')->orderBy('id')->first()
            : null;
        $program = ($dept && \Illuminate\Support\Facades\Schema::hasTable('programs'))
            ? DB::table('programs')->where('department_id', $dept->id)->orderBy('id')->first()
            : null;
        $year = \Illuminate\Support\Facades\Schema::hasTable('academic_years')
            ? DB::table('academic_years')->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->orderByDesc('id')->first()
            : null;

        $base = [
            'department_code' => $dept->code ?? 'DEPARTMENT_CODE',
            'program_code' => $program->code ?? 'PROGRAM_CODE',
            'year_level' => '1',
            'academic_year_code' => $year->name ?? 'ACADEMIC_YEAR',
            'semester_code' => '1',
            'password' => '',
        ];

        return [
            $base + [
                'khmer_name' => 'សិស្សគំរូ ទី១', 'english_name' => 'Sample Studentone', 'gender' => 'Male',
                'date_of_birth' => '2005-01-31', 'place_of_birth' => 'Sample City', 'current_address' => 'Sample Address 1',
                'student_identifier' => 'SAMPLE-001', 'father_khmer_name' => '', 'father_english_name' => 'Sample Father',
                'father_phone' => '012000001', 'mother_khmer_name' => '', 'mother_english_name' => 'Sample Mother',
                'mother_phone' => '012000002', 'emergency_contact_name' => 'Sample Contact', 'emergency_relationship' => 'Parent',
                'emergency_contact_phone' => '012000003', 'emergency_contact_address' => 'Sample Address 1',
            ],
            $base + [
                'khmer_name' => 'សិស្សគំរូ ទី២', 'english_name' => 'Sample Studenttwo', 'gender' => 'Female',
                'date_of_birth' => '2004-12-15', 'place_of_birth' => 'Sample Town', 'current_address' => 'Sample Address 2',
                'student_identifier' => 'SAMPLE-002', 'father_khmer_name' => '', 'father_english_name' => '',
                'father_phone' => '', 'mother_khmer_name' => '', 'mother_english_name' => '',
                'mother_phone' => '', 'emergency_contact_name' => '', 'emergency_relationship' => '',
                'emergency_contact_phone' => '', 'emergency_contact_address' => '',
            ],
        ];
    }

    public function templateXlsx(string $path): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Students');
        $cols = StudentImportValidationService::COLUMNS;

        foreach ($cols as $i => $col) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            // Apply Text format at the column level so leading zeros/dates stay text without materializing 5000x23 cells.
            $sheet->getStyle($letter)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            $cell = $sheet->getCell($letter . '1');
            $cell->setValueExplicit($col, DataType::TYPE_STRING);
            $required = in_array($col, StudentImportValidationService::REQUIRED, true);
            $sheet->getStyle($letter . '1')->getFont()->setBold(true);
            $sheet->getStyle($letter . '1')->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB($required ? 'FDE2E2' : 'E8F0FE');
            $sheet->getColumnDimension($letter)->setAutoSize(true);
        }

        foreach ($this->sampleRows() as $r => $row) {
            foreach ($cols as $i => $col) {
                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                $sheet->getCell($letter . ($r + 2))->setValueExplicit((string) ($row[$col] ?? ''), DataType::TYPE_STRING);
            }
        }
        $sheet->freezePane('A2');

        $help = $book->createSheet()->setTitle('Instructions');
        $deptCodes = \Illuminate\Support\Facades\Schema::hasTable('departments')
            ? DB::table('departments')->orderBy('id')->pluck('code')->implode(', ')
            : 'CS, IT, BA';
        $progCodes = \Illuminate\Support\Facades\Schema::hasTable('programs')
            ? DB::table('programs')->orderBy('id')->pluck('code')->implode(', ')
            : 'BSCS, CS, BSE, SE, BSIT, IT, BBA, BA';
        $yearCodes = \Illuminate\Support\Facades\Schema::hasTable('academic_years')
            ? DB::table('academic_years')->orderBy('id')->pluck('name')->implode(', ')
            : '2025-2026, 2026-2027';
        $lines = [
            ['SLAS Student Import Template'],
            [''],
            ['1. Fill one student per row on the "Students" sheet. Do not rename or reorder the header row.'],
            ['2. Columns with a red header are REQUIRED: ' . implode(', ', StudentImportValidationService::REQUIRED) . '.'],
            ['3. student_identifier is BOTH the Student ID and the login username (example format: bsr180872). It must be unique.'],
            ['4. gender: Male or Female.'],
            ['5. date_of_birth: YYYY-MM-DD (for example 2005-01-31). DD/MM/YYYY is also accepted.'],
            ['6. Phone numbers: digits, optional leading +, spaces or dashes (7-19 characters).'],
            ['7. Valid department_code values: ' . $deptCodes . ' (full department names and BUS alias are also accepted).'],
            ['8. Valid program_code values: ' . $progCodes . ' (full program names are also accepted; program must belong to the department).'],
            ['9. Valid academic_year_code values: ' . $yearCodes . '. Valid semester_code values: 1, 2 (or Semester-1, Semester-2).'],
            ['10. year_level: whole number (1-4), not greater than the program duration.'],
            ['11. All imported student accounts receive the standard initial password: STD@12345 (stored securely with Laravel Hash). Username = Student ID (student_identifier).'],
            ['12. The rows SAMPLE-001 and SAMPLE-002 are fictional examples. DELETE them before uploading.'],
            ['13. Existing students are never overwritten: a row whose identifier already exists is reported as a duplicate.'],
            ['14. Maximum rows per file: ' . config('student_import.max_rows') . '. Maximum file size: ' . config('student_import.max_file_kb') . ' KB.'],
        ];
        foreach ($lines as $i => $l) {
            $help->setCellValue('A' . ($i + 1), $l[0]);
        }
        $help->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $help->getColumnDimension('A')->setWidth(130);
        $book->setActiveSheetIndex(0);

        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
    }

    public function templateCsv(): \Closure
    {
        return function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, StudentImportValidationService::COLUMNS);
            foreach ($this->sampleRows() as $row) {
                fputcsv($out, array_map(fn ($c) => $row[$c] ?? '', StudentImportValidationService::COLUMNS));
            }
            fclose($out);
        };
    }

    /** Rows with the given statuses: row number, identifier, status, errors. No passwords. */
    public function rowsCsv(StudentImport $import, array $statuses): \Closure
    {
        return function () use ($import, $statuses) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['row_number', 'student_identifier', 'english_name', 'khmer_name', 'department_code', 'program_code', 'status', 'errors']);
            StudentImportRow::where('student_import_id', $import->id)->whereIn('status', $statuses)
                ->orderBy('row_number')->chunkById(500, function ($rows) use ($out) {
                    foreach ($rows as $r) {
                        $d = $r->data ?? [];
                        fputcsv($out, array_map([self::class, 'safe'], [
                            $r->row_number, $r->student_identifier, $d['english_name'] ?? '', $d['khmer_name'] ?? '',
                            $d['department_code'] ?? '', $d['program_code'] ?? '', $r->status, implode(' | ', $r->errors ?? []),
                        ]));
                    }
                });
            fclose($out);
        };
    }

    /**
     * One-time credentials for generated temporary passwords. After this callback
     * has streamed, the encrypted values are erased.
     */
    public function credentialsCsv(StudentImport $import): \Closure
    {
        return function () use ($import) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['student_identifier', 'english_name', 'temporary_password']);
            $ids = [];
            StudentImportRow::where('student_import_id', $import->id)->where('status', 'imported')
                ->whereNotNull('temp_password_encrypted')->orderBy('id')
                ->chunkById(500, function ($rows) use ($out, &$ids) {
                    foreach ($rows as $r) {
                        $plain = substr(decrypt($r->temp_password_encrypted), 2);
                        fputcsv($out, [$r->student_identifier, $r->data['english_name'] ?? '', $plain]);
                        $ids[] = $r->id;
                    }
                });
            fclose($out);
            foreach (array_chunk($ids, 500) as $part) {
                StudentImportRow::whereIn('id', $part)->update(['temp_password_encrypted' => null]);
            }
        };
    }
}
