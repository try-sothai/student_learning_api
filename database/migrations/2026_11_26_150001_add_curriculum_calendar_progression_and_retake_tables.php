<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, non-destructive migration for:
 *  - Phase 3: Study Curriculum Management (curriculum_items)
 *  - Phase 4: Flexible Major-Specific Academic Calendar & Audit History (major_academic_periods, academic_calendar_audits)
 *  - Phase 5-7: Student Progression & Cross-Cohort Retake Management (student_progression_histories, student_course_retakes,
 *               plus additive columns on students, course_enrollments, grades, and gpa_records).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('curriculum_items')) {
            Schema::create('curriculum_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
                $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
                $table->foreignId('course_category_id')->nullable()->constrained('course_categories')->nullOnDelete();
                $table->string('curriculum_version', 30)->default('2026')->index();
                $table->unsignedTinyInteger('study_year')->default(1)->index();
                $table->unsignedTinyInteger('semester_number')->default(1)->index();
                $table->string('semester_name', 80)->nullable();
                $table->decimal('credits', 4, 1)->nullable();
                $table->boolean('is_required')->default(true);
                $table->unsignedSmallInteger('display_order')->default(1);
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();

                $table->unique(
                    ['program_id', 'curriculum_version', 'study_year', 'semester_number', 'course_id'],
                    'curriculum_prog_ver_yr_sem_course_unique'
                );
            });
        }

        if (! Schema::hasTable('major_academic_periods')) {
            Schema::create('major_academic_periods', function (Blueprint $table) {
                $table->id();
                $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
                $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
                $table->foreignId('semester_id')->nullable()->constrained('semesters')->nullOnDelete();
                $table->unsignedTinyInteger('study_year')->default(1)->index();
                $table->unsignedTinyInteger('semester_number')->default(1)->index();
                $table->string('semester_name', 100);
                $table->unsignedSmallInteger('sequence_order')->default(1);
                $table->date('start_date');
                $table->date('end_date');
                $table->string('status', 20)->default('upcoming')->index();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(
                    ['program_id', 'academic_year_id', 'study_year', 'semester_number'],
                    'major_period_prog_ay_yr_sem_unique'
                );
            });
        }

        if (! Schema::hasTable('academic_calendar_audits')) {
            Schema::create('academic_calendar_audits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('major_academic_period_id')->nullable()->constrained('major_academic_periods')->nullOnDelete();
                $table->foreignId('semester_id')->nullable()->constrained('semesters')->nullOnDelete();
                $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
                $table->date('previous_start_date')->nullable();
                $table->date('new_start_date')->nullable();
                $table->date('previous_end_date')->nullable();
                $table->date('new_end_date')->nullable();
                $table->string('previous_status', 20)->nullable();
                $table->string('new_status', 20)->nullable();
                $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('reason')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('students')) {
            Schema::table('students', function (Blueprint $table) {
                if (! Schema::hasColumn('students', 'current_academic_year_id')) {
                    $table->foreignId('current_academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
                }
                if (! Schema::hasColumn('students', 'current_semester_id')) {
                    $table->foreignId('current_semester_id')->nullable()->constrained('semesters')->nullOnDelete();
                }
                if (! Schema::hasColumn('students', 'current_study_year')) {
                    $table->unsignedTinyInteger('current_study_year')->nullable()->index();
                }
                if (! Schema::hasColumn('students', 'current_semester_number')) {
                    $table->unsignedTinyInteger('current_semester_number')->nullable();
                }
                if (! Schema::hasColumn('students', 'curriculum_version')) {
                    $table->string('curriculum_version', 30)->nullable()->default('2026');
                }
                if (! Schema::hasColumn('students', 'progression_status')) {
                    $table->string('progression_status', 40)->default('in_progress')->index();
                }
                if (! Schema::hasColumn('students', 'last_progressed_at')) {
                    $table->timestamp('last_progressed_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('course_enrollments')) {
            Schema::table('course_enrollments', function (Blueprint $table) {
                if (! Schema::hasColumn('course_enrollments', 'academic_year_id')) {
                    $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
                }
                if (! Schema::hasColumn('course_enrollments', 'semester_number')) {
                    $table->unsignedTinyInteger('semester_number')->nullable();
                }
                if (! Schema::hasColumn('course_enrollments', 'enrollment_type')) {
                    $table->string('enrollment_type', 20)->default('regular')->index();
                }
                if (! Schema::hasColumn('course_enrollments', 'attempt_number')) {
                    $table->unsignedTinyInteger('attempt_number')->default(1);
                }
                if (! Schema::hasColumn('course_enrollments', 'original_enrollment_id')) {
                    $table->foreignId('original_enrollment_id')->nullable()->constrained('course_enrollments')->nullOnDelete();
                }
                if (! Schema::hasColumn('course_enrollments', 'retake_status')) {
                    $table->string('retake_status', 30)->nullable()->index();
                }
                if (! Schema::hasColumn('course_enrollments', 'finalized_at')) {
                    $table->timestamp('finalized_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('grades')) {
            Schema::table('grades', function (Blueprint $table) {
                if (! Schema::hasColumn('grades', 'study_year')) {
                    $table->unsignedTinyInteger('study_year')->nullable()->index();
                }
                if (! Schema::hasColumn('grades', 'semester_number')) {
                    $table->unsignedTinyInteger('semester_number')->nullable();
                }
                if (! Schema::hasColumn('grades', 'attempt_number')) {
                    $table->unsignedTinyInteger('attempt_number')->default(1);
                }
                if (! Schema::hasColumn('grades', 'is_retake')) {
                    $table->boolean('is_retake')->default(false)->index();
                }
                if (! Schema::hasColumn('grades', 'original_grade_id')) {
                    $table->foreignId('original_grade_id')->nullable()->constrained('grades')->nullOnDelete();
                }
                if (! Schema::hasColumn('grades', 'finalized_at')) {
                    $table->timestamp('finalized_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('gpa_records')) {
            Schema::table('gpa_records', function (Blueprint $table) {
                if (! Schema::hasColumn('gpa_records', 'academic_year_id')) {
                    $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
                }
                if (! Schema::hasColumn('gpa_records', 'study_year')) {
                    $table->unsignedTinyInteger('study_year')->nullable()->index();
                }
                if (! Schema::hasColumn('gpa_records', 'semester_number')) {
                    $table->unsignedTinyInteger('semester_number')->nullable();
                }
                if (! Schema::hasColumn('gpa_records', 'semester_average')) {
                    $table->decimal('semester_average', 5, 2)->nullable();
                }
                if (! Schema::hasColumn('gpa_records', 'is_official')) {
                    $table->boolean('is_official')->default(true);
                }
            });
        }

        if (! Schema::hasTable('student_progression_histories')) {
            Schema::create('student_progression_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
                $table->foreignId('from_academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
                $table->foreignId('from_semester_id')->nullable()->constrained('semesters')->nullOnDelete();
                $table->unsignedTinyInteger('from_study_year')->nullable();
                $table->unsignedTinyInteger('from_semester_number')->nullable();
                $table->foreignId('to_academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
                $table->foreignId('to_semester_id')->nullable()->constrained('semesters')->nullOnDelete();
                $table->unsignedTinyInteger('to_study_year')->nullable();
                $table->unsignedTinyInteger('to_semester_number')->nullable();
                $table->unsignedSmallInteger('passed_courses_count')->default(0);
                $table->unsignedSmallInteger('failed_courses_count')->default(0);
                $table->unsignedSmallInteger('enrolled_next_courses_count')->default(0);
                $table->string('status', 40)->default('progressed')->index();
                $table->string('trigger_source', 40)->default('grade_finalized');
                $table->text('notes')->nullable();
                $table->timestamp('progressed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('student_course_retakes')) {
            Schema::create('student_course_retakes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
                $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
                $table->foreignId('failed_enrollment_id')->nullable()->constrained('course_enrollments')->nullOnDelete();
                $table->foreignId('failed_grade_id')->nullable()->constrained('grades')->nullOnDelete();
                $table->foreignId('failed_semester_id')->nullable()->constrained('semesters')->nullOnDelete();
                $table->unsignedTinyInteger('failed_study_year')->nullable();
                $table->unsignedTinyInteger('failed_semester_number')->nullable();
                $table->decimal('original_score', 5, 2)->nullable();
                $table->string('original_letter_grade', 5)->nullable();
                $table->foreignId('retake_enrollment_id')->nullable()->constrained('course_enrollments')->nullOnDelete();
                $table->foreignId('retake_offering_id')->nullable()->constrained('course_offerings')->nullOnDelete();
                $table->foreignId('retake_semester_id')->nullable()->constrained('semesters')->nullOnDelete();
                $table->foreignId('retake_grade_id')->nullable()->constrained('grades')->nullOnDelete();
                $table->decimal('retake_score', 5, 2)->nullable();
                $table->string('retake_letter_grade', 5)->nullable();
                $table->unsignedTinyInteger('attempt_number')->default(2);
                $table->string('status', 30)->default('required')->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_course_retakes');
        Schema::dropIfExists('student_progression_histories');
        Schema::dropIfExists('academic_calendar_audits');
        Schema::dropIfExists('major_academic_periods');
        Schema::dropIfExists('curriculum_items');
    }
};
