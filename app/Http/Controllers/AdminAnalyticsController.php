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
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdminAnalyticsController extends Controller
{
    public function enrollment(Request $request)
    {
        $academicYears = AcademicYear::orderBy('start_date', 'asc')->get();
        $series = [];

        foreach ($academicYears as $ay) {
            $totalStudents = CourseEnrollment::whereHas('semester', function($q) use ($ay) {
                $q->where('academic_year_id', $ay->id);
            })->distinct('student_id')->count('student_id');

            $activeLearners = \App\Models\AttendanceDetail::whereHas('attendance.semester', function($q) use ($ay) {
                $q->where('academic_year_id', $ay->id);
            })->where('status', 'present')->distinct('student_id')->count('student_id');

            $atRiskStudents = RiskAssessment::whereHas('semester', function($q) use ($ay) {
                $q->where('academic_year_id', $ay->id);
            })->whereIn('risk_level', ['high', 'medium'])->distinct('student_id')->count('student_id');

            $series[] = [
                'academic_year' => $ay->name,
                'total_students' => $totalStudents,
                'active_learners' => min($activeLearners, $totalStudents),
                'at_risk_students' => min($atRiskStudents, $totalStudents)
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'series' => $series
            ]
        ]);
    }

    public function performance(Request $request)
    {
        $semesters = Semester::orderBy('start_date', 'asc')->get();
        $series = [];

        foreach ($semesters as $sem) {
            $avgGpa = GpaRecord::where('semester_id', $sem->id)->avg('gpa') ?? 0;
            // Map 0-4 GPA scale to 0-100% scale for charting visual compatibility
            $avgGpaMapped = $avgGpa > 0 ? ($avgGpa / 4.0) * 100 : 0;

            $avgAssignment = AssignmentSubmission::join('assignments', 'assignment_submissions.assignment_id', '=', 'assignments.id')
                ->where('assignments.semester_id', $sem->id)
                ->whereNotNull('assignment_submissions.score')
                ->where('assignments.total_score', '>', 0)
                ->selectRaw('AVG((assignment_submissions.score / assignments.total_score) * 100) as avg_pct')
                ->value('avg_pct') ?? 0;

            $avgQuiz = QuizAttempt::join('quizzes', 'quiz_attempts.quiz_id', '=', 'quizzes.id')
                ->where('quizzes.semester_id', $sem->id)
                ->whereNotNull('quiz_attempts.score')
                ->where('quizzes.total_score', '>', 0)
                ->selectRaw('AVG((quiz_attempts.score / quizzes.total_score) * 100) as avg_pct')
                ->value('avg_pct') ?? 0;

            $avgExam = ExamResult::join('exams', 'exam_results.exam_id', '=', 'exams.id')
                ->where('exams.semester_id', $sem->id)
                ->whereNotNull('exam_results.score')
                ->where('exams.total_score', '>', 0)
                ->selectRaw('AVG((exam_results.score / exams.total_score) * 100) as avg_pct')
                ->value('avg_pct') ?? 0;

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
        $semesters = Semester::orderBy('start_date', 'asc')->get();
        $series = [];

        foreach ($semesters as $sem) {
            $agg = AttendanceDetail::join('attendance', 'attendance_details.attendance_id', '=', 'attendance.id')
                ->where('attendance.semester_id', $sem->id)
                ->selectRaw("
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
        $low = RiskAssessment::whereIn('id', function($query) { $query->select(DB::raw('MAX(id)'))->from('risk_assessments')->groupBy('student_id'); })->where('risk_level', 'low')->count();
        $medium = RiskAssessment::whereIn('id', function($query) { $query->select(DB::raw('MAX(id)'))->from('risk_assessments')->groupBy('student_id'); })->where('risk_level', 'medium')->count();
        $high = RiskAssessment::whereIn('id', function($query) { $query->select(DB::raw('MAX(id)'))->from('risk_assessments')->groupBy('student_id'); })->where('risk_level', 'high')->count();

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

        $predictions = \App\Models\Prediction::with(['student.user', 'course', 'semester'])
            ->when($semesterId, fn ($q) => $q->where('semester_id', $semesterId))
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
