<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Department;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TeacherProvisioningAndAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Department::create([
            'name' => 'Computer Science',
            'code' => 'CS',
            'status' => 'active',
        ]);
    }

    private function createAdmin(): User
    {
        $admin = User::create([
            'name' => 'System Admin',
            'email' => 'sysadmin@slas.com',
            'password' => Hash::make('Admin@12345'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        Admin::create([
            'user_id' => $admin->id,
            'admin_code' => 'ADM001',
            'first_name' => 'System',
            'last_name' => 'Admin',
            'status' => 'active',
        ]);

        return $admin;
    }

    public function test_create_teacher_creates_linked_user_and_authenticates_with_role_separation(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $this->postJson('/api/login', [
            'login_id' => 'ADM001',
            'password' => 'Admin@12345',
        ])->assertOk()->json('token');

        $createRes = $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson('/api/teachers', [
            'teacher_id' => ' tch0ngim ',
            'first_name' => 'Ngim',
            'last_name' => 'Sokha',
            'email' => 'Ngim.Sokha@slas.edu.kh',
            'department' => 'Computer Science',
            'position' => 'Lecturer',
            'work_type' => 'Full-time',
            'status' => 'active',
        ]);

        $createRes->assertCreated()
            ->assertJsonPath('login_id', 'TCH0NGIM')
            ->assertJsonPath('username', 'TCH0NGIM')
            ->assertJsonPath('initial_password', 'TCH@12345')
            ->assertJsonPath('role', 'teacher')
            ->assertJsonPath('account_created', true)
            ->assertJsonPath('account_linked', true)
            ->assertJsonPath('account_status', 'active')
            ->assertJsonMissing(['password']);

        $teacher = Teacher::with('user')->where('teacher_code', 'TCH0NGIM')->firstOrFail();
        $this->assertNotNull($teacher->user);
        $this->assertSame((int) $teacher->user->id, (int) $teacher->user_id);
        $this->assertSame('teacher', $teacher->user->role);
        $this->assertSame('active', $teacher->user->status);
        $this->assertNotSame('TCH@12345', $teacher->user->password);
        $this->assertTrue(Hash::check('TCH@12345', $teacher->user->password));

        // Invalid password rejected with 401
        $this->postJson('/api/login', [
            'login_id' => 'TCH0NGIM',
            'password' => 'WrongPass!',
        ])->assertStatus(401)
            ->assertJsonMissing(['token']);

        // Valid login succeeds (both uppercase & lowercase) and opens teacher endpoints only
        $loginRes = $this->postJson('/api/login', [
            'login_id' => 'tch0ngim',
            'password' => 'TCH@12345',
        ])->assertOk()
            ->assertJsonPath('user.role', 'teacher')
            ->assertJsonPath('role', 'teacher')
            ->assertJsonPath('login_id', 'TCH0NGIM')
            ->assertJsonPath('user.teacher_id', 'TCH0NGIM');

        $token = $loginRes->json('token');
        $this->assertNotEmpty($token);

        Auth::forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/teacher/dashboard')
            ->assertOk();

        // Role separation: teacher cannot access Admin endpoints
        Auth::forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/users')
            ->assertStatus(403);

        Auth::forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/teachers', [
                'teacher_id' => 'TCH0FAKE',
                'first_name' => 'Fake',
                'last_name' => 'User',
                'email' => 'fake@slas.edu.kh',
            ])
            ->assertStatus(403);
    }

    public function test_inactive_teacher_cannot_login_and_deactivation_revokes_tokens(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'sanctum')->postJson('/api/teachers', [
            'teacher_id' => 'TCH0LOCK',
            'first_name' => 'Locked',
            'last_name' => 'Teacher',
            'email' => 'locked@slas.edu.kh',
            'status' => 'active',
        ])->assertCreated();

        $loginRes = $this->postJson('/api/login', [
            'login_id' => 'TCH0LOCK',
            'password' => 'TCH@12345',
        ])->assertOk();
        $token = $loginRes->json('token');

        $teacher = Teacher::where('teacher_code', 'TCH0LOCK')->firstOrFail();

        // Deactivate via Admin status endpoint -> tokens revoked & future logins blocked
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/users/{$teacher->user_id}/status", ['status' => 'inactive'])
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->postJson('/api/login', [
            'login_id' => 'TCH0LOCK',
            'password' => 'TCH@12345',
        ])->assertStatus(403);

        // Wrong password for inactive teacher still fails as 401 rather than leaking status
        $this->postJson('/api/login', [
            'login_id' => 'TCH0LOCK',
            'password' => 'BadPass99',
        ])->assertStatus(401);
    }

    public function test_edit_teacher_preserves_user_link_and_custom_password(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'sanctum')->postJson('/api/teachers', [
            'teacher_id' => 'TCH0EDIT',
            'first_name' => 'Dara',
            'last_name' => 'Chan',
            'email' => 'dara.chan@slas.edu.kh',
            'status' => 'active',
        ])->assertCreated();

        $teacher = Teacher::with('user')->where('teacher_code', 'TCH0EDIT')->firstOrFail();
        $userId = $teacher->user->id;
        $teacher->user->forceFill(['password' => Hash::make('CustomPass#2026')])->save();
        $userCountBefore = User::count();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$userId}", [
                'name' => 'Dara Updated',
                'email' => 'dara.updated@slas.edu.kh',
                'position' => 'Senior Lecturer',
                'status' => 'active',
            ])
            ->assertOk();

        $this->assertSame($userCountBefore, User::count());
        $teacher->refresh();
        $this->assertSame($userId, (int) $teacher->user_id);
        $this->assertSame('teacher', $teacher->user->role);

        // Custom password must remain intact
        $this->postJson('/api/login', [
            'login_id' => 'TCH0EDIT',
            'password' => 'TCH@12345',
        ])->assertStatus(401);

        $this->postJson('/api/login', [
            'login_id' => 'TCH0EDIT',
            'password' => 'CustomPass#2026',
        ])->assertOk();
    }

    public function test_teacher_csv_import_creates_login_accounts_and_reports_row_errors(): void
    {
        $admin = $this->createAdmin();

        // Pre-existing teacher with custom password must NOT be reset during import
        $this->actingAs($admin, 'sanctum')->postJson('/api/teachers', [
            'teacher_id' => 'TCH0EXIST',
            'first_name' => 'Existing',
            'last_name' => 'One',
            'email' => 'existing@slas.edu.kh',
            'status' => 'active',
        ])->assertCreated();
        $existingTeacher = Teacher::with('user')->where('teacher_code', 'TCH0EXIST')->firstOrFail();
        $existingTeacher->user->forceFill(['password' => Hash::make('KeepSecret!99')])->save();

        $csvContent = implode("\n", [
            'teacher_id,first_name,last_name,email,department,position,work_type,status',
            'TCH0HUR,Hur,Ratha,hur.ratha@slas.edu.kh,Computer Science,Lecturer,Full-time,active',
            'TCH0EXIST,Dup,Teacher,dup.email@slas.edu.kh,Computer Science,Lecturer,Full-time,active',
            'TCH0BAD,,NoName,bad-email,Computer Science,Lecturer,Full-time,active',
            'TCH0HUR,Duplicate,InFile,another@slas.edu.kh,Computer Science,Lecturer,Full-time,active',
        ]);

        $file = UploadedFile::fake()->createWithContent('teachers.csv', $csvContent);

        $res = $this->actingAs($admin, 'sanctum')
            ->post('/api/admin/teachers/import', ['file' => $file], ['Accept' => 'application/json']);

        $res->assertStatus(207)
            ->assertJsonPath('data.total_rows', 4)
            ->assertJsonPath('data.imported_count', 1)
            ->assertJsonPath('data.failed_count', 3)
            ->assertJsonPath('data.created_teachers.0.login_id', 'TCH0HUR')
            ->assertJsonPath('data.created_teachers.0.initial_password', 'TCH@12345');

        // Newly imported teacher logs in with Teacher ID + TCH@12345
        $this->postJson('/api/login', [
            'login_id' => 'TCH0HUR',
            'password' => 'TCH@12345',
        ])->assertOk()
            ->assertJsonPath('user.role', 'teacher')
            ->assertJsonPath('login_id', 'TCH0HUR');

        // Existing teacher password untouched
        $this->postJson('/api/login', [
            'login_id' => 'TCH0EXIST',
            'password' => 'KeepSecret!99',
        ])->assertOk();
    }

    public function test_orphaned_teacher_diagnostics_and_safe_account_repair(): void
    {
        $this->createAdmin();
        $adminToken = $this->postJson('/api/login', [
            'login_id' => 'ADM001',
            'password' => 'Admin@12345',
        ])->assertOk()->json('token');

        $deptId = Department::first()->id;
        // Simulate a broken teacher link (wrong role + blank DB password bypassing Eloquent 'hashed' cast)
        $brokenUser = User::create([
            'name' => 'Broken Link Teacher',
            'email' => null,
            'password' => 'TempPlaceholder123!',
            'role' => 'guest',
            'status' => 'active',
        ]);
        DB::table('users')->where('id', $brokenUser->id)->update(['password' => '']);
        $brokenTeacher = Teacher::create([
            'user_id' => $brokenUser->id,
            'teacher_code' => 'TCH0ORPH',
            'department_id' => $deptId,
            'first_name' => 'Orphan',
            'last_name' => 'Profile',
            'status' => 'active',
        ]);

        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->getJson("/api/teachers/{$brokenTeacher->id}")
            ->assertOk()
            ->assertJsonPath('data.account_created', true)
            ->assertJsonPath('data.account_linked', false)
            ->assertJsonPath('data.login_account_status', 'role_mismatch')
            ->assertJsonPath('data.can_login', false);

        // Repair fixes role='teacher' and assigns hashed TCH@12345 when password was blank
        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/admin/teachers/{$brokenTeacher->id}/provision-account", [
                'email' => 'orphan@slas.edu.kh',
            ])
            ->assertOk()
            ->assertJsonPath('login_id', 'TCH0ORPH')
            ->assertJsonPath('initial_password', 'TCH@12345')
            ->assertJsonPath('data.account_created', true)
            ->assertJsonPath('data.account_linked', true)
            ->assertJsonPath('data.can_login', true);

        // Now login succeeds
        $this->postJson('/api/login', [
            'login_id' => 'TCH0ORPH',
            'password' => 'TCH@12345',
        ])->assertOk();

        // Calling provision-account again does NOT overwrite a custom password or create duplicate user
        $brokenTeacher->refresh();
        $brokenTeacher->user->forceFill(['password' => Hash::make('TeacherOwnPass#1')])->save();
        $userCount = User::count();

        Auth::forgetGuards();
        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/admin/teachers/{$brokenTeacher->id}/provision-account")
            ->assertOk()
            ->assertJsonPath('created_new_user', false)
            ->assertJsonMissing(['initial_password']);

        $this->assertSame($userCount, User::count());
        $this->postJson('/api/login', [
            'login_id' => 'TCH0ORPH',
            'password' => 'TeacherOwnPass#1',
        ])->assertOk();
    }

    public function test_teacher_id_cannot_collide_with_existing_student_code(): void
    {
        $admin = $this->createAdmin();
        $studentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Student::create([
            'user_id' => $studentUser->id,
            'student_code' => 'ID100',
            'first_name' => 'Stu',
            'last_name' => 'Dent',
            'date_of_birth' => '2004-01-01',
            'gender' => 'Male',
            'status' => 'enrolled',
        ]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/teachers', [
            'teacher_id' => 'id100',
            'first_name' => 'Colliding',
            'last_name' => 'Teacher',
            'email' => 'collide@slas.edu.kh',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['teacher_id']);
    }
}
