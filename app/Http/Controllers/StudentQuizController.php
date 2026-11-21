<?php

namespace App\Http\Controllers;

use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\Student;
use App\Services\AssessmentAccessService;
use App\Services\QuizGradingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Student quiz flow. One quiz = one attempt row = N answer rows.
 * Identity comes from the auth token only; ids in the URL/body are verified, never trusted.
 * Correct answers are only ever returned by review(), once the quiz is closed.
 */
class StudentQuizController extends Controller
{
    private const GRACE_SECONDS = 60;

    public function __construct(
        protected AssessmentAccessService $access,
        protected QuizGradingService $grading,
    ) {}

    protected function student(Request $request): Student
    {
        $student = $request->user()?->student;
        if (! $student) {
            abort(response()->json(['success' => false, 'message' => 'Student profile not found.'], 404));
        }

        return $student;
    }

    /** Quiz that this student is allowed to open, or a 403/404 response. */
    protected function accessibleQuiz(Student $student, int $quizId): Quiz|JsonResponse
    {
        $quiz = Quiz::find($quizId);
        if (! $quiz || ! $this->access->isVisibleStatus('quiz', $quiz->status) || ! $this->access->studentCanAccess($student, $quiz)) {
            // Same answer for "does not exist" and "not yours": do not leak existence.
            return response()->json(['success' => false, 'message' => 'Quiz not found or you are not enrolled in this course.'], 404);
        }

        return $quiz;
    }

    protected function ownedAttempt(Student $student, int $attemptId): QuizAttempt|JsonResponse
    {
        $attempt = QuizAttempt::with('quiz')->find($attemptId);
        if (! $attempt || (int) $attempt->student_id !== (int) $student->id) {
            return response()->json(['success' => false, 'message' => 'Attempt not found.'], 404);
        }

        return $attempt;
    }

    protected function questionsForStudent(Quiz $quiz): array
    {
        return QuizQuestion::where('quiz_id', $quiz->id)->orderBy('question_order')->get()->map(function (QuizQuestion $q) {
            $options = [];
            foreach (['A' => 'option_a', 'B' => 'option_b', 'C' => 'option_c', 'D' => 'option_d'] as $key => $col) {
                if ($q->{$col} !== null && trim((string) $q->{$col}) !== '') {
                    $options[] = ['key' => $key, 'text' => $q->{$col}];
                }
            }
            if ($q->question_type === 'true_false') {
                $options = [['key' => 'true', 'text' => 'True'], ['key' => 'false', 'text' => 'False']];
            }

            return [
                'id' => $q->id,
                'number' => $q->question_order,
                'question_text' => $q->question_text,
                'question_type' => $q->question_type,
                'marks' => (float) $q->marks,
                'options' => $options,
            ];
        })->values()->all();
    }

    protected function windowState(Quiz $quiz): string
    {
        $now = now();
        if ($quiz->status === 'closed' || ($quiz->end_at && $now->gt($quiz->end_at))) {
            return 'closed';
        }
        if ($quiz->start_at && $now->lt($quiz->start_at)) {
            return 'upcoming';
        }

        return 'open';
    }

    protected function expiresAt(QuizAttempt $attempt): Carbon
    {
        $quiz = $attempt->quiz;
        $limit = $attempt->started_at->copy()->addMinutes((int) ($quiz->duration_minutes ?: 30));

        return ($quiz->end_at && $quiz->end_at->lt($limit)) ? $quiz->end_at->copy() : $limit;
    }

    /** Result visible to the student, honouring grading/release rules. */
    protected function resultPayload(QuizAttempt $attempt, Quiz $quiz): array
    {
        $graded = $attempt->status === 'completed';
        $reviewAllowed = in_array($attempt->status, ['completed', 'submitted'], true) && $this->windowState($quiz) === 'closed';

        return [
            'id' => $attempt->id,
            'status' => $attempt->status,
            'attempt_number' => $attempt->attempt_number,
            'started_at' => $attempt->started_at,
            'submitted_at' => $attempt->submitted_at,
            'score' => $graded ? $attempt->score : null,
            'total_marks' => (float) QuizQuestion::where('quiz_id', $quiz->id)->sum('marks') ?: (float) $quiz->total_score,
            'score_released' => $graded,
            'awaiting_manual_grading' => $attempt->status === 'submitted',
            'review_allowed' => $reviewAllowed,
        ];
    }

