<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Department;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TeacherPasswordStandardizationTest extends TestCase
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

    public function test_reset_teacher_passwords_command_updates_only_teachers_and_preserves_students_and_admins(): void
    {
        $adminUser = User::factory()->create([
            'role' => 'admin',
            'password' => Hash::make('AdminSecret99!'),
            'status' => 'active',
        ]);
        Admin::create([
            'user_id' => $adminUser->id,
            'admin_code' => 'ADM900',
            'first_name' => 'Root',
            'last_name' => 'Admin',
            'status' => 'active',
        ]);
        $adminUser->createToken('admin-token');

        $studentUser = User::factory()->create([
            'role' => 'student',
            'password' => Hash::make('STD@12345'),
            'status' => 'active',
        ]);
        Student::create([
            'user_id' => $studentUser->id,
            'student_code' => 'STU900',
            'first_name' => 'Srey',
            'last_name' => 'Mom',
            'date_of_birth' => '2004-01-10',
            'gender' => 'Female',
            'status' => 'enrolled',
        ]);
        $studentUser->createToken('student-token');

        $teacherUser1 = User::factory()->create([
            'role' => 'teacher',
            'password' => Hash::make('OldTeacherPass1!'),
            'status' => 'active',
        ]);
        Teacher::create([
            'user_id' => $teacherUser1->id,
            'teacher_code' => 'TCH901',
            'first_name' => 'Dara',
            'last_name' => 'Sok',
            'status' => 'active',
        ]);
        $teacherUser1->createToken('teacher-token-1');

        $teacherUser2 = User::factory()->create([
            'role' => 'teacher',
            'password' => Hash::make('OldTeacherPass2!'),
            'status' => 'active',
        ]);
        Teacher::create([
            'user_id' => $teacherUser2->id,
            'teacher_code' => 'TCH902',
            'first_name' => 'Bopha',
            'last_name' => 'Chan',
            'status' => 'active',
        ]);
        $teacherUser2->createToken('teacher-token-2');

        $this->artisan('slas:reset-teacher-passwords')
            ->assertExitCode(0);

        $teacherUser1->refresh();
        $teacherUser2->refresh();
        $studentUser->refresh();
        $adminUser->refresh();

        $this->assertTrue(Hash::check('TCH@12345', $teacherUser1->password));
        $this->assertTrue(Hash::check('TCH@12345', $teacherUser2->password));
        $this->assertSame(0, $teacherUser1->tokens()->count());
        $this->assertSame(0, $teacherUser2->tokens()->count());

        // Strict role separation: student and admin passwords and tokens remain untouched.
        $this->assertTrue(Hash::check('STD@12345', $studentUser->password));
        $this->assertSame(1, $studentUser->tokens()->count());
        $this->assertTrue(Hash::check('AdminSecret99!', $adminUser->password));
        $this->assertSame(1, $adminUser->tokens()->count());

        // Teacher login works using Teacher ID + TCH@12345.
        $this->postJson('/api/login', [
            'login_id' => 'TCH901',
            'password' => 'TCH@12345',
        ])->assertOk()
            ->assertJsonPath('user.role', 'teacher')
            ->assertJsonPath('user.teacher_id', 'TCH901');
    }

    public function test_new_teacher_created_via_api_receives_standardized_password_and_can_login(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $res = $this->actingAs($admin)->postJson('/api/teachers', [
            'teacher_id' => 'TCH950',
            'first_name' => 'Vanna',
            'last_name' => 'Keo',
            'email' => 'vanna.keo@slas.edu.kh',
            'department' => 'Computer Science',
            'position' => 'Lecturer',
            'work_type' => 'Full-time',
            'status' => 'active',
        ])->assertCreated()
            ->assertJsonPath('username', 'TCH950')
            ->assertJsonPath('initial_password', 'TCH@12345')
            ->assertJsonPath('role', 'teacher')
            ->assertJsonPath('data.teacher_id', 'TCH950');

        $teacher = Teacher::with('user')->where('teacher_code', 'TCH950')->firstOrFail();
        $this->assertSame('teacher', $teacher->user->role);
        $this->assertTrue(Hash::check('TCH@12345', $teacher->user->password));

        $this->postJson('/api/login', [
            'login_id' => 'TCH950',
            'password' => 'TCH@12345',
        ])->assertOk()
            ->assertJsonPath('user.role', 'teacher')
            ->assertJsonPath('user.teacher_id', 'TCH950');
    }

    public function test_new_teacher_created_via_admin_users_receives_standardized_password(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Narith Phan',
            'email' => 'narith.phan@slas.edu.kh',
            'role' => 'teacher',
            'teacher_code' => 'TCH951',
            'department' => 'Computer Science',
            'position' => 'Lecturer',
            'status' => 'active',
        ])->assertCreated();

        $teacher = Teacher::with('user')->where('teacher_code', 'TCH951')->firstOrFail();
        $this->assertTrue(Hash::check('TCH@12345', $teacher->user->password));

        $this->postJson('/api/login', [
            'login_id' => 'TCH951',
            'password' => 'TCH@12345',
        ])->assertOk()
            ->assertJsonPath('user.role', 'teacher');
    }

    public function test_new_teacher_created_via_web_admin_receives_standardized_password(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)->post('/admin/teachers', [
            'teacher_id' => 'TCH952',
            'first_name' => 'Kosal',
            'last_name' => 'Men',
            'email' => 'kosal.men@slas.edu.kh',
            'department' => 'Computer Science',
            'position' => 'Lecturer',
            'work_type' => 'Full-time',
            'status' => 'active',
        ])->assertRedirect(route('admin.teachers'));

        $teacher = Teacher::with('user')->where('teacher_code', 'TCH952')->firstOrFail();
        $this->assertTrue(Hash::check('TCH@12345', $teacher->user->password));
    }
}
