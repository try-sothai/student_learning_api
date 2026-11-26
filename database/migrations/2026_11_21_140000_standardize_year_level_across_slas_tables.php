<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Safely integrates Student Year Level (1..4 / Y1..Y4) across SLAS tables
 * without altering existing student records or dropping/recreating tables.
 *
 * - Adds nullable `courses.year_level` (optional target year; NULL = cross-year).
 * - Adds nullable `course_enrollments.year_level` (snapshots enrollment year level).
 * - Indexes `students.year_level` and `course_offerings.study_year`.
 * - Normalizes only explicit Year 1-4 strings ('Year 2', 'Y2') on `students.year_level`
 *   to canonical numeric string '1'..'4'. Leaves NULL or unknown values untouched.
 * - Backfills `course_enrollments.year_level` only from linked `course_offerings.study_year`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('courses')) {
            Schema::table('courses', function (Blueprint $table) {
                if (! Schema::hasColumn('courses', 'year_level')) {
                    $table->unsignedTinyInteger('year_level')->nullable()->after('department_id');
                }
            });

            if (! Schema::hasIndex('courses', 'courses_year_level_index')) {
                Schema::table('courses', function (Blueprint $table) {
                    $table->index('year_level', 'courses_year_level_index');
                });
            }
        }

        if (Schema::hasTable('course_enrollments')) {
            Schema::table('course_enrollments', function (Blueprint $table) {
                if (! Schema::hasColumn('course_enrollments', 'year_level')) {
                    $table->unsignedTinyInteger('year_level')->nullable()->after('semester_id');
                }
            });

            if (! Schema::hasIndex('course_enrollments', 'course_enrollments_year_level_index')) {
                Schema::table('course_enrollments', function (Blueprint $table) {
                    $table->index('year_level', 'course_enrollments_year_level_index');
                });
            }

            if (Schema::hasTable('course_offerings')) {
                DB::table('course_enrollments')
                    ->whereNotNull('course_offering_id')
                    ->whereNull('year_level')
                    ->orderBy('id')
                    ->chunkById(200, function ($rows) {
                        $offeringIds = $rows->pluck('course_offering_id')->filter()->unique()->all();
                        $studyYears = DB::table('course_offerings')
                            ->whereIn('id', $offeringIds)
                            ->pluck('study_year', 'id');

                        foreach ($rows as $row) {
                            $sy = $studyYears->get($row->course_offering_id);
                            if ($sy !== null && (int) $sy >= 1 && (int) $sy <= 4) {
                                DB::table('course_enrollments')
                                    ->where('id', $row->id)
                                    ->update(['year_level' => (int) $sy]);
                            }
                        }
                    });
            }
        }

        if (Schema::hasTable('students')) {
            if (! Schema::hasIndex('students', 'students_year_level_index')) {
                Schema::table('students', function (Blueprint $table) {
                    $table->index('year_level', 'students_year_level_index');
                });
            }

            DB::table('students')
                ->whereNotNull('year_level')
                ->orderBy('id')
                ->chunkById(200, function ($rows) {
                    foreach ($rows as $row) {
                        $raw = trim((string) $row->year_level);
                        if ($raw === '' || preg_match('/\d{4}/', $raw)) {
                            continue;
                        }
                        $normalized = null;
                        if (preg_match('/^(?:year|y|yr)?\s*0*([1-4])$/iu', $raw, $m)) {
                            $normalized = $m[1];
                        } elseif (preg_match('/^ឆ្នាំទី\s*([1-4១២៣៤])$/u', $raw, $m)) {
                            $normalized = strtr($m[1], ['១' => '1', '២' => '2', '៣' => '3', '៤' => '4']);
                        }
                        if ($normalized !== null && $normalized !== $raw) {
                            DB::table('students')
                                ->where('id', $row->id)
                                ->update(['year_level' => $normalized]);
                        }
                    }
                });
        }

        if (Schema::hasTable('course_offerings') && Schema::hasColumn('course_offerings', 'study_year')) {
            if (! Schema::hasIndex('course_offerings', 'course_offerings_study_year_index')) {
                Schema::table('course_offerings', function (Blueprint $table) {
                    $table->index('study_year', 'course_offerings_study_year_index');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('course_offerings') && Schema::hasIndex('course_offerings', 'course_offerings_study_year_index')) {
            Schema::table('course_offerings', function (Blueprint $table) {
                $table->dropIndex('course_offerings_study_year_index');
            });
        }

        if (Schema::hasTable('students') && Schema::hasIndex('students', 'students_year_level_index')) {
            Schema::table('students', function (Blueprint $table) {
                $table->dropIndex('students_year_level_index');
            });
        }

        if (Schema::hasTable('course_enrollments')) {
            if (Schema::hasIndex('course_enrollments', 'course_enrollments_year_level_index')) {
                Schema::table('course_enrollments', function (Blueprint $table) {
                    $table->dropIndex('course_enrollments_year_level_index');
                });
            }
            if (Schema::hasColumn('course_enrollments', 'year_level')) {
                Schema::table('course_enrollments', function (Blueprint $table) {
                    $table->dropColumn('year_level');
                });
            }
        }

        if (Schema::hasTable('courses')) {
            if (Schema::hasIndex('courses', 'courses_year_level_index')) {
                Schema::table('courses', function (Blueprint $table) {
                    $table->dropIndex('courses_year_level_index');
                });
            }
            if (Schema::hasColumn('courses', 'year_level')) {
                Schema::table('courses', function (Blueprint $table) {
                    $table->dropColumn('year_level');
                });
            }
        }
    }
};
