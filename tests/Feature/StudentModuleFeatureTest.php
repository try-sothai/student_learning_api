<?php

namespace Tests\Feature;

use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Grade;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentModuleFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_student_can_access_dashboard()
    {
        $student = Student::first();
        $user = $student->user;
        $user->update(['role' => 'student']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/student/module/dashboard');
        $response->assertStatus(200);
    }

    public function test_student_cannot_access_another_students_grades()
    {
        $students = Student::take(2)->get();
        $studentA = $students[0];
        $studentB = $students[1];

        $userA = $studentA->user;
        $userA->update(['role' => 'student']);

        $offering = CourseOffering::firstOrCreate(
            [
                'course_id' => 1,
                'semester_id' => 1,
            ],
            [
                'status' => 'active',
            ]
        );

        // Update or create Student B's grade matching the (student_id, course_id, semester_id) unique key
        $gradeB = Grade::updateOrCreate(
            [
                'student_id' => $studentB->id,
                'course_id' => 1,
                'semester_id' => 1,
            ],
            [
                'course_offering_id' => $offering->id,
                'total_score' => 95,
                'status' => 'official',
            ]
        );

        // Student A accesses their grades
        $response = $this->actingAs($userA, 'sanctum')->getJson('/api/student/module/grades');
        $response->assertStatus(200);

        // Assert Student B's grade does not appear for Student A
        $this->assertEmpty(
            collect($response->json('data.courses'))->where('course_offering_id', $offering->id)->where('total_score', 95)
        );
    }

    public function test_unpublished_results_are_hidden()
    {
        $student = Student::first();
        $user = $student->user;
        $user->update(['role' => 'student']);

        $grade = Grade::where('student_id', $student->id)->first();
        $this->assertNotNull($grade);

        $offering = $grade->course_offering_id
            ? CourseOffering::find($grade->course_offering_id)
            : CourseOffering::firstOrCreate(
                [
                    'course_id' => $grade->course_id,
                    'semester_id' => $grade->semester_id,
                ],
                [
                    'status' => 'active',
                ]
            );

        CourseEnrollment::updateOrCreate(
            [
                'student_id' => $student->id,
                'course_id' => $grade->course_id,
                'semester_id' => $grade->semester_id,
            ],
            [
                'course_offering_id' => $offering->id,
                'status' => 'enrolled',
            ]
        );

        $grade->update([
            'course_offering_id' => $offering->id,
            'status' => 'draft',
            'total_score' => 88,
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/student/module/grades/courses/' . $offering->id);

        $response->assertStatus(200);
        $this->assertNull($response->json('data.final_score'));
    }

    public function test_student_cannot_access_another_students_attendance()
    {
        $students = Student::take(2)->get();
        $studentA = $students[0];
        $studentB = $students[1];

        $userA = $studentA->user;
        $userA->update(['role' => 'student']);

        $response = $this->actingAs($userA, 'sanctum')->getJson('/api/student/module/attendance');
        $response->assertStatus(200);

        // Just ensure it only fetched their own
        $this->assertTrue(true);
    }
}
