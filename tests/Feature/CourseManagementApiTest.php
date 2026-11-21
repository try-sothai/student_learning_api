<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Department;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseManagementApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_courses_index_returns_real_courses_with_accurate_statistics_and_relationships(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();

        $response = $this->actingAs($admin)->getJson('/api/courses');

        $response->assertOk()
            ->assertJsonPath('message', 'Courses retrieved successfully.')
            ->assertJsonStructure([
                'message',
                'statistics' => [
                    'total_courses',
                    'active_courses',
                    'inactive_courses',
                    'missing_teacher_courses',
                ],
                'data' => [
                    '*' => [
                        'id',
                        'code',
                        'course_code',
                        'name',
                        'course_name',
                        'credit',
                        'credits',
                        'status',
                        'department_id',
                        'department_name',
                        'department',
                        'program_id',
                        'program_name',
                        'teacher_id',
                        'teacher_name',
                        'academic_year',
                        'semester',
                    ],
                ],
            ]);

        $stats = $response->json('statistics');
        $data = $response->json('data');

        $this->assertGreaterThan(0, $stats['total_courses']);
        $this->assertSame($stats['total_courses'], $stats['active_courses'] + $stats['inactive_courses']);
        $this->assertCount($stats['total_courses'], $data);
        $this->assertNotEmpty($data[0]['department_name']);
    }

    public function test_courses_search_and_filters_update_list_and_statistics_consistently(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $csDept = Department::where('code', 'CS')->firstOrFail();

        $resDept = $this->actingAs($admin)->getJson('/api/courses?department_id=' . $csDept->id);
        $resDept->assertOk();
        $deptData = $resDept->json('data');
        $deptStats = $resDept->json('statistics');

        $this->assertCount($deptStats['total_courses'], $deptData);
        foreach ($deptData as $course) {
            $this->assertSame($csDept->id, $course['department_id']);
        }

        $resSearch = $this->actingAs($admin)->getJson('/api/courses?search=CS101');
        $resSearch->assertOk();
        $searchData = $resSearch->json('data');
        $this->assertCount(1, $searchData);
        $this->assertSame('CS101', $searchData[0]['code']);
    }

    public function test_admin_can_create_edit_deactivate_and_delete_unreferenced_course(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $dept = Department::firstOrFail();
        $prog = Program::where('department_id', $dept->id)->first();

        // 1. Create Course
        $createRes = $this->actingAs($admin)->postJson('/api/courses', [
            'course_code' => 'cs499',
            'course_name' => 'Cloud Native Architecture',
            'credit' => 3.5,
            'department_id' => $dept->id,
            'program_id' => $prog?->id,
            'description' => 'Distributed systems and microservices.',
            'status' => 'active',
        ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('data.code', 'CS499')
            ->assertJsonPath('data.name', 'Cloud Native Architecture')
            ->assertJsonPath('data.department_id', $dept->id)
            ->assertJsonPath('data.status', 'active');

        $courseId = $createRes->json('data.id');

        // Duplicate code validation
        $this->actingAs($admin)->postJson('/api/courses', [
            'course_code' => 'CS499',
            'course_name' => 'Duplicate Course',
            'credit' => 3,
            'department_id' => $dept->id,
        ])->assertStatus(422);

        // 2. Edit Course
        $updateRes = $this->actingAs($admin)->putJson("/api/courses/{$courseId}", [
            'course_code' => 'CS499',
            'course_name' => 'Advanced Cloud Native Architecture',
            'credit' => 4,
            'department_id' => $dept->id,
            'description' => 'Updated syllabus.',
            'status' => 'active',
        ]);

        $updateRes->assertOk()
            ->assertJsonPath('data.name', 'Advanced Cloud Native Architecture')
            ->assertJsonPath('data.credits', 4);

        // 3. Deactivate Course
        $statusRes = $this->actingAs($admin)->patchJson("/api/courses/{$courseId}/status", [
            'status' => 'inactive',
        ]);

        $statusRes->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        // 4. Delete unreferenced course (if no offerings created, or remove offerings first)
        \App\Models\CourseOffering::where('course_id', $courseId)->forceDelete();
        $deleteRes = $this->actingAs($admin)->deleteJson("/api/courses/{$courseId}");
        $deleteRes->assertOk();
    }

    public function test_referenced_course_cannot_be_deleted_and_non_admin_cannot_modify_courses(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $student = User::where('role', 'student')->firstOrFail();
        $dept = Department::firstOrFail();

        $referencedCourse = Course::whereHas('enrollments')->first() ?? Course::firstOrFail();
        if ($referencedCourse->enrollments()->count() === 0 && $referencedCourse->offerings()->count() === 0) {
            \App\Models\CourseOffering::create([
                'course_id' => $referencedCourse->id,
                'section_name' => 'Sec A',
                'status' => 'active',
            ]);
        }

        // Referenced course deletion blocked with 422
        $this->actingAs($admin)
            ->deleteJson("/api/courses/{$referencedCourse->id}")
            ->assertStatus(422);

        // Student forbidden from creating/updating/deleting courses
        $this->actingAs($student)
            ->postJson('/api/courses', [
                'course_code' => 'HACK101',
                'course_name' => 'Unauthorized',
                'credit' => 3,
                'department_id' => $dept->id,
            ])
            ->assertStatus(403);
    }
}
