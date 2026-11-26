<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Attendance;
use App\Models\AttendanceDetail;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Teacher;
use App\Support\YearLevel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class TeacherAnalyticsService
{
    public function __construct(
        protected TeacherAuthorizationService $authService,
        protected TeacherDashboardService $dashboardService
    ) {}

    /**
     * Calculate engagement overview metrics comparing the four learning activities.
     *
     * @param array<string, mixed> $filters
     * @return array{
     *     scope: array<string, string|null>,
     *     summary: array<string, int>,
     *     engagement: array<int, array{
     *         activity: string,
     *         completed: float|null,
     *         late: float|null,
     *         missed: float|null,
     *         total_events: int,
     *         has_data: bool
     *     }>
     * }
     */
    public function calculateEngagement(Teacher $teacher, array $filters = []): array
    {
        $offeringsQuery = $this->authService->getAuthorizedOfferingsQuery($teacher);
        $this->dashboardService->applyScopeFilters($offeringsQuery, $filters);

        $offeringIds = (clone $offeringsQuery)->pluck('id')->all();
        $courseIds = (clone $offeringsQuery)->pluck('course_id')->filter()->unique()->all();

        // 1. Resolve human-readable scope info
        $scope = $this->resolveScopeLabels($filters, $offeringsQuery);

        // 2. Summary counts for this scope
        $summary = $this->dashboardService->getSummaryMetrics($teacher, $filters);

        // 3. Count eligible enrolled students in this scope
        $enrolledStudentCount = 0;
        if (! empty($offeringIds)) {
            $enrolledStudentCount = CourseEnrollment::whereIn('course_offering_id', $offeringIds)
                ->where('status', 'enrolled')
                ->distinct('student_id')
                ->count('student_id');
        } elseif (! empty($courseIds)) {
            $enrolledStudentCount = CourseEnrollment::whereIn('course_id', $courseIds)
                ->where('status', 'enrolled')
                ->distinct('student_id')
                ->count('student_id');
        }

        // 4. Calculate individual activity engagement
        $attendanceMetrics = $this->calculateAttendanceEngagement($offeringIds, $courseIds, $teacher);
        $assignmentMetrics = $this->calculateAssignmentEngagement($offeringIds, $courseIds, $enrolledStudentCount, false);
        $homeworkMetrics   = $this->calculateAssignmentEngagement($offeringIds, $courseIds, $enrolledStudentCount, true);
        $quizMetrics       = $this->calculateQuizEngagement($offeringIds, $courseIds, $enrolledStudentCount);

        return [
            'scope' => $scope,
            'summary' => $summary,
            'engagement' => [
                $attendanceMetrics,
                $assignmentMetrics,
                $homeworkMetrics,
                $quizMetrics,
            ],
        ];
    }

    /**
     * Calculate Attendance engagement metrics.
     *
     * @param array<int> $offeringIds
     * @param array<int> $courseIds
     * @return array{activity: string, completed: float|null, late: float|null, missed: float|null, total_events: int, has_data: bool}
     */
    protected function calculateAttendanceEngagement(array $offeringIds, array $courseIds, Teacher $teacher): array
    {
        if (empty($offeringIds) && empty($courseIds)) {
            return [
                'activity' => 'Attendance',
                'completed' => null,
                'late' => null,
                'missed' => null,
                'total_events' => 0,
                'has_data' => false,
            ];
        }

        $attendanceQuery = Attendance::query()
            ->where(function (Builder $q) use ($offeringIds, $courseIds) {
                if (! empty($offeringIds)) {
                    $q->whereIn('course_offering_id', $offeringIds);
                } elseif (! empty($courseIds)) {
                    $q->whereIn('course_id', $courseIds);
                }
            })
            ->where('status', 'completed');

        $attendanceIds = $attendanceQuery->pluck('id')->all();

        if (empty($attendanceIds)) {
            return [
                'activity' => 'Attendance',
                'completed' => null,
                'late' => null,
                'missed' => null,
                'total_events' => 0,
                'has_data' => false,
            ];
        }

        $details = AttendanceDetail::whereIn('attendance_id', $attendanceIds)
            ->selectRaw("status, count(*) as count")
            ->groupBy('status')
            ->pluck('count', 'status');

        $present = (int) ($details['present'] ?? 0);
        $late    = (int) ($details['late'] ?? 0);
        $absent  = (int) ($details['absent'] ?? 0);
        $excused = (int) ($details['excused'] ?? 0);

        $totalExpected = $present + $late + $absent + $excused;

        if ($totalExpected === 0) {
            return [
                'activity' => 'Attendance',
                'completed' => null,
                'late' => null,
                'missed' => null,
                'total_events' => 0,
                'has_data' => false,
            ];
        }

        // Attendance rate considers present + late as present sessions or keeps them distinct
        $completedRate = round(($present / $totalExpected) * 100, 1);
        $lateRate      = round(($late / $totalExpected) * 100, 1);
        $missedRate    = round(($absent / $totalExpected) * 100, 1);

        return [
            'activity' => 'Attendance',
            'completed' => $completedRate,
            'late' => $lateRate,
            'missed' => $missedRate,
            'total_events' => $totalExpected,
            'has_data' => true,
        ];
    }

    /**
     * Calculate Assignment or Homework engagement metrics.
     *
     * @param array<int> $offeringIds
     * @param array<int> $courseIds
     * @return array{activity: string, completed: float|null, late: float|null, missed: float|null, total_events: int, has_data: bool}
     */
    protected function calculateAssignmentEngagement(
        array $offeringIds,
        array $courseIds,
        int $enrolledCount,
        bool $isHomework
    ): array {
        $activityName = $isHomework ? 'Homework' : 'Assignment';

        if (empty($offeringIds) && empty($courseIds)) {
            return [
                'activity' => $activityName,
                'completed' => null,
                'late' => null,
                'missed' => null,
                'total_events' => 0,
                'has_data' => false,
            ];
        }

        $assignmentsQuery = Assignment::query()
            ->where(function (Builder $q) use ($offeringIds, $courseIds) {
                if (! empty($offeringIds)) {
                    $q->whereIn('course_offering_id', $offeringIds);
                } elseif (! empty($courseIds)) {
                    $q->whereIn('course_id', $courseIds);
                }
            })
            ->where('status', '!=', 'draft');

        if ($isHomework) {
            $assignmentsQuery->where('assignment_type', 'homework');
        } else {
            $assignmentsQuery->where('assignment_type', '!=', 'homework');
        }

        $assignments = $assignmentsQuery->get(['id', 'due_date', 'ends_at']);

        if ($assignments->isEmpty()) {
            return [
                'activity' => $activityName,
                'completed' => null,
                'late' => null,
                'missed' => null,
                'total_events' => 0,
                'has_data' => false,
            ];
        }

        $assignmentIds = $assignments->pluck('id')->all();

        $submissions = AssignmentSubmission::whereIn('assignment_id', $assignmentIds)
            ->where('is_latest', true)
            ->whereIn('status', ['submitted', 'late', 'graded'])
            ->get(['id', 'assignment_id', 'status', 'submitted_at']);

        $totalSubmissions = $submissions->count();
        $lateSubmissions  = $submissions->where('status', 'late')->count();
        $onTimeSubmissions = $totalSubmissions - $lateSubmissions;

        // Total expected submissions = enrolled students * eligible closed/due assignments
        $closedAssignmentCount = $assignments->filter(function ($a) {
            $due = $a->ends_at ?? $a->due_date;
            return $due && Carbon::parse($due)->isPast();
        })->count();

        // If no assignments are past deadline yet, use total count if available
        $expectedTotal = ($closedAssignmentCount > 0 ? $closedAssignmentCount : $assignments->count()) * max(1, $enrolledCount);

        if ($expectedTotal < $totalSubmissions) {
            $expectedTotal = $totalSubmissions;
        }

        if ($expectedTotal === 0) {
            return [
                'activity' => $activityName,
                'completed' => null,
                'late' => null,
                'missed' => null,
                'total_events' => 0,
                'has_data' => false,
            ];
        }

        $missedSubmissions = max(0, $expectedTotal - $totalSubmissions);

        $completedRate = round(($onTimeSubmissions / $expectedTotal) * 100, 1);
        $lateRate      = round(($lateSubmissions / $expectedTotal) * 100, 1);
        $missedRate    = round(($missedSubmissions / $expectedTotal) * 100, 1);

        return [
            'activity' => $activityName,
            'completed' => min(100.0, $completedRate),
            'late' => min(100.0, $lateRate),
            'missed' => min(100.0, $missedRate),
            'total_events' => $expectedTotal,
            'has_data' => true,
        ];
    }

    /**
     * Calculate Quiz engagement metrics.
     *
     * @param array<int> $offeringIds
     * @param array<int> $courseIds
     * @return array{activity: string, completed: float|null, late: float|null, missed: float|null, total_events: int, has_data: bool}
     */
    protected function calculateQuizEngagement(array $offeringIds, array $courseIds, int $enrolledCount): array
    {
        if (empty($offeringIds) && empty($courseIds)) {
            return [
                'activity' => 'Quiz',
                'completed' => null,
                'late' => null,
                'missed' => null,
                'total_events' => 0,
                'has_data' => false,
            ];
        }

        $quizzesQuery = Quiz::query()
            ->where(function (Builder $q) use ($offeringIds, $courseIds) {
                if (! empty($offeringIds)) {
                    $q->whereIn('course_offering_id', $offeringIds);
                } elseif (! empty($courseIds)) {
                    $q->whereIn('course_id', $courseIds);
                }
            })
            ->where('status', '!=', 'draft');

        $quizzes = $quizzesQuery->get(['id', 'start_at', 'end_at', 'status']);

        if ($quizzes->isEmpty()) {
            return [
                'activity' => 'Quiz',
                'completed' => null,
                'late' => null,
                'missed' => null,
                'total_events' => 0,
                'has_data' => false,
            ];
        }

        $quizIds = $quizzes->pluck('id')->all();

        $attempts = QuizAttempt::whereIn('quiz_id', $quizIds)
            ->whereIn('status', ['submitted', 'completed', 'graded'])
            ->count();

        $eligibleQuizCount = $quizzes->filter(function ($q) {
            return $q->status === 'closed' || ($q->end_at && Carbon::parse($q->end_at)->isPast());
        })->count();

        $expectedTotal = ($eligibleQuizCount > 0 ? $eligibleQuizCount : $quizzes->count()) * max(1, $enrolledCount);

        if ($expectedTotal < $attempts) {
            $expectedTotal = $attempts;
        }

        if ($expectedTotal === 0) {
            return [
                'activity' => 'Quiz',
                'completed' => null,
                'late' => null,
                'missed' => null,
                'total_events' => 0,
                'has_data' => false,
            ];
        }

        $missed = max(0, $expectedTotal - $attempts);

        $completedRate = round(($attempts / $expectedTotal) * 100, 1);
        $missedRate    = round(($missed / $expectedTotal) * 100, 1);

        return [
            'activity' => 'Quiz',
            'completed' => min(100.0, $completedRate),
            'late' => 0.0, // Quizzes do not permit late attempts past deadline
            'missed' => min(100.0, $missedRate),
            'total_events' => $expectedTotal,
            'has_data' => true,
        ];
    }

    /**
     * Resolve descriptive labels for the active filter scope.
     *
     * @param array<string, mixed> $filters
     * @return array<string, string|null>
     */
    protected function resolveScopeLabels(array $filters, Builder $offeringsQuery): array
    {
        $firstOffering = (clone $offeringsQuery)->with(['course', 'classRoom', 'semester.academicYear'])->first();

        $yl = YearLevel::parse($filters['year_level'] ?? $filters['study_year'] ?? null);

        return [
            'academic_year' => $firstOffering?->semester?->academicYear?->name ?? 'All Assigned Years',
            'semester' => $firstOffering?->semester?->name ?? 'All Assigned Semesters',
            'course' => $firstOffering?->course?->name ?? $firstOffering?->course?->course_name ?? 'All Assigned Courses',
            'class' => $firstOffering?->classRoom?->name ?? 'All Assigned Classes',
            'year_level' => $yl !== null ? YearLevel::label($yl) : null,
            'year_level_code' => $yl !== null ? YearLevel::code($yl) : null,
            'study_year' => $yl !== null ? YearLevel::name($yl) : null,
        ];
    }
}
