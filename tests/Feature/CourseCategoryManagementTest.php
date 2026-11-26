<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseCategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private function createAdminUser(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    public function test_admin_can_create_category_with_only_name_and_auto_generated_code(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/departments', [
            'name' => 'Computer Science',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Computer Science')
            ->assertJsonPath('data.code', 'CS')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.courses_count', 0);

        $this->assertDatabaseHas('departments', [
            'name' => 'Computer Science',
            'code' => 'CS',
            'status' => 'active',
        ]);

        // Creating another category whose initials also collide generates a unique suffix
        $second = $this->postJson('/api/departments', [
            'name' => 'Cognitive Systems',
            'description' => 'Interdisciplinary AI & cognitive science courses',
            'status' => 'inactive',
        ]);

        $second->assertCreated()
            ->assertJsonPath('data.name', 'Cognitive Systems')
            ->assertJsonPath('data.code', 'CS2')
            ->assertJsonPath('data.description', 'Interdisciplinary AI & cognitive science courses')
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_duplicate_category_name_is_rejected_case_insensitively(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        Department::create([
            'name' => 'Data Science',
            'code' => 'DS',
            'status' => 'active',
        ]);

        $this->postJson('/api/departments', [
            'name' => 'data science',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_admin_can_list_search_and_filter_categories_by_status(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $cs = Department::create([
            'name' => 'Computer Science',
            'code' => 'CS',
            'description' => 'Software engineering and computing',
            'status' => 'active',
        ]);
        Department::create([
            'name' => 'Business Administration',
            'code' => 'BA',
            'description' => 'Management and finance',
            'status' => 'inactive',
        ]);

        Course::create([
            'department_id' => $cs->id,
            'department' => $cs->name,
            'code' => 'CS101',
            'course_code' => 'CS101',
            'name' => 'Algorithms',
            'course_name' => 'Algorithms',
            'credits' => 3,
            'credit' => 3,
            'status' => 'active',
        ]);

        $all = $this->getJson('/api/departments')->assertOk();
        $this->assertCount(2, $all->json('data'));
        $this->assertSame('Computer Science', $all->json('data.0.name'));
        $this->assertSame(1, $all->json('data.0.courses_count'));

        $searchRes = $this->getJson('/api/departments?search=software')->assertOk();
        $this->assertCount(1, $searchRes->json('data'));
        $this->assertSame('CS', $searchRes->json('data.0.code'));

        $inactiveRes = $this->getJson('/api/departments?status=inactive')->assertOk();
        $this->assertCount(1, $inactiveRes->json('data'));
        $this->assertSame('BA', $inactiveRes->json('data.0.code'));
    }

    public function test_admin_can_edit_category_clear_description_toggle_status_and_sync_courses(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $category = Department::create([
            'name' => 'Information Tech',
            'code' => 'IT',
            'description' => 'Legacy description',
            'status' => 'active',
        ]);

        $course = Course::create([
            'department_id' => $category->id,
            'department' => $category->name,
            'code' => 'IT101',
            'course_code' => 'IT101',
            'name' => 'Networking Basics',
            'course_name' => 'Networking Basics',
            'credits' => 3,
            'credit' => 3,
            'status' => 'active',
        ]);

        $this->putJson("/api/departments/{$category->id}", [
            'name' => 'Information Technology',
            'description' => '',
            'status' => 'active',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Information Technology')
            ->assertJsonPath('data.code', 'IT')
            ->assertJsonPath('data.description', null);

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'department_id' => $category->id,
            'department' => 'Information Technology',
        ]);

        // Status-only toggle (Deactivate -> Activate)
        $this->putJson("/api/departments/{$category->id}", [
            'status' => 'inactive',
        ])->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->putJson("/api/departments/{$category->id}", [
            'status' => 'active',
        ])->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_deleting_category_with_courses_is_safely_blocked_and_unlinked_category_deletes(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $linked = Department::create([
            'name' => 'Artificial Intelligence',
            'code' => 'AI',
            'status' => 'active',
        ]);
        $unlinked = Department::create([
            'name' => 'Robotics',
            'code' => 'ROB',
            'status' => 'active',
        ]);

        Course::create([
            'department_id' => $linked->id,
            'department' => $linked->name,
            'code' => 'AI201',
            'course_code' => 'AI201',
            'name' => 'Machine Learning',
            'course_name' => 'Machine Learning',
            'credits' => 3,
            'credit' => 3,
            'status' => 'active',
        ]);

        $blocked = $this->deleteJson("/api/departments/{$linked->id}");
        $blocked->assertStatus(422)
            ->assertJsonPath('can_deactivate', true)
            ->assertJsonPath('dependencies.courses', 1);

        $this->assertDatabaseHas('departments', ['id' => $linked->id]);

        $this->deleteJson("/api/departments/{$unlinked->id}")
            ->assertOk();
        $this->assertDatabaseMissing('departments', ['id' => $unlinked->id]);
    }

    public function test_non_admin_cannot_modify_categories_and_active_categories_appear_in_course_lookups(): void
    {
        $studentUser = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $existing = Department::create([
            'name' => 'Cybersecurity',
            'code' => 'CSEC',
            'status' => 'active',
        ]);

        Sanctum::actingAs($studentUser);
        $this->postJson('/api/departments', ['name' => 'Unauthorized'])->assertForbidden();
        $this->putJson("/api/departments/{$existing->id}", ['status' => 'inactive'])->assertForbidden();
        $this->deleteJson("/api/departments/{$existing->id}")->assertForbidden();

        // Admin creates an active and an inactive category; active appears in lookups and works for course creation
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $createdActive = $this->postJson('/api/departments', [
            'name' => 'Cloud Engineering',
            'status' => 'active',
        ])->assertCreated()->json('data');

        $this->postJson('/api/departments', [
            'name' => 'Archived Faculty',
            'status' => 'inactive',
        ])->assertCreated();

        $lookups = $this->getJson('/api/admin/class-enrollments/lookups')->assertOk();
        $deptNames = collect($lookups->json('data.departments'))->pluck('name')->all();

        $this->assertContains('Cloud Engineering', $deptNames);
        $this->assertContains('Cybersecurity', $deptNames);
        $this->assertNotContains('Archived Faculty', $deptNames);

        $this->postJson('/api/courses', [
            'course_code' => 'CLD101',
            'course_name' => 'Cloud Architecture',
            'credit' => 3,
            'department_id' => $createdActive['id'],
            'status' => 'active',
        ])->assertCreated()
            ->assertJsonPath('data.department_id', $createdActive['id'])
            ->assertJsonPath('data.department_name', 'Cloud Engineering');
    }
}
