<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AcademicYear;
use App\Models\Semester;
use App\Models\Student;
use App\Models\CourseEnrollment;
use App\Models\RiskAssessment;
use App\Models\GpaRecord;
use App\Models\AssignmentSubmission;
use App\Models\QuizAttempt;
use App\Models\ExamResult;
use App\Models\AttendanceDetail;
use App\Support\YearLevel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdminAnalyticsController extends Controller
{
    public function enrollment(Request $request)
    {
        $ayFilter = $request->query('academic_year_id');
        $ayId = ($ayFilter && strtolower((string) $ayFilter) !== 'all' && is_numeric($ayFilter))
            ? (int) $ayFilter
            : null;
        $academicYears = AcademicYear::when($ayId, fn ($q) => $q->where('id', $ayId))
            ->orderBy('start_date', 'asc')
            ->get();
        $yearLevelFilter = $request->query('year_level');
        $ylParsed = ($yearLevelFilter && strtolower((string) $yearLevelFilter) !== 'all')
            ? YearLevel::parse($yearLevelFilter)
            : null;
        $series = [];

        foreach ($academicYears as $ay) {
            $enrollQuery = CourseEnrollment::whereHas('semester', function ($q) use ($ay) {
                $q->where('academic_year_id', $ay->id);
            });
            $attQuery = \App\Models\AttendanceDetail::whereHas('attendance.semester', function ($q) use ($ay) {
                $q->where('academic_year_id', $ay->id);
            })->where('status', 'present');
            $riskQuery = RiskAssessment::whereHas('semester', function ($q) use ($ay) {
                $q->where('academic_year_id', $ay->id);
            })->whereIn('risk_level', ['high', 'medium']);

            if ($ylParsed !== null) {
                $enrollQuery->whereHas('student', fn ($sq) => $sq->forYearLevel($ylParsed));
                $attQuery->whereHas('student', fn ($sq) => $sq->forYearLevel($ylParsed));
                $riskQuery->whereHas('student', fn ($sq) => $sq->forYearLevel($ylParsed));
            } elseif ($yearLevelFilter && strtolower((string) $yearLevelFilter) !== 'all') {
                $enrollQuery->whereRaw('1 = 0');
                $attQuery->whereRaw('1 = 0');
                $riskQuery->whereRaw('1 = 0');
            }

            $totalStudents = $enrollQuery->distinct('student_id')->count('student_id');
            $activeLearners = $attQuery->distinct('student_id')->count('student_id');
            $atRiskStudents = $riskQuery->distinct('student_id')->count('student_id');

            $series[] = [
                'academic_year' => $ay->name,
                'total_students' => $totalStudents,
                'active_learners' => min($activeLearners, $totalStudents),
                'at_risk_students' => min($atRiskStudents, $totalStudents)
            ];
        }

        $allStudents = Student::get(['id', 'year_level']);
        $ylCounts = array_fill(1, YearLevel::maxLevel(), 0);
        $unassigned = 0;
        foreach ($allStudents as $st) {
            $yl = YearLevel::parse($st->year_level);
            if ($yl !== null && isset($ylCounts[$yl])) {
                $ylCounts[$yl]++;
            } else {
                $unassigned++;
            }
        }
        $yearLevelBreakdown = array_map(fn (array $opt) => [
            'year_level' => $opt['number'],
            'code' => $opt['code'],
            'name' => $opt['name'],
            'label' => $opt['label'],
            'students_count' => $ylCounts[$opt['number']] ?? 0,
        ], YearLevel::options());

        return response()->json([
            'success' => true,
            'data' => [
                'series' => $series,
                'year_level_breakdown' => $yearLevelBreakdown,
                'unassigned_year_level_students' => $unassigned,
            ]
        ]);
    }

    public function performance(Request $request)
    {
        $ayFilter = $request->query('academic_year_id');
        $ayId = ($ayFilter && strtolower((string) $ayFilter) !== 'all' && is_numeric($ayFilter))
            ? (int) $ayFilter
            : null;
        $semesters = Semester::when($ayId, fn ($q) => $q->where('academic_year_id', $ayId))
            ->orderBy('start_date', 'asc')
            ->get();
        $yearLevelFilter = $request->query('year_level');
        $ylParsed = ($yearLevelFilter && strtolower((string) $yearLevelFilter) !== 'all')
            ? YearLevel::parse($yearLevelFilter)
            : null;
        $invalidYl = ($yearLevelFilter && strtolower((string) $yearLevelFilter) !== 'all' && $ylParsed === null);
        $series = [];

        foreach ($semesters as $sem) {
            if ($invalidYl) {
                $avgGpa = 0;
                $avgAssignment = 0;
                $avgQuiz = 0;
                $avgExam = 0;
            } else {
                $gpaQ = GpaRecord::where('semester_id', $sem->id);
                if ($ylParsed !== null) {
                    $gpaQ->whereHas('student', fn ($sq) => $sq->forYearLevel($ylParsed));
                }
                $avgGpa = $gpaQ->avg('gpa') ?? 0;

                $studentIdsScope = $ylParsed !== null ? Student::forYearLevel($ylParsed)->select('id') : null;

                $assignQ = AssignmentSubmission::join('assignments', 'assignment_submissions.assignment_id', '=', 'assignments.id')
                    ->where('assignments.semester_id', $sem->id)
                    ->where('assignment_submissions.is_latest', true)
                    ->whereNotNull('assignment_submissions.score')
                    ->where('assignments.total_score', '>', 0);
                if ($studentIdsScope) {
                    $assignQ->whereIn('assignment_submissions.student_id', $studentIdsScope);
                }
                $avgAssignment = $assignQ->selectRaw('AVG((assignment_submissions.score / assignments.total_score) * 100) as avg_pct')
                    ->value('avg_pct') ?? 0;

                $quizQ = QuizAttempt::join('quizzes', 'quiz_attempts.quiz_id', '=', 'quizzes.id')
                    ->where('quizzes.semester_id', $sem->id)
                    ->whereNotNull('quiz_attempts.score')
                    ->where('quizzes.total_score', '>', 0);
                if ($studentIdsScope) {
                    $quizQ->whereIn('quiz_attempts.student_id', $studentIdsScope);
                }
                $avgQuiz = $quizQ->selectRaw('AVG((quiz_attempts.score / quizzes.total_score) * 100) as avg_pct')
                    ->value('avg_pct') ?? 0;

                $examQ = ExamResult::join('exams', 'exam_results.exam_id', '=', 'exams.id')
                    ->where('exams.semester_id', $sem->id)
                    ->whereNotNull('exam_results.score')
                    ->where('exams.total_score', '>', 0);
                if ($studentIdsScope) {
                    $examQ->whereIn('exam_results.student_id', $studentIdsScope);
                }
                $avgExam = $examQ->selectRaw('AVG((exam_results.score / exams.total_score) * 100) as avg_pct')
                    ->value('avg_pct') ?? 0;
            }
            // Map 0-4 GPA scale to 0-100% scale for charting visual compatibility
            $avgGpaMapped = $avgGpa > 0 ? ($avgGpa / 4.0) * 100 : 0;

            $series[] = [
                'semester' => $sem->name,
                'avg_gpa' => round($avgGpaMapped, 2),
                'actual_gpa' => round($avgGpa, 2),
                'avg_assignment' => round($avgAssignment, 2),
                'avg_quiz' => round($avgQuiz, 2),
                'avg_exam' => round($avgExam, 2)
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'series' => $series
            ]
        ]);
    }

    public function attendance(Request $request)
    {
        $ayFilter = $request->query('academic_year_id');
        $ayId = ($ayFilter && strtolower((string) $ayFilter) !== 'all' && is_numeric($ayFilter))
            ? (int) $ayFilter
            : null;
        $semesters = Semester::when($ayId, fn ($q) => $q->where('academic_year_id', $ayId))
            ->orderBy('start_date', 'asc')
            ->get();
        $yearLevelFilter = $request->query('year_level');
        $ylParsed = ($yearLevelFilter && strtolower((string) $yearLevelFilter) !== 'all')
            ? YearLevel::parse($yearLevelFilter)
            : null;
        $invalidYl = ($yearLevelFilter && strtolower((string) $yearLevelFilter) !== 'all' && $ylParsed === null);
        $series = [];

        foreach ($semesters as $sem) {
            if ($invalidYl) {
                $total = $present = $absent = $late = 0;
            } else {
                $aggQ = AttendanceDetail::join('attendance', 'attendance_details.attendance_id', '=', 'attendance.id')
                    ->where('attendance.semester_id', $sem->id);
                if ($ylParsed !== null) {
                    $aggQ->whereIn('attendance_details.student_id', Student::forYearLevel($ylParsed)->select('id'));
                }
                $agg = $aggQ->selectRaw("
                        COUNT(*) as total,
                        SUM(CASE WHEN attendance_details.status = 'present' THEN 1 ELSE 0 END) as present_count,
                        SUM(CASE WHEN attendance_details.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                        SUM(CASE WHEN attendance_details.status = 'late' THEN 1 ELSE 0 END) as late_count
                    ")
                    ->first();

                $total = (int) ($agg->total ?? 0);
                $present = (int) ($agg->present_count ?? 0);
                $absent = (int) ($agg->absent_count ?? 0);
                $late = (int) ($agg->late_count ?? 0);
            }

            $rate = $total > 0 ? round((($present + $late) / $total) * 100, 2) : 0;

            $series[] = [
                'semester' => $sem->name,
                'rate' => $rate,
                'present' => $present,
                'absent' => $absent,
                'late' => $late
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'series' => $series
            ]
        ]);
    }

    public function studentRisk(Request $request)
    {
        $ayFilter = $request->query('academic_year_id');
        $ayId = ($ayFilter && strtolower((string) $ayFilter) !== 'all' && is_numeric($ayFilter))
            ? (int) $ayFilter
            : null;
        $yearLevelFilter = $request->query('year_level');
        $ylParsed = ($yearLevelFilter && strtolower((string) $yearLevelFilter) !== 'all')
            ? YearLevel::parse($yearLevelFilter)
            : null;
        $invalidYl = ($yearLevelFilter && strtolower((string) $yearLevelFilter) !== 'all' && $ylParsed === null);

        $latestSub = function ($query) use ($ayId) {
            $query->select(DB::raw('MAX(id)'))->from('risk_assessments');
            if ($ayId) {
                $query->whereIn('semester_id', Semester::where('academic_year_id', $ayId)->select('id'));
            }
            $query->groupBy('student_id');
        };
        $base = RiskAssessment::whereIn('id', $latestSub);
        if ($invalidYl) {
            $base->whereRaw('1 = 0');
        } elseif ($ylParsed !== null) {
            $base->whereHas('student', fn ($sq) => $sq->forYearLevel($ylParsed));
        }

        $low = (clone $base)->where('risk_level', 'low')->count();
        $medium = (clone $base)->where('risk_level', 'medium')->count();
        $high = (clone $base)->where('risk_level', 'high')->count();

        $total = $low + $medium + $high;

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'low' => $low,
                    'medium' => $medium,
                    'high' => $high,
                    'total' => $total,
                    'low_percentage' => $total > 0 ? round(($low / $total) * 100, 2) : 0,
                    'medium_percentage' => $total > 0 ? round(($medium / $total) * 100, 2) : 0,
                    'high_percentage' => $total > 0 ? round(($high / $total) * 100, 2) : 0
                ]
            ]
        ]);
    }

    public function modelEvaluation(Request $request)
    {
        $semesterId = $request->query('semester_id');
        $yearLevelFilter = $request->query('year_level');

        $predictionsQuery = \App\Models\Prediction::with(['student.user', 'course', 'semester'])
            ->when($semesterId, fn ($q) => $q->where('semester_id', $semesterId));
        if ($yearLevelFilter && strtolower((string) $yearLevelFilter) !== 'all') {
            $predictionsQuery->whereHas('student', fn ($sq) => $sq->forYearLevel($yearLevelFilter));
        }
        $predictions = $predictionsQuery
            ->orderByDesc('prediction_date')
            ->get();

        if ($predictions->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No prediction records available for model evaluation.',
                'data' => [
                    'has_data' => false,
                    'total_predictions' => 0,
                    'evaluated_count' => 0,
                    'mae' => null,
                    'rmse' => null,
                    'r2' => null,
                    'actual_scores' => [],
                    'predicted_scores' => [],
                    'evaluation_labels' => [],
                    'recent_evaluations' => [],
                ],
            ]);
        }

        // Match predictions against actual grades for the same student + semester (+ course if specified)
        $grades = \App\Models\Grade::query()
            ->whereIn('student_id', $predictions->pluck('student_id')->unique())
            ->get()
            ->groupBy(fn ($g) => $g->student_id . ':' . $g->semester_id . ':' . ($g->course_id ?? ''));

        $pairs = [];
        $actualScores = [];
        $predictedScores = [];
        $labels = [];

        foreach ($predictions as $p) {
            $key = $p->student_id . ':' . $p->semester_id . ':' . ($p->course_id ?? '');
            $matched = $grades->get($key)?->first();
            if (! $matched || $matched->total_score === null) {
                continue;
            }
            $actual = (float) $matched->total_score;
            $pred = (float) $p->predicted_score;
            $pairs[] = [
                'actual' => $actual,
                'predicted' => $pred,
                'date' => (string) $p->prediction_date,
                'model_version' => $p->model_version ?? 'v1.0.0',
            ];
            if (count($actualScores) < 12) {
                $actualScores[] = round($actual, 2);
                $predictedScores[] = round($pred, 2);
                $labels[] = $p->student?->student_code ?? ('Stu ' . $p->student_id);
            }
        }

        $n = count($pairs);
        if ($n === 0) {
            return response()->json([
                'success' => true,
                'message' => 'Predictions exist, but no matching actual grades were found for evaluation.',
                'data' => [
                    'has_data' => false,
                    'total_predictions' => $predictions->count(),
                    'evaluated_count' => 0,
                    'mae' => null,
                    'rmse' => null,
                    'r2' => null,
                    'actual_scores' => [],
                    'predicted_scores' => [],
                    'evaluation_labels' => [],
                    'recent_evaluations' => [],
                ],
            ]);
        }

        $sumAbs = 0.0;
        $sumSq = 0.0;
        $sumActual = 0.0;
        foreach ($pairs as $pair) {
            $err = $pair['actual'] - $pair['predicted'];
            $sumAbs += abs($err);
            $sumSq += $err * $err;
            $sumActual += $pair['actual'];
        }
        $mae = round($sumAbs / $n, 2);
        $rmse = round(sqrt($sumSq / $n), 2);
        $meanActual = $sumActual / $n;
        $ssTot = 0.0;
        foreach ($pairs as $pair) {
            $diff = $pair['actual'] - $meanActual;
            $ssTot += $diff * $diff;
        }
        $r2 = $ssTot > 0 ? round(1.0 - ($sumSq / $ssTot), 2) : null;

        $recentEvaluations = collect($pairs)
            ->groupBy(fn ($item) => $item['date'] . '|' . $item['model_version'])
            ->map(function ($group, $groupKey) {
                [$date, $version] = explode('|', $groupKey, 2);
                $cnt = $group->count();
                $gAbs = $group->sum(fn ($i) => abs($i['actual'] - $i['predicted']));
                $gSq = $group->sum(fn ($i) => pow($i['actual'] - $i['predicted'], 2));
                return [
                    'date' => $date,
                    'title' => 'Model ' . $version . ' (' . $cnt . ' samples)',
                    'mae' => round($gAbs / $cnt, 2),
                    'rmse' => round(sqrt($gSq / $cnt), 2),
                ];
            })
            ->values()
            ->take(5)
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Model evaluation metrics calculated from verified predictions and grades.',
            'data' => [
                'has_data' => true,
                'total_predictions' => $predictions->count(),
                'evaluated_count' => $n,
                'mae' => $mae,
                'rmse' => $rmse,
                'r2' => $r2,
                'actual_scores' => $actualScores,
                'predicted_scores' => $predictedScores,
                'evaluation_labels' => $labels,
                'recent_evaluations' => $recentEvaluations,
            ],
        ]);
    }
}
