<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('buildings')) {
            Schema::create('buildings', function (Blueprint $table) {
                $table->id();
                $table->string('code', 30)->nullable()->unique();
                $table->string('name', 120)->unique();
                $table->text('description')->nullable();
                $table->string('status', 30)->default('active');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('floors')) {
            Schema::create('floors', function (Blueprint $table) {
                $table->id();
                $table->foreignId('building_id')->constrained('buildings')->cascadeOnDelete();
                $table->integer('floor_number');
                $table->string('name', 80);
                $table->string('status', 30)->default('active');
                $table->timestamps();

                $table->unique(['building_id', 'floor_number'], 'floors_building_number_unique');
            });
        }

        Schema::table('classes', function (Blueprint $table) {
            if (! Schema::hasColumn('classes', 'building_id')) {
                $table->foreignId('building_id')->nullable()->after('academic_year_id')
                    ->constrained('buildings')->nullOnDelete();
            }
            if (! Schema::hasColumn('classes', 'floor_id')) {
                $table->foreignId('floor_id')->nullable()->after('building_id')
                    ->constrained('floors')->nullOnDelete();
            }
            if (! Schema::hasColumn('classes', 'capacity')) {
                $table->unsignedInteger('capacity')->default(40)->after('room');
            }
        });

        if (! Schema::hasTable('class_schedules')) {
            Schema::create('class_schedules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
                $table->foreignId('semester_id')->nullable()->constrained('semesters')->nullOnDelete();
                $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
                $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
                $table->foreignId('building_id')->nullable()->constrained('buildings')->nullOnDelete();
                $table->foreignId('floor_id')->nullable()->constrained('floors')->nullOnDelete();
                $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
                $table->foreignId('course_offering_id')->nullable()->constrained('course_offerings')->nullOnDelete();
                $table->foreignId('teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
                $table->string('day_of_week', 30);
                $table->time('start_time');
                $table->time('end_time');
                $table->string('status', 30)->default('active');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['class_id', 'day_of_week', 'start_time', 'end_time'], 'idx_sched_class_slot');
                $table->index(['teacher_id', 'day_of_week', 'start_time', 'end_time'], 'idx_sched_teacher_slot');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('class_schedules');

        Schema::table('classes', function (Blueprint $table) {
            if (Schema::hasColumn('classes', 'floor_id')) {
                $table->dropForeign(['floor_id']);
                $table->dropColumn('floor_id');
            }
            if (Schema::hasColumn('classes', 'building_id')) {
                $table->dropForeign(['building_id']);
                $table->dropColumn('building_id');
            }
            if (Schema::hasColumn('classes', 'capacity')) {
                $table->dropColumn('capacity');
            }
        });

        Schema::dropIfExists('floors');
        Schema::dropIfExists('buildings');
    }
};