    /** GET /api/student/quizzes/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $student = $this->student($request);
        $quiz = $this->accessibleQuiz($student, $id);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }
        $quiz->load(['courseOffering.course', 'courseOffering.classRoom', 'courseOffering.semester.academicYear']);

        $attempts = QuizAttempt::where('quiz_id', $quiz->id)->where('student_id', $student->id)->orderByDesc('attempt_number')->get();
        $latest = $attempts->first();
        $state = $this->windowState($quiz);
        $inProgress = $attempts->firstWhere('status', 'in_progress');
        $hasSubmitted = $attempts->contains(fn ($a) => $a->status !== 'in_progress');
        $questionCount = QuizQuestion::where('quiz_id', $quiz->id)->count();

        return response()->json(['success' => true, 'data' => [
            'id' => $quiz->id,
            'title' => $quiz->title,
            'instructions' => $quiz->description,
            'course_name' => $quiz->courseOffering?->course?->name,
            'course_code' => $quiz->courseOffering?->course?->code,
            'class_name' => $quiz->courseOffering?->classRoom?->name,
            'semester_name' => $quiz->courseOffering?->semester?->name,
            'academic_year_name' => $quiz->courseOffering?->semester?->academicYear?->name,
            'status' => $quiz->status,
            'window_state' => $state,
            'total_questions' => $questionCount,
            'total_marks' => (float) QuizQuestion::where('quiz_id', $quiz->id)->sum('marks') ?: (float) $quiz->total_score,
            'duration_minutes' => $quiz->duration_minutes,
            'start_at' => $quiz->start_at,
            'end_at' => $quiz->end_at,
            'can_start' => $state === 'open' && $questionCount > 0 && ! $hasSubmitted,
            'can_continue' => (bool) $inProgress && $state !== 'closed',
            'attempt' => $latest ? $this->resultPayload($latest, $quiz) : null,
        ]]);
    }

    /** POST /api/student/quizzes/{id}/start */
    public function start(Request $request, int $id): JsonResponse
    {
        $student = $this->student($request);
        $quiz = $this->accessibleQuiz($student, $id);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }

