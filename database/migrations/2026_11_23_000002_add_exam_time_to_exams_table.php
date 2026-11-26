<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            if (! Schema::hasColumn('exams', 'start_time')) {
                $table->string('start_time', 10)->nullable()->after('exam_date');
            }
            if (! Schema::hasColumn('exams', 'end_time')) {
                $table->string('end_time', 10)->nullable()->after('start_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            if (Schema::hasColumn('exams', 'end_time')) {
                $table->dropColumn('end_time');
            }
            if (Schema::hasColumn('exams', 'start_time')) {
                $table->dropColumn('start_time');
            }
        });
    }
};
