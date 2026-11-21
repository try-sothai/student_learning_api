<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_code', 32)->unique();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('category', 60)->default('Academic Risk');
            $table->string('priority', 30)->default('Medium Priority');
            $table->string('severity', 20)->default('medium');
            $table->string('status', 30)->default('Open');
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'priority']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
