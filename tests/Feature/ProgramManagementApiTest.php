<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProgramManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role, string $email): User
    {
        return User::create([
            'name' => ucfirst($role) . ' User',
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    public function test_by_department_returns_empty_array_when_faculty_has_no_majors_and_returns_created_majors(): void
    {
        $admin = $this->makeUser('admin', 'prog.admin@example.test');
        $fosat = Department::create([
            'name' => 'Faculty Of Science And Technology',
            'code' => 'FOSAT',
            'status' => 'active',
        ]);
        $ba = Department::create([
            'name' => 'Business Administration',
            'code' => 'BA',
            'status' => 'active',
        ]);
        Program::create([
            'department_id' => $ba->id,
            'code' => 'BBA',
            'name' => 'Bachelor of Business Administration',
            'duration_years' => 4,
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);

        // Initially FOSAT has 0 majors -> returns 200 with empty array and accurate metadata
        $this->getJson("/api/departments/{$fosat->id}/programs")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.faculty_id', $fosat->id)
            ->assertJsonPath('meta.faculty_code', 'FOSAT')
            ->assertJsonPath('meta.count', 0);

        // Non-admin cannot create a major
        $teacher = $this->makeUser('teacher', 'prog.teacher@example.test');
        Sanctum::actingAs($teacher);
        $this->postJson('/api/programs', [
            'department_id' => $fosat->id,
            'name' => 'Physics',
        ])->assertStatus(403);

        // Admin creates majors under FOSAT (explicit code and auto-generated code)
        Sanctum::actingAs($admin);
        $created1 = $this->postJson('/api/programs', [
            'department_id' => $fosat->id,
            'name' => 'Computer Science',
            'code' => 'FOSAT-CS',
            'duration_years' => 4,
        ])->assertCreated()
            ->assertJsonPath('data.department_id', $fosat->id)
            ->assertJsonPath('data.faculty_id', $fosat->id)
            ->assertJsonPath('data.code', 'FOSAT-CS')
            ->assertJsonPath('data.name', 'Computer Science');

        $created2 = $this->postJson('/api/programs', [
            'faculty_id' => $fosat->id,
            'name' => 'Applied Physics',
        ])->assertCreated()
            ->assertJsonPath('data.department_id', $fosat->id)
            ->assertJsonPath('data.code', 'AP');

        // Duplicate name in same faculty rejected
        $this->postJson('/api/programs', [
            'department_id' => $fosat->id,
            'name' => 'Computer Science',
        ])->assertStatus(422);

        // Duplicate code globally rejected
        $this->postJson('/api/programs', [
            'department_id' => $fosat->id,
            'name' => 'Another Major',
            'code' => 'BBA',
        ])->assertStatus(422);

        // FOSAT major dropdown endpoint now returns the 2 created majors and NOT BA's BBA
        $fosatList = $this->getJson("/api/departments/{$fosat->id}/programs")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.count', 2);

        $returnedCodes = collect($fosatList->json('data'))->pluck('code')->all();
        $this->assertEqualsCanonicalizing(['AP', 'FOSAT-CS'], $returnedCodes);
        $this->assertNotContains('BBA', $returnedCodes);

        // Student can now be created and persisted under FOSAT + newly created Major
        $ay = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
            'status' => 'active',
        ]);

        $newMajorId = $created1->json('data.id');
        $this->postJson('/api/admin/students', [
            'english_name' => 'FOSAT Student',
            'gender' => 'Male',
            'student_identifier' => 'STDFOSAT01',
            'department_id' => $fosat->id,
            'program_id' => $newMajorId,
            'year_level' => 1,
            'academic_year_id' => $ay->id,
        ])->assertCreated();

        $student = Student::where('student_code', 'STDFOSAT01')->firstOrFail();
        $this->assertSame($fosat->id, (int) $student->department_id);
        $this->assertSame($newMajorId, (int) $student->program_id);

        // Linked major cannot be deleted (422), unlinked major can be updated & deleted (200)
        $this->deleteJson("/api/programs/{$newMajorId}")->assertStatus(422);

        $unlinkedId = $created2->json('data.id');
        $this->putJson("/api/programs/{$unlinkedId}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        // Inactive major is excluded from active-only department lookup
        $this->getJson("/api/departments/{$fosat->id}/programs")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->deleteJson("/api/programs/{$unlinkedId}")->assertOk();
    }
}
