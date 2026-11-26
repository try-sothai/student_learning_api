<?php

namespace App\Services;

use App\Models\AssignmentAttempt;
use App\Models\AssignmentSubmission;
use App\Models\AttendanceDetail;
use App\Models\ExamResult;
use App\Models\QuizAttempt;
use App\Models\Semester;
use App\Models\Student;
use Carbon\Carbon;

class StudentAnalyticsCalculator
{
    /**
     * Build time-series analytics points for a student from real academic records.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildTimeSeries(
        Student $student,
        string $period = 'monthly',
        ?int $semesterId = null,
        ?int $courseId = null
    ): array {
        $period = in_array($period, ['weekly', 'monthly', 'semester', 'yearly'], true) ? $period : 'monthly';

        $attendances = $this->fetchAttendanceRows($student->id, $semesterId, $courseId);
        $assignments = $this->fetchDeduplicatedAssignmentRows($student->id, $semesterId, $courseId);
        $quizzes = $this->fetchDeduplicatedQuizRows($student->id, $semesterId, $courseId);
        $exams = $this->fetchDeduplicatedExamRows($student->id, $semesterId, $courseId);

        $semestersById = [];
        if ($period === 'semester') {
            $semIds = collect([$attendances, $assignments, $quizzes, $exams])
                ->flatten(1)
                ->pluck('semester_id')
                ->filter()
                ->unique()
                ->all();
            if (! empty($semIds)) {
                $semestersById = Semester::whereIn('id', $semIds)->get()->keyBy('id')->all();
            }
        }

        $buckets = [];
        $resolveBucket = function ($row) use ($period, $semestersById): ?array {
            $rawDate = $row->effective_date ?? null;
            if ($period === 'semester') {
                $semId = (int) ($row->semester_id ?? 0);
                $sem = $semId > 0 ? ($semestersById[$semId] ?? null) : null;
                if ($sem) {
                    $startDate = $sem->start_date
                        ? Carbon::parse($sem->start_date)->format('Y-m-d')
                        : ($rawDate ? Carbon::parse($rawDate)->startOfMonth()->format('Y-m-d') : null);
                    if (! $startDate) {
                        return null;
                    }
                    return [
                        'key' => sprintf('sem_%06d', $semId),
                        'period_start' => $startDate,
                        'period_label' => $sem->name,
                        'semester_id' => $semId,
                    ];
                }
            }

            if (! $rawDate) {
                return null;
            }
            $d = Carbon::parse($rawDate);
            $start = match ($period) {
                'weekly' => $d->copy()->startOfWeek()->format('Y-m-d'),
                'yearly' => $d->copy()->startOfYear()->format('Y-m-d'),
                default => $d->copy()->startOfMonth()->format('Y-m-d'),
            };
            return [
                'key' => $start,
                'period_start' => $start,
                'period_label' => $start,
                'semester_id' => $row->semester_id ?? null,
            ];
        };

        $ensureBucket = function (array $meta) use (&$buckets): string {
            $key = $meta['key'];
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'period_start' => $meta['period_start'],
                    'period_label' => $meta['period_label'],
                    'semester_id' => $meta['semester_id'],
                    'att_present' => 0,
                    'att_total' => 0,
                    'asn_earned' => 0.0,
                    'asn_max' => 0.0,
                    'qz_earned' => 0.0,
                    'qz_max' => 0.0,
                    'ex_earned' => 0.0,
                    'ex_max' => 0.0,
                ];
            }
            return $key;
        };

        foreach ($attendances as $a) {
            $meta = $resolveBucket($a);
            if (! $meta) {
                continue;
            }
            $k = $ensureBucket($meta);
            $buckets[$k]['att_total']++;
            if (strtolower((string) $a->status) === 'present') {
                $buckets[$k]['att_present']++;
            }
        }

        foreach ($assignments as $a) {
            $meta = $resolveBucket($a);
            if (! $meta) {
                continue;
            }
            $k = $ensureBucket($meta);
            $max = ((float) ($a->total_score ?? 0)) > 0 ? (float) $a->total_score : 100.0;
            $buckets[$k]['asn_earned'] += (float) $a->score;
            $buckets[$k]['asn_max'] += $max;
        }

        foreach ($quizzes as $q) {
            $meta = $resolveBucket($q);
            if (! $meta) {
                continue;
            }
            $k = $ensureBucket($meta);
            $max = ((float) ($q->total_score ?? 0)) > 0 ? (float) $q->total_score : 100.0;
            $buckets[$k]['qz_earned'] += (float) $q->score;
            $buckets[$k]['qz_max'] += $max;
        }

        foreach ($exams as $e) {
            $meta = $resolveBucket($e);
            if (! $meta) {
                continue;
            }
            $k = $ensureBucket($meta);
            $max = ((float) ($e->total_score ?? 0)) > 0 ? (float) $e->total_score : 100.0;
            $buckets[$k]['ex_earned'] += (float) $e->score;
            $buckets[$k]['ex_max'] += $max;
        }

        uasort($buckets, fn (array $a, array $b) => strcmp($a['period_start'], $b['period_start']));

        $analytics = [];
        $id = 1;
        foreach ($buckets as $d) {
            $attRate = $d['att_total'] > 0 ? ($d['att_present'] / $d['att_total']) * 100.0 : null;
            $asnAvg = $d['asn_max'] > 0 ? ($d['asn_earned'] / $d['asn_max']) * 100.0 : null;
            $qzAvg = $d['qz_max'] > 0 ? ($d['qz_earned'] / $d['qz_max']) * 100.0 : null;
            $exAvg = $d['ex_max'] > 0 ? ($d['ex_earned'] / $d['ex_max']) * 100.0 : null;

            $overall = $this->averageNonNull([$attRate, $asnAvg, $qzAvg, $exAvg]);

            $analytics[] = [
                'id' => $id++,
                'student_id' => $student->id,
                'semester_id' => $d['semester_id'],
                'period_type' => $period,
                'period_start' => $d['period_start'],
                'period_label' => $d['period_label'],
                'attendance_rate' => $attRate !== null ? round($attRate, 2) : null,
                'assignment_average' => $asnAvg !== null ? round($asnAvg, 2) : null,
                'quiz_average' => $qzAvg !== null ? round($qzAvg, 2) : null,
                'exam_average' => $exAvg !== null ? round($exAvg, 2) : null,
                'overall_performance' => $overall !== null ? round($overall, 2) : null,
            ];
        }

        return $analytics;
    }

    /**
     * Compute aggregate current analytics metrics across the requested scope without storing or faking missing values.
     *
     * @return array<string, float|null>
     */
    public function computeCurrentSummary(Student $student, ?int $semesterId = null, ?int $courseId = null): array
    {
        $attendances = $this->fetchAttendanceRows($student->id, $semesterId, $courseId);
        $attTotal = $attendances->count();
        $attPresent = $attendances->filter(fn ($r) => strtolower((string) $r->status) === 'present')->count();
        $attRate = $attTotal > 0 ? round(($attPresent / $attTotal) * 100.0, 2) : null;

        $assignments = $this->fetchDeduplicatedAssignmentRows($student->id, $semesterId, $courseId);
        $asnAvg = $this->weightedPercentage($assignments);

        $quizzes = $this->fetchDeduplicatedQuizRows($student->id, $semesterId, $courseId);
        $qzAvg = $this->weightedPercentage($quizzes);

        $exams = $this->fetchDeduplicatedExamRows($student->id, $semesterId, $courseId);
        $exAvg = $this->weightedPercentage($exams);

        $overall = $this->averageNonNull([$attRate, $asnAvg, $qzAvg, $exAvg]);

        return [
            'attendance_rate' => $attRate,
            'assignment_average' => $asnAvg,
            'quiz_average' => $qzAvg,
            'exam_average' => $exAvg,
            'overall_performance' => $overall !== null ? round($overall, 2) : null,
        ];
    }

