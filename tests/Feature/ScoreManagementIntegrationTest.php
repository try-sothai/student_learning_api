<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\ClassRoom;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CourseOfferingTeacher;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Grade;
use App\Models\Quiz;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoreManagementIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected $teacherUser;
    protected $teacher;
    protected $otherTeacherUser;
    protected $otherTeacher;
    protected $studentUser1;
    protected $student1;
    protected $studentUser2;
    protected $student2;
    protected $offering;
    protected $course;
    protected $semester;
    protected $assignment;
    protected $quiz;
    protected $exam;

    protected function setUp(): void
    {
        parent::setUp();

        $year = AcademicYear::create(['name' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30']);
        $this->semester = Semester::create(['name' => 'Fall 2026', 'academic_year_id' => $year->id, 'semester_number' => 1, 'start_date' => '2026-09-01', 'end_date' => '2026-12-15']);
        $this->course = Course::create(['name' => 'Software Engineering', 'code' => 'CS301', 'status' => 'active']);
        $classRoom = ClassRoom::create(['name' => 'Year 3 Room B', 'code' => 'Y3RB', 'academic_year_id' => $year->id]);

        $this->teacherUser = User::create(['name' => 'Dr. Robert', 'email' => 'robert@test.com', 'password' => 'pwd', 'role' => 'teacher']);
        $this->teacher = Teacher::create(['user_id' => $this->teacherUser->id, 'teacher_code' => 'T201', 'first_name' => 'Robert', 'last_name' => 'Smith']);

        $this->otherTeacherUser = User::create(['name' => 'Prof. Anna', 'email' => 'anna@test.com', 'password' => 'pwd', 'role' => 'teacher']);
        $this->otherTeacher = Teacher::create(['user_id' => $this->otherTeacherUser->id, 'teacher_code' => 'T202', 'first_name' => 'Anna', 'last_name' => 'Taylor']);

        $this->studentUser1 = User::create(['name' => 'Dara', 'email' => 'dara@test.com', 'password' => 'pwd', 'role' => 'student']);
        $this->student1 = Student::create(['user_id' => $this->studentUser1->id, 'student_id' => 'STU101', 'student_code' => 'STU101', 'first_name' => 'Dara', 'last_name' => 'Sok', 'date_of_birth' => '2002-01-01', 'gender' => 'Male']);

        $this->studentUser2 = User::create(['name' => 'Channa', 'email' => 'channa@test.com', 'password' => 'pwd', 'role' => 'student']);
        $this->student2 = Student::create(['user_id' => $this->studentUser2->id, 'student_id' => 'STU102', 'student_code' => 'STU102', 'first_name' => 'Channa', 'last_name' => 'Chan', 'date_of_birth' => '2002-02-02', 'gender' => 'Female']);

        $this->offering = CourseOffering::create([
            'course_id' => $this->course->id,
            'semester_id' => $this->semester->id,
            'class_id' => $classRoom->id,
            'status' => 'active',
        ]);

        CourseOfferingTeacher::create([
            'course_offering_id' => $this->offering->id,
            'teacher_id' => $this->teacher->id,
            'role' => 'primary',
            'status' => 'active',
        ]);

        CourseEnrollment::create(['student_id' => $this->student1->id, 'course_id' => $this->course->id, 'semester_id' => $this->semester->id, 'course_offering_id' => $this->offering->id, 'status' => 'enrolled']);
        CourseEnrollment::create(['student_id' => $this->student2->id, 'course_id' => $this->course->id, 'semester_id' => $this->semester->id, 'course_offering_id' => $this->offering->id, 'status' => 'enrolled']);

        // Create assignment
        $this->assignment = Assignment::create([
            'course_id' => $this->course->id,
            'course_offering_id' => $this->offering->id,
            'title' => 'System Design Document',
            'total_score' => 100,
            'status' => 'published',
            'due_date' => now()->addDays(7),
        ]);

        // Student 1 submitted and graded
        AssignmentSubmission::create([
            'assignment_id' => $this->assignment->id,
            'student_id' => $this->student1->id,
            'status' => 'graded',
            'score' => 90,
            'submitted_at' => now()->subDay(),
        ]);

        // Student 2 submitted and pending grading
        AssignmentSubmission::create([
            'assignment_id' => $this->assignment->id,
            'student_id' => $this->student2->id,
            'status' => 'submitted',
            'score' => null,
            'submitted_at' => now()->subHours(2),
        ]);

        // Create quiz
        $this->quiz = Quiz::create([
            'teacher_id' => $this->teacher->id,
            'course_id' => $this->course->id,
            'semester_id' => $this->semester->id,
            'course_offering_id' => $this->offering->id,
            'title' => 'Agile Methodologies Quiz',
            'total_score' => 50,
            'status' => 'published',
        ]);

        // Create exam (offline paper exam)
        $this->exam = Exam::create([
            'teacher_id' => $this->teacher->id,
            'course_id' => $this->course->id,
            'semester_id' => $this->semester->id,
            'course_offering_id' => $this->offering->id,
            'title' => 'Software Engineering Midterm',
            'exam_type' => 'midterm',
            'exam_date' => now()->subDays(2)->toDateString(),
            'total_score' => 100,
            'status' => 'completed',
            'results_published_at' => null, // Unpublished
        ]);

        // Enter manual score for Student 1 only
        ExamResult::create([
            'exam_id' => $this->exam->id,
            'student_id' => $this->student1->id,
            'score' => 88,
            'grade' => 'B+',
        ]);
    }

    public function test_score_management_overview_returns_real_database_metrics(): void
    {
        $response = $this->actingAs($this->teacherUser)
            ->getJson('/api/teacher/assessments-overview');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.total_assignments', 1)
            ->assertJsonPath('data.summary.total_quizzes', 1)
            ->assertJsonPath('data.summary.total_exams', 1)
            ->assertJsonPath('data.summary.total_assessments', 3)
            ->assertJsonPath('data.summary.pending_submissions', 1);

        $data = $response->json('data');
        $this->assertNotEmpty($data['course_progress']);
        $this->assertNotEmpty($data['pending_assessments']);
        $this->assertNotEmpty($data['gradebook']);
    }

    public function test_score_management_course_progress_calculated_accurately(): void
    {
        $response = $this->actingAs($this->teacherUser)
            ->getJson('/api/teacher/assessments-overview');

        $response->assertStatus(200);
        $progressList = $response->json('data.course_progress');
        $this->assertCount(1, $progressList);

        $cp = $progressList[0];
        $this->assertEquals('CS301', $cp['course_code']);
        $this->assertEquals('Year 3 Room B', $cp['class_name']);
        $this->assertEquals(3, $cp['total_assessments']);
        $this->assertGreaterThan(0, $cp['progress_percent']);
    }

    public function test_score_management_pending_assessments_lists_actionable_tasks(): void
    {
        $response = $this->actingAs($this->teacherUser)
            ->getJson('/api/teacher/assessments-overview');

        $response->assertStatus(200);
        $pending = $response->json('data.pending_assessments');
        $this->assertNotEmpty($pending);

        // Verify assignment pending submission is present
        $asnPending = collect($pending)->first(fn ($item) => $item['type'] === 'Assignment');
        $this->assertNotNull($asnPending);
        $this->assertEquals('CS301', $asnPending['course_code']);

        // Verify unpublished exam is present as pending
        $examPending = collect($pending)->first(fn ($item) => $item['type'] === 'Exam');
        $this->assertNotNull($examPending);
        $this->assertEquals('CS301', $examPending['course_code']);
    }

    public function test_score_management_gradebook_roster_and_filtering(): void
    {
        // Add finalized grade for student 1
        Grade::create([
            'student_id' => $this->student1->id,
            'course_id' => $this->course->id,
            'semester_id' => $this->semester->id,
            'course_offering_id' => $this->offering->id,
            'assignment_score' => 90,
            'quiz_score' => 85,
            'attendance_score' => 95,
            'midterm_score' => 88,
            'final_exam_score' => 92,
            'total_score' => 89.9,
            'letter_grade' => 'A-',
            'grade_point' => 3.7,
            'status' => 'finalized',
        ]);

        $response = $this->actingAs($this->teacherUser)
            ->getJson("/api/teacher/assessments-overview?course_id={$this->course->id}");

        $response->assertStatus(200);
        $gradebook = $response->json('data.gradebook');
        $this->assertNotEmpty($gradebook);

        $stu1Grade = collect($gradebook)->first(fn ($g) => $g['student_id'] === $this->student1->id);
        $this->assertNotNull($stu1Grade);
        $this->assertEquals('A-', $stu1Grade['letter_grade']);
        $this->assertEquals(89.9, $stu1Grade['total_score']);
    }

    public function test_unauthorized_teacher_cannot_access_score_management_of_other_teacher(): void
    {
        // Dr. Robert can access CS301
        $this->actingAs($this->teacherUser)
            ->getJson("/api/teacher/assessments-overview?course_id={$this->course->id}")
            ->assertStatus(200);

        // Prof. Anna is not authorized for CS301
        $this->actingAs($this->otherTeacherUser)
            ->getJson("/api/teacher/assessments-overview?course_id={$this->course->id}")
            ->assertStatus(403);
    }
}
