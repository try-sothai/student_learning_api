<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AcademicAnalyticsModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_to_protected_analytics_and_incidents_are_rejected(): void
    {
        foreach ([
            '/api/learning-analytics',
            '/api/predictions',
            '/api/risk-assessments',
            '/api/recommendations',
            '/api/incidents',
            '/api/admin/dashboard/statistics',
            '/api/admin/analytics/model-evaluation',
        ] as $uri) {
            $this->getJson($uri)->assertStatus(401);
        }
    }

    public function test_student_cannot_access_admin_statistics_model_evaluation_or_incidents(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);

        $this->getJson('/api/admin/dashboard/statistics')->assertStatus(403);
        $this->getJson('/api/admin/analytics/model-evaluation')->assertStatus(403);
        $this->getJson('/api/incidents')->assertStatus(403);
    }

    public function test_admin_statistics_and_model_evaluation_return_real_structure(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $stats = $this->getJson('/api/admin/dashboard/statistics');
        $stats->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'total_students',
                    'total_teachers',
                    'total_courses',
                    'total_predictions',
                    'risk_distribution' => ['low', 'medium', 'high', 'unassessed', 'total_assessed'],
                    'grade_distribution' => ['A', 'B', 'C', 'D', 'F'],
                    'grade_counts' => ['A', 'B', 'C', 'D', 'F'],
                    'total_graded_records',
                ],
            ]);

        $ml = $this->getJson('/api/admin/analytics/model-evaluation');
        $ml->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'has_data',
                    'total_predictions',
                    'evaluated_count',
                    'mae',
                    'rmse',
                    'r2',
                    'actual_scores',
                    'predicted_scores',
                    'evaluation_labels',
                    'recent_evaluations',
                ],
            ]);
    }

    public function test_admin_can_create_list_and_update_incident_with_persistence(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $create = $this->postJson('/api/incidents', [
            'title' => 'Test Attendance Drop',
            'category' => 'Attendance',
            'priority' => 'High Priority',
            'status' => 'Open',
        ]);
        $create->assertStatus(201);
        $id = $create->json('data.id');

        $this->assertDatabaseHas('incidents', [
            'id' => $id,
            'title' => 'Test Attendance Drop',
            'status' => 'Open',
        ]);

        $update = $this->putJson("/api/incidents/{$id}", [
            'status' => 'Resolved',
            'resolution_notes' => 'Advisor contacted student.',
        ]);
        $update->assertStatus(200)
            ->assertJsonPath('data.status', 'Resolved');

        $list = $this->getJson('/api/incidents?status=Resolved');
        $list->assertStatus(200);
        $this->assertTrue(collect($list->json('data'))->contains('id', $id));
    }
}