    private function weightedPercentage($rows): ?float
    {
        $earned = 0.0;
        $maxSum = 0.0;
        foreach ($rows as $row) {
            if ($row->score === null) {
                continue;
            }
            $max = ((float) ($row->total_score ?? 0)) > 0 ? (float) $row->total_score : 100.0;
            $earned += (float) $row->score;
            $maxSum += $max;
        }
        return $maxSum > 0 ? round(($earned / $maxSum) * 100.0, 2) : null;
    }

    /**
     * @param array<int, float|null> $values
     */
    public function averageNonNull(array $values): ?float
    {
        $sum = 0.0;
        $count = 0;
        foreach ($values as $v) {
            if ($v !== null) {
                $sum += (float) $v;
                $count++;
            }
        }
        return $count > 0 ? round($sum / $count, 2) : null;
    }

    private function fetchAttendanceRows(int $studentId, ?int $semesterId, ?int $courseId)
    {
        $q = AttendanceDetail::where('attendance_details.student_id', $studentId)
            ->join('attendance', 'attendance_details.attendance_id', '=', 'attendance.id')
            ->select(
                'attendance.attendance_date as effective_date',
                'attendance.semester_id',
                'attendance_details.status'
            );
        if ($semesterId) {
            $q->where('attendance.semester_id', $semesterId);
        }
        if ($courseId) {
            $q->where('attendance.course_id', $courseId);
        }
        return $q->get();
    }

