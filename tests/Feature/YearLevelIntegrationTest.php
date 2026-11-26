<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassRoom;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Program;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\StudentImportValidationService;
use App\Support\YearLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class YearLevelIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(string $email = 'yl.admin@example.test'): User
    {
        return User::create([
            'name' => 'YearLevel Admin',
            'email' => $email,
            'password' => 'password',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    public function test_year_level_helper_parses_canonical_variants_and_rejects_academic_years_and_out_of_range(): void
    {
        $this->assertSame(1, YearLevel::parse(1));
        $this->assertSame(2, YearLevel::parse('Y2'));
        $this->assertSame(3, YearLevel::parse('Year 3'));
        $this->assertSame(4, YearLevel::parse('y4'));
        $this->assertSame(1, YearLevel::parse('ឆ្នាំទី១'));
        $this->assertSame(3, YearLevel::parse('ឆ្នាំទី៣'));
        $this->assertSame('2', YearLevel::storageValue('Y2'));
        $this->assertSame('Y4', YearLevel::code('Year 4'));
        $this->assertSame('Year 3', YearLevel::name('3'));
        $this->assertCount(4, YearLevel::options());

        $this->assertNull(YearLevel::parse(0));
        $this->assertNull(YearLevel::parse(5));
        $this->assertNull(YearLevel::parse('Y5'));
        $this->assertNull(YearLevel::parse('Year 5'));
        $this->assertNull(YearLevel::parse('2024-2025'));
        $this->assertNull(YearLevel::parse('2025-2026'));
        $this->assertNull(YearLevel::parse('2026–2027'));
    }

    public function test_student_crud_normalizes_year_level_and_stays_independent_from_academic_year_changes(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $dept = Department::create(['name' => 'Computer Science', 'code' => 'CS']);
        $ay1 = AcademicYear::create(['name' => '2025-2026', 'start_date' => '2025-10-01', 'end_date' => '2026-07-31', 'status' => 'active']);
        $program = Program::create(['department_id' => $dept->id, 'code' => 'BSCS', 'name' => 'B.Sc. CS', 'duration_years' => 4, 'status' => 'active']);
        $classY2 = ClassRoom::create([
            'department_id' => $dept->id,
            'academic_year_id' => $ay1->id,
            'name' => 'CS Y2-A',
            'code' => 'CS-Y2A',
            'year_level' => 2,
            'status' => 'active',
        ]);

        // Reject invalid Year 5
        $this->postJson('/api/students', [
            'student_id' => 'STDYL500',
            'english_name' => 'Invalid Year',
            'gender' => 'Male',
            'year_level' => 'Y5',
        ])->assertStatus(422);

        // Reject academic year passed as year_level
        $this->postJson('/api/students', [
            'student_id' => 'STDYL501',
            'english_name' => 'Confused Year',
            'gender' => 'Male',
            'year_level' => '2026-2027',
        ])->assertStatus(422);

        // Create using Y2 representation -> stored canonically as '2'
        $createRes = $this->postJson('/api/students', [
            'student_id' => 'STDYL200',
            'english_name' => 'Dara Sok',
            'gender' => 'Male',
            'department_id' => $dept->id,
            'class_id' => $classY2->id,
            'year_level' => 'Y2',
            'status' => 'enrolled',
        ])->assertStatus(201);

        $createRes->assertJsonPath('data.year_level', '2')
            ->assertJsonPath('data.year_level_code', 'Y2')
            ->assertJsonPath('data.year_level_name', 'Year 2');

        $student = Student::where('student_code', 'STDYL200')->firstOrFail();
        $student->update(['program_id' => $program->id]);

        // Adding and activating a new Academic Year MUST NOT alter student's year_level
        $ay1->update(['status' => 'completed']);
        AcademicYear::create(['name' => '2027-2028', 'start_date' => '2027-10-01', 'end_date' => '2028-07-31', 'status' => 'active']);

        $this->assertSame('2', (string) $student->fresh()->year_level);

        // Student cannot self-modify year_level
        Sanctum::actingAs($student->user);
        $this->putJson("/api/students/{$student->id}", ['year_level' => 'Y4'])->assertStatus(403);
        $this->putJson('/api/profile', ['year_level' => '4'])->assertStatus(422);
        $this->assertSame('2', (string) $student->fresh()->year_level);
    }

    public function test_classes_courses_and_bulk_enrollment_enforce_and_separate_year_level_and_academic_year(): void
    {
        $admin = $this->makeAdmin('yl.enroll@example.test');
        Sanctum::actingAs($admin);

        $dept = Department::create(['name' => 'Software Engineering', 'code' => 'SE']);
        $ay = AcademicYear::create(['name' => '2026-2027', 'start_date' => '2026-10-01', 'end_date' => '2027-07-31', 'status' => 'active']);
        $sem = Semester::create(['academic_year_id' => $ay->id, 'name' => 'Semester 1', 'semester_number' => 1, 'start_date' => '2026-10-01', 'end_date' => '2027-02-28', 'status' => 'active']);
        $program = Program::create(['department_id' => $dept->id, 'code' => 'BSE', 'name' => 'B.Sc. SE', 'duration_years' => 4, 'status' => 'active']);

        $teacherUser = User::create(['name' => 'Prof. Vanna', 'email' => 'vanna@example.test', 'password' => 'password', 'role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_code' => 'TCH701', 'first_name' => 'Vanna', 'last_name' => 'Chea', 'department_id' => $dept->id, 'status' => 'active']);

        // Reject invalid Year 5 on class and course
        $this->postJson('/api/classes', [
            'department_id' => $dept->id,
            'academic_year_id' => $ay->id,
            'name' => 'Bad Class',
            'code' => 'SE-Y5',
            'year_level' => 5,
        ])->assertStatus(422);

        $this->postJson('/api/courses', [
            'department_id' => $dept->id,
            'code' => 'SE505',
            'name' => 'Invalid Year Course',
            'credits' => 3,
            'year_level' => 'Y5',
        ])->assertStatus(422);

        $classY2 = ClassRoom::create(['department_id' => $dept->id, 'academic_year_id' => $ay->id, 'teacher_id' => $teacher->id, 'name' => 'SE 2A', 'code' => 'SE-2A', 'year_level' => 2, 'status' => 'active']);
        $classY3 = ClassRoom::create(['department_id' => $dept->id, 'academic_year_id' => $ay->id, 'teacher_id' => $teacher->id, 'name' => 'SE 3A', 'code' => 'SE-3A', 'year_level' => 3, 'status' => 'active']);

        $courseY2 = Course::create(['department_id' => $dept->id, 'teacher_id' => $teacher->id, 'code' => 'SE201', 'name' => 'Data Structures', 'credits' => 3, 'year_level' => 2, 'status' => 'active']);
        $courseY3 = Course::create(['department_id' => $dept->id, 'teacher_id' => $teacher->id, 'code' => 'SE301', 'name' => 'Software Architecture', 'credits' => 3, 'year_level' => 3, 'status' => 'active']);

        CourseOffering::create([
            'course_id' => $courseY3->id,
            'class_id' => $classY3->id,
            'semester_id' => $sem->id,
            'academic_year_id' => $ay->id,
            'teacher_id' => $teacher->id,
            'study_year' => 3,
            'status' => 'active',
        ]);

        // Filter classes & courses by Y-code
        $this->getJson('/api/classes?year_level=Y2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'SE-2A')
            ->assertJsonPath('data.0.year_level_code', 'Y2');

        $this->getJson('/api/courses?year_level=Y3')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'SE301')
            ->assertJsonPath('data.0.year_level_code', 'Y3');

        $stuUser = User::create(['name' => 'Sophea Lim', 'email' => 'sophealim@example.test', 'password' => 'password', 'role' => 'student', 'status' => 'active']);
        $studentY2 = Student::create([
            'user_id' => $stuUser->id,
            'student_code' => 'STDUY2',
            'first_name' => 'Sophea',
            'last_name' => 'Lim',
            'english_name' => 'Sophea Lim',
            'gender' => 'Female',
            'date_of_birth' => '2005-02-02',
            'department_id' => $dept->id,
            'program_id' => $program->id,
            'class_id' => $classY2->id,
            'year_level' => 2,
            'status' => 'enrolled',
        ]);

        // Enrollment snapshot records year_level even when omitted
        $this->postJson('/api/course-enrollments', [
            'student_id' => $studentY2->id,
            'course_id' => $courseY2->id,
            'semester_id' => $sem->id,
        ])->assertStatus(201)->assertJsonPath('data.year_level', 2);

        // Eligible students check surfaces cross-year mismatch warning for Y2 student vs Y3 class
        $this->getJson("/api/admin/class-enrollments/classes/{$classY3->id}/students?search=STDUY2")
            ->assertOk()
            ->assertJsonPath('data.0.year_level_code', 'Y2')
            ->assertJsonPath('data.0.year_level_matches_class', false);

        // strict_year_level=true rejects mismatch
        $this->postJson("/api/admin/class-enrollments/classes/{$classY3->id}/bulk-enroll", [
            'student_ids' => [$studentY2->id],
            'strict_year_level' => true,
        ])->assertOk()->assertJsonPath('data.failed_count', 1);

        // Non-strict allows cross-year retake enrollment
        $this->postJson("/api/admin/class-enrollments/classes/{$classY3->id}/bulk-enroll", [
            'student_ids' => [$studentY2->id],
            'strict_year_level' => false,
        ])->assertOk()->assertJsonPath('data.enrolled_count', 1);
    }

    public function test_teacher_student_analytics_and_import_export_endpoints_handle_year_level_consistently(): void
    {
        Storage::fake('local');
        $admin = $this->makeAdmin('yl.analytics@example.test');
        Sanctum::actingAs($admin);

        $dept = Department::create(['name' => 'Information Technology', 'code' => 'IT']);
        $ay = AcademicYear::create(['name' => '2026-2027', 'start_date' => '2026-10-01', 'end_date' => '2027-07-31', 'status' => 'active']);
        $sem = Semester::create(['academic_year_id' => $ay->id, 'name' => 'Semester 1', 'semester_number' => 1, 'start_date' => '2026-10-01', 'end_date' => '2027-02-28', 'status' => 'active']);
        $program = Program::create(['department_id' => $dept->id, 'code' => 'BSIT', 'name' => 'B.Sc. IT', 'duration_years' => 4, 'status' => 'active']);

        $teacherUser = User::create(['name' => 'Prof. Rith', 'email' => 'rith@example.test', 'password' => 'password', 'role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_code' => 'TCH801', 'first_name' => 'Rith', 'last_name' => 'Phan', 'department_id' => $dept->id, 'status' => 'active']);

        $classY2 = ClassRoom::create(['department_id' => $dept->id, 'academic_year_id' => $ay->id, 'teacher_id' => $teacher->id, 'name' => 'IT 2A', 'code' => 'IT-2A', 'year_level' => 2, 'status' => 'active']);
        $course = Course::create(['department_id' => $dept->id, 'teacher_id' => $teacher->id, 'code' => 'IT202', 'name' => 'Web Systems', 'credits' => 3, 'year_level' => 2, 'status' => 'active']);
        $offering = CourseOffering::create(['course_id' => $course->id, 'class_id' => $classY2->id, 'semester_id' => $sem->id, 'academic_year_id' => $ay->id, 'teacher_id' => $teacher->id, 'study_year' => 2, 'status' => 'active']);

        $stuUser = User::create(['name' => 'Bopha Mao', 'email' => 'bopha.yl@example.test', 'password' => 'password', 'role' => 'student', 'status' => 'active']);
        $student = Student::create([
            'user_id' => $stuUser->id,
            'student_code' => 'STDIT200',
            'first_name' => 'Bopha',
            'last_name' => 'Mao',
            'english_name' => 'Bopha Mao',
            'date_of_birth' => '2005-03-10',
            'gender' => 'Female',
            'department_id' => $dept->id,
            'program_id' => $program->id,
            'class_id' => $classY2->id,
            'year_level' => 2,
            'status' => 'enrolled',
        ]);
        CourseEnrollment::create(['student_id' => $student->id, 'course_id' => $course->id, 'course_offering_id' => $offering->id, 'semester_id' => $sem->id, 'year_level' => 2, 'status' => 'enrolled']);

        // Admin Reports Lookups & Overview distribution
        $this->getJson('/api/admin/reports-analytics/lookups')
            ->assertOk()
            ->assertJsonCount(4, 'data.year_levels');

        $this->getJson('/api/admin/reports-analytics/overview?year_level=Y2')
            ->assertOk()
            ->assertJsonPath('data.metrics.total_students', 1)
            ->assertJsonPath('data.student_enrollment_roster.0.year_level_code', 'Y2');

        // Import preview accepts Y2/Khmer and rejects Academic Year in year_level column
        $cols = StudentImportValidationService::COLUMNS;
        $validRow = [
            'khmer_name' => 'ដារ៉ា', 'english_name' => 'Import Valid', 'gender' => 'Male',
            'date_of_birth' => '2005-01-01', 'place_of_birth' => 'PP', 'current_address' => 'PP',
            'student_identifier' => 'STDIMPY2', 'password' => '',
            'father_khmer_name' => '', 'father_english_name' => '', 'father_phone' => '',
            'mother_khmer_name' => '', 'mother_english_name' => '', 'mother_phone' => '',
            'emergency_contact_name' => '', 'emergency_relationship' => '', 'emergency_contact_phone' => '', 'emergency_contact_address' => '',
            'department_code' => 'IT', 'program_code' => 'BSIT',
            'year_level' => 'Y3', 'academic_year_code' => '2026-2027', 'semester_code' => '1',
        ];
        $invalidRow = array_merge($validRow, ['student_identifier' => 'STDIMPAY', 'year_level' => '2026-2027']);
        $csvContent = implode(',', $cols) . "\n"
            . implode(',', array_map(fn ($c) => '"' . ($validRow[$c] ?? '') . '"', $cols)) . "\n"
            . implode(',', array_map(fn ($c) => '"' . ($invalidRow[$c] ?? '') . '"', $cols)) . "\n";
        $csvFile = UploadedFile::fake()->createWithContent('yl_test.csv', $csvContent);

        $this->post('/api/admin/students/import/preview', ['file' => $csvFile], ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->assertJsonPath('data.valid_rows', 1)
            ->assertJsonPath('data.invalid_rows', 1);

        // Export outputs canonical Y2 format
        $exportRes = $this->post('/api/admin/students/export', ['format' => 'csv', 'year_level' => 'Y2']);
        $exportRes->assertOk();
        $csvOut = $exportRes->streamedContent();
        $this->assertStringContainsString('STDIT200', $csvOut);
        $this->assertStringContainsString('Y2', $csvOut);

        // Teacher filters & student list
        Sanctum::actingAs($teacherUser);
        $filtersRes = $this->getJson('/api/teacher/filters')->assertOk();
        $studyYears = $filtersRes->json('data.study_years');
        $this->assertNotEmpty($studyYears);
        $this->assertContains(2, array_column($studyYears, 'year'));
        foreach ($studyYears as $sy) {
            $this->assertGreaterThanOrEqual(1, (int) ($sy['year'] ?? 0));
            $this->assertLessThanOrEqual(4, (int) ($sy['year'] ?? 0));
        }

        $this->getJson('/api/teacher/students?year_level=Y2')
            ->assertOk()
            ->assertJsonPath('data.students.0.student_code', 'STDIT200')
            ->assertJsonPath('data.students.0.year_level_code', 'Y2');

        // Student profile, dashboard & academic journey
        Sanctum::actingAs($stuUser);
        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('profile.profile.year_level_code', 'Y2')
            ->assertJsonPath('profile.profile.academic_year', '2026-2027')
            ->assertJsonPath('user.student.year_level_code', 'Y2')
            ->assertJsonPath('user.student.academic_year', '2026-2027');

        $this->getJson('/api/dashboard/student')
            ->assertOk()
            ->assertJsonPath('data.student.year_level_code', 'Y2')
            ->assertJsonPath('data.student.academic_year', '2026-2027');

        $this->getJson('/api/student/module/academic-journey')
            ->assertOk()
            ->assertJsonPath('data.0.year_level_code', 'Y2');
    }
}
