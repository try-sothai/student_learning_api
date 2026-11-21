<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\Program;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\StudentImportValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FlexibleNameEmailDateValidationTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;
    private Department $department;
    private Program $program;
    private AcademicYear $academicYear;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->department = Department::create([
            'name' => 'Computer Science',
            'code' => 'CS',
            'status' => 'active',
        ]);

        $this->program = Program::create([
            'department_id' => $this->department->id,
            'code' => 'BSCS',
            'name' => 'Bachelor of Computer Science',
            'duration_years' => 4,
            'status' => 'active',
        ]);

        $this->academicYear = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-10-01',
            'end_date' => '2027-07-31',
            'status' => 'active',
        ]);

        $admin = User::create([
            'name' => 'System Admin',
            'email' => 'admin.forms@slas.com',
            'password' => Hash::make('Admin@12345'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        Admin::create([
            'user_id' => $admin->id,
            'admin_code' => 'ADM001',
            'first_name' => 'System',
            'last_name' => 'Admin',
            'status' => 'active',
        ]);

        $this->adminToken = $this->postJson('/api/login', [
            'login_id' => 'ADM001',
            'password' => 'Admin@12345',
        ])->assertOk()->json('token');
    }

    public function test_student_creation_allows_khmer_only_english_only_both_and_optional_email_and_date(): void
    {
        // 1. Khmer name only, no email, no date_of_birth
        $resKhmer = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/admin/users', [
                'role' => 'student',
                'student_code' => 'STD_KH_01',
                'khmer_name' => 'សុខ សុធា',
                'gender' => 'Male',
                'department_id' => $this->department->id,
                'program_id' => $this->program->id,
                'year_level' => 1,
                'academic_year_id' => $this->academicYear->id,
            ]);

        $resKhmer->assertCreated()
            ->assertJsonPath('data.khmer_name', 'សុខ សុធា')
            ->assertJsonPath('data.english_name', '')
            ->assertJsonPath('data.name', 'សុខ សុធា')
            ->assertJsonPath('data.email', '');

        $stuKhmer = Student::where('student_code', 'STD_KH_01')->firstOrFail();
        $this->assertSame('សុខ សុធា', $stuKhmer->khmer_name);
        $this->assertNull($stuKhmer->english_name);
        $this->assertNull($stuKhmer->date_of_birth);
        $this->assertNull($stuKhmer->user->email);

        // 2. English name only, non-Gmail email, valid ISO date_of_birth
        $resEnglish = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/admin/users', [
                'role' => 'student',
                'student_code' => 'STD_EN_02',
                'english_name' => 'Try Sothai',
                'email' => 'sothai@university.edu.kh',
                'date_of_birth' => '2005-03-19',
                'gender' => 'Female',
                'department_id' => $this->department->id,
                'program_id' => $this->program->id,
                'year_level' => 2,
                'academic_year_id' => $this->academicYear->id,
            ]);

        $resEnglish->assertCreated()
            ->assertJsonPath('data.english_name', 'Try Sothai')
            ->assertJsonPath('data.khmer_name', '')
            ->assertJsonPath('data.name', 'Try Sothai')
            ->assertJsonPath('data.email', 'sothai@university.edu.kh')
            ->assertJsonPath('data.date_of_birth', '2005-03-19');

        $stuEng = Student::where('student_code', 'STD_EN_02')->firstOrFail();
        $this->assertNull($stuEng->khmer_name);
        $this->assertSame('Try Sothai', $stuEng->english_name);

        // 3. Both names empty / whitespace-only -> rejected 422
        $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/admin/users', [
                'role' => 'student',
                'student_code' => 'STD_NONE_03',
                'khmer_name' => '   ',
                'english_name' => '',
            ])->assertStatus(422);

        // 4. Invalid non-empty email -> rejected 422; invalid DOB -> rejected 422
        $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/admin/users', [
                'role' => 'student',
                'student_code' => 'STD_BAD_04',
                'english_name' => 'Valid Name',
                'email' => 'not-an-email',
            ])->assertStatus(422);

        $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/admin/users', [
                'role' => 'student',
                'student_code' => 'STD_BAD_05',
                'english_name' => 'Valid Name',
                'date_of_birth' => '19/03/2005',
            ])->assertStatus(422);
    }

    public function test_student_update_allows_clearing_email_or_date_and_switching_name_language(): void
    {
        $create = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/admin/users', [
                'role' => 'student',
                'student_code' => 'STD_EDIT_01',
                'khmer_name' => 'តេស្ត',
                'english_name' => 'Test Edit',
                'email' => 'editme@domain.org',
                'date_of_birth' => '2004-08-15',
            ])->assertCreated();

        $userId = $create->json('data.id');

        // Clear English name (keep Khmer only), clear email, clear DOB
        $upd = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->putJson("/api/admin/users/{$userId}", [
                'khmer_name' => 'តេស្ត ប៉ុណ្ណោះ',
                'english_name' => '',
                'email' => '',
                'date_of_birth' => '',
            ]);

        $upd->assertOk()
            ->assertJsonPath('data.khmer_name', 'តេស្ត ប៉ុណ្ណោះ')
            ->assertJsonPath('data.english_name', '')
            ->assertJsonPath('data.name', 'តេស្ត ប៉ុណ្ណោះ')
            ->assertJsonPath('data.email', '')
            ->assertJsonPath('data.date_of_birth', '');

        $stuCleared = Student::where('student_code', 'STD_EDIT_01')->firstOrFail();
        $this->assertNull($stuCleared->date_of_birth);

        // Clearing BOTH names must fail 422
        $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->putJson("/api/admin/users/{$userId}", [
                'khmer_name' => '',
                'english_name' => '   ',
            ])->assertStatus(422);
    }

    public function test_teacher_creation_and_update_with_flexible_names_optional_email_and_dates(): void
    {
        // 1. Khmer only teacher, no email, no DOB, with hire_date -> still provisions login TCH@12345
        $resKh = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/teachers', [
                'teacher_id' => 'TCH_KH_01',
                'khmer_name' => 'សាស្ត្រាចារ្យ តេស្ត',
                'hire_date' => '2024-09-01',
                'department' => 'Computer Science',
                'position' => 'Lecturer',
                'work_type' => 'Full-time',
                'status' => 'active',
            ]);

        $resKh->assertCreated()
            ->assertJsonPath('login_id', 'TCH_KH_01')
            ->assertJsonPath('initial_password', 'TCH@12345')
            ->assertJsonPath('data.khmer_name', 'សាស្ត្រាចារ្យ តេស្ត')
            ->assertJsonPath('data.english_name', '')
            ->assertJsonPath('data.name', 'សាស្ត្រាចារ្យ តេស្ត')
            ->assertJsonPath('data.hire_date', '2024-09-01')
            ->assertJsonPath('data.email', '');

        $tchKh = Teacher::with('user')->where('teacher_code', 'TCH_KH_01')->firstOrFail();
        $this->assertNull($tchKh->english_name);
        $this->assertSame('សាស្ត្រាចារ្យ តេស្ត', $tchKh->khmer_name);
        $this->assertNull($tchKh->user->email);

        // Verify login works with Teacher ID + TCH@12345 even with no email & Khmer-only name
        $this->postJson('/api/login', [
            'login_id' => 'TCH_KH_01',
            'password' => 'TCH@12345',
        ])->assertOk()->assertJsonPath('user.role', 'teacher');

        // 2. Neither name -> 422
        $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/teachers', [
                'teacher_id' => 'TCH_NONE',
                'khmer_name' => '',
                'english_name' => '  ',
            ])->assertStatus(422);

        // 3. Update via /api/teachers/{id}: set English name, valid email, DOB, clear hire_date
        $upd = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->putJson("/api/teachers/{$tchKh->id}", [
                'khmer_name' => 'សាស្ត្រាចារ្យ តេស្ត',
                'english_name' => 'Prof. Sokha',
                'email' => 'sokha@institute.edu.kh',
                'date_of_birth' => '1988-11-20',
                'hire_date' => '',
            ]);

        $upd->assertOk()
            ->assertJsonPath('data.name', 'Prof. Sokha')
            ->assertJsonPath('data.english_name', 'Prof. Sokha')
            ->assertJsonPath('data.khmer_name', 'សាស្ត្រាចារ្យ តេស្ត')
            ->assertJsonPath('data.email', 'sokha@institute.edu.kh')
            ->assertJsonPath('data.date_of_birth', '1988-11-20')
            ->assertJsonPath('data.hire_date', '');

        $tchKhFresh = $tchKh->fresh();
        $this->assertNull($tchKhFresh->hire_date);
    }

    public function test_student_import_allows_khmer_only_english_only_and_optional_email_and_dob(): void
    {
        $cols = StudentImportValidationService::COLUMNS;
        $makeRow = fn(array $override) => implode(',', array_map(
            fn($col) => '"' . str_replace('"', '""', (string) ($override[$col] ?? '')) . '"',
            $cols
        ));

        $rows = [
            // Khmer-only, no email, no DOB -> VALID
            [
                'khmer_name' => 'សុខ សុធា',
                'english_name' => '',
                'email' => '',
                'gender' => 'Male',
                'date_of_birth' => '',
                'student_identifier' => 'IMP_KH_01',
                'father_english_name' => 'Father A',
                'father_phone' => '012345678',
                'emergency_contact_name' => 'Contact A',
                'emergency_relationship' => 'Parent',
                'emergency_contact_phone' => '098765432',
                'department_code' => 'CS',
                'program_code' => 'BSCS',
                'year_level' => '1',
                'academic_year_code' => '2026-2027',
            ],
            // English-only, non-Gmail email, valid DOB -> VALID
            [
                'khmer_name' => '',
                'english_name' => 'Sok Dara',
                'email' => 'dara@company.org',
                'gender' => 'Female',
                'date_of_birth' => '2005-06-09',
                'student_identifier' => 'IMP_EN_02',
                'father_english_name' => 'Father B',
                'father_phone' => '012345679',
                'emergency_contact_name' => 'Contact B',
                'emergency_relationship' => 'Parent',
                'emergency_contact_phone' => '098765433',
                'department_code' => 'CS',
                'program_code' => 'BSCS',
                'year_level' => '1',
                'academic_year_code' => '2026-2027',
            ],
            // Neither name -> INVALID
            [
                'khmer_name' => '',
                'english_name' => '',
                'gender' => 'Male',
                'student_identifier' => 'IMP_BAD_03',
                'father_english_name' => 'Father C',
                'father_phone' => '012345670',
                'emergency_contact_name' => 'Contact C',
                'emergency_relationship' => 'Parent',
                'emergency_contact_phone' => '098765434',
                'department_code' => 'CS',
                'program_code' => 'BSCS',
                'year_level' => '1',
                'academic_year_code' => '2026-2027',
            ],
        ];

        $csv = "\xEF\xBB\xBF" . implode(',', $cols) . "\n" . implode("\n", array_map($makeRow, $rows));
        $file = UploadedFile::fake()->createWithContent('flexible_students.csv', $csv);

        $preview = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->post('/api/admin/students/import/preview', ['file' => $file]);

        $preview->assertCreated()
            ->assertJsonPath('data.valid_rows', 2)
            ->assertJsonPath('data.invalid_rows', 1);

        $importId = $preview->json('data.id');

        $confirm = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/students/import/{$importId}/confirm");

        $confirm->assertStatus(202);

        $s1 = Student::with('user')->where('student_code', 'IMP_KH_01')->firstOrFail();
        $this->assertSame('សុខ សុធា', $s1->khmer_name);
        $this->assertNull($s1->english_name);
        $this->assertNull($s1->user->email);
        $this->assertNull($s1->date_of_birth);

        $s2 = Student::with('user')->where('student_code', 'IMP_EN_02')->firstOrFail();
        $this->assertNull($s2->khmer_name);
        $this->assertSame('Sok Dara', $s2->english_name);
        $this->assertSame('dara@company.org', $s2->user->email);
        $this->assertSame('2005-06-09', $s2->date_of_birth?->format('Y-m-d'));
    }

    public function test_teacher_import_allows_khmer_only_english_only_optional_email_and_dates(): void
    {
        $csv = "teacher_id,khmer_name,english_name,email,department,position,work_type,status,date_of_birth,hire_date\n"
            . "TCH_IMP_KH,គ្រូ ខ្មែរ,,,Computer Science,Lecturer,Full-time,active,,2025-01-10\n"
            . "TCH_IMP_EN,,Teacher English,ten@school.kh,Computer Science,Lecturer,Full-time,active,1990-02-14,\n"
            . "TCH_IMP_NONE,,,,Computer Science,Lecturer,Full-time,active,,\n";

        $file = UploadedFile::fake()->createWithContent('flexible_teachers.csv', $csv);

        $res = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->post('/api/admin/teachers/import', ['file' => $file]);

        $res->assertStatus(207)
            ->assertJsonPath('data.imported_count', 2)
            ->assertJsonPath('data.failed_count', 1);

        $tKh = Teacher::with('user')->where('teacher_code', 'TCH_IMP_KH')->firstOrFail();
        $this->assertSame('គ្រូ ខ្មែរ', $tKh->khmer_name);
        $this->assertNull($tKh->english_name);
        $this->assertNull($tKh->user->email);
        $this->assertSame('2025-01-10', $tKh->hire_date?->format('Y-m-d'));

        $tEn = Teacher::with('user')->where('teacher_code', 'TCH_IMP_EN')->firstOrFail();
        $this->assertNull($tEn->khmer_name);
        $this->assertSame('Teacher English', $tEn->english_name);
        $this->assertSame('ten@school.kh', $tEn->user->email);
        $this->assertSame('1990-02-14', $tEn->date_of_birth?->format('Y-m-d'));
    }
}
