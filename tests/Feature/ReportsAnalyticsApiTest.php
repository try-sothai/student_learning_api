<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\AttendanceDetail;
use App\Models\ClassRoom;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Department;
use App\Models\FacultyReview;
use App\Models\GpaRecord;
use App\Models\Grade;
use App\Models\Program;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportsAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::create([
            'name' => 'Admin Reports',
            'email' => 'admin.reports@example.test',
            'password' => 'password',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    public function test_admin_reports_analytics_overview_teachers_courses_and_exports_use_real_db_data(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        $dept = Department::create(['name' => 'Computer Science', 'code' => 'CS']);
        $ay = AcademicYear::create(['name' => '2025-2026', 'start_date' => '2025-10-01', 'end_date' => '2026-07-31', 'status' => 'active']);
        $sem = Semester::create(['academic_year_id' => $ay->id, 'name' => 'Semester 1', 'semester_number' => 1, 'start_date' => '2025-10-01', 'end_date' => '2026-02-28', 'status' => 'active']);
        $program = Program::create(['department_id' => $dept->id, 'code' => 'BSCS', 'name' => 'B.Sc. Computer Science', 'duration_years' => 4, 'status' => 'active']);

        $teacherUser = User::create(['name' => 'Prof. Sokha', 'email' => 'sokha@example.test', 'password' => 'password', 'role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_code' => 'TCH101', 'first_name' => 'Sokha', 'last_name' => 'Chan', 'department_id' => $dept->id, 'position' => 'Senior Lecturer', 'status' => 'active']);

        $course = Course::create(['department_id' => $dept->id, 'teacher_id' => $teacher->id, 'code' => 'CS101', 'name' => 'Algorithms', 'credits' => 3, 'status' => 'active']);
        $classRoom = ClassRoom::create(['department_id' => $dept->id, 'academic_year_id' => $ay->id, 'course_id' => $course->id, 'teacher_id' => $teacher->id, 'name' => 'BSCS 3-A', 'code' => 'BSCS-3A', 'year_level' => 3, 'status' => 'active']);

        $stuUser = User::create(['name' => 'សុភា', 'email' => 'stu101@example.test', 'password' => 'password', 'role' => 'student', 'status' => 'active']);
        $student = Student::create(['user_id' => $stuUser->id, 'student_code' => 'STD101', 'first_name' => 'Sophea', 'last_name' => 'Keo', 'khmer_name' => 'សុភា', 'english_name' => 'Sophea Keo', 'date_of_birth' => '2005-01-01', 'gender' => 'Female', 'department_id' => $dept->id, 'program_id' => $program->id, 'class_id' => $classRoom->id, 'year_level' => 3, 'status' => 'enrolled']);

        CourseEnrollment::create(['student_id' => $student->id, 'course_id' => $course->id, 'semester_id' => $sem->id, 'enrollment_date' => '2025-10-05', 'status' => 'completed']);
        GpaRecord::create(['student_id' => $student->id, 'semester_id' => $sem->id, 'gpa' => 3.75, 'cumulative_gpa' => 3.75, 'total_credits' => 3]);
        Grade::create(['student_id' => $student->id, 'course_id' => $course->id, 'semester_id' => $sem->id, 'total_score' => 88.0, 'grade' => 'A']);
        FacultyReview::create(['teacher_id' => $teacher->id, 'semester_id' => $sem->id, 'reviewer_id' => $admin->id, 'rating' => 4.8, 'status' => 'completed', 'reviewed_at' => now()]);

        $att1 = Attendance::create(['course_id' => $course->id, 'teacher_id' => $teacher->id, 'semester_id' => $sem->id, 'attendance_date' => '2025-10-10', 'status' => 'completed']);
        $att2 = Attendance::create(['course_id' => $course->id, 'teacher_id' => $teacher->id, 'semester_id' => $sem->id, 'attendance_date' => '2025-10-11', 'status' => 'completed']);
        $att3 = Attendance::create(['course_id' => $course->id, 'teacher_id' => $teacher->id, 'semester_id' => $sem->id, 'attendance_date' => '2025-10-12', 'status' => 'completed']);
        AttendanceDetail::create(['attendance_id' => $att1->id, 'student_id' => $student->id, 'status' => 'present']);
        AttendanceDetail::create(['attendance_id' => $att2->id, 'student_id' => $student->id, 'status' => 'late']);
        AttendanceDetail::create(['attendance_id' => $att3->id, 'student_id' => $student->id, 'status' => 'absent']);

        $this->getJson('/api/admin/reports-analytics/lookups')
            ->assertOk()
            ->assertJsonPath('data.departments.0.code', 'CS');

        $this->getJson('/api/admin/reports-analytics/overview')
            ->assertOk()
            ->assertJsonPath('data.metrics.total_users', 3)
            ->assertJsonPath('data.metrics.orphan_student_users', 0)
            ->assertJsonPath('data.metrics.total_students', 1)
            ->assertJsonPath('data.metrics.total_teachers', 1)
            ->assertJsonPath('data.metrics.total_courses', 1)
            ->assertJsonPath('data.metrics.average_gpa', 3.75)
            ->assertJsonPath('data.student_enrollment_roster.0.student_code', 'STD101');

        $this->getJson('/api/admin/reports-analytics/attendance?semester_id=' . $sem->id)
            ->assertOk()
            ->assertJsonPath('data.summary.total_records', 3)
            ->assertJsonPath('data.summary.present_count', 1)
            ->assertJsonPath('data.summary.late_count', 1)
            ->assertJsonPath('data.summary.absent_count', 1)
            ->assertJsonPath('data.summary.overall_rate', 66.7)
            ->assertJsonPath('data.student_attendance_roster.0.student_code', 'STD101');

        $this->getJson('/api/admin/reports-analytics/attendance?semester_id=999999')
            ->assertOk()
            ->assertJsonPath('data.summary.total_records', 0)
            ->assertJsonPath('data.summary.overall_rate', null);

        $this->getJson('/api/admin/reports-analytics/teachers')
            ->assertOk()
            ->assertJsonPath('data.teachers.0.teacher_code', 'TCH101')
            ->assertJsonPath('data.teachers.0.rating', 4.8);

        $this->getJson('/api/admin/reports-analytics/courses')
            ->assertOk()
            ->assertJsonPath('data.enrollment_summary.total_courses', 1)
            ->assertJsonPath('data.enrollment_summary.completed_enrollments', 1)
            ->assertJsonPath('data.enrollment_summary.completion_rate', 100)
            ->assertJsonPath('data.enrollment_summary.total_enrollments', 1)
            ->assertJsonPath('data.pass_rate_summary.overall_pass_rate', 100);

        $this->postJson('/api/admin/reports-analytics/generate', ['category' => 'student', 'report_key' => 'information'])
            ->assertOk()
            ->assertJsonPath('data.total_records', 1);

        $csvRes = $this->post('/api/admin/reports-analytics/export', ['category' => 'student', 'format' => 'csv']);
        $csvRes->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csvRes->headers->get('Content-Type'));

        $xlsxRes = $this->post('/api/admin/reports-analytics/export', ['category' => 'student', 'format' => 'xlsx']);
        $xlsxRes->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $xlsxRes->headers->get('Content-Type'));

        $pdfRes = $this->post('/api/admin/reports-analytics/export', ['category' => 'student', 'format' => 'pdf']);
        $pdfRes->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdfRes->headers->get('Content-Type'));
    }

    public function test_non_admin_cannot_access_admin_reports_analytics(): void
    {
        $student = User::create([
            'name' => 'Student User',
            'email' => 'student.block@example.test',
            'password' => 'password',
            'role' => 'student',
            'status' => 'active',
        ]);
        Sanctum::actingAs($student);

        $this->getJson('/api/admin/reports-analytics/overview')->assertStatus(403);
    }
}
