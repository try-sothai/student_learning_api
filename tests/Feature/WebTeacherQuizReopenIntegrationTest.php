<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AssessmentAuditLog;
use App\Models\AssessmentStudentOverride;
use App\Models\ClassRoom;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CourseOfferingTeacher;
use App\Models\Department;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizOverrideLog;
use App\Models\QuizQuestion;
use App\Models\QuizStudentOverride;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WebTeacherQuizReopenIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;
    private AcademicYear $academicYear;
    private Semester $semester;
    private ClassRoom $classRoom;
    private User $teacherUser;
    private Teacher $teacher;
    private User $otherTeacherUser;
    private Teacher $otherTeacher;
    private User $studentUserA;
    private Student $studentA;
    private User $studentUserB;
    private Student $studentB;
    private Course $course;
    private CourseOffering $offering;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Department::create([
            'name' => 'Software Engineering',
            'code' => 'SE',
            'status' => 'active',
        ]);

        $this->academicYear = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
            'status' => 'active',
        ]);

        $this->semester = Semester::create([
            'academic_year_id' => $this->academicYear->id,
            'name' => 'Semester 1',
            'semester_number' => 1,
            'start_date' => '2026-09-01',
            'end_date' => '2027-01-31',
            'status' => 'active',
        ]);

        $this->classRoom = ClassRoom::create([
            'department_id' => $this->department->id,
            'academic_year_id' => $this->academicYear->id,
            'name' => 'SE Year 3',
            'code' => 'SE-3A',
            'year_level' => 3,
            'status' => 'active',
        ]);

        $this->teacherUser = User::create([
            'name' => 'Prof. Quiz Owner',
            'email' => 'quiz.owner@slas.test',
            'password' => Hash::make('TCH@12345'),
            'role' => 'teacher',
            'status' => 'active',
        ]);
        $this->teacher = Teacher::create([
            'user_id' => $this->teacherUser->id,
            'teacher_code' => 'TCH-WQ1',
            'first_name' => 'Quiz',
            'last_name' => 'Owner',
            'status' => 'active',
            'department_id' => $this->department->id,
        ]);

        $this->otherTeacherUser = User::create([
            'name' => 'Prof. Intruder',
            'email' => 'quiz.intruder@slas.test',
            'password' => Hash::make('TCH@12345'),
            'role' => 'teacher',
            'status' => 'active',
        ]);
        $this->otherTeacher = Teacher::create([
            'user_id' => $this->otherTeacherUser->id,
            'teacher_code' => 'TCH-WQ2',
            'first_name' => 'Other',
            'last_name' => 'Teacher',
            'status' => 'active',
            'department_id' => $this->department->id,
        ]);

        $this->course = Course::create([
            'course_code' => 'SE301',
            'course_name' => 'Software Testing',
            'credit' => 3,
            'department_id' => $this->department->id,
            'teacher_id' => $this->teacher->id,
        ]);

        $this->offering = CourseOffering::create([
            'course_id' => $this->course->id,
            'class_id' => $this->classRoom->id,
            'semester_id' => $this->semester->id,
            'academic_year_id' => $this->academicYear->id,
            'study_year' => 3,
            'section_name' => 'Sec 1',
            'status' => 'active',
        ]);

        CourseOfferingTeacher::create([
            'course_offering_id' => $this->offering->id,
            'teacher_id' => $this->teacher->id,
            'role' => 'primary',
            'status' => 'active',
            'assigned_at' => now(),
        ]);

        $this->studentUserA = User::create([
            'name' => 'Dara Sok',
            'email' => 'dara.webquiz@slas.test',
            'password' => Hash::make('STD@12345'),
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->studentA = Student::create([
            'user_id' => $this->studentUserA->id,
            'student_code' => 'STU-WQ1',
            'english_name' => 'Dara Sok',
            'first_name' => 'Dara',
            'last_name' => 'Sok',
            'gender' => 'male',
            'department_id' => $this->department->id,
            'class_id' => $this->classRoom->id,
            'status' => 'enrolled',
        ]);

        $this->studentUserB = User::create([
            'name' => 'Nary Chann',
            'email' => 'nary.webquiz@slas.test',
            'password' => Hash::make('STD@12345'),
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->studentB = Student::create([
            'user_id' => $this->studentUserB->id,
            'student_code' => 'STU-WQ2',
            'english_name' => 'Nary Chann',
            'first_name' => 'Nary',
            'last_name' => 'Chann',
            'gender' => 'female',
            'department_id' => $this->department->id,
            'class_id' => $this->classRoom->id,
            'status' => 'enrolled',
        ]);

        foreach ([$this->studentA, $this->studentB] as $student) {
            CourseEnrollment::create([
                'student_id' => $student->id,
                'course_id' => $this->course->id,
                'semester_id' => $this->semester->id,
                'course_offering_id' => $this->offering->id,
                'status' => 'enrolled',
            ]);
        }
    }

    /**
     * @return array{0: Quiz, 1: QuizQuestion, 2: QuizQuestion}
     */
    private function createPublishedQuizWithQuestions(array $overrides = []): array
    {
        $quiz = Quiz::create(array_merge([
            'course_id' => $this->course->id,
            'course_offering_id' => $this->offering->id,
            'teacher_id' => $this->teacher->id,
            'semester_id' => $this->semester->id,
            'title' => 'Web Reopen Quiz 1',
            'description' => 'Cross-platform reopen verification quiz',
            'duration_minutes' => 20,
            'start_at' => now()->subDays(3),
            'end_at' => now()->subHours(2), // Already expired
            'total_score' => 10,
            'status' => 'published',
            'max_attempts' => 1,
            'scoring_method' => 'highest',
            'allow_late_submission' => false,
            'version' => 1,
        ], $overrides));

        $q1 = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question_order' => 1,
            'question_text' => 'What is 2 + 2?',
            'question_type' => 'multiple_choice',
            'marks' => 6,
            'is_required' => true,
            'option_a' => '4',
            'option_b' => '5',
            'correct_answer' => 'A',
        ]);

        $q2 = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question_order' => 2,
            'question_text' => 'Unit tests verify individual components.',
            'question_type' => 'true_false',
            'marks' => 4,
            'is_required' => true,
            'option_a' => 'True',
            'option_b' => 'False',
            'correct_answer' => 'true',
        ]);

        return [$quiz, $q1, $q2];
    }

    public function test_web_teacher_quiz_reopen_retake_history_revoke_and_android_sync_end_to_end(): void
    {
        [$quiz, $q1, $q2] = $this->createPublishedQuizWithQuestions();
        $originalEndAt = $quiz->end_at->copy();

        // Seed Student A's completed Attempt #1 (scored 4/10) before deadline expired
        $att1 = QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_id' => $this->studentA->id,
            'attempt_number' => 1,
            'started_at' => now()->subDays(2),
            'submitted_at' => now()->subDays(2)->addMinutes(5),
            'status' => 'completed',
            'grading_status' => 'auto_graded',
            'score' => 4.0,
            'auto_graded_score' => 4.0,
            'manual_graded_score' => 0.0,
            'total_marks_snapshot' => 10.0,
            'quiz_version' => 1,
            'questions_snapshot' => [
                $q1->makeVisible(['correct_answer', 'grading_guidance'])->toSnapshot(),
                $q2->makeVisible(['correct_answer', 'grading_guidance'])->toSnapshot(),
            ],
            'is_late' => false,
            'is_reset' => false,
        ]);
        QuizAnswer::create([
            'quiz_attempt_id' => $att1->id,
            'question_id' => $q1->id,
            'answer' => 'B',
            'is_correct' => false,
            'marks_obtained' => 0.0,
            'grading_status' => 'auto_graded',
        ]);
        QuizAnswer::create([
            'quiz_attempt_id' => $att1->id,
            'question_id' => $q2->id,
            'answer' => 'true',
            'is_correct' => true,
            'marks_obtained' => 4.0,
            'grading_status' => 'auto_graded',
        ]);

        // 0. Teacher views /teacher/quizzes and /teacher/quizzes/{quiz}/manage (sees modern Quiz + both enrolled students)
        $this->actingAs($this->teacherUser)
            ->get(route('teacher.quizzes'))
            ->assertOk()
            ->assertSee('Web Reopen Quiz 1')
            ->assertSee('Manage Access')
            ->assertSee('Create Quiz')
            ->assertDontSee('Create Assignment');

        $this->actingAs($this->teacherUser)
            ->get(route('teacher.quizzes.manage', $quiz->id))
            ->assertOk()
            ->assertSee('Dara Sok')
            ->assertSee('Nary Chann')
            ->assertSee('STU-WQ1')
            ->assertSee('Expired'); // Nary Chann has 0 submissions & expired quiz

        // Confirm Android API before reopen shows Student A blocked (0 attempts left, expired)
        $this->actingAs($this->studentUserA, 'sanctum')
            ->getJson("/api/student/quizzes/{$quiz->id}")
            ->assertOk()
            ->assertJsonPath('data.can_start', false)
            ->assertJsonPath('data.remaining_attempts', 0)
            ->assertJsonPath('data.reopen_status', 'Submitted');

        // 1. Teacher reopens Quiz via Web for Student A with late submission + +1 attempt + extended deadline
        $extendedAt = now()->addDays(2)->startOfHour();
        $this->actingAs($this->teacherUser)
            ->post(route('teacher.quizzes.reopen', $quiz->id), [
                'student_ids' => [$this->studentA->id],
                'allow_submission' => '1',
                'allow_resubmission' => '1',
                'allow_late_submission' => '1',
                'additional_attempts' => 1,
                'new_deadline' => $extendedAt->format('Y-m-d\TH:i'),
                'reason' => 'Medical absence approved via Web',
                'teacher_notes' => 'Allow one late retake',
            ])
            ->assertRedirect(route('teacher.quizzes.manage', ['quiz' => $quiz->id, 'tab' => 'students']));

        // Verify original quiz end_at is untouched and override rows synced cleanly
        $this->assertSame($originalEndAt->toIso8601String(), $quiz->fresh()->end_at->toIso8601String());
        $this->assertDatabaseHas('assessment_student_overrides', [
            'assessment_type' => 'quiz',
            'assessment_id' => $quiz->id,
            'student_id' => $this->studentA->id,
            'allow_submission' => 1,
            'allow_resubmission' => 1,
            'allow_late_submission' => 1,
            'additional_attempts' => 1,
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('quiz_student_overrides', [
            'quiz_id' => $quiz->id,
            'student_id' => $this->studentA->id,
            'extra_attempts' => 1,
            'allow_late_submission' => 1,
            'is_active' => 1,
        ]);

        // 2. Student A sees updated permission in Android API while Student B remains completely unaffected
        $stuARes = $this->actingAs($this->studentUserA, 'sanctum')
            ->getJson("/api/student/quizzes/{$quiz->id}")
            ->assertOk()
            ->assertJsonPath('data.can_start', true)
            ->assertJsonPath('data.allow_late_submission', true)
            ->assertJsonPath('data.reopen_status', 'Reopened')
            ->assertJsonPath('data.original_attempt_limit', 1)
            ->assertJsonPath('data.additional_attempts', 1)
            ->assertJsonPath('data.remaining_attempts', 1);
        $this->assertSame($originalEndAt->toIso8601String(), $stuARes->json('data.original_deadline'));
        $this->assertSame($extendedAt->format('Y-m-d\TH:i:00P'), \Carbon\Carbon::parse($stuARes->json('data.extended_deadline'))->format('Y-m-d\TH:i:00P'));

        $this->actingAs($this->studentUserB, 'sanctum')
            ->getJson("/api/student/assessments/quiz/{$quiz->id}/reopen-status")
            ->assertOk()
            ->assertJsonPath('data.can_start', false)
            ->assertJsonPath('data.reopen_status', 'Expired')
            ->assertJsonPath('data.additional_attempts', 0);

        // 3. Student A starts & submits Attempt #2 via Android API (scores 10/10, within extended deadline -> is_late = 0)
        $start2 = $this->actingAs($this->studentUserA, 'sanctum')
            ->postJson("/api/student/quizzes/{$quiz->id}/start")
            ->assertOk();
        $att2Id = (int) $start2->json('data.attempt_id');
        $this->assertNotSame($att1->id, $att2Id);
        $this->assertSame(2, (int) $start2->json('data.attempt_number'));

        $sub2 = $this->actingAs($this->studentUserA, 'sanctum')
            ->postJson("/api/student/quiz-attempts/{$att2Id}/submit", [
                'answers' => [
                    ['question_id' => $q1->id, 'answer' => 'A'],
                    ['question_id' => $q2->id, 'answer' => 'true'],
                ],
            ])
            ->assertOk();
        $this->assertEquals(10.0, (float) $sub2->json('data.score'));

        // Also allow late-only submission for Student B (expired quiz, no new deadline) -> Attempt #1 marked is_late = 1
        $this->actingAs($this->teacherUser)
            ->post(route('teacher.quizzes.reopen', $quiz->id), [
                'student_ids' => [$this->studentB->id],
                'allow_submission' => '1',
                'allow_resubmission' => '0',
                'allow_late_submission' => '1',
                'reason' => 'Allow late submission without extending deadline',
            ])
            ->assertRedirect();

        $startB = $this->actingAs($this->studentUserB, 'sanctum')
            ->postJson("/api/student/quizzes/{$quiz->id}/start")
            ->assertOk()
            ->assertJsonPath('data.is_late', true);
        $attBId = (int) $startB->json('data.attempt_id');
        $this->actingAs($this->studentUserB, 'sanctum')
            ->postJson("/api/student/quiz-attempts/{$attBId}/submit", [
                'answers' => [
                    ['question_id' => $q1->id, 'answer' => 'A'],
                    ['question_id' => $q2->id, 'answer' => 'false'],
                ],
            ])
            ->assertOk();
        $this->assertDatabaseHas('quiz_attempts', [
            'id' => $attBId,
            'attempt_number' => 1,
            'score' => 6.0,
            'is_late' => 1,
        ]);

        // 4. Verify Attempt #1, its answers, and its score 4.0 remain completely intact
        $this->assertDatabaseHas('quiz_attempts', [
            'id' => $att1->id,
            'attempt_number' => 1,
            'score' => 4.0,
            'is_reset' => 0,
        ]);
        $this->assertDatabaseHas('quiz_answers', [
            'quiz_attempt_id' => $att1->id,
            'question_id' => $q1->id,
            'answer' => 'B',
            'is_correct' => 0,
        ]);
        $this->assertDatabaseHas('quiz_attempts', [
            'id' => $att2Id,
            'attempt_number' => 2,
            'score' => 10.0,
            'is_late' => 0,
        ]);

        // 5. Teacher views Attempt History via Web (for Student A and full quiz) and sees preserved attempts + Late badge
        $this->actingAs($this->teacherUser)
            ->get(route('teacher.quizzes.manage', [
                'quiz' => $quiz->id,
                'tab' => 'attempts',
                'student_id' => $this->studentA->id,
            ]))
            ->assertOk()
            ->assertSee('#1')
            ->assertSee('#2')
            ->assertSee('4.0 / 10.0')
            ->assertSee('10.0 / 10.0')
            ->assertSee('What is 2 + 2?');

        $this->actingAs($this->teacherUser)
            ->get(route('teacher.quizzes.manage', [
                'quiz' => $quiz->id,
                'tab' => 'attempts',
            ]))
            ->assertOk()
            ->assertSee('Nary Chann')
            ->assertSee('Late');

        // Student-specific attempt limit override & deadline extension via Web
        $newExt = now()->addDays(4)->startOfHour();
        $this->actingAs($this->teacherUser)
            ->post(route('teacher.quizzes.extend-deadline', $quiz->id), [
                'student_ids' => [$this->studentA->id],
                'new_deadline' => $newExt->format('Y-m-d\TH:i'),
                'reason' => 'Network outage extension',
            ])
            ->assertRedirect();

        $this->actingAs($this->teacherUser)
            ->post(route('teacher.quizzes.reopen', $quiz->id), [
                'student_ids' => [$this->studentA->id],
                'allow_submission' => '1',
                'allow_resubmission' => '1',
                'allow_late_submission' => '1',
                'target_attempt_limit' => 4, // base 1 -> +3 additional attempts
                'reason' => 'Student-specific cap override to 4 attempts',
            ])
            ->assertRedirect();

        $this->actingAs($this->studentUserA, 'sanctum')
            ->getJson("/api/student/assessments/quiz/{$quiz->id}/reopen-status")
            ->assertOk()
            ->assertJsonPath('data.original_attempt_limit', 1)
            ->assertJsonPath('data.additional_attempts', 3)
            ->assertJsonPath('data.effective_attempt_limit', 4)
            ->assertJsonPath('data.used_attempts', 2)
            ->assertJsonPath('data.remaining_attempts', 2);

        // Grant +1 additional attempt via Web -> effective limit becomes 5 (remaining = 3)
        $this->actingAs($this->teacherUser)
            ->post(route('teacher.quizzes.grant-attempts', $quiz->id), [
                'student_ids' => [$this->studentA->id],
                'additional_attempts' => 1,
                'reason' => 'Extra buffer attempt',
            ])
            ->assertRedirect();

        $this->actingAs($this->studentUserA, 'sanctum')
            ->getJson("/api/student/assessments/quiz/{$quiz->id}/reopen-status")
            ->assertOk()
            ->assertJsonPath('data.effective_attempt_limit', 5)
            ->assertJsonPath('data.remaining_attempts', 3);

        // 6. Teacher revokes permission via Web
        $this->actingAs($this->teacherUser)
            ->post(route('teacher.quizzes.revoke', $quiz->id), [
                'student_ids' => [$this->studentA->id],
                'reason' => 'Window ended after audit check',
            ])
            ->assertRedirect(route('teacher.quizzes.manage', ['quiz' => $quiz->id, 'tab' => 'students']));

        // 7. Android immediately respects revoked permission (quiz expired -> can_start=false, start blocked)
        $this->actingAs($this->studentUserA, 'sanctum')
            ->getJson("/api/student/quizzes/{$quiz->id}")
            ->assertOk()
            ->assertJsonPath('data.can_start', false)
            ->assertJsonPath('data.has_active_override', false)
            ->assertJsonPath('data.remaining_attempts', 0);

        $this->actingAs($this->studentUserA, 'sanctum')
            ->postJson("/api/student/quizzes/{$quiz->id}/start")
            ->assertStatus(422);

        // Historical attempts stay intact after revoke
        $this->assertSame(2, QuizAttempt::where('quiz_id', $quiz->id)->where('student_id', $this->studentA->id)->count());

        // Also test explicit submission block (allow_submission = 0) on an OPEN quiz blocks with 403
        $openQuiz = Quiz::create([
            'course_id' => $this->course->id,
            'course_offering_id' => $this->offering->id,
            'teacher_id' => $this->teacher->id,
            'semester_id' => $this->semester->id,
            'title' => 'Open Restricted Quiz',
            'duration_minutes' => 15,
            'start_at' => now()->subHour(),
            'end_at' => now()->addDays(2),
            'total_score' => 5,
            'status' => 'published',
            'max_attempts' => 2,
            'version' => 1,
        ]);
        QuizQuestion::create([
            'quiz_id' => $openQuiz->id,
            'question_order' => 1,
            'question_text' => 'Sample Q',
            'question_type' => 'true_false',
            'marks' => 5,
            'is_required' => true,
            'option_a' => 'True',
            'option_b' => 'False',
            'correct_answer' => 'true',
        ]);

        $this->actingAs($this->teacherUser)
            ->post(route('teacher.quizzes.reopen', $openQuiz->id), [
                'student_ids' => [$this->studentB->id],
                'allow_submission' => '0',
                'allow_resubmission' => '0',
                'allow_late_submission' => '0',
                'reason' => 'Academic hold',
            ])
            ->assertRedirect();

        $this->actingAs($this->studentUserB, 'sanctum')
            ->postJson("/api/student/quizzes/{$openQuiz->id}/start")
            ->assertStatus(403);

        // 8. Audit Logs tab & unified API reflect all actual operations
        $this->actingAs($this->teacherUser)
            ->get(route('teacher.quizzes.manage', ['quiz' => $quiz->id, 'tab' => 'audit-logs']))
            ->assertOk()
            ->assertSee('REOPEN')
            ->assertSee('EXTEND_DEADLINE')
            ->assertSee('GRANT_ADDITIONAL_ATTEMPTS')
            ->assertSee('REVOKE_REOPEN')
            ->assertSee('Window ended after audit check');

        $this->assertGreaterThanOrEqual(5, AssessmentAuditLog::where('assessment_type', 'quiz')->where('assessment_id', $quiz->id)->count());
        $this->assertGreaterThanOrEqual(4, QuizOverrideLog::where('quiz_id', $quiz->id)->count());

        $this->actingAs($this->teacherUser, 'sanctum')
            ->getJson("/api/teacher/assessments/audit-logs?assessment_type=quiz&assessment_id={$quiz->id}")
            ->assertOk()
            ->assertJsonFragment(['action_type' => 'revoke_reopen']);
    }

    public function test_web_quiz_reopen_rejects_unauthorized_users_and_invalid_attempt_limits(): void
    {
        [$quiz] = $this->createPublishedQuizWithQuestions(['max_attempts' => 3]);

        // Another teacher cannot manage or mutate this quiz (403)
        $this->actingAs($this->otherTeacherUser)
            ->get(route('teacher.quizzes.manage', $quiz->id))
            ->assertStatus(403);

        $this->actingAs($this->otherTeacherUser)
            ->post(route('teacher.quizzes.reopen', $quiz->id), [
                'student_ids' => [$this->studentA->id],
                'additional_attempts' => 1,
            ])
            ->assertStatus(403);

        $this->actingAs($this->otherTeacherUser)
            ->post(route('teacher.quizzes.revoke', $quiz->id), [
                'student_ids' => [$this->studentA->id],
            ])
            ->assertStatus(403);

        // Student role blocked from Web teacher routes
        $this->actingAs($this->studentUserA)
            ->get(route('teacher.quizzes.manage', $quiz->id))
            ->assertStatus(403);

        // Target attempt limit below base limit (3) returns validation error and writes no override
        $this->actingAs($this->teacherUser)
            ->from(route('teacher.quizzes.manage', $quiz->id))
            ->post(route('teacher.quizzes.reopen', $quiz->id), [
                'student_ids' => [$this->studentA->id],
                'target_attempt_limit' => 2,
            ])
            ->assertRedirect(route('teacher.quizzes.manage', $quiz->id))
            ->assertSessionHasErrors('target_attempt_limit');

        $this->assertSame(0, AssessmentStudentOverride::where('assessment_type', 'quiz')->where('assessment_id', $quiz->id)->count());
        $this->assertSame(0, QuizStudentOverride::where('quiz_id', $quiz->id)->count());
    }
}
