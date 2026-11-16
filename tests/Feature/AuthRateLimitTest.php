<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_rate_limited()
    {
        $user = User::create([
            'name' => 'Test Student',
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
            'role' => 'student'
        ]);

        Student::create([
            'user_id' => $user->id,
            'student_code' => 'STD999',
            'first_name' => 'Test',
            'last_name' => 'Student',
            'gender' => 'Other',
            'date_of_birth' => '2000-01-01',
            'enrollment_date' => '2026-01-01'
        ]);

        // 5 Failed attempts
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/login', [
                'login_id' => 'STD999',
                'password' => 'wrongpassword'
            ]);
            $response->assertStatus(401);
            $response->assertJson(['message' => 'Invalid credentials.']);
        }

        // 6th Attempt should hit rate limiter (429)
        $response = $this->postJson('/api/login', [
            'login_id' => 'STD999',
            'password' => 'wrongpassword'
        ]);

        $response->assertStatus(429);
        $response->assertJsonStructure(['message']);

        $message = $response->json('message');
        $this->assertStringContainsString('Too many login attempts', $message);
    }

    public function test_login_rate_limiter_clears_on_success()
    {
        $user = User::create([
            'name' => 'Test Student',
            'email' => 'test2@example.com',
            'password' => Hash::make('password123'),
            'role' => 'student'
        ]);

        Student::create([
            'user_id' => $user->id,
            'student_code' => 'STD998',
            'first_name' => 'Test',
            'last_name' => 'Student',
            'gender' => 'Other',
            'date_of_birth' => '2000-01-01',
            'enrollment_date' => '2026-01-01'
        ]);

        // 4 Failed attempts
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/login', [
                'login_id' => 'STD998',
                'password' => 'wrongpassword'
            ])->assertStatus(401);
        }

        // 5th Attempt succeeds
        $response = $this->postJson('/api/login', [
            'login_id' => 'STD998',
            'password' => 'password123'
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['token']);

        // Next attempt fails with 401 instead of 429 because counter cleared
        $this->postJson('/api/login', [
            'login_id' => 'STD998',
            'password' => 'wrongpassword'
        ])->assertStatus(401);
    }
}
