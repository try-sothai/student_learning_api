<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Department;
use App\Models\Program;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AcademicStructureNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AcademicStructureRefactoringTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    public function test_normalizer_migrates_misclassified_faculties_to_majors_and_preserves_all_records(): void
    {
        $csDept = Department::create(['name' => 'Computer Science', 'code' => 'CS', 'status' => 'active']);
        $itDept = Department::create(['name' => 'Information Technology', 'code' => 'IT', 'status' => 'active']);
        $baDept = Department::create(['name' => 'Business Administration', 'code' => 'BA', 'status' => 'active']);
        $dsDept = Department::create(['name' => 'Data Science', 'code' => 'DS', 'status' => 'active']);
        $fosat = Department::create(['name' => 'Faculty Of Science And Technology', 'code' => 'FOSAT', 'status' => 'active']);

        $bscs = Program::create(['department_id' => $csDept->id, 'code' => 'BSCS', 'name' => 'Bachelor of Science in Computer Science', 'duration_years' => 4, 'status' => 'active']);
        $bse = Program::create(['department_id' => $csDept->id, 'code' => 'BSE', 'name' => 'Bachelor of Software Engineering', 'duration_years' => 4, 'status' => 'active']);
        $bsit = Program::create(['department_id' => $itDept->id, 'code' => 'BSIT', 'name' => 'Bachelor of Science in Information Technology', 'duration_years' => 4, 'status' => 'active']);
        $bba = Program::create(['department_id' => $baDept->id, 'code' => 'BBA', 'name' => 'Bachelor of Business Administration', 'duration_years' => 4, 'status' => 'active']);
        $csMaj = Program::create(['department_id' => $csDept->id, 'code' => 'CS', 'name' => 'Computer Science', 'duration_years' => 4, 'status' => 'active']);
        $seMaj = Program::create(['department_id' => $csDept->id, 'code' => 'SE', 'name' => 'Software Engineering', 'duration_years' => 4, 'status' => 'active']);
        $itMaj = Program::create(['department_id' => $itDept->id, 'code' => 'IT', 'name' => 'Information Technology', 'duration_years' => 4, 'status' => 'active']);
        $baMaj = Program::create(['department_id' => $baDept->id, 'code' => 'BA', 'name' => 'Business Administration', 'duration_years' => 4, 'status' => 'active']);

        $courseCs = Course::create([
            'department_id' => $csDept->id,
            'department' => $csDept->name,
            'code' => 'CS101',
            'course_code' => 'CS101',
            'name' => 'Intro to CS',
            'course_name' => 'Intro to CS',
            'credit' => 3,
            'credits' => 3,
            'status' => 'active',
        ]);
        $courseDs = Course::create([
            'department_id' => $dsDept->id,
            'department' => $dsDept->name,
            'code' => 'DS101',
            'course_code' => 'DS101',
            'name' => 'Intro to Data Science',
            'course_name' => 'Intro to Data Science',
            'credit' => 3,
            'credits' => 3,
            'status' => 'active',
        ]);

        $studentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $student = Student::create([
            'user_id' => $studentUser->id,
            'student_code' => 'STD001',
            'first_name' => 'Test',
            'last_name' => 'Student',
            'gender' => 'Male',
            'department_id' => $csDept->id,
            'program_id' => $bscs->id,
            'status' => 'enrolled',
        ]);

        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create([
            'user_id' => $teacherUser->id,
            'teacher_code' => 'TCH001',
            'first_name' => 'Test',
            'last_name' => 'Teacher',
            'gender' => 'Male',
            'department_id' => $itDept->id,
            'status' => 'active',
        ]);

        $report = app(AcademicStructureNormalizer::class)->run(false);

        $this->assertTrue($report['changed']);

        // 1. Only real Faculties remain in departments: FOSAT & FBA
        $remainingDepts = Department::orderBy('code')->pluck('code')->all();
        $this->assertSame(['FBA', 'FOSAT'], $remainingDepts);

        $fba = Department::where('code', 'FBA')->firstOrFail();
        $fosat->refresh();
        $this->assertSame('Faculty of Science and Technology', $fosat->name);

        // 2. Active Majors under FOSAT are CS, DS, IT, SE; under FBA are BA, BBA
        $activeFosatMajors = Program::where('department_id', $fosat->id)
            ->where('status', 'active')
            ->orderBy('code')
            ->pluck('code')
            ->all();
        $this->assertSame(['CS', 'DS', 'IT', 'SE'], $activeFosatMajors);

        $activeFbaMajors = Program::where('department_id', $fba->id)
            ->where('status', 'active')
            ->orderBy('code')
            ->pluck('code')
            ->all();
        $this->assertSame(['BA', 'BBA'], $activeFbaMajors);

        // 3. Legacy duplicate majors BSCS, BSE, BSIT are marked inactive and re-pointed to FOSAT
        $this->assertSame('inactive', $bscs->fresh()->status);
        $this->assertSame($fosat->id, $bscs->fresh()->department_id);
        $this->assertSame('inactive', $bse->fresh()->status);
        $this->assertSame('inactive', $bsit->fresh()->status);

        // 4. Courses linked to their respective Majors via course_program pivot
        $dsMajor = Program::where('code', 'DS')->firstOrFail();
        $this->assertTrue($courseCs->fresh()->programs->contains('id', $csMaj->id));
        $this->assertTrue($courseDs->fresh()->programs->contains('id', $dsMajor->id));

        // 5. Foreign keys re-pointed to FOSAT without losing student or teacher records
        $this->assertSame($fosat->id, (int) $student->fresh()->department_id);
        $this->assertSame($fosat->id, (int) $teacher->fresh()->department_id);
    }

    public function test_four_academic_entities_crud_shared_course_majors_and_student_registration(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        // A. Faculty Management (/api/departments and /api/faculties)
        $fosatRes = $this->postJson('/api/faculties', [
            'name' => 'Faculty of Science and Technology',
            'code' => 'FOSAT',
            'description' => 'Science, Computing and Engineering',
            'status' => 'active',
        ])->assertCreated();
        $fosatId = $fosatRes->json('data.id');

        // B. Major Management (/api/programs and /api/majors)
        $itMajorId = $this->postJson('/api/majors', [
            'faculty_id' => $fosatId,
            'name' => 'Information Technology',
            'code' => 'IT',
            'description' => 'IT and Network Systems',
        ])->assertCreated()->json('data.id');

        $csMajorId = $this->postJson('/api/majors', [
            'faculty_id' => $fosatId,
            'name' => 'Computer Science',
            'code' => 'CS',
        ])->assertCreated()->json('data.id');

        $dsMajorId = $this->postJson('/api/majors', [
            'faculty_id' => $fosatId,
            'name' => 'Data Science',
            'code' => 'DS',
        ])->assertCreated()->json('data.id');

        $seMajorId = $this->postJson('/api/majors', [
            'faculty_id' => $fosatId,
            'name' => 'Software Engineering',
            'code' => 'SE',
        ])->assertCreated()->json('data.id');

        //Selecting FOSAT returns all 4 active majors
        $majorsByFaculty = $this->getJson("/api/departments/{$fosatId}/programs")
            ->assertOk()
            ->assertJsonCount(4, 'data');
        $this->assertEqualsCanonicalizing(
            ['CS', 'DS', 'IT', 'SE'],
            collect($majorsByFaculty->json('data'))->pluck('code')->all()
        );

        // C. Course Category Management (/api/course-categories) - separate from Faculty and Major
        $coreCatId = $this->postJson('/api/course-categories', [
            'name' => 'Major Core',
            'code' => 'CORE',
            'description' => 'Mandatory major core courses',
            'status' => 'active',
        ])->assertCreated()->json('data.id');

        $genEdCatId = $this->postJson('/api/course-categories', [
            'name' => 'General Education',
            ' description' => 'University-wide foundational courses',
        ])->assertCreated()->json('data.id');

        // Verify creating a Course Category did NOT pollute the departments (Faculty) table
        $this->assertSame(1, Department::count());
        $this->assertSame(2, CourseCategory::count());

        // D. Course Management (/api/courses) with multiple shared Majors & Course Category
        $sharedCourseRes = $this->postJson('/api/courses', [
            'course_code' => 'DB201',
            'course_name' => 'Database Management Systems',
            'credit' => 3,
            'department_id' => $fosatId,
            'program_ids' => [$itMajorId, $csMajorId, $dsMajorId],
            'course_category_id' => $coreCatId,
            'description' => 'Relational databases and SQL',
            'status' => 'active',
        ])->assertCreated();

        $courseId = $sharedCourseRes->json('data.id');
        $this->assertSame($coreCatId, $sharedCourseRes->json('data.course_category_id'));
        $this->assertSame('Major Core', $sharedCourseRes->json('data.course_category_name'));
        $this->assertEqualsCanonicalizing([$itMajorId, $csMajorId, $dsMajorId], $sharedCourseRes->json('data.program_ids'));

        // Assigning/updating majors via POST /api/courses/{course}/majors
        $this->postJson("/api/courses/{$courseId}/majors", [
            'major_ids' => [$itMajorId, $csMajorId, $dsMajorId, $seMajorId],
        ])->assertOk()
            ->assertJsonCount(4, 'data.majors');

        // Filtering courses by Major ID returns the shared course
        $this->getJson("/api/courses?program_id={$dsMajorId}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.course_code', 'DB201');

        // GET /api/programs/{program}/courses returns courses assigned to that Major
        $this->getJson("/api/programs/{$seMajorId}/courses")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.course_code', 'DB201');

        // Deleting a Course Category linked to DB201 is blocked with 422; unlinked category deletes cleanly
        $this->deleteJson("/api/course-categories/{$coreCatId}")
            ->assertStatus(422)
            ->assertJsonPath('can_deactivate', true);
        $this->deleteJson("/api/course-categories/{$genEdCatId}")
            ->assertOk();

        // Deleting a Major linked to DB201 is blocked with 422
        $this->deleteJson("/api/programs/{$seMajorId}")
            ->assertStatus(422)
            ->assertJsonPath('can_deactivate', true);

        // E. Add Student under FOSAT + Data Science (DS) Major
        $ay = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
            'status' => 'active',
        ]);

        $this->postJson('/api/admin/students', [
            'english_name' => 'Sokha Chan',
            'gender' => 'Female',
            'student_identifier' => 'STD2026DS01',
            'department_id' => $fosatId,
            'program_id' => $dsMajorId,
            'year_level' => 1,
            'academic_year_id' => $ay->id,
        ])->assertCreated();

        $savedStudent = Student::where('student_code', 'STD2026DS01')->firstOrFail();
        $this->assertSame($fosatId, (int) $savedStudent->department_id);
        $this->assertSame($dsMajorId, (int) $savedStudent->program_id);
    }
}
