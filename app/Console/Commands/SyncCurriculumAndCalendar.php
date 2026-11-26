<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\CurriculumItem;
use App\Models\MajorAcademicPeriod;
use App\Models\Program;
use App\Models\Semester;
use App\Models\Student;
use App\Services\GpaCalculationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncCurriculumAndCalendar extends Command
{
    protected $signature = 'slas:sync-curriculum-and-calendar';

    protected $description = 'Non-destructively sync existing course_program links, semesters, and student current period metadata into curriculum_items and major_academic_periods.';

    public function handle(GpaCalculationService $gpaService): int
    {
        $links = DB::table('course_program')->get();
        foreach ($links as $idx => $link) {
            $course = Course::find($link->course_id);
            if (! $course) {
                continue;
            }
            $studyYear = max(1, min(4, (int) ($course->year_level ?: 1)));
            $offering = DB::table('course_offerings')->where('course_id', $course->id)->orderBy('semester_id')->first();
            $semNum = 1;
            $semName = 'Semester 1';
            if ($offering && $offering->semester_id) {
                $sem = Semester::find($offering->semester_id);
                if ($sem) {
                    $semNum = (int) ($sem->semester_number ?: 1);
                    $semName = $sem->name ?: ('Semester ' . $semNum);
                }
            }

            CurriculumItem::firstOrCreate(
                [
                    'program_id' => $link->program_id,
                    'course_id' => $course->id,
                    'curriculum_version' => '2026',
                ],
                [
                    'course_category_id' => $course->course_category_id,
                    'study_year' => $studyYear,
                    'semester_number' => $semNum,
                    'semester_name' => $semName,
                    'credits' => (float) ($course->credit ?: $course->credits ?: 3.0),
                    'is_required' => true,
                    'display_order' => $idx + 1,
                    'status' => 'active',
                ]
            );
        }

        $semesters = Semester::with('academicYear')->orderBy('id')->get();
        $programs = Program::where('status', 'active')->get();
        foreach ($programs as $prog) {
            foreach ($semesters as $sIdx => $sem) {
                $semNum = (int) ($sem->semester_number ?: 1);
                MajorAcademicPeriod::firstOrCreate(
                    [
                        'program_id' => $prog->id,
                        'academic_year_id' => $sem->academic_year_id,
                        'study_year' => 1,
                        'semester_number' => $semNum,
                    ],
                    [
                        'semester_id' => $sem->id,
                        'semester_name' => $sem->name,
                        'sequence_order' => $sIdx + 1,
                        'start_date' => $sem->start_date ? $sem->start_date->toDateString() : '2026-09-01',
                        'end_date' => $sem->end_date ? $sem->end_date->toDateString() : '2026-12-31',
                        'status' => $sem->status ?: 'active',
                        'notes' => 'Synced from institutional semester schedule',
                    ]
                );
            }
        }

        foreach (Student::all() as $stu) {
            $cp = $gpaService->resolveCurrentPeriod($stu);
            $stu->update([
                'current_academic_year_id' => $stu->current_academic_year_id ?: $cp['academic_year_id'],
                'current_semester_id' => $stu->current_semester_id ?: $cp['semester_id'],
                'current_study_year' => $stu->current_study_year ?: $cp['study_year'],
                'current_semester_number' => $stu->current_semester_number ?: $cp['semester_number'],
                'curriculum_version' => $stu->curriculum_version ?: '2026',
                'progression_status' => $stu->progression_status ?: 'in_progress',
            ]);
        }

        $this->info('Curriculum items in DB: ' . CurriculumItem::count());
        $this->info('Major academic periods in DB: ' . MajorAcademicPeriod::count());

        return self::SUCCESS;
    }
}
