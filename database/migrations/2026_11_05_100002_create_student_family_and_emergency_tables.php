<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Why: no table stores parents or emergency contacts for a student. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_family_information')) {
            Schema::create('student_family_information', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->unique()->constrained('students')->cascadeOnDelete();
                $table->string('father_khmer_name', 150)->nullable();
                $table->string('father_english_name', 150)->nullable();
                $table->string('father_phone', 30)->nullable();
                $table->string('mother_khmer_name', 150)->nullable();
                $table->string('mother_english_name', 150)->nullable();
                $table->string('mother_phone', 30)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('student_emergency_contacts')) {
            Schema::create('student_emergency_contacts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->string('name', 150);
                $table->string('relationship', 80)->nullable();
                $table->string('phone', 30)->nullable();
                $table->string('address', 255)->nullable();
                $table->timestamps();

                $table->index('student_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_emergency_contacts');
        Schema::dropIfExists('student_family_information');
    }
};
