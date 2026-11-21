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

class SubmissionFilePreviewAndGradingTest extends TestCase
{
    use RefreshDatabase;

    private User $uTeacher;
    private User $uOther;
    private User $uStuA;
    private User $uStuB;
    private Teacher $teacher;
    private Teacher $otherTeacher;
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
            'course_code' => 'CS101',
            'course_name' => 'Intro to Computer Science',
            'credit' => 3,
            'status' => 'active',
        ]);

        $this->uTeacher = User::create([
            'name' => 'Teacher One',
            'email' => 't1@slas.edu',
            'password' => Hash::make('TCH@12345'),
            'role' => 'teacher',
            'status' => 'active',
        ]);
        $this->teacher = Teacher::create([
            'user_id' => $this->uTeacher->id,
            'teacher_code' => 'TCH001',
            'english_name' => 'Teacher One',
            'first_name' => 'Teacher',
            'last_name' => 'One',
            'department_id' => $dept->id,
            'status' => 'active',
        ]);

        $this->uOther = User::create([
            'name' => 'Teacher Two',
            'email' => 't2@slas.edu',
            'password' => Hash::make('TCH@12345'),
            'role' => 'teacher',
            'status' => 'active',
        ]);
        $this->otherTeacher = Teacher::create([
            'user_id' => $this->uOther->id,
            'teacher_code' => 'TCH002',
            'english_name' => 'Teacher Two',
            'first_name' => 'Teacher',
            'last_name' => 'Two',
            'department_id' => $dept->id,
            'status' => 'active',
        ]);

        $this->offering = CourseOffering::create([
            'course_id' => $course->id,
            'class_id' => $classRoom->id,
            'semester_id' => $semester->id,
            'academic_year_id' => $ay->id,
            'teacher_id' => $this->teacher->id,
            'status' => 'active',
        ]);

        DB::table('course_offering_teachers')->insert([
            'course_offering_id' => $this->offering->id,
            'teacher_id' => $this->teacher->id,
            'role' => 'primary',
            'status' => 'active',
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->uStuA = User::create([
            'name' => 'Student Alpha',
            'email' => 'sa@slas.edu',
            'password' => Hash::make('STD@12345'),
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->studentA = Student::create([
            'user_id' => $this->uStuA->id,
            'student_code' => 'STU001',
            'english_name' => 'Student Alpha',
            'first_name' => 'Student',
            'last_name' => 'Alpha',
            'gender' => 'male',
            'department_id' => $dept->id,
            'class_id' => $classRoom->id,
            'status' => 'enrolled',
        ]);

        $this->uStuB = User::create([
            'name' => 'Student Beta',
            'email' => 'sb@slas.edu',
            'password' => Hash::make('STD@12345'),
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->studentB = Student::create([
            'user_id' => $this->uStuB->id,
            'student_code' => 'STU002',
            'english_name' => 'Student Beta',
            'first_name' => 'Student',
            'last_name' => 'Beta',
            'gender' => 'female',
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
            'teacher_id' => $this->teacher->id,
            'semester_id' => $semester->id,
            'title' => 'Lab 1: Flowcharts',
            'assignment_type' => 'assignment',
            'total_score' => 50,
            'due_date' => now()->addDays(5),
            'status' => 'published',
        ]);
    }

    public function test_image_submission_end_to_end_view_grade_and_student_published_visibility(): void
    {
        $img = UploadedFile::fake()->image('flowchart_solution.png', 400, 300);

        $submitRes = $this->actingAs($this->uStuA, 'sanctum')
            ->post('/api/assignment-submissions', [
                'assignment_id' => $this->assignment->id,
                'submission_file' => $img,
            ], ['Accept' => 'application/json']);

        $submitRes->assertCreated();
        $subId = (int) $submitRes->json('data.id');
        $sub = AssignmentSubmission::findOrFail($subId);
        $this->assertSame('flowchart_solution.png', $sub->original_file_name);
        Storage::disk('public')->assertExists($sub->file_path);

        // Teacher overview shows user-friendly file metadata (not raw storage path)
        $overview = $this->actingAs($this->uTeacher, 'sanctum')
            ->getJson('/api/teacher/assessments-overview')
            ->assertOk();
        $overview->assertJsonPath('data.submissions.0.id', $subId)
            ->assertJsonPath('data.submissions.0.file_name', 'flowchart_solution.png')
            ->assertJsonPath('data.submissions.0.file_type', 'PNG Image')
            ->assertJsonPath('data.submissions.0.preview_type', 'image')
            ->assertJsonPath('data.submissions.0.file_exists', true)
            ->assertJsonPath('data.submissions.0.max_score', 50);

        // Teacher detail endpoint returns exact submission + file metadata
        $this->actingAs($this->uTeacher, 'sanctum')
            ->getJson("/api/teacher/submissions/{$subId}")
            ->assertOk()
            ->assertJsonPath('data.student_code', 'STU001')
            ->assertJsonPath('data.assignment_title', 'Lab 1: Flowcharts')
            ->assertJsonPath('data.file_name', 'flowchart_solution.png')
            ->assertJsonPath('data.file_exists', true);

        // Teacher streams actual inline image
        $fileRes = $this->actingAs($this->uTeacher, 'sanctum')
            ->get("/api/teacher/submissions/{$subId}/file");
        $fileRes->assertOk();
        $this->assertStringContainsString('image/png', (string) $fileRes->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $fileRes->headers->get('Content-Disposition'));

        // Download mode sets attachment disposition
        $dlRes = $this->actingAs($this->uTeacher, 'sanctum')
            ->get("/api/teacher/submissions/{$subId}/file?download=1");
        $dlRes->assertOk();
        $this->assertStringContainsString('attachment', (string) $dlRes->headers->get('Content-Disposition'));

        // Save draft score first: student does NOT see published score yet
        $this->actingAs($this->uTeacher, 'sanctum')
            ->postJson("/api/teacher/submissions/{$subId}/grade", [
                'score' => 42,
                'feedback' => 'Draft review',
                'publish' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_published', false);

        $this->actingAs($this->uStuA, 'sanctum')
            ->getJson("/api/student/module/grades/courses/{$this->offering->id}")
            ->assertOk()
            ->assertJsonPath('data.breakdown.0.publication_status', 'Pending')
            ->assertJsonPath('data.breakdown.0.earned_score', null);

        // Score above assignment max_score rejected
        $this->actingAs($this->uTeacher, 'sanctum')
            ->postJson("/api/teacher/submissions/{$subId}/grade", [
                'score' => 55,
                'publish' => true,
            ])
            ->assertStatus(422);

        // Save & publish final score
        $this->actingAs($this->uTeacher, 'sanctum')
            ->postJson("/api/teacher/submissions/{$subId}/grade", [
                'score' => 46.5,
                'feedback' => 'Well structured diagram!',
                'publish' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'Graded')
            ->assertJsonPath('data.is_published', true);

        // Student sees published score and feedback
        $this->actingAs($this->uStuA, 'sanctum')
            ->getJson("/api/student/module/grades/courses/{$this->offering->id}")
            ->assertOk()
            ->assertJsonPath('data.breakdown.0.publication_status', 'Published')
            ->assertJsonPath('data.breakdown.0.earned_score', 46.5)
            ->assertJsonPath('data.breakdown.0.teacher_feedback', 'Well structured diagram!');
    }

    public function test_pdf_docx_unsupported_missing_traversal_and_authorization_guards(): void
    {
        // PDF submission
        $pdf = UploadedFile::fake()->create('lab_report.pdf', 120, 'application/pdf');
        $pdfRes = $this->actingAs($this->uStuA, 'sanctum')
            ->post('/api/assignment-submissions', [
                'assignment_id' => $this->assignment->id,
                'submission_file' => $pdf,
            ], ['Accept' => 'application/json'])->assertCreated();
        $pdfSubId = (int) $pdfRes->json('data.id');

        $this->actingAs($this->uTeacher, 'sanctum')
            ->getJson("/api/teacher/submissions/{$pdfSubId}")
            ->assertOk()
            ->assertJsonPath('data.preview_type', 'pdf')
            ->assertJsonPath('data.file_type', 'PDF Document');

        $streamPdf = $this->actingAs($this->uTeacher, 'sanctum')
            ->get("/api/teacher/submissions/{$pdfSubId}/file")
            ->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $streamPdf->headers->get('Content-Type'));

        // Other student submits DOCX -> isolated per student and preserves own submission
        $docx = UploadedFile::fake()->create('beta_essay.docx', 80, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $bRes = $this->actingAs($this->uStuB, 'sanctum')
            ->post('/api/assignment-submissions', [
                'assignment_id' => $this->assignment->id,
                'submission_file' => $docx,
            ], ['Accept' => 'application/json'])->assertCreated();
        $bSubId = (int) $bRes->json('data.id');

        $this->actingAs($this->uTeacher, 'sanctum')
            ->getJson("/api/teacher/submissions/{$bSubId}")
            ->assertOk()
            ->assertJsonPath('data.student_code', 'STU002')
            ->assertJsonPath('data.preview_type', 'document');

        // Unauthorized teacher blocked (403) & student blocked (403)
        $this->actingAs($this->uOther, 'sanctum')
            ->getJson("/api/teacher/submissions/{$pdfSubId}")
            ->assertStatus(403);
        $this->actingAs($this->uOther, 'sanctum')
            ->get("/api/teacher/submissions/{$pdfSubId}/file")
            ->assertStatus(403);
        $this->actingAs($this->uStuA, 'sanctum')
            ->get("/api/teacher/submissions/{$pdfSubId}/file")
            ->assertStatus(403);

        // Grading Student A does NOT alter Student B's submission
        $this->actingAs($this->uTeacher, 'sanctum')
            ->postJson("/api/teacher/submissions/{$pdfSubId}/grade", ['score' => 40, 'publish' => true])
            ->assertOk();
        $subB = AssignmentSubmission::findOrFail($bSubId);
        $this->assertNull($subB->score);
        $this->assertNotSame('graded', $subB->status);

        // Missing physical file -> clean 404 JSON + file_exists=false
        $subA = AssignmentSubmission::findOrFail($pdfSubId);
        Storage::disk('public')->delete($subA->file_path);

        $this->actingAs($this->uTeacher, 'sanctum')
            ->getJson("/api/teacher/submissions/{$pdfSubId}")
            ->assertOk()
            ->assertJsonPath('data.file_exists', false)
            ->assertJsonPath('data.preview_type', 'missing');

        $this->actingAs($this->uTeacher, 'sanctum')
            ->getJson("/api/teacher/submissions/{$pdfSubId}/file")
            ->assertStatus(404);

        // Path traversal attempt in file_path -> rejected cleanly with 404
        $subB->update(['file_path' => '../../.env']);
        $this->actingAs($this->uTeacher, 'sanctum')
            ->getJson("/api/teacher/submissions/{$bSubId}/file")
            ->assertStatus(404);
    }
}
