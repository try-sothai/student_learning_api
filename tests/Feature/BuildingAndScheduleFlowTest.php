<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Admin;
use App\Models\ClassRoom;
use App\Models\Course;
use App\Models\Department;
use App\Models\Program;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BuildingAndScheduleFlowTest extends TestCase
{
    use RefreshDatabase;

    private function createAdminUser(): User
    {
        $user = User::create([
            'name' => 'Admin SLAS',
            'email' => 'admin@slas.com',
            'password' => Hash::make('Admin@123'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        Admin::create([
            'user_id' => $user->id,
            'admin_code' => 'ADM001',
            'first_name' => 'SLAS',
            'last_name' => 'Administrator',
            'status' => 'active',
        ]);

        return $user;
    }

    public function test_public_student_registration_stays_disabled(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test',
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(403);
    }

    public function test_empty_teacher_and_student_state_returns_clean_zero_metrics(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/dashboard/overview')
            ->assertOk()
            ->assertJsonPath('data.total_students_count', 0);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/statistics')
            ->assertOk()
            ->assertJsonPath('data.total_teachers', 0)
            ->assertJsonPath('data.total_students', 0);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/academic-structure')
            ->assertOk()
            ->assertJsonCount(0, 'data.teacher_assignments');
    }

    public function test_building_floor_class_hierarchy_and_schedule_conflict_detection(): void
    {
        $admin = $this->createAdminUser();

        $ay = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-10-01',
            'end_date' => '2027-07-31',
            'status' => 'active',
        ]);

        $sem = Semester::create([
            'academic_year_id' => $ay->id,
            'name' => 'Semester 1',
            'semester_number' => 1,
            'start_date' => '2026-10-01',
            'end_date' => '2027-02-28',
            'status' => 'active',
        ]);

        $dept = Department::create(['name' => 'Computer Science', 'code' => 'CS', 'status' => 'active']);
        $program = Program::create([
            'department_id' => $dept->id,
            'code' => 'BSCS',
            'name' => 'Computer Science',
            'duration_years' => 4,
            'status' => 'active',
        ]);

        $c1 = Course::create(['department_id' => $dept->id, 'code' => 'DS101', 'course_code' => 'DS101', 'name' => 'Data Science', 'course_name' => 'Data Science', 'credits' => 3, 'credit' => 3, 'status' => 'active']);
        $c2 = Course::create(['department_id' => $dept->id, 'code' => 'CS305', 'course_code' => 'CS305', 'name' => 'Android App', 'course_name' => 'Android App', 'credits' => 3, 'credit' => 3, 'status' => 'active']);
        $c3 = Course::create(['department_id' => $dept->id, 'code' => 'IT205', 'course_code' => 'IT205', 'name' => 'Network Administration', 'course_name' => 'Network Administration', 'credits' => 3, 'credit' => 3, 'status' => 'active']);

        // Create Building A with 4 floors
        $bRes = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/buildings', [
                'name' => 'Building A',
                'code' => 'BLDG-A',
                'total_floors' => 4,
                'status' => 'active',
            ])
            ->assertCreated();

        $buildingId = $bRes->json('data.id');
        $floors = $bRes->json('data.floors');
        $this->assertCount(4, $floors);
        $floor1Id = $floors[0]['id'];
        $floor2Id = $floors[1]['id'];

        // Create Class A on Floor 1, Class B on Floor 1
        $classARes = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/classes', [
                'department_id' => $dept->id,
                'academic_year_id' => $ay->id,
                'building_id' => $buildingId,
                'floor_id' => $floor1Id,
                'name' => 'Class A',
                'code' => 'CLASS-A',
                'year_level' => 1,
                'capacity' => 40,
            ])
            ->assertCreated()
            ->assertJsonPath('data.room', 'Class A');

        $classAId = $classARes->json('data.id');

        $classB = ClassRoom::create([
            'department_id' => $dept->id,
            'academic_year_id' => $ay->id,
            'building_id' => $buildingId,
            'floor_id' => $floor1Id,
            'name' => 'Class B',
            'code' => 'CLASS-B',
            'year_level' => 1,
            'room' => 'Class B',
            'capacity' => 40,
            'status' => 'active',
        ]);

        // Create Teacher & Student via Admin
        $tUser = User::create(['name' => 'Teacher One', 'email' => 't1@slas.com', 'password' => Hash::make('pass1234'), 'role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $tUser->id, 'teacher_code' => 'TCH001', 'department_id' => $dept->id, 'first_name' => 'Teacher', 'last_name' => 'One', 'status' => 'active']);

        $sUser = User::create(['name' => 'Student One', 'email' => 's1@slas.com', 'password' => Hash::make('pass1234'), 'role' => 'student', 'status' => 'active']);
        $student = Student::create([
            'user_id' => $sUser->id,
            'student_code' => 'STD001',
            'department_id' => $dept->id,
            'program_id' => $program->id,
            'first_name' => 'Student',
            'last_name' => 'One',
            'date_of_birth' => '2004-01-15',
            'gender' => 'Male',
            'year_level' => 1,
            'status' => 'enrolled',
        ]);

        // Schedule 3 different courses in Class A on Mon / Tue / Thu 18:00-21:00
        $this->actingAs($admin, 'sanctum')->postJson('/api/class-schedules', [
            'academic_year_id' => $ay->id, 'semester_id' => $sem->id, 'class_id' => $classAId,
            'course_id' => $c1->id, 'teacher_id' => $teacher->id,
            'day_of_week' => 'Monday', 'start_time' => '18:00', 'end_time' => '21:00',
        ])->assertCreated();

        $this->actingAs($admin, 'sanctum')->postJson('/api/class-schedules', [
            'academic_year_id' => $ay->id, 'semester_id' => $sem->id, 'class_id' => $classAId,
            'course_id' => $c2->id, 'teacher_id' => $teacher->id,
            'day_of_week' => 'Tuesday', 'start_time' => '18:00', 'end_time' => '21:00',
        ])->assertCreated();

        $this->actingAs($admin, 'sanctum')->postJson('/api/class-schedules', [
            'academic_year_id' => $ay->id, 'semester_id' => $sem->id, 'class_id' => $classAId,
            'course_id' => $c3->id, 'teacher_id' => $teacher->id,
            'day_of_week' => 'Thursday', 'start_time' => '18:00', 'end_time' => '21:00',
        ])->assertCreated();

        // Class conflict: same Class A overlapping Monday 19:00-20:30
        $this->actingAs($admin, 'sanctum')->postJson('/api/class-schedules', [
            'academic_year_id' => $ay->id, 'semester_id' => $sem->id, 'class_id' => $classAId,
            'course_id' => $c2->id,
            'day_of_week' => 'Monday', 'start_time' => '19:00', 'end_time' => '20:30',
        ])->assertStatus(422);

        // Teacher conflict: same Teacher in Class B overlapping Monday 18:30-20:00
        $this->actingAs($admin, 'sanctum')->postJson('/api/class-schedules', [
            'academic_year_id' => $ay->id, 'semester_id' => $sem->id, 'class_id' => $classB->id,
            'course_id' => $c2->id, 'teacher_id' => $teacher->id,
            'day_of_week' => 'Monday', 'start_time' => '18:30', 'end_time' => '20:00',
        ])->assertStatus(422);

        // Enroll student into Class A -> automatically syncs 3 course_enrollments
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/class-enrollments/classes/{$classAId}/bulk-enroll", [
                'student_ids' => [$student->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.enrolled_count', 1);

        $this->assertDatabaseHas('course_enrollments', ['student_id' => $student->id, 'course_id' => $c1->id]);
        $this->assertDatabaseHas('course_enrollments', ['student_id' => $student->id, 'course_id' => $c2->id]);
        $this->assertDatabaseHas('course_enrollments', ['student_id' => $student->id, 'course_id' => $c3->id]);

        // Student sees their 3 schedules, and cannot modify buildings
        $this->actingAs($sUser, 'sanctum')
            ->getJson('/api/my-schedules')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->actingAs($sUser, 'sanctum')
            ->postJson('/api/buildings', ['name' => 'Unauthorized Bldg'])
            ->assertStatus(403);
    }

    public function test_class_schedule_filtering_ordering_update_delete_and_location_sync(): void
    {
        $admin = $this->createAdminUser();

        $ay = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-10-01',
            'end_date' => '2027-07-31',
            'status' => 'active',
        ]);

        $sem = Semester::create([
            'academic_year_id' => $ay->id,
            'name' => 'Semester 1',
            'semester_number' => 1,
            'start_date' => '2026-10-01',
            'end_date' => '2027-02-28',
            'status' => 'active',
        ]);

        $dept = Department::create(['name' => 'Computer Science', 'code' => 'CS', 'status' => 'active']);
        $c1 = Course::create(['department_id' => $dept->id, 'code' => 'DS101', 'course_code' => 'DS101', 'name' => 'Data Science', 'course_name' => 'Data Science', 'credits' => 3, 'credit' => 3, 'status' => 'active']);
        $c2 = Course::create(['department_id' => $dept->id, 'code' => 'CS305', 'course_code' => 'CS305', 'name' => 'Android App', 'course_name' => 'Android App', 'credits' => 3, 'credit' => 3, 'status' => 'active']);

        $bRes = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/buildings', [
                'name' => 'Building A',
                'code' => 'BLDG-A',
                'total_floors' => 3,
                'status' => 'active',
            ])
            ->assertCreated();

        $buildingId = $bRes->json('data.id');
        $floors = $bRes->json('data.floors');
        $floor1Id = $floors[0]['id'];
        $floor2Id = $floors[1]['id'];

        $classA = ClassRoom::create([
            'department_id' => $dept->id, 'academic_year_id' => $ay->id,
            'building_id' => $buildingId, 'floor_id' => $floor1Id,
            'name' => 'Class A', 'code' => 'CLASS-A', 'year_level' => 1, 'room' => 'Class A', 'capacity' => 40, 'status' => 'active',
        ]);
        $classB = ClassRoom::create([
            'department_id' => $dept->id, 'academic_year_id' => $ay->id,
            'building_id' => $buildingId, 'floor_id' => $floor1Id,
            'name' => 'Class B', 'code' => 'CLASS-B', 'year_level' => 1, 'room' => 'Class B', 'capacity' => 40, 'status' => 'active',
        ]);
        $classC = ClassRoom::create([
            'department_id' => $dept->id, 'academic_year_id' => $ay->id,
            'building_id' => $buildingId, 'floor_id' => $floor1Id,
            'name' => 'Class C', 'code' => 'CLASS-C', 'year_level' => 1, 'room' => 'Class C', 'capacity' => 40, 'status' => 'active',
        ]);

        // All 3 classes visible via GET /api/classes
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/classes')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        // Insert Thursday before Monday and Tuesday to verify chronological ordering
        $thuRes = $this->actingAs($admin, 'sanctum')->postJson('/api/class-schedules', [
            'academic_year_id' => $ay->id, 'semester_id' => $sem->id, 'class_id' => $classA->id,
            'course_id' => $c2->id, 'day_of_week' => 'Thursday', 'start_time' => '18:00', 'end_time' => '21:00',
        ])->assertCreated();
        $thuId = $thuRes->json('data.id');

        $monRes = $this->actingAs($admin, 'sanctum')->postJson('/api/class-schedules', [
            'academic_year_id' => $ay->id, 'semester_id' => $sem->id, 'class_id' => $classA->id,
            'course_id' => $c1->id, 'day_of_week' => 'Monday', 'start_time' => '18:00', 'end_time' => '21:00',
        ])->assertCreated();
        $monId = $monRes->json('data.id');

        $tueRes = $this->actingAs($admin, 'sanctum')->postJson('/api/class-schedules', [
            'academic_year_id' => $ay->id, 'semester_id' => $sem->id, 'class_id' => $classA->id,
            'course_id' => $c1->id, 'day_of_week' => 'Tuesday', 'start_time' => '18:00', 'end_time' => '21:00',
        ])->assertCreated();
        $tueId = $tueRes->json('data.id');

        // Class B gets 1 schedule on Friday
        $this->actingAs($admin, 'sanctum')->postJson('/api/class-schedules', [
            'academic_year_id' => $ay->id, 'semester_id' => $sem->id, 'class_id' => $classB->id,
            'course_id' => $c2->id, 'day_of_week' => 'Friday', 'start_time' => '18:00', 'end_time' => '21:00',
        ])->assertCreated();

        // Class A filter returns strictly Class A's 3 schedules ordered Monday -> Tuesday -> Thursday
        $classASchedules = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/class-schedules?class_id={$classA->id}")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->json('data');

        $this->assertSame(['Monday', 'Tuesday', 'Thursday'], array_column($classASchedules, 'day_of_week'));

        // Empty Class C returns 200 with empty array
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/class-schedules?class_id={$classC->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Update Thursday schedule to overlap Monday -> rejected with 422
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/class-schedules/{$thuId}", [
                'day_of_week' => 'Monday',
                'start_time' => '19:00',
                'end_time' => '20:30',
            ])
            ->assertStatus(422);

        // Update Thursday schedule to Wednesday -> accepted (200)
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/class-schedules/{$thuId}", [
                'day_of_week' => 'Wednesday',
                'start_time' => '18:00',
                'end_time' => '20:00',
            ])
            ->assertOk()
            ->assertJsonPath('data.day_of_week', 'Wednesday');

        // Moving Class A to Floor 2 syncs existing schedules for Class A
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/classes/{$classA->id}", [
                'department_id' => $dept->id,
                'academic_year_id' => $ay->id,
                'building_id' => $buildingId,
                'floor_id' => $floor2Id,
                'name' => 'Class A',
                'code' => 'CLASS-A',
                'year_level' => 1,
            ])
            ->assertOk();

        $this->assertDatabaseHas('class_schedules', ['id' => $monId, 'floor_id' => $floor2Id]);
        $this->assertDatabaseHas('class_schedules', ['id' => $tueId, 'floor_id' => $floor2Id]);

        // Delete one schedule from Class A -> remaining 2
        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/class-schedules/{$tueId}")
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/class-schedules?class_id={$classA->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
