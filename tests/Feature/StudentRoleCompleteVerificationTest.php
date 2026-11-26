<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Attendance;
use App\Models\AttendanceDetail;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Notification;
use App\Models\Prediction;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\Recommendation;
use App\Models\RiskAssessment;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentRoleCompleteVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function createStudentSetup(): array
    {
        $year = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
        ]);
        $semester = Semester::create([
            'name' => 'Semester 1, 2026',
            'academic_year_id' => $year->id,
            'semester_number' => 1,
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
        ]);
        $course1 = Course::create(['name' => 'Data Structures', 'code' => 'CS201', 'credit' => 3, 'status' => 'active']);
        $course2 = Course::create(['name' => 'Database Systems', 'code' => 'CS202', 'credit' => 3, 'status' => 'active']);

        $tUser = User::create(['name' => 'Prof. Sok', 'email' => 'sok@slas.edu.kh', 'password' => 'pwd', 'role' => 'teacher']);
        $teacher = Teacher::create(['user_id' => $tUser->id, 'teacher_code' => 'TCH01', 'first_name' => 'Sok', 'last_name' => 'Chan']);

        $u1 = User::create(['name' => 'Sophea Student', 'username' => 'STU2026001', 'email' => 'sophea@slas.edu.kh', 'password' => 'pwd', 'role' => 'student']);
        $s1 = Student::create(['user_id' => $u1->id, 'student_id' => 'STU2026001', 'first_name' => 'Sophea', 'last_name' => 'Student', 'date_of_birth' => '2004-05-10', 'gender' => 'Female']);

        $u2 = User::create(['name' => 'Other Student', 'username' => 'STU2026002', 'email' => 'other@slas.edu.kh', 'password' => 'pwd', 'role' => 'student']);
        $s2 = Student::create(['user_id' => $u2->id, 'student_id' => 'STU2026002', 'first_name' => 'Other', 'last_name' => 'Student', 'date_of_birth' => '2004-06-15', 'gender' => 'Male']);

        CourseEnrollment::create(['student_id' => $s1->id, 'course_id' => $course1->id, 'semester_id' => $semester->id, 'status' => 'enrolled']);
        CourseEnrollment::create(['student_id' => $s1->id, 'course_id' => $course2->id, 'semester_id' => $semester->id, 'status' => 'enrolled']);
        CourseEnrollment::create(['student_id' => $s2->id, 'course_id' => $course1->id, 'semester_id' => $semester->id, 'status' => 'enrolled']);

        return compact('year', 'semester', 'course1', 'course2', 'teacher', 'u1', 's1', 'u2', 's2');
    }

    public function test_student_dashboard_and_my_analytics_consistency_and_summaries()
    {
        $ctx = $this->createStudentSetup();
        $u1 = $ctx['u1'];
        $s1 = $ctx['s1'];
        $course1 = $ctx['course1'];
        $semester = $ctx['semester'];
        $teacher = $ctx['teacher'];

        // Create 4 attendance sessions: 3 present, 1 late => 75% attendance rate
        $dates = ['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04'];
        $statuses = ['present', 'present', 'present', 'late'];
        foreach ($dates as $idx => $dt) {
            $att = Attendance::create([
                'course_id' => $course1->id,
                'teacher_id' => $teacher->id,
                'semester_id' => $semester->id,
                'attendance_date' => $dt,
            ]);
            AttendanceDetail::create([
                'attendance_id' => $att->id,
                'student_id' => $s1->id,
                'status' => $statuses[$idx],
            ]);
        }

        // Graded assignment (85%)
        $assignment = Assignment::create([
            'course_id' => $course1->id,
            'teacher_id' => $teacher->id,
            'semester_id' => $semester->id,
            'title' => 'Linked List Lab',
            'total_score' => 100,
            'due_date' => '2026-10-15',
            'status' => 'published',
        ]);
        AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'student_id' => $s1->id,
            'submitted_at' => '2026-10-10 10:00:00',
            'score' => 85,
            'status' => 'graded',
        ]);

        // Graded exam (90%)
        $exam = Exam::create([
            'course_id' => $course1->id,
            'teacher_id' => $teacher->id,
            'semester_id' => $semester->id,
            'title' => 'Midterm Exam',
            'exam_type' => 'midterm',
            'exam_date' => '2026-10-12',
            'total_score' => 100,
            'status' => 'completed',
        ]);
        ExamResult::create([
            'exam_id' => $exam->id,
            'student_id' => $s1->id,
            'score' => 90,
            'grade' => 'A',
        ]);

        // 1. Check /api/dashboard/student
        $dashRes = $this->actingAs($u1)->getJson('/api/dashboard/student');
        $dashRes->assertOk()
            ->assertJsonPath('data.attendance_summary.total', 4)
            ->assertJsonPath('data.attendance_summary.present', 3)
            ->assertJsonPath('data.attendance_summary.late', 1)
            ->assertJsonPath('data.assignments_summary.total', 1)
            ->assertJsonPath('data.assignments_summary.completed', 1)
            ->assertJsonPath('data.exams_summary.total', 1)
            ->assertJsonPath('data.exams_summary.completed', 1);

        $this->assertEquals(75.0, (float) $dashRes->json('data.attendance_summary.percentage'));
        $this->assertEquals(85.0, (float) $dashRes->json('data.assignments_summary.average'));
        $this->assertEquals(90.0, (float) $dashRes->json('data.exams_summary.average'));
        $this->assertNotEmpty($dashRes->json('data.recent_activities'));

        // 2. Check /api/student/attendance matches 75% and includes course_breakdown
        $attRes = $this->actingAs($u1)->getJson('/api/student/attendance');
        $attRes->assertOk();
        $this->assertEquals(75.0, (float) $attRes->json('data.summary.percentage'));
        $this->assertNotEmpty($attRes->json('data.course_breakdown'));

        // 3. Check /api/student/my-analytics matches 75% attendance, 85% assignment, 90% exam
        $analyticsRes = $this->actingAs($u1)->getJson('/api/student/my-analytics?period_type=semester&semester_id=' . $semester->id);
        $analyticsRes->assertOk();
        $analyticsRows = $analyticsRes->json('data');
        $this->assertNotEmpty($analyticsRows);
        $this->assertEquals(75.0, (float) $analyticsRows[0]['attendance_rate']);
        $this->assertEquals(85.0, (float) $analyticsRows[0]['assignment_average']);
        $this->assertEquals(90.0, (float) $analyticsRows[0]['exam_average']);
    }

    public function test_quiz_attempt_auto_grading_on_submit()
    {
        $ctx = $this->createStudentSetup();
        $u1 = $ctx['u1'];
        $s1 = $ctx['s1'];
        $course1 = $ctx['course1'];
        $semester = $ctx['semester'];
        $teacher = $ctx['teacher'];

        $quiz = Quiz::create([
            'course_id' => $course1->id,
            'teacher_id' => $teacher->id,
            'semester_id' => $semester->id,
            'title' => 'Trees & Graphs Quiz',
            'total_score' => 20,
            'duration_minutes' => 15,
            'status' => 'published',
        ]);

        $q1 = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question_text' => 'What is the time complexity of binary search?',
            'question_type' => 'mcq',
            'question_order' => 1,
            'option_a' => 'O(1)',
            'option_b' => 'O(log n)',
            'option_c' => 'O(n)',
            'option_d' => 'O(n^2)',
            'correct_answer' => 'O(log n)',
            'marks' => 10,
        ]);

        $q2 = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question_text' => 'Which data structure uses FIFO?',
            'question_type' => 'mcq',
            'question_order' => 2,
            'option_a' => 'Stack',
            'option_b' => 'Queue',
            'option_c' => 'Tree',
            'option_d' => 'Graph',
            'correct_answer' => 'Queue',
            'marks' => 10,
        ]);

        $attempt = QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_id' => $s1->id,
            'started_at' => now(),
            'status' => 'in_progress',
        ]);

        // Answer Q1 correctly (10 pts) and Q2 incorrectly (0 pts)
        QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_id' => $q1->id,
            'answer' => 'O(log n)',
        ]);
        QuizAnswer::create([
            'quiz_attempt_id' => $attempt->id,
            'question_id' => $q2->id,
            'answer' => 'Stack',
        ]);

        // Student cannot bypass role authorization via admin-only PUT /api/quiz-attempts/{id}
        $forbiddenRes = $this->actingAs($u1)->putJson('/api/quiz-attempts/' . $attempt->id, [
            'status' => 'completed',
        ]);
        $forbiddenRes->assertStatus(403);

        // Complete attempt via student quiz submission endpoint (server-side auto-grading)
        $res = $this->actingAs($u1)->postJson('/api/student/quiz-attempts/' . $attempt->id . '/submit');

        $res->assertOk();
        $this->assertEquals(10.0, (float) $res->json('data.score'));
    }

    public function test_student_data_isolation_and_notification_mark_as_read()
    {
        $ctx = $this->createStudentSetup();
        $u1 = $ctx['u1'];
        $s1 = $ctx['s1'];
        $u2 = $ctx['u2'];
        $s2 = $ctx['s2'];
        $course1 = $ctx['course1'];
        $semester = $ctx['semester'];

        // Create notifications for u1 and u2
        $n1 = Notification::create([
            'user_id' => $u1->id,
            'title' => 'New Assignment Posted',
            'message' => 'Assignment 1 is now available.',
            'type' => 'assignment',
            'is_read' => false,
        ]);
        $n2 = Notification::create([
            'user_id' => $u2->id,
            'title' => 'Private Alert',
            'message' => 'Other student notification.',
            'type' => 'risk',
            'is_read' => false,
        ]);

        // u1 marks n1 as read with empty body (Android PATCH /api/notifications/{id}/read)
        $this->actingAs($u1)->patchJson('/api/notifications/' . $n1->id . '/read')
            ->assertOk()
            ->assertJsonPath('data.is_read', true)
            ->assertJsonPath('data.category', 'Academic');

        // u1 cannot mark u2's notification as read
        $this->actingAs($u1)->patchJson('/api/notifications/' . $n2->id . '/read')
            ->assertStatus(403);

        // Recommendations & Predictions & Risk Assessments isolation
        Recommendation::create([
            'student_id' => $s1->id,
            'course_id' => $course1->id,
            'type' => 'study',
            'title' => 'Review Binary Trees',
            'message' => 'Review binary trees',
            'priority' => 'high',
            'status' => 'active',
        ]);
        $rec2 = Recommendation::create([
            'student_id' => $s2->id,
            'course_id' => $course1->id,
            'type' => 'attendance',
            'title' => 'Other Student Recommendation',
            'message' => 'Other student recommendation',
            'priority' => 'high',
            'status' => 'active',
        ]);

        Prediction::create([
            'student_id' => $s1->id,
            'course_id' => $course1->id,
            'semester_id' => $semester->id,
            'prediction_type' => 'final_grade',
            'predicted_score' => 88.5,
            'confidence_score' => 0.91,
            'prediction_date' => '2026-10-20',
            'model_version' => 'v1.0.0',
        ]);
        Prediction::create([
            'student_id' => $s2->id,
            'course_id' => $course1->id,
            'semester_id' => $semester->id,
            'prediction_type' => 'final_grade',
            'predicted_score' => 52.0,
            'confidence_score' => 0.80,
            'prediction_date' => '2026-10-20',
            'model_version' => 'v1.0.0',
        ]);

        RiskAssessment::create([
            'student_id' => $s1->id,
            'course_id' => $course1->id,
            'semester_id' => $semester->id,
            'risk_level' => 'low',
            'risk_score' => 15,
            'assessment_date' => '2026-10-20',
            'status' => 'active',
        ]);
        RiskAssessment::create([
            'student_id' => $s2->id,
            'course_id' => $course1->id,
            'semester_id' => $semester->id,
            'risk_level' => 'high',
            'risk_score' => 85,
            'assessment_date' => '2026-10-20',
            'status' => 'active',
        ]);

        // Even if u1 passes ?student_id=$s2->id, u1 only gets s1's records
        $recRes = $this->actingAs($u1)->getJson('/api/recommendations?student_id=' . $s2->id);
        $recRes->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_id', $s1->id);

        // u1 cannot update s2's recommendation
        $this->actingAs($u1)->putJson('/api/recommendations/' . $rec2->id, ['is_read' => true])
            ->assertStatus(403);

        $predRes = $this->actingAs($u1)->getJson('/api/predictions?student_id=' . $s2->id);
        $predRes->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_id', $s1->id);

        $riskRes = $this->actingAs($u1)->getJson('/api/risk-assessments?student_id=' . $s2->id);
        $riskRes->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_id', $s1->id);
    }
}
