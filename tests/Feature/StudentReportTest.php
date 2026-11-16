<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Department;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_dashboard_returns_filtered_semester_data()
    {
        $this->seed();

        $user = User::factory()->create(['role' => 'student']);
        $dept = Department::first();
        $academicYear = AcademicYear::first();

        $student = Student::create([
            'user_id' => $user->id,
            'student_code' => 'TEST_STUDENT_01',
            'first_name' => 'Test',
            'last_name' => 'Student',
            'date_of_birth' => '2004-01-01',
            'gender' => 'Male',
            'department_id' => $dept?->id,
        ]);

        $semester1 = Semester::firstOrCreate(
            ['name' => 'Semester 1'],
            ['academic_year_id' => $academicYear?->id, 'semester_number' => 1]
        );
        $semester2 = Semester::firstOrCreate(
            ['name' => 'Semester 2'],
            ['academic_year_id' => $academicYear?->id, 'semester_number' => 2]
        );

        $course1 = Course::create([
            'name' => 'Report Course 1',
            'code' => 'RC101',
            'course_code' => 'RC101',
            'department_id' => $dept?->id,
        ]);
        $course2 = Course::create([
            'name' => 'Report Course 2',
            'code' => 'RC102',
            'course_code' => 'RC102',
            'department_id' => $dept?->id,
        ]);

        CourseEnrollment::create([
            'student_id' => $student->id,
            'course_id' => $course1->id,
            'semester_id' => $semester1->id,
            'final_score' => 90,
            'status' => 'enrolled',
        ]);

        CourseEnrollment::create([
            'student_id' => $student->id,
            'course_id' => $course2->id,
            'semester_id' => $semester2->id,
            'final_score' => 80,
            'status' => 'enrolled',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        // Fetch without filter
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
             ->getJson('/api/dashboard/student');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.enrollments'));

        // Fetch with semester 1 filter
        $responseFiltered = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
             ->getJson('/api/dashboard/student?semester_id=' . $semester1->id);

        $responseFiltered->assertStatus(200);
        $this->assertCount(1, $responseFiltered->json('data.enrollments'));
        $this->assertEquals(90, $responseFiltered->json('data.enrollments.0.final_score'));
    }
}
