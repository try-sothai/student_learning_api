<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Admin;
use App\Models\Attendance;
use App\Models\AttendanceDetail;
use App\Models\ClassRoom;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CourseOfferingTeacher;
use App\Models\Department;
use App\Models\GpaRecord;
use App\Models\LearningAnalytic;
use App\Models\Notification;
use App\Models\PerformanceRecord;
use App\Models\Prediction;
use App\Models\Program;
use App\Models\Recommendation;
use App\Models\Report;
use App\Models\RiskAssessment;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SystemWideAuditAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private User $teacherUserA;
    private User $teacherUserB;
    private User $studentUserA;
    private User $studentUserB;

    private Teacher $teacherA;
    private Teacher $teacherB;
    private Student $studentA;
    private Student $studentB;

    private AcademicYear $ay;
    private Semester $semester;
    private Course $courseA;
    private Course $courseB;
    private CourseOffering $offeringA;
    private CourseOffering $offeringB;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create(['name' => 'Computer Science', 'code' => 'CS', 'status' => 'active']);
        $prog = Program::create(['department_id' => $dept->id, 'name' => 'CS Degree', 'code' => 'BSCS', 'status' => 'active']);
        $this->ay = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-10-01',
            'end_date' => '2027-07-31',
            'status' => 'active',
        ]);
        $this->semester = Semester::create([
            'academic_year_id' => $this->ay->id,
            'name' => 'Semester 1',
            'semester_number' => 1,
            'start_date' => '2026-10-01',
            'end_date' => '2027-02-28',
            'status' => 'active',
        ]);

        $classA = ClassRoom::create([
            'department_id' => $dept->id,
            'program_id' => $prog->id,
            'academic_year_id' => $this->ay->id,
            'code' => 'CS1-A',
            'name' => 'CS Year 1 A',
            'year_level' => 1,
            'status' => 'active',
        ]);

        $this->adminUser = User::create([
            'name' => 'Admin SLAS',
            'email' => 'admin.audit@slas.test',
            'password' => Hash::make('Admin@123'),
            'role' => 'admin',
            'status' => 'active',
        ]);
        Admin::create([
            'user_id' => $this->adminUser->id,
            'admin_code' => 'ADM900',
            'first_name' => 'Admin',
            'last_name' => 'SLAS',
            'status' => 'active',
        ]);

        $this->teacherUserA = User::create([
            'name' => 'Teacher A',
            'email' => 'teachera@slas.test',
            'password' => Hash::make('Teacher@123'),
            'role' => 'teacher',
            'status' => 'active',
        ]);
        $this->teacherA = Teacher::create([
            'user_id' => $this->teacherUserA->id,
            'teacher_code' => 'TCH901',
            'first_name' => 'Teacher',
            'last_name' => 'A',
            'status' => 'active',
        ]);

        $this->teacherUserB = User::create([
            'name' => 'Teacher B',
            'email' => 'teacherb@slas.test',
            'password' => Hash::make('Teacher@123'),
            'role' => 'teacher',
            'status' => 'active',
        ]);
        $this->teacherB = Teacher::create([
            'user_id' => $this->teacherUserB->id,
            'teacher_code' => 'TCH902',
            'first_name' => 'Teacher',
            'last_name' => 'B',
            'status' => 'active',
        ]);

        $this->studentUserA = User::create([
            'name' => 'Student A',
            'email' => 'studenta@slas.test',
            'password' => Hash::make('Student@123'),
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->studentA = Student::create([
            'user_id' => $this->studentUserA->id,
            'student_code' => 'STD901',
            'first_name' => 'Student',
            'last_name' => 'A',
            'gender' => 'Male',
            'department_id' => $dept->id,
            'program_id' => $prog->id,
            'class_id' => $classA->id,
            'year_level' => 1,
            'status' => 'enrolled',
        ]);

        $this->studentUserB = User::create([
            'name' => 'Student B',
            'email' => null,
            'password' => Hash::make('Student@123'),
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->studentB = Student::create([
            'user_id' => $this->studentUserB->id,
            'student_code' => 'STD902',
            'first_name' => 'Student',
            'last_name' => 'B',
            'gender' => 'Female',
            'department_id' => $dept->id,
            'program_id' => $prog->id,
            'class_id' => $classA->id,
            'year_level' => 1,
            'status' => 'enrolled',
        ]);

        $this->courseA = Course::create([
            'department_id' => $dept->id,
            'program_id' => $prog->id,
            'teacher_id' => $this->teacherA->id,
            'code' => 'CS901',
            'name' => 'Algorithms',
            'credits' => 3,
            'year_level' => 1,
            'status' => 'active',
        ]);
        $this->courseB = Course::create([
            'department_id' => $dept->id,
            'program_id' => $prog->id,
            'teacher_id' => $this->teacherB->id,
            'code' => 'CS902',
            'name' => 'Databases',
            'credits' => 3,
            'year_level' => 1,
            'status' => 'active',
        ]);

        $this->offeringA = CourseOffering::create([
            'course_id' => $this->courseA->id,
            'semester_id' => $this->semester->id,
            'class_id' => $classA->id,
            'section_name' => 'Sec A',
            'status' => 'active',
        ]);
        $this->offeringB = CourseOffering::create([
            'course_id' => $this->courseB->id,
            'semester_id' => $this->semester->id,
            'class_id' => $classA->id,
            'section_name' => 'Sec B',
            'status' => 'active',
        ]);

        CourseOfferingTeacher::create([
            'course_offering_id' => $this->offeringA->id,
            'teacher_id' => $this->teacherA->id,
            'role' => 'primary',
            'status' => 'active',
        ]);
        CourseOfferingTeacher::create([
            'course_offering_id' => $this->offeringB->id,
            'teacher_id' => $this->teacherB->id,
            'role' => 'primary',
            'status' => 'active',
        ]);

        CourseEnrollment::create([
            'student_id' => $this->studentA->id,
            'course_id' => $this->courseA->id,
            'course_offering_id' => $this->offeringA->id,
            'semester_id' => $this->semester->id,
            'year_level' => 1,
            'status' => 'enrolled',
        ]);
        CourseEnrollment::create([
            'student_id' => $this->studentB->id,
            'course_id' => $this->courseB->id,
            'course_offering_id' => $this->offeringB->id,
            'semester_id' => $this->semester->id,
            'year_level' => 1,
            'status' => 'enrolled',
        ]);
    }

    public function test_previously_public_endpoints_require_authentication(): void
    {
        $routes = [
            '/api/students',
            '/api/teachers',
            '/api/departments',
            '/api/academic-years',
            '/api/semesters',
            '/api/classes',
            '/api/courses',
            '/api/course-enrollments',
            '/api/attendance',
            '/api/attendance-details',
            '/api/notifications',
            '/api/notifications/unread-count',
            '/api/reports',
        ];

        foreach ($routes as $uri) {
            $this->getJson($uri)->assertStatus(401);
        }
    }

    public function test_notifications_unread_count_route_and_cross_user_isolation(): void
    {
        $notifA = Notification::create([
            'user_id' => $this->studentUserA->id,
            'title' => 'Private A',
            'message' => 'Only for Student A',
            'type' => 'grade',
            'is_read' => false,
        ]);
        Notification::create([
            'user_id' => $this->teacherUserA->id,
            'title' => 'Teacher Alert',
            'message' => 'Only for Teacher A',
            'type' => 'system',
            'is_read' => false,
        ]);

        $this->actingAs($this->teacherUserA, 'sanctum')
            ->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.unread_count', 1);

        // Student B and Teacher B cannot read/modify/delete Student A's notification
        foreach ([$this->studentUserB, $this->teacherUserB] as $other) {
            $this->actingAs($other, 'sanctum')
                ->getJson("/api/notifications/{$notifA->id}")
                ->assertStatus(403);
            $this->actingAs($other, 'sanctum')
                ->patchJson("/api/notifications/{$notifA->id}/read")
                ->assertStatus(403);
            $this->actingAs($other, 'sanctum')
                ->deleteJson("/api/notifications/{$notifA->id}")
                ->assertStatus(403);
        }

        // Student cannot broadcast notifications
        $this->actingAs($this->studentUserA, 'sanctum')
            ->postJson('/api/notifications', [
                'user_id' => $this->studentUserB->id,
                'title' => 'Spoof',
                'message' => 'Test',
            ])
            ->assertStatus(403);
    }

    public function test_cross_role_scoping_blocks_unauthorized_student_and_teacher_access(): void
    {
        $attA = Attendance::create([
            'course_id' => $this->courseA->id,
            'course_offering_id' => $this->offeringA->id,
            'teacher_id' => $this->teacherA->id,
            'semester_id' => $this->semester->id,
            'attendance_date' => now()->toDateString(),
            'status' => 'completed',
        ]);
        $attDetailA = AttendanceDetail::create([
            'attendance_id' => $attA->id,
            'student_id' => $this->studentA->id,
            'status' => 'present',
        ]);
        $gpaB = GpaRecord::create([
            'student_id' => $this->studentB->id,
            'semester_id' => $this->semester->id,
            'gpa' => 3.5,
            'total_credits' => 3,
            'earned_credits' => 3,
        ]);
        $perfB = PerformanceRecord::create([
            'student_id' => $this->studentB->id,
            'course_id' => $this->courseB->id,
            'semester_id' => $this->semester->id,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'overall_score' => 88,
        ]);
        $analyticB = LearningAnalytic::create([
            'student_id' => $this->studentB->id,
            'semester_id' => $this->semester->id,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'overall_performance' => 88,
            'calculated_at' => now(),
        ]);
        $predB = Prediction::create([
            'student_id' => $this->studentB->id,
            'course_id' => $this->courseB->id,
            'semester_id' => $this->semester->id,
            'prediction_type' => 'final',
            'predicted_score' => 85,
            'prediction_date' => now()->toDateString(),
        ]);
        $riskB = RiskAssessment::create([
            'student_id' => $this->studentB->id,
            'course_id' => $this->courseB->id,
            'semester_id' => $this->semester->id,
            'risk_level' => 'low',
            'risk_score' => 15,
            'assessment_date' => now()->toDateString(),
        ]);
        $recB = Recommendation::create([
            'student_id' => $this->studentB->id,
            'course_id' => null,
            'semester_id' => $this->semester->id,
            'title' => 'Study Plan',
            'message' => 'Review chapter 2',
        ]);
        $repB = Report::create([
            'report_type' => 'student',
            'title' => 'Student B Report',
            'student_id' => $this->studentB->id,
            'teacher_id' => $this->teacherB->id,
            'semester_id' => $this->semester->id,
        ]);

        // Teacher A cannot view or mutate Student B records
        $this->actingAs($this->teacherUserA, 'sanctum')
            ->getJson("/api/students/{$this->studentB->id}")->assertStatus(403);
        $this->actingAs($this->teacherUserA, 'sanctum')
            ->getJson("/api/gpa-records/{$gpaB->id}")->assertStatus(403);
        $this->actingAs($this->teacherUserA, 'sanctum')
            ->getJson("/api/performance-records/{$perfB->id}")->assertStatus(403);
        $this->actingAs($this->teacherUserA, 'sanctum')
            ->getJson("/api/learning-analytics/{$analyticB->id}")->assertStatus(403);
        $this->actingAs($this->teacherUserA, 'sanctum')
            ->getJson("/api/predictions/{$predB->id}")->assertStatus(403);
        $this->actingAs($this->teacherUserA, 'sanctum')
            ->getJson("/api/risk-assessments/{$riskB->id}")->assertStatus(403);
        $this->actingAs($this->teacherUserA, 'sanctum')
            ->getJson("/api/recommendations/{$recB->id}")->assertStatus(403);
        $this->actingAs($this->teacherUserA, 'sanctum')
            ->getJson("/api/reports/{$repB->id}")->assertStatus(403);
        $this->actingAs($this->teacherUserA, 'sanctum')
            ->patchJson("/api/teacher/recommendations/{$recB->id}", ['status' => 'viewed'])
            ->assertStatus(403);

        // Student B cannot view Student A's attendance detail or private record
        $this->actingAs($this->studentUserB, 'sanctum')
            ->getJson("/api/attendance-details/{$attDetailA->id}")->assertStatus(403);
        $this->actingAs($this->studentUserB, 'sanctum')
            ->getJson("/api/students/{$this->studentA->id}")->assertStatus(403);
    }

    public function test_teacher_module_fixes_and_admin_system_config_and_password_contract(): void
    {
        // Assessments overview returns 0.0 (not 85.0) when there are no grades
        $this->actingAs($this->teacherUserA, 'sanctum')
            ->getJson('/api/teacher/assessments-overview')
            ->assertOk()
            ->assertJsonPath('data.summary.average_class_score', 0);

        // Teacher A cannot post attendance/scores for Student B (not enrolled in offeringA)
        $this->actingAs($this->teacherUserA, 'sanctum')
            ->postJson("/api/teacher/course-offerings/{$this->offeringA->id}/attendance", [
                'attendance_date' => now()->toDateString(),
                'records' => [['student_id' => $this->studentB->id, 'status' => 'present']],
            ])
            ->assertStatus(403);

        $this->actingAs($this->teacherUserA, 'sanctum')
            ->postJson("/api/teacher/course-offerings/{$this->offeringA->id}/scores", [
                'student_id' => $this->studentB->id,
                'total_score' => 95,
            ])
            ->assertStatus(403);

        // Privilege escalation blocked for Teacher & Student on Admin catalog endpoints
        $this->actingAs($this->teacherUserA, 'sanctum')
            ->postJson('/api/departments', ['name' => 'Rogue', 'code' => 'RGE'])
            ->assertStatus(403);
        $this->actingAs($this->studentUserA, 'sanctum')
            ->putJson("/api/academic-years/{$this->ay->id}", [
                'name' => '2026-2027',
                'start_date' => '2026-10-01',
                'end_date' => '2027-07-31',
                'status' => 'inactive',
            ])
            ->assertStatus(403);

        // Admin can persist Academic Year & Semester active settings
        $this->actingAs($this->adminUser, 'sanctum')
            ->putJson("/api/academic-years/{$this->ay->id}", [
                'name' => $this->ay->name,
                'start_date' => '2026-10-01',
                'end_date' => '2027-07-31',
                'status' => 'active',
            ])
            ->assertOk();

        $this->actingAs($this->adminUser, 'sanctum')
            ->putJson("/api/semesters/{$this->semester->id}", [
                'academic_year_id' => $this->ay->id,
                'name' => $this->semester->name,
                'semester_number' => 1,
                'start_date' => '2026-10-01',
                'end_date' => '2027-02-28',
                'status' => 'active',
            ])
            ->assertOk();

        // Password change contract matches Android AccountSecurityActivity payload keys
        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/change-password', [
                'current_password' => 'Admin@123',
                'password' => 'NewAdmin@123',
                'password_confirmation' => 'NewAdmin@123',
            ])
            ->assertOk();

        // Read-only Web login page regression check
        $this->get('/login')->assertOk();
    }

    public function test_student_grades_overview_lists_pending_enrolled_courses_without_leaking_draft_scores(): void
    {
        // Draft grade for offeringA must NOT expose score/letter, yet enrolled offering still appears as pending
        \App\Models\Grade::create([
            'student_id' => $this->studentA->id,
            'course_id' => $this->courseA->id,
            'course_offering_id' => $this->offeringA->id,
            'semester_id' => $this->semester->id,
            'total_score' => 91,
            'letter_grade' => 'A',
            'status' => 'draft',
        ]);

        // Published grade for Student B in offeringB must never leak to Student A
        \App\Models\Grade::create([
            'student_id' => $this->studentB->id,
            'course_id' => $this->courseB->id,
            'course_offering_id' => $this->offeringB->id,
            'semester_id' => $this->semester->id,
            'total_score' => 95,
            'letter_grade' => 'A',
            'status' => 'official',
        ]);

        $res = $this->actingAs($this->studentUserA, 'sanctum')
            ->getJson('/api/student/module/grades')
            ->assertOk()
            ->assertJsonPath('data.summary.pending', 1)
            ->assertJsonPath('data.summary.passed', 0);

        $courses = collect($res->json('data.courses'));
        $this->assertCount(1, $courses);
        $entryA = $courses->firstWhere('course_offering_id', $this->offeringA->id);
        $this->assertNotNull($entryA);
        $this->assertSame('pending', $entryA['status']);
        $this->assertNull($entryA['total_score']);
        $this->assertNull($entryA['letter_grade']);
        $this->assertNull($courses->firstWhere('course_offering_id', $this->offeringB->id));
    }
}
