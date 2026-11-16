<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why: bulk import needs persistent tracking (history, progress, row-level
 * errors, who imported). Row errors are stored on student_import_rows.errors,
 * so no separate errors table is created. Temporary passwords are kept only
 * ENCRYPTED (Crypt) until the admin downloads credentials once, then nulled.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_imports')) {
            Schema::create('student_imports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('original_filename', 255);
                $table->string('stored_path', 500)->nullable();
                // pending | processing | completed | completed_with_errors | failed | cancelled
                $table->string('status', 30)->default('pending');
                $table->unsignedInteger('total_rows')->default(0);
                $table->unsignedInteger('valid_rows')->default(0);
                $table->unsignedInteger('invalid_rows')->default(0);
                $table->unsignedInteger('duplicate_rows')->default(0);
                $table->unsignedInteger('processed_rows')->default(0);
                $table->unsignedInteger('success_rows')->default(0);
                $table->unsignedInteger('failed_rows')->default(0);
                $table->unsignedInteger('skipped_rows')->default(0);
                $table->boolean('cancel_requested')->default(false);
                $table->text('failure_message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at']);
                $table->index('user_id');
            });
        }

        if (! Schema::hasTable('student_import_rows')) {
            Schema::create('student_import_rows', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_import_id')->constrained('student_imports')->cascadeOnDelete();
                $table->unsignedInteger('row_number');
                $table->string('student_identifier', 60)->nullable();
                // valid | invalid | duplicate | imported | failed | skipped
                $table->string('status', 20)->default('valid');
                $table->json('data')->nullable();       // normalized row, never contains a password
                $table->json('errors')->nullable();     // list of messages
                $table->text('temp_password_encrypted')->nullable();
                $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
                $table->timestamps();

                $table->unique(['student_import_id', 'row_number'], 'student_import_rows_unique');
                $table->index(['student_import_id', 'status']);
                $table->index('student_identifier');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_import_rows');
        Schema::dropIfExists('student_imports');
    }
};
