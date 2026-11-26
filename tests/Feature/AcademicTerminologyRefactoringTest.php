<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Department;
use App\Models\Program;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\StudentImportValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AcademicTerminologyRefactoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_views_and_localization_use_faculty_and_major_labels(): void
    {
        $this->seed();

        $adminUser = User::where('email', 'admin@slas.com')->firstOrFail();
        $teacherUser = User::where('email', 'teacher@slas.com')->firstOrFail();
        $studentUser = User::where('email', 'student@slas.com')->firstOrFail();

        $teacher = Teacher::where('user_id', $teacherUser->id)->firstOrFail();
        $student = Student::where('user_id', $studentUser->id)->firstOrFail();

        $ay = AcademicYear::orderByDesc('id')->firstOrFail();
        $semester = Semester::where('academic_year_id', $ay->id)->firstOrFail();
        $course = Course::where('teacher_id', $teacher->id)->first()
            ?? Course::create([
                'department_id' => $student->department_id,
                'teacher_id' => $teacher->id,
                'code' => 'CS901',
                'name' => 'Terminology Audit Course',
                'credits' => 3,
                'status' => 'active',
            ]);

        CourseEnrollment::firstOrCreate(
            ['student_id' => $student->id, 'course_id' => $course->id],
            ['semester_id' => $semester->id, 'enrollment_date' => now()->toDateString(), 'status' => 'enrolled']
        );

        $this->actingAs($studentUser)->get(route('student.profile'))
            ->assertOk()
            ->assertSee('Faculty')
            ->assertSee('Major');

        $this->actingAs($studentUser)->get(route('student.profile.edit'))
            ->assertOk()
            ->assertSee('Faculty')
            ->assertSee('Major');

        $this->actingAs($studentUser)->get(route('student.settings'))
            ->assertOk()
            ->assertSee('Faculty')
            ->assertSee('Major');

        $this->actingAs($studentUser)->get(route('student.courses'))
            ->assertOk()
            ->assertSee('Faculty');

        $this->actingAs($teacherUser)->get(route('teacher.students.show', $student))
            ->assertOk()
            ->assertSee('Faculty')
            ->assertSee('Major');

        $this->actingAs($teacherUser)->get(route('teacher.courses.show', $course))
            ->assertOk()
            ->assertSee('Faculty');

        $this->actingAs($adminUser)->get(route('admin.students'))
            ->assertOk()
            ->assertSee('Faculty')
            ->assertSee('All faculties')
            ->assertSee('Major');

        $this->actingAs($adminUser)->get(route('admin.teachers'))
            ->assertOk()
            ->assertSee('Faculty');

        $this->actingAs($adminUser)->get(route('admin.courses'))
            ->assertOk()
            ->assertSee('Faculty');

        $this->actingAs($adminUser)->get(route('admin.students.show', $student))
            ->assertOk()
            ->assertSee('Faculty')
            ->assertSee('Major');

        $this->actingAs($adminUser)->get(route('admin.students.edit', $student))
            ->assertOk()
            ->assertSee('Faculty')
            ->assertSee('Major');

        $this->actingAs($adminUser)->get(route('admin.teachers.show', $teacher))
            ->assertOk()
            ->assertSee('Faculty');

        $this->actingAs($adminUser)->get(route('admin.teachers.edit', $teacher))
            ->assertOk()
            ->assertSee('Faculty');

        $this->actingAs($adminUser)->get(route('admin.courses.show', $course))
            ->assertOk()
            ->assertSee('Faculty');

        $this->actingAs($adminUser)->get(route('admin.courses.edit', $course))
            ->assertOk()
            ->assertSee('Faculty');

        app()->setLocale('km');
        $this->assertSame('មហាវិទ្យាល័យ', __('Faculty'));
        $this->assertSame('មហាវិទ្យាល័យ', __('Faculties'));
        $this->assertSame('ជំនាញ', __('Major'));
        $this->assertSame('ជំនាញ', __('Majors'));
    }

    public function test_lookups_expose_backward_compatible_and_faculty_major_arrays(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@slas.com')->firstOrFail();
        Sanctum::actingAs($admin);

        $res = $this->getJson('/api/admin/students/lookups')->assertOk();
        $data = $res->json('data');

        $this->assertNotEmpty($data['departments']);
        $this->assertNotEmpty($data['programs']);
        $this->assertSame($data['departments'], $data['faculties']);
        $this->assertSame($data['programs'], $data['majors']);
    }

    public function test_student_create_and_update_validate_faculty_major_compatibility_and_support_aliases(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@slas.com')->firstOrFail();
        Sanctum::actingAs($admin);

        $cs = Department::where('code', 'CS')->firstOrFail();
        $it = Department::where('code', 'IT')->firstOrFail();
        $ba = Department::where('code', 'BA')->firstOrFail();
        $ds = Department::firstOrCreate(['code' => 'DS'], ['name' => 'Data Science']);

        $bscs = Program::where('code', 'BSCS')->firstOrFail();
        $bba = Program::where('code', 'BBA')->firstOrFail();
        $ay = AcademicYear::orderByDesc('id')->firstOrFail();

        $basePayload = [
            'english_name' => 'Faculty Major Student',
            'gender' => 'Male',
            'date_of_birth' => '2005-05-05',
            'student_identifier' => 'STDFAC001',
            'year_level' => 1,
            'academic_year_id' => $ay->id,
        ];

        // Invalid BA faculty + BSCS major rejected
        $this->postJson('/api/admin/students', array_merge($basePayload, [
            'faculty_id' => $ba->id,
            'major_id' => $bscs->id,
        ]))->assertStatus(422);

        // Faculty with zero majors paired with CS major rejected
        $this->postJson('/api/admin/students', array_merge($basePayload, [
            'faculty_id' => $ds->id,
            'major_id' => $bscs->id,
        ]))->assertStatus(422);

        // Valid CS/IT computing compatibility via faculty_id/major_id aliases succeeds
        $createRes = $this->postJson('/api/admin/students', array_merge($basePayload, [
            'faculty_id' => $it->id,
            'major_id' => $bscs->id,
        ]))->assertCreated();

        $createdUserId = $createRes->json('data.id');
        $this->assertNotNull($createdUserId);

        // Update rejecting incompatible Faculty + Major pair via canonical and alias payloads
        $this->putJson("/api/admin/users/{$createdUserId}", [
            'english_name' => 'Faculty Major Student',
            'department_id' => $ba->id,
            'program_id' => $bscs->id,
        ])->assertStatus(422);

        $this->putJson("/api/admin/users/{$createdUserId}", [
            'english_name' => 'Faculty Major Student',
            'faculty_id' => $cs->id,
            'major_id' => $bba->id,
        ])->assertStatus(422);

        // Valid update via faculty_id + major_id persists and exposes both canonical and alias payload keys
        $this->putJson("/api/admin/users/{$createdUserId}", [
            'english_name' => 'Faculty Major Student Updated',
            'faculty_id' => $ba->id,
            'major_id' => $bba->id,
        ])->assertOk()
            ->assertJsonPath('data.department_id', $ba->id)
            ->assertJsonPath('data.faculty_id', $ba->id)
            ->assertJsonPath('data.faculty_name', $ba->name)
            ->assertJsonPath('data.program_id', $bba->id)
            ->assertJsonPath('data.major_id', $bba->id)
            ->assertJsonPath('data.major_name', $bba->name);
    }

    public function test_user_and_teacher_filters_support_faculty_aliases_and_all_faculties(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@slas.com')->firstOrFail();
        $cs = Department::where('code', 'CS')->firstOrFail();

        Sanctum::actingAs($admin);

        $allUsers = $this->getJson('/api/admin/users?role=teacher&faculty=All+Faculties')->assertOk();
        $this->assertNotEmpty($allUsers->json('data'));

        $this->getJson('/api/admin/users?role=teacher&faculty_id=' . $cs->id)->assertOk();
        $this->getJson('/api/teachers?faculty=All+Faculties')->assertOk();
        $this->getJson('/api/teachers?faculty_id=' . $cs->id)->assertOk();
    }

    public function test_student_import_preview_supports_faculty_major_aliases_and_flags_mismatched_pair(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@slas.com')->firstOrFail();
        $ayName = AcademicYear::orderByDesc('id')->value('name');
        Sanctum::actingAs($admin);

        $headers = array_map(function (string $col) {
            return match ($col) {
                'department_code' => 'faculty_code',
                'program_code' => 'major_code',
                default => $col,
            };
        }, StudentImportValidationService::COLUMNS);

        $validRow = [
            'khmer_name' => 'សិស្សតេស្ត',
            'english_name' => 'Alias Import Ok',
            'gender' => 'Female',
            'date_of_birth' => '2004-04-04',
            'place_of_birth' => 'Phnom Penh',
            'current_address' => 'Phnom Penh',
            'student_identifier' => 'STDFMIMP1',
            'password' => '',
            'father_khmer_name' => '',
            'father_english_name' => '',
            'father_phone' => '',
            'mother_khmer_name' => '',
            'mother_english_name' => '',
            'mother_phone' => '',
            'emergency_contact_name' => '',
            'emergency_relationship' => '',
            'emergency_contact_phone' => '',
            'emergency_contact_address' => '',
            'faculty_code' => 'CS',
            'major_code' => 'BSCS',
            'year_level' => 'Y1',
            'academic_year_code' => $ayName,
            'semester_code' => '1',
        ];
        $invalidRow = array_merge($validRow, [
            'student_identifier' => 'STDFMIMP2',
            'faculty_code' => 'BA',
            'major_code' => 'BSCS',
        ]);

        $csvContent = implode(',', $headers) . "\n"
            . implode(',', array_map(fn ($h) => '"' . ($validRow[$h] ?? '') . '"', $headers)) . "\n"
            . implode(',', array_map(fn ($h) => '"' . ($invalidRow[$h] ?? '') . '"', $headers)) . "\n";

        $file = UploadedFile::fake()->createWithContent('faculty_major_alias.csv', $csvContent);

        $this->post('/api/admin/students/import/preview', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->assertJsonPath('data.valid_rows', 1)
            ->assertJsonPath('data.invalid_rows', 1)
            ->assertJsonPath('data.invalid_department_program_rows', 1);
    }

    public function test_reports_analytics_uses_faculty_and_major_filters_and_export_headers(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@slas.com')->firstOrFail();
        $cs = Department::where('code', 'CS')->firstOrFail();
        $bscs = Program::where('code', 'BSCS')->firstOrFail();

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/reports-analytics/overview?faculty_id=' . $cs->id . '&major_id=' . $bscs->id)
            ->assertOk();

        $csvRes = $this->post('/api/admin/reports-analytics/export', ['category' => 'student', 'format' => 'csv']);
        $csvRes->assertOk();
        $csvOut = $csvRes->streamedContent();
        $this->assertStringContainsString('Faculty', $csvOut);
        $this->assertStringContainsString('Major', $csvOut);
    }
}
