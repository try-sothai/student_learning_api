<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAuthorizationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_unauthenticated_cannot_access_protected_dashboards_or_admin_endpoints(): void
    {
        $this->getJson('/api/dashboard/admin')->assertStatus(401);
        $this->getJson('/api/dashboard/teacher')->assertStatus(401);
        $this->getJson('/api/dashboard/student')->assertStatus(401);
        $this->getJson('/api/admins')->assertStatus(401);
    }

    public function test_student_cannot_access_admin_or_teacher_dashboards_or_admin_resources(): void
    {
        $studentUser = User::where('email', 'student@slas.com')->firstOrFail();

        $this->actingAs($studentUser)
            ->getJson('/api/dashboard/admin')
            ->assertStatus(403);

        $this->actingAs($studentUser)
            ->getJson('/api/dashboard/teacher')
            ->assertStatus(403);

        $this->actingAs($studentUser)
            ->getJson('/api/admins')
            ->assertStatus(403);
    }

    public function test_teacher_cannot_access_admin_dashboard_or_admin_resources(): void
    {
        $teacherUser = User::where('email', 'teacher@slas.com')->firstOrFail();

        $this->actingAs($teacherUser)
            ->getJson('/api/dashboard/admin')
            ->assertStatus(403);

        $this->actingAs($teacherUser)
            ->getJson('/api/admins')
            ->assertStatus(403);
    }

    public function test_authorized_roles_can_access_their_respective_dashboards(): void
    {
        $studentUser = User::where('email', 'student@slas.com')->firstOrFail();
        $teacherUser = User::where('email', 'teacher@slas.com')->firstOrFail();
        $adminUser = User::where('email', 'admin@slas.com')->firstOrFail();

        $this->actingAs($studentUser)
            ->getJson('/api/dashboard/student')
            ->assertStatus(200);

        $this->actingAs($teacherUser)
            ->getJson('/api/dashboard/teacher')
            ->assertStatus(200);

        $this->actingAs($adminUser)
            ->getJson('/api/dashboard/admin')
            ->assertStatus(200);

        $this->actingAs($adminUser)
            ->getJson('/api/admins')
            ->assertStatus(200);
    }
}
