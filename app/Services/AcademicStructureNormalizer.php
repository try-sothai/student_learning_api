<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Program;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-off, idempotent correction of the academic structure.
 *
 * Problem: the `departments` table (Faculty) also contains rows that are really
 * Majors (Computer Science, Information Technology, Data Science, Business
 * Administration). Students, teachers, classes, schedules and courses point at
 * those rows.
 *
 * Correction (all inside ONE database transaction):
 *  1. Normalise / create the real Faculties.
 *  2. For every misclassified department row, find (or create) the matching
 *     Major in `programs`, attached to the correct parent Faculty.
 *  3. Link the courses that used the misclassified row to that Major
 *     (course_program pivot) - nothing is guessed beyond the existing link.
 *  4. Re-point EVERY table that has a `department_id` column from the old row
 *     to the real Faculty (students, teachers, classes, schedules, courses ...).
 *  5. Deactivate the legacy "Bachelor of ..." duplicate majors (never deleted).
 *  6. Delete a misclassified department row ONLY when nothing references it.
 */
class AcademicStructureNormalizer
{
    public const FACULTIES = [
        'science' => ['code' => 'FOSAT', 'name' => 'Faculty of Science and Technology'],
        'business' => ['code' => 'FBA', 'name' => 'Faculty of Business Administration'],
    ];

    /** department code that is really a Major => [parent faculty key, major code, major name] */
    public const MISCLASSIFIED = [
        'CS' => ['faculty' => 'science', 'major_code' => 'CS', 'major_name' => 'Computer Science'],
        'IT' => ['faculty' => 'science', 'major_code' => 'IT', 'major_name' => 'Information Technology'],
        'DS' => ['faculty' => 'science', 'major_code' => 'DS', 'major_name' => 'Data Science'],
        'BA' => ['faculty' => 'business', 'major_code' => 'BA', 'major_name' => 'Business Administration'],
    ];

    /** legacy duplicate major code => the canonical major code it duplicates */
    public const LEGACY_DUPLICATES = [
        'BSCS' => 'CS',
        'BSE' => 'SE',
        'BSIT' => 'IT',
    ];

    private const SNAPSHOT_TABLES = [
        'departments', 'programs', 'students', 'teachers', 'classes', 'class_schedules', 'courses',
        'course_offerings', 'course_enrollments', 'assignments', 'quizzes', 'exams', 'attendance',
        'grades', 'incidents', 'specializations',
    ];

