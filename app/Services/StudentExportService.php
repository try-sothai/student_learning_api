<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Exports students. Only whitelisted business columns are exported; password
 * hashes, tokens and internal ids never leave the server.
 */
class StudentExportService
{
    public const HEADERS = [
        'student_identifier', 'khmer_name', 'english_name', 'gender', 'date_of_birth', 'place_of_birth',
        'current_address', 'department_code', 'program_code', 'year_level', 'academic_year', 'status',
        'father_khmer_name', 'father_english_name', 'father_phone',
        'mother_khmer_name', 'mother_english_name', 'mother_phone',
        'emergency_contact_name', 'emergency_relationship', 'emergency_contact_phone',
    ];

    public function query(array $f): Builder
    {
        $q = Student::query()->with(['department:id,code', 'program:id,code', 'classRoom.academicYear:id,name',
            'familyInformation', 'emergencyContacts']);

        if (! empty($f['ids'])) {
            $q->whereIn('id', array_map('intval', (array) $f['ids']));
        }
        if (! empty($f['department_id'])) {
            $q->where('department_id', (int) $f['department_id']);
        }
        if (! empty($f['program_id'])) {
            $q->where('program_id', (int) $f['program_id']);
        }
        if (! empty($f['year_level'])) {
            $q->where('year_level', (string) $f['year_level']);
        }
        if (! empty($f['academic_year_id'])) {
            $q->whereHas('classRoom', fn ($c) => $c->where('academic_year_id', (int) $f['academic_year_id']));
        }
        if (! empty($f['semester_id'])) {
            $q->whereHas('courseEnrollments', fn ($e) => $e->where('semester_id', (int) $f['semester_id']));
        }
        if (! empty($f['status']) && $f['status'] !== 'all') {
            $s = strtolower($f['status']);
            $s === 'active' ? $q->whereIn('status', ['enrolled', 'active'])
                : ($s === 'inactive' ? $q->whereIn('status', ['inactive', 'suspended', 'graduated']) : $q->where('status', $s));
        }

        return $q->orderBy('id');
    }

    private function line(Student $s): array
    {
        $fam = $s->familyInformation;
        $em = $s->emergencyContacts->first();

        return array_map([StudentImportReportService::class, 'safe'], [
            $s->student_code, $s->khmer_name, $s->english_name ?: trim($s->first_name . ' ' . $s->last_name),
            $s->gender, $s->date_of_birth?->toDateString(), $s->place_of_birth, $s->address,
            $s->department?->code, $s->program?->code, $s->year_level, $s->classRoom?->academicYear?->name, $s->status,
            $fam?->father_khmer_name, $fam?->father_english_name, $fam?->father_phone,
            $fam?->mother_khmer_name, $fam?->mother_english_name, $fam?->mother_phone,
            $em?->name, $em?->relationship, $em?->phone,
        ]);
    }

    public function csv(array $filters): \Closure
    {
        return function () use ($filters) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::HEADERS);
            $this->query($filters)->chunkById(500, function ($students) use ($out) {
                foreach ($students as $s) {
                    fputcsv($out, $this->line($s));
                }
            });
            fclose($out);
        };
    }

    public function xlsx(array $filters, string $path): int
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Students');
        foreach (self::HEADERS as $i => $h) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '1', $h);
        }
        $sheet->getStyle('A1:' . Coordinate::stringFromColumnIndex(count(self::HEADERS)) . '1')->getFont()->setBold(true);

        $row = 2;
        $this->query($filters)->chunkById(500, function ($students) use ($sheet, &$row) {
            foreach ($students as $s) {
                foreach ($this->line($s) as $i => $v) {
                    $sheet->getCell(Coordinate::stringFromColumnIndex($i + 1) . $row)
                        ->setValueExplicit((string) ($v ?? ''), DataType::TYPE_STRING);
                }
                $row++;
            }
        });
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $row - 2;
    }

    public static function filtersFrom(Request $r): array
    {
        return $r->only(['ids', 'department_id', 'program_id', 'year_level', 'academic_year_id', 'semester_id', 'status']);
    }
}
