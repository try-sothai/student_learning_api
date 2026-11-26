<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassRoom;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CourseOfferingTeacher;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherExamsTest extends TestCase
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
    protected $outsideStudentUser;
    protected $outsideStudent;
    protected $offering;
    protected $secondOffering;
    protected $course;
    protected $secondCourse;
    protected $semester;

    protected function setUp(): void
    {
        parent::setUp();

        $year = AcademicYear::create(['name' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30']);
        $this->semester = Semester::create(['name' => 'Fall 2026', 'academic_year_id' => $year->id, 'semester_number' => 1, 'start_date' => '2026-09-01', 'end_date' => '2026-12-15']);
        $this->course = Course::create(['name' => 'Physics', 'code' => 'PHY101', 'status' => 'active']);
        $this->secondCourse = Course::create(['name' => 'Chemistry', 'code' => 'CHM101', 'status' => 'active']);
        $classRoom = ClassRoom::create(['name' => 'Class A', 'code' => 'CLA01', 'academic_year_id' => $year->id]);

        $this->teacherUser = User::create(['name' => 'Teacher One', 'email' => 't1@test.com', 'password' => 'pwd', 'role' => 'teacher']);
        $this->teacher = Teacher::create(['user_id' => $this->teacherUser->id, 'teacher_code' => 'T101', 'first_name' => 'John', 'last_name' => 'Doe']);

        $this->otherTeacherUser = User::create(['name' => 'Teacher Two', 'email' => 't2@test.com', 'password' => 'pwd', 'role' => 'teacher']);
        $this->otherTeacher = Teacher::create(['user_id' => $this->otherTeacherUser->id, 'teacher_code' => 'T102', 'first_name' => 'Jane', 'last_name' => 'Smith']);

        $this->studentUser1 = User::create(['name' => 'Alice', 'email' => 'alice@test.com', 'password' => 'pwd', 'role' => 'student']);
        $this->student1 = Student::create(['user_id' => $this->studentUser1->id, 'student_id' => 'STU001', 'student_code' => 'STU001', 'first_name' => 'Alice', 'last_name' => 'Wonder', 'date_of_birth' => '2000-01-01', 'gender' => 'Female']);

        $this->studentUser2 = User::create(['name' => 'Bob', 'email' => 'bob@test.com', 'password' => 'pwd', 'role' => 'student']);
        $this->student2 = Student::create(['user_id' => $this->studentUser2->id, 'student_id' => 'STU002', 'student_code' => 'STU002', 'first_name' => 'Bob', 'last_name' => 'Builder', 'date_of_birth' => '2000-01-01', 'gender' => 'Male']);

        $this->outsideStudentUser = User::create(['name' => 'Charlie', 'email' => 'charlie@test.com', 'password' => 'pwd', 'role' => 'student']);
        $this->outsideStudent = Student::create(['user_id' => $this->outsideStudentUser->id, 'student_id' => 'STU003', 'student_code' => 'STU003', 'first_name' => 'Charlie', 'last_name' => 'Brown', 'date_of_birth' => '2000-01-01', 'gender' => 'Male']);

        $this->offering = CourseOffering::create([
            'course_id' => $this->course->id,
            'semester_id' => $this->semester->id,
            'class_id' => $classRoom->id,
            'status' => 'active',
        ]);

        $this->secondOffering = CourseOffering::create([
            'course_id' => $this->secondCourse->id,
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
        CourseOfferingTeacher::create([
            'course_offering_id' => $this->secondOffering->id,
            'teacher_id' => $this->teacher->id,
            'role' => 'primary',
            'status' => 'active',
        ]);

        CourseEnrollment::create(['student_id' => $this->student1->id, 'course_id' => $this->course->id, 'semester_id' => $this->semester->id, 'course_offering_id' => $this->offering->id, 'status' => 'enrolled']);
        CourseEnrollment::create(['student_id' => $this->student2->id, 'course_id' => $this->course->id, 'semester_id' => $this->semester->id, 'course_offering_id' => $this->offering->id, 'status' => 'enrolled']);
    }

    public function test_teacher_can_create_and_manage_physical_exam(): void
    {
        // 1. Create exam with start_time + duration_minutes -> auto computes end_time
        $createRes = $this->actingAs($this->teacherUser)->postJson("/api/teacher/course-offerings/{$this->offering->id}/exams", [
            'title' => 'Physics Midterm Exam',
            'exam_type' => 'midterm',
            'exam_date' => '2026-12-25',
            'start_time' => '09:00',
            'total_score' => 100,
            'duration_minutes' => 90,
            'venue' => 'Room 301',
            'description' => 'Closed-book paper exam. Bring pens and scientific calculator.',
            'status' => 'scheduled',
        ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('data.title', 'Physics Midterm Exam')
            ->assertJsonPath('data.start_time', '09:00')
            ->assertJsonPath('data.end_time', '10:30')
            ->assertJsonPath('data.total_score', 100)
            ->assertJsonPath('data.venue', 'Room 301')
            ->assertJsonPath('data.display_status', 'Scheduled');

        $examId = $createRes->json('data.id');

        // 2. Unauthorized teacher cannot view or edit via /teacher/exams or /exams/{id}
        $this->actingAs($this->otherTeacherUser)->getJson("/api/teacher/exams/{$examId}")->assertStatus(403);
        $this->actingAs($this->otherTeacherUser)->putJson("/api/teacher/exams/{$examId}", ['title' => 'Hack'])->assertStatus(403);
        $this->actingAs($this->otherTeacherUser)->putJson("/api/exams/{$examId}", [
            'title' => 'Hack',
            'exam_type' => 'midterm',
            'exam_date' => '2026-12-25',
            'total_score' => 100,
        ])->assertStatus(403);

        // 3. Authorized teacher views exam details with enrolled students
        $showRes = $this->actingAs($this->teacherUser)->getJson("/api/teacher/exams/{$examId}");
        $showRes->assertOk()
            ->assertJsonPath('data.exam.title', 'Physics Midterm Exam')
            ->assertJsonPath('data.exam.start_time', '09:00')
            ->assertJsonPath('data.exam.end_time', '10:30')
            ->assertJsonPath('data.statistics.total_students', 2)
            ->assertJsonPath('data.statistics.graded_students', 0)
            ->assertJsonPath('data.statistics.pending_students', 2)
            ->assertJsonCount(2, 'data.students');

        // 4. Student sees scheduled exam, but NO results (since unpublished)
        $studExamRes = $this->actingAs($this->studentUser1)->getJson('/api/student/exams');
        $studExamRes->assertOk()
            ->assertJsonPath('data.0.title', 'Physics Midterm Exam')
            ->assertJsonPath('data.0.start_time', '09:00')
            ->assertJsonPath('data.0.end_time', '10:30')
            ->assertJsonCount(0, 'data.0.results');

        // 5. Score validation: negative, exceeding total marks, or non-enrolled student -> 422
        $this->actingAs($this->teacherUser)->postJson("/api/teacher/exams/{$examId}/scores", [
            'student_id' => $this->student1->id,
            'score' => -5,
        ])->assertStatus(422);

        $this->actingAs($this->teacherUser)->postJson("/api/teacher/exams/{$examId}/scores", [
            'student_id' => $this->student1->id,
            'score' => 150,
        ])->assertStatus(422);

        $this->actingAs($this->teacherUser)->postJson("/api/teacher/exams/{$examId}/scores", [
            'student_id' => $this->outsideStudent->id,
            'score' => 80,
        ])->assertStatus(422);

        // 6. Teacher enters valid scores as unpublished (batch)
        $scoreRes = $this->actingAs($this->teacherUser)->postJson("/api/teacher/exams/{$examId}/scores", [
            'scores' => [
                ['student_id' => $this->student1->id, 'score' => 88.5, 'remarks' => 'Great work!'],
                ['student_id' => $this->student2->id, 'score' => 74.0, 'remarks' => 'Good effort'],
            ],
        ]);
        $scoreRes->assertOk();

        // 7. Verify results in DB, enriched stats, and Results Pending display_status
        $this->assertDatabaseHas('exam_results', [
            'exam_id' => $examId,
            'student_id' => $this->student1->id,
            'score' => 88.5,
            'grade' => 'A-',
        ]);
        $this->assertEquals(2, ExamResult::where('exam_id', $examId)->count());

        $showGradedRes = $this->actingAs($this->teacherUser)->getJson("/api/teacher/exams/{$examId}");
        $showGradedRes->assertOk()
            ->assertJsonPath('data.exam.display_status', 'Results Pending')
            ->assertJsonPath('data.statistics.graded_students', 2)
            ->assertJsonPath('data.statistics.pending_students', 0)
            ->assertJsonPath('data.students.0.result_status', 'Pass')
            ->assertJsonPath('data.students.0.grading_status', 'Graded')
            ->assertJsonPath('data.students.0.publication_status', 'Unpublished');
        $this->assertEquals(81.25, (float) $showGradedRes->json('data.statistics.average_score'));
        $this->assertEquals(88.5, (float) $showGradedRes->json('data.statistics.highest_score'));
        $this->assertEquals(74.0, (float) $showGradedRes->json('data.statistics.lowest_score'));

        // 8. Results not published yet -> Student still cannot see score
        $studExamRes2 = $this->actingAs($this->studentUser1)->getJson('/api/student/exams');
        $studExamRes2->assertOk()
            ->assertJsonPath('data.0.display_status', 'Results Pending')
            ->assertJsonCount(0, 'data.0.results');

        // 9. Teacher publishes results
        $pubRes = $this->actingAs($this->teacherUser)->patchJson("/api/teacher/exams/{$examId}/publish");
        $pubRes->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.display_status', 'Results Published');

        // 10. Student 1 now sees only their OWN result with percentage and Pass/Fail
        $studExamRes3 = $this->actingAs($this->studentUser1)->getJson('/api/student/exams');
        $studExamRes3->assertOk()
            ->assertJsonPath('data.0.display_status', 'Results Published')
            ->assertJsonCount(1, 'data.0.results')
            ->assertJsonPath('data.0.results.0.student_id', $this->student1->id)
            ->assertJsonPath('data.0.results.0.result_status', 'Pass');
        $this->assertEquals(88.5, (float) $studExamRes3->json('data.0.results.0.score'));
        $this->assertEquals(88.5, (float) $studExamRes3->json('data.0.results.0.percentage'));

        // 11. Student 2 sees only Student 2's result
        $stud2Res = $this->actingAs($this->studentUser2)->getJson('/api/student/exams');
        $stud2Res->assertOk()
            ->assertJsonCount(1, 'data.0.results')
            ->assertJsonPath('data.0.results.0.student_id', $this->student2->id);
        $this->assertEquals(74.0, (float) $stud2Res->json('data.0.results.0.score'));

        // 12. Student cannot access another student's result via /api/exam-results
        $this->actingAs($this->studentUser1)->getJson("/api/exam-results?student_id={$this->student2->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_id', $this->student1->id);

        // 13. Teacher edits a score -> no duplicate record created
        $editScoreRes = $this->actingAs($this->teacherUser)->postJson("/api/teacher/exams/{$examId}/scores", [
            'student_id' => $this->student1->id,
            'score' => 95.0,
            'remarks' => 'Revised after re-check',
        ]);
        $editScoreRes->assertOk();

        $this->assertEquals(2, ExamResult::where('exam_id', $examId)->count());
        $this->assertDatabaseHas('exam_results', [
            'exam_id' => $examId,
            'student_id' => $this->student1->id,
            'score' => 95.0,
            'grade' => 'A',
        ]);

        // 14. Teacher unpublishes results
        $unpubRes = $this->actingAs($this->teacherUser)->patchJson("/api/teacher/exams/{$examId}/unpublish");
        $unpubRes->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.display_status', 'Results Pending');

        // 15. Student cannot see scores after unpublishing
        $studExamRes4 = $this->actingAs($this->studentUser1)->getJson('/api/student/exams');
        $studExamRes4->assertOk()
            ->assertJsonCount(0, 'data.0.results');

        // 16. Delete requires confirmation (409) when results exist, then force=true deletes cleanly
        $this->actingAs($this->teacherUser)->deleteJson("/api/teacher/exams/{$examId}")
            ->assertStatus(409)
            ->assertJsonPath('has_results', true);

        $delRes = $this->actingAs($this->teacherUser)->deleteJson("/api/teacher/exams/{$examId}?force=true");
        $delRes->assertOk();

        $this->assertDatabaseMissing('exams', ['id' => $examId]);
        $this->assertDatabaseMissing('exam_results', ['exam_id' => $examId]);
    }

    public function test_teacher_edit_and_schedule_validation_rules(): void
    {
        // Reject invalid duration or end_time <= start_time on store
        $this->actingAs($this->teacherUser)->postJson("/api/teacher/course-offerings/{$this->offering->id}/exams", [
            'title' => 'Bad Schedule Exam',
            'exam_type' => 'final',
            'exam_date' => '2026-12-20',
            'start_time' => '14:00',
            'end_time' => '13:00',
            'total_score' => 100,
        ])->assertStatus(422);

        $this->actingAs($this->teacherUser)->postJson("/api/teacher/course-offerings/{$this->offering->id}/exams", [
            'title' => 'Bad Duration Exam',
            'exam_type' => 'final',
            'exam_date' => '2026-12-20',
            'total_score' => 100,
            'duration_minutes' => 900,
        ])->assertStatus(422);

        // Create valid exam
        $createRes = $this->actingAs($this->teacherUser)->postJson("/api/teacher/course-offerings/{$this->offering->id}/exams", [
            'title' => 'Initial Title',
            'exam_type' => 'midterm',
            'exam_date' => '2026-12-18',
            'start_time' => '08:30',
            'duration_minutes' => 60,
            'total_score' => 100,
        ])->assertStatus(201);

        $examId = $createRes->json('data.id');
        $this->assertEquals('09:30', $createRes->json('data.end_time'));

        // Edit offering, schedule, duration, total_score, type while no results exist
        $updateRes = $this->actingAs($this->teacherUser)->putJson("/api/teacher/exams/{$examId}", [
            'course_offering_id' => $this->secondOffering->id,
            'title' => 'Chemistry Final Exam',
            'exam_type' => 'final',
            'exam_date' => '2026-12-22',
            'start_time' => '13:30',
            'duration_minutes' => 120,
            'total_score' => 50,
            'venue' => 'Hall B',
            'description' => 'Updated paper instructions',
            'status' => 'scheduled',
        ]);

        $updateRes->assertOk()
            ->assertJsonPath('data.course_offering_id', $this->secondOffering->id)
            ->assertJsonPath('data.course_id', $this->secondCourse->id)
            ->assertJsonPath('data.title', 'Chemistry Final Exam')
            ->assertJsonPath('data.exam_type', 'final')
            ->assertJsonPath('data.start_time', '13:30')
            ->assertJsonPath('data.end_time', '15:30')
            ->assertJsonPath('data.total_score', 50)
            ->assertJsonPath('data.venue', 'Hall B');

        // Re-assign back to first offering before adding score for offering lock test
        $this->actingAs($this->teacherUser)->putJson("/api/teacher/exams/{$examId}", [
            'course_offering_id' => $this->offering->id,
            'total_score' => 100,
        ])->assertOk();

        // Add student score -> offering lock & total_score floor validation apply
        $this->actingAs($this->teacherUser)->postJson("/api/teacher/exams/{$examId}/scores", [
            'student_id' => $this->student1->id,
            'score' => 80,
        ])->assertOk();

        // Cannot change course_offering_id once scores exist
        $this->actingAs($this->teacherUser)->putJson("/api/teacher/exams/{$examId}", [
            'course_offering_id' => $this->secondOffering->id,
        ])->assertStatus(422);

        // Cannot lower total_score below existing student score (80)
        $this->actingAs($this->teacherUser)->putJson("/api/teacher/exams/{$examId}", [
            'total_score' => 70,
        ])->assertStatus(422);
    }
}