    public function run(bool $dryRun = false): array
    {
        $report = [
            'dry_run' => $dryRun,
            'changed' => false,
            'before' => $this->snapshot(),
            'faculties' => [],
            'majors' => [],
            'courses_linked' => [],
            'repointed' => [],
            'deactivated_majors' => [],
            'deleted_departments' => [],
            'warnings' => [],
        ];

        DB::beginTransaction();
        try {
            $this->apply($report);
            $report['after'] = $this->snapshot();
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $report;
    }

    private function apply(array &$report): void
    {
        $misclassified = $this->findMisclassified();

        if ($misclassified->isEmpty()) {
            $report['warnings'][] = 'No misclassified Faculty rows found - structure already normalised.';
        }

        // 1. real faculties (only the ones that are needed)
        $needed = $misclassified->map(fn ($d) => self::MISCLASSIFIED[strtoupper($d->code)]['faculty'])->unique()->values();
        $faculties = [];
        foreach ($needed as $key) {
            $faculties[$key] = $this->ensureFaculty($key, $report);
        }
        $existingScience = $this->findByCode(self::FACULTIES['science']['code']);
        if ($existingScience && ! isset($faculties['science'])) {
            $faculties['science'] = $this->ensureFaculty('science', $report);
        }

        // 2 + 3. majors and course links, then 4. re-point foreign keys
        $deptMap = [];
        $majorByOldDept = [];
        foreach ($misclassified as $old) {
            $spec = self::MISCLASSIFIED[strtoupper($old->code)];
            $faculty = $faculties[$spec['faculty']];
            $major = $this->ensureMajor($old, $spec, $faculty, $report);

            $deptMap[(int) $old->id] = (int) $faculty->id;
            $majorByOldDept[(int) $old->id] = $major;
        }

        $this->linkCoursesToMajors($majorByOldDept, $report);
        $this->repointDepartmentForeignKeys($deptMap, $report);

        // 5. legacy duplicates -> inactive (kept, never deleted)
        $this->deactivateLegacyDuplicates($report);

        // 6. delete the misclassified rows once nothing references them
        foreach ($misclassified as $old) {
            $remaining = $this->countReferences((int) $old->id);
            if ($remaining > 0) {
                $report['warnings'][] = "Department {$old->code} (id {$old->id}) still referenced by {$remaining} row(s); NOT deleted.";
                continue;
            }
            DB::table('departments')->where('id', $old->id)->delete();
            $report['deleted_departments'][] = ['id' => (int) $old->id, 'code' => $old->code, 'name' => $old->name];
            $report['changed'] = true;
        }

        // anything else left over in departments that is not a known faculty
        $knownCodes = array_map(fn ($f) => $f['code'], self::FACULTIES);
        foreach (Department::all() as $dept) {
            if (! in_array(strtoupper((string) $dept->code), $knownCodes, true)) {
                $report['warnings'][] = "Department '{$dept->name}' ({$dept->code}, id {$dept->id}) is not part of the approved plan; left unchanged for manual review.";
            }
        }
    }

    private function findMisclassified()
    {
        $faculty = array_map(fn ($f) => $f['code'], self::FACULTIES);

        return Department::orderBy('id')->get()->filter(function ($d) use ($faculty) {
            $code = strtoupper(trim((string) $d->code));

            return isset(self::MISCLASSIFIED[$code]) && ! in_array($code, $faculty, true);
        })->values();
    }

    private function findByCode(string $code): ?Department
    {
        return Department::whereRaw('UPPER(code) = ?', [strtoupper($code)])->first();
    }

    private function ensureFaculty(string $key, array &$report): Department
    {
        $spec = self::FACULTIES[$key];
        $faculty = $this->findByCode($spec['code'])
            ?? Department::whereRaw('LOWER(name) = ?', [mb_strtolower($spec['name'])])->first();

        if (! $faculty) {
            $faculty = Department::create([
                'name' => $spec['name'],
                'code' => $spec['code'],
                'status' => 'active',
            ]);
            $report['faculties'][] = ['action' => 'created', 'id' => (int) $faculty->id, 'code' => $spec['code'], 'name' => $spec['name']];
            $report['changed'] = true;

            return $faculty;
        }

        $before = ['name' => $faculty->name, 'code' => $faculty->code, 'status' => $faculty->status];
        $faculty->forceFill(['name' => $spec['name'], 'code' => $spec['code'], 'status' => 'active']);
        if ($faculty->isDirty()) {
            $faculty->save();
            $report['faculties'][] = ['action' => 'normalised', 'id' => (int) $faculty->id, 'from' => $before, 'to' => ['name' => $spec['name'], 'code' => $spec['code']]];
            $report['changed'] = true;
        }

        return $faculty;
    }

    private function ensureMajor(Department $old, array $spec, Department $faculty, array &$report): Program
    {
        $major = Program::whereRaw('UPPER(code) = ?', [strtoupper($spec['major_code'])])->first()
            ?? Program::where('department_id', $old->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($spec['major_name'])])->first();

        if (! $major) {
            $major = Program::create([
                'department_id' => $faculty->id,
                'code' => $spec['major_code'],
                'name' => $spec['major_name'],
                'duration_years' => 4,
                'status' => 'active',
            ]);
            $report['majors'][] = ['action' => 'created_from_department', 'id' => (int) $major->id, 'code' => $major->code, 'name' => $major->name, 'faculty_id' => (int) $faculty->id, 'source_department_id' => (int) $old->id];
            $report['changed'] = true;

            return $major;
        }

        $report['majors'][] = [
            'action' => 'existing_major_matched',
            'id' => (int) $major->id,
            'code' => $major->code,
            'name' => $major->name,
            'faculty_id' => (int) $faculty->id,
            'source_department_id' => (int) $old->id,
        ];

        // force the correct parent Faculty even if the row was not under the misclassified department
        if ((int) $major->department_id !== (int) $faculty->id) {
            $major->update(['department_id' => $faculty->id]);
            $report['changed'] = true;
        }

        return $major;
    }

