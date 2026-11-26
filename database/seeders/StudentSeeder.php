<?php

namespace Database\Seeders;

use App\Models\ClassRoom;
use App\Models\Department;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StudentSeeder extends Seeder
{
    /**
     * Seed 300 additional students (with linked user accounts).
     * Safe to run once; re-running adds another 300 on top.
     */
    public function run(): void
    {
        $departments = [
            'CS' => ['name' => 'Computer Science', 'majors' => ['Software Engineering', 'Information Technology', 'Data Science']],
            'BA' => ['name' => 'Business Administration', 'majors' => ['Marketing', 'Finance', 'Human Resources']],
            'IT' => ['name' => 'Information Technology', 'majors' => ['Network Administration', 'Cybersecurity']],
        ];

        $yearLevels = ['1', '2', '3', '4'];
        $statuses = ['enrolled', 'enrolled', 'enrolled', 'enrolled', 'graduated', 'suspended'];
        $password = Hash::make('12345678');

        $departmentIds = Department::pluck('id', 'code');
        $classesByDeptYear = ClassRoom::query()
            ->whereNotNull('department_id')
            ->whereNotNull('year_level')
            ->get()
            ->groupBy(fn (ClassRoom $c) => $c->department_id . ':' . $c->year_level);

        // Continue numbering after the highest existing STD number (e.g. STD001 -> starts at STD002).
        $lastNumber = (int) Student::query()
            ->selectRaw("MAX(CAST(SUBSTRING(student_code, 4) AS UNSIGNED)) as max_num")
            ->value('max_num');

        DB::transaction(function () use ($departments, $yearLevels, $statuses, $password, $lastNumber, $departmentIds, $classesByDeptYear) {
            for ($i = 1; $i <= 300; $i++) {
                $number = $lastNumber + $i;
                $studentId = 'STD' . str_pad((string) $number, 3, '0', STR_PAD_LEFT);

                $firstName = fake()->firstName();
                $lastName = fake()->lastName();
                $deptCode = fake()->randomElement(array_keys($departments));
                $deptId = $departmentIds[$deptCode] ?? null;
                $major = fake()->randomElement($departments[$deptCode]['majors']);
                $yearLevel = fake()->randomElement($yearLevels);
                $classRoom = $deptId ? $classesByDeptYear->get($deptId . ':' . $yearLevel)?->first() : null;

                $user = User::create([
                    'name' => "{$firstName} {$lastName}",
                    'email' => 'student' . $number . '@slas.com',
                    'password' => $password,
                    'role' => 'student',
                ]);

                Student::create([
                    'user_id' => $user->id,
                    'student_code' => $studentId,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'date_of_birth' => fake()->dateTimeBetween('-26 years', '-18 years')->format('Y-m-d'),
                    'gender' => fake()->randomElement(['Male', 'Female']),
                    'nationality' => 'Cambodian',
                    'phone' => fake()->numerify('0##-###-###'),
                    'address' => fake()->city() . ', Cambodia',
                    'department_id' => $deptId,
                    'class_id' => $classRoom?->id,
                    'major' => $major,
                    'year_level' => $yearLevel,
                    'enrollment_date' => fake()->dateTimeBetween('-4 years', 'now')->format('Y-m-d'),
                    'status' => fake()->randomElement($statuses),
                ]);
            }
        });

        $this->command?->info('300 students seeded (' . 'STD' . str_pad((string) ($lastNumber + 1), 3, '0', STR_PAD_LEFT) . ' - STD' . str_pad((string) ($lastNumber + 300), 3, '0', STR_PAD_LEFT) . ').');
    }
}