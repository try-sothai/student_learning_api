<?php

namespace App\Http\Controllers;

use App\Models\QuizAttempt;
use Illuminate\Http\Request;

class QuizAttemptController extends Controller
{
    public function index(Request $request)
    {
        $query = QuizAttempt::with(['quiz.course', 'student.user']);

        $apiUser = auth('sanctum')->user() ?? $request->user();
        if ($apiUser && $apiUser->role === 'student' && $apiUser->student) {
            $query->where('student_id', $apiUser->student->id);
        } elseif ($request->has('student_id')) {
            $query->where('student_id', $request->student_id);
        }

        if ($request->has('quiz_id')) {
            $query->where('quiz_id', $request->quiz_id);
        }

        $attempts = $query->latest()->get();

        return response()->json([
            'message' => 'Quiz attempts retrieved successfully.',
            'data' => $attempts,
        ], 200);
    }

    public function store(Request $request)
    {
        if (! $request->filled('student_id') && $request->user()?->student) {
            $request->merge(['student_id' => $request->user()->student->id]);
        } elseif ($request->filled('student_id')) {
            $rawStudentId = $request->input('student_id');
            if (! \App\Models\Student::where('id', $rawStudentId)->exists()) {
                $resolved = \App\Models\Student::where('student_code', (string) $rawStudentId)
                    ->orWhere('user_id', $rawStudentId)
                    ->value('id') ?? $request->user()?->student?->id;
                if ($resolved) {
                    $request->merge(['student_id' => $resolved]);
                }
            }
        }

        $validated = $request->validate([
            'quiz_id' => 'required|integer|exists:quizzes,id',
            'student_id' => 'required|integer|exists:students,id',
            'started_at' => 'nullable|date',
        ]);

        $nextAttemptNumber = QuizAttempt::where('quiz_id', $validated['quiz_id'])
            ->where('student_id', $validated['student_id'])
            ->count() + 1;

        $attempt = QuizAttempt::create([
            'quiz_id' => $validated['quiz_id'],
            'student_id' => $validated['student_id'],
            'attempt_number' => $nextAttemptNumber,
            'started_at' => $validated['started_at'] ?? now(),
            'status' => 'in_progress',
        ]);

        return response()->json([
            'message' => 'Quiz attempt started successfully.',
            'data' => $attempt->load(['quiz', 'student']),
        ], 201);
    }

    public function show(QuizAttempt $quizAttempt)
    {
        return response()->json([
            'message' => 'Quiz attempt retrieved successfully.',
            'data' => $quizAttempt->load(['quiz.questions', 'student.user', 'answers.question']),
        ], 200);
    }

    public function update(Request $request, QuizAttempt $quizAttempt)
    {
        $validated = $request->validate([
            'score' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:in_progress,completed,timed_out',
            'submitted_at' => 'nullable|date',
        ]);

        $computedScore = $quizAttempt->score;
        if (array_key_exists('score', $validated) && $validated['score'] !== null) {
            $computedScore = $validated['score'];
        } else {
            $quizAttempt->load(['answers.question', 'quiz.questions']);
            if ($quizAttempt->answers->isNotEmpty()) {
                $earnedMarks = 0.0;
                foreach ($quizAttempt->answers as $ans) {
                    if ($ans->question) {
                        $isCorrect = strtoupper(trim((string) $ans->answer)) === strtoupper(trim((string) $ans->question->correct_answer));
                        $marksObtained = $isCorrect ? (float) $ans->question->marks : 0.0;
                        if ($ans->is_correct !== $isCorrect || (float) $ans->marks_obtained !== $marksObtained) {
                            $ans->update([
                                'is_correct' => $isCorrect,
                                'marks_obtained' => $marksObtained,
                            ]);
                        }
                        $earnedMarks += $marksObtained;
                    } else {
                        $earnedMarks += (float) $ans->marks_obtained;
                    }
                }
                $totalQuestionMarks = (float) ($quizAttempt->quiz?->questions?->sum('marks') ?? 0);
                $quizTotalScore = (float) ($quizAttempt->quiz?->total_score ?? 0);
                if ($totalQuestionMarks > 0 && $quizTotalScore > 0 && abs($totalQuestionMarks - $quizTotalScore) > 0.01) {
                    $computedScore = round(($earnedMarks / $totalQuestionMarks) * $quizTotalScore, 2);
                } else {
                    $computedScore = round($earnedMarks, 2);
                }
            } elseif (($validated['status'] ?? 'completed') === 'completed' && $computedScore === null) {
                $computedScore = 0.0;
            }
        }

        $quizAttempt->update([
            'score' => $computedScore,
            'status' => $validated['status'] ?? 'completed',
            'submitted_at' => $validated['submitted_at'] ?? now(),
        ]);

        return response()->json([
            'message' => 'Quiz attempt submitted/updated successfully.',
            'data' => $quizAttempt->fresh()->load(['quiz', 'student', 'answers']),
        ], 200);
    }

    public function destroy(QuizAttempt $quizAttempt)
    {
        $quizAttempt->delete();

        return response()->json([
            'message' => 'Quiz attempt deleted successfully.',
        ], 200);
    }
}
