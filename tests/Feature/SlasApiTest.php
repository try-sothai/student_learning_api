<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Department;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlasApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_can_retrieve_departments_list(): void
    {
        $response = $this->getJson('/api/departments');

        $response->assertOk()
            ->assertJsonPath('message', 'Departments retrieved successfully.')
            ->assertJsonStructure(['data' => [['id', 'name', 'code']]]);
    }

    public function test_can_create_new_department(): void
    {
        $response = $this->postJson('/api/departments', [
            'name' => 'Cybersecurity & Infrastructure',
            'code' => 'CYBER',
            'description' => 'Cybersecurity department',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Department created successfully.')
            ->assertJsonPath('data.code', 'CYBER');
    }

    public function test_can_retrieve_academic_years_and_semesters(): void
    {
        $responseYears = $this->getJson('/api/academic-years');
        $responseYears->assertOk();

        $responseSemesters = $this->getJson('/api/semesters');
        $responseSemesters->assertOk();
    }

    public function test_can_retrieve_courses_list(): void
    {
        $response = $this->getJson('/api/courses');

        $response->assertOk()
            ->assertJsonPath('message', 'Courses retrieved successfully.');
    }

    public function test_can_retrieve_students_list(): void
    {
        $response = $this->getJson('/api/students');

        $response->assertOk()
            ->assertJsonPath('message', 'Students retrieved successfully.');
    }

    public function test_can_retrieve_teachers_list(): void
    {
        $response = $this->getJson('/api/teachers');

        $response->assertOk()
            ->assertJsonPath('message', 'Teachers retrieved successfully.');
    }

    public function test_can_retrieve_learning_analytics(): void
    {
        // Endpoint is now protected by auth:sanctum
        $this->getJson('/api/learning-analytics')->assertStatus(401);

        $admin = User::where('role', 'admin')->first();
        $response = $this->actingAs($admin)->getJson('/api/learning-analytics');

        $response->assertOk()
            ->assertJsonPath('message', 'Learning analytics retrieved successfully.');
    }

    public function test_can_retrieve_risk_assessments(): void
    {
        $this->getJson('/api/risk-assessments')->assertStatus(401);

        $admin = User::where('role', 'admin')->first();
        $response = $this->actingAs($admin)->getJson('/api/risk-assessments');

        $response->assertOk()
            ->assertJsonPath('message', 'Risk assessments retrieved successfully.');
    }

    public function test_can_retrieve_predictions(): void
    {
        $this->getJson('/api/predictions')->assertStatus(401);

        $admin = User::where('role', 'admin')->first();
        $response = $this->actingAs($admin)->getJson('/api/predictions');

        $response->assertOk()
            ->assertJsonPath('message', 'Predictions retrieved successfully.');
    }

    public function test_student_dashboard_endpoint(): void
    {
        $studentUser = User::where('email', 'student@slas.com')->first();

        $response = $this->actingAs($studentUser)->getJson('/api/dashboard/student');

        $response->assertOk()
            ->assertJsonPath('message', 'Student dashboard retrieved successfully.');
    }

    public function test_student_dashboard_returns_exact_analytics_and_chart_data(): void
    {
        $studentUser = User::where('email', 'student@slas.com')->first();
        $student = $studentUser->student;

        \App\Models\LearningAnalytic::create([
            'student_id' => $student->id,
            'period_type' => 'semester',
            'period_start' => '2026-01-01',
            'period_end' => '2026-06-30',
            'attendance_rate' => 92.5,
            'assignment_average' => 88.0,
            'quiz_average' => 84.0,
            'exam_average' => 86.0,
            'overall_performance' => 87.5,
            'calculated_at' => now(),
        ]);

        $response = $this->actingAs($studentUser)->getJson('/api/dashboard/student');

        $response->assertOk()
            ->assertJsonPath('message', 'Student dashboard retrieved successfully.')
            ->assertJsonPath('data.analytics.overall_performance', 87.5)
            ->assertJsonPath('data.analytics.attendance_rate', 92.5)
            ->assertJsonPath('data.analytics.assignment_average', 88)
            ->assertJsonPath('data.analytics.quiz_average', 84);
    }

    public function test_student_dashboard_supports_semester_and_course_filtering(): void
    {
        $studentUser = User::where('email', 'student@slas.com')->first();
        $student = $studentUser->student;

        $semester = \App\Models\Semester::first();
        $course = \App\Models\Course::first();

        $response = $this->actingAs($studentUser)->getJson("/api/dashboard/student?semester_id={$semester->id}&course_id={$course->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Student dashboard retrieved successfully.')
            ->assertJsonStructure([
                'data' => [
                    'student',
                    'enrollments',
                    'semesters',
                    'chart_data',
                    'chart_labels',
                ],
            ]);
    }

    public function test_authenticated_student_can_retrieve_attendance_summary(): void
    {
        $studentUser = User::where('email', 'student@slas.com')->first();

        $response = $this->actingAs($studentUser)->getJson('/api/student/attendance');

        $response->assertOk()
            ->assertJsonPath('message', 'Student attendance retrieved successfully.')
            ->assertJsonStructure([
                'data' => [
                    'details',
                    'summary' => [
                        'total',
                        'present',
                        'absent',
                        'late',
                        'excused',
                        'percentage',
                    ],
                ],
            ]);
    }

    public function test_unauthenticated_cannot_access_student_attendance(): void
    {
        $response = $this->getJson('/api/student/attendance');

        $response->assertStatus(401);
    }

    public function test_non_student_account_gets_404_on_attendance_endpoint(): void
    {
        $adminUser = User::where('email', 'admin@slas.com')->first();

        $response = $this->actingAs($adminUser)->getJson('/api/student/attendance');

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Student profile not found.');
    }

    public function test_authenticated_student_can_retrieve_assignments_list(): void
    {
        $studentUser = User::where('email', 'student@slas.com')->first();

        $response = $this->actingAs($studentUser)->getJson('/api/student/assignments');

        $response->assertOk()
            ->assertJsonPath('message', 'Student assignments retrieved successfully.')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'due_date',
                        'total_score',
                        'submissions',
                        'attempts',
                        'course',
                        'semester',
                    ],
                ],
            ]);
    }

    public function test_unauthenticated_cannot_access_student_assignments(): void
    {
        $response = $this->getJson('/api/student/assignments');

        $response->assertStatus(401);
    }

    public function test_non_student_account_gets_404_on_assignments_endpoint(): void
    {
        $adminUser = User::where('email', 'admin@slas.com')->first();

        $response = $this->actingAs($adminUser)->getJson('/api/student/assignments');

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Student profile not found.');
    }

    public function test_teacher_dashboard_endpoint(): void
    {
        $teacherUser = User::where('email', 'teacher@slas.com')->first();

        $response = $this->actingAs($teacherUser)->getJson('/api/dashboard/teacher');

        $response->assertOk()
            ->assertJsonPath('message', 'Teacher dashboard retrieved successfully.');
    }

    public function test_admin_dashboard_endpoint(): void
    {
        $adminUser = User::where('email', 'admin@slas.com')->first();

        $response = $this->actingAs($adminUser)->getJson('/api/dashboard/admin');

        $response->assertOk()
            ->assertJsonPath('message', 'Admin dashboard retrieved successfully.');
    }
}
