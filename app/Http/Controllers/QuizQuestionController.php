<?php

namespace App\Http\Controllers;

use App\Models\QuizQuestion;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuizQuestionController extends Controller
{
    public function index(Request $request)
    {
        $query = QuizQuestion::query();

        if ($request->has('quiz_id')) {
            $query->where('quiz_id', $request->quiz_id);
        }

        $questions = $query->orderBy('question_order')->get();

        return response()->json([
            'message' => 'Quiz questions retrieved successfully.',
            'data' => $questions,
        ], 200);
    }

    public function store(Request $request)
    {
        $type = $request->input('question_type', 'multiple_choice');
        $validated = $request->validate([
            'quiz_id' => 'required|integer|exists:quizzes,id',
            'question_text' => 'required|string',
            'question_type' => 'nullable|string|in:multiple_choice,mcq,true_false,short_answer,essay',
            'marks' => 'required|numeric|min:0',
            'is_required' => 'nullable|boolean',
            'question_order' => 'nullable|integer|min:1',
            'option_a' => 'nullable|string',
            'option_b' => 'nullable|string',
            'option_c' => 'nullable|string',
            'option_d' => 'nullable|string',
            'correct_answer' => [Rule::requiredIf(! in_array($type, ['short_answer', 'essay'], true)), 'nullable', 'string'],
            'grading_guidance' => 'nullable|string',
        ]);

        $question = QuizQuestion::create([
            'quiz_id' => $validated['quiz_id'],
            'question_text' => $validated['question_text'],
            'question_type' => $validated['question_type'] ?? 'multiple_choice',
            'marks' => $validated['marks'],
            'is_required' => array_key_exists('is_required', $validated) ? (bool) $validated['is_required'] : true,
            'question_order' => $validated['question_order'] ?? ((int) QuizQuestion::where('quiz_id', $validated['quiz_id'])->max('question_order') + 1),
            'option_a' => $validated['option_a'] ?? null,
            'option_b' => $validated['option_b'] ?? null,
            'option_c' => $validated['option_c'] ?? null,
            'option_d' => $validated['option_d'] ?? null,
            'correct_answer' => $validated['correct_answer'] ?? null,
            'grading_guidance' => $validated['grading_guidance'] ?? ($validated['correct_answer'] ?? null),
        ]);

        return response()->json([
            'message' => 'Quiz question created successfully.',
            'data' => $question,
        ], 201);
    }

    public function show(QuizQuestion $quizQuestion)
    {
        return response()->json([
            'message' => 'Quiz question retrieved successfully.',
            'data' => $quizQuestion,
        ], 200);
    }

    public function update(Request $request, QuizQuestion $quizQuestion)
    {
        $type = $request->input('question_type', $quizQuestion->question_type);
        $validated = $request->validate([
            'question_text' => 'required|string',
            'question_type' => 'nullable|string|in:multiple_choice,mcq,true_false,short_answer,essay',
            'marks' => 'required|numeric|min:0',
            'is_required' => 'nullable|boolean',
            'question_order' => 'nullable|integer|min:1',
            'option_a' => 'nullable|string',
            'option_b' => 'nullable|string',
            'option_c' => 'nullable|string',
            'option_d' => 'nullable|string',
            'correct_answer' => [Rule::requiredIf(! in_array($type, ['short_answer', 'essay'], true)), 'nullable', 'string'],
            'grading_guidance' => 'nullable|string',
        ]);

        $quizQuestion->update($validated);

        return response()->json([
            'message' => 'Quiz question updated successfully.',
            'data' => $quizQuestion->fresh(),
        ], 200);
    }

    public function destroy(QuizQuestion $quizQuestion)
    {
        if ($quizQuestion->answers()->exists()) {
            $quizQuestion->delete();
        } else {
            $quizQuestion->forceDelete();
        }

        return response()->json([
            'message' => 'Quiz question deleted successfully.',
        ], 200);
    }
}