    private function fetchDeduplicatedAssignmentRows(int $studentId, ?int $semesterId, ?int $courseId)
    {
        $subQ = AssignmentSubmission::where('assignment_submissions.student_id', $studentId)
            ->whereNotNull('assignment_submissions.score')
            ->join('assignments', 'assignment_submissions.assignment_id', '=', 'assignments.id')
            ->selectRaw('assignment_submissions.assignment_id, COALESCE(assignments.due_date, assignment_submissions.submitted_at, assignment_submissions.created_at) as effective_date, assignments.semester_id, assignment_submissions.score, assignments.total_score')
            ->orderByDesc('assignment_submissions.submitted_at')
            ->orderByDesc('assignment_submissions.id');
        if ($semesterId) {
            $subQ->where('assignments.semester_id', $semesterId);
        }
        if ($courseId) {
            $subQ->where('assignments.course_id', $courseId);
        }
        $subs = $subQ->get()->unique('assignment_id');

        $seenIds = $subs->pluck('assignment_id')->all();

        $attQ = AssignmentAttempt::where('assignment_attempts.student_id', $studentId)
            ->whereNotNull('assignment_attempts.score')
            ->join('assignments', 'assignment_attempts.assignment_id', '=', 'assignments.id')
            ->selectRaw('assignment_attempts.assignment_id, COALESCE(assignments.due_date, assignment_attempts.submitted_at, assignment_attempts.created_at) as effective_date, assignments.semester_id, assignment_attempts.score, assignments.total_score')
            ->orderByDesc('assignment_attempts.attempt_number')
            ->orderByDesc('assignment_attempts.id');
        if (! empty($seenIds)) {
            $attQ->whereNotIn('assignment_attempts.assignment_id', $seenIds);
        }
        if ($semesterId) {
            $attQ->where('assignments.semester_id', $semesterId);
        }
        if ($courseId) {
            $attQ->where('assignments.course_id', $courseId);
        }
        $atts = $attQ->get()->unique('assignment_id');

        return $subs->concat($atts)->values();
    }

    private function fetchDeduplicatedQuizRows(int $studentId, ?int $semesterId, ?int $courseId)
    {
        $q = QuizAttempt::where('quiz_attempts.student_id', $studentId)
            ->where('quiz_attempts.is_reset', false)
            ->where('quiz_attempts.grading_status', '!=', 'pending_grading')
            ->whereIn('quiz_attempts.status', ['completed', 'graded', 'timed_out'])
            ->whereNotNull('quiz_attempts.score')
            ->join('quizzes', 'quiz_attempts.quiz_id', '=', 'quizzes.id')
            ->whereNull('quizzes.deleted_at')
            ->select(
                'quiz_attempts.id',
                'quiz_attempts.quiz_id',
                'quiz_attempts.attempt_number',
                'quiz_attempts.status',
                'quiz_attempts.grading_status',
                'quiz_attempts.is_reset',
                'quiz_attempts.submitted_at',
                'quiz_attempts.created_at',
                'quiz_attempts.score',
                'quizzes.start_at',
                'quizzes.semester_id',
                'quizzes.total_score',
                'quizzes.scoring_method'
            )
            ->orderByDesc('quiz_attempts.attempt_number')
            ->orderByDesc('quiz_attempts.id');
        if ($semesterId) {
            $q->where('quizzes.semester_id', $semesterId);
        }
        if ($courseId) {
            $q->where('quizzes.course_id', $courseId);
        }

        return $q->get()->groupBy('quiz_id')->map(function ($group) {
            $latest = $group->first();
            $method = strtolower((string) ($latest->scoring_method ?? 'highest'));
            $score = match ($method) {
                'latest' => (float) $latest->score,
                'average' => round((float) $group->avg('score'), 2),
                default => round((float) $group->max('score'), 2),
            };

            return (object) [
                'quiz_id' => $latest->quiz_id,
                'effective_date' => $latest->start_at ?? $latest->submitted_at ?? $latest->created_at,
                'semester_id' => $latest->semester_id,
                'score' => $score,
                'total_score' => $latest->total_score,
            ];
        })->values();
    }

    private function fetchDeduplicatedExamRows(int $studentId, ?int $semesterId, ?int $courseId)
    {
        $q = ExamResult::where('exam_results.student_id', $studentId)
            ->whereNotNull('exam_results.score')
            ->join('exams', 'exam_results.exam_id', '=', 'exams.id')
            ->selectRaw('exam_results.exam_id, COALESCE(exams.exam_date, exam_results.created_at) as effective_date, exams.semester_id, exam_results.score, exams.total_score')
            ->orderByDesc('exam_results.id');
        if ($semesterId) {
            $q->where('exams.semester_id', $semesterId);
        }
        if ($courseId) {
            $q->where('exams.course_id', $courseId);
        }
        return $q->get()->unique('exam_id')->values();
    }
}
