<?php

namespace Tests\Feature;

use App\Models\ClassRoom;
use App\Models\Course;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacultyEvaluatorApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@slas.com')->firstOrFail();
    }

    public function test_requires_authentication_and_admin_role(): void
    {
        $this->getJson('/api/faculty-evaluators')->assertStatus(401);

        $teacher = User::where('email', 'teacher@slas.com')->firstOrFail();
        $this->actingAs($teacher)->getJson('/api/faculty-evaluators')->assertStatus(403);
    }

    public function test_lists_faculty_with_pagination_and_search(): void
    {
        $res = $this->actingAs($this->admin)->getJson('/api/faculty-evaluators?per_page=2')->assertOk();
        $res->assertJsonStructure(['data' => [['id', 'faculty_id', 'name', 'review_status']], 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
        $this->assertLessThanOrEqual(2, count($res->json('data')));

        $teacher = Teacher::firstOrFail();
        $found = $this->actingAs($this->admin)
            ->getJson('/api/faculty-evaluators?search=' . $teacher->teacher_code)->assertOk();
        $this->assertSame($teacher->teacher_code, $found->json('data.0.faculty_id'));
    }

    public function test_statistics_come_from_database(): void
    {
        $teacher = Teacher::firstOrFail();
        $this->actingAs($this->admin)->getJson('/api/faculty-evaluators/statistics')
            ->assertOk()->assertJsonPath('data.faculty_reviewed', 0)
            ->assertJsonPath('data.total_faculty', Teacher::count());

        $this->actingAs($this->admin)->postJson("/api/faculty-evaluators/{$teacher->id}/reviews", [
            'status' => 'completed', 'rating' => 4.5,
        ])->assertStatus(201);

        $this->actingAs($this->admin)->getJson('/api/faculty-evaluators/statistics')
            ->assertJsonPath('data.faculty_reviewed', 1)
            ->assertJsonPath('data.reviews_needed', Teacher::count() - 1);
    }

    public function test_assign_courses_and_classes(): void
    {
        $teacher = Teacher::firstOrFail();
        $course = Course::firstOrFail();
        $class = ClassRoom::firstOrFail();

        $this->actingAs($this->admin)->postJson("/api/faculty-evaluators/{$teacher->id}/assign-courses", [
            'course_ids' => [$course->id],
        ])->assertOk();
        $this->assertSame($teacher->id, $course->fresh()->teacher_id);

        $this->actingAs($this->admin)->postJson("/api/faculty-evaluators/{$teacher->id}/assign-classes", [
            'class_ids' => [$class->id],
        ])->assertOk();
        $this->assertDatabaseHas('class_teacher', ['class_id' => $class->id, 'teacher_id' => $teacher->id]);

        $this->actingAs($this->admin)->getJson("/api/faculty-evaluators/{$teacher->id}/classes")
            ->assertOk()->assertJsonPath('data.0.id', $class->id);

        $this->actingAs($this->admin)->deleteJson("/api/faculty-evaluators/{$teacher->id}/assignments", [
            'course_ids' => [$course->id], 'class_ids' => [$class->id],
        ])->assertOk();
        $this->assertNull($course->fresh()->teacher_id);
        $this->assertDatabaseMissing('class_teacher', ['class_id' => $class->id, 'teacher_id' => $teacher->id]);
    }

    public function test_review_validation(): void
    {
        $teacher = Teacher::firstOrFail();
        $this->actingAs($this->admin)->postJson("/api/faculty-evaluators/{$teacher->id}/reviews", ['status' => 'completed'])
            ->assertStatus(422)->assertJsonValidationErrors('rating');
        $this->actingAs($this->admin)->postJson("/api/faculty-evaluators/{$teacher->id}/assign-courses", ['course_ids' => [999999]])
            ->assertStatus(422);
    }

    public function test_rating_and_pass_rate_are_unavailable_without_records(): void
    {
        $teacher = Teacher::firstOrFail();
        $res = $this->actingAs($this->admin)->getJson("/api/faculty-evaluators/{$teacher->id}")->assertOk();
        $this->assertNull($res->json('data.student_rating'));
    }
}
