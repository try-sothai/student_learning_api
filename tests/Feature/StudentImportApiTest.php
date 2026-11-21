<?php

namespace Tests\Feature;

use App\Jobs\StudentImportJob;
use App\Models\Student;
use App\Models\StudentImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class StudentImportApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private string $deptCode;
    private string $programCode;
    private string $yearName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        config(['student_import.chunk_size' => 2]);
        $this->admin = User::where('email', 'admin@slas.com')->firstOrFail();

        $dept = DB::table('departments')->orderBy('id')->first();
        $this->deptCode = $dept->code;
        $this->programCode = 'TESTPRG';
        DB::table('programs')->insert([
            'department_id' => $dept->id, 'code' => $this->programCode, 'name' => 'Test Program',
            'duration_years' => 4, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->yearName = DB::table('academic_years')->orderByDesc('id')->value('name');
    }

    private function row(string $id, array $over = []): array
    {
        return array_merge([
            'khmer_name' => 'សិស្ស', 'english_name' => 'Test Student ' . $id, 'gender' => 'Male',
            'date_of_birth' => '2004-05-17', 'place_of_birth' => 'Town', 'current_address' => 'Addr',
            'student_identifier' => $id, 'password' => '',
            'father_khmer_name' => '', 'father_english_name' => 'Dad', 'father_phone' => '012345678',
            'mother_khmer_name' => '', 'mother_english_name' => '', 'mother_phone' => '',
            'emergency_contact_name' => 'Contact', 'emergency_relationship' => 'Parent',
            'emergency_contact_phone' => '098765432', 'emergency_contact_address' => '',
            'department_code' => $this->deptCode, 'program_code' => $this->programCode,
            'year_level' => '1', 'academic_year_code' => $this->yearName, 'semester_code' => '',
        ], $over);
    }

    private function csvFile(array $rows, string $name = 'students.csv'): UploadedFile
    {
        $cols = \App\Services\StudentImportValidationService::COLUMNS;
        $lines = [implode(',', $cols)];
        foreach ($rows as $r) {
            $lines[] = implode(',', array_map(fn ($c) => '"' . str_replace('"', '""', $r[$c] ?? '') . '"', $cols));
        }

        return UploadedFile::fake()->createWithContent($name, implode("\n", $lines));
    }

    private function xlsxFile(array $rows): UploadedFile
    {
        $cols = \App\Services\StudentImportValidationService::COLUMNS;
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray($cols, null, 'A1');
        foreach ($rows as $i => $r) {
            $sheet->fromArray(array_map(fn ($c) => (string) ($r[$c] ?? ''), $cols), null, 'A' . ($i + 2));
        }
        $path = tempnam(sys_get_temp_dir(), 'tst') . '.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'students.xlsx', null, null, true);
    }

    private function preview(UploadedFile $f)
    {
        return $this->actingAs($this->admin)->post('/api/admin/students/import/preview', ['file' => $f], ['Accept' => 'application/json']);
    }

    public function test_templates_download(): void
    {
        $x = $this->actingAs($this->admin)->get('/api/admin/students/import/template');
        $x->assertOk();
        $this->assertStringContainsString('spreadsheetml', $x->headers->get('content-type'));

        $csv = $this->actingAs($this->admin)->get('/api/admin/students/import/template-csv');
        $csv->assertOk();
        $this->assertStringContainsString('student_identifier', $csv->streamedContent());
        $this->assertStringNotContainsString('username', explode("\n", $csv->streamedContent())[0]);
    }

    public function test_unauthorized_access_is_blocked(): void
    {
        $this->getJson('/api/admin/students/imports')->assertStatus(401);
        $teacher = User::where('email', 'teacher@slas.com')->firstOrFail();
        $this->actingAs($teacher)->getJson('/api/admin/students/imports')->assertStatus(403);
        $this->actingAs($teacher)->getJson('/api/admin/students/import/template')->assertStatus(403);
        $this->actingAs($teacher)->getJson('/api/admin/students/export')->assertStatus(403);
    }

    public function test_preview_valid_csv_does_not_save_students(): void
    {
        $before = Student::count();
        $res = $this->preview($this->csvFile([$this->row('bsr100001'), $this->row('bsr100002')]))->assertCreated();
        $res->assertJsonPath('data.total_rows', 2)->assertJsonPath('data.valid_rows', 2)
            ->assertJsonPath('data.invalid_rows', 0)->assertJsonPath('data.status', 'pending');
        $this->assertSame($before, Student::count());
        $this->assertDatabaseMissing('students', ['student_code' => 'bsr100001']);
    }

    public function test_preview_valid_xlsx(): void
    {
        $this->preview($this->xlsxFile([$this->row('bsr100010'), $this->row('bsr100011'), $this->row('bsr100012')]))
            ->assertCreated()->assertJsonPath('data.valid_rows', 3);
    }

    public function test_rejects_unsupported_and_oversized_files(): void
    {
        $this->preview(UploadedFile::fake()->createWithContent('evil.php', '<?php echo 1;'))->assertStatus(422);
        $this->preview(UploadedFile::fake()->createWithContent('x.txt', 'a,b'))->assertStatus(422);

        config(['student_import.max_file_kb' => 1]);
        $this->preview(UploadedFile::fake()->create('big.csv', 50, 'text/csv'))->assertStatus(422);
    }

    public function test_rejects_when_row_limit_exceeded_and_missing_columns(): void
    {
        config(['student_import.max_rows' => 2]);
        $this->preview($this->csvFile([$this->row('a1x'), $this->row('a2x'), $this->row('a3x')]))->assertStatus(422);
        $this->assertSame(0, StudentImport::count());

        config(['student_import.max_rows' => 100]);
        $this->preview(UploadedFile::fake()->createWithContent('s.csv', "name,email\nx,y"))->assertStatus(422);
    }

    public function test_validation_errors_and_duplicates_are_reported(): void
    {
        $existing = Student::firstOrFail();
        $rows = [
            $this->row('bsr200001'),
            $this->row('bsr200001'),                                  // duplicate inside file
            $this->row($existing->student_code),                      // duplicate in database
            $this->row('bsr200002', ['khmer_name' => '', 'english_name' => '']), // missing both names
            $this->row('bsr200003', ['gender' => 'robot']),
            $this->row('bsr200004', ['date_of_birth' => '31/02/2004']),
            $this->row('bsr200005', ['department_code' => 'NOPE']),
            $this->row('bsr200006', ['program_code' => 'NOPE']),
            $this->row('bsr200007', ['academic_year_code' => 'NOPE']),
            $this->row('bsr200008', ['year_level' => '99']),
            $this->row('bsr200009', ['father_phone' => 'abc']),
            $this->row('bsr200010', ['semester_code' => '9']),
        ];
        $res = $this->preview($this->csvFile($rows))->assertCreated();
        $res->assertJsonPath('data.total_rows', 12)->assertJsonPath('data.valid_rows', 1)
            ->assertJsonPath('data.duplicate_rows', 2)->assertJsonPath('data.invalid_rows', 9);

        $id = $res->json('data.id');
        $errors = $this->actingAs($this->admin)->getJson("/api/admin/students/imports/{$id}/errors?per_page=50")->assertOk();
        $this->assertSame(11, $errors->json('meta.total'));

        $report = $this->actingAs($this->admin)->get("/api/admin/students/imports/{$id}/error-report");
        $report->assertOk();
        $this->assertStringContainsString('At least one of khmer_name or english_name is required.', $report->streamedContent());
    }

    public function test_confirm_imports_students_with_hashed_passwords_and_login_works(): void
    {
        $rows = [
            $this->row('bsr300001', ['password' => 'MyPassw0rd!']),
            $this->row('bsr300002'),                  // generated password
            $this->row('bsr300003'),
            $this->row('bsr300004'),
            $this->row('bsr300005'),                  // 5 rows, chunk size 2 => 3 chunks
        ];
        $id = $this->preview($this->csvFile($rows))->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/admin/students/import/{$id}/confirm")->assertStatus(202);

        $status = $this->actingAs($this->admin)->getJson("/api/admin/students/import/{$id}/status")->assertOk();
        $status->assertJsonPath('data.status', 'completed')->assertJsonPath('data.success_rows', 5)
            ->assertJsonPath('data.failed_rows', 0)->assertJsonPath('data.progress_percent', 100);

        $student = Student::where('student_code', 'bsr300001')->firstOrFail();
        $this->assertSame('student', $student->user->role);
        $this->assertNull($student->user->email);
        $this->assertTrue(Hash::check('STD@12345', $student->user->password));
        $this->assertNotSame('STD@12345', $student->user->password);
        $this->assertSame('Dad', $student->familyInformation->father_english_name);
        $this->assertSame('Contact', $student->emergencyContacts->first()->name);
        $this->assertNotNull($student->program_id);
        $this->assertNotNull($student->class_id);

        // university identifier is the login (all imported students receive standardized STD@12345)
        $this->postJson('/api/login', ['login_id' => 'bsr300001', 'password' => 'STD@12345'])
            ->assertOk()->assertJsonPath('student_id', 'bsr300001');

        // generated credentials downloadable exactly once, never in reports
        $creds = $this->actingAs($this->admin)->get("/api/admin/students/imports/{$id}/credentials")->streamedContent();
        $this->assertStringContainsString('bsr300001', $creds);
        $this->assertStringContainsString('bsr300002', $creds);
        $this->assertStringContainsString('STD@12345', $creds);
        $line = explode(',', explode("\n", trim($creds))[1]);
        $temp = trim(end($line), "\" \r");
        $this->assertSame('STD@12345', $temp);
        $this->postJson('/api/login', ['login_id' => 'bsr300002', 'password' => $temp])->assertOk();
        $again = $this->actingAs($this->admin)->get("/api/admin/students/imports/{$id}/credentials")->streamedContent();
        $this->assertStringNotContainsString('bsr300002', $again);
        $this->assertStringNotContainsString($temp, $this->actingAs($this->admin)->get("/api/admin/students/imports/{$id}/successful-report")->streamedContent());

        // history
        $this->actingAs($this->admin)->getJson('/api/admin/students/imports?status=completed&search=students.csv')
            ->assertOk()->assertJsonPath('data.0.imported_by', $this->admin->name)->assertJsonPath('meta.total', 1);

        // second confirmation is rejected
        $this->actingAs($this->admin)->postJson("/api/admin/students/import/{$id}/confirm")->assertStatus(409);
    }

    public function test_partial_results_are_completed_with_errors(): void
    {
        $id = $this->preview($this->csvFile([$this->row('bsr400001'), $this->row('bsr400002', ['gender' => 'x'])]))->json('data.id');
        $this->actingAs($this->admin)->postJson("/api/admin/students/import/{$id}/confirm")->assertStatus(202);
        $this->actingAs($this->admin)->getJson("/api/admin/students/import/{$id}/status")
            ->assertJsonPath('data.status', 'completed_with_errors')->assertJsonPath('data.success_rows', 1)
            ->assertJsonPath('data.failed_rows', 1);
    }

    public function test_race_duplicate_at_confirm_is_not_overwritten(): void
    {
        $id = $this->preview($this->csvFile([$this->row('bsr500001')]))->json('data.id');

        // someone registers the same identifier between preview and confirm
        $u = new User();
        $u->forceFill(['name' => 'Existing', 'email' => 'ex@example.com', 'password' => Hash::make('x12345678'), 'role' => 'student', 'status' => 'active'])->save();
        Student::create(['user_id' => $u->id, 'student_code' => 'bsr500001', 'first_name' => 'Ex', 'last_name' => 'Ist', 'date_of_birth' => '2000-01-01', 'gender' => 'Male']);

        $this->actingAs($this->admin)->postJson("/api/admin/students/import/{$id}/confirm")->assertStatus(202);
        $this->assertSame(1, Student::where('student_code', 'bsr500001')->count());
        $this->assertSame('Ex', Student::where('student_code', 'bsr500001')->first()->first_name);
        $this->actingAs($this->admin)->getJson("/api/admin/students/import/{$id}/status")
            ->assertJsonPath('data.success_rows', 0)->assertJsonPath('data.skipped_rows', 1);
    }

    public function test_job_retry_is_idempotent_and_failure_is_reported(): void
    {
        Storage::fake('local');
        $import = StudentImport::create(['user_id' => $this->admin->id, 'original_filename' => 'x.csv', 'status' => 'pending']);
        $import->rows()->create([
            'row_number' => 2, 'student_identifier' => 'bsr600001', 'status' => 'valid',
            'data' => $this->resultData($this->row('bsr600001')),
        ]);
        $import->update(['total_rows' => 1, 'valid_rows' => 1]);

        $job = new StudentImportJob($import->id);
        $job->handle(app(\App\Services\StudentImportService::class));
        $job->handle(app(\App\Services\StudentImportService::class)); // retry
        $this->assertSame(1, Student::where('student_code', 'bsr600001')->count());

        $other = StudentImport::create(['user_id' => $this->admin->id, 'original_filename' => 'y.csv', 'status' => 'processing']);
        (new StudentImportJob($other->id))->failed(new \RuntimeException('worker crashed'));
        $this->assertSame('failed', $other->fresh()->status);
    }

    public function test_cancel_pending_import(): void
    {
        $id = $this->preview($this->csvFile([$this->row('bsr700001')]))->json('data.id');
        $this->actingAs($this->admin)->postJson("/api/admin/students/import/{$id}/cancel")->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->actingAs($this->admin)->postJson("/api/admin/students/import/{$id}/confirm")->assertStatus(409);
        $this->assertDatabaseMissing('students', ['student_code' => 'bsr700001']);
    }

    public function test_add_single_student_without_email(): void
    {
        $dept = DB::table('departments')->where('code', $this->deptCode)->first();
        $payload = [
            'khmer_name' => 'សិស្ស', 'english_name' => 'Single Student', 'gender' => 'Female',
            'date_of_birth' => '2003-03-03', 'student_identifier' => 'bsr800001',
            'department_id' => $dept->id, 'program_id' => DB::table('programs')->where('code', 'TESTPRG')->value('id'),
            'year_level' => 2, 'academic_year_id' => DB::table('academic_years')->orderByDesc('id')->value('id'),
        ];
        $res = $this->actingAs($this->admin)->postJson('/api/admin/students', $payload)->assertCreated()
            ->assertJsonPath('data.username', 'bsr800001')
            ->assertJsonPath('data.role', 'student')
            ->assertJsonPath('data.initial_password', 'STD@12345')
            ->assertJsonPath('data.temporary_password', 'STD@12345');
        $this->postJson('/api/login', ['login_id' => 'bsr800001', 'password' => 'STD@12345'])->assertOk();

        $this->actingAs($this->admin)->postJson('/api/admin/students', $payload)->assertStatus(422)
            ->assertJsonValidationErrors('student_identifier');
        $this->actingAs($this->admin)->postJson('/api/admin/students', ['english_name' => 'x'])->assertStatus(422);
    }

    public function test_export_filters_and_never_leaks_secrets(): void
    {
        $id = $this->preview($this->csvFile([$this->row('bsr900001'), $this->row('bsr900002', ['year_level' => '2'])]))->json('data.id');
        $this->actingAs($this->admin)->postJson("/api/admin/students/import/{$id}/confirm");

        $programId = DB::table('programs')->where('code', 'TESTPRG')->value('id');
        $csv = $this->actingAs($this->admin)->get("/api/admin/students/export?format=csv&program_id={$programId}&year_level=2")
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('bsr900002', $csv);
        $this->assertStringNotContainsString('bsr900001', $csv);
        $this->assertStringNotContainsString('password', strtolower($csv));
        $this->assertStringNotContainsString('$2y$', $csv);

        $this->actingAs($this->admin)->get("/api/admin/students/export?format=xlsx&program_id={$programId}")->assertOk();

        $studentId = Student::where('student_code', 'bsr900001')->value('id');
        $sel = $this->actingAs($this->admin)->postJson('/api/admin/students/export', ['format' => 'csv', 'ids' => [$studentId]])
            ->streamedContent();
        $this->assertStringContainsString('bsr900001', $sel);
        $this->assertStringNotContainsString('bsr900002', $sel);
    }

    public function test_imports_multi_column_addresses_and_computing_business_code_aliases(): void
    {
        $csvContent = implode("\n", [
            'khmer_name,english_name,gender,date_of_birth,place_of_birth_province,place_of_birth_district,place_of_birth_commune,place_of_birth_village,current_address_province,current_address_district,current_address_commune,current_address_village,student_identifier,password,father_khmer_name,father_english_name,father_phone,mother_khmer_name,mother_english_name,mother_phone,emergency_contact_name,emergency_contact_relationship,emergency_contact_phone,emergency_contact_address,department_code,program_code,year_level,academic_year_code,semester_code',
            'សុខ ដារ៉ា,Sok Dara,Male,2004-03-14,Phnom Penh,Chamkar Mon,Tonle Bassac,Phum 1,Phnom Penh,Toul Kork,Boeung Kak 1,Phum 2,BSR980871,Pass1234,សុខ សំអាត,Sok Samart,012345111,ចាន់ សុភា,Chan Sophea,012345112,Sok Samart,Father,012345111,"Phnom Penh, Toul Kork",CS,CS,1,' . $this->yearName . ',1',
            'ចាន់ ស្រីនាង,Chan Sreyneang,Female,2005-07-22,Kandal,Ta Khmau,Prek Russey,Phum 3,Phnom Penh,Sen Sok,Khmuonh,Phum 4,BSR980872,Pass1234,ចាន់ វណ្ណា,Chan Vanna,012345221,លី ម៉ាលី,Ly Maly,012345222,Chan Vanna,Father,012345221,"Kandal, Ta Khmau",IT,CS,2,' . $this->yearName . ',1',
            'លឹម ពិសិដ្ឋ,Lim Piseth,Male,2003-11-05,Siem Reap,Siem Reap,Sla Kram,Phum 5,Phnom Penh,Mean Chey,Stung Meanchey,Phum 6,BSR980874,Pass1234,លឹម ហេង,Lim Heng,012345441,ស៊ុន ណារី,Sun Nary,012345442,Lim Heng,Father,012345441,"Siem Reap, Sla Kram",BUS,BA,1,' . $this->yearName . ',2',
        ]);

        $file = UploadedFile::fake()->createWithContent('SLAS_Student_Import_Test.csv', $csvContent);
        $res = $this->preview($file)->assertCreated();
        $res->assertJsonPath('data.total_rows', 3)
            ->assertJsonPath('data.valid_rows', 3)
            ->assertJsonPath('data.invalid_rows', 0);

        $id = $res->json('data.id');
        $this->actingAs($this->admin)->postJson("/api/admin/students/import/{$id}/confirm")->assertStatus(202);

        $s1 = Student::where('student_code', 'BSR980871')->firstOrFail();
        $this->assertStringContainsString('Phnom Penh', $s1->place_of_birth);
        $this->assertStringContainsString('Toul Kork', $s1->current_address);

        $s2 = Student::where('student_code', 'BSR980872')->firstOrFail();
        $this->assertSame(2, (int) $s2->year_level);

        $s3 = Student::where('student_code', 'BSR980874')->firstOrFail();
        $this->assertNotNull($s3->program_id);
    }

    public function test_admin_can_reset_imported_student_password_and_verify_login(): void
    {
        $id = $this->preview($this->csvFile([$this->row('SLAS26T999')]))->json('data.id');
        $confirm = $this->actingAs($this->admin)->postJson("/api/admin/students/import/{$id}/confirm")
            ->assertStatus(202)
            ->assertJsonPath('data.has_generated_credentials', true);

        $student = Student::where('student_code', 'SLAS26T999')->firstOrFail();

        // Non-admin cannot reset student password
        $teacher = User::where('email', 'teacher@slas.com')->firstOrFail();
        $this->actingAs($teacher)->postJson("/api/admin/students/{$student->id}/reset-password")
            ->assertStatus(403);

        // Admin-approved reset with generated temporary password
        $reset = $this->actingAs($this->admin)->postJson("/api/admin/students/{$student->id}/reset-password")
            ->assertOk()
            ->assertJsonPath('data.student_identifier', 'SLAS26T999')
            ->assertJsonPath('data.username', 'SLAS26T999')
            ->assertJsonPath('data.role', 'student')
            ->assertJsonPath('data.password_generated', true);

        $tempPassword = $reset->json('data.temporary_password');
        $this->assertSame('STD@12345', $tempPassword);

        // Credentials flag clears once temp password is invalidated
        $this->actingAs($this->admin)->getJson("/api/admin/students/import/{$id}/status")
            ->assertOk()
            ->assertJsonPath('data.has_generated_credentials', false);

        // Login via API succeeds with STD@12345 (and trims whitespace)
        $this->postJson('/api/login', ['login_id' => ' SLAS26T999 ', 'password' => 'STD@12345'])
            ->assertOk()
            ->assertJsonPath('student_id', 'SLAS26T999')
            ->assertJsonPath('user.role', 'student');

        // Also supports resolving by student_code and CSV downloadable credentials format
        $csvReset = $this->actingAs($this->admin)->post('/api/admin/students/SLAS26T999/reset-password?format=csv');
        $csvReset->assertOk();
        $csvText = $csvReset->streamedContent();
        $this->assertStringContainsString('SLAS26T999', $csvText);
        $this->assertStringContainsString('STD@12345', $csvText);
        $csvLines = array_values(array_filter(explode("\n", str_replace("\r", '', trim($csvText)))));
        $csvCols = str_getcsv($csvLines[1]);
        $csvTempPass = trim($csvCols[4]);
        $this->assertSame('STD@12345', $csvTempPass);
        $this->postJson('/api/login', ['login_id' => 'SLAS26T999', 'password' => $csvTempPass])->assertOk();

        // Admin-approved reset with explicit password
        $this->actingAs($this->admin)->postJson("/api/admin/students/{$student->id}/reset-password", [
            'password' => 'NewSecurePass123!',
        ])->assertOk()->assertJsonPath('data.temporary_password', null);

        $this->postJson('/api/login', ['login_id' => 'SLAS26T999', 'password' => $csvTempPass])
            ->assertStatus(401);

        $this->postJson('/api/login', ['login_id' => 'SLAS26T999', 'password' => 'NewSecurePass123!'])
            ->assertOk()
            ->assertJsonPath('student_id', 'SLAS26T999');
    }

    public function test_imports_two_students_csv_with_bom_simplified_headers_and_verifies_login_and_dashboard(): void
    {
        $csvContent = "\xEF\xBB\xBF" . implode("\r\n", [
            'khmer_name,english_name,gender,date_of_birth,place_of_birth,student_identifier,father_name,father_phone,mother_name,mother_phone,current_address,department,program,year_level,academic_year',
            'គឹម សុវណ្ណា,Kim Sovanna,Male,2004-05-18,"Poipet, Banteay Meanchey",SLAS26T101,គឹម សុខ,012000101,លី ស្រីពៅ,012000102,"Poipet, Banteay Meanchey, Cambodia",Computer Science,Test Program,Year 2,' . $this->yearName,
            'សុខ មុនីរ័ត្ន,Sok Moniroth,Female,2004-11-02,"Serei Saophoan, Banteay Meanchey",SLAS26T102,សុខ វណ្ណា,012000103,ចាន់ ដាលីន,012000104,"Serei Saophoan, Banteay Meanchey, Cambodia",Computer Science,Test Program,Year 2,' . $this->yearName,
        ]);

        $file = UploadedFile::fake()->createWithContent('SLAS_student_import_test_2_students.csv', $csvContent);
        $preview = $this->preview($file)->assertCreated();
        $preview->assertJsonPath('data.total_rows', 2)
            ->assertJsonPath('data.valid_rows', 2)
            ->assertJsonPath('data.invalid_rows', 0)
            ->assertJsonPath('data.duplicate_rows', 0)
            ->assertJsonPath('data.missing_required_rows', 0)
            ->assertJsonPath('data.invalid_department_program_rows', 0);

        $id = $preview->json('data.id');
        $confirm = $this->actingAs($this->admin)->postJson("/api/admin/students/import/{$id}/confirm")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.success_rows', 2)
            ->assertJsonPath('data.created_user_accounts', 2)
            ->assertJsonPath('data.has_generated_credentials', true);

        // Verify both student records & linked user accounts
        foreach (['SLAS26T101', 'SLAS26T102'] as $code) {
            $student = Student::where('student_code', $code)->firstOrFail();
            $this->assertNotNull($student->user_id);
            $this->assertSame('student', $student->user->role);
            $this->assertSame('2', (string) $student->year_level);
            $this->assertNotNull($student->familyInformation);
            $this->assertNotEmpty($student->familyInformation->father_khmer_name);
        }

        // Retrieve one-time credentials CSV and test login + student dashboard for BOTH students
        $credsCsv = $this->actingAs($this->admin)->get("/api/admin/students/imports/{$id}/credentials")->streamedContent();
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', trim($credsCsv)))));
        $this->assertCount(3, $lines); // header + 2 students

        foreach (array_slice($lines, 1) as $rowLine) {
            $cols = str_getcsv($rowLine);
            $username = trim($cols[0]);
            $tempPassword = trim($cols[2]);

            $user = Student::where('student_code', $username)->firstOrFail()->user;
            $this->assertTrue(Hash::check($tempPassword, $user->password));

            Auth::forgetGuards();
            $login = $this->postJson('/api/login', ['login_id' => $username, 'password' => $tempPassword])
                ->assertOk()
                ->assertJsonPath('student_id', $username)
                ->assertJsonPath('user.role', 'student');

            $token = $login->json('token');
            Auth::forgetGuards();
            $this->withToken($token)->getJson('/api/dashboard/student')
                ->assertOk()
                ->assertJsonPath('data.student.student_code', $username);
            $this->withToken($token)->getJson('/api/student/module/dashboard')
                ->assertOk()
                ->assertJsonPath('data.profile.student_code', $username);
        }
    }

    public function test_validation_breakdown_counts_missing_required_and_invalid_dept_program(): void
    {
        $rows = [
            $this->row('bsr950001', ['khmer_name' => '', 'english_name' => '']),
            $this->row('bsr950002', ['department_code' => 'BADDEPT']),
            $this->row('bsr950003', ['program_code' => 'BADPROG']),
        ];

        $res = $this->preview($this->csvFile($rows))->assertCreated();
        $res->assertJsonPath('data.total_rows', 3)
            ->assertJsonPath('data.valid_rows', 0)
            ->assertJsonPath('data.invalid_rows', 3)
            ->assertJsonPath('data.missing_required_rows', 1)
            ->assertJsonPath('data.invalid_department_program_rows', 2);
    }

    public function test_rollback_when_import_fails_leaves_no_orphan_users_or_students(): void
    {
        $id = $this->preview($this->csvFile([$this->row('bsr990001'), $this->row('bsr990002')]))->json('data.id');
        $usersBefore = User::count();
        $studentsBefore = Student::count();

        Student::creating(function () {
            throw new \RuntimeException('Simulated DB constraint failure');
        });

        try {
            $res = $this->actingAs($this->admin)->postJson("/api/admin/students/import/{$id}/confirm");
            $res->assertStatus(500)
                ->assertJsonPath('data.status', 'failed')
                ->assertJsonPath('data.success_rows', 0)
                ->assertJsonPath('data.failed_rows', 2);
        } finally {
            Student::flushEventListeners();
            Student::clearBootedModels();
        }

        $this->assertSame($usersBefore, User::count());
        $this->assertSame($studentsBefore, Student::count());
        $this->assertDatabaseMissing('students', ['student_code' => 'bsr990001']);
        $this->assertDatabaseMissing('students', ['student_code' => 'bsr990002']);
    }

    public function test_slas_reset_student_passwords_command_updates_only_students_and_preserves_admin_and_teacher(): void
    {
        $teacher = User::where('role', 'teacher')->firstOrFail();
        $adminHashBefore = $this->admin->password;
        $teacherHashBefore = $teacher->password;

        $student = Student::firstOrFail();
        $student->user->createToken('old-student-token');
        $usersCountBefore = User::count();
        $studentsCountBefore = Student::count();

        $this->artisan('slas:reset-student-passwords')->assertExitCode(0);

        $this->assertSame($usersCountBefore, User::count());
        $this->assertSame($studentsCountBefore, Student::count());

        $this->assertSame($adminHashBefore, $this->admin->fresh()->password);
        $this->assertSame($teacherHashBefore, $teacher->fresh()->password);

        foreach (User::where('role', 'student')->get() as $su) {
            $this->assertTrue(Hash::check('STD@12345', $su->password));
            $this->assertSame(0, $su->tokens()->count());
        }

        // Student can log in directly with STD@12345 and immediately access student dashboard without forced password change
        $login = $this->postJson('/api/login', ['login_id' => $student->student_code, 'password' => 'STD@12345'])
            ->assertOk()
            ->assertJsonPath('user.role', 'student');
        $token = $login->json('token');
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/dashboard/student')
            ->assertOk()
            ->assertJsonPath('data.student.student_code', $student->student_code);
    }

    private function resultData(array $row): array
    {
        $v = app(\App\Services\StudentImportValidationService::class);

        return $v->validate($v->normalize($row))['data'];
    }
}
