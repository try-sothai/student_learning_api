<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\AssignmentSubmission;
use App\Models\AttendanceDetail;
use App\Models\ClassRoom;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Department;
use App\Models\ExamResult;
use App\Models\FacultyReview;
use App\Models\GpaRecord;
use App\Models\Grade;
use App\Models\Program;
use App\Models\QuizAttempt;
use App\Models\Report;
use App\Models\RiskAssessment;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;

/**
 * Real MySQL-backed Reports & Analytics Controller for SLAS.
 * Reuses existing academic, enrollment, attendance, assessment, and grading tables.
 */
class AdminReportsAnalyticsController extends Controller
{
    private const PASS_MARK = 50.0;
    private const DEFAULT_UNDERLOADED_MAX = 9.0;
    private const DEFAULT_OVERLOADED_MIN = 18.0;

    public function lookups(): JsonResponse
    {
        return response()->json([
            'message' => 'Reports and analytics lookups retrieved successfully.',
            'data' => [
                'academic_years' => AcademicYear::orderByDesc('id')->get(['id', 'name', 'status']),
                'semesters' => Semester::orderBy('academic_year_id')->orderBy('semester_number')
                    ->get(['id', 'academic_year_id', 'name', 'semester_number']),
                'departments' => Department::orderBy('name')->get(['id', 'code', 'name']),
                'programs' => Program::where('status', 'active')->orderBy('name')
                    ->get(['id', 'department_id', 'code', 'name']),
                'courses' => Course::orderBy('code')->get(['id', 'department_id', 'code', 'name', 'credits', 'status']),
                'classes' => ClassRoom::orderBy('code')->get(['id', 'department_id', 'academic_year_id', 'code', 'name', 'status']),
                'teachers' => Teacher::orderBy('first_name')->get(['id', 'teacher_code', 'first_name', 'last_name', 'department_id']),
                'workload_thresholds' => [
                    'underloaded_max' => (float) config('slas.workload_underloaded_max', self::DEFAULT_UNDERLOADED_MAX),
                    'overloaded_min' => (float) config('slas.workload_overloaded_min', self::DEFAULT_OVERLOADED_MIN),
                ],
            ],
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        $deptId = $request->filled('department_id') ? (int) $request->input('department_id') : null;
        $ayId = $request->filled('academic_year_id') ? (int) $request->input('academic_year_id') : null;
        $semId = $request->filled('semester_id') ? (int) $request->input('semester_id') : null;

        $studentQuery = Student::query();
        if ($deptId) {
            $studentQuery->where('department_id', $deptId);
        }
        $totalStudents = (clone $studentQuery)->count();
        $activeStudents = (clone $studentQuery)->whereIn('status', ['enrolled', 'active'])->count();

        $teacherQuery = Teacher::query();
        if ($deptId) {
            $teacherQuery->where('department_id', $deptId);
        }
        $totalTeachers = $teacherQuery->count();

        $courseQuery = Course::query();
        if ($deptId) {
            $courseQuery->where('department_id', $deptId);
        }
        $totalCourses = (clone $courseQuery)->count();
        $activeCourses = (clone $courseQuery)->where('status', 'active')->count();

        $classQuery = ClassRoom::query();
        if ($deptId) {
            $classQuery->where('department_id', $deptId);
        }
        if ($ayId) {
            $classQuery->where('academic_year_id', $ayId);
        }
        $totalClasses = $classQuery->count();

        $totalDepartments = Department::count();
        $totalPrograms = Program::count();
        $totalUsers = User::count();
        $orphanStudentUsers = User::where('role', 'student')
            ->whereNotIn('id', Student::whereNotNull('user_id')->pluck('user_id'))
            ->count();
        $orphanTeacherUsers = User::where('role', 'teacher')
            ->whereNotIn('id', Teacher::whereNotNull('user_id')->pluck('user_id'))
            ->count();

        // Average GPA
        $gpaQuery = GpaRecord::query();
        if ($semId) {
            $gpaQuery->where('semester_id', $semId);
        } elseif ($ayId) {
            $gpaQuery->whereHas('semester', fn ($q) => $q->where('academic_year_id', $ayId));
        }
        if ($deptId) {
            $gpaQuery->whereHas('student', fn ($q) => $q->where('department_id', $deptId));
        }
        $avgGpa = $gpaQuery->avg('gpa');

        // Attendance rate
        $attQuery = AttendanceDetail::query();
        if ($semId) {
            $attQuery->whereHas('attendance', fn ($q) => $q->where('semester_id', $semId));
        } elseif ($ayId) {
            $attQuery->whereHas('attendance.semester', fn ($q) => $q->where('academic_year_id', $ayId));
        }
        if ($deptId) {
            $attQuery->whereHas('student', fn ($q) => $q->where('department_id', $deptId));
        }
        $attTotal = (clone $attQuery)->count();
        $attPresent = (clone $attQuery)->whereIn('status', ['present', 'late'])->count();
        $attendanceRate = $attTotal > 0 ? round(($attPresent / $attTotal) * 100, 1) : null;

        // Assignment & Quiz averages
        $assignQuery = AssignmentSubmission::whereNotNull('score');
        if ($semId) {
            $assignQuery->whereHas('assignment', fn ($q) => $q->where('semester_id', $semId));
        }
        $avgAssignment = $assignQuery->avg('score');

        $quizQuery = QuizAttempt::whereNotNull('score');
        if ($semId) {
            $quizQuery->whereHas('quiz', fn ($q) => $q->where('semester_id', $semId));
        }
        $avgQuiz = $quizQuery->avg('score');

        // Enrollment Trend by Academic Year
        $enrollmentTrend = AcademicYear::orderBy('start_date')->get()->map(function (AcademicYear $ay) use ($deptId) {
            $q = CourseEnrollment::whereHas('semester', fn ($sq) => $sq->where('academic_year_id', $ay->id));
            if ($deptId) {
                $q->whereHas('student', fn ($stq) => $stq->where('department_id', $deptId));
            }
            return [
                'label' => $ay->name,
                'count' => $q->distinct('student_id')->count('student_id'),
            ];
        })->values();

        // Department distribution
        $deptDistribution = Department::withCount('students')->orderByDesc('students_count')->get()
            ->map(fn (Department $d) => ['label' => $d->name, 'code' => $d->code, 'count' => $d->students_count])
            ->values();

        // GPA distribution buckets
        $gpaRecords = (clone $gpaQuery)->pluck('gpa');
        $gpaBuckets = [
            ['label' => '3.75 - 4.0', 'count' => $gpaRecords->filter(fn ($v) => $v >= 3.75)->count()],
            ['label' => '3.25 - 3.74', 'count' => $gpaRecords->filter(fn ($v) => $v >= 3.25 && $v < 3.75)->count()],
            ['label' => '2.75 - 3.24', 'count' => $gpaRecords->filter(fn ($v) => $v >= 2.75 && $v < 3.25)->count()],
            ['label' => '2.25 - 2.74', 'count' => $gpaRecords->filter(fn ($v) => $v >= 2.25 && $v < 2.75)->count()],
            ['label' => '< 2.25', 'count' => $gpaRecords->filter(fn ($v) => $v < 2.25)->count()],
        ];

        // Top students by GPA
        $topStudents = (clone $gpaQuery)->with(['student.department', 'student.classRoom'])
            ->orderByDesc('gpa')
            ->limit(10)
            ->get()
            ->map(function (GpaRecord $g) {
                $s = $g->student;
                return [
                    'student_code' => $s?->student_code ?? '',
                    'student_name' => $this->studentName($s),
                    'department' => $s?->department?->name ?? '',
                    'class_code' => $s?->classRoom?->code ?? '',
                    'gpa' => round((float) $g->gpa, 2),
                ];
            })->values();

        // Real student enrollment roster (filtered by department / semester / academic year)
        $rosterQuery = Student::with(['department', 'classRoom']);
        if ($deptId) {
            $rosterQuery->where('department_id', $deptId);
        }
        $rosterStudents = $rosterQuery->orderBy('student_code')->limit(50)->get();
        $rosterIds = $rosterStudents->pluck('id')->all();
        $enrollCountsQuery = CourseEnrollment::whereIn('student_id', $rosterIds);
        if ($semId) {
            $enrollCountsQuery->where('semester_id', $semId);
        } elseif ($ayId) {
            $enrollCountsQuery->whereHas('semester', fn ($sq) => $sq->where('academic_year_id', $ayId));
        }
        $enrollCountsByStudent = $enrollCountsQuery
            ->selectRaw('student_id, COUNT(*) as cnt')
            ->groupBy('student_id')
            ->pluck('cnt', 'student_id');

        $studentEnrollmentRoster = $rosterStudents->map(function (Student $s) use ($enrollCountsByStudent) {
            return [
                'student_code' => $s->student_code ?? '',
                'student_name' => $this->studentName($s),
                'department' => $s->department?->name ?? '',
                'class_code' => $s->classRoom?->code ?? '',
                'status' => ucfirst((string) ($s->status ?? 'active')),
                'enrollment_count' => (int) ($enrollCountsByStudent->get($s->id) ?? 0),
            ];
        })->values();

        return response()->json([
            'message' => 'Overview analytics retrieved successfully.',
            'data' => [
                'metrics' => [
                    'total_users' => $totalUsers,
                    'orphan_student_users' => $orphanStudentUsers,
                    'orphan_teacher_users' => $orphanTeacherUsers,
                    'total_students' => $totalStudents,
                    'active_students' => $activeStudents,
                    'total_teachers' => $totalTeachers,
                    'total_courses' => $totalCourses,
                    'active_courses' => $activeCourses,
                    'total_classes' => $totalClasses,
                    'total_departments' => $totalDepartments,
                    'total_programs' => $totalPrograms,
                    'average_gpa' => $avgGpa !== null ? round((float) $avgGpa, 2) : null,
                    'attendance_rate' => $attendanceRate,
                    'assignment_performance' => $avgAssignment !== null ? round((float) $avgAssignment, 1) : null,
                    'quiz_performance' => $avgQuiz !== null ? round((float) $avgQuiz, 1) : null,
                ],
                'enrollment_trend' => $enrollmentTrend,
                'department_distribution' => $deptDistribution,
                'gpa_distribution' => $gpaBuckets,
                'top_students' => $topStudents,
                'student_enrollment_roster' => $studentEnrollmentRoster,
            ],
        ]);
    }

    public function attendance(Request $request): JsonResponse
    {
        $deptId = $request->filled('department_id') ? (int) $request->input('department_id') : null;
        $ayId = $request->filled('academic_year_id') ? (int) $request->input('academic_year_id') : null;
        $semId = $request->filled('semester_id') ? (int) $request->input('semester_id') : null;
        $courseId = $request->filled('course_id') ? (int) $request->input('course_id') : null;
        $classId = $request->filled('class_id') ? (int) $request->input('class_id') : null;

        $baseQuery = AttendanceDetail::query();
        if ($semId) {
            $baseQuery->whereHas('attendance', fn ($q) => $q->where('semester_id', $semId));
        } elseif ($ayId) {
            $baseQuery->whereHas('attendance.semester', fn ($q) => $q->where('academic_year_id', $ayId));
        }
        if ($courseId) {
            $baseQuery->whereHas('attendance', fn ($q) => $q->where('course_id', $courseId));
        }
        if ($deptId) {
            $baseQuery->whereHas('student', fn ($q) => $q->where('department_id', $deptId));
        }
        if ($classId) {
            $baseQuery->whereHas('student', fn ($q) => $q->where('class_id', $classId));
        }

        $counts = (clone $baseQuery)->selectRaw(
            "COUNT(*) as total_records,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count,
            SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused_count"
        )->first();

        $totalRecords = (int) ($counts->total_records ?? 0);
        $presentCount = (int) ($counts->present_count ?? 0);
        $absentCount = (int) ($counts->absent_count ?? 0);
        $lateCount = (int) ($counts->late_count ?? 0);
        $excusedCount = (int) ($counts->excused_count ?? 0);
        $overallRate = $totalRecords > 0
            ? round((($presentCount + $lateCount) / $totalRecords) * 100, 1)
            : null;

        // Monthly attendance trend (joins 1:N attendance -> attendance_details, no duplication)
        $trendRows = (clone $baseQuery)
            ->join('attendance', 'attendance.id', '=', 'attendance_details.attendance_id')
            ->select('attendance.attendance_date', 'attendance_details.status')
            ->whereNotNull('attendance.attendance_date')
            ->get();

        $monthlyTrend = $trendRows
            ->groupBy(fn ($r) => substr((string) $r->attendance_date, 0, 7))
            ->sortKeys()
            ->take(-12)
            ->map(function ($rows, $ym) {
                $total = $rows->count();
                $attended = $rows->filter(fn ($r) => in_array($r->status, ['present', 'late'], true))->count();
                try {
                    $label = Carbon::createFromFormat('Y-m', $ym)->format('M y');
                } catch (\Throwable) {
                    $label = $ym;
                }
                return [
                    'month' => $ym,
                    'label' => $label,
                    'total' => $total,
                    'rate' => $total > 0 ? round(($attended / $total) * 100, 1) : 0.0,
                ];
            })->values();

        // Student attendance roster
        $studentAggs = (clone $baseQuery)
            ->selectRaw(
                "student_id,
                COUNT(*) as total_days,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days"
            )
            ->groupBy('student_id')
            ->get()
            ->keyBy('student_id');

        $students = Student::with(['department', 'classRoom'])
            ->whereIn('id', $studentAggs->keys())
            ->orderBy('student_code')
            ->limit(50)
            ->get();

        $studentAttendanceRoster = $students->map(function (Student $s) use ($studentAggs) {
            $agg = $studentAggs->get($s->id);
            $total = (int) ($agg->total_days ?? 0);
            $present = (int) ($agg->present_days ?? 0);
            $late = (int) ($agg->late_days ?? 0);
            $absent = (int) ($agg->absent_days ?? 0);
            $rate = $total > 0 ? round((($present + $late) / $total) * 100, 1) : 0.0;

            return [
                'student_code' => $s->student_code ?? '',
                'student_name' => $this->studentName($s),
                'department' => $s->department?->name ?? '',
                'class_code' => $s->classRoom?->code ?? '',
                'present_days' => $present,
                'late_days' => $late,
                'absent_days' => $absent,
                'total_days' => $total,
                'attendance_rate' => $rate,
            ];
        })->values();

        return response()->json([
            'message' => 'Attendance analytics retrieved successfully.',
            'data' => [
                'summary' => [
                    'overall_rate' => $overallRate,
                    'total_records' => $totalRecords,
                    'present_count' => $presentCount,
                    'absent_count' => $absentCount,
                    'late_count' => $lateCount,
                    'excused_count' => $excusedCount,
                ],
                'monthly_trend' => $monthlyTrend,
                'student_attendance_roster' => $studentAttendanceRoster,
            ],
        ]);
    }

    public function teachers(Request $request): JsonResponse
    {
        $underloadedMax = (float) ($request->input('underloaded_max') ?: config('slas.workload_underloaded_max', self::DEFAULT_UNDERLOADED_MAX));
        $overloadedMin = (float) ($request->input('overloaded_min') ?: config('slas.workload_overloaded_min', self::DEFAULT_OVERLOADED_MIN));

        $query = Teacher::with(['department', 'courses', 'classes']);
        if ($request->filled('department_id')) {
            $query->where('department_id', (int) $request->input('department_id'));
        }
        if ($request->filled('search')) {
            $q = trim((string) $request->input('search'));
            $query->where(function ($sub) use ($q) {
                $sub->where('teacher_code', 'like', "%{$q}%")
                    ->orWhere('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%");
            });
        }

        $teachers = $query->get();
        $teacherIds = $teachers->pluck('id')->all();

        $ratings = FacultyReview::where('status', 'completed')
            ->whereNotNull('rating')
            ->whereIn('teacher_id', $teacherIds)
            ->groupBy('teacher_id')
            ->selectRaw('teacher_id, AVG(rating) as avg_rating')
            ->pluck('avg_rating', 'teacher_id');

        $gradeStats = Grade::query()
            ->join('courses', 'courses.id', '=', 'grades.course_id')
            ->whereIn('courses.teacher_id', $teacherIds)
            ->whereNotNull('grades.total_score')
            ->groupBy('courses.teacher_id')
            ->selectRaw('courses.teacher_id as tid, COUNT(*) as total, SUM(CASE WHEN grades.total_score >= ? THEN 1 ELSE 0 END) as passed', [self::PASS_MARK])
            ->get()->keyBy('tid');

        $rows = $teachers->map(function (Teacher $t) use ($underloadedMax, $overloadedMin, $ratings, $gradeStats) {
            $coursesCount = $t->courses->count();
            $classesCount = $t->classes->count();

            $weeklyHours = 0.0;
            foreach ($t->classes as $cls) {
                if ($cls->start_time && $cls->end_time) {
                    try {
                        $diff = Carbon::parse($cls->start_time)->diffInMinutes(Carbon::parse($cls->end_time), false);
                        if ($diff > 0) {
                            $weeklyHours += round($diff / 60.0, 1);
                        }
                    } catch (\Throwable) {
                    }
                }
            }
            if ($weeklyHours <= 0 && $coursesCount > 0) {
                $weeklyHours = (float) $t->courses->sum(fn ($c) => max(1, (int) ($c->credits ?? 3)));
            }

            $status = 'Normal Workload';
            if ($weeklyHours < $underloadedMax) {
                $status = 'Underloaded';
            } elseif ($weeklyHours > $overloadedMin) {
                $status = 'Overloaded';
            }

            $gs = $gradeStats->get($t->id);
            $passRate = $gs && $gs->total > 0 ? round(($gs->passed / $gs->total) * 100, 1) : null;
            $rating = $ratings->has($t->id) ? round((float) $ratings->get($t->id), 2) : null;

            return [
                'id' => $t->id,
                'teacher_code' => $t->teacher_code,
                'name' => trim(($t->first_name ?? '') . ' ' . ($t->last_name ?? '')),
                'department_id' => $t->department_id,
                'department' => $t->department?->name ?? '',
                'position' => $t->position ?? 'Lecturer',
                'assigned_courses' => $coursesCount,
                'assigned_classes' => $classesCount,
                'weekly_hours' => round($weeklyHours, 1),
                'workload_status' => $status,
                'rating' => $rating,
                'pass_rate' => $passRate,
            ];
        });

        if ($request->filled('workload_status')) {
            $ws = strtolower((string) $request->input('workload_status'));
            if ($ws !== 'all') {
                $rows = $rows->filter(fn ($r) => strtolower($r['workload_status']) === $ws);
            }
        }

        if ($request->input('sort_by') === 'hours') {
            $rows = $rows->sortByDesc('weekly_hours');
        } else {
            $rows = $rows->sortBy('name');
        }
        $rows = $rows->values();

        $total = $rows->count();
        $avgHours = $total > 0 ? round($rows->avg('weekly_hours'), 1) : 0.0;

        return response()->json([
            'message' => 'Teacher analytics retrieved successfully.',
            'data' => [
                'summary' => [
                    'total_teachers' => $total,
                    'normal_count' => $rows->where('workload_status', 'Normal Workload')->count(),
                    'underloaded_count' => $rows->where('workload_status', 'Underloaded')->count(),
                    'overloaded_count' => $rows->where('workload_status', 'Overloaded')->count(),
                    'average_weekly_hours' => $avgHours,
                ],
                'teachers' => $rows,
            ],
        ]);
    }

    public function courses(Request $request): JsonResponse
    {
        $deptId = $request->filled('department_id') ? (int) $request->input('department_id') : null;
        $semId = $request->filled('semester_id') ? (int) $request->input('semester_id') : null;
        $ayId = $request->filled('academic_year_id') ? (int) $request->input('academic_year_id') : null;

        $courseQuery = Course::with(['department']);
        if ($deptId) {
            $courseQuery->where('department_id', $deptId);
        }
        if ($request->filled('course_id')) {
            $courseQuery->where('id', (int) $request->input('course_id'));
        }
        $courses = $courseQuery->get();

        $enrollQuery = CourseEnrollment::query();
        if ($semId) {
            $enrollQuery->where('semester_id', $semId);
        } elseif ($ayId) {
            $enrollQuery->whereHas('semester', fn ($q) => $q->where('academic_year_id', $ayId));
        }
        if ($deptId) {
            $enrollQuery->whereHas('course', fn ($q) => $q->where('department_id', $deptId));
        }

        $totalEnrollments = (clone $enrollQuery)->count();
        $enrolledStudents = (clone $enrollQuery)->distinct('student_id')->count('student_id');
        $completedCount = (clone $enrollQuery)->where('status', 'completed')->count();
        $droppedCount = (clone $enrollQuery)->where('status', 'dropped')->count();

        $courseItems = $courses->map(function (Course $c) use ($semId, $ayId) {
            $eq = CourseEnrollment::where('course_id', $c->id);
            if ($semId) {
                $eq->where('semester_id', $semId);
            } elseif ($ayId) {
                $eq->whereHas('semester', fn ($q) => $q->where('academic_year_id', $ayId));
            }
            $enrolled = (clone $eq)->count();
            $completed = (clone $eq)->where('status', 'completed')->count();
            $completionPct = $enrolled > 0 ? round(($completed / $enrolled) * 100, 1) : 0.0;

            $grades = Grade::where('course_id', $c->id)->whereNotNull('total_score');
            if ($semId) {
                $grades->where('semester_id', $semId);
            }
            $gradeRows = $grades->get(['total_score']);
            $gradeTotal = $gradeRows->count();
            $passed = $gradeRows->filter(fn ($g) => (float) $g->total_score >= self::PASS_MARK)->count();
            $passRate = $gradeTotal > 0 ? round(($passed / $gradeTotal) * 100, 1) : null;
            $avgScore = $gradeTotal > 0 ? round((float) $gradeRows->avg('total_score'), 1) : null;

            return [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'department' => $c->department?->name ?? '',
                'status' => ucfirst((string) ($c->status ?? 'active')),
                'enrollment_count' => $enrolled,
                'completed_count' => $completed,
                'completion_percentage' => $completionPct,
                'pass_rate' => $passRate,
                'average_score' => $avgScore,
            ];
        })->values();

        $gradedCourses = $courseItems->filter(fn ($c) => $c['pass_rate'] !== null);
        $overallPassRate = $gradedCourses->isNotEmpty() ? round($gradedCourses->avg('pass_rate'), 1) : null;
        $overallAvgScore = $gradedCourses->isNotEmpty() ? round($gradedCourses->avg('average_score'), 1) : null;

        $semestersQuery = Semester::with('academicYear')->orderBy('id');
        if ($ayId) {
            $semestersQuery->where('academic_year_id', $ayId);
        }
        $courseIdFilter = $request->filled('course_id') ? (int) $request->input('course_id') : null;
        $enrollmentBySemester = $semestersQuery->get()->map(function (Semester $sem) use ($deptId, $courseIdFilter) {
            $eq = CourseEnrollment::where('semester_id', $sem->id);
            if ($deptId) {
                $eq->whereHas('course', fn ($q) => $q->where('department_id', $deptId));
            }
            if ($courseIdFilter) {
                $eq->where('course_id', $courseIdFilter);
            }
            $ayName = $sem->academicYear?->name ?? '';
            $shortAy = strlen($ayName) >= 4 ? substr($ayName, 2, 2) . '-' . substr($ayName, -2) : $ayName;
            return [
                'semester_id' => $sem->id,
                'label' => 'S' . ($sem->semester_number ?? $sem->id) . ($shortAy !== '' ? ' ' . $shortAy : ''),
                'count' => $eq->count(),
            ];
        })->values();

        return response()->json([
            'message' => 'Course analytics retrieved successfully.',
            'data' => [
                'enrollment_summary' => [
                    'total_courses' => $courses->count(),
                    'active_courses' => $courses->where('status', 'active')->count(),
                    'total_enrollments' => $totalEnrollments,
                    'enrolled_students' => $enrolledStudents,
                    'completed_enrollments' => $completedCount,
                    'incomplete_enrollments' => max(0, $totalEnrollments - $completedCount),
                    'active_cohorts' => ClassRoom::where('status', 'active')->count(),
                    'average_class_size' => $courses->count() > 0 ? round($totalEnrollments / $courses->count(), 1) : 0.0,
                    'completion_rate' => $totalEnrollments > 0 ? round(($completedCount / $totalEnrollments) * 100, 1) : 0.0,
                    'dropout_rate' => $totalEnrollments > 0 ? round(($droppedCount / $totalEnrollments) * 100, 1) : 0.0,
                ],
                'pass_rate_summary' => [
                    'overall_pass_rate' => $overallPassRate,
                    'overall_failure_rate' => $overallPassRate !== null ? round(100 - $overallPassRate, 1) : null,
                    'average_course_score' => $overallAvgScore,
                ],
                'enrollment_by_semester' => $enrollmentBySemester,
                'courses' => $courseItems,
            ],
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $payload = $this->buildReportPayload($request);

        return response()->json([
            'message' => 'Report generated successfully.',
            'data' => $payload,
        ]);
    }

    public function export(Request $request): Response
    {
        $format = strtolower((string) $request->input('format', 'csv'));
        $payload = $this->buildReportPayload($request);

        if ($request->user()) {
            Report::create([
                'report_type' => substr((string) $payload['category'], 0, 30),
                'title' => substr((string) $payload['title'], 0, 200),
                'description' => 'Format: ' . strtoupper($format) . ' | Records: ' . $payload['total_records'],
                'generated_by' => $request->user()->id,
                'generated_at' => now(),
                'status' => 'generated',
            ]);
        }

        $safeSlug = preg_replace('/[^a-zA-Z0-9_-]+/', '_', strtolower($payload['title']));
        $filename = $safeSlug . '_' . now()->format('Ymd_His');

        if ($format === 'xlsx' || $format === 'excel') {
            return $this->exportXlsx($payload, $filename . '.xlsx');
        }
        if ($format === 'pdf') {
            return $this->exportPdf($payload, $filename . '.pdf');
        }

        return $this->exportCsv($payload, $filename . '.csv');
    }

    private function buildReportPayload(Request $request): array
    {
        $category = strtolower((string) $request->input('category', 'student'));
        $reportKey = strtolower((string) $request->input('report_key', 'information'));
        $deptId = $request->filled('department_id') ? (int) $request->input('department_id') : null;
        $programId = $request->filled('program_id') ? (int) $request->input('program_id') : null;
        $courseId = $request->filled('course_id') ? (int) $request->input('course_id') : null;
        $classId = $request->filled('class_id') ? (int) $request->input('class_id') : null;
        $ayId = $request->filled('academic_year_id') ? (int) $request->input('academic_year_id') : null;
        $semId = $request->filled('semester_id') ? (int) $request->input('semester_id') : null;
        $status = $request->filled('status') ? trim((string) $request->input('status')) : null;
        $search = $request->filled('search') ? trim((string) $request->input('search')) : null;

        $columns = [];
        $rows = [];
        $summary = [];
        $title = ucfirst($category) . ' - ' . ucwords(str_replace('_', ' ', $reportKey)) . ' Report';

        if ($category === 'teacher') {
            $columns = ['Teacher ID', 'Name', 'Department', 'Position', 'Courses', 'Classes', 'Status'];
            $q = Teacher::with(['department', 'courses', 'classes']);
            if ($deptId) $q->where('department_id', $deptId);
            if ($search) {
                $q->where(fn ($s) => $s->where('teacher_code', 'like', "%{$search}%")->orWhere('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"));
            }
            $teachers = $q->orderBy('teacher_code')->get();
            foreach ($teachers as $t) {
                $rows[] = [
                    $t->teacher_code,
                    trim(($t->first_name ?? '') . ' ' . ($t->last_name ?? '')),
                    $t->department?->name ?? 'N/A',
                    $t->position ?? 'Lecturer',
                    (string) $t->courses->count(),
                    (string) $t->classes->count(),
                    ucfirst((string) ($t->status ?? 'active')),
                ];
            }
            $summary = ['Total Teachers' => count($rows)];
        } elseif ($category === 'course' || $category === 'academic') {
            $columns = ['Course Code', 'Course Name', 'Department', 'Credits', 'Enrollments', 'Status'];
            $q = Course::with(['department'])->withCount('enrollments');
            if ($deptId) $q->where('department_id', $deptId);
            if ($courseId) $q->where('id', $courseId);
            if ($status && strtolower($status) !== 'all') $q->where('status', strtolower($status));
            foreach ($q->orderBy('code')->get() as $c) {
                $rows[] = [
                    $c->code,
                    $c->name,
                    $c->department?->name ?? 'N/A',
                    (string) ($c->credits ?? 3),
                    (string) $c->enrollments_count,
                    ucfirst((string) ($c->status ?? 'active')),
                ];
            }
            $summary = ['Total Courses' => count($rows)];
        } elseif ($category === 'attendance') {
            $columns = ['Student ID', 'Student Name', 'Course', 'Date', 'Status'];
            $q = AttendanceDetail::with(['student', 'attendance.course']);
            if ($semId) $q->whereHas('attendance', fn ($aq) => $aq->where('semester_id', $semId));
            if ($courseId) $q->whereHas('attendance', fn ($aq) => $aq->where('course_id', $courseId));
            if ($deptId) $q->whereHas('student', fn ($sq) => $sq->where('department_id', $deptId));
            $details = $q->latest()->limit(500)->get();
            foreach ($details as $d) {
                $rows[] = [
                    $d->student?->student_code ?? '',
                    $this->studentName($d->student),
                    $d->attendance?->course?->code ?? '',
                    (string) ($d->attendance?->attendance_date ?? ''),
                    ucfirst((string) ($d->status ?? '')),
                ];
            }
            $present = $details->whereIn('status', ['present', 'late'])->count();
            $summary = [
                'Total Records' => count($rows),
                'Attendance Rate' => count($rows) > 0 ? round(($present / count($rows)) * 100, 1) . '%' : '0%',
            ];
        } elseif ($category === 'assessment') {
            $columns = ['Student ID', 'Student Name', 'Course', 'Score', 'Grade'];
            $q = Grade::with(['student', 'course']);
            if ($semId) $q->where('semester_id', $semId);
            if ($courseId) $q->where('course_id', $courseId);
            if ($deptId) $q->whereHas('student', fn ($sq) => $sq->where('department_id', $deptId));
            $grades = $q->latest()->limit(500)->get();
            foreach ($grades as $g) {
                $rows[] = [
                    $g->student?->student_code ?? '',
                    $this->studentName($g->student),
                    $g->course?->code ?? '',
                    $g->total_score !== null ? number_format((float) $g->total_score, 1) : 'N/A',
                    $g->grade ?? 'N/A',
                ];
            }
            $summary = ['Total Graded Records' => count($rows)];
        } else {
            // student or analytics
            $columns = ['Student ID', 'Full Name', 'Department', 'Program', 'Class', 'Status'];
            $q = Student::with(['department', 'program', 'classRoom']);
            if ($deptId) $q->where('department_id', $deptId);
            if ($programId) $q->where('program_id', $programId);
            if ($classId) $q->where('class_id', $classId);
            if ($status && strtolower($status) !== 'all') $q->where('status', strtolower($status));
            if ($search) {
                $q->where(fn ($s) => $s->where('student_code', 'like', "%{$search}%")->orWhere('english_name', 'like', "%{$search}%")->orWhere('khmer_name', 'like', "%{$search}%"));
            }
            if ($reportKey === 'at_risk' || $reportKey === 'risk_analysis') {
                $atRiskIds = RiskAssessment::whereIn('risk_level', ['high', 'medium'])->pluck('student_id')->all();
                $q->whereIn('id', $atRiskIds);
            }
            foreach ($q->orderBy('student_code')->limit(500)->get() as $st) {
                $rows[] = [
                    $st->student_code,
                    $this->studentName($st),
                    $st->department?->name ?? 'N/A',
                    $st->program?->name ?? ($st->major ?? 'N/A'),
                    $st->classRoom?->code ?? 'Unassigned',
                    ucfirst((string) ($st->status ?? 'enrolled')),
                ];
            }
            $summary = ['Total Students' => count($rows)];
        }

        return [
            'title' => $title,
            'category' => $category,
            'report_key' => $reportKey,
            'generated_at' => now()->toDateTimeString(),
            'total_records' => count($rows),
            'summary_metrics' => $summary,
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    private function exportCsv(array $payload, string $filename): Response
    {
        return response()->streamDownload(function () use ($payload) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [$payload['title']]);
            fputcsv($out, ['Generated At', $payload['generated_at'], 'Total Records', $payload['total_records']]);
            fputcsv($out, []);
            fputcsv($out, $payload['columns']);
            foreach ($payload['rows'] as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function exportXlsx(array $payload, string $filename): Response
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('SLAS Report');

        $sheet->setCellValue('A1', $payload['title']);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', 'Generated At: ' . $payload['generated_at'] . ' | Total Records: ' . $payload['total_records']);

        $headerRow = 4;
        foreach ($payload['columns'] as $colIdx => $colName) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1);
            $sheet->setCellValue($colLetter . $headerRow, $colName);
            $sheet->getStyle($colLetter . $headerRow)->getFont()->setBold(true);
            $sheet->getStyle($colLetter . $headerRow)->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFEDE9FE');
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        foreach ($payload['rows'] as $rowIdx => $row) {
            $excelRow = $headerRow + 1 + $rowIdx;
            foreach ($row as $colIdx => $val) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1);
                $sheet->setCellValue($colLetter . $excelRow, $val);
            }
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function exportPdf(array $payload, string $filename): Response
    {
        $ths = '';
        foreach ($payload['columns'] as $col) {
            $ths .= '<th>' . htmlspecialchars((string) $col, ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $trs = '';
        foreach ($payload['rows'] as $row) {
            $trs .= '<tr>';
            foreach ($row as $cell) {
                $trs .= '<td>' . htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $trs .= '</tr>';
        }
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0f172a; }
            h1 { color: #3155f5; font-size: 16px; margin-bottom: 4px; }
            .meta { color: #64748b; font-size: 10px; margin-bottom: 12px; }
            table { width: 100%; border-collapse: collapse; }
            th { background: #eef2ff; color: #1e293b; text-align: left; padding: 6px; border: 1px solid #cbd5e1; }
            td { padding: 5px 6px; border: 1px solid #e2e8f0; }
        </style></head><body>
            <h1>' . htmlspecialchars($payload['title'], ENT_QUOTES, 'UTF-8') . '</h1>
            <div class="meta">Student Learning Analytics System (SLAS) | Generated: ' . htmlspecialchars($payload['generated_at'], ENT_QUOTES, 'UTF-8') . ' | Total Records: ' . (int) $payload['total_records'] . '</div>
            <table><thead><tr>' . $ths . '</tr></thead><tbody>' . $trs . '</tbody></table>
        </body></html>';

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'landscape');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function studentName(?Student $s): string
    {
        if (! $s) {
            return '';
        }
        $eng = trim((string) ($s->english_name ?? ''));
        if ($eng !== '') {
            return $eng;
        }
        $fl = trim(($s->first_name ?? '') . ' ' . ($s->last_name ?? ''));

        return $fl !== '' ? $fl : (string) ($s->khmer_name ?: $s->student_code);
    }
}
