<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('student_notes')) {
            Schema::create('student_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
                $table->text('note');
                $table->timestamps();

                $table->index(['student_id', 'updated_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_notes');
    }
};
