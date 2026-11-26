<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_login_with_student_id_via_api(): void
    {
        $this->seed();

        $response = $this->postJson('/api/login', [
            'login_id' => 'STD001',
            'password' => '12345678',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('user.role', 'student');

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_invalid_credentials_are_rejected_by_api(): void
    {
        $this->seed();

        $response = $this->postJson('/api/login', [
            'login_id' => 'STD001',
            'password' => 'wrong-password',
        ]);

        $response
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_public_student_registration_is_disabled(): void
    {
        $response = $this->postJson('/api/register', [
            'student_id' => 'STD999',
            'first_name' => 'Test',
            'last_name' => 'Student',
            'email' => 'student999@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'date_of_birth' => '2005-01-15',
            'gender' => 'Male',
            'department' => 'Computer Science',
            'major' => 'Software Engineering',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Public student registration is disabled. Student accounts must be created by an administrator.');

        $this->assertDatabaseMissing('students', ['student_code' => 'STD999']);
        $this->assertDatabaseMissing('users', ['email' => 'student999@example.com']);
    }

    public function test_student_cannot_change_account_or_personal_fields_via_api(): void
    {
        $this->seed();

        $login = $this->postJson('/api/login', [
            'login_id' => 'STD001',
            'password' => '12345678',
        ]);

        $token = $login->json('token');

        $response = $this->withToken($token)->putJson('/api/profile', [
            'name' => 'Sok Dara Updated',
            'email' => 'student.updated@slas.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
            'phone' => '+855 99 999 999',
            'address' => 'Phnom Penh, Cambodia',
            'first_name' => 'Sok',
            'last_name' => 'Dara Updated',
            'department' => 'Information Technology',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Students may only update their profile picture.');

        $studentUser = \App\Models\User::where('email', 'student@slas.com')->firstOrFail();

        $this->assertDatabaseHas('users', [
            'email' => 'student@slas.com',
            'name' => 'Sok Dara',
        ]);

        $this->assertDatabaseHas('students', [
            'user_id' => $studentUser->id,
            'first_name' => 'Sok',
            'last_name' => 'Dara',
            'phone' => null,
            'address' => null,
        ]);
    }

    public function test_authenticated_student_can_update_profile_picture_via_api(): void
    {
        $this->seed();
        Storage::fake('public');

        $login = $this->postJson('/api/login', [
            'login_id' => 'STD001',
            'password' => '12345678',
        ]);

        $token = $login->json('token');

        $response = $this->withToken($token)
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/api/profile', [
                '_method' => 'PUT',
                'profile_picture' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
            ]);

        $response
                ->assertOk()
            ->assertJsonPath('message', 'Profile picture updated successfully.');

        $this->assertNotNull($response->json('user.profile_picture'));

        $this->assertDatabaseHas('users', [
                'email' => 'student@slas.com',
        ]);
    }

    public function test_student_can_only_edit_profile_picture_in_profile_and_settings_pages(): void
    {
        $this->seed();
        $student = \App\Models\User::where('email', 'student@slas.com')->firstOrFail();

        $this->actingAs($student)
            ->get(route('student.profile.edit'))
            ->assertOk()
            ->assertSee('data-live-clock', false)
            ->assertSee('student-profile-menu', false)
            ->assertSee('student@slas.com')
            ->assertSee('Student ID: STD001')
            ->assertSee('My Account')
            ->assertSee('Sign out')
            ->assertDontSee('Logout')
            ->assertSee('name="profile_picture"', false)
            ->assertDontSee('name="name"', false)
            ->assertDontSee('name="email"', false)
            ->assertDontSee('name="phone"', false)
            ->assertDontSee('name="address"', false)
            ->assertDontSee('name="password"', false);

        $this->get(route('student.settings'))
            ->assertOk()
            ->assertSee('Student information')
            ->assertSee('Faculty')
            ->assertSee('Computer Science')
            ->assertSee('Academic year')
            ->assertSee('name="profile_picture"', false)
            ->assertDontSee('name="name"', false)
            ->assertDontSee('name="email"', false);
    }

    public function test_student_record_can_be_created_via_students_api_by_admin_only(): void
    {
        $this->seed();

        $payload = [
            'student_id' => 'STD500',
            'first_name' => 'New',
            'last_name' => 'Student',
            'email' => 'newstudent@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'date_of_birth' => '2005-02-14',
            'gender' => 'Female',
            'department' => 'Computer Science',
            'major' => 'Software Engineering',
            'academic_year' => '2026-2027',
            'year_level' => 2,
            'semester' => 'Semester 1',
        ];

        // Public / unauthenticated creation must be rejected
        $this->postJson('/api/students', $payload)->assertStatus(401);

        $admin = \App\Models\User::where('email', 'admin@slas.com')->firstOrFail();

        $response = $this->actingAs($admin)->postJson('/api/students', $payload);

        $response
            ->assertStatus(201)
            ->assertJsonPath('message', 'Student created successfully.')
            ->assertJsonPath('data.student_id', 'STD500');

        $this->assertDatabaseHas('students', [
            'student_code' => 'STD500',
        ]);

        // Standardized initial student password works immediately without forced password change
        $loginStd = $this->postJson('/api/login', [
            'login_id' => 'STD500',
            'password' => 'STD@12345',
        ])->assertOk();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->withToken($loginStd->json('token'))->getJson('/api/dashboard/student')
            ->assertOk()
            ->assertJsonPath('data.student.student_code', 'STD500');
    }

    public function test_student_can_change_password_via_api(): void
    {
        $this->seed();

        $login = $this->postJson('/api/login', [
            'login_id' => 'STD001',
            'password' => '12345678',
        ]);

        $token = $login->json('token');

        $response = $this->withToken($token)->postJson('/api/change-password', [
            'current_password' => '12345678',
            'password' => 'newSecret123',
            'password_confirmation' => 'newSecret123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Password changed successfully.');

        $loginNew = $this->postJson('/api/login', [
            'login_id' => 'STD001',
            'password' => 'newSecret123',
        ]);

        $loginNew->assertOk();
    }

    public function test_invalid_current_password_is_rejected(): void
    {
        $this->seed();

        $login = $this->postJson('/api/login', [
            'login_id' => 'STD001',
            'password' => '12345678',
        ]);

        $token = $login->json('token');

        $response = $this->withToken($token)->postJson('/api/change-password', [
            'current_password' => 'wrongPassword',
            'password' => 'newSecret123',
            'password_confirmation' => 'newSecret123',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', 'The current password provided is incorrect.');
    }

    public function test_unauthenticated_cannot_change_password(): void
    {
        $response = $this->postJson('/api/change-password', [
            'current_password' => '12345678',
            'password' => 'newSecret123',
            'password_confirmation' => 'newSecret123',
        ]);

        $response->assertStatus(401);
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        $this->seed();

        $login = $this->postJson('/api/login', [
            'login_id' => 'STD001',
            'password' => '12345678',
        ]);

        $token = $login->json('token');

        $response = $this->withToken($token)->postJson('/api/change-password', [
            'current_password' => '12345678',
            'password' => 'newSecret123',
            'password_confirmation' => 'differentPassword',
        ]);

        $response->assertStatus(422);
    }

    public function test_old_password_fails_after_successful_change(): void
    {
        $this->seed();

        $login = $this->postJson('/api/login', [
            'login_id' => 'STD001',
            'password' => '12345678',
        ]);

        $token = $login->json('token');

        $this->withToken($token)->postJson('/api/change-password', [
            'current_password' => '12345678',
            'password' => 'brandNewPassword123',
            'password_confirmation' => 'brandNewPassword123',
        ])->assertOk();

        // Old password must now fail
        $loginOld = $this->postJson('/api/login', [
            'login_id' => 'STD001',
            'password' => '12345678',
        ]);

        $loginOld->assertStatus(401);
    }

    public function test_admin_user_gets_404_on_student_dashboard_endpoint(): void
    {
        $this->seed();

        $adminUser = \App\Models\User::where('role', 'admin')->first();

        $response = $this->actingAs($adminUser)->getJson('/api/dashboard/student');

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Student profile not found.');
    }
}

