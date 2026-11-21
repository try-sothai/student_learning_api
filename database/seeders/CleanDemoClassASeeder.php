<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\ClassRoom;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CourseOfferingTeacher;
use App\Models\Department;
use App\Models\Program;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Idempotent clean academic demo setup (Classes A-E).
 *
 * - Class A: 3 courses, 3 teachers, 7 enrolled students (enrolled in all 3 courses).
 * - Classes B-E: created and left empty for future Admin management.
 * - Preserves all existing unrelated users, courses, classes, and assessments.
 */
class CleanDemoClassASeeder extends Seeder
{
    public function run(): void
    {
        $teacherPassword = Hash::make((string) config('slas.default_teacher_password', 'TCH@12345'));
        $studentPassword = Hash::make((string) config('slas.default_student_password', 'STD@12345'));

        DB::transaction(function () use ($teacherPassword, $studentPassword) {
            // 1. Reuse existing active Academic Year & Semester (2026-2027 / Semester 1)
            $academicYear = AcademicYear::where('status', 'active')->first()
                ?? AcademicYear::orderByDesc('id')->firstOrFail();

            $semester = Semester::where('academic_year_id', $academicYear->id)
                ->where('status', 'active')
                ->first()
                ?? Semester::where('academic_year_id', $academicYear->id)->orderBy('semester_number')->firstOrFail();

            $csDept = Department::where('code', 'CS')->first() ?? Department::firstOrFail();
            $itDept = Department::where('code', 'IT')->first() ?? $csDept;
            $dsDept = Department::firstOrCreate(
                ['code' => 'DS'],
                ['name' => 'Data Science', 'status' => 'active']
            );

            $bscsProgram = Program::where('code', 'BSCS')->first()
                ?? Program::where('department_id', $csDept->id)->first();

            // 2. Ensure 5 Classes (Class A..E) exist
            $classes = [];
            foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
                $classes[$letter] = ClassRoom::updateOrCreate(
                    ['code' => "CLASS-{$letter}"],
                    [
                        'department_id' => $csDept->id,
                        'academic_year_id' => $academicYear->id,
                        'name' => "Class {$letter}",
                        'year_level' => 1,
                        'status' => 'active',
                    ]
                );
            }
            $classA = $classes['A'];

            // 3. Ensure 3 Demo Teacher accounts exist
            $teacherDefs = [
                [
                    'code' => 'TCH0SANG',
                    'name' => 'តាត់ ប៉ុនស៊ាង',
                    'first_name' => 'តាត់',
                    'last_name' => 'ប៉ុនស៊ាង',
                    'email' => 'tch0sang@slas.com',
                    'dept_id' => $dsDept->id,
                    'specialization' => 'Data Science',
                    'course_code' => 'DS101',
                    'course_name' => 'Data Science',
                    'course_desc' => 'Data analysis, statistics, predictive modeling, and machine learning foundations.',
                ],
                [
                    'code' => 'TCH0NGIM',
                    'name' => 'សា សុខងីម',
                    'first_name' => 'សា',
                    'last_name' => 'សុខងីម',
                    'email' => 'tch0ngim@slas.com',
                    'dept_id' => $csDept->id,
                    'specialization' => 'Android App Development',
                    'course_code' => 'CS305',
                    'course_name' => 'Android App',
                    'course_desc' => 'Android architecture, Material UI, Retrofit REST APIs, and mobile data persistence.',
                ],
                [
                    'code' => 'TCH0HUR',
                    'name' => 'តៀប តៅហ៊ូ',
                    'first_name' => 'តៀប',
                    'last_name' => 'តៅហ៊ូ',
                    'email' => 'tch0hur@slas.com',
                    'dept_id' => $itDept->id,
                    'specialization' => 'Network Administration',
                    'course_code' => 'IT205',
                    'course_name' => 'Network Administration',
                    'course_desc' => 'Routing, switching, Linux/Windows server administration, DNS, DHCP, and network security.',
                ],
            ];

            $classAOfferings = [];
            foreach ($teacherDefs as $def) {
                $existingTeacher = Teacher::withTrashed()->where('teacher_code', $def['code'])->first();
                $user = $existingTeacher?->user ?? User::where('email', $def['email'])->first();

                if ($user) {
                    $user->update([
                        'name' => $def['name'],
                        'role' => 'teacher',
                        'status' => 'active',
                    ]);
                } else {
                    $user = User::create([
                        'name' => $def['name'],
                        'email' => $def['email'],
                        'password' => $teacherPassword,
                        'role' => 'teacher',
                        'status' => 'active',
                    ]);
                }

                $teacher = Teacher::withTrashed()->updateOrCreate(
                    ['teacher_code' => $def['code']],
                    [
                        'user_id' => $user->id,
                        'department_id' => $def['dept_id'],
                        'first_name' => $def['first_name'],
                        'last_name' => $def['last_name'],
                        'position' => 'Lecturer',
                        'specialization' => $def['specialization'],
                        'status' => 'active',
                        'deleted_at' => null,
                    ]
                );

                // Ensure Course exists (reuse by course_name or course_code without duplicating)
                $course = Course::withTrashed()
                    ->where('course_code', $def['course_code'])
                    ->orWhere('course_name', $def['course_name'])
                    ->first();

                if ($course) {
                    $course->update([
                        'department_id' => $def['dept_id'],
                        'teacher_id' => $teacher->id,
                        'course_name' => $def['course_name'],
                        'description' => $def['course_desc'],
                        'status' => 'active',
                        'deleted_at' => null,
                    ]);
                } else {
                    $course = Course::create([
                        'department_id' => $def['dept_id'],
                        'teacher_id' => $teacher->id,
                        'course_code' => $def['course_code'],
                        'course_name' => $def['course_name'],
                        'description' => $def['course_desc'],
                        'credit' => 3.0,
                        'status' => 'active',
                    ]);
                }

                // Class A CourseOffering for active academic year + semester
                $offering = CourseOffering::withTrashed()->updateOrCreate(
                    [
                        'course_id' => $course->id,
                        'class_id' => $classA->id,
                        'semester_id' => $semester->id,
                    ],
                    [
                        'academic_year_id' => $academicYear->id,
                        'program_id' => $bscsProgram?->id,
                        'study_year' => 1,
                        'section_name' => $classA->name,
                        'status' => 'active',
                        'deleted_at' => null,
                    ]
                );

                // Assign exact teacher to this offering (ensure no stray teachers on this demo offering)
                CourseOfferingTeacher::where('course_offering_id', $offering->id)
                    ->where('teacher_id', '!=', $teacher->id)
                    ->update(['status' => 'ended', 'ended_at' => now()]);

                CourseOfferingTeacher::updateOrCreate(
                    [
                        'course_offering_id' => $offering->id,
                        'teacher_id' => $teacher->id,
                    ],
                    [
                        'role' => 'primary',
                        'status' => 'active',
                        'assigned_at' => now(),
                        'ended_at' => null,
                    ]
                );

                // Also keep class_teacher pivot synced for Admin Faculty Evaluator views
                DB::table('class_teacher')->updateOrInsert(
                    ['class_id' => $classA->id, 'teacher_id' => $teacher->id],
                    ['updated_at' => now(), 'created_at' => now()]
                );

                $classAOfferings[] = $offering;
            }

            // 4. Ensure 7 Demo Student accounts exist and belong to Class A
            $studentDefs = [
                ['code' => 'STD0SAL',  'name' => 'សុន វិសាល',             'first' => 'សុន',     'last' => 'វិសាល',            'email' => 'std0sal@slas.com'],
                ['code' => 'STD0BAT',  'name' => 'ជ្រុយ សម្បត្តិ',           'first' => 'ជ្រុយ',    'last' => 'សម្បត្តិ',          'email' => 'std0bat@slas.com'],
                ['code' => 'STD0THAI', 'name' => 'ទ្រី សុថៃ',              'first' => 'ទ្រី',     'last' => 'សុថៃ',             'email' => 'std0thai@slas.com'],
                ['code' => 'STD0HAM',  'name' => 'ឌី ហ៊ាម',               'first' => 'ឌី',      'last' => 'ហ៊ាម',              'email' => 'std0ham@slas.com'],
                ['code' => 'STD0KERN', 'name' => 'ហាំ សុខខឿន',            'first' => 'ហាំ',     'last' => 'សុខខឿន',           'email' => 'std0kern@slas.com'],
                ['code' => 'STD0PHEA', 'name' => 'ភឿន សុភា',              'first' => 'ភឿន',    'last' => 'សុភា',             'email' => 'std0phea@slas.com'],
                ['code' => 'STD0FA',   'name' => 'តេងតាន់ វ៉ាន់ឡាបហ្វា',   'first' => 'តេងតាន់', 'last' => 'វ៉ាន់ឡាបហ្វា',      'email' => 'std0fa@slas.com'],
            ];

            foreach ($studentDefs as $sDef) {
                $existingStudent = Student::withTrashed()->where('student_code', $sDef['code'])->first();
                $user = $existingStudent?->user ?? User::where('email', $sDef['email'])->first();

                if ($user) {
                    $user->update([
                        'name' => $sDef['name'],
                        'role' => 'student',
                        'status' => 'active',
                    ]);
                } else {
                    $user = User::create([
                        'name' => $sDef['name'],
                        'email' => $sDef['email'],
                        'password' => $studentPassword,
                        'role' => 'student',
                        'status' => 'active',
                    ]);
                }

                $student = Student::withTrashed()->updateOrCreate(
                    ['student_code' => $sDef['code']],
                    [
                        'user_id' => $user->id,
                        'department_id' => $csDept->id,
                        'program_id' => $bscsProgram?->id,
                        'class_id' => $classA->id,
                        'khmer_name' => $sDef['name'],
                        'english_name' => $sDef['name'],
                        'first_name' => $sDef['first'],
                        'last_name' => $sDef['last'],
                        'date_of_birth' => $existingStudent?->date_of_birth ?? '2005-01-15',
                        'gender' => $existingStudent?->gender ?? 'Male',
                        'nationality' => $existingStudent?->nationality ?? 'Cambodian',
                        'major' => 'Computer Science',
                        'year_level' => 'Year 1',
                        'enrollment_date' => '2026-09-01',
                        'status' => 'enrolled',
                        'deleted_at' => null,
                    ]
                );

                // Enroll each Class A student in all 3 Class A course offerings
                foreach ($classAOfferings as $offering) {
                    CourseEnrollment::updateOrCreate(
                        [
                            'student_id' => $student->id,
                            'course_offering_id' => $offering->id,
                        ],
                        [
                            'course_id' => $offering->course_id,
                            'semester_id' => $semester->id,
                            'enrollment_date' => '2026-09-01',
                            'status' => 'enrolled',
                        ]
                    );
                }
            }
        });
    }
}
