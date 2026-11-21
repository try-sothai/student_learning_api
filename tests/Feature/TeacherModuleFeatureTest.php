<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Attendance;
use App\Models\AttendanceDetail;
use App\Models\ClassRoom;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CourseOfferingTeacher;
use App\Models\Department;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\RiskAssessment;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\TeacherAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherModuleFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacherUserA;
    protected Teacher $teacherA;
    protected User $teacherUserB;
    protected Teacher $teacherB;
    protected User $studentUser;
    protected Student $student;
    protected CourseOffering $offeringA;
    protected CourseOffering $offeringB;
    protected Course $course1;
    protected Course $course2;
    protected ClassRoom $classK;
    protected Semester $sem;
    protected AcademicYear $ay;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create(['name' => 'CS Dept', 'code' => 'CS', 'status' => 'active']);
        $this->ay = AcademicYear::create(['name' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'status' => 'active']);
        $this->sem = Semester::create(['academic_year_id' => $this->ay->id, 'name' => 'Semester 1', 'semester_number' => 1, 'start_date' => '2026-09-01', 'end_date' => '2027-01-31', 'status' => 'active']);
        $this->classK = ClassRoom::create(['department_id' => $dept->id, 'academic_year_id' => $this->ay->id, 'name' => 'Class K', 'code' => 'CS-K', 'year_level' => 1, 'status' => 'active']);

        // Teacher A
        $this->teacherUserA = User::create(['name' => 'Teacher A', 'email' => 'teachera@test.com', 'password' => bcrypt('password'), 'role' => 'teacher', 'status' => 'active']);
        $this->teacherA = Teacher::create(['user_id' => $this->teacherUserA->id, 'teacher_code' => 'TCH001', 'first_name' => 'Teacher', 'last_name' => 'A', 'status' => 'active', 'department_id' => $dept->id]);

        // Teacher B
        $this->teacherUserB = User::create(['name' => 'Teacher B', 'email' => 'teacherb@test.com', 'password' => bcrypt('password'), 'role' => 'teacher', 'status' => 'active']);
        $this->teacherB = Teacher::create(['user_id' => $this->teacherUserB->id, 'teacher_code' => 'TCH002', 'first_name' => 'Teacher', 'last_name' => 'B', 'status' => 'active', 'department_id' => $dept->id]);

        // Student
        $this->studentUser = User::create(['name' => 'Student One', 'email' => 'student1@test.com', 'password' => bcrypt('password'), 'role' => 'student', 'status' => 'active']);
        $this->student = Student::create(['user_id' => $this->studentUser->id, 'student_code' => 'STD001', 'first_name' => 'Student', 'last_name' => 'One', 'status' => 'enrolled', 'department_id' => $dept->id, 'class_id' => $this->classK->id, 'gender' => 'Male', 'date_of_birth' => '2005-01-01']);

        // Course 1 & Course 2
        $this->course1 = Course::create(['course_code' => 'CS101', 'course_name' => 'Intro to CS', 'credit' => 3, 'department_id' => $dept->id, 'teacher_id' => $this->teacherA->id]);
        $this->course2 = Course::create(['course_code' => 'CS102', 'course_name' => 'Data Structures', 'credit' => 3, 'department_id' => $dept->id, 'teacher_id' => $this->teacherB->id]);

        // Offerings
        $this->offeringA = CourseOffering::create([
            'course_id' => $this->course1->id,
            'class_id' => $this->classK->id,
            'semester_id' => $this->sem->id,
            'academic_year_id' => $this->ay->id,
            'study_year' => 1,
            'section_name' => 'Class K',
            'status' => 'active',
        ]);
        CourseOfferingTeacher::create([
            'course_offering_id' => $this->offeringA->id,
            'teacher_id' => $this->teacherA->id,
            'role' => 'primary',
            'status' => 'active',
            'assigned_at' => now(),
        ]);

        $this->offeringB = CourseOffering::create([
            'course_id' => $this->course2->id,
            'class_id' => $this->classK->id,
            'semester_id' => $this->sem->id,
            'academic_year_id' => $this->ay->id,
            'study_year' => 1,
            'section_name' => 'Class K',
            'status' => 'active',
        ]);
        CourseOfferingTeacher::create([
            'course_offering_id' => $this->offeringB->id,
            'teacher_id' => $this->teacherB->id,
            'role' => 'primary',
            'status' => 'active',
            'assigned_at' => now(),
        ]);

        // Enrollment in Offering A
        CourseEnrollment::create([
            'student_id' => $this->student->id,
            'course_id' => $this->course1->id,
            'semester_id' => $this->sem->id,
            'course_offering_id' => $this->offeringA->id,
            'status' => 'enrolled',
        ]);
    }

    public function test_teacher_can_access_dashboard_summary(): void
    {
        $response = $this->actingAs($this->teacherUserA)
            ->getJson('/api/teacher/dashboard')
            ->assertStatus(200);

        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.summary.total_students', 1);
        $response->assertJsonPath('data.summary.my_courses', 1);
    }

    public function test_teacher_can_access_dynamic_filters(): void
    {
        $response = $this->actingAs($this->teacherUserA)
            ->getJson('/api/teacher/filters')
            ->assertStatus(200);

        $response->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('data.courses'));
        $this->assertNotEmpty($response->json('data.classes'));
    }

    public function test_teacher_sees_only_assigned_courses_in_filters(): void
    {
        $response = $this->actingAs($this->teacherUserA)
            ->getJson('/api/teacher/filters')
            ->assertStatus(200);

        $courseIds = collect($response->json('data.courses'))->pluck('id')->all();
        $this->assertContains($this->course1->id, $courseIds);
        $this->assertNotContains($this->course2->id, $courseIds);
    }

    public function test_teacher_can_access_engagement_analytics(): void
    {
        $response = $this->actingAs($this->teacherUserA)
            ->getJson('/api/teacher/analytics')
            ->assertStatus(200);

        $response->assertJsonPath('success', true);
        $this->assertCount(4, $response->json('data.engagement'));
    }

    public function test_empty_dataset_handled_gracefully_without_division_by_zero(): void
    {
        // Teacher B has no enrolled students or activities yet
        $response = $this->actingAs($this->teacherUserB)
            ->getJson('/api/teacher/analytics')
            ->assertStatus(200);

        $response->assertJsonPath('success', true);
        $engagement = $response->json('data.engagement');
        foreach ($engagement as $act) {
            $this->assertFalse($act['has_data']);
            $this->assertNull($act['completed']);
        }
    }

    public function test_activity_metrics_calculate_accurately(): void
    {
        // 1. Create completed attendance session with 1 present detail
        $att = Attendance::create([
            'course_id' => $this->course1->id,
            'course_offering_id' => $this->offeringA->id,
            'teacher_id' => $this->teacherA->id,
            'semester_id' => $this->sem->id,
            'attendance_date' => now()->toDateString(),
            'status' => 'completed',
        ]);
        AttendanceDetail::create([
            'attendance_id' => $att->id,
            'student_id' => $this->student->id,
            'status' => 'present',
        ]);

        // 2. Create assignment with on-time submission
        $assign = Assignment::create([
            'course_id' => $this->course1->id,
            'course_offering_id' => $this->offeringA->id,
            'teacher_id' => $this->teacherA->id,
            'semester_id' => $this->sem->id,
            'title' => 'Homework 1',
            'due_date' => now()->addDays(2),
            'status' => 'published',
            'assignment_type' => 'homework',
        ]);
        AssignmentSubmission::create([
            'assignment_id' => $assign->id,
            'student_id' => $this->student->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        // 3. Create quiz with completed attempt
        $quiz = Quiz::create([
            'course_id' => $this->course1->id,
            'course_offering_id' => $this->offeringA->id,
            'teacher_id' => $this->teacherA->id,
            'semester_id' => $this->sem->id,
            'title' => 'Quiz 1',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'status' => 'published',
        ]);
        QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_id' => $this->student->id,
            'attempt_number' => 1,
            'started_at' => now()->subHours(2),
            'submitted_at' => now()->subHour(),
            'status' => 'completed',
        ]);

        $response = $this->actingAs($this->teacherUserA)
            ->getJson('/api/teacher/analytics')
            ->assertStatus(200);

        $data = collect($response->json('data.engagement'))->keyBy('activity');

        // Attendance: 100% completed
        $this->assertEquals(100.0, $data['Attendance']['completed']);
        $this->assertEquals(0.0, $data['Attendance']['missed']);

        // Homework: 100% completed
        $this->assertEquals(100.0, $data['Homework']['completed']);

        // Quiz: 100% completed
        $this->assertEquals(100.0, $data['Quiz']['completed']);
    }

    public function test_at_risk_students_counted_strictly_for_teacher(): void
    {
        RiskAssessment::create([
            'student_id' => $this->student->id,
            'course_id' => $this->course1->id,
            'course_offering_id' => $this->offeringA->id,
            'semester_id' => $this->sem->id,
            'risk_level' => 'high',
            'risk_score' => 85.0,
            'assessment_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->teacherUserA)
            ->getJson('/api/teacher/dashboard')
            ->assertStatus(200);

        $response->assertJsonPath('data.summary.at_risk_students', 1);

        // Teacher B does not teach offering A, should see 0
        $responseB = $this->actingAs($this->teacherUserB)
            ->getJson('/api/teacher/dashboard')
            ->assertStatus(200);

        $responseB->assertJsonPath('data.summary.at_risk_students', 0);
    }

    public function test_student_cannot_access_teacher_dashboard(): void
    {
        $this->actingAs($this->studentUser)
            ->getJson('/api/teacher/dashboard')
            ->assertStatus(403);
    }

    public function test_unauthenticated_cannot_access_teacher_dashboard(): void
    {
        $this->getJson('/api/teacher/dashboard')
            ->assertStatus(401);
    }

    public function test_teacher_a_can_view_assigned_offering(): void
    {
        $this->actingAs($this->teacherUserA)
            ->getJson('/api/teacher/course-offerings/' . $this->offeringA->id)
            ->assertStatus(200);
    }

    public function test_teacher_a_cannot_view_unassigned_offering_of_teacher_b(): void
    {
        $this->actingAs($this->teacherUserA)
            ->getJson('/api/teacher/course-offerings/' . $this->offeringB->id)
            ->assertStatus(403);
    }

    public function test_teacher_cannot_access_unauthorized_course_analytics(): void
    {
        $this->actingAs($this->teacherUserA)
            ->getJson('/api/teacher/analytics?course_id=' . $this->course2->id)
            ->assertStatus(403);
    }

    public function test_teacher_replacement_transfers_access_and_preserves_records(): void
    {
        $authService = app(TeacherAuthorizationService::class);

        // Teacher B currently cannot access Offering A
        $this->actingAs($this->teacherUserB)
            ->getJson('/api/teacher/course-offerings/' . $this->offeringA->id)
            ->assertStatus(403);

        // Perform teacher replacement: Teacher B replaces Teacher A for Offering A
        $authService->transferTeacherAssignment(
            $this->offeringA->id,
            $this->teacherA->id,
            $this->teacherB->id
        );

        // Now Teacher B can access Offering A
        $this->actingAs($this->teacherUserB)
            ->getJson('/api/teacher/course-offerings/' . $this->offeringA->id)
            ->assertStatus(200);

        // Teacher A's active assignment has ended -> cannot access Offering A
        $this->actingAs($this->teacherUserA)
            ->getJson('/api/teacher/course-offerings/' . $this->offeringA->id)
            ->assertStatus(403);

        // Enrolled student is still enrolled in offering A
        $this->assertDatabaseHas('course_enrollments', [
            'course_offering_id' => $this->offeringA->id,
            'student_id' => $this->student->id,
        ]);
    }

    public function test_teacher_students_and_detail_enforce_scope(): void
    {
        $resA = $this->actingAs($this->teacherUserA)
            ->getJson('/api/teacher/students')
            ->assertStatus(200);
        $this->assertCount(1, $resA->json('data.students'));

        $this->actingAs($this->teacherUserA)
            ->getJson('/api/teacher/students/' . $this->student->id)
            ->assertStatus(200)
            ->assertJsonPath('data.student.id', $this->student->id);

        // Teacher B is not teaching this student -> 403
        $this->actingAs($this->teacherUserB)
            ->getJson('/api/teacher/students/' . $this->student->id)
            ->assertStatus(403);
    }

    public function test_teacher_can_record_attendance_and_unauthorized_teacher_is_blocked(): void
    {
        $this->actingAs($this->teacherUserA)
            ->postJson('/api/teacher/course-offerings/' . $this->offeringA->id . '/attendance', [
                'attendance_date' => '2026-11-18',
                'topic' => 'Week 5 Lecture',
                'records' => [
                    ['student_id' => $this->student->id, 'status' => 'present'],
                ],
            ])
            ->assertStatus(201);

        $this->actingAs($this->teacherUserB)
            ->postJson('/api/teacher/course-offerings/' . $this->offeringA->id . '/attendance', [
                'attendance_date' => '2026-11-18',
                'records' => [
                    ['student_id' => $this->student->id, 'status' => 'present'],
                ],
            ])
            ->assertStatus(403);

        $this->actingAs($this->teacherUserA)
            ->getJson('/api/teacher/attendance-overview')
            ->assertStatus(200)
            ->assertJsonPath('data.summary.present', 1);
    }

    public function test_teacher_assignments_grading_quizzes_scores_and_risk_endpoints(): void
    {
        // Create assignment
        $asnRes = $this->actingAs($this->teacherUserA)
            ->postJson('/api/teacher/course-offerings/' . $this->offeringA->id . '/assignments', [
                'title' => 'Lab 1',
                'total_score' => 100,
                'due_date' => now()->addDays(5)->toDateTimeString(),
                'status' => 'published',
            ])
            ->assertStatus(201);
        $asnId = $asnRes->json('data.id');

        $sub = AssignmentSubmission::create([
            'assignment_id' => $asnId,
            'student_id' => $this->student->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        // Teacher B cannot grade Teacher A's submission
        $this->actingAs($this->teacherUserB)
            ->postJson("/api/teacher/submissions/{$sub->id}/grade", ['score' => 90])
            ->assertStatus(403);

        // Teacher A grades submission
        $this->actingAs($this->teacherUserA)
            ->postJson("/api/teacher/submissions/{$sub->id}/grade", [
                'score' => 92,
                'feedback' => 'Well done',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.score', 92);

        // Create quiz, add question, publish
        $qzRes = $this->actingAs($this->teacherUserA)
            ->postJson('/api/teacher/course-offerings/' . $this->offeringA->id . '/quizzes', [
                'title' => 'Pop Quiz 1',
                'total_score' => 50,
                'status' => 'draft',
            ])
            ->assertStatus(201);
        $qzId = $qzRes->json('data.id');

        $this->actingAs($this->teacherUserA)
            ->postJson("/api/teacher/quizzes/{$qzId}/questions", [
                'question_text' => 'What is 2+2?',
                'question_type' => 'short_answer',
                'correct_answer' => '4',
                'points' => 10,
            ])
            ->assertStatus(201);

        $this->actingAs($this->teacherUserA)
            ->patchJson("/api/teacher/quizzes/{$qzId}/publish")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'published');

        // Enter score
        $this->actingAs($this->teacherUserA)
            ->postJson('/api/teacher/course-offerings/' . $this->offeringA->id . '/scores', [
                'student_id' => $this->student->id,
                'total_score' => 88,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.letter_grade', 'A-');

        // Assessments overview & risk predictions
        $this->actingAs($this->teacherUserA)
            ->getJson('/api/teacher/assessments-overview')
            ->assertStatus(200)
            ->assertJsonPath('data.summary.total_assignments', 1);

        $this->actingAs($this->teacherUserA)
            ->getJson('/api/teacher/risk-predictions')
            ->assertStatus(200)
            ->assertJsonPath('data.has_predictions', false);
    }
}
