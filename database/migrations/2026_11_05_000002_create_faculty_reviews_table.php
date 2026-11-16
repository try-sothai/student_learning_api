<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faculty Evaluators: no table stores evaluations of teachers. This table
 * holds one review per teacher (optionally per semester) with a 1-5 rating
 * and a completed / pending status. Student Rating is the average of the
 * completed ratings, so nothing is invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('faculty_reviews')) {
            return;
        }

        Schema::create('faculty_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->foreignId('semester_id')->nullable()->constrained('semesters')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('rating', 3, 2)->nullable();
            $table->string('status', 20)->default('pending'); // pending | completed
            $table->text('comments')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['teacher_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faculty_reviews');
    }
};
