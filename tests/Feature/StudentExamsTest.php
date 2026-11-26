<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentExamsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_cannot_access_student_exams()
    {
        $this->getJson('/api/student/exams')->assertStatus(401);
    }

    public function test_user_without_student_profile_gets_404()
    {
        $user = User::create(['name' => 'X', 'email' => 'x@test.com', 'password' => 'pwd', 'role' => 'student']);
        $this->actingAs($user)->getJson('/api/student/exams')->assertStatus(404);
    }

    public function test_student_sees_only_enrolled_exams_and_own_results()
    {
        $year = AcademicYear::create(['name' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30']);
        $semester = Semester::create(['name' => 'Fall 2026', 'academic_year_id' => $year->id, 'semester_number' => 1, 'start_date' => '2026-09-01', 'end_date' => '2026-12-15']);
        $enrolledCourse = Course::create(['name' => 'Math', 'code' => 'M101', 'status' => 'active']);
        $otherCourse = Course::create(['name' => 'Art', 'code' => 'A101', 'status' => 'active']);

        $tUser = User::create(['name' => 'T', 'email' => 't@test.com', 'password' => 'pwd', 'role' => 'teacher']);
        $teacher = Teacher::create(['user_id' => $tUser->id, 'teacher_code' => 'T01', 'first_name' => 'T', 'last_name' => 'L']);

        $u1 = User::create(['name' => 'S1', 'email' => 's1@test.com', 'password' => 'pwd', 'role' => 'student']);
        $s1 = Student::create(['user_id' => $u1->id, 'student_id' => 'STD01', 'first_name' => 'S1', 'last_name' => 'L', 'date_of_birth' => '2000-01-01', 'gender' => 'Male']);
        $u2 = User::create(['name' => 'S2', 'email' => 's2@test.com', 'password' => 'pwd', 'role' => 'student']);
        $s2 = Student::create(['user_id' => $u2->id, 'student_id' => 'STD02', 'first_name' => 'S2', 'last_name' => 'L', 'date_of_birth' => '2000-01-01', 'gender' => 'Female']);

        CourseEnrollment::create(['student_id' => $s1->id, 'course_id' => $enrolledCourse->id, 'semester_id' => $semester->id, 'status' => 'enrolled']);
        CourseEnrollment::create(['student_id' => $s2->id, 'course_id' => $enrolledCourse->id, 'semester_id' => $semester->id, 'status' => 'enrolled']);

        $base = ['teacher_id' => $teacher->id, 'semester_id' => $semester->id, 'exam_type' => 'midterm', 'total_score' => 100];
        $completed = Exam::create($base + ['course_id' => $enrolledCourse->id, 'title' => 'Midterm', 'exam_date' => '2026-10-01', 'start_time' => '09:00', 'end_time' => '10:30', 'duration_minutes' => 90, 'venue' => 'Room 101', 'description' => 'Paper exam', 'status' => 'completed']);
        $unpublished = Exam::create($base + ['course_id' => $enrolledCourse->id, 'title' => 'Pending Final', 'exam_date' => '2026-11-28', 'start_time' => '14:00', 'end_time' => '16:00', 'duration_minutes' => 120, 'status' => 'results_pending']);
        Exam::create($base + ['course_id' => $enrolledCourse->id, 'title' => 'Final', 'exam_date' => '2026-12-10', 'status' => 'scheduled']);
        Exam::create($base + ['course_id' => $enrolledCourse->id, 'title' => 'Cancelled', 'exam_date' => '2026-11-01', 'status' => 'cancelled']);
        Exam::create($base + ['course_id' => $otherCourse->id, 'title' => 'Other', 'exam_date' => '2026-11-05', 'status' => 'scheduled']);

        ExamResult::create(['exam_id' => $completed->id, 'student_id' => $s1->id, 'score' => 85, 'grade' => 'A-']);
        $s2CompletedRes = ExamResult::create(['exam_id' => $completed->id, 'student_id' => $s2->id, 'score' => 40, 'grade' => 'F']);
        // Even if results exist in DB for an unpublished exam, students must never see them
        ExamResult::create(['exam_id' => $unpublished->id, 'student_id' => $s1->id, 'score' => 91, 'grade' => 'A']);

        $response = $this->actingAs($u1)->getJson('/api/student/exams?student_id=' . $s2->id);

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.title', 'Midterm')
            ->assertJsonPath('data.0.start_time', '09:00')
            ->assertJsonPath('data.0.end_time', '10:30')
            ->assertJsonPath('data.0.venue', 'Room 101')
            ->assertJsonPath('data.0.display_status', 'Results Published')
            ->assertJsonPath('data.1.title', 'Pending Final')
            ->assertJsonPath('data.1.display_status', 'Results Pending')
            ->assertJsonPath('data.2.title', 'Final')
            ->assertJsonPath('data.2.display_status', 'Scheduled')
            ->assertJsonCount(1, 'data.0.results')
            ->assertJsonPath('data.0.results.0.student_id', $s1->id)
            ->assertJsonPath('data.0.results.0.result_status', 'Pass')
            ->assertJsonCount(0, 'data.1.results')
            ->assertJsonCount(0, 'data.2.results');

        $this->assertEquals(85, (float) $response->json('data.0.results.0.score'));
        $this->assertEquals(85.0, (float) $response->json('data.0.results.0.percentage'));

        // Student cannot view another student's direct ExamResult resource (404)
        $this->actingAs($u1)->getJson("/api/exam-results/{$s2CompletedRes->id}")->assertStatus(404);

        // Student cannot create or take exam results directly (403)
        $this->actingAs($u1)->postJson('/api/exam-results', [
            'exam_id' => $completed->id,
            'student_id' => $s1->id,
            'score' => 100,
        ])->assertStatus(403);
    }
}
