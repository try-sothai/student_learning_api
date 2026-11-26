<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Academic structure correction (additive only - no data is touched here):
 *
 *  - course_categories : a real, separate entity for the TYPE of a course
 *                        (General Education, Major Core, Major Elective, ...).
 *                        Previously the "Course Category" screens edited the
 *                        departments (faculty) table by mistake.
 *  - courses.course_category_id : each course may belong to one category.
 *  - course_program    : many-to-many pivot so a course can be assigned to one
 *                        or more Majors (programs) and shared between majors.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('course_categories')) {
            Schema::create('course_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('code', 30)->nullable()->unique();
                $table->text('description')->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('courses') && ! Schema::hasColumn('courses', 'course_category_id')) {
            Schema::table('courses', function (Blueprint $table) {
                $table->foreignId('course_category_id')
                    ->nullable()
                    ->constrained('course_categories')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('programs') && ! Schema::hasColumn('programs', 'description')) {
            Schema::table('programs', function (Blueprint $table) {
                $table->text('description')->nullable()->after('name');
            });
        }

        if (! Schema::hasTable('course_program')) {
            Schema::create('course_program', function (Blueprint $table) {
                $table->id();
                $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
                $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['course_id', 'program_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_program');

        if (Schema::hasTable('programs') && Schema::hasColumn('programs', 'description')) {
            Schema::table('programs', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }

        if (Schema::hasTable('courses') && Schema::hasColumn('courses', 'course_category_id')) {
            Schema::table('courses', function (Blueprint $table) {
                $table->dropConstrainedForeignId('course_category_id');
            });
        }

        Schema::dropIfExists('course_categories');
    }
};
