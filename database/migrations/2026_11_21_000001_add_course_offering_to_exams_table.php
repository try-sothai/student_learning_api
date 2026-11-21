<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links exams to a course offering (the existing "teaching assignment":
 * course + class + semester + academic year + teacher), exactly like assignments
 * and quizzes already are. Non-destructive: adds nullable columns and back-fills.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            if (! Schema::hasColumn('exams', 'course_offering_id')) {
                $table->foreignId('course_offering_id')->nullable()->after('id')
                    ->constrained('course_offerings')->nullOnDelete();
            }
            if (! Schema::hasColumn('exams', 'venue')) {
                $table->string('venue', 150)->nullable()->after('duration_minutes');
            }
        });

        $this->backfill('exams', 'teacher_id');
        $this->backfill('assignments', 'teacher_id');
        $this->backfill('quizzes', 'teacher_id');
    }

    /**
     * Resolve course_offering_id for legacy rows from course + semester,
     * preferring the offering the row's own teacher is assigned to.
     */
    private function backfill(string $table, string $teacherColumn): void
    {
        $rows = DB::table($table)->whereNull('course_offering_id')->get(['id', 'course_id', 'semester_id', $teacherColumn]);

        foreach ($rows as $row) {
            $candidates = DB::table('course_offerings')
                ->where('course_id', $row->course_id)
                ->where('semester_id', $row->semester_id)
                ->whereNull('deleted_at')
                ->pluck('id');

            if ($candidates->isEmpty()) {
                continue;
            }

            $offeringId = null;
            if ($row->{$teacherColumn}) {
                $offeringId = DB::table('course_offering_teachers')
                    ->whereIn('course_offering_id', $candidates)
                    ->where('teacher_id', $row->{$teacherColumn})
                    ->where('status', 'active')
                    ->value('course_offering_id');
            }

            DB::table($table)->where('id', $row->id)->update([
                'course_offering_id' => $offeringId ?: $candidates->first(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            if (Schema::hasColumn('exams', 'course_offering_id')) {
                $table->dropConstrainedForeignId('course_offering_id');
            }
            if (Schema::hasColumn('exams', 'venue')) {
                $table->dropColumn('venue');
            }
        });
    }
};
