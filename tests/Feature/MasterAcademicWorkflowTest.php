<?php

namespace Tests\Feature;

use App\Models\AcademicCalendarAudit;
use App\Models\AcademicYear;
use App\Models\ClassRoom;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CurriculumItem;
use App\Models\Department;
use App\Models\Grade;
use App\Models\MajorAcademicPeriod;
use App\Models\Program;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentCourseRetake;
use App\Models\StudentProgressionHistory;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MasterAcademicWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function createAdminUser(): User
    {
        return User::factory()->create([
            'name' => 'System Admin',
            'email' => 'admin_master_workflow@slas.edu',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function createTeacherWithUser(Department $faculty, string $suffix = '1'): Teacher
    {
        $user = User::factory()->create([
            'name' => "Teacher {$suffix}",
            'email' => "teacher_{$suffix}@slas.edu",
            'role' => 'teacher',
            'status' => 'active',
        ]);

        return Teacher::create([
            'user_id' => $user->id,
            'department_id' => $faculty->id,
            'teacher_code' => "TCH-{$suffix}",
            'first_name' => 'Teacher',
            'last_name' => $suffix,
            'gender' => 'Male',
            'email' => $user->email,
            'status' => 'active',
        ]);
    }

    private function createStudentWithUser(
        Department $faculty,
        Program $major,
        ClassRoom $cohort,
        AcademicYear $ay,
        Semester $sem,
        int $studyYear,
        int $semNum,
        string $suffix
    ): Student {
        $user = User::factory()->create([
            'name' => "Student {$suffix}",
            'email' => "student_{$suffix}@slas.edu",
            'role' => 'student',
            'status' => 'active',
        ]);

        return Student::create([
            'user_id' => $user->id,
            'department_id' => $faculty->id,
            'program_id' => $major->id,
            'class_id' => $cohort->id,
            'student_code' => "STU-{$suffix}",
            'first_name' => 'Student',
            'last_name' => $suffix,
            'gender' => 'Male',
            'email' => $user->email,
            'major' => $major->name,
            'year_level' => $studyYear,
            'academic_year' => $ay->name,
            'semester' => $sem->name,
            'current_academic_year_id' => $ay->id,
            'current_semester_id' => $sem->id,
            'current_study_year' => $studyYear,
            'current_semester_number' => $semNum,
            'progression_status' => 'in_progress',
            'enrollment_status' => 'active',
            'status' => 'active',
        ]);
    }

    /**
     * Test 1: Academic Structure, Study Curriculum (Year 1 - Year 4), and Flexible Academic Calendar with Audit Trail.
     */
    public function test_academic_structure_curriculum_and_flexible_calendar_with_audits(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        // 1. Create Faculty, Majors, Course Categories, and Courses
        $fosat = Department::create([
            'name' => 'Faculty of Science and Technology',
            'code' => 'FOSAT',
            'status' => 'active',
        ]);

        $itMajor = Program::create([
            'department_id' => $fosat->id,
            'name' => 'Information Technology',
            'code' => 'IT',
            'degree_level' => 'Bachelor',
            'duration_years' => 4,
            'total_credits' => 120,
            'status' => 'active',
        ]);

        $dsMajor = Program::create([
            'department_id' => $fosat->id,
            'name' => 'Data Science',
            'code' => 'DS',
            'degree_level' => 'Bachelor',
            'duration_years' => 4,
            'total_credits' => 120,
            'status' => 'active',
        ]);

        $catProg = CourseCategory::create([
            'name' => 'Programming',
            'code' => 'PROG',
            'status' => 'active',
        ]);

        $catDb = CourseCategory::create([
            'name' => 'Database Systems',
            'code' => 'DB',
            'status' => 'active',
        ]);

        $cJava = Course::create([
            'department_id' => $fosat->id,
            'program_id' => $itMajor->id,
            'course_category_id' => $catProg->id,
            'course_code' => 'IT101',
            'course_name' => 'Java Programming',
            'credits' => 3,
            'credit' => 3.0,
            'year_level' => 1,
            'status' => 'active',
        ]);

        $cWeb = Course::create([
            'department_id' => $fosat->id,
            'program_id' => $itMajor->id,
            'course_category_id' => $catProg->id,
            'course_code' => 'IT102',
            'course_name' => 'Web Development',
            'credits' => 3,
            'credit' => 3.0,
            'year_level' => 1,
            'status' => 'active',
        ]);

        $cDb = Course::create([
            'department_id' => $fosat->id,
            'program_id' => $itMajor->id,
            'course_category_id' => $catDb->id,
            'course_code' => 'IT201',
            'course_name' => 'Database Administration',
            'credits' => 3,
            'credit' => 3.0,
            'year_level' => 2,
            'status' => 'active',
        ]);

        // 2. Configure Study Curriculum via API for IT Major across Year 1 & Year 2
        $res1 = $this->postJson('/api/curriculum', [
            'program_id' => $itMajor->id,
            'course_id' => $cJava->id,
            'course_category_id' => $catProg->id,
            'study_year' => 1,
            'semester_number' => 1,
            'semester_name' => 'Semester 1',
            'credits' => 3.0,
            'is_required' => true,
        ]);
        $res1->assertStatus(201);

        $res2 = $this->postJson('/api/curriculum', [
            'program_id' => $itMajor->id,
            'course_id' => $cWeb->id,
            'course_category_id' => $catProg->id,
            'study_year' => 1,
            'semester_number' => 2,
            'semester_name' => 'Semester 2',
            'credits' => 3.0,
            'is_required' => true,
        ]);
        $res2->assertStatus(201);

        $res3 = $this->postJson('/api/curriculum', [
            'program_id' => $itMajor->id,
            'course_id' => $cDb->id,
            'course_category_id' => $catDb->id,
            'study_year' => 2,
            'semester_number' => 1,
            'semester_name' => 'Semester 1',
            'credits' => 3.0,
            'is_required' => true,
        ]);
        $res3->assertStatus(201);

        // Retrieve structured Year 1 - Year 4 curriculum for IT Major
        $currRes = $this->getJson("/api/programs/{$itMajor->id}/curriculum");
        $currRes->assertOk()
            ->assertJsonPath('meta.major_code', 'IT')
            ->assertJsonCount(4, 'years');

        // 3. Configure Flexible Academic Calendar with DIFFERENT durations per Major (IT: 4 months, DS: 3 months)
        $ay = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-08-31',
            'status' => 'active',
        ]);

        $sem1 = Semester::create([
            'academic_year_id' => $ay->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
        ]);

        $itPeriodRes = $this->postJson('/api/academic-calendar/periods', [
            'program_id' => $itMajor->id,
            'academic_year_id' => $ay->id,
            'semester_id' => $sem1->id,
            'study_year' => 1,
            'semester_number' => 1,
            'semester_name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31', // 4 months
            'status' => 'active',
            'reason' => 'Initial IT 4-month schedule',
        ]);
        $itPeriodRes->assertStatus(201);
        $itPeriodId = $itPeriodRes->json('data.id');

        $dsPeriodRes = $this->postJson('/api/academic-calendar/periods', [
            'program_id' => $dsMajor->id,
            'academic_year_id' => $ay->id,
            'semester_id' => $sem1->id,
            'study_year' => 1,
            'semester_number' => 1,
            'semester_name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-11-30', // 3 months
            'status' => 'active',
            'reason' => 'Initial DS 3-month accelerated schedule',
        ]);
        $dsPeriodRes->assertStatus(201);

        // 4. Modify IT Semester End Date early (e.g. finished in 3.5 months) and verify Audit Log
        $updateRes = $this->putJson("/api/academic-calendar/periods/{$itPeriodId}", [
            'end_date' => '2026-12-15',
            'reason' => 'Final exams completed 2 weeks early',
        ]);
        $updateRes->assertOk()
            ->assertJsonPath('data.end_date', '2026-12-15');

        $this->assertDatabaseHas('academic_calendar_audits', [
            'major_academic_period_id' => $itPeriodId,
            'program_id' => $itMajor->id,
            'changed_by' => $admin->id,
            'reason' => 'Final exams completed 2 weeks early',
        ]);

        $auditListRes = $this->getJson("/api/academic-calendar/audits?program_id={$itMajor->id}");
        $auditListRes->assertOk();
        $this->assertGreaterThanOrEqual(2, count($auditListRes->json('data')));
    }

    /**
     * Test 2: Automatic Individual Student Semester Progression, Retake Creation on Failure,
     * Cross-Cohort Retake Enrollment with Junior Class, and Full Year 1-Year 4 GPA History.
     */
    public function test_automatic_individual_progression_cross_cohort_retake_and_full_gpa_history(): void
    {
        $fosat = Department::create([
            'name' => 'Faculty of Science and Technology',
            'code' => 'FOSAT',
            'status' => 'active',
        ]);

        $csMajor = Program::create([
            'department_id' => $fosat->id,
            'name' => 'Computer Science',
            'code' => 'CS',
            'degree_level' => 'Bachelor',
            'duration_years' => 4,
            'total_credits' => 120,
            'status' => 'active',
        ]);

        $catCore = CourseCategory::create([
            'name' => 'Computer Science Core',
            'code' => 'CS-CORE',
            'status' => 'active',
        ]);

        $ay1 = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-08-31',
            'status' => 'active',
        ]);

        $sem1 = Semester::create([
            'academic_year_id' => $ay1->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
        ]);

        $sem2 = Semester::create([
            'academic_year_id' => $ay1->id,
            'name' => 'Semester 2',
            'start_date' => '2027-01-15',
            'end_date' => '2027-05-31',
            'status' => 'upcoming',
        ]);

        $teacher = $this->createTeacherWithUser($fosat, 'CS1');

        $cohortSenior = ClassRoom::create([
            'department_id' => $fosat->id,
            'program_id' => $csMajor->id,
            'name' => 'CS-Cohort-2026',
            'year_level' => 1,
            'academic_year' => $ay1->name,
            'status' => 'active',
        ]);

        $cohortJunior = ClassRoom::create([
            'department_id' => $fosat->id,
            'program_id' => $csMajor->id,
            'name' => 'CS-Cohort-2027-Junior',
            'year_level' => 1,
            'academic_year' => $ay1->name,
            'status' => 'active',
        ]);

        // Create Year 1 - Year 4 curriculum courses for CS Major
        $cY1S1_A = Course::create([
            'department_id' => $fosat->id,
            'program_id' => $csMajor->id,
            'course_category_id' => $catCore->id,
            'course_code' => 'CS101',
            'course_name' => 'Introduction to Programming',
            'credits' => 3,
            'credit' => 3.0,
            'year_level' => 1,
            'status' => 'active',
        ]);

        $cY1S1_B = Course::create([
            'department_id' => $fosat->id,
            'program_id' => $csMajor->id,
            'course_category_id' => $catCore->id,
            'course_code' => 'CS102',
            'course_name' => 'Discrete Mathematics',
            'credits' => 3,
            'credit' => 3.0,
            'year_level' => 1,
            'status' => 'active',
        ]);

        $cY1S2_A = Course::create([
            'department_id' => $fosat->id,
            'program_id' => $csMajor->id,
            'course_category_id' => $catCore->id,
            'course_code' => 'CS103',
            'course_name' => 'Data Structures & Algorithms',
            'credits' => 3,
            'credit' => 3.0,
            'year_level' => 1,
            'status' => 'active',
        ]);

        $cY1S2_B = Course::create([
            'department_id' => $fosat->id,
            'program_id' => $csMajor->id,
            'course_category_id' => $catCore->id,
            'course_code' => 'CS104',
            'course_name' => 'Object-Oriented Programming',
            'credits' => 3,
            'credit' => 3.0,
            'year_level' => 1,
            'status' => 'active',
        ]);

        $cY2S1 = Course::create([
            'department_id' => $fosat->id,
            'program_id' => $csMajor->id,
            'course_category_id' => $catCore->id,
            'course_code' => 'CS201',
            'course_name' => 'Operating Systems',
            'credits' => 3,
            'credit' => 3.0,
            'year_level' => 2,
            'status' => 'active',
        ]);

        $cY3S1 = Course::create([
            'department_id' => $fosat->id,
            'program_id' => $csMajor->id,
            'course_category_id' => $catCore->id,
            'course_code' => 'CS301',
            'course_name' => 'Artificial Intelligence',
            'credits' => 3,
            'credit' => 3.0,
            'year_level' => 3,
            'status' => 'active',
        ]);

        $cY4S1 = Course::create([
            'department_id' => $fosat->id,
            'program_id' => $csMajor->id,
            'course_category_id' => $catCore->id,
            'course_code' => 'CS401',
            'course_name' => 'Senior Capstone Project',
            'credits' => 6,
            'credit' => 6.0,
            'year_level' => 4,
            'status' => 'active',
        ]);

        // Register Curriculum Items for Year 1 Sem 1, Year 1 Sem 2, Year 2 Sem 1, Year 3 Sem 1, Year 4 Sem 1
        foreach ([
            [$cY1S1_A, 1, 1, 'Semester 1', 3.0],
            [$cY1S1_B, 1, 1, 'Semester 1', 3.0],
            [$cY1S2_A, 1, 2, 'Semester 2', 3.0],
            [$cY1S2_B, 1, 2, 'Semester 2', 3.0],
            [$cY2S1, 2, 1, 'Semester 1', 3.0],
            [$cY3S1, 3, 1, 'Semester 1', 3.0],
            [$cY4S1, 4, 1, 'Semester 1', 6.0],
        ] as [$course, $sy, $sn, $sName, $cr]) {
            CurriculumItem::create([
                'program_id' => $csMajor->id,
                'course_id' => $course->id,
                'course_category_id' => $catCore->id,
                'study_year' => $sy,
                'semester_number' => $sn,
                'semester_name' => $sName,
                'credits' => $cr,
                'is_required' => true,
                'status' => 'active',
            ]);
        }

        // Configure MajorAcademicPeriod for Year 1 Sem 1 and Year 1 Sem 2
        MajorAcademicPeriod::create([
            'program_id' => $csMajor->id,
            'academic_year_id' => $ay1->id,
            'semester_id' => $sem1->id,
            'study_year' => 1,
            'semester_number' => 1,
            'semester_name' => 'Semester 1',
            'sequence_order' => 1,
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
        ]);

        MajorAcademicPeriod::create([
            'program_id' => $csMajor->id,
            'academic_year_id' => $ay1->id,
            'semester_id' => $sem2->id,
            'study_year' => 1,
            'semester_number' => 2,
            'semester_name' => 'Semester 2',
            'sequence_order' => 2,
            'start_date' => '2027-01-15',
            'end_date' => '2027-05-31',
            'status' => 'upcoming',
        ]);

        // Create CourseOfferings for Year 1 Semester 1
        $offY1S1_A = CourseOffering::create([
            'course_id' => $cY1S1_A->id,
            'academic_year_id' => $ay1->id,
            'semester_id' => $sem1->id,
            'class_id' => $cohortSenior->id,
            'study_year' => 1,
            'status' => 'active',
        ]);

        $offY1S1_B = CourseOffering::create([
            'course_id' => $cY1S1_B->id,
            'academic_year_id' => $ay1->id,
            'semester_id' => $sem1->id,
            'class_id' => $cohortSenior->id,
            'study_year' => 1,
            'status' => 'active',
        ]);

        // Create 3 Students in Year 1 Semester 1 to test INDIVIDUAL evaluation:
        // - Student A: Passes all Y1S1 courses -> auto-progresses immediately to Y1S2
        // - Student B: Has 1 finalized grade and 1 provisional/pending grade -> does NOT progress yet
        // - Student C: Passes CS101 (85 / A) and FAILS CS102 (42 / F) -> still auto-progresses to Y1S2, AND CS102 is flagged for Retake
        $studentA = $this->createStudentWithUser($fosat, $csMajor, $cohortSenior, $ay1, $sem1, 1, 1, 'A');
        $studentB = $this->createStudentWithUser($fosat, $csMajor, $cohortSenior, $ay1, $sem1, 1, 1, 'B');
        $studentC = $this->createStudentWithUser($fosat, $csMajor, $cohortSenior, $ay1, $sem1, 1, 1, 'C');

        foreach ([$studentA, $studentB, $studentC] as $stu) {
            CourseEnrollment::create([
                'student_id' => $stu->id,
                'course_id' => $cY1S1_A->id,
                'course_offering_id' => $offY1S1_A->id,
                'semester_id' => $sem1->id,
                'academic_year_id' => $ay1->id,
                'study_year' => 1,
                'semester_number' => 1,
                'enrollment_type' => 'regular',
                'attempt_number' => 1,
                'status' => 'enrolled',
            ]);
            CourseEnrollment::create([
                'student_id' => $stu->id,
                'course_id' => $cY1S1_B->id,
                'course_offering_id' => $offY1S1_B->id,
                'semester_id' => $sem1->id,
                'academic_year_id' => $ay1->id,
                'study_year' => 1,
                'semester_number' => 1,
                'enrollment_type' => 'regular',
                'attempt_number' => 1,
                'status' => 'enrolled',
            ]);
        }

        // --- Test Student B (Incomplete grades: 1 official, 1 provisional) ---
        Grade::create([
            'student_id' => $studentB->id,
            'course_id' => $cY1S1_A->id,
            'course_offering_id' => $offY1S1_A->id,
            'semester_id' => $sem1->id,
            'study_year' => 1,
            'semester_number' => 1,
            'total_score' => 88,
            'status' => 'official',
        ]);
        Grade::create([
            'student_id' => $studentB->id,
            'course_id' => $cY1S1_B->id,
            'course_offering_id' => $offY1S1_B->id,
            'semester_id' => $sem1->id,
            'study_year' => 1,
            'semester_number' => 1,
            'total_score' => 79,
            'status' => 'provisional', // NOT finalized yet!
        ]);

        $studentB->refresh();
        $this->assertEquals($sem1->id, $studentB->current_semester_id);
        $this->assertEquals(1, $studentB->current_semester_number);
        $this->assertDatabaseMissing('student_progression_histories', [
            'student_id' => $studentB->id,
        ]);

        // Verify Student B's Dashboard & GPA Overview show N/A (not a partial GPA!) while Semester 1 has a pending grade
        Sanctum::actingAs($studentB->user);
        $bDash = $this->getJson('/api/dashboard/student');
        $bDash->assertOk()
            ->assertJsonPath('data.gpa', null)
            ->assertJsonPath('data.gpa_summary.current_semester_gpa', null);

        $bGpaOverview = $this->getJson('/api/student/gpa-overview');
        $bGpaOverview->assertOk()
            ->assertJsonPath('data.current_semester.is_complete', false)
            ->assertJsonPath('data.current_semester.semester_gpa_display', 'N/A');

        // --- Test Student A (All grades finalized: 92/A and 84/B+) ---
        Grade::create([
            'student_id' => $studentA->id,
            'course_id' => $cY1S1_A->id,
            'course_offering_id' => $offY1S1_A->id,
            'semester_id' => $sem1->id,
            'study_year' => 1,
            'semester_number' => 1,
            'total_score' => 92,
            'status' => 'official',
        ]);

        // Finalizing the second grade triggers automatic individual progression via Grade::saved!
        Grade::create([
            'student_id' => $studentA->id,
            'course_id' => $cY1S1_B->id,
            'course_offering_id' => $offY1S1_B->id,
            'semester_id' => $sem1->id,
            'study_year' => 1,
            'semester_number' => 1,
            'total_score' => 84,
            'status' => 'official',
        ]);

        $studentA->refresh();
        $this->assertEquals($sem2->id, $studentA->current_semester_id);
        $this->assertEquals(1, $studentA->current_study_year);
        $this->assertEquals(2, $studentA->current_semester_number);

        // Verify Student A was automatically enrolled in Year 1 Semester 2 curriculum courses (CS103 & CS104)
        $this->assertDatabaseHas('course_enrollments', [
            'student_id' => $studentA->id,
            'course_id' => $cY1S2_A->id,
            'semester_id' => $sem2->id,
            'status' => 'enrolled',
        ]);
        $this->assertDatabaseHas('course_enrollments', [
            'student_id' => $studentA->id,
            'course_id' => $cY1S2_B->id,
            'semester_id' => $sem2->id,
            'status' => 'enrolled',
        ]);
        // And NOT enrolled in Year 2, Year 3, or Year 4 courses prematurely!
        $this->assertDatabaseMissing('course_enrollments', [
            'student_id' => $studentA->id,
            'course_id' => $cY2S1->id,
        ]);

        // Verify Student B is still in Semester 1 (Individual progression isolation)
        $studentB->refresh();
        $this->assertEquals($sem1->id, $studentB->current_semester_id);

        // --- Test Idempotency: Running slas:reconcile-student-progression does not duplicate enrollments or history ---
        Artisan::call('slas:reconcile-student-progression', ['--student_id' => $studentA->id]);
        $this->assertEquals(1, StudentProgressionHistory::where('student_id', $studentA->id)->count());
        $this->assertEquals(2, CourseEnrollment::where('student_id', $studentA->id)->where('semester_id', $sem2->id)->count());

        // --- Test Student C (1 Passed course: 86/A, 1 Failed course: 42/F) ---
        Grade::create([
            'student_id' => $studentC->id,
            'course_id' => $cY1S1_A->id,
            'course_offering_id' => $offY1S1_A->id,
            'semester_id' => $sem1->id,
            'study_year' => 1,
            'semester_number' => 1,
            'total_score' => 86,
            'status' => 'official',
        ]);
        $failedGradeC = Grade::create([
            'student_id' => $studentC->id,
            'course_id' => $cY1S1_B->id,
            'course_offering_id' => $offY1S1_B->id,
            'semester_id' => $sem1->id,
            'study_year' => 1,
            'semester_number' => 1,
            'total_score' => 42, // Failed (F)
            'status' => 'official',
        ]);

        // Verify Student C STILL progresses automatically to Year 1 Semester 2
        $studentC->refresh();
        $this->assertEquals($sem2->id, $studentC->current_semester_id);
        $this->assertEquals(2, $studentC->current_semester_number);

        // Verify failed course CS102 is recorded in student_course_retakes with status = 'required'
        $this->assertDatabaseHas('student_course_retakes', [
            'student_id' => $studentC->id,
            'course_id' => $cY1S1_B->id,
            'failed_semester_id' => $sem1->id,
            'original_letter_grade' => 'F',
            'status' => 'required',
        ]);

        // --- Test Cross-Cohort Retake Enrollment with Junior Cohort in Semester 2 ---
        // A junior cohort offers CS102 in Semester 2
        $juniorOfferingCS102 = CourseOffering::create([
            'course_id' => $cY1S1_B->id,
            'academic_year_id' => $ay1->id,
            'semester_id' => $sem2->id,
            'class_id' => $cohortJunior->id,
            'study_year' => 1,
            'status' => 'active',
        ]);

        Sanctum::actingAs($studentC->user);

        // Check available retake offerings via API
        $retakeListRes = $this->getJson('/api/student/retakes');
        $retakeListRes->assertOk()
            ->assertJsonPath('data.summary.required_count', 1)
            ->assertJsonPath('data.retakes.0.course_code', 'CS102');

        // Enroll Student C into the junior cohort's CourseOffering for CS102
        $enrollRetakeRes = $this->postJson('/api/student/retakes/enroll', [
            'course_id' => $cY1S1_B->id,
            'course_offering_id' => $juniorOfferingCS102->id,
        ]);
        $enrollRetakeRes->assertStatus(201)
            ->assertJsonPath('data.enrollment.enrollment_type', 'retake')
            ->assertJsonPath('data.enrollment.attempt_number', 2)
            ->assertJsonPath('data.retake.status', 'enrolled');

        // Teacher finalizes Student C's retake grade in Semester 2 (Score: 78 -> B+)
        $retakeGrade = Grade::create([
            'student_id' => $studentC->id,
            'course_id' => $cY1S1_B->id,
            'course_offering_id' => $juniorOfferingCS102->id,
            'semester_id' => $sem2->id,
            'study_year' => 1,
            'semester_number' => 2,
            'total_score' => 78,
            'status' => 'official',
        ]);

        // Verify original failed grade in Semester 1 is NEVER overwritten or deleted!
        $failedGradeC->refresh();
        $this->assertEquals(42.0, (float) $failedGradeC->total_score);
        $this->assertEquals('F', $failedGradeC->letter_grade);
        $this->assertFalse((bool) $failedGradeC->is_retake);
        $this->assertEquals(1, (int) $failedGradeC->attempt_number);

        // Verify retake grade in Semester 2 is stored as Attempt #2 with is_retake = true
        $retakeGrade->refresh();
        $this->assertEquals(78.0, (float) $retakeGrade->total_score);
        $this->assertEquals('B', $retakeGrade->letter_grade);
        $this->assertTrue((bool) $retakeGrade->is_retake);
        $this->assertEquals(2, (int) $retakeGrade->attempt_number);
        $this->assertEquals($failedGradeC->id, $retakeGrade->original_grade_id);

        // Verify student_course_retakes record is now marked 'passed'
        $this->assertDatabaseHas('student_course_retakes', [
            'student_id' => $studentC->id,
            'course_id' => $cY1S1_B->id,
            'status' => 'passed',
            'retake_grade_id' => $retakeGrade->id,
            'retake_letter_grade' => 'B',
        ]);

        // --- Test Full Year 1 - Year 4 GPA Academic History ("View All") for Student C ---
        $historyRes = $this->getJson('/api/student/gpa-history');
        $historyRes->assertOk()
            ->assertJsonCount(4, 'data.years');

        $years = $historyRes->json('data.years');

        // Year 1:
        // - Semester 1 (Completed): CS101 (86 / A- / 3.7) and CS102 (42 / F / 0.0) -> Average = 64.00, GPA = (3.7*3 + 0.0*3)/6 = 1.85
        // - Semester 2 (Current): CS103 (N/A), CS104 (N/A), plus Retake CS102 (78 / B) -> Incomplete because CS103 & CS104 are ungraded -> Semester GPA = N/A
        $year1 = $years[0];
        $this->assertEquals(1, $year1['study_year']);
        $this->assertEquals('Year 1', $year1['year_level_name']);

        $y1Sem1 = $year1['semesters'][0];
        $this->assertEquals('Completed', $y1Sem1['state']);
        $this->assertEquals('64.00', $y1Sem1['semester_average_display']);
        $this->assertEquals('1.85', $y1Sem1['semester_gpa_display']);

        $y1Sem2 = $year1['semesters'][1];
        $this->assertEquals('Current', $y1Sem2['state']);
        $this->assertEquals('N/A', $y1Sem2['semester_average_display']);
        $this->assertEquals('N/A', $y1Sem2['semester_gpa_display']);

        // Year 2, Year 3, Year 4 (Planned future curriculum years):
        // Must show planned curriculum courses with N/A Score, N/A Grade, N/A Semester Average, N/A Semester GPA
        for ($idx = 1; $idx <= 3; $idx++) {
            $futureYear = $years[$idx];
            $this->assertEquals($idx + 1, $futureYear['study_year']);
            $this->assertContains($futureYear['state'], ['Future', 'Planned']);
            $futureSem1 = $futureYear['semesters'][0];
            $this->assertEquals('N/A', $futureSem1['semester_average_display']);
            $this->assertEquals('N/A', $futureSem1['semester_gpa_display']);
            $this->assertNotEmpty($futureSem1['courses']);
            foreach ($futureSem1['courses'] as $plannedCourse) {
                $this->assertEquals('N/A', $plannedCourse['score_display']);
                $this->assertEquals('N/A', $plannedCourse['grade_display']);
            }
        }
    }
}
