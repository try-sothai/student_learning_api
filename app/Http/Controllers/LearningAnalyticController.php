<?php

namespace App\Http\Controllers;

use App\Models\LearningAnalytic;
use App\Models\AttendanceDetail;
use App\Models\AssignmentSubmission;
use App\Models\QuizAttempt;
use App\Models\ExamResult;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LearningAnalyticController extends Controller
{
    public function myAnalytics(Request $request)
    {
        $user = $request->user();
        $student = $user?->student;

        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $period = $request->query('period_type', 'weekly');
        $semesterId = $request->query('semester_id');

        // Attendance (table is 'attendance', date column is 'attendance_date')
        $attQuery = AttendanceDetail::where('attendance_details.student_id', $student->id)
            ->join('attendance', 'attendance_details.attendance_id', '=', 'attendance.id')
            ->select('attendance.attendance_date as date', 'attendance_details.status');
        if ($semesterId) $attQuery->where('attendance.semester_id', $semesterId);
        $attendances = $attQuery->get();

        // Assignments (scored submissions)
        $asnQuery = AssignmentSubmission::where('assignment_submissions.student_id', $student->id)
            ->whereNotNull('assignment_submissions.score')
            ->join('assignments', 'assignment_submissions.assignment_id', '=', 'assignments.id')
            ->select('assignments.due_date as date', 'assignment_submissions.score', 'assignments.total_score');
        if ($semesterId) $asnQuery->where('assignments.semester_id', $semesterId);
        $assignments = $asnQuery->get();

        // Quizzes (scored attempts)
        $qzQuery = QuizAttempt::where('quiz_attempts.student_id', $student->id)
            ->whereNotNull('quiz_attempts.score')
            ->join('quizzes', 'quiz_attempts.quiz_id', '=', 'quizzes.id')
            ->select('quizzes.start_at as date', 'quiz_attempts.score', 'quizzes.total_score');
        if ($semesterId) $qzQuery->where('quizzes.semester_id', $semesterId);
        $quizzes = $qzQuery->get();

        // Exams (scored exam results)
        $exQuery = ExamResult::where('exam_results.student_id', $student->id)
            ->whereNotNull('exam_results.score')
            ->join('exams', 'exam_results.exam_id', '=', 'exams.id')
            ->select('exams.exam_date as date', 'exam_results.score', 'exams.total_score');
        if ($semesterId) $exQuery->where('exams.semester_id', $semesterId);
        $exams = $exQuery->get();

        $periodsData = [];

        $groupFn = function($dateStr) use ($period) {
            if (!$dateStr) return null;
            $d = Carbon::parse($dateStr);
            if ($period === 'weekly') return $d->startOfWeek()->format('Y-m-d');
            if ($period === 'monthly') return $d->startOfMonth()->format('Y-m-d');
            if ($period === 'yearly') return $d->startOfYear()->format('Y-m-d');
            return $d->startOfMonth()->format('Y-m-d');
        };

        foreach ($attendances as $a) {
            $p = $groupFn($a->date);
            if (!$p) continue;
            if (!isset($periodsData[$p])) $periodsData[$p] = $this->initPeriod($p);
            $periodsData[$p]['att_total']++;
            if ($a->status === 'present') $periodsData[$p]['att_present']++;
        }

        foreach ($assignments as $a) {
            $p = $groupFn($a->date);
            if (!$p) continue;
            if (!isset($periodsData[$p])) $periodsData[$p] = $this->initPeriod($p);
            $periodsData[$p]['asn_earned'] += (float)$a->score;
            $periodsData[$p]['asn_max'] += (float)$a->total_score;
        }

        foreach ($quizzes as $a) {
            $p = $groupFn($a->date);
            if (!$p) continue;
            if (!isset($periodsData[$p])) $periodsData[$p] = $this->initPeriod($p);
            $periodsData[$p]['qz_earned'] += (float)$a->score;
            $periodsData[$p]['qz_max'] += (float)$a->total_score;
        }

        foreach ($exams as $a) {
            $p = $groupFn($a->date);
            if (!$p) continue;
            if (!isset($periodsData[$p])) $periodsData[$p] = $this->initPeriod($p);
            $periodsData[$p]['ex_earned'] += (float)$a->score;
            $periodsData[$p]['ex_max'] += (float)$a->total_score;
        }

        ksort($periodsData);
        $analytics = [];
        $id = 1;
        foreach ($periodsData as $p => $d) {
            $attRate = $d['att_total'] > 0 ? ($d['att_present'] / $d['att_total']) * 100 : null;
            $asnAvg = $d['asn_max'] > 0 ? ($d['asn_earned'] / $d['asn_max']) * 100 : null;
            $qzAvg = $d['qz_max'] > 0 ? ($d['qz_earned'] / $d['qz_max']) * 100 : null;
            $exAvg = $d['ex_max'] > 0 ? ($d['ex_earned'] / $d['ex_max']) * 100 : null;

            $overall = 0; $cnt = 0;
            if ($attRate !== null) { $overall += $attRate; $cnt++; }
            if ($asnAvg !== null) { $overall += $asnAvg; $cnt++; }
            if ($qzAvg !== null) { $overall += $qzAvg; $cnt++; }
            if ($exAvg !== null) { $overall += $exAvg; $cnt++; }

            $analytics[] = [
                'id' => $id++,
                'student_id' => $student->id,
                'period_type' => $period,
                'period_start' => $p,
                'attendance_rate' => $attRate !== null ? round($attRate, 2) : null,
                'assignment_average' => $asnAvg !== null ? round($asnAvg, 2) : null,
                'quiz_average' => $qzAvg !== null ? round($qzAvg, 2) : null,
                'exam_average' => $exAvg !== null ? round($exAvg, 2) : null,
                'overall_performance' => $cnt > 0 ? round($overall / $cnt, 2) : null
            ];
        }

        if (empty($analytics)) {
            $storedQuery = LearningAnalytic::where('student_id', $student->id);
            if ($semesterId) {
                $storedQuery->where('semester_id', $semesterId);
            }
            $stored = $storedQuery->orderBy('period_start')->get();
            foreach ($stored as $row) {
                $analytics[] = [
                    'id' => $row->id,
                    'student_id' => $row->student_id,
                    'period_type' => $row->period_type ?? $period,
                    'period_start' => $row->period_start ? Carbon::parse($row->period_start)->format('Y-m-d') : null,
                    'attendance_rate' => $row->attendance_rate !== null ? (float) $row->attendance_rate : null,
                    'assignment_average' => $row->assignment_average !== null ? (float) $row->assignment_average : null,
                    'quiz_average' => $row->quiz_average !== null ? (float) $row->quiz_average : null,
                    'exam_average' => $row->exam_average !== null ? (float) $row->exam_average : null,
                    'overall_performance' => $row->overall_performance !== null ? (float) $row->overall_performance : null,
                ];
            }
        }

        return response()->json([
            'message' => 'Student analytics retrieved successfully.',
            'data' => $analytics,
        ], 200);
    }

    private function initPeriod($p) {
        return ['att_present'=>0, 'att_total'=>0, 'asn_earned'=>0, 'asn_max'=>0, 'qz_earned'=>0, 'qz_max'=>0, 'ex_earned'=>0, 'ex_max'=>0];
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = LearningAnalytic::with(['student.user', 'semester']);

        if ($user && $user->role === 'student') {
            if (!$user->student) {
                return response()->json(['message' => 'Student profile not found.'], 404);
            }
            $query->where('student_id', $user->student->id);
        } elseif ($request->has('student_id')) {
            $query->where('student_id', $request->student_id);
        }

        if ($request->has('semester_id')) {
            $query->where('semester_id', $request->semester_id);
        }

        $analytics = $query->latest('calculated_at')->get();

        return response()->json([
            'message' => 'Learning analytics retrieved successfully.',
            'data' => $analytics,
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'semester_id' => 'nullable|integer|exists:semesters,id',
            'period_type' => 'nullable|string|in:weekly,monthly,semester,yearly',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'attendance_rate' => 'nullable|numeric|min:0|max:100',
            'assignment_average' => 'nullable|numeric|min:0|max:100',
            'quiz_average' => 'nullable|numeric|min:0|max:100',
            'exam_average' => 'nullable|numeric|min:0|max:100',
            'overall_performance' => 'nullable|numeric|min:0|max:100',
            'performance_change' => 'nullable|numeric',
            'learning_trend' => 'nullable|string|in:improving,stable,declining',
            'strengths' => 'nullable|array',
            'weaknesses' => 'nullable|array',
            'analytics_summary' => 'nullable|string',
        ]);

        $analytic = LearningAnalytic::create([
            'student_id' => $validated['student_id'],
            'semester_id' => $validated['semester_id'] ?? null,
            'period_type' => $validated['period_type'] ?? 'semester',
            'period_start' => $validated['period_start'],
            'period_end' => $validated['period_end'],
            'attendance_rate' => $validated['attendance_rate'] ?? null,
            'assignment_average' => $validated['assignment_average'] ?? null,
            'quiz_average' => $validated['quiz_average'] ?? null,
            'exam_average' => $validated['exam_average'] ?? null,
            'overall_performance' => $validated['overall_performance'] ?? null,
            'performance_change' => $validated['performance_change'] ?? 0.0,
            'learning_trend' => $validated['learning_trend'] ?? 'stable',
            'strengths' => isset($validated['strengths']) ? json_encode($validated['strengths']) : null,
            'weaknesses' => isset($validated['weaknesses']) ? json_encode($validated['weaknesses']) : null,
            'analytics_summary' => $validated['analytics_summary'] ?? null,
            'calculated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Learning analytic record created successfully.',
            'data' => $analytic->load(['student', 'semester']),
        ], 201);
    }

    public function show(LearningAnalytic $learningAnalytic)
    {
        return response()->json([
            'message' => 'Learning analytic retrieved successfully.',
            'data' => $learningAnalytic->load(['student.user', 'semester']),
        ], 200);
    }

    public function update(Request $request, LearningAnalytic $learningAnalytic)
    {
        $validated = $request->validate([
            'attendance_rate' => 'nullable|numeric|min:0|max:100',
            'assignment_average' => 'nullable|numeric|min:0|max:100',
            'quiz_average' => 'nullable|numeric|min:0|max:100',
            'exam_average' => 'nullable|numeric|min:0|max:100',
            'overall_performance' => 'nullable|numeric|min:0|max:100',
            'learning_trend' => 'nullable|string|in:improving,stable,declining',
            'analytics_summary' => 'nullable|string',
        ]);

        $learningAnalytic->update($validated);

        return response()->json([
            'message' => 'Learning analytic updated successfully.',
            'data' => $learningAnalytic->fresh()->load(['student', 'semester']),
        ], 200);
    }

    public function destroy(LearningAnalytic $learningAnalytic)
    {
        $learningAnalytic->delete();

        return response()->json([
            'message' => 'Learning analytic deleted successfully.',
        ], 200);
    }
}
