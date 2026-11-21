<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanupDemoTeachersAndStudents extends Command
{
    protected $signature = 'slas:cleanup-users {--force : Actually execute the cleanup inside a transaction}';

    protected $description = 'Safely remove all Teacher and Student accounts, profiles, and dependent records while preserving Admin, Academic Structure, Courses, and Classes.';

    public function handle(): int
    {
        $teacherUserIds = DB::table('users')->where('role', 'teacher')->pluck('id')->all();
        $studentUserIds = DB::table('users')->where('role', 'student')->pluck('id')->all();
        $targetUserIds = array_values(array_unique(array_merge($teacherUserIds, $studentUserIds)));

        $teacherIds = DB::table('teachers')->pluck('id')->all();
        $studentIds = DB::table('students')->pluck('id')->all();

        $this->info('Pre-cleanup summary:');
        $this->line('  Teachers: ' . count($teacherIds) . ' (users: ' . count($teacherUserIds) . ')');
        $this->line('  Students: ' . count($studentIds) . ' (users: ' . count($studentUserIds) . ')');
        $this->line('  Admins (preserved): ' . DB::table('admins')->count());

        if (! $this->option('force')) {
            $this->warn('Dry-run only. Re-run with --force after verifying backup.');
            return self::SUCCESS;
        }

        $deleted = [];

        DB::transaction(function () use ($teacherIds, $studentIds, $targetUserIds, &$deleted) {
            // 1. Nullify soft references on preserved core tables
            if (Schema::hasTable('courses')) {
                $deleted['courses_teacher_nulled'] = DB::table('courses')->whereNotNull('teacher_id')->update(['teacher_id' => null]);
            }
            if (Schema::hasTable('classes')) {
                $deleted['classes_teacher_nulled'] = DB::table('classes')->whereNotNull('teacher_id')->update(['teacher_id' => null]);
            }
            if (Schema::hasTable('specializations')) {
                DB::table('specializations')->whereIn('created_by', $targetUserIds)->update(['created_by' => null]);
            }
            if (Schema::hasTable('office_locations')) {
                DB::table('office_locations')->whereIn('created_by', $targetUserIds)->update(['created_by' => null]);
            }
            if (Schema::hasTable('student_imports')) {
                $deleted['student_import_rows'] = DB::table('student_import_rows')->delete();
                $deleted['student_imports'] = DB::table('student_imports')->delete();
            }

            // 2. Clean up teaching schedules/assignments/enrollments
            if (Schema::hasTable('class_schedules')) {
                $deleted['class_schedules'] = DB::table('class_schedules')->delete();
            }
            $deleted['class_teacher'] = Schema::hasTable('class_teacher') ? DB::table('class_teacher')->delete() : 0;
            $deleted['course_offering_teachers'] = Schema::hasTable('course_offering_teachers') ? DB::table('course_offering_teachers')->delete() : 0;
            $deleted['course_enrollments'] = Schema::hasTable('course_enrollments') ? DB::table('course_enrollments')->delete() : 0;
            $deleted['course_student'] = Schema::hasTable('course_student') ? DB::table('course_student')->delete() : 0;

            // 3. Clean up assessments (assignments, quizzes, exams)
            foreach (['assignment_attempts', 'assignment_questions', 'assignment_submissions', 'assignments'] as $tbl) {
                $deleted[$tbl] = Schema::hasTable($tbl) ? DB::table($tbl)->delete() : 0;
            }
            foreach (['quiz_answers', 'quiz_attempts', 'quiz_questions', 'quizzes'] as $tbl) {
                $deleted[$tbl] = Schema::hasTable($tbl) ? DB::table($tbl)->delete() : 0;
            }
            foreach (['exam_results', 'exams'] as $tbl) {
                $deleted[$tbl] = Schema::hasTable($tbl) ? DB::table($tbl)->delete() : 0;
            }

            // 4. Clean up attendance
            foreach (['attendance_details', 'attendance', 'attendances'] as $tbl) {
                $deleted[$tbl] = Schema::hasTable($tbl) ? DB::table($tbl)->delete() : 0;
            }

            // 5. Clean up grades, GPA, analytics, predictions, risk, recommendations, faculty reviews, incidents, reports
            foreach ([
                'grades',
                'gpa_records',
                'performance_records',
                'learning_analytics',
                'predictions',
                'risk_assessments',
                'recommendations',
                'faculty_reviews',
                'incidents',
            ] as $tbl) {
                $deleted[$tbl] = Schema::hasTable($tbl) ? DB::table($tbl)->delete() : 0;
            }

            if (Schema::hasTable('reports')) {
                $deleted['reports'] = DB::table('reports')
                    ->whereNotNull('student_id')
                    ->orWhereNotNull('teacher_id')
                    ->when(! empty($targetUserIds), fn ($q) => $q->orWhereIn('generated_by', $targetUserIds))
                    ->delete();
            }

            // 6. Clean up student & teacher profile auxiliary tables
            foreach ([
                'student_notes',
                'student_family_information',
                'student_emergency_contacts',
                'teacher_specialization',
            ] as $tbl) {
                $deleted[$tbl] = Schema::hasTable($tbl) ? DB::table($tbl)->delete() : 0;
            }

            if (Schema::hasTable('notifications') && ! empty($targetUserIds)) {
                $deleted['notifications'] = DB::table('notifications')->whereIn('user_id', $targetUserIds)->delete();
            }

            if (Schema::hasTable('personal_access_tokens') && ! empty($targetUserIds)) {
                $deleted['personal_access_tokens'] = DB::table('personal_access_tokens')
                    ->where('tokenable_type', \App\Models\User::class)
                    ->whereIn('tokenable_id', $targetUserIds)
                    ->delete();
            }

            // 7. Delete students, teachers, and teacher/student users
            $deleted['students'] = Schema::hasTable('students') ? DB::table('students')->delete() : 0;
            $deleted['teachers'] = Schema::hasTable('teachers') ? DB::table('teachers')->delete() : 0;
            $deleted['users_teacher_student'] = ! empty($targetUserIds)
                ? DB::table('users')->whereIn('id', $targetUserIds)->delete()
                : 0;
        });

        $this->info('Cleanup completed cleanly.');
        foreach ($deleted as $key => $count) {
            $this->line(sprintf('  %-30s %d', $key, $count));
        }

        return self::SUCCESS;
    }
}
