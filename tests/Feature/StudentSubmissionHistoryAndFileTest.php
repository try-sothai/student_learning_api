<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\ClassRoom;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentSubmissionHistoryAndFileTest extends TestCase
{
    use RefreshDatabase;

    private User $uTeacher;
    private User $uStuA;
    private User $uStuB;
    private Student $studentA;
    private Student $studentB;
    private CourseOffering $offering;
    private Assignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');

        $dept = Department::create([
            'name' => 'Computer Science',
            'code' => 'CS',
            'status' => 'active',
        ]);

        $ay = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-10-01',
            'end_date' => '2027-07-31',
            'status' => 'active',
        ]);

        $semester = Semester::create([
            'academic_year_id' => $ay->id,
            'name' => 'Semester 1',
            'semester_number' => 1,
            'start_date' => '2026-10-01',
            'end_date' => '2027-02-28',
            'status' => 'active',
        ]);

        $classRoom = ClassRoom::create([
            'department_id' => $dept->id,
            'academic_year_id' => $ay->id,
            'code' => 'CLASS-A',
            'name' => 'Class A',
            'year_level' => 1,
            'status' => 'active',
        ]);

        $course = Course::create([
            'department_id' => $dept->id,
            'course_code' => 'CS202',
            'course_name' => 'Software Engineering',
            'credit' => 3,
            'status' => 'active',
        ]);

        $this->uTeacher = User::create([
            'name' => 'Prof. Teacher',
            'email' => 'teacher.se@slas.edu',
            'password' => Hash::make('TCH@12345'),
            'role' => 'teacher',
            'status' => 'active',
        ]);
        $teacher = Teacher::create([
            'user_id' => $this->uTeacher->id,
            'teacher_code' => 'TCH_SE',
            'english_name' => 'Prof. Teacher',
            'first_name' => 'Prof.',
            'last_name' => 'Teacher',
            'department_id' => $dept->id,
            'status' => 'active',
        ]);

        $this->offering = CourseOffering::create([
            'course_id' => $course->id,
            'class_id' => $classRoom->id,
            'semester_id' => $semester->id,
            'academic_year_id' => $ay->id,
            'teacher_id' => $teacher->id,
            'status' => 'active',
        ]);

        DB::table('course_offering_teachers')->insert([
            'course_offering_id' => $this->offering->id,
            'teacher_id' => $teacher->id,
            'role' => 'primary',
            'status' => 'active',
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->uStuA = User::create([
            'name' => 'Student Alice',
            'email' => 'alice@slas.edu',
            'password' => Hash::make('STD@12345'),
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->studentA = Student::create([
            'user_id' => $this->uStuA->id,
            'student_code' => 'STU_A1',
            'english_name' => 'Student Alice',
            'first_name' => 'Student',
            'last_name' => 'Alice',
            'gender' => 'female',
            'department_id' => $dept->id,
            'class_id' => $classRoom->id,
            'status' => 'enrolled',
        ]);

        $this->uStuB = User::create([
            'name' => 'Student Bob',
            'email' => 'bob@slas.edu',
            'password' => Hash::make('STD@12345'),
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->studentB = Student::create([
            'user_id' => $this->uStuB->id,
            'student_code' => 'STU_B2',
            'english_name' => 'Student Bob',
            'first_name' => 'Student',
            'last_name' => 'Bob',
            'gender' => 'male',
            'department_id' => $dept->id,
            'class_id' => $classRoom->id,
            'status' => 'enrolled',
        ]);

        foreach ([$this->studentA, $this->studentB] as $stu) {
            CourseEnrollment::create([
                'student_id' => $stu->id,
                'course_id' => $course->id,
                'semester_id' => $semester->id,
                'course_offering_id' => $this->offering->id,
                'status' => 'enrolled',
            ]);
        }

        $this->assignment = Assignment::create([
            'course_id' => $course->id,
            'course_offering_id' => $this->offering->id,
            'teacher_id' => $teacher->id,
            'semester_id' => $semester->id,
            'title' => 'UML Architecture Diagram',
            'assignment_type' => 'assignment',
            'total_score' => 50,
            'due_date' => now()->addDays(4),
            'status' => 'published',
        ]);
    }

    public function test_student_submits_views_own_file_enforces_ownership_and_receives_published_grade(): void
    {
        // 1. Student A uploads image
        $img = UploadedFile::fake()->image('uml_architecture.png', 500, 350);
        $submitRes = $this->actingAs($this->uStuA, 'sanctum')
            ->post('/api/assignment-submissions', [
                'assignment_id' => $this->assignment->id,
                'submission_file' => $img,
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $subId = (int) $submitRes->json('data.id');
        $submission = AssignmentSubmission::findOrFail($subId);
        $this->assertSame('uml_architecture.png', $submission->original_file_name);

        // 2. Student A fetches My Submissions
        $myList = $this->actingAs($this->uStuA, 'sanctum')
            ->getJson('/api/student/submissions')
            ->assertOk();
        $myList->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $subId)
            ->assertJsonPath('data.0.assignment_title', 'UML Architecture Diagram')
            ->assertJsonPath('data.0.course_code', 'CS202')
            ->assertJsonPath('data.0.file_name', 'uml_architecture.png')
            ->assertJsonPath('data.0.file_type', 'PNG Image')
            ->assertJsonPath('data.0.preview_type', 'image')
            ->assertJsonPath('data.0.file_exists', true)
            ->assertJsonPath('data.0.grading_status', 'Waiting for Teacher Grading')
            ->assertJsonPath('data.0.is_published', false)
            ->assertJsonPath('data.0.score', null);

        // 3. Student A fetches Submission Detail & previews/downloads actual file
        $this->actingAs($this->uStuA, 'sanctum')
            ->getJson("/api/student/submissions/{$subId}")
            ->assertOk()
            ->assertJsonPath('data.student_code', 'STU_A1')
            ->assertJsonPath('data.file_name', 'uml_architecture.png');

        $streamRes = $this->actingAs($this->uStuA, 'sanctum')
            ->get("/api/student/submissions/{$subId}/file")
            ->assertOk();
        $this->assertStringContainsString('image/png', (string) $streamRes->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $streamRes->headers->get('Content-Disposition'));

        $dlRes = $this->actingAs($this->uStuA, 'sanctum')
            ->get("/api/student/submissions/{$subId}/file?download=1")
            ->assertOk();
        $this->assertStringContainsString('attachment', (string) $dlRes->headers->get('Content-Disposition'));

        // 4. Student B cannot list, inspect, or stream Student A's submission (even spoofing ?student_id)
        $this->actingAs($this->uStuB, 'sanctum')
            ->getJson('/api/student/submissions?student_id=' . $this->studentA->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->uStuB, 'sanctum')
            ->getJson("/api/student/submissions/{$subId}")
            ->assertStatus(403);

        $this->actingAs($this->uStuB, 'sanctum')
            ->get("/api/student/submissions/{$subId}/file")
            ->assertStatus(403);

        // 5. Teacher saves UNPUBLISHED draft score -> must stay hidden from Student A everywhere
        $this->actingAs($this->uTeacher, 'sanctum')
            ->postJson("/api/teacher/submissions/{$subId}/grade", [
                'score' => 35,
                'feedback' => 'Draft comment only',
                'publish' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_published', false);

        $this->actingAs($this->uStuA, 'sanctum')
            ->getJson("/api/student/submissions/{$subId}")
            ->assertOk()
            ->assertJsonPath('data.is_published', false)
            ->assertJsonPath('data.grading_status', 'Waiting for Teacher Grading')
            ->assertJsonPath('data.score', null)
            ->assertJsonPath('data.feedback', '');

        $this->actingAs($this->uStuA, 'sanctum')
            ->getJson("/api/assignments/{$this->assignment->id}")
            ->assertOk()
            ->assertJsonPath('data.submissions.0.is_published', false)
            ->assertJsonPath('data.submissions.0.score', null);

        // 6. Teacher views the same file, then publishes final score & feedback
        $this->actingAs($this->uTeacher, 'sanctum')
            ->get("/api/teacher/submissions/{$subId}/file")
            ->assertOk();

        $this->actingAs($this->uTeacher, 'sanctum')
            ->postJson("/api/teacher/submissions/{$subId}/grade", [
                'score' => 48,
                'feedback' => 'Clear components and clean arrows!',
                'publish' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_published', true);

        // 7. Student A sees published score, max score, and feedback
        $this->actingAs($this->uStuA, 'sanctum')
            ->getJson('/api/student/submissions')
            ->assertOk()
            ->assertJsonPath('data.0.is_published', true)
            ->assertJsonPath('data.0.grading_status', 'Published (Graded)')
            ->assertJsonPath('data.0.score', 48)
            ->assertJsonPath('data.0.max_score', 50)
            ->assertJsonPath('data.0.feedback', 'Clear components and clean arrows!');
    }

    public function test_student_pdf_missing_file_and_traversal_guards(): void
    {
        $pdf = UploadedFile::fake()->create('student_report.pdf', 150, 'application/pdf');
        $res = $this->actingAs($this->uStuA, 'sanctum')
            ->post('/api/assignment-submissions', [
                'assignment_id' => $this->assignment->id,
                'submission_file' => $pdf,
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $subId = (int) $res->json('data.id');

        $this->actingAs($this->uStuA, 'sanctum')
            ->getJson("/api/student/submissions/{$subId}")
            ->assertOk()
            ->assertJsonPath('data.preview_type', 'pdf')
            ->assertJsonPath('data.file_type', 'PDF Document');

        $stream = $this->actingAs($this->uStuA, 'sanctum')
            ->get("/api/student/submissions/{$subId}/file")
            ->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $stream->headers->get('Content-Type'));

        // Missing file -> clean 404 JSON + file_exists = false
        $sub = AssignmentSubmission::findOrFail($subId);
        Storage::disk('public')->delete($sub->file_path);

        $this->actingAs($this->uStuA, 'sanctum')
            ->getJson("/api/student/submissions/{$subId}")
            ->assertOk()
            ->assertJsonPath('data.file_exists', false)
            ->assertJsonPath('data.preview_type', 'missing');

        $this->actingAs($this->uStuA, 'sanctum')
            ->getJson("/api/student/submissions/{$subId}/file")
            ->assertStatus(404);

        // Path traversal blocked
        $sub->update(['file_path' => '../config/app.php']);
        $this->actingAs($this->uStuA, 'sanctum')
            ->getJson("/api/student/submissions/{$subId}/file")
            ->assertStatus(404);
    }
}
