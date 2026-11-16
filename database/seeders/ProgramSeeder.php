<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Program;
use Illuminate\Database\Seeder;

class ProgramSeeder extends Seeder
{
    public function run(): void
    {
        $csId = Department::where('code', 'CS')->value('id') ?? 1;
        $itId = Department::where('code', 'IT')->value('id') ?? 2;
        $baId = Department::whereIn('code', ['BA', 'BUS'])->value('id') ?? 3;

        $programs = [
            [
                'department_id' => $csId,
                'code' => 'BSCS',
                'name' => 'Bachelor of Science in Computer Science',
                'duration_years' => 4,
                'status' => 'active',
            ],
            [
                'department_id' => $csId,
                'code' => 'CS',
                'name' => 'Computer Science',
                'duration_years' => 4,
                'status' => 'active',
            ],
            [
                'department_id' => $csId,
                'code' => 'BSE',
                'name' => 'Bachelor of Software Engineering',
                'duration_years' => 4,
                'status' => 'active',
            ],
            [
                'department_id' => $csId,
                'code' => 'SE',
                'name' => 'Software Engineering',
                'duration_years' => 4,
                'status' => 'active',
            ],
            [
                'department_id' => $itId,
                'code' => 'BSIT',
                'name' => 'Bachelor of Science in Information Technology',
                'duration_years' => 4,
                'status' => 'active',
            ],
            [
                'department_id' => $itId,
                'code' => 'IT',
                'name' => 'Information Technology',
                'duration_years' => 4,
                'status' => 'active',
            ],
            [
                'department_id' => $baId,
                'code' => 'BBA',
                'name' => 'Bachelor of Business Administration',
                'duration_years' => 4,
                'status' => 'active',
            ],
            [
                'department_id' => $baId,
                'code' => 'BA',
                'name' => 'Business Administration',
                'duration_years' => 4,
                'status' => 'active',
            ],
        ];

        foreach ($programs as $program) {
            Program::updateOrCreate(
                ['code' => $program['code']],
                [
                    'department_id' => $program['department_id'],
                    'name' => $program['name'],
                    'duration_years' => $program['duration_years'],
                    'status' => $program['status'],
                ]
            );
        }
    }
}