        $state = $this->windowState($quiz);
        if ($state === 'upcoming') {
            return response()->json(['success' => false, 'message' => 'This quiz has not opened yet.'], 422);
        }
        if ($state === 'closed') {
            return response()->json(['success' => false, 'message' => 'This quiz is closed.'], 422);
        }
        if (! QuizQuestion::where('quiz_id', $quiz->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'This quiz has no questions yet.'], 422);
        }

        $attempt = DB::transaction(function () use ($quiz, $student) {
            $existing = QuizAttempt::where('quiz_id', $quiz->id)->where('student_id', $student->id)->lockForUpdate()->get();
            if ($existing->contains(fn ($a) => $a->status !== 'in_progress')) {
                return null;
            }

            return $existing->firstWhere('status', 'in_progress') ?? QuizAttempt::create([
                'quiz_id' => $quiz->id,
                'student_id' => $student->id,
                'attempt_number' => $existing->count() + 1,
                'started_at' => now(),
                'status' => 'in_progress',
            ]);
        });

        if (! $attempt) {
            return response()->json(['success' => false, 'message' => 'You have already submitted this quiz.'], 409);
        }
        $attempt->setRelation('quiz', $quiz);

        return response()->json(['success' => true, 'data' => [
            'attempt_id' => $attempt->id,
            'started_at' => $attempt->started_at,
            'expires_at' => $this->expiresAt($attempt),
            'server_time' => now(),
            'questions' => $this->questionsForStudent($quiz),
            'answers' => QuizAnswer::where('quiz_attempt_id', $attempt->id)->pluck('answer', 'question_id'),
        ]]);
    }

    /** PUT /api/student/quiz-attempts/{attemptId}/answers  body: { answers: [{question_id, answer}] } */
    public function saveAnswers(Request $request, int $attemptId): JsonResponse
    {
        $attempt = $this->ownedAttempt($this->student($request), $attemptId);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
        }
        if ($attempt->status !== 'in_progress') {
            return response()->json(['success' => false, 'message' => 'This attempt has already been submitted.'], 409);
        }
        if (now()->gt($this->expiresAt($attempt)->addSeconds(self::GRACE_SECONDS))) {
            return response()->json(['success' => false, 'message' => 'Time is up. Submit the quiz to finish.', 'time_up' => true], 422);
        }

        $this->storeAnswers($request, $attempt);

        return response()->json(['success' => true, 'message' => 'Answers saved.']);
    }

    protected function storeAnswers(Request $request, QuizAttempt $attempt): void
    {
        $data = $request->validate([
            'answers' => 'sometimes|array',
            'answers.*.question_id' => 'required|integer',
            'answers.*.answer' => 'nullable|string|max:5000',
        ]);

        $valid = QuizQuestion::where('quiz_id', $attempt->quiz_id)->pluck('id')->all();
        foreach ($data['answers'] ?? [] as $row) {
            if (! in_array((int) $row['question_id'], $valid, true)) {
                continue; // ignore questions that are not part of this quiz
            }
            $text = $row['answer'] ?? null;
            if ($text === null || trim($text) === '') {
                QuizAnswer::where('quiz_attempt_id', $attempt->id)->where('question_id', $row['question_id'])->delete();

                continue;
            }
            QuizAnswer::updateOrCreate(
                ['quiz_attempt_id' => $attempt->id, 'question_id' => (int) $row['question_id']],
                ['answer' => $text, 'is_correct' => null, 'marks_obtained' => null]
            );
        }
    }

    /** POST /api/student/quiz-attempts/{attemptId}/submit  body: { answers?: [...] } */
    public function submit(Request $request, int $attemptId): JsonResponse
    {
        $student = $this->student($request);
        $attempt = $this->ownedAttempt($student, $attemptId);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
        }
        if ($attempt->status !== 'in_progress') {
            return response()->json(['success' => false, 'message' => 'This quiz was already submitted.', 'data' => $this->resultPayload($attempt, $attempt->quiz)], 409);
        }

        $late = now()->gt($this->expiresAt($attempt)->addSeconds(self::GRACE_SECONDS));
        if (! $late) {
            $this->storeAnswers($request, $attempt);
        }

        $attempt->update(['submitted_at' => now()]);
        $attempt = $this->grading->grade($attempt);
        $answered = QuizAnswer::where('quiz_attempt_id', $attempt->id)->count();
        $total = QuizQuestion::where('quiz_id', $attempt->quiz_id)->count();

        return response()->json([
            'success' => true,
            'message' => $late ? 'Time was up; your saved answers were submitted.' : 'Quiz submitted successfully.',
            'data' => $this->resultPayload($attempt, $attempt->quiz) + ['answered' => $answered, 'unanswered' => $total - $answered],
        ]);
    }

    /** GET /api/student/quiz-attempts/{attemptId}/review — only after the quiz closed. */
    public function review(Request $request, int $attemptId): JsonResponse
    {
        $attempt = $this->ownedAttempt($this->student($request), $attemptId);
        if ($attempt instanceof JsonResponse) {
            return $attempt;
        }
        $result = $this->resultPayload($attempt, $attempt->quiz);
        if (! $result['review_allowed']) {
            return response()->json(['success' => false, 'message' => 'Answer review is not available yet.'], 403);
        }

        $answers = QuizAnswer::where('quiz_attempt_id', $attempt->id)->get()->keyBy('question_id');
        $items = QuizQuestion::where('quiz_id', $attempt->quiz_id)->orderBy('question_order')->get()->each->makeVisible('correct_answer')
            ->map(function (QuizQuestion $q) use ($answers) {
                $a = $answers->get($q->id);

                return [
                    'number' => $q->question_order,
                    'question_text' => $q->question_text,
                    'question_type' => $q->question_type,
                    'marks' => (float) $q->marks,
                    'your_answer' => $a?->answer,
                    'correct_answer' => $q->correct_answer,
                    'is_correct' => $a?->is_correct,
                    'marks_obtained' => $a?->marks_obtained,
                ];
            })->values();

        return response()->json(['success' => true, 'data' => ['result' => $result, 'questions' => $items]]);
    }
}
