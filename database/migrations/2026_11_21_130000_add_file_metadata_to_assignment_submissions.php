<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignment_submissions', function (Blueprint $table) {
            if (! Schema::hasColumn('assignment_submissions', 'original_file_name')) {
                $table->string('original_file_name', 255)->nullable()->after('file_path');
            }
            if (! Schema::hasColumn('assignment_submissions', 'mime_type')) {
                $table->string('mime_type', 120)->nullable()->after('original_file_name');
            }
            if (! Schema::hasColumn('assignment_submissions', 'file_size')) {
                $table->unsignedBigInteger('file_size')->nullable()->after('mime_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('assignment_submissions', function (Blueprint $table) {
            foreach (['file_size', 'mime_type', 'original_file_name'] as $column) {
                if (Schema::hasColumn('assignment_submissions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
