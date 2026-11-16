<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('course_offerings')) {
            Schema::create('course_offerings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
                $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
                $table->foreignId('semester_id')->constrained('semesters')->restrictOnDelete();
                $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
                $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
                $table->unsignedTinyInteger('study_year')->default(1);
                $table->string('section_name', 50)->nullable();
                $table->string('status', 20)->default('active');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['course_id', 'semester_id'], 'offerings_course_semester_index');
                $table->index(['class_id', 'status'], 'offerings_class_status_index');
                $table->index('status');
            });
        }

        if (! Schema::hasTable('course_offering_teachers')) {
            Schema::create('course_offering_teachers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('course_offering_id')->constrained('course_offerings')->cascadeOnDelete();
                $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
                $table->string('role', 30)->default('primary');
                $table->string('status', 20)->default('active');
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->timestamps();

                $table->index(['teacher_id', 'status'], 'offering_teachers_teacher_status_idx');
                $table->index(['course_offering_id', 'status'], 'offering_teachers_offering_status_idx');
            });
        }

        // Add nullable course_offering_id to learning records
        $tablesToExtend = [
            'course_enrollments',
            'attendance',
            'assignments',
            'quizzes',
            'grades',
            'risk_assessments',
        ];

        foreach ($tablesToExtend as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'course_offering_id')) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    $table->foreignId('course_offering_id')
                        ->nullable()
                        ->after('id')
                        ->constrained('course_offerings')
                        ->nullOnDelete();
                });
            }
        }

        // Backfill existing course_offerings from active courses, semesters, and teachers
        $this->backfillCourseOfferings();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tablesToExtend = [
            'risk_assessments',
            'grades',
            'quizzes',
            'assignments',
            'attendance',
            'course_enrollments',
        ];

        foreach ($tablesToExtend as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'course_offering_id')) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    $table->dropConstrainedForeignId('course_offering_id');
                });
            }
        }

        Schema::dropIfExists('course_offering_teachers');
        Schema::dropIfExists('course_offerings');
    }

    /**
     * Non-destructive backfill mapping existing learning data to offerings.
     */
    private function backfillCourseOfferings(): void
    {
        if (! Schema::hasTable('courses') || ! Schema::hasTable('semesters')) {
            return;
        }

        // 1. Identify distinct (course_id, semester_id) pairings across courses and enrollments
        $distinctPairs = DB::table('course_enrollments')
            ->select('course_id', 'semester_id')
            ->whereNotNull('semester_id')
            ->groupBy('course_id', 'semester_id')
            ->get();

        if ($distinctPairs->isEmpty()) {
            // Fall back to pairing all active courses with active semesters
            $activeSemesters = DB::table('semesters')->where('status', 'active')->get();
            $courses = DB::table('courses')->whereNull('deleted_at')->get();
            $distinctPairs = collect();
            foreach ($courses as $c) {
                foreach ($activeSemesters as $s) {
                    $distinctPairs->push((object) ['course_id' => $c->id, 'semester_id' => $s->id]);
                }
            }
        }

        foreach ($distinctPairs as $pair) {
            $course = DB::table('courses')->where('id', $pair->course_id)->first();
            $semester = DB::table('semesters')->where('id', $pair->semester_id)->first();

            if (! $course || ! $semester) {
                continue;
            }

            // Find associated class from students enrolled in this pair
            $sampleClassId = DB::table('course_enrollments')
                ->join('students', 'students.id', '=', 'course_enrollments.student_id')
                ->where('course_enrollments.course_id', $pair->course_id)
                ->where('course_enrollments.semester_id', $pair->semester_id)
                ->whereNotNull('students.class_id')
                ->value('students.class_id');

            if (! $sampleClassId) {
                $sampleClassId = DB::table('classes')->where('status', 'active')->value('id');
            }

            $class = $sampleClassId ? DB::table('classes')->where('id', $sampleClassId)->first() : null;

            $programId = DB::table('course_enrollments')
                ->join('students', 'students.id', '=', 'course_enrollments.student_id')
                ->where('course_enrollments.course_id', $pair->course_id)
                ->where('course_enrollments.semester_id', $pair->semester_id)
                ->whereNotNull('students.program_id')
                ->value('students.program_id');

            $studyYear = $class ? ($class->year_level ?? 1) : 1;
            $sectionName = $class ? $class->name : 'Class A';

            $existingOffering = DB::table('course_offerings')
                ->where('course_id', $pair->course_id)
                ->where('semester_id', $pair->semester_id)
                ->when($sampleClassId, fn ($q) => $q->where('class_id', $sampleClassId))
                ->first();

            if ($existingOffering) {
                $offeringId = $existingOffering->id;
            } else {
                $offeringId = DB::table('course_offerings')->insertGetId([
                    'course_id' => $pair->course_id,
                    'class_id' => $sampleClassId,
                    'semester_id' => $pair->semester_id,
                    'academic_year_id' => $semester->academic_year_id,
                    'program_id' => $programId,
                    'study_year' => $studyYear,
                    'section_name' => $sectionName,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Assign teacher if course has a teacher or attendance/assignment has one
            $teacherId = $course->teacher_id;
            if (! $teacherId) {
                $teacherId = DB::table('assignments')->where('course_id', $pair->course_id)->whereNotNull('teacher_id')->value('teacher_id');
            }
            if (! $teacherId) {
                $teacherId = DB::table('attendance')->where('course_id', $pair->course_id)->whereNotNull('teacher_id')->value('teacher_id');
            }
            if (! $teacherId) {
                $teacherId = DB::table('teachers')->where('status', 'active')->value('id');
            }

            if ($teacherId) {
                $hasTeacher = DB::table('course_offering_teachers')
                    ->where('course_offering_id', $offeringId)
                    ->where('teacher_id', $teacherId)
                    ->where('status', 'active')
                    ->exists();

                if (! $hasTeacher) {
                    DB::table('course_offering_teachers')->insert([
                        'course_offering_id' => $offeringId,
                        'teacher_id' => $teacherId,
                        'role' => 'primary',
                        'status' => 'active',
                        'assigned_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Backfill foreign key on learning rows
            DB::table('course_enrollments')
                ->where('course_id', $pair->course_id)
                ->where('semester_id', $pair->semester_id)
                ->whereNull('course_offering_id')
                ->update(['course_offering_id' => $offeringId]);

            DB::table('attendance')
                ->where('course_id', $pair->course_id)
                ->where('semester_id', $pair->semester_id)
                ->whereNull('course_offering_id')
                ->update(['course_offering_id' => $offeringId]);

            DB::table('assignments')
                ->where('course_id', $pair->course_id)
                ->where('semester_id', $pair->semester_id)
                ->whereNull('course_offering_id')
                ->update(['course_offering_id' => $offeringId]);

            DB::table('quizzes')
                ->where('course_id', $pair->course_id)
                ->where('semester_id', $pair->semester_id)
                ->whereNull('course_offering_id')
                ->update(['course_offering_id' => $offeringId]);

            DB::table('grades')
                ->where('course_id', $pair->course_id)
                ->where('semester_id', $pair->semester_id)
                ->whereNull('course_offering_id')
                ->update(['course_offering_id' => $offeringId]);

            DB::table('risk_assessments')
                ->where('course_id', $pair->course_id)
                ->where('semester_id', $pair->semester_id)
                ->whereNull('course_offering_id')
                ->update(['course_offering_id' => $offeringId]);
        }
    }
};