    /** courses that used the misclassified row become assigned to the matching Major */
    private function linkCoursesToMajors(array $majorByOldDept, array &$report): void
    {
        if (! Schema::hasTable('course_program') || ! Schema::hasTable('courses')) {
            $report['warnings'][] = 'course_program table missing - run migrations first; courses were not linked to majors.';

            return;
        }

        $now = now();
        foreach ($majorByOldDept as $oldDeptId => $major) {
            $courseIds = DB::table('courses')->where('department_id', $oldDeptId)->pluck('id');
            $linked = 0;
            foreach ($courseIds as $courseId) {
                $inserted = DB::table('course_program')->insertOrIgnore([
                    'course_id' => $courseId,
                    'program_id' => $major->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $linked += (int) $inserted;
            }
            if ($courseIds->count() > 0) {
                $report['courses_linked'][] = ['major_code' => $major->code, 'major_id' => (int) $major->id, 'courses' => $courseIds->count(), 'newly_linked' => $linked];
                $report['changed'] = $report['changed'] || $linked > 0;
            }
        }
    }

    private function repointDepartmentForeignKeys(array $deptMap, array &$report): void
    {
        $facultyNamesById = Department::whereIn('id', array_values($deptMap))
            ->pluck('name', 'id')
            ->all();

        foreach ($this->tablesWithDepartmentColumn() as $table) {
            foreach ($deptMap as $oldId => $newId) {
                $count = DB::table($table)->where('department_id', $oldId)->count();
                if ($count === 0) {
                    continue;
                }
                $updates = ['department_id' => $newId];
                if ($table === 'courses' && Schema::hasColumn('courses', 'department') && isset($facultyNamesById[$newId])) {
                    $updates['department'] = $facultyNamesById[$newId];
                }
                DB::table($table)->where('department_id', $oldId)->update($updates);
                $report['repointed'][] = ['table' => $table, 'from_department_id' => $oldId, 'to_faculty_id' => $newId, 'rows' => $count];
                $report['changed'] = true;
            }
        }
    }

    private function deactivateLegacyDuplicates(array &$report): void
    {
        foreach (self::LEGACY_DUPLICATES as $legacyCode => $canonicalCode) {
            $legacy = Program::whereRaw('UPPER(code) = ?', [$legacyCode])->first();
            if (! $legacy || strtolower((string) $legacy->status) === 'inactive') {
                continue;
            }
            $canonical = Program::whereRaw('UPPER(code) = ?', [$canonicalCode])->first();
            if (! $canonical) {
                $report['warnings'][] = "Legacy major {$legacyCode} not deactivated: canonical major {$canonicalCode} does not exist.";
                continue;
            }
            $legacy->update(['status' => 'inactive']);
            $report['deactivated_majors'][] = ['id' => (int) $legacy->id, 'code' => $legacyCode, 'duplicate_of' => $canonicalCode];
            $report['changed'] = true;
        }
    }

    /** @return string[] */
    private function tablesWithDepartmentColumn(): array
    {
        $tables = [];
        foreach (Schema::getTables() as $t) {
            $name = $t['name'];
            if ($name !== 'departments' && Schema::hasColumn($name, 'department_id')) {
                $tables[] = $name;
            }
        }
        sort($tables);

        return $tables;
    }

    private function countReferences(int $departmentId): int
    {
        $total = 0;
        foreach ($this->tablesWithDepartmentColumn() as $table) {
            $total += DB::table($table)->where('department_id', $departmentId)->count();
        }

        return $total;
    }

    private function snapshot(): array
    {
        $out = [];
        foreach (self::SNAPSHOT_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $out[$table] = DB::table($table)->count();
            }
        }

        return $out;
    }
}
