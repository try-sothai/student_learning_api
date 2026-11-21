<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('office_locations')) {
            Schema::create('office_locations', function (Blueprint $table) {
                $table->id();
                $table->string('building', 100);
                $table->string('room_number', 60);
                $table->string('display_name', 191)->unique();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['building', 'room_number'], 'office_locations_building_room_index');
            });
        }

        if (! Schema::hasTable('specializations')) {
            Schema::create('specializations', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120)->unique();
                $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('teacher_specialization')) {
            Schema::create('teacher_specialization', function (Blueprint $table) {
                $table->id();
                $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
                $table->foreignId('specialization_id')->constrained('specializations')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['teacher_id', 'specialization_id'], 'teacher_specialization_unique');
            });
        }

        if (Schema::hasTable('teachers')) {
            Schema::table('teachers', function (Blueprint $table) {
                if (! Schema::hasColumn('teachers', 'office_location_id')) {
                    $table->foreignId('office_location_id')
                        ->nullable()
                        ->after('phone')
                        ->constrained('office_locations')
                        ->nullOnDelete();
                }

                if (! Schema::hasColumn('teachers', 'work_type')) {
                    $table->string('work_type', 50)->nullable()->default('Full-time')->after('position');
                }

                if (! Schema::hasColumn('teachers', 'biography')) {
                    $table->text('biography')->nullable()->after('specialization');
                }
            });

            Schema::table('teachers', function (Blueprint $table) {
                $table->text('specialization')->nullable()->change();
            });
        }

        $this->seedCatalogAndBackfill();
    }

    public function down(): void
    {
        if (Schema::hasTable('teacher_specialization')) {
            Schema::dropIfExists('teacher_specialization');
        }

        if (Schema::hasTable('teachers')) {
            if (Schema::hasColumn('teachers', 'office_location_id')) {
                Schema::table('teachers', function (Blueprint $table) {
                    $table->dropForeign(['office_location_id']);
                    $table->dropColumn('office_location_id');
                });
            }

            Schema::table('teachers', function (Blueprint $table) {
                if (Schema::hasColumn('teachers', 'work_type')) {
                    $table->dropColumn('work_type');
                }
                if (Schema::hasColumn('teachers', 'biography')) {
                    $table->dropColumn('biography');
                }
            });
        }

        Schema::dropIfExists('specializations');
        Schema::dropIfExists('office_locations');
    }

    private function seedCatalogAndBackfill(): void
    {
        $now = now();

        $defaultOffices = [
            ['Building A', 'Room 101'],
            ['Building A', 'Room 102'],
            ['Building A', 'Room 103'],
            ['Building A', 'Room 201'],
            ['Building A', 'Room 202'],
            ['Building A', 'Room 203'],
            ['Building B', 'Room 101'],
            ['Building B', 'Room 102'],
            ['Building B', 'Room 201'],
            ['Building B', 'Room 202'],
            ['Building C', 'Room 101'],
            ['Building C', 'Room 102'],
            ['Faculty Center', 'Office 101'],
            ['Faculty Center', 'Office 102'],
        ];

        foreach ($defaultOffices as [$building, $room]) {
            $displayName = "{$building}, {$room}";
            DB::table('office_locations')->updateOrInsert(
                ['display_name' => $displayName],
                [
                    'building' => $building,
                    'room_number' => $room,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $defaultSpecializations = [
            'Algorithms',
            'Artificial Intelligence',
            'Business Analytics',
            'Cloud Computing',
            'Cybersecurity',
            'Data Science',
            'Data Structures',
            'Database Systems',
            'Databases',
            'Machine Learning',
            'Mobile Development',
            'Networking',
            'Operating Systems',
            'Software Engineering',
            'Web Development',
        ];

        foreach ($defaultSpecializations as $name) {
            DB::table('specializations')->updateOrInsert(
                ['name' => $name],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }

        // Backfill existing teachers' specializations into the relational pivot cleanly.
        if (Schema::hasTable('teachers')) {
            $teachers = DB::table('teachers')->whereNotNull('specialization')->get(['id', 'specialization']);
            foreach ($teachers as $teacher) {
                $parts = array_values(array_filter(array_map('trim', explode(',', (string) $teacher->specialization))));
                foreach ($parts as $part) {
                    $existing = DB::table('specializations')
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower($part)])
                        ->first();
                    if ($existing) {
                        $specId = $existing->id;
                    } else {
                        $specId = DB::table('specializations')->insertGetId([
                            'name' => $part,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                    DB::table('teacher_specialization')->updateOrInsert(
                        ['teacher_id' => $teacher->id, 'specialization_id' => $specId],
                        ['created_at' => $now, 'updated_at' => $now]
                    );
                }
            }
        }
    }
};
