<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AcademicYear;
use App\Models\Semester;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\GpaRecord;
use App\Models\Attendance;
use App\Models\AttendanceDetail;
use App\Models\RiskAssessment;
use App\Models\User;
use Carbon\Carbon;

class AnalyticsDemoSeeder extends Seeder
{
    public function run()
    {
        $years = AcademicYear::count();
        if ($years < 3) {
            $this->createDemoStructure();
        }

        $this->seedDemoAnalytics();
    }

    private function createDemoStructure()
    {
        $startYear = date('Y') - 2;
        for ($i = 0; $i < 3; $i++) {
            $ay = AcademicYear::firstOrCreate([
                'name' => $startYear . '-' . ($startYear + 1)
            ], [
                'start_date' => $startYear . '-09-01',
                'end_date' => ($startYear + 1) . '-06-30',
                'status' => $i == 2 ? 'active' : 'completed'
            ]);

            Semester::firstOrCreate([
                'academic_year_id' => $ay->id,
                'name' => 'Fall ' . $startYear
            ], [
                'start_date' => $startYear . '-09-01',
                'end_date' => $startYear . '-12-20',
                'status' => $i == 2 ? 'active' : 'completed'
            ]);

            Semester::firstOrCreate([
                'academic_year_id' => $ay->id,
                'name' => 'Spring ' . ($startYear + 1)
            ], [
                'start_date' => ($startYear + 1) . '-01-10',
                'end_date' => ($startYear + 1) . '-05-30',
                'status' => 'upcoming'
            ]);
            $startYear++;
        }
    }

    private function seedDemoAnalytics()
    {
        $students = Student::inRandomOrder()->limit(50)->get();
        if ($students->count() < 10) return;

        $teacher = Teacher::first();
        if (!$teacher) return;

        $semesters = Semester::all();
        $courses = Course::inRandomOrder()->limit(10)->get();

        foreach ($semesters as $sem) {
            foreach ($courses as $course) {
                foreach ($students as $student) {
                    CourseEnrollment::firstOrCreate([
                        'student_id' => $student->id,
                        'course_id' => $course->id,
                        'semester_id' => $sem->id
                    ], ['enrollment_date' => $sem->start_date]);

                    $riskLevels = ['low', 'low', 'low', 'medium', 'medium', 'high'];
                    RiskAssessment::firstOrCreate([
                        'student_id' => $student->id,
                        'course_id' => $course->id,
                        'semester_id' => $sem->id
                    ], [
                        'risk_level' => $riskLevels[array_rand($riskLevels)],
                        'risk_score' => rand(10, 90),
                        'assessment_date' => $sem->start_date
                    ]);

                    GpaRecord::firstOrCreate([
                        'student_id' => $student->id,
                        'semester_id' => $sem->id
                    ], [
                        'gpa' => rand(20, 40) / 10,
                        'total_credits' => 15,
                        'earned_credits' => 15
                    ]);
                }

                $assignment = Assignment::firstOrCreate([
                    'course_id' => $course->id,
                    'title' => 'Demo Assignment ' . $sem->name
                ], [
                    'description' => 'Demo',
                    'due_date' => Carbon::parse($sem->start_date)->addDays(30),
                    'total_score' => 100,
                    'teacher_id' => $teacher->id,
                    'semester_id' => $sem->id,
                    'created_by' => User::first()->id
                ]);

                $quiz = Quiz::firstOrCreate([
                    'course_id' => $course->id,
                    'title' => 'Demo Quiz ' . $sem->name
                ], [
                    'description' => 'Demo',
                    'start_at' => Carbon::parse($sem->start_date)->addDays(40),
                    'end_at' => Carbon::parse($sem->start_date)->addDays(42),
                    'duration_minutes' => 60,
                    'total_score' => 100,
                    'semester_id' => $sem->id,
                    'teacher_id' => $teacher->id
                ]);

                $exam = Exam::firstOrCreate([
                    'course_id' => $course->id,
                    'title' => 'Demo Exam ' . $sem->name
                ], [
                    'description' => 'Demo',
                    'exam_date' => Carbon::parse($sem->start_date)->addDays(80),
                    'total_score' => 100,
                    'semester_id' => $sem->id,
                    'teacher_id' => $teacher->id
                ]);

                $attendance = Attendance::firstOrCreate([
                    'course_id' => $course->id,
                    'attendance_date' => Carbon::parse($sem->start_date)->addDays(15)
                ], [
                    'topic' => 'Lecture 1',
                    'teacher_id' => $teacher->id,
                    'semester_id' => $sem->id,
                    'marked_by' => User::first()->id
                ]);

                foreach ($students as $student) {
                    AssignmentSubmission::firstOrCreate([
                        'assignment_id' => $assignment->id,
                        'student_id' => $student->id
                    ], ['score' => rand(60, 100), 'status' => 'graded']);

                    QuizAttempt::firstOrCreate([
                        'quiz_id' => $quiz->id,
                        'student_id' => $student->id
                    ], [
                        'score' => rand(60, 100),
                        'status' => 'completed',
                        'started_at' => Carbon::now()
                    ]);

                    ExamResult::firstOrCreate([
                        'exam_id' => $exam->id,
                        'student_id' => $student->id
                    ], ['score' => rand(60, 100)]);

                    $statuses = ['present', 'present', 'present', 'late', 'absent'];
                    AttendanceDetail::firstOrCreate([
                        'attendance_id' => $attendance->id,
                        'student_id' => $student->id
                    ], ['status' => $statuses[array_rand($statuses)]]);
                }
            }
        }
    }
}
