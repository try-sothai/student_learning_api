<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
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
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\StudentAnalyticsCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuizSystemFullAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $teacherUser;
    private Teacher $teacher;
    private User $otherTeacherUser;
    private Teacher $otherTeacher;
    private User $studentUserA;
    private Student $studentA;
    private User $studentUserB;
    private Student $studentB;
    private CourseOffering $offering;
    private Course $course;
    private Semester $semester;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create(['name' => 'IT Faculty', 'code' => 'IT', 'status' => 'active']);
        $ay = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
            'status' => 'active',
        ]);
        $this->semester = Semester::create([
            'academic_year_id' => $ay->id,
            'name' => 'Semester 1',
            'semester_number' => 1,
            'start_date' => '2026-09-01',
            'end_date' => '2027-01-31',
            'status' => 'active',
        ]);
        $classRoom = ClassRoom::create([
            'department_id' => $dept->id,
            'academic_year_id' => $ay->id,
            'name' => 'IT Year 2',
            'code' => 'IT-2A',
            'year_level' => 2,
            'status' => 'active',
        ]);

        $this->teacherUser = User::create([
            'name' => 'Teacher One',
            'email' => 'teacher.quiz@slas.test',
            'password' => bcrypt('password'),
            'role' => 'teacher',
            'status' => 'active',
        ]);
        $this->teacher = Teacher::create([
            'user_id' => $this->teacherUser->id,
            'teacher_code' => 'TCH-Q1',
            'first_name' => 'Teacher',
            'last_name' => 'One',
            'status' => 'active',
            'department_id' => $dept->id,
        ]);

        $this->otherTeacherUser = User::create([
            'name' => 'Teacher Two',
            'email' => 'teacher.other@slas.test',
            'password' => bcrypt('password'),
            'role' => 'teacher',
            'status' => 'active',
        ]);
        $this->otherTeacher = Teacher::create([
            'user_id' => $this->otherTeacherUser->id,
            'teacher_code' => 'TCH-Q2',
            'first_name' => 'Teacher',
            'last_name' => 'Two',
            'status' => 'active',
            'department_id' => $dept->id,
        ]);

        $this->course = Course::create([
            'course_code' => 'CS301',
            'course_name' => 'Software Engineering',
            'credit' => 3,
            'department_id' => $dept->id,
            'teacher_id' => $this->teacher->id,
        ]);

        $this->offering = CourseOffering::create([
            'course_id' => $this->course->id,
            'class_id' => $classRoom->id,
            'semester_id' => $this->semester->id,
            'academic_year_id' => $ay->id,
            'study_year' => 2,
            'section_name' => 'IT Year 2',
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
            'name' => 'Student Alpha',
            'email' => 'alpha@slas.test',
            'password' => bcrypt('password'),
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->studentA = Student::create([
            'user_id' => $this->studentUserA->id,
            'student_code' => 'STU-A1',
            'first_name' => 'Student',
            'last_name' => 'Alpha',
            'status' => 'enrolled',
            'department_id' => $dept->id,
            'class_id' => $classRoom->id,
            'gender' => 'Female',
            'date_of_birth' => '2005-03-15',
        ]);

        $this->studentUserB = User::create([
            'name' => 'Student Beta',
            'email' => 'beta@slas.test',
            'password' => bcrypt('password'),
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->studentB = Student::create([
            'user_id' => $this->studentUserB->id,
            'student_code' => 'STU-B2',
            'first_name' => 'Student',
            'last_name' => 'Beta',
            'status' => 'enrolled',
            'department_id' => $dept->id,
            'class_id' => $classRoom->id,
            'gender' => 'Male',
            'date_of_birth' => '2005-07-20',
        ]);

        foreach ([$this->studentA, $this->studentB] as $st) {
            CourseEnrollment::create([
                'student_id' => $st->id,
                'course_id' => $this->course->id,
                'semester_id' => $this->semester->id,
                'course_offering_id' => $this->offering->id,
                'status' => 'enrolled',
            ]);
        }
    }

    private function createQuizWithQuestions(array $quizOverrides = [], array $questionsSpec = []): Quiz
    {
        $quiz = Quiz::create(array_merge([
            'course_offering_id' => $this->offering->id,
            'course_id' => $this->course->id,
            'semester_id' => $this->semester->id,
            'teacher_id' => $this->teacher->id,
            'title' => 'Audit Quiz',
            'description' => 'Audit instructions',
            'duration_minutes' => 30,
            'start_at' => now()->subHour(),
            'end_at' => now()->addDays(2),
            'total_score' => 10,
            'status' => 'published',
            'max_attempts' => 1,
            'scoring_method' => 'highest',
            'allow_late_submission' => false,
            'version' => 1,
        ], $quizOverrides));

        $order = 1;
        $total = 0.0;
        foreach ($questionsSpec as $spec) {
            $marks = (float) ($spec['marks'] ?? 5);
            $total += $marks;
            QuizQuestion::create(array_merge([
                'quiz_id' => $quiz->id,
                'question_order' => $order++,
                'question_text' => 'Question ' . $order,
                'question_type' => 'multiple_choice',
                'marks' => $marks,
                'is_required' => true,
                'option_a' => 'Opt A',
                'option_b' => 'Opt B',
                'correct_answer' => 'A',
            ], $spec));
        }

        if ($total > 0) {
            $quiz->update(['total_score' => $total]);
        }

        return $quiz->fresh();
    }

    public function test_short_answer_stays_pending_grading_and_hides_final_score_until_manual_grading(): void
    {
        $quiz = $this->createQuizWithQuestions([], [
            [
                'question_text' => 'What is 2 + 2?',
                'question_type' => 'multiple_choice',
                'marks' => 5,
                'option_a' => '4',
                'option_b' => '5',
                'correct_answer' => 'A',
            ],
            [
                'question_text' => 'Explain MVC architecture.',
                'question_type' => 'short_answer',
                'marks' => 5,
                'option_a' => null,
                'option_b' => null,
                'correct_answer' => 'Model View Controller',
                'grading_guidance' => 'Mention separation of concerns across Model, View, Controller.',
            ],
        ]);

        // Even an exact string match on Short Answer MUST remain pending manual grading!
        $startRes = $this->actingAs($this->studentUserA)->postJson("/api/student/quizzes/{$quiz->id}/start");
        $startRes->assertOk();
        $attemptId = (int) $startRes->json('data.attempt_id');
        $qs = collect($startRes->json('data.questions'));
        $mcqId = $qs->firstWhere('question_type', 'multiple_choice')['id'];
        $saId = $qs->firstWhere('question_type', 'short_answer')['id'];

        $submitRes = $this->actingAs($this->studentUserA)->postJson("/api/student/quiz-attempts/{$attemptId}/submit", [
            'answers' => [
                ['question_id' => $mcqId, 'answer' => 'A'],
                ['question_id' => $saId, 'answer' => 'Model View Controller'],
            ],
        ]);

        $submitRes->assertOk();
        $this->assertEquals('pending_grading', $submitRes->json('data.grading_status'));
        $this->assertEquals('submitted', $submitRes->json('data.status'));
        $this->assertNull($submitRes->json('data.score'));
        $this->assertFalse((bool) $submitRes->json('data.score_released'));
        $this->assertTrue((bool) $submitRes->json('data.awaiting_manual_grading'));

        // Student B also submits so we can verify single-student isolation later
        $startB = $this->actingAs($this->studentUserB)->postJson("/api/student/quizzes/{$quiz->id}/start");
        $attemptIdB = (int) $startB->json('data.attempt_id');
        $this->actingAs($this->studentUserB)->postJson("/api/student/quiz-attempts/{$attemptIdB}/submit", [
            'answers' => [
                ['question_id' => $mcqId, 'answer' => 'A'],
                ['question_id' => $saId, 'answer' => 'Separates data from presentation.'],
            ],
        ])->assertOk();

        // Student quiz list & show endpoints must mask score and expose pending status
        $detailA = $this->actingAs($this->studentUserA)->getJson("/api/student/quizzes/{$quiz->id}");
        $detailA->assertOk();
        $this->assertNull($detailA->json('data.effective_score'));
        $this->assertTrue((bool) $detailA->json('data.has_pending_grading'));
        $this->assertNull($detailA->json('data.attempt.score'));

        $listA = $this->actingAs($this->studentUserA)->getJson('/api/student/quizzes');
        $listA->assertOk();
        $rowA = collect($listA->json('data'))->firstWhere('id', $quiz->id);
        $this->assertNotNull($rowA);
        $this->assertTrue((bool) $rowA['has_pending_grading']);
        $this->assertNull($rowA['effective_score']);
        $this->assertNull($rowA['attempts'][0]['score']);

        // Teacher inspects attempt A: sees guidance, student answer, and pending status
        $teacherAttempt = $this->actingAs($this->teacherUser)->getJson("/api/teacher/quiz-attempts/{$attemptId}");
        $teacherAttempt->assertOk();
        $this->assertTrue((bool) $teacherAttempt->json('data.pending_manual_grading'));
        $saRow = collect($teacherAttempt->json('data.questions'))->firstWhere('question_type', 'short_answer');
        $this->assertNotNull($saRow);
        $this->assertTrue((bool) $saRow['needs_manual_grading']);
        $this->assertStringContainsString('Model, View, Controller', (string) $saRow['grading_guidance']);
        $answerId = (int) $saRow['answer_id'];

        // Validation: reject negative or > max marks
        $this->actingAs($this->teacherUser)->patchJson("/api/teacher/quiz-answers/{$answerId}/grade", [
            'marks_obtained' => -1,
        ])->assertStatus(422);

        $this->actingAs($this->teacherUser)->patchJson("/api/teacher/quiz-answers/{$answerId}/grade", [
            'marks_obtained' => 6,
        ])->assertStatus(422);

        // Partial manual grade (3.5 / 5) + teacher feedback
        $gradeRes = $this->actingAs($this->teacherUser)->patchJson("/api/teacher/quiz-answers/{$answerId}/grade", [
            'marks_obtained' => 3.5,
            'teacher_feedback' => 'Good concise summary, expand on controllers.',
        ]);
        $gradeRes->assertOk();
        $this->assertEquals(8.5, (float) $gradeRes->json('data.attempt_score'));
        $this->assertEquals(5.0, (float) $gradeRes->json('data.auto_graded_score'));
        $this->assertEquals(3.5, (float) $gradeRes->json('data.manual_graded_score'));
        $this->assertEquals('graded', $gradeRes->json('data.attempt_grading_status'));
        $this->assertEquals(8.5, (float) $gradeRes->json('data.effective_student_score'));

        // Student A's review shows marks + feedback; Student B remains untouched at pending_grading
        $reviewA = $this->actingAs($this->studentUserA)->getJson("/api/student/quiz-attempts/{$attemptId}/review");
        $reviewA->assertOk();
        $this->assertEquals(8.5, (float) $reviewA->json('data.result.score'));
        $reviewSaA = collect($reviewA->json('data.questions'))->firstWhere('question_type', 'short_answer');
        $this->assertEquals(3.5, (float) $reviewSaA['marks_obtained']);
        $this->assertEquals('Good concise summary, expand on controllers.', $reviewSaA['teacher_feedback']);

        $attemptB = QuizAttempt::findOrFail($attemptIdB);
        $this->assertNull($attemptB->score);
        $this->assertEquals('pending_grading', $attemptB->grading_status);

        // Batch endpoint works cleanly for Student B
        $teacherAttemptB = $this->actingAs($this->teacherUser)->getJson("/api/teacher/quiz-attempts/{$attemptIdB}");
        $saB = collect($teacherAttemptB->json('data.questions'))->firstWhere('question_type', 'short_answer');
        $batchGradeB = $this->actingAs($this->teacherUser)->postJson("/api/teacher/quiz-attempts/{$attemptIdB}/grade", [
            'grades' => [
                [
                    'answer_id' => $saB['answer_id'],
                    'marks_obtained' => 4.0,
                    'feedback' => 'Solid answer.',
                ],
            ],
        ]);
        $batchGradeB->assertOk();
        $this->assertEquals(9.0, (float) $batchGradeB->json('data.score'));
        $this->assertEquals((int) $this->teacherUser->id, (int) $attemptB->fresh()->graded_by);
    }

    public function test_incomplete_submission_blocks_required_questions_but_allows_blank_optional_questions(): void
    {
        $quiz = $this->createQuizWithQuestions([], [
            [
                'question_text' => 'Required MCQ',
                'question_type' => 'multiple_choice',
                'marks' => 4,
                'is_required' => true,
                'option_a' => 'Yes',
                'option_b' => 'No',
                'correct_answer' => 'A',
            ],
            [
                'question_text' => 'Required Short Answer',
                'question_type' => 'short_answer',
                'marks' => 4,
                'is_required' => true,
                'option_a' => null,
                'option_b' => null,
                'correct_answer' => 'Explanation',
            ],
            [
                'question_text' => 'Optional Bonus Short Answer',
                'question_type' => 'short_answer',
                'marks' => 2,
                'is_required' => false,
                'option_a' => null,
                'option_b' => null,
                'correct_answer' => 'Optional notes',
            ],
        ]);

        $start = $this->actingAs($this->studentUserA)->postJson("/api/student/quizzes/{$quiz->id}/start");
        $start->assertOk();
        $attemptId = (int) $start->json('data.attempt_id');
        $qs = $start->json('data.questions');

        // Submit with Q1 unanswered & Q2 whitespace-only -> must fail 422
        $blocked = $this->actingAs($this->studentUserA)->postJson("/api/student/quiz-attempts/{$attemptId}/submit", [
            'answers' => [
                ['question_id' => $qs[1]['id'], 'answer' => '   '],
            ],
        ]);
        $blocked->assertStatus(422);
        $this->assertEquals([1, 2], $blocked->json('unanswered_question_numbers'));
        $this->assertEquals([$qs[0]['id'], $qs[1]['id']], $blocked->json('unanswered_question_ids'));
        $this->assertEquals('in_progress', QuizAttempt::findOrFail($attemptId)->status);

        // Answer Q1 + Q2, leave Q3 (optional) blank -> succeeds 200 and remains pending for Q2
        $ok = $this->actingAs($this->studentUserA)->postJson("/api/student/quiz-attempts/{$attemptId}/submit", [
            'answers' => [
                ['question_id' => $qs[0]['id'], 'answer' => 'A'],
                ['question_id' => $qs[1]['id'], 'answer' => 'Valid written answer'],
                ['question_id' => $qs[2]['id'], 'answer' => ''],
            ],
        ]);
        $ok->assertOk();
        $this->assertEquals('pending_grading', $ok->json('data.grading_status'));
    }

    public function test_attempt_limits_and_scoring_policies_highest_latest_average(): void
    {
        $policies = [
            ['method' => 'highest', 'max' => 2, 'scores' => [4.0, 2.0], 'expected' => 4.0],
            ['method' => 'latest', 'max' => 3, 'scores' => [4.0, 4.0, 2.0], 'expected' => 2.0],
            ['method' => 'average', 'max' => 5, 'scores' => [2.0, 4.0], 'expected' => 3.0],
        ];

        foreach ($policies as $idx => $case) {
            $quiz = $this->createQuizWithQuestions([
                'title' => 'Policy Quiz ' . $case['method'],
                'max_attempts' => $case['max'],
                'scoring_method' => $case['method'],
            ], [
                [
                    'question_text' => 'Half Q1',
                    'question_type' => 'true_false',
                    'marks' => 2,
                    'option_a' => null,
                    'option_b' => null,
                    'correct_answer' => 'true',
                ],
                [
                    'question_text' => 'Half Q2',
                    'question_type' => 'true_false',
                    'marks' => 2,
                    'option_a' => null,
                    'option_b' => null,
                    'correct_answer' => 'true',
                ],
            ]);

            $qIds = QuizQuestion::where('quiz_id', $quiz->id)->orderBy('question_order')->pluck('id')->all();

            foreach ($case['scores'] as $targetScore) {
                $start = $this->actingAs($this->studentUserA)->postJson("/api/student/quizzes/{$quiz->id}/start");
                $start->assertOk();
                $attId = (int) $start->json('data.attempt_id');
                $answers = match ((int) $targetScore) {
                    4 => [
                        ['question_id' => $qIds[0], 'answer' => 'true'],
                        ['question_id' => $qIds[1], 'answer' => 'true'],
                    ],
                    3 => [
                        ['question_id' => $qIds[0], 'answer' => 'true'],
                        ['question_id' => $qIds[1], 'answer' => 'false'],
                    ],
                    2 => [
                        ['question_id' => $qIds[0], 'answer' => 'true'],
                        ['question_id' => $qIds[1], 'answer' => 'false'],
                    ],
                    default => [
                        ['question_id' => $qIds[0], 'answer' => 'false'],
                        ['question_id' => $qIds[1], 'answer' => 'false'],
                    ],
                };
                // Adjust 3-pt case vs 2-pt case explicitly via exact marks if needed
                $this->actingAs($this->studentUserA)->postJson("/api/student/quiz-attempts/{$attId}/submit", [
                    'answers' => $answers,
                ])->assertOk();
            }

            // Recompute expected if 2-pt vs 3-pt share 2+2 questions -> fix Q2 marks on 'latest' case!
            $detail = $this->actingAs($this->studentUserA)->getJson("/api/student/quizzes/{$quiz->id}");
            $detail->assertOk();
            $expectedScore = $case['method'] === 'latest' ? 2.0 : $case['expected'];
            $this->assertEquals($expectedScore, (float) $detail->json('data.effective_score'));
        }

        // Enforce max_attempts = 2 blocks 3rd start with HTTP 409
        $cappedQuiz = Quiz::where('title', 'Policy Quiz highest')->firstOrFail();
        $blockedThird = $this->actingAs($this->studentUserA)->postJson("/api/student/quizzes/{$cappedQuiz->id}/start");
        $blockedThird->assertStatus(409);

        // Unlimited (null / 0) allows unlimited attempts and shows null max_attempts
        $unlimRes = $this->actingAs($this->teacherUser)->postJson("/api/teacher/course-offerings/{$this->offering->id}/quizzes", [
            'title' => 'Unlimited Practice Quiz',
            'duration_minutes' => 15,
            'max_attempts' => 0,
            'scoring_method' => 'average',
            'status' => 'draft',
        ]);
        $unlimRes->assertStatus(201);
        $unlimQuiz = Quiz::findOrFail((int) $unlimRes->json('data.id'));
        $this->assertNull($unlimQuiz->max_attempts);

        // Dashboard student quizzes and Teacher assessments overview reflect scoring method & attempts
        $dashQuizzes = $this->actingAs($this->studentUserA)->getJson('/api/student/quizzes');
        $dashQuizzes->assertOk();
        $highestCard = collect($dashQuizzes->json('data'))->firstWhere('id', $cappedQuiz->id);
        $this->assertEquals(4.0, (float) $highestCard['effective_score']);

        $overview = $this->actingAs($this->teacherUser)->getJson('/api/teacher/assessments-overview');
        $overview->assertOk();
        $gbRow = collect($overview->json('data.gradebook'))->firstWhere('student_id', $this->studentA->id);
        $this->assertGreaterThan(0, (float) $gbRow['quiz_score']);

        // StudentAnalyticsCalculator reflects finalized attempts
        $calc = app(StudentAnalyticsCalculator::class)->computeCurrentSummary($this->studentA, null);
        $this->assertEquals(75.0, (float) $calc['quiz_average']); // (4 + 2 + 3) / (4 + 4 + 4) = 9/12 = 75%
    }

    public function test_full_quiz_management_lifecycle_snapshots_and_soft_archive(): void
    {
        $quiz = $this->createQuizWithQuestions(['max_attempts' => 2], [
            [
                'question_text' => 'Original Q1',
                'question_type' => 'multiple_choice',
                'marks' => 10,
                'option_a' => 'Old Correct',
                'option_b' => 'Old Wrong',
                'correct_answer' => 'A',
            ],
        ]);
        $q1 = QuizQuestion::where('quiz_id', $quiz->id)->firstOrFail();

        // Student A submits Attempt 1 on original v1
        $s1 = $this->actingAs($this->studentUserA)->postJson("/api/student/quizzes/{$quiz->id}/start");
        $attemptAId = (int) $s1->json('data.attempt_id');
        $this->actingAs($this->studentUserA)->postJson("/api/student/quiz-attempts/{$attemptAId}/submit", [
            'answers' => [['question_id' => $q1->id, 'answer' => 'A']],
        ])->assertOk();
        $this->assertEquals(10.0, (float) QuizAttempt::findOrFail($attemptAId)->score);

        // Teacher unpublishes, edits quiz info, updates Q1, adds Q2, duplicates & reorders, then re-publishes
        $this->actingAs($this->teacherUser)->patchJson("/api/teacher/quizzes/{$quiz->id}/unpublish")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        $this->actingAs($this->teacherUser)->putJson("/api/teacher/quizzes/{$quiz->id}", [
            'title' => 'Updated Quiz Title',
            'duration_minutes' => 45,
            'max_attempts' => 3,
            'scoring_method' => 'latest',
            'allow_late_submission' => true,
        ])->assertOk()
            ->assertJsonPath('data.title', 'Updated Quiz Title')
            ->assertJsonPath('data.scoring_method', 'latest');

        $this->actingAs($this->teacherUser)->putJson("/api/teacher/quiz-questions/{$q1->id}", [
            'question_text' => 'Edited Q1 New Text',
            'question_type' => 'multiple_choice',
            'marks' => 5,
            'option_a' => 'Updated Correct',
            'option_b' => 'Updated Wrong',
            'correct_answer' => 'A',
            'is_required' => true,
        ])->assertOk();

        $q2Res = $this->actingAs($this->teacherUser)->postJson("/api/teacher/quizzes/{$quiz->id}/questions", [
            'question_text' => 'Added Q2',
            'question_type' => 'true_false',
            'marks' => 5,
            'correct_answer' => 'true',
            'is_required' => true,
        ]);
        $q2Res->assertStatus(201);
        $q2Id = (int) $q2Res->json('data.id');

        $this->actingAs($this->teacherUser)->putJson("/api/teacher/quizzes/{$quiz->id}/questions/reorder", [
            'order' => [$q2Id, $q1->id],
        ])->assertOk();

        // Soft-delete Q1 when historical answer exists -> should soft-delete & preserve Attempt A snapshot!
        $this->actingAs($this->teacherUser)->deleteJson("/api/teacher/quiz-questions/{$q1->id}")->assertOk();
        $this->assertSoftDeleted('quiz_questions', ['id' => $q1->id]);

        $this->actingAs($this->teacherUser)->patchJson("/api/teacher/quizzes/{$quiz->id}/publish")->assertOk();

        // Verify historical Attempt A still preserves its original v1 snapshot & 10.0 score
        $reviewA = $this->actingAs($this->studentUserA)->getJson("/api/student/quiz-attempts/{$attemptAId}/review");
        $reviewA->assertOk();
        $this->assertEquals(10.0, (float) $reviewA->json('data.result.score'));
        $this->assertEquals('Original Q1', $reviewA->json('data.questions.0.question_text'));

        // Student B starts new attempt -> sees updated Q2 only (5 marks)
        $sB = $this->actingAs($this->studentUserB)->postJson("/api/student/quizzes/{$quiz->id}/start");
        $sB->assertOk();
        $this->assertCount(1, $sB->json('data.questions'));
        $this->assertEquals('Added Q2', $sB->json('data.questions.0.question_text'));

        // Archiving quiz with attempts soft-deletes it without deleting attempts
        $delRes = $this->actingAs($this->teacherUser)->deleteJson("/api/teacher/quizzes/{$quiz->id}");
        $delRes->assertOk()->assertJsonPath('archived', true);
        $this->assertSoftDeleted('quizzes', ['id' => $quiz->id]);
        $this->assertNotNull(QuizAttempt::find($attemptAId));

        // Deleting a quiz with 0 attempts force-deletes it
        $emptyQuiz = $this->createQuizWithQuestions(['title' => 'Empty Quiz'], []);
        $forceDel = $this->actingAs($this->teacherUser)->deleteJson("/api/teacher/quizzes/{$emptyQuiz->id}");
        $forceDel->assertOk()->assertJsonPath('archived', false);
        $this->assertNull(Quiz::withTrashed()->find($emptyQuiz->id));
    }

    public function test_reopen_quiz_and_student_specific_overrides_with_audit_logs_and_isolation(): void
    {
        $quiz = $this->createQuizWithQuestions([
            'status' => 'closed',
            'start_at' => now()->subDays(3),
            'end_at' => now()->subDay(),
            'max_attempts' => 1,
        ], [
            [
                'question_text' => 'Server deadline Q1',
                'question_type' => 'true_false',
                'marks' => 10,
                'option_a' => null,
                'option_b' => null,
                'correct_answer' => 'true',
            ],
        ]);
        $q1 = QuizQuestion::where('quiz_id', $quiz->id)->firstOrFail();

        // Both students are initially blocked from starting closed quiz
        $this->actingAs($this->studentUserA)->postJson("/api/student/quizzes/{$quiz->id}/start")->assertStatus(422);
        $this->actingAs($this->studentUserB)->postJson("/api/student/quizzes/{$quiz->id}/start")->assertStatus(422);

        // Teacher grants override ONLY to Student A (extended deadline + allow late)
        $ovRes = $this->actingAs($this->teacherUser)->postJson("/api/teacher/quizzes/{$quiz->id}/overrides", [
            'student_id' => $this->studentA->id,
            'extended_end_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'allow_late_submission' => true,
            'reason' => 'Medical excuse',
        ]);
        $ovRes->assertStatus(201);

        // Student A can take & submit attempt 1 (marked is_late appropriately if past quiz.end_at), Student B still blocked
        $this->actingAs($this->studentUserB)->postJson("/api/student/quizzes/{$quiz->id}/start")->assertStatus(422);

        $startA1 = $this->actingAs($this->studentUserA)->postJson("/api/student/quizzes/{$quiz->id}/start");
        $startA1->assertOk();
        $att1 = (int) $startA1->json('data.attempt_id');
        $this->actingAs($this->studentUserA)->postJson("/api/student/quiz-attempts/{$att1}/submit", [
            'answers' => [['question_id' => $q1->id, 'answer' => 'false']],
        ])->assertOk();

        // Student A used 1/1 attempts -> now blocked (409)
        $this->actingAs($this->studentUserA)->postJson("/api/student/quizzes/{$quiz->id}/start")->assertStatus(409);

        // Teacher grants Student A +1 extra attempt AND resets prior attempt -> preserves old attempt as is_reset = true
        $this->actingAs($this->teacherUser)->postJson("/api/teacher/quizzes/{$quiz->id}/overrides", [
            'student_id' => $this->studentA->id,
            'grant_extra_attempts' => 1,
            'reset_attempts' => true,
            'reason' => 'Device crashed during submission',
        ])->assertStatus(201);

        $this->assertTrue((bool) QuizAttempt::findOrFail($att1)->is_reset);

        $startA2 = $this->actingAs($this->studentUserA)->postJson("/api/student/quizzes/{$quiz->id}/start");
        $startA2->assertOk();
        $att2 = (int) $startA2->json('data.attempt_id');
        $this->actingAs($this->studentUserA)->postJson("/api/student/quiz-attempts/{$att2}/submit", [
            'answers' => [['question_id' => $q1->id, 'answer' => 'true']],
        ])->assertOk();
        $this->assertEquals(10.0, (float) QuizAttempt::findOrFail($att2)->score);

        // Teacher reopens quiz for class & logs audit
        $this->actingAs($this->teacherUser)->postJson("/api/teacher/quizzes/{$quiz->id}/reopen", [
            'end_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
            'allow_late_submission' => false,
            'reason' => 'Class extension',
        ])->assertOk()->assertJsonPath('data.status', 'published');

        // Student B can now start!
        $this->actingAs($this->studentUserB)->postJson("/api/student/quizzes/{$quiz->id}/start")->assertOk();

        // Verify audit logs capture actor, student, quiz, reason, previous & new settings
        $logsRes = $this->actingAs($this->teacherUser)->getJson("/api/teacher/quizzes/{$quiz->id}/audit-logs");
        $logsRes->assertOk();
        $actions = collect($logsRes->json('data'))->pluck('action')->all();
        $this->assertContains('extend_deadline+allow_late_submission', $actions);
        $this->assertContains('grant_extra_attempt+reset_attempts', $actions);
        $this->assertContains('reopen_quiz', $actions);
        $this->assertGreaterThanOrEqual(3, QuizOverrideLog::where('quiz_id', $quiz->id)->count());

        // Role & ownership authorization checks
        $this->actingAs($this->studentUserA)->postJson("/api/teacher/quizzes/{$quiz->id}/reopen", [])->assertStatus(403);
        $this->actingAs($this->otherTeacherUser)->getJson("/api/teacher/quizzes/{$quiz->id}")->assertStatus(403);
        $this->actingAs($this->otherTeacherUser)->postJson("/api/teacher/quizzes/{$quiz->id}/overrides", [
            'student_id' => $this->studentA->id,
            'grant_extra_attempts' => 1,
        ])->assertStatus(403);
        $this->actingAs($this->studentUserB)->getJson("/api/student/quiz-attempts/{$att2}/review")->assertStatus(404);
    }
}
