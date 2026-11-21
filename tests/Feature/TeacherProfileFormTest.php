<?php

namespace Tests\Feature;

use App\Models\OfficeLocation;
use App\Models\Specialization;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherProfileFormTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $teacherUser;
    private User $studentUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@slas.com')->firstOrFail();
        $this->teacherUser = User::where('email', 'teacher@slas.com')->firstOrFail();
        $this->studentUser = User::where('email', 'student@slas.com')->firstOrFail();
    }

    public function test_teacher_profile_catalog_endpoints_require_admin_role(): void
    {
        $this->getJson('/api/admin/teachers/lookups')->assertStatus(401);
        $this->postJson('/api/admin/office-locations', ['building' => 'Building Z', 'room_number' => '101'])->assertStatus(401);
        $this->postJson('/api/admin/specializations', ['name' => 'Robotics'])->assertStatus(401);

        $this->actingAs($this->teacherUser)->getJson('/api/admin/teachers/lookups')->assertStatus(403);
        $this->actingAs($this->teacherUser)->postJson('/api/admin/office-locations', ['building' => 'Building Z', 'room_number' => '101'])->assertStatus(403);
        $this->actingAs($this->studentUser)->postJson('/api/admin/specializations', ['name' => 'Robotics'])->assertStatus(403);
        $this->actingAs($this->teacherUser)->postJson('/api/teachers', [])->assertStatus(403);
    }

    public function test_office_location_lookup_groups_by_building_and_filters_by_building_or_room(): void
    {
        $all = $this->actingAs($this->admin)->getJson('/api/admin/teachers/lookups')->assertOk();
        $groups = collect($all->json('data.office_locations_grouped'));
        $this->assertTrue($groups->contains('building', 'Building A'));
        $this->assertTrue($groups->contains('building', 'Building B'));

        $byBuilding = $this->actingAs($this->admin)->getJson('/api/admin/teachers/lookups?search=Building%20B')->assertOk();
        $bGroups = $byBuilding->json('data.office_locations_grouped');
        $this->assertCount(1, $bGroups);
        $this->assertSame('Building B', $bGroups[0]['building']);

        $byRoom = $this->actingAs($this->admin)->getJson('/api/admin/teachers/lookups?search=203')->assertOk();
        $rOffices = $byRoom->json('data.office_locations');
        $this->assertNotEmpty($rOffices);
        $this->assertStringContainsString('203', $rOffices[0]['room_number']);
    }

    public function test_admin_can_add_office_location_and_specialization_without_duplicates(): void
    {
        $loc1 = $this->actingAs($this->admin)->postJson('/api/admin/office-locations', [
            'building' => 'Building D',
            'room_number' => '305',
        ])->assertCreated()
            ->assertJsonPath('data.building', 'Building D')
            ->assertJsonPath('data.room_number', 'Room 305')
            ->assertJsonPath('data.display_name', 'Building D, Room 305');

        $locDup = $this->actingAs($this->admin)->postJson('/api/admin/office-locations', [
            'building' => 'building d',
            'room_number' => 'Room 305',
        ])->assertOk();
        $this->assertSame($loc1->json('data.id'), $locDup->json('data.id'));

        $spec1 = $this->actingAs($this->admin)->postJson('/api/admin/specializations', [
            'name' => 'សន្តិសុខព័ត៌មាន និងបណ្តាញ',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'សន្តិសុខព័ត៌មាន និងបណ្តាញ');

        $specDup = $this->actingAs($this->admin)->postJson('/api/admin/specializations', [
            'name' => 'សន្តិសុខព័ត៌មាន និងបណ្តាញ',
        ])->assertOk();
        $this->assertSame($spec1->json('data.id'), $specDup->json('data.id'));
    }

    public function test_add_and_edit_teacher_persist_office_multiple_specializations_and_khmer_english_biography(): void
    {
        $office = OfficeLocation::where('display_name', 'Building A, Room 201')->firstOrFail();
        $khmerEngBio = "សាស្ត្រាចារ្យផ្នែកវិស្វកម្មសូហ្វវែរ និងបញ្ញាសិប្បនិម្មិត។\nResearch focus: Distributed Systems & Applied ML.";

        $create = $this->actingAs($this->admin)->postJson('/api/teachers', [
            'teacher_id' => 'TCH910',
            'first_name' => 'Sokha',
            'last_name' => 'Dara',
            'email' => 'sokha.dara@slas.edu.kh',
            'password' => 'TeacherPass123!',
            'password_confirmation' => 'TeacherPass123!',
            'department' => 'Computer Science',
            'position' => 'Senior Lecturer',
            'work_type' => 'Full-time',
            'office_location_id' => $office->id,
            'specializations' => ['Data Structures', 'Algorithms', 'Cybersecurity'],
            'biography' => $khmerEngBio,
            'status' => 'active',
        ])->assertCreated()
            ->assertJsonPath('data.teacher_id', 'TCH910')
            ->assertJsonPath('data.office_location_id', $office->id)
            ->assertJsonPath('data.office_location', 'Building A, Room 201')
            ->assertJsonPath('data.biography', $khmerEngBio);

        $teacher = Teacher::with(['office', 'specializationItems'])->where('teacher_code', 'TCH910')->firstOrFail();
        $this->assertSame($office->id, $teacher->office_location_id);
        $this->assertCount(3, $teacher->specializationItems);
        $this->assertSame(['Data Structures', 'Algorithms', 'Cybersecurity'], $teacher->specializations);
        $this->assertSame($khmerEngBio, $teacher->biography);

        // Update via AdminUserController (EditTeacherActivity path), changing office, specializations & Khmer/English bio
        $updatedBio = "បទពិសោធន៍បង្រៀន ១០ឆ្នាំ។\n10+ years teaching Database Systems and Cloud Architecture.";
        $this->actingAs($this->admin)->putJson('/api/admin/users/' . $teacher->user_id, [
            'name' => 'Sokha Dara',
            'email' => 'sokha.dara@slas.edu.kh',
            'department_name' => 'Information Technology',
            'position' => 'Associate Professor',
            'work_type' => 'Part-time',
            'office_location' => 'Building E, Room 404',
            'specializations' => ['Database Systems', 'វិស្វកម្មសូហ្វវែរ', 'Cloud Computing'],
            'biography' => $updatedBio,
            'status' => 'active',
        ])->assertOk()
            ->assertJsonPath('data.office_location', 'Building E, Room 404')
            ->assertJsonPath('data.work_type', 'Part-time')
            ->assertJsonPath('data.biography', $updatedBio);

        $teacher->refresh()->load(['office', 'specializationItems']);
        $this->assertSame('Building E, Room 404', $teacher->office->display_name);
        $this->assertSame(['Database Systems', 'វិស្វកម្មសូហ្វវែរ', 'Cloud Computing'], $teacher->specializations);
        $this->assertSame($updatedBio, $teacher->biography);

        // Clear all optional fields cleanly without errors
        $this->actingAs($this->admin)->putJson('/api/admin/users/' . $teacher->user_id, [
            'name' => 'Sokha Dara',
            'email' => 'sokha.dara@slas.edu.kh',
            'office_location_id' => null,
            'office_location' => '',
            'specializations' => [],
            'biography' => '',
            'status' => 'active',
        ])->assertOk()
            ->assertJsonPath('data.office_location', '');

        $teacher->refresh();
        $this->assertNull($teacher->office_location_id);
        $this->assertNull($teacher->biography);
        $this->assertSame([], $teacher->specializations);
    }

    public function test_web_admin_add_and_edit_teacher_forms_work_with_optional_profile_fields(): void
    {
        $this->actingAs($this->admin)->get('/admin/teachers')->assertOk()
            ->assertSee('Office Location')
            ->assertSee('Specialization')
            ->assertSee('Biography');

        $office = OfficeLocation::where('display_name', 'Building B, Room 202')->firstOrFail();

        $this->actingAs($this->admin)->post('/admin/teachers', [
            'teacher_id' => 'TCHWEB01',
            'first_name' => 'Vanna',
            'last_name' => 'Keo',
            'email' => 'vanna.keo@slas.edu.kh',
            'password' => 'TeacherPass123!',
            'password_confirmation' => 'TeacherPass123!',
            'department' => 'Computer Science',
            'position' => 'Lecturer',
            'work_type' => 'Full-time',
            'office_location_id' => $office->id,
            'specializations' => ['Networking', 'Cybersecurity'],
            'biography' => "សាស្ត្រាចារ្យបណ្តាញកុំព្យូទ័រ\nNetwork Security Specialist",
            'status' => 'active',
        ])->assertRedirect(route('admin.teachers'));

        $teacher = Teacher::where('teacher_code', 'TCHWEB01')->firstOrFail();
        $this->actingAs($this->admin)->get("/admin/teachers/{$teacher->id}/edit")->assertOk()
            ->assertSee('Building B, Room 202')
            ->assertSee('Networking')
            ->assertSee('Cybersecurity');
    }
}
