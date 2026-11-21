<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            if (! Schema::hasColumn('teachers', 'khmer_name')) {
                $table->string('khmer_name', 150)->nullable()->after('teacher_code');
            }
            if (! Schema::hasColumn('teachers', 'english_name')) {
                $table->string('english_name', 150)->nullable()->after('khmer_name');
            }
            if (! Schema::hasColumn('teachers', 'hire_date')) {
                $table->date('hire_date')->nullable()->after('date_of_birth');
            }
        });

        // Preserve existing teacher display names cleanly in english_name where empty.
        DB::table('teachers')->whereNull('english_name')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $combined = trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? ''));
                if ($combined !== '') {
                    DB::table('teachers')->where('id', $row->id)->update(['english_name' => $combined]);
                }
            }
        });

        Schema::table('students', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            foreach (['hire_date', 'english_name', 'khmer_name'] as $col) {
                if (Schema::hasColumn('teachers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
