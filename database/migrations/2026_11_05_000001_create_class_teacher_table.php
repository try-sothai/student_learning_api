<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faculty Evaluators: the existing schema links teachers to courses
 * (courses.teacher_id) but has NO link between teachers and classes.
 * This pivot records which teacher teaches which class (optionally for a
 * specific course). Necessary for the "Assigned Classes" feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('class_teacher')) {
            return;
        }

        Schema::create('class_teacher', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->timestamps();

            $table->unique(['class_id', 'teacher_id', 'course_id'], 'class_teacher_unique');
            $table->index('teacher_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_teacher');
    }
};
