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
use App\Services\QuizGradingService;
use App\Services\StudentAnalyticsCalculator;
use App\Services\StudentAuthorizationService;
use App\Support\YearLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentModuleController extends Controller
{
    protected StudentAuthorizationService $authService;
    protected StudentAnalyticsCalculator $calculator;

    public function __construct(StudentAuthorizationService $authService, StudentAnalyticsCalculator $calculator)
    {
        $this->authService = $authService;
        $this->calculator = $calculator;
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

        $gpaRecords = GpaRecord::where('student_id', $student->id)->orderBy('semester_id')->get();
        $weightedPoints = 0.0;
        $weightedCredits = 0.0;
        foreach ($gpaRecords as $gr) {
            $cr = (float) ($gr->earned_credits ?: 0.0);
            if ($cr > 0) {
                $weightedPoints += ((float) $gr->gpa) * $cr;
                $weightedCredits += $cr;
            }
        }
        $cumulativeGpa = $weightedCredits > 0
            ? round($weightedPoints / $weightedCredits, 2)
            : ($gpaRecords->isNotEmpty() ? round((float) $gpaRecords->avg('gpa'), 2) : null);

        // Get current period from the latest active enrollment
        $latestEnrollment = CourseEnrollment::where('student_id', $student->id)
            ->where('status', 'enrolled')
            ->with(['courseOffering.academicYear', 'courseOffering.semester'])
            ->orderByDesc('semester_id')
            ->first();

        $student->load(['user', 'program', 'department', 'classRoom.academicYear']);
        $gpaCalc = app(\App\Services\GpaCalculationService::class);
        $currentPeriodInfo = $gpaCalc->resolveCurrentPeriod($student);
        $resolvedPeriodYear = $currentPeriodInfo['study_year'];
        $liveSemSummary = $currentPeriodInfo['semester_id']
            ? $gpaCalc->calculateSemesterSummary($student, (int) $currentPeriodInfo['semester_id'], $resolvedPeriodYear)
            : null;
        $currentSemesterGpa = ($liveSemSummary && $liveSemSummary['is_complete']) ? $liveSemSummary['semester_gpa'] : null;

        return response()->json([
            'success' => true,
            'data' => [
                'profile' => [
                    'name' => $student->user->name,
                    'student_code' => $student->student_code,
                    'faculty' => $student->department->name ?? 'N/A',
                    'faculty_code' => $student->department->code ?? 'N/A',
                    'program' => $student->program->code ?? 'N/A',
                    'major' => $student->program->name ?? ($student->major ?? 'N/A'),
                    'cohort' => $student->classRoom->name ?? 'N/A',
                    'academic_year' => $currentPeriodInfo['academic_year_name'],
                    'year_level' => YearLevel::name($resolvedPeriodYear) ?? 'Unassigned',
                    'year_level_number' => $resolvedPeriodYear,
                    'year_level_code' => YearLevel::code($resolvedPeriodYear),
                    'year_level_name' => YearLevel::name($resolvedPeriodYear),
                    'year_level_khmer' => YearLevel::khmer($resolvedPeriodYear),
                    'year_level_label' => YearLevel::label($resolvedPeriodYear),
                    'progression_status' => $student->progression_status ?? 'in_progress',
                ],
                'current_period' => [
                    'academic_year_id' => $currentPeriodInfo['academic_year_id'],
                    'academic_year' => $currentPeriodInfo['academic_year_name'],
                    'study_year' => $resolvedPeriodYear,
                    'year_level_code' => YearLevel::code($resolvedPeriodYear),
                    'year_level_label' => YearLevel::label($resolvedPeriodYear),
                    'semester_id' => $currentPeriodInfo['semester_id'],
                    'semester_number' => $currentPeriodInfo['semester_number'],
                    'semester' => $currentPeriodInfo['semester_name'],
                ],
                'metrics' => [
                    'enrolled_courses' => $enrollments->count(),
                    'overall_attendance_rate' => $overallAttendanceRate,
                    'current_semester_gpa' => $currentSemesterGpa,
                    'semester_gpa' => $currentSemesterGpa,
                    'semester_average' => $liveSemSummary['semester_average'] ?? null,
                    'cumulative_gpa' => $cumulativeGpa,
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
        })->with(['academicYear', 'semester', 'course', 'classRoom'])->get();

        $grouped = [];
        foreach ($offerings as $offering) {
            $studyYear = YearLevel::parse($offering->study_year ?? $offering->classRoom?->year_level ?? $offering->course?->year_level) ?? 0;
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
                    'year_level' => $studyYear > 0 ? $studyYear : null,
                    'year_level_code' => YearLevel::code($studyYear),
                    'year_level_name' => YearLevel::name($studyYear),
                    'year_level_label' => YearLevel::label($studyYear),
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
        if ($request->filled('study_year') || $request->filled('year_level')) {
            $yl = YearLevel::parse($request->query('year_level', $request->query('study_year')));
            if ($yl !== null) {
                $query->where('study_year', $yl);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $courses = $query->get()->map(function ($offering) {
            $yl = YearLevel::parse($offering->study_year ?? $offering->classRoom?->year_level ?? $offering->course?->year_level);

            return [
                'course_offering_id' => $offering->id,
                'course_name' => $offering->course->course_name,
                'course_code' => $offering->course->course_code,
                'credits' => $offering->course->credit ?? $offering->course->credits,
                'teachers' => $offering->activeTeachers->map(fn($t) => $t->user->name),
                'semester' => $offering->semester->name ?? '',
                'academic_year' => $offering->academicYear->name ?? '',
                'study_year' => $yl ?? $offering->study_year,
                'year_level' => $yl,
                'year_level_code' => YearLevel::code($yl),
                'year_level_label' => YearLevel::label($yl),
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

        $offeringYl = YearLevel::parse($offering->study_year ?? $offering->classRoom?->year_level ?? $offering->course?->year_level);

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
                'study_year' => $offeringYl ?? $offering->study_year,
                'year_level' => $offeringYl,
                'year_level_code' => YearLevel::code($offeringYl),
                'year_level_label' => YearLevel::label($offeringYl),
            ]
        ]);
    }

    public function attendanceOverview(Request $request)
    {
        $student = $this->authService->resolveStudent($request->user());
        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $query = AttendanceDetail::where('student_id', $student->id)->with(['attendance.courseOffering.course', 'attendance.course']);

        if ($request->filled('semester_id')) {
            $query->whereHas('attendance.courseOffering', function ($q) use ($request) {
                $q->where('semester_id', $request->semester_id);
            });
        }

        if ($request->filled('study_year') || $request->filled('year_level')) {
            $yl = YearLevel::parse($request->query('year_level', $request->query('study_year')));
            $query->whereHas('attendance.courseOffering', function ($q) use ($yl) {
                if ($yl !== null) {
                    $q->where('study_year', $yl);
                } else {
                    $q->whereRaw('1 = 0');
                }
            });
        }

        $details = $query->get();
        $total = $details->count();
        $present = $details->where('status', 'present')->count();

        $coursesBreakdown = $details->groupBy(fn ($d) => $d->attendance?->courseOffering?->id ?? $d->attendance?->course_id ?? 0)
            ->map(function ($group, $offeringId) {
                $firstAtt = $group->first()?->attendance;
                $first = $firstAtt?->courseOffering;
                $t = $group->count();
                $p = $group->where('status', 'present')->count();
                return [
                    'course_offering_id' => $first?->id ?? $offeringId,
                    'course_name' => $first?->course?->course_name ?? $firstAtt?->course?->course_name ?? 'Unknown',
                    'total' => $t,
                    'present' => $p,
                    'late' => $group->where('status', 'late')->count(),
                    'absent' => $group->where('status', 'absent')->count(),
                    'excused' => $group->where('status', 'excused')->count(),
                    'percentage' => $t > 0 ? round(($p / $t) * 100, 2) : null,
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
                    'percentage' => $total > 0 ? round(($present / $total) * 100, 2) : null,
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
                    'date' => $d->attendance?->attendance_date,
                    'status' => $d->status,
                    'topic' => $d->attendance?->topic,
                ];
            });

        // Provide aggregated info for calendar visualization
        $total = $details->count();
        $present = $details->where('status', 'present')->count();
        $late = $details->where('status', 'late')->count();
        $absent = $details->where('status', 'absent')->count();
        $excused = $details->where('status', 'excused')->count();
        $percentage = $total > 0 ? round(($present / $total) * 100, 2) : null;

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
        if ($request->filled('study_year') || $request->filled('year_level')) {
            $yl = YearLevel::parse($request->query('year_level', $request->query('study_year')));
            $query->whereHas('courseOffering', function ($q) use ($yl) {
                if ($yl !== null) {
                    $q->where('study_year', $yl);
                } else {
                    $q->whereRaw('1 = 0');
                }
            });
        }

        $grades = $query->get();

        $passed = $grades->where('letter_grade', '!=', 'F')->where('letter_grade', '!=', null)->count();
        $failed = $grades->where('letter_grade', 'F')->count();

        $enrollPendingQuery = CourseEnrollment::where('student_id', $student->id)
            ->whereIn('status', ['enrolled', 'active'])
            ->with(['courseOffering.course', 'courseOffering.semester', 'courseOffering.academicYear']);
        if ($request->filled('semester_id')) {
            $enrollPendingQuery->where('semester_id', $request->semester_id);
        }
        if ($request->filled('study_year') || $request->filled('year_level')) {
            $yl = YearLevel::parse($request->query('year_level', $request->query('study_year')));
            $enrollPendingQuery->whereHas('courseOffering', function ($q) use ($yl) {
                if ($yl !== null) {
                    $q->where('study_year', $yl);
                } else {
                    $q->whereRaw('1 = 0');
                }
            });
        }
        $enrollments = $enrollPendingQuery->get();
        $enrolledOfferingIds = $enrollments->pluck('course_offering_id')->filter()->unique();
        $gradedOfferingIds = $grades->pluck('course_offering_id')->filter()->unique();
        $pending = $enrolledOfferingIds->diff($gradedOfferingIds)->count();

        $mapped = $grades->map(function ($g) {
            $offering = $g->courseOffering;
            $course = $offering?->course ?? $g->course;
            return [
                'course_offering_id' => $g->course_offering_id,
                'course_name' => $course?->course_name ?? 'Unknown',
                'course_code' => $course?->course_code ?? '',
                'credits' => $course?->credit ?? $course?->credits,
                'semester' => $offering?->semester?->name ?? '',
                'academic_year' => $offering?->academicYear?->name ?? '',
                'total_score' => $g->total_score,
                'letter_grade' => $g->letter_grade,
                'status' => $g->status, // distinguishes Provisional vs Official
            ];
        })->values();

        // Include active enrolled courses without finalized grades (including draft-only) as pending, never leaking draft scores
        $seenOfferings = $gradedOfferingIds->flip()->all();
        foreach ($enrollments as $enrollment) {
            $offeringId = $enrollment->course_offering_id;
            if (!$offeringId || isset($seenOfferings[$offeringId])) {
                continue;
            }
            $seenOfferings[$offeringId] = true;
            $offering = $enrollment->courseOffering;
            $course = $offering?->course;
            $mapped->push([
                'course_offering_id' => $offeringId,
                'course_name' => $course?->course_name ?? 'Unknown',
                'course_code' => $course?->course_code ?? '',
                'credits' => $course?->credit ?? $course?->credits,
                'semester' => $offering?->semester?->name ?? '',
                'academic_year' => $offering?->academicYear?->name ?? '',
                'total_score' => null,
                'letter_grade' => null,
                'status' => 'pending',
            ]);
        }

        // Compute Semester GPA if scoped by semester, or cumulative GPA when unfiltered if records exist
        $gpaInfo = null;
        if ($request->filled('semester_id')) {
            $gpaRecord = GpaRecord::where('student_id', $student->id)->where('semester_id', $request->semester_id)->first();
            if ($gpaRecord) {
                $gpaInfo = [
                    'semester_gpa' => $gpaRecord->gpa,
                    'credit_hours' => $gpaRecord->earned_credits,
                ];
            }
        } else {
            $gpaRecords = GpaRecord::where('student_id', $student->id)->get();
            if ($gpaRecords->isNotEmpty()) {
                $weightedPoints = 0.0;
                $weightedCredits = 0.0;
                foreach ($gpaRecords as $gr) {
                    $cr = (float) ($gr->earned_credits ?: 0.0);
                    if ($cr > 0) {
                        $weightedPoints += ((float) $gr->gpa) * $cr;
                        $weightedCredits += $cr;
                    }
                }
                $cumGpa = $weightedCredits > 0
                    ? round($weightedPoints / $weightedCredits, 2)
                    : round((float) $gpaRecords->avg('gpa'), 2);
                $gpaInfo = [
                    'cumulative_gpa' => $cumGpa,
                    'credit_hours' => $weightedCredits,
                ];
            }
        }

        $gpaCalc = app(\App\Services\GpaCalculationService::class);
        $currentPeriodInfo = $gpaCalc->resolveCurrentPeriod($student);
        $currentSemSummary = $currentPeriodInfo['semester_id']
            ? $gpaCalc->calculateSemesterSummary($student, (int) $currentPeriodInfo['semester_id'], $currentPeriodInfo['study_year'])
            : null;

        return response()->json([
            'success' => true,
            'data' => [
                'current_period' => [
                    'academic_year_id' => $currentPeriodInfo['academic_year_id'],
                    'academic_year' => $currentPeriodInfo['academic_year_name'],
                    'study_year' => $currentPeriodInfo['study_year'],
                    'year_level_code' => YearLevel::code($currentPeriodInfo['study_year']),
                    'year_level_label' => YearLevel::label($currentPeriodInfo['study_year']),
                    'semester_id' => $currentPeriodInfo['semester_id'],
                    'semester_number' => $currentPeriodInfo['semester_number'],
                    'semester' => $currentPeriodInfo['semester_name'],
                ],
                'current_semester' => $currentSemSummary,
                'courses' => $mapped,
                'summary' => [
                    'passed' => $passed,
                    'failed' => $failed,
                    'pending' => $pending,
                    'semester_average' => $currentSemSummary['semester_average'] ?? null,
                    'semester_average_display' => $currentSemSummary['semester_average_display'] ?? 'N/A',
                    'semester_gpa' => $currentSemSummary['semester_gpa'] ?? null,
                    'semester_gpa_display' => $currentSemSummary['semester_gpa_display'] ?? 'N/A',
                ],
                'gpa_info' => $gpaInfo ?? ($currentSemSummary ? [
                    'semester_gpa' => $currentSemSummary['semester_gpa'],
                    'semester_average' => $currentSemSummary['semester_average'],
                    'credit_hours' => $currentSemSummary['earned_credits'],
                ] : null),
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
            ->with(['submissions' => fn($q) => $q->where('student_id', $student->id)->orderByDesc('version_number')->orderByDesc('id')])
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
        $quizzes = Quiz::where('course_offering_id', $id)->whereIn('status', ['published', 'open', 'closed'])
            ->with(['attempts' => fn ($q) => $q->where('student_id', $student->id)->orderByDesc('attempt_number')])
            ->get();

        $quizGrading = app(QuizGradingService::class);
        foreach ($quizzes as $q) {
            $effectiveScore = $quizGrading->calculateStudentQuizScore($q, $q->attempts);
            $hasPending = $q->attempts->contains(fn ($a) => ! $a->is_reset && $a->isPendingManualGrading());
            $pubStatus = $hasPending && $effectiveScore === null
                ? 'Pending Grading'
                : ($effectiveScore !== null ? 'Published' : 'Pending');
            $breakdown->push([
                'component_name' => $q->title,
                'type' => 'Quiz',
                'earned_score' => ($hasPending && $q->scoring_method === 'latest') ? null : $effectiveScore,
                'maximum_score' => $q->total_score,
                'weight' => null,
                'assessment_date' => $q->start_at,
                'publication_status' => $pubStatus,
                'teacher_feedback' => null,
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

        $gradeOfferingYl = YearLevel::parse($offering->study_year ?? $offering->classRoom?->year_level ?? $offering->course?->year_level);

        return response()->json([
            'success' => true,
            'data' => [
                'course_name' => $offering?->course?->course_name ?? 'Course',
                'academic_year' => $offering?->academicYear?->name ?? '',
                'study_year' => $gradeOfferingYl ?? $offering?->study_year,
                'year_level' => $gradeOfferingYl,
                'year_level_code' => YearLevel::code($gradeOfferingYl),
                'year_level_label' => YearLevel::label($gradeOfferingYl),
                'semester' => $offering?->semester?->name ?? '',
                'teacher' => $offering?->activeTeachers?->first()?->user?->name ?? 'Unknown',
                'final_score' => $isOfficial ? $grade->total_score : null,
                'letter_grade' => $isOfficial ? $grade->letter_grade : null,
                'final_grade' => [
                    'total_score' => $isOfficial ? $grade->total_score : null,
                    'letter_grade' => $isOfficial ? $grade->letter_grade : null,
                ],
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

        $semesterId = $request->filled('semester_id') ? (int) $request->semester_id : null;
        $analyticsQuery = LearningAnalytic::where('student_id', $student->id);

        if ($semesterId) {
            $analyticsQuery->where('semester_id', $semesterId);
        }

        $latest = (clone $analyticsQuery)->latest('calculated_at')->first();
        $history = (clone $analyticsQuery)->orderBy('period_start')->get();

        $trend = [];
        if ($history->isNotEmpty()) {
            $trend = $history->map(function ($a) {
                return [
                    'period' => $a->period_start,
                    'attendance_rate' => $a->attendance_rate,
                    'assignment_average' => $a->assignment_average,
                    'quiz_average' => $a->quiz_average,
                    'exam_average' => $a->exam_average,
                    'overall_performance' => $a->overall_performance,
                ];
            })->values()->all();
        } else {
            $liveSeries = $this->calculator->buildTimeSeries($student, 'monthly', $semesterId);
            $trend = array_map(fn (array $row) => [
                'period' => $row['period_start'],
                'attendance_rate' => $row['attendance_rate'],
                'assignment_average' => $row['assignment_average'],
                'quiz_average' => $row['quiz_average'],
                'exam_average' => $row['exam_average'],
                'overall_performance' => $row['overall_performance'],
            ], $liveSeries);
        }

        $liveCurrent = $this->calculator->computeCurrentSummary($student, $semesterId);
        $currentPayload = $latest ? [
            'attendance_rate' => $latest->attendance_rate ?? $liveCurrent['attendance_rate'],
            'assignment_average' => $latest->assignment_average ?? $liveCurrent['assignment_average'],
            'quiz_average' => $latest->quiz_average ?? $liveCurrent['quiz_average'],
            'exam_average' => $latest->exam_average ?? $liveCurrent['exam_average'],
            'overall_performance' => $latest->overall_performance ?? $liveCurrent['overall_performance'],
            'calculated_at' => $latest->calculated_at,
        ] : $liveCurrent;

        return response()->json([
            'success' => true,
            'data' => [
                'current' => $currentPayload,
                'attendance_rate' => $currentPayload['attendance_rate'],
                'assignment_average' => $currentPayload['assignment_average'],
                'quiz_average' => $currentPayload['quiz_average'],
                'exam_average' => $currentPayload['exam_average'],
                'overall_performance' => $currentPayload['overall_performance'],
                'trend' => $trend,
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
                'risks' => $risks->map(function ($r) {
                    $rawFactors = $r->risk_factors;
                    if (is_array($rawFactors)) {
                        $factors = $rawFactors;
                    } elseif (is_string($rawFactors) && trim($rawFactors) !== '') {
                        $decoded = json_decode($rawFactors, true);
                        $factors = is_array($decoded) ? $decoded : [$rawFactors];
                    } else {
                        $factors = [];
                    }

                    return [
                        'course' => $r->course?->course_name ?? 'General',
                        'risk_level' => $r->risk_level,
                        'risk_score' => $r->risk_score,
                        'factors' => $factors,
                        'last_updated' => $r->assessment_date,
                    ];
                }),
                'predictions' => $predictions->map(function ($p) {
                    return [
                        'course' => $p->course?->course_name ?? 'General',
                        'predicted_score' => $p->predicted_score,
                        'confidence_score' => $p->confidence_score,
                        'confidence_level' => $p->confidence_score,
                        'model_version' => $p->model_version,
                        'notes' => $p->notes,
                        'factors' => $p->notes ? [$p->notes] : [],
                        'last_updated' => $p->prediction_date,
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
            ->whereNotIn('status', ['completed', 'dismissed'])
            ->with('course')
            ->latest()
            ->get()
            ->map(function ($r) {
                $courseName = $r->course?->course_name;
                $typeLabel = ucfirst(str_replace('_', ' ', (string) ($r->type ?? 'general')));
                $actionLabel = match (strtolower((string) ($r->type ?? 'general'))) {
                    'attendance' => $courseName ? "Attend all upcoming {$courseName} sessions" : 'Improve class attendance consistency',
                    'assignment' => $courseName ? "Review pending assignments in {$courseName}" : 'Complete upcoming assignment tasks early',
                    'quiz' => $courseName ? "Practice quiz topics for {$courseName}" : 'Review recent quiz feedback',
                    'exam' => $courseName ? "Prepare study guide for {$courseName} exam" : 'Begin structured exam preparation',
                    default => $courseName ? "Focus study plan on {$courseName}" : 'Follow mentor & system study guidance',
                };

                return [
                    'id' => $r->id,
                    'title' => $r->title,
                    'reason' => $r->message,
                    'message' => $r->message,
                    'priority' => $r->priority,
                    'status' => $r->status,
                    'action' => $actionLabel,
                    'course_name' => $courseName,
                    'indicator' => $typeLabel,
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
