<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassRoom;
use App\Models\Department;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClassBulkEnrollmentApiTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::create([
            'name' => 'Admin User',
            'email' => 'admin.enroll@example.test',
            'password' => 'password',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function createStudent(string $code, string $first, string $last, ?int $deptId = null, ?int $programId = null, ?int $classId = null, string $status = 'enrolled'): Student
    {
        $user = User::create([
            'name' => "$first $last",
            'email' => strtolower($code) . '@example.test',
            'password' => 'STD@12345',
            'role' => 'student',
            'status' => 'active',
        ]);

        return Student::create([
            'user_id' => $user->id,
            'student_code' => $code,
            'first_name' => $first,
            'last_name' => $last,
            'english_name' => "$first $last",
            'date_of_birth' => '2005-01-01',
            'gender' => 'Male',
            'department_id' => $deptId,
            'program_id' => $programId,
            'class_id' => $classId,
            'year_level' => 3,
            'status' => $status,
        ]);
    }

    public function test_admin_can_search_eligible_students_and_bulk_enroll_with_duplicate_protection(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        $dept = Department::create(['name' => 'Computer Science', 'code' => 'CS']);
        $ay = AcademicYear::create(['name' => '2025-2026', 'start_date' => '2025-10-01', 'end_date' => '2026-07-31', 'status' => 'active']);
        $program = Program::create(['department_id' => $dept->id, 'code' => 'BSCS', 'name' => 'B.Sc. Computer Science', 'duration_years' => 4, 'status' => 'active']);

        $activeClass = ClassRoom::create([
            'department_id' => $dept->id,
            'academic_year_id' => $ay->id,
            'name' => 'BSCS 3-A',
            'code' => 'BSCS-3A',
            'year_level' => 3,
            'status' => 'active',
        ]);

        $inactiveClass = ClassRoom::create([
            'department_id' => $dept->id,
            'academic_year_id' => $ay->id,
            'name' => 'BSCS 3-B',
            'code' => 'BSCS-3B',
            'year_level' => 3,
            'status' => 'inactive',
        ]);

        $s1 = $this->createStudent('STD101', 'Sophea', 'Chan', $dept->id, $program->id);
        $s2 = $this->createStudent('STD102', 'Dara', 'Sok', $dept->id, $program->id);
        $s3 = $this->createStudent('STD103', 'Vannak', 'Lim', $dept->id, $program->id, $activeClass->id);
        $s4 = $this->createStudent('STD104', 'Bopha', 'Keo', $dept->id, $program->id, null, 'inactive');

        // Lookups & searchable students catalog
        $this->getJson('/api/admin/class-enrollments/lookups')
            ->assertOk()
            ->assertJsonPath('data.classes.0.code', 'BSCS-3B');

        $this->getJson("/api/admin/class-enrollments/classes/{$activeClass->id}/students?search=STD101")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_eligible', true);

        // Bulk enroll (1 new + 1 new + 1 already enrolled + 1 inactive)
        $res = $this->postJson("/api/admin/class-enrollments/classes/{$activeClass->id}/bulk-enroll", [
            'student_ids' => [$s1->id, $s2->id, $s3->id, $s4->id],
        ]);

        $res->assertOk()
            ->assertJsonPath('data.enrolled_count', 2)
            ->assertJsonPath('data.skipped_count', 1)
            ->assertJsonPath('data.failed_count', 1);

        $this->assertDatabaseHas('students', ['id' => $s1->id, 'class_id' => $activeClass->id]);
        $this->assertDatabaseHas('students', ['id' => $s2->id, 'class_id' => $activeClass->id]);
        $this->assertDatabaseHas('students', ['id' => $s4->id, 'class_id' => null]);

        // Inactive class must reject enrollment
        $this->postJson("/api/admin/class-enrollments/classes/{$inactiveClass->id}/bulk-enroll", [
            'student_ids' => [$s1->id],
        ])->assertStatus(422);
    }

    public function test_csv_import_preview_and_confirm_validates_without_creating_students(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        $dept = Department::create(['name' => 'Information Tech', 'code' => 'IT']);
        $ay = AcademicYear::create(['name' => '2025-2026', 'start_date' => '2025-10-01', 'end_date' => '2026-07-31', 'status' => 'active']);

        $activeClass = ClassRoom::create([
            'department_id' => $dept->id,
            'academic_year_id' => $ay->id,
            'name' => 'BSIT 4-A',
            'code' => 'BSIT-4A',
            'year_level' => 4,
            'status' => 'active',
        ]);

        $s1 = $this->createStudent('STD201', 'Nita', 'Pov', $dept->id);
        $s2 = $this->createStudent('STD202', 'Kosal', 'Rith', $dept->id, null, $activeClass->id);

        $csv = implode("\n", [
            'student_id,class_code',
            'STD201,BSIT-4A',      // valid
            'STD201,BSIT-4A',      // duplicate in file
            'STD202,BSIT-4A',      // already enrolled
            'UNKNOWN99,BSIT-4A',   // invalid student
            'STD201,BAD-CLASS',    // invalid class
        ]);

        $file = UploadedFile::fake()->createWithContent('enrollments.csv', $csv);
        $initialStudentCount = Student::count();

        $preview = $this->postJson('/api/admin/class-enrollments/import/preview', [
            'file' => $file,
        ]);

        $preview->assertOk()
            ->assertJsonPath('data.valid_count', 1)
            ->assertJsonPath('data.invalid_count', 4);

        // Must never create student accounts during enrollment import
        $this->assertSame($initialStudentCount, Student::count());

        $confirm = $this->postJson('/api/admin/class-enrollments/import/confirm', [
            'rows' => $preview->json('data.valid_rows'),
        ]);

        $confirm->assertOk()
            ->assertJsonPath('data.enrolled_count', 1)
            ->assertJsonPath('data.failed_count', 0);

        $this->assertDatabaseHas('students', ['id' => $s1->id, 'class_id' => $activeClass->id]);
        $this->assertDatabaseHas('students', ['id' => $s2->id, 'class_id' => $activeClass->id]);
    }
}
