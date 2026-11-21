<?php

namespace App\Http\Controllers;

use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\Teacher;
use App\Services\AssessmentAccessService;
use App\Services\QuizGradingService;
use App\Services\TeacherAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Teacher-side assessment management that is scoped to the teacher's own
 * teaching assignments (course offerings). Every write re-derives authorization
 * from the authenticated user; client supplied ids are only ever *checked*.
 */
class TeacherAssessmentController extends Controller
{
    public function __construct(
        protected TeacherAuthorizationService $teacherAuth,
        protected AssessmentAccessService $access,
        protected QuizGradingService $grading,
    ) {}

    protected function teacher(Request $request): Teacher
    {
        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated.');

        $teacher = $this->teacherAuth->resolveTeacher($user);
        if (! $teacher && $user->role === 'admin') {
            $teacher = Teacher::first();
        }
        if (! $teacher) {
            abort(response()->json(['success' => false, 'message' => 'Teacher profile not found.'], 404));
        }

        return $teacher;
    }

    protected function forbidden(string $message = 'You are not assigned to this class/course.'): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 403);
    }

    /* ================================================================== */
    /* Teaching assignments (drives Year -> Semester -> Class -> Course)   */
    /* ================================================================== */

    /** GET /api/teacher/teaching-assignments */
    public function teachingAssignments(Request $request): JsonResponse
    {
        $teacher = $this->teacher($request);
        $rows = $this->access->teachingAssignments($teacher);

        return response()->json([
            'success' => true,
            'message' => empty($rows) ? 'No teaching assignments available.' : 'Teaching assignments retrieved successfully.',
            'data' => $rows,
        ]);
    }

    /* ================================================================== */
    /* Exams                                                              */
    /* ================================================================== */

    /** GET /api/teacher/course-offerings/{id}/exams */
    public function offeringExams(Request $request, int $id): JsonResponse
    {
        $teacher = $this->teacher($request);
        if (! $this->teacherAuth->isOfferingAuthorized($teacher, $id)) {
            return $this->forbidden();
        }

        $exams = Exam::where('course_offering_id', $id)->orderByDesc('exam_date')->get();

        return response()->json(['success' => true, 'message' => 'Exams retrieved successfully.', 'data' => $exams]);
    }

    /** POST /api/teacher/course-offerings/{id}/exams */
    public function storeExam(Request $request, int $id): JsonResponse
    {
        $teacher = $this->teacher($request);
        if (! $this->teacherAuth->isOfferingAuthorized($teacher, $id)) {
            return $this->forbidden();
        }
        $offering = CourseOffering::findOrFail($id);

        $data = $request->validate([
            'title' => 'required|string|max:200',
            'exam_type' => ['required', Rule::in(['midterm', 'final'])],
            'exam_date' => 'required|date',
            'total_score' => 'required|numeric|min:1|max:1000',
            'duration_minutes' => 'nullable|integer|min:1|max:600',
            'venue' => 'nullable|string|max:150',
            'description' => 'nullable|string',
            'status' => ['nullable', Rule::in(['draft', 'scheduled'])],
        ]);

        $exam = Exam::create($data + [
            'course_offering_id' => $offering->id,
            'course_id' => $offering->course_id,
            'semester_id' => $offering->semester_id,
            'teacher_id' => $teacher->id,
            'status' => $data['status'] ?? 'scheduled',
        ]);

        return response()->json(['success' => true, 'message' => 'Exam created successfully.', 'data' => $exam], 201);
    }

    /** PUT /api/teacher/exams/{examId} */
    public function updateExam(Request $request, int $examId): JsonResponse
    {
        $teacher = $this->teacher($request);
        $exam = Exam::findOrFail($examId);
        if (! $this->access->teacherCanManage($teacher, $exam)) {
            return $this->forbidden('Unauthorized to modify this exam.');
        }

        $data = $request->validate([
            'title' => 'sometimes|string|max:200',
            'exam_type' => ['sometimes', Rule::in(['midterm', 'final'])],
            'exam_date' => 'sometimes|date',
            'total_score' => 'sometimes|numeric|min:1|max:1000',
            'duration_minutes' => 'nullable|integer|min:1|max:600',
            'venue' => 'nullable|string|max:150',
            'description' => 'nullable|string',
            'status' => ['sometimes', Rule::in(['draft', 'scheduled', 'ongoing', 'completed', 'cancelled'])],
        ]);

        $exam->update($data);

        return response()->json(['success' => true, 'message' => 'Exam updated successfully.', 'data' => $exam->fresh()]);
    }

    /** DELETE /api/teacher/exams/{examId} */
    public function deleteExam(Request $request, int $examId): JsonResponse
    {
        $teacher = $this->teacher($request);
        $exam = Exam::findOrFail($examId);
        if (! $this->access->teacherCanManage($teacher, $exam)) {
            return $this->forbidden('Unauthorized to delete this exam.');
        }
        if ($exam->results()->exists()) {
            return response()->json(['success' => false, 'message' => 'This exam already has results and cannot be deleted. Cancel it instead.'], 409);
        }
        $exam->delete();

        return response()->json(['success' => true, 'message' => 'Exam deleted successfully.']);
    }

    /* ================================================================== */
    /* Quizzes                                                            */
    /* ================================================================== */

    protected function authorizedQuiz(Request $request, int $quizId): Quiz|JsonResponse
    {
        $teacher = $this->teacher($request);
        $quiz = Quiz::findOrFail($quizId);
        if (! $this->access->teacherCanManage($teacher, $quiz)) {
            return $this->forbidden('Unauthorized to manage this quiz.');
        }

        return $quiz;
    }

    protected function formatQuestion(QuizQuestion $q): array
    {
        $options = [];
        foreach (['A' => 'option_a', 'B' => 'option_b', 'C' => 'option_c', 'D' => 'option_d'] as $letter => $col) {
            if ($q->{$col} !== null && trim((string) $q->{$col}) !== '') {
                $options[] = ['key' => $letter, 'text' => $q->{$col}];
            }
        }

        return [
            'id' => $q->id,
            'quiz_id' => $q->quiz_id,
            'question_order' => $q->question_order,
            'question_text' => $q->question_text,
            'question_type' => $q->question_type,
            'marks' => (float) $q->marks,
            'option_a' => $q->option_a,
            'option_b' => $q->option_b,
            'option_c' => $q->option_c,
            'option_d' => $q->option_d,
            'options' => $options,
            'option_count' => count($options),
            'correct_answer' => $q->correct_answer,
        ];
    }

    protected function quizPayload(Quiz $quiz): array
    {
        $quiz->load(['courseOffering.course', 'courseOffering.classRoom', 'courseOffering.semester.academicYear']);
        $questions = QuizQuestion::where('quiz_id', $quiz->id)->orderBy('question_order')->get()
            ->map(fn ($q) => $this->formatQuestion($q))->values();

        return [
            'id' => $quiz->id,
            'title' => $quiz->title,
            'description' => $quiz->description,
            'status' => $quiz->status,
            'duration_minutes' => $quiz->duration_minutes,
            'start_at' => $quiz->start_at,
            'end_at' => $quiz->end_at,
            'course_offering_id' => $quiz->course_offering_id,
            'course_name' => $quiz->courseOffering?->course?->name,
            'course_code' => $quiz->courseOffering?->course?->code,
            'class_name' => $quiz->courseOffering?->classRoom?->name,
            'semester_name' => $quiz->courseOffering?->semester?->name,
            'total_questions' => $questions->count(),
            'total_marks' => round((float) $questions->sum('marks'), 2),
            'stored_total_score' => (float) $quiz->total_score,
            'attempts_count' => QuizAttempt::where('quiz_id', $quiz->id)->count(),
            'questions' => $questions,
        ];
    }

    /** Keep quizzes.total_score equal to the sum of question marks. */
    protected function syncTotal(Quiz $quiz): void
    {
        $sum = (float) QuizQuestion::where('quiz_id', $quiz->id)->sum('marks');
        if ($sum > 0) {
            $quiz->update(['total_score' => $sum]);
        }
    }

    /** GET /api/teacher/quizzes/{quizId} */
    public function showQuiz(Request $request, int $quizId): JsonResponse
    {
        $quiz = $this->authorizedQuiz($request, $quizId);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }

        return response()->json(['success' => true, 'message' => 'Quiz retrieved successfully.', 'data' => $this->quizPayload($quiz)]);
    }

    /** PUT /api/teacher/quizzes/{quizId} */
    public function updateQuiz(Request $request, int $quizId): JsonResponse
    {
        $quiz = $this->authorizedQuiz($request, $quizId);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }

        $data = $request->validate([
            'title' => 'sometimes|string|max:200',
            'description' => 'nullable|string',
            'duration_minutes' => 'sometimes|integer|min:1|max:300',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date',
            'status' => ['sometimes', Rule::in(['draft', 'closed'])], // publishing goes through /publish validation
        ]);

        $start = $data['start_at'] ?? $quiz->start_at;
        $end = $data['end_at'] ?? $quiz->end_at;
        if ($start && $end && strtotime((string) $end) < strtotime((string) $start)) {
            throw ValidationException::withMessages(['end_at' => 'End time must be after the start time.']);
        }

        $quiz->update($data);

        return response()->json(['success' => true, 'message' => 'Quiz updated successfully.', 'data' => $this->quizPayload($quiz->fresh())]);
    }

    /** DELETE /api/teacher/quizzes/{quizId} */
    public function deleteQuiz(Request $request, int $quizId): JsonResponse
    {
        $quiz = $this->authorizedQuiz($request, $quizId);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }
        if (QuizAttempt::where('quiz_id', $quiz->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Students have already attempted this quiz. Close it instead of deleting it.'], 409);
        }
        $quiz->delete();

        return response()->json(['success' => true, 'message' => 'Quiz deleted successfully.']);
    }

    /** POST /api/teacher/quizzes/{quizId}/questions */
    public function storeQuestion(Request $request, int $quizId): JsonResponse
    {
        $quiz = $this->authorizedQuiz($request, $quizId);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }
        if (QuizAttempt::where('quiz_id', $quiz->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Questions cannot be added after students started attempting this quiz.'], 409);
        }

        $data = $this->validatedQuestion($request);

        $question = DB::transaction(function () use ($quiz, $data) {
            Quiz::whereKey($quiz->id)->lockForUpdate()->first();
            $next = (int) QuizQuestion::where('quiz_id', $quiz->id)->max('question_order') + 1;

            return QuizQuestion::create($data + ['quiz_id' => $quiz->id, 'question_order' => $next]);
        });

        $this->syncTotal($quiz);

        return response()->json([
            'success' => true,
            'message' => 'Question added to quiz successfully.',
            'data' => $this->formatQuestion($question->makeVisible('correct_answer')),
            'quiz' => $this->quizSummary($quiz->fresh()),
        ], 201);
    }

    /** PUT /api/teacher/quiz-questions/{questionId} */
    public function updateQuestion(Request $request, int $questionId): JsonResponse
    {
        $question = QuizQuestion::findOrFail($questionId);
        $quiz = $this->authorizedQuiz($request, $question->quiz_id);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }
        if (QuizAttempt::where('quiz_id', $quiz->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Questions cannot be edited after students started attempting this quiz.'], 409);
        }

        $question->update($this->validatedQuestion($request));
        $this->syncTotal($quiz);

        return response()->json([
            'success' => true,
            'message' => 'Question updated successfully.',
            'data' => $this->formatQuestion($question->fresh()->makeVisible('correct_answer')),
            'quiz' => $this->quizSummary($quiz->fresh()),
        ]);
    }

    /** DELETE /api/teacher/quiz-questions/{questionId} */
    public function deleteQuestion(Request $request, int $questionId): JsonResponse
    {
        $question = QuizQuestion::findOrFail($questionId);
        $quiz = $this->authorizedQuiz($request, $question->quiz_id);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }
        if (QuizAttempt::where('quiz_id', $quiz->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Questions cannot be deleted after students started attempting this quiz.'], 409);
        }

        DB::transaction(function () use ($question, $quiz) {
            $question->delete();
            $this->resequence($quiz->id, QuizQuestion::where('quiz_id', $quiz->id)->orderBy('question_order')->pluck('id')->all());
        });
        $this->syncTotal($quiz);

        return response()->json(['success' => true, 'message' => 'Question deleted successfully.', 'quiz' => $this->quizSummary($quiz->fresh())]);
    }

    /** POST /api/teacher/quiz-questions/{questionId}/duplicate */
    public function duplicateQuestion(Request $request, int $questionId): JsonResponse
    {
        $question = QuizQuestion::findOrFail($questionId);
        $quiz = $this->authorizedQuiz($request, $question->quiz_id);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }
        if (QuizAttempt::where('quiz_id', $quiz->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Questions cannot be added after students started attempting this quiz.'], 409);
        }

        $copy = DB::transaction(function () use ($question, $quiz) {
            $next = (int) QuizQuestion::where('quiz_id', $quiz->id)->max('question_order') + 1;

            return QuizQuestion::create(array_merge(
                $question->makeVisible('correct_answer')->only(['quiz_id', 'question_text', 'question_type', 'marks', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_answer']),
                ['question_order' => $next]
            ));
        });
        $this->syncTotal($quiz);

        return response()->json([
            'success' => true,
            'message' => 'Question duplicated successfully.',
            'data' => $this->formatQuestion($copy->makeVisible('correct_answer')),
            'quiz' => $this->quizSummary($quiz->fresh()),
        ], 201);
    }

    /** PUT /api/teacher/quizzes/{quizId}/questions/reorder  body: { "order": [questionId, ...] } */
    public function reorderQuestions(Request $request, int $quizId): JsonResponse
    {
        $quiz = $this->authorizedQuiz($request, $quizId);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }
        if (QuizAttempt::where('quiz_id', $quiz->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Questions cannot be reordered after students started attempting this quiz.'], 409);
        }

        $data = $request->validate(['order' => 'required|array|min:1', 'order.*' => 'integer']);
        $existing = QuizQuestion::where('quiz_id', $quiz->id)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $requested = array_map('intval', $data['order']);

        if (count($requested) !== count($existing) || array_diff($requested, $existing) || array_diff($existing, $requested)) {
            throw ValidationException::withMessages(['order' => 'The order must list every question of this quiz exactly once.']);
        }

        DB::transaction(fn () => $this->resequence($quiz->id, $requested));

        return response()->json(['success' => true, 'message' => 'Questions reordered successfully.', 'data' => $this->quizPayload($quiz->fresh())]);
    }

    /** Two-phase update because (quiz_id, question_order) is unique. */
    protected function resequence(int $quizId, array $orderedIds): void
    {
        foreach ($orderedIds as $i => $id) {
            QuizQuestion::where('quiz_id', $quizId)->whereKey($id)->update(['question_order' => 10000 + $i + 1]);
        }
        foreach ($orderedIds as $i => $id) {
            QuizQuestion::where('quiz_id', $quizId)->whereKey($id)->update(['question_order' => $i + 1]);
        }
    }

    /** PATCH /api/teacher/quizzes/{quizId}/publish */
    public function publishQuiz(Request $request, int $quizId): JsonResponse
    {
        $quiz = $this->authorizedQuiz($request, $quizId);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }

        $errors = $this->publishErrors($quiz);
        if ($errors) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz cannot be published: '.$errors[0],
                'errors' => ['quiz' => $errors],
            ], 422);
        }

        $this->syncTotal($quiz);
        $quiz->update(['status' => 'published']);

        return response()->json(['success' => true, 'message' => 'Quiz published successfully.', 'data' => $this->quizPayload($quiz->fresh())]);
    }

    /** Server-side validation shared by publish. Returns a list of human readable problems. */
    protected function publishErrors(Quiz $quiz): array
    {
        $errors = [];
        if (trim((string) $quiz->title) === '') {
            $errors[] = 'The quiz title is required.';
        }
        if (! $quiz->duration_minutes || $quiz->duration_minutes < 1) {
            $errors[] = 'A quiz duration is required.';
        }
        if ($quiz->start_at && $quiz->end_at && $quiz->end_at->lt($quiz->start_at)) {
            $errors[] = 'The end time is before the start time.';
        }

        $questions = QuizQuestion::where('quiz_id', $quiz->id)->orderBy('question_order')->get()->each->makeVisible('correct_answer');
        if ($questions->isEmpty()) {
            $errors[] = 'Add at least one question.';
        }

        foreach ($questions as $q) {
            $n = $q->question_order;
            if (trim((string) $q->question_text) === '') {
                $errors[] = "Question $n has no text.";
            }
            if ((float) $q->marks <= 0) {
                $errors[] = "Question $n must be worth more than 0 marks.";
            }
            $opts = array_filter(['A' => $q->option_a, 'B' => $q->option_b, 'C' => $q->option_c, 'D' => $q->option_d], fn ($v) => $v !== null && trim((string) $v) !== '');
            switch ($q->question_type) {
                case 'multiple_choice':
                    if (count($opts) < 2) {
                        $errors[] = "Question $n needs at least two answer options.";
                    }
                    if (! isset($opts[strtoupper((string) $q->correct_answer)])) {
                        $errors[] = "Question $n has no valid correct answer.";
                    }
                    break;
                case 'true_false':
                    if (! in_array(strtolower((string) $q->correct_answer), ['true', 'false'], true)) {
                        $errors[] = "Question $n needs a True/False correct answer.";
                    }
                    break;
                case 'short_answer':
                    // expected answer is optional guidance; those without one are graded manually.
                    break;
            }
        }

        return $errors;
    }

    protected function quizSummary(Quiz $quiz): array
    {
        $qs = QuizQuestion::where('quiz_id', $quiz->id);

        return [
            'id' => $quiz->id,
            'status' => $quiz->status,
            'total_questions' => (clone $qs)->count(),
            'total_marks' => round((float) (clone $qs)->sum('marks'), 2),
            'duration_minutes' => $quiz->duration_minutes,
        ];
    }

    /** Shared question validation + normalisation. */
    protected function validatedQuestion(Request $request): array
    {
        if ($request->has('points') && ! $request->has('marks')) {
            $request->merge(['marks' => $request->input('points')]);
        }
        if (is_array($request->input('options'))) {
            $opts = array_values($request->input('options'));
            $merge = [];
            foreach (['option_a', 'option_b', 'option_c', 'option_d'] as $i => $col) {
                if (! $request->has($col)) {
                    $merge[$col] = $opts[$i] ?? null;
                }
            }
            $request->merge($merge);
        }

        $type = $request->input('question_type', 'multiple_choice');
        $data = $request->validate([
            'question_text' => 'required|string|max:5000',
            'question_type' => ['required', Rule::in(['multiple_choice', 'true_false', 'short_answer'])],
            'marks' => 'required|numeric|min:0.5|max:100',
            'option_a' => 'nullable|string|max:500',
            'option_b' => 'nullable|string|max:500',
            'option_c' => 'nullable|string|max:500',
            'option_d' => 'nullable|string|max:500',
            'correct_answer' => [Rule::requiredIf($type !== 'short_answer'), 'nullable', 'string', 'max:500'],
        ], [], ['marks' => 'marks']);

        $data['question_text'] = trim($data['question_text']);
        foreach (['option_a', 'option_b', 'option_c', 'option_d'] as $col) {
            $data[$col] = isset($data[$col]) && trim($data[$col]) !== '' ? trim($data[$col]) : null;
        }

        if ($type === 'multiple_choice') {
            $opts = ['A' => $data['option_a'], 'B' => $data['option_b'], 'C' => $data['option_c'], 'D' => $data['option_d']];
            if (count(array_filter($opts)) < 2) {
                throw ValidationException::withMessages(['options' => 'Multiple choice questions need at least two answer options.']);
            }
            $correct = strtoupper(trim((string) $data['correct_answer']));
            if (! isset($opts[$correct]) || $opts[$correct] === null) {
                throw ValidationException::withMessages(['correct_answer' => 'Select a correct answer that matches a filled-in option (A-D).']);
            }
            $data['correct_answer'] = $correct;
        } elseif ($type === 'true_false') {
            $correct = strtolower(trim((string) $data['correct_answer']));
            if (! in_array($correct, ['true', 'false'], true)) {
                throw ValidationException::withMessages(['correct_answer' => 'The correct answer must be True or False.']);
            }
            $data['correct_answer'] = $correct;
            $data['option_a'] = $data['option_b'] = $data['option_c'] = $data['option_d'] = null;
        } else {
            $data['option_a'] = $data['option_b'] = $data['option_c'] = $data['option_d'] = null;
            $data['correct_answer'] = isset($data['correct_answer']) ? trim($data['correct_answer']) : null;
        }

        return $data;
    }

    /* ================================================================== */
    /* Quiz attempts / manual grading                                      */
    /* ================================================================== */

    /** GET /api/teacher/quizzes/{quizId}/attempts */
    public function quizAttempts(Request $request, int $quizId): JsonResponse
    {
        $quiz = $this->authorizedQuiz($request, $quizId);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }

        $attempts = QuizAttempt::with('student.user')->where('quiz_id', $quiz->id)->latest()->get()->map(fn ($a) => [
            'id' => $a->id,
            'student_id' => $a->student_id,
            'student_name' => $a->student?->user?->name,
            'status' => $a->status,
            'score' => $a->score,
            'submitted_at' => $a->submitted_at,
            'pending_manual_grading' => QuizAnswer::where('quiz_attempt_id', $a->id)->whereNull('is_correct')->exists(),
        ]);

        return response()->json(['success' => true, 'data' => $attempts]);
    }

    /** GET /api/teacher/quiz-attempts/{attemptId} */
    public function showQuizAttempt(Request $request, int $attemptId): JsonResponse
    {
        $attempt = QuizAttempt::with(['quiz', 'student.user'])->findOrFail($attemptId);
        if (! $attempt->quiz || ! $this->access->teacherCanManage($this->teacher($request), $attempt->quiz)) {
            return $this->forbidden('Unauthorized to view this quiz attempt.');
        }

        $answers = QuizAnswer::where('quiz_attempt_id', $attempt->id)->get()->keyBy('question_id');
        $questions = QuizQuestion::where('quiz_id', $attempt->quiz_id)
            ->orderBy('question_order')
            ->get()
            ->each->makeVisible('correct_answer')
            ->map(function (QuizQuestion $q) use ($answers) {
                $a = $answers->get($q->id);

                return [
                    'question_id' => $q->id,
                    'answer_id' => $a?->id,
                    'number' => $q->question_order,
                    'question_text' => $q->question_text,
                    'question_type' => $q->question_type,
                    'marks' => (float) $q->marks,
                    'correct_answer' => $q->correct_answer,
                    'student_answer' => $a?->answer,
                    'is_correct' => $a?->is_correct,
                    'marks_obtained' => $a?->marks_obtained,
                    'needs_manual_grading' => $a !== null && $a->is_correct === null,
                ];
            })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $attempt->id,
                'quiz_id' => $attempt->quiz_id,
                'quiz_title' => $attempt->quiz->title,
                'student_name' => $attempt->student?->user?->name,
                'status' => $attempt->status,
                'score' => $attempt->score,
                'total_marks' => (float) $questions->sum('marks'),
                'submitted_at' => $attempt->submitted_at,
                'questions' => $questions,
            ],
        ]);
    }

    /** PATCH /api/teacher/quiz-answers/{answerId}/grade  body: { marks_obtained } */
    public function gradeAnswer(Request $request, int $answerId): JsonResponse
    {
        $answer = QuizAnswer::with('question', 'attempt.quiz')->findOrFail($answerId);
        $quiz = $answer->attempt?->quiz;
        if (! $quiz || ! $this->access->teacherCanManage($this->teacher($request), $quiz)) {
            return $this->forbidden('Unauthorized to grade this answer.');
        }

        $max = (float) $answer->question->marks;
        $data = $request->validate(['marks_obtained' => "required|numeric|min:0|max:$max"]);

        $answer->update([
            'marks_obtained' => $data['marks_obtained'],
            'is_correct' => (float) $data['marks_obtained'] >= $max,
        ]);
        $attempt = $this->grading->recalculate($answer->attempt);

        return response()->json(['success' => true, 'message' => 'Answer graded.', 'data' => ['attempt_score' => $attempt->score, 'attempt_status' => $attempt->status]]);
    }
}
