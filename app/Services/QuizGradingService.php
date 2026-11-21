<?php

namespace App\Services;

use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;

/**
 * Grades quiz attempts server side. The client never sends a score.
 *
 *  - multiple_choice / true_false: exact (case-insensitive) match with the key.
 *  - short_answer: an exact match with the expected answer earns full marks; anything else
 *    is left ungraded (is_correct = null) for the teacher, because free text is subjective.
 */
class QuizGradingService
{
    public function grade(QuizAttempt $attempt): QuizAttempt
    {
        $attempt->loadMissing('quiz');
        $questions = QuizQuestion::where('quiz_id', $attempt->quiz_id)->get()->each->makeVisible('correct_answer');
        $answers = QuizAnswer::where('quiz_attempt_id', $attempt->id)->get()->keyBy('question_id');

        foreach ($questions as $question) {
            $answer = $answers->get($question->id);
            if (! $answer) {
                continue; // unanswered => no row, scores 0
            }

            $given = strtolower(trim((string) $answer->answer));
            $key = strtolower(trim((string) $question->correct_answer));

            if ($question->question_type === 'short_answer') {
                if ($key !== '' && $given !== '' && $given === $key) {
                    $answer->update(['is_correct' => true, 'marks_obtained' => $question->marks]);
                } elseif ($given === '') {
                    $answer->update(['is_correct' => false, 'marks_obtained' => 0]);
                } else {
                    $answer->update(['is_correct' => null, 'marks_obtained' => null]); // manual review
                }

                continue;
            }

            $correct = $given !== '' && $given === $key;
            $answer->update(['is_correct' => $correct, 'marks_obtained' => $correct ? $question->marks : 0]);
        }

        return $this->recalculate($attempt);
    }

    public function recalculate(QuizAttempt $attempt): QuizAttempt
    {
        $answers = QuizAnswer::where('quiz_attempt_id', $attempt->id)->get();
        $pending = $answers->contains(fn ($a) => $a->is_correct === null);

        $attempt->update([
            'score' => round((float) $answers->sum('marks_obtained'), 2),
            'status' => $pending ? 'submitted' : 'completed',
        ]);

        return $attempt->fresh();
    }
}
