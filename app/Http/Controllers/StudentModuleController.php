<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\AttendanceDetail;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\GpaRecord;
use App\Models\Grade;
use App\Models\LearningAnalytic;
use App\Models\Prediction;
use App\Models\Quiz;
use App\Models\Recommendation;
use App\Models\RiskAssessment;
use App\Models\Semester;
use App\Models\Exam;
use App\Models\Attendance;
use App\Services\StudentAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentModuleController extends Controller
{
    protected StudentAuthorizationService $authService;

    public function __construct(StudentAuthorizationService $authService)
    {
        $this->authService = $authService;
    }

    public function dashboard(Request $request)
    {
        $student = $this->authService->resolveStudent($request->user());
        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $enrollments = CourseEnrollment::where('student_id', $student->id)->where('status', 'enrolled')->get();

        $overallAttendanceDetails = AttendanceDetail::where('student_id', $student->id)->get();
        $totalSessions = $overallAttendanceDetails->count();
        $presentSessions = $overallAttendanceDetails->where('status', 'present')->count();
        $overallAttendanceRate = $totalSessions > 0 ? round(($presentSessions / $totalSessions) * 100, 2) : null;

        $gpaRecord = GpaRecord::where('student_id', $student->id)->orderByDesc('semester_id')->first();

        // Get current period from the latest active enrollment
        $latestEnrollment = CourseEnrollment::where('student_id', $student->id)
            ->where('status', 'enrolled')
            ->with(['courseOffering.academicYear', 'courseOffering.semester'])
            ->orderByDesc('semester_id')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'profile' => [
                    'name' => $student->user->name,
                    'student_code' => $student->student_code,
                    'program' => $student->program->code ?? 'N/A',
                    'cohort' => $student->classRoom->name ?? 'N/A',
                ],
                'current_period' => $latestEnrollment ? [
                    'academic_year' => $latestEnrollment->courseOffering->academicYear->name ?? 'N/A',
                    'study_year' => $latestEnrollment->courseOffering->study_year ?? 1,
                    'semester' => $latestEnrollment->courseOffering->semester->name ?? 'N/A',
                ] : null,
                'metrics' => [
                    'enrolled_courses' => $enrollments->count(),
                    'overall_attendance_rate' => $overallAttendanceRate,
                    'cumulative_gpa' => $gpaRecord ? (float) $gpaRecord->gpa : null,
                ]
            ]
        ]);
    }

    public function academicJourney(Request $request)
    {
        $student = $this->authService->resolveStudent($request->user());
        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $offerings = CourseOffering::whereHas('enrollments', function ($q) use ($student) {
            $q->where('student_id', $student->id)->where('status', 'enrolled');
        })->with(['academicYear', 'semester', 'course'])->get();

        $grouped = [];
        foreach ($offerings as $offering) {
            $studyYear = $offering->study_year;
            $semesterName = $offering->semester->name ?? 'Unknown';
            $semesterStatus = $offering->semester->status ?? 'upcoming'; // active, completed, upcoming

            // Map DB status to display state
            $state = 'Upcoming';
            if ($semesterStatus === 'active') {
                $state = 'Current';
            } elseif ($semesterStatus === 'completed') {
                $state = 'Completed';
            }

            if (!isset($grouped[$studyYear])) {
                $grouped[$studyYear] = [
                    'study_year' => $studyYear,
                    'academic_years' => [],
                    'semesters' => []
                ];
            }
            if ($offering->academicYear && !in_array($offering->academicYear->name, $grouped[$studyYear]['academic_years'])) {
                $grouped[$studyYear]['academic_years'][] = $offering->academicYear->name;
            }

            if (!isset($grouped[$studyYear]['semesters'][$semesterName])) {
                $grouped[$studyYear]['semesters'][$semesterName] = [
                    'semester_name' => $semesterName,
                    'state' => $state,
                    'courses' => []
                ];
            }

            $grouped[$studyYear]['semesters'][$semesterName]['courses'][] = [
                'course_offering_id' => $offering->id,
                'course_name' => $offering->course->course_name,
                'course_code' => $offering->course->course_code,
                'credits' => $offering->course->credit ?? $offering->course->credits,
            ];
        }

        $output = array_values($grouped);
        // Sort by study_year
        usort($output, fn($a, $b) => $a['study_year'] <=> $b['study_year']);

        return response()->json([
            'success' => true,
            'data' => $output
        ]);
    }

    public function courses(Request $request)
    {
        $student = $this->authService->resolveStudent($request->user());
        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $query = CourseOffering::whereHas('enrollments', function ($q) use ($student) {
            $q->where('student_id', $student->id)->where('status', 'enrolled');
        })->with(['course', 'activeTeachers.user', 'semester', 'academicYear']);

        if ($request->filled('academic_year_id')) {
            $query->where('academic_year_id', $request->academic_year_id);
        }
        if ($request->filled('semester_id')) {
            $query->where('semester_id', $request->semester_id);
        }
        if ($request->filled('study_year')) {
            $query->where('study_year', $request->study_year);
        }

        $courses = $query->get()->map(function ($offering) {
            return [
                'course_offering_id' => $offering->id,
                'course_name' => $offering->course->course_name,
                'course_code' => $offering->course->course_code,
                'credits' => $offering->course->credit ?? $offering->course->credits,
                'teachers' => $offering->activeTeachers->map(fn($t) => $t->user->name),
                'semester' => $offering->semester->name ?? '',
                'academic_year' => $offering->academicYear->name ?? '',
                'study_year' => $offering->study_year,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $courses
        ]);
    }

    public function courseDetail(Request $request, $id)
    {
        $student = $this->authService->resolveStudent($request->user());
        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $offering = CourseOffering::where('id', $id)
            ->whereHas('enrollments', function ($q) use ($student) {
                $q->where('student_id', $student->id)->where('status', 'enrolled');
            })->with(['course', 'activeTeachers.user', 'semester', 'academicYear'])->first();

        if (!$offering) {
            return response()->json(['message' => 'Unauthorized or Course not found.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'course_offering_id' => $offering->id,
                'course_name' => $offering->course->course_name,
                'course_code' => $offering->course->course_code,
                'credits' => $offering->course->credit ?? $offering->course->credits,
                'teachers' => $offering->activeTeachers->map(fn($t) => $t->user->name),
                'semester' => $offering->semester->name ?? '',
                'academic_year' => $offering->academicYear->name ?? '',
                'study_year' => $offering->study_year,
            ]
        ]);
    }

    public function attendanceOverview(Request $request)
    {
        $student = $this->authService->resolveStudent($request->user());
        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $query = AttendanceDetail::where('student_id', $student->id)->with('attendance.courseOffering.course');

        if ($request->filled('semester_id')) {
            $query->whereHas('attendance.courseOffering', function ($q) use ($request) {
                $q->where('semester_id', $request->semester_id);
            });
        }

        if ($request->filled('study_year')) {
            $query->whereHas('attendance.courseOffering', function ($q) use ($request) {
                $q->where('study_year', $request->study_year);
            });
        }

        $details = $query->get();
        $total = $details->count();
        $present = $details->where('status', 'present')->count();

        $coursesBreakdown = $details->groupBy(fn($d) => $d->attendance->courseOffering->id ?? 0)
            ->map(function ($group, $offeringId) {
                $first = $group->first()->attendance->courseOffering;
                $t = $group->count();
                $p = $group->where('status', 'present')->count();
                return [
                    'course_offering_id' => $offeringId,
                    'course_name' => $first ? $first->course->course_name : 'Unknown',
                    'total' => $t,
                    'present' => $p,
                    'late' => $group->where('status', 'late')->count(),
                    'absent' => $group->where('status', 'absent')->count(),
                    'excused' => $group->where('status', 'excused')->count(),
                    'percentage' => $t > 0 ? round(($p / $t) * 100, 2) : 0,
                ];
            })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'total' => $total,
                    'present' => $present,
                    'late' => $details->where('status', 'late')->count(),
                    'absent' => $details->where('status', 'absent')->count(),
                    'excused' => $details->where('status', 'excused')->count(),
                    'percentage' => $total > 0 ? round(($present / $total) * 100, 2) : 0,
                ],
                'courses' => $coursesBreakdown
            ]
        ]);
    }

    public function attendanceCourse(Request $request, $id)
    {
        $student = $this->authService->resolveStudent($request->user());
        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $isEnrolled = CourseEnrollment::where('student_id', $student->id)->where('course_offering_id', $id)->exists();
        if (!$isEnrolled) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $details = AttendanceDetail::where('student_id', $student->id)
            ->whereHas('attendance', fn($q) => $q->where('course_offering_id', $id))
            ->with('attendance')
            ->get()->map(function ($d) {
                return [
                    'date' => $d->attendance->attendance_date,
                    'status' => $d->status,
                    'topic' => $d->attendance->topic,
                ];
            });

        // Provide aggregated info for calendar visualization
        $total = $details->count();
        $present = $details->where('status', 'present')->count();
        $late = $details->where('status', 'late')->count();
        $absent = $details->where('status', 'absent')->count();
        $excused = $details->where('status', 'excused')->count();
        $percentage = $total > 0 ? round(($present / $total) * 100, 2) : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'records' => $details,
                'summary' => [
                    'total' => $total,
                    'present' => $present,
                    'late' => $late,
                    'absent' => $absent,
                    'excused' => $excused,
                    'percentage' => $percentage
                ]
            ]
        ]);
    }

    public function gradesOverview(Request $request)
    {
        $student = $this->authService->resolveStudent($request->user());
        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        // Must respect publication rules - do not expose drafts as official
        $query = Grade::where('student_id', $student->id)
            ->whereIn('status', ['official', 'published', 'provisional', 'finalized'])
            ->with('course', 'courseOffering.course', 'courseOffering.semester');

        if ($request->filled('semester_id')) {
            $query->whereHas('courseOffering', function($q) use ($request) {
                $q->where('semester_id', $request->semester_id);
            });
        }
        if ($request->filled('study_year')) {
            $query->whereHas('courseOffering', function($q) use ($request) {
                $q->where('study_year', $request->study_year);
            });
        }

        $grades = $query->get();

        $passed = $grades->where('letter_grade', '!=', 'F')->where('letter_grade', '!=', null)->count();
        $failed = $grades->where('letter_grade', 'F')->count();

        $mapped = $grades->map(function ($g) {
            $course = $g->courseOffering?->course ?? $g->course;
            return [
                'course_offering_id' => $g->course_offering_id,
                'course_name' => $course?->course_name ?? 'Unknown',
                'credits' => $course?->credit ?? $course?->credits,
                'total_score' => $g->total_score,
                'letter_grade' => $g->letter_grade,
                'status' => $g->status, // distinguishes Provisional vs Official
            ];
        });

        // Compute Semester GPA if scoped by semester
        $gpaInfo = null;
        if ($request->filled('semester_id')) {
            $gpaRecord = GpaRecord::where('student_id', $student->id)->where('semester_id', $request->semester_id)->first();
            if ($gpaRecord) {
                $gpaInfo = [
                    'semester_gpa' => $gpaRecord->gpa,
                    'credit_hours' => $gpaRecord->earned_credits,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'courses' => $mapped,
                'summary' => [
                    'passed' => $passed,
                    'failed' => $failed,
                    'pending' => 0 // Would count enrolled courses without grades
                ],
                'gpa_info' => $gpaInfo
            ]
        ]);
    }

    public function gradesCourse(Request $request, $id)
    {
        $student = $this->authService->resolveStudent($request->user());
        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $isEnrolled = CourseEnrollment::where('student_id', $student->id)->where('course_offering_id', $id)->exists();
        if (!$isEnrolled) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $offering = CourseOffering::with(['course', 'activeTeachers.user', 'academicYear', 'semester'])->find($id);

        $grade = Grade::where('student_id', $student->id)->where('course_offering_id', $id)->first();

        // Real grading components derived from active items for this offering
        $breakdown = collect();

        // 1. Assignments (Homework, Project, etc)
        $assignments = Assignment::where('course_offering_id', $id)->where('status', 'published')
            ->with(['submissions' => fn($q) => $q->where('student_id', $student->id)])
            ->get();

        foreach ($assignments as $a) {
            $sub = $a->submissions->first();
            $isPublished = $sub && $sub->score !== null && strtolower((string) $sub->status) === 'graded';
            $breakdown->push([
                'component_name' => $a->title,
                'type' => $a->assignment_type,
                'earned_score' => $isPublished ? $sub->score : null,
                'maximum_score' => $a->total_score,
                'weight' => null, // Dynamic if weight was tracked
                'assessment_date' => $a->due_date,
                'publication_status' => $isPublished ? 'Published' : 'Pending',
                'teacher_feedback' => $isPublished ? $sub->feedback : null
            ]);
        }

        // 2. Quizzes
        $quizzes = Quiz::where('course_offering_id', $id)->whereIn('status', ['published', 'closed'])
            ->with(['attempts' => fn($q) => $q->where('student_id', $student->id)->orderByDesc('attempt_number')])
            ->get();

        foreach ($quizzes as $q) {
            $att = $q->attempts->first();
            $breakdown->push([
                'component_name' => $q->title,
                'type' => 'Quiz',
                'earned_score' => $att ? $att->score : null,
                'maximum_score' => $q->total_score,
                'weight' => null,
                'assessment_date' => $q->start_at,
                'publication_status' => ($att && $att->score !== null) ? 'Published' : 'Pending',
                'teacher_feedback' => null
            ]);
        }

        // 3. Exams
        $exams = Exam::where('course_id', $offering->course_id)->where('semester_id', $offering->semester_id)
            ->whereIn('status', ['published', 'completed'])
            ->with(['results' => fn($q) => $q->where('student_id', $student->id)])
            ->get();

        foreach ($exams as $e) {
            $res = $e->results->first();
            $breakdown->push([
                'component_name' => $e->title,
                'type' => $e->exam_type ?? 'Exam',
                'earned_score' => $res ? $res->score : null,
                'maximum_score' => $e->total_score,
                'weight' => null,
                'assessment_date' => $e->exam_date,
                'publication_status' => ($res && $res->score !== null) ? 'Published' : 'Pending',
                'teacher_feedback' => $res ? $res->feedback : null
            ]);
        }

        // 4. Attendance
        $attendanceSessions = Attendance::where('course_offering_id', $id)->count();
        if ($attendanceSessions > 0) {
            $attendanceDetails = AttendanceDetail::where('student_id', $student->id)
                ->whereHas('attendance', fn($q) => $q->where('course_offering_id', $id))->get();
            $presentCount = $attendanceDetails->where('status', 'present')->count();
            $breakdown->push([
                'component_name' => 'Attendance',
                'type' => 'Attendance',
                'earned_score' => $presentCount,
                'maximum_score' => $attendanceSessions,
                'weight' => null,
                'assessment_date' => null,
                'publication_status' => 'Published',
                'teacher_feedback' => null
            ]);
        }

        // Determine if grade is Official based on status
        $isOfficial = $grade && in_array($grade->status, ['official', 'finalized', 'published']);

        return response()->json([
            'success' => true,
            'data' => [
                'course_name' => $offering->course->course_name,
                'academic_year' => $offering->academicYear->name ?? '',
                'study_year' => $offering->study_year,
                'semester' => $offering->semester->name ?? '',
                'teacher' => $offering->activeTeachers->first()->user->name ?? 'Unknown',
                'final_score' => $isOfficial ? $grade->total_score : null,
                'letter_grade' => $isOfficial ? $grade->letter_grade : null,
                'result_status' => $grade ? ucfirst($grade->status) : 'Pending',
                'breakdown' => $breakdown
            ]
        ]);
    }

    public function analytics(Request $request)
    {
        $student = $this->authService->resolveStudent($request->user());
        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $analyticsQuery = LearningAnalytic::where('student_id', $student->id);

        if ($request->filled('semester_id')) {
            $analyticsQuery->where('semester_id', $request->semester_id);
        }

        $latest = clone $analyticsQuery->latest('calculated_at')->first();
        $history = clone $analyticsQuery->orderBy('period_start')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'current' => $latest,
                'trend' => $history->map(function($a) {
                    return [
                        'period' => $a->period_start,
                        'attendance_rate' => $a->attendance_rate,
                        'assignment_average' => $a->assignment_average,
                        'quiz_average' => $a->quiz_average,
                        'overall_performance' => $a->overall_performance
                    ];
                })
            ]
        ]);
    }

    public function predictionsAndRisk(Request $request)
    {
        $student = $this->authService->resolveStudent($request->user());
        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $riskQuery = RiskAssessment::where('student_id', $student->id)->with('course');
        $predQuery = Prediction::where('student_id', $student->id)->with('course');

        if ($request->filled('semester_id')) {
            $riskQuery->where('semester_id', $request->semester_id);
            $predQuery->where('semester_id', $request->semester_id);
        }

        $risks = $riskQuery->latest('assessment_date')->get();
        $predictions = $predQuery->latest('prediction_date')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'risks' => $risks->map(function($r) {
                    return [
                        'course' => $r->course->course_name ?? 'General',
                        'risk_level' => $r->risk_level,
                        'risk_score' => $r->risk_score,
                        'factors' => json_decode($r->factors, true) ?? [],
                        'last_updated' => $r->assessment_date
                    ];
                }),
                'predictions' => $predictions->map(function($p) {
                    return [
                        'course' => $p->course->course_name ?? 'General',
                        'predicted_score' => $p->predicted_score,
                        'confidence_level' => $p->confidence_level,
                        'factors' => json_decode($p->factors, true) ?? [],
                        'last_updated' => $p->prediction_date
                    ];
                })
            ]
        ]);
    }

    public function recommendations(Request $request)
    {
        $student = $this->authService->resolveStudent($request->user());
        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $recs = Recommendation::where('student_id', $student->id)
            ->where('status', 'pending')
            ->with('course')
            ->get()->map(function($r) {
                return [
                    'id' => $r->id,
                    'title' => $r->title,
                    'reason' => $r->reason,
                    'priority' => $r->priority,
                    'action' => $r->suggested_action,
                    'course_name' => $r->course->course_name ?? null,
                    'indicator' => $r->relevant_indicator ?? 'General Performance'
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $recs
        ]);
    }

    public function comparisons(Request $request)
    {
        $student = $this->authService->resolveStudent($request->user());
        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $gpaRecords = GpaRecord::where('student_id', $student->id)->with('semester.academicYear')->orderBy('semester_id')->get();

        $semesterComparison = [];
        $yearlyComparison = [];

        foreach ($gpaRecords as $record) {
            $semName = $record->semester->name ?? 'Unknown';
            $yearName = $record->semester->academicYear->name ?? 'Unknown';

            $semesterComparison[] = [
                'period' => "$semName ($yearName)",
                'gpa' => $record->gpa,
                'credits' => $record->earned_credits
            ];

            if (!isset($yearlyComparison[$yearName])) {
                $yearlyComparison[$yearName] = ['total_points' => 0, 'total_credits' => 0];
            }
            $yearlyComparison[$yearName]['total_points'] += ($record->gpa * $record->earned_credits);
            $yearlyComparison[$yearName]['total_credits'] += $record->earned_credits;
        }

        $formattedYearly = [];
        foreach ($yearlyComparison as $year => $data) {
            $formattedYearly[] = [
                'period' => $year,
                'gpa' => $data['total_credits'] > 0 ? round($data['total_points'] / $data['total_credits'], 2) : 0,
                'credits' => $data['total_credits']
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'semester_comparison' => $semesterComparison,
                'yearly_comparison' => $formattedYearly
            ]
        ]);
    }
}
