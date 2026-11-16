<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why: the schema has no `programs` table (students.major is free text), and
 * students lack Khmer/English names and place of birth required by the
 * university Add Student form. Email must be optional for university students
 * (they log in with students.student_code via AuthController::findUserByLoginId),
 * so users.email becomes nullable. MySQL unique indexes allow multiple NULLs.
 * Existing rows are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('programs')) {
            Schema::create('programs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
                $table->string('code', 30)->unique();
                $table->string('name', 150);
                $table->unsignedTinyInteger('duration_years')->default(4);
                $table->string('status', 20)->default('active');
                $table->timestamps();

                $table->index('status');
            });
        }

        Schema::table('students', function (Blueprint $table) {
            if (! Schema::hasColumn('students', 'khmer_name')) {
                $table->string('khmer_name', 150)->nullable()->after('student_code');
            }
            if (! Schema::hasColumn('students', 'english_name')) {
                $table->string('english_name', 150)->nullable()->after('khmer_name');
            }
            if (! Schema::hasColumn('students', 'place_of_birth')) {
                $table->string('place_of_birth', 190)->nullable()->after('date_of_birth');
            }
            if (! Schema::hasColumn('students', 'program_id')) {
                $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (Schema::hasColumn('students', 'program_id')) {
                $table->dropConstrainedForeignId('program_id');
            }
            foreach (['place_of_birth', 'english_name', 'khmer_name'] as $col) {
                if (Schema::hasColumn('students', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        // users.email is intentionally left nullable on rollback: restoring NOT NULL
        // would fail once students without email exist.
        Schema::dropIfExists('programs');
    }
};
