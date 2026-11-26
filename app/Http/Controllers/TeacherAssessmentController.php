<?php

namespace App\Http\Controllers;

use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizOverrideLog;
use App\Models\QuizQuestion;
use App\Models\QuizStudentOverride;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\AssessmentAccessService;
use App\Services\QuizAvailabilityService;
use App\Services\QuizGradingService;
use App\Services\TeacherAuthorizationService;
use Carbon\Carbon;
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
        protected QuizAvailabilityService $availability,
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

        $exams = Exam::where('course_offering_id', $id)
            ->withCount('results')
            ->orderByDesc('exam_date')
            ->get()
            ->map(function (Exam $exam) {
                $exam->is_published = $exam->isResultsPublished();
                $exam->display_status = $exam->displayStatus($exam->results_count);
                return $exam;
            });

        return response()->json(['success' => true, 'message' => 'Exams retrieved successfully.', 'data' => $exams]);
    }

    /** @return array<int> */
    protected function getEnrolledStudentIdsForExam(Exam $exam): array
    {
        $offering = $exam->courseOffering;
        $studentsQuery = \App\Models\CourseEnrollment::query();
        if ($offering) {
            $studentsQuery->where(function ($q) use ($offering) {
                $q->where('course_offering_id', $offering->id)
                  ->orWhere(function ($sq) use ($offering) {
                      $sq->whereNull('course_offering_id')
                         ->where('course_id', $offering->course_id)
                         ->where('semester_id', $offering->semester_id);
                  });
            });
        } else {
            $studentsQuery->where('course_id', $exam->course_id)
                ->where('semester_id', $exam->semester_id);
        }

        return $studentsQuery->pluck('student_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** GET /api/teacher/exams/{examId} */
    public function showExam(Request $request, int $examId): JsonResponse
    {
        $teacher = $this->teacher($request);
        $exam = Exam::with(['course', 'semester', 'courseOffering.classRoom', 'courseOffering.semester.academicYear'])->findOrFail($examId);
        if (! $this->access->teacherCanManage($teacher, $exam)) {
            return $this->forbidden('Unauthorized to view this exam.');
        }

        $offering = $exam->courseOffering;
        $studentsQuery = \App\Models\CourseEnrollment::query();
        if ($offering) {
            $studentsQuery->where(function ($q) use ($offering) {
                $q->where('course_offering_id', $offering->id)
                  ->orWhere(function ($sq) use ($offering) {
                      $sq->whereNull('course_offering_id')
                         ->where('course_id', $offering->course_id)
                         ->where('semester_id', $offering->semester_id);
                  });
            });
        } else {
            $studentsQuery->where('course_id', $exam->course_id)
                ->where('semester_id', $exam->semester_id);
        }

        $enrollments = $studentsQuery->with(['student.user', 'student.classRoom'])->get()->unique('student_id');
        $results = $exam->results()->get()->keyBy('student_id');
        $totalMarks = (float) ($exam->total_score ?: 100);
        $isPublished = $exam->isResultsPublished();

        $studentsData = $enrollments->map(function ($enrollment) use ($results, $exam, $totalMarks, $isPublished) {
            $student = $enrollment->student;
            $res = $student ? $results->get($student->id) : null;
            $hasScore = $res !== null && $res->score !== null;
            $scoreVal = $hasScore ? (float) $res->score : null;
            $pct = ($hasScore && $totalMarks > 0) ? round(($scoreVal / $totalMarks) * 100, 1) : null;

            return [
                'student_id' => $student?->id,
                'student_code' => $student?->student_code ?? ('STU' . $student?->id),
                'name' => trim(($student?->first_name ?? '') . ' ' . ($student?->last_name ?? '')) ?: ($student?->english_name ?? 'Student'),
                'email' => $student?->user?->email,
                'class_name' => $student?->classRoom?->name ?? $exam->courseOffering?->classRoom?->name,
                'has_score' => $hasScore,
                'score' => $scoreVal,
                'total_marks' => $totalMarks,
                'percentage' => $pct,
                'grade' => $res?->grade,
                'result_status' => $hasScore ? Exam::calculatePassStatus($scoreVal, $totalMarks) : null,
                'grading_status' => $hasScore ? 'Graded' : 'Pending',
                'publication_status' => $isPublished ? 'Published' : 'Unpublished',
                'remarks' => $res?->remarks,
                'updated_at' => $res?->updated_at?->toIso8601String(),
            ];
        })->sortBy('name')->values();

        $scores = $results->pluck('score')->filter(fn ($s) => $s !== null)->map(fn ($s) => (float) $s);
        $statistics = [
            'total_students' => $studentsData->count(),
            'graded_students' => $scores->count(),
            'pending_students' => max(0, $studentsData->count() - $scores->count()),
            'average_score' => $scores->count() > 0 ? round($scores->average(), 2) : 0,
            'highest_score' => $scores->count() > 0 ? $scores->max() : 0,
            'lowest_score' => $scores->count() > 0 ? $scores->min() : 0,
            'total_marks' => $totalMarks,
        ];

        return response()->json([
            'success' => true,
            'message' => 'Exam details retrieved successfully.',
            'data' => [
                'exam' => [
                    'id' => $exam->id,
                    'title' => $exam->title,
                    'exam_type' => $exam->exam_type,
                    'exam_date' => $exam->exam_date ? \Carbon\Carbon::parse($exam->exam_date)->format('Y-m-d') : null,
                    'start_time' => $exam->start_time,
                    'end_time' => $exam->end_time,
                    'total_score' => $totalMarks,
                    'duration_minutes' => $exam->duration_minutes,
                    'venue' => $exam->venue,
                    'description' => $exam->description,
                    'status' => $exam->status,
                    'display_status' => $exam->displayStatus($scores->count()),
                    'is_published' => $isPublished,
                    'results_published_at' => $exam->results_published_at?->toIso8601String(),
                    'course_id' => $exam->course_id,
                    'course_code' => $exam->course?->code ?? $exam->course?->course_code,
                    'course_name' => $exam->course?->name ?? $exam->course?->course_name,
                    'course_offering_id' => $exam->course_offering_id,
                    'class_name' => $exam->courseOffering?->classRoom?->name,
                    'semester_id' => $exam->semester_id,
                    'semester_name' => $exam->semester?->name,
                ],
                'students' => $studentsData,
                'statistics' => $statistics,
            ],
        ]);
    }

    /** POST /api/teacher/course-offerings/{id}/exams */
    public function storeExam(Request $request, int $id): JsonResponse
    {
        $teacher = $this->teacher($request);
        if (! $this->teacherAuth->isOfferingAuthorized($teacher, $id)) {
            return $this->forbidden();
        }
        $offering = CourseOffering::findOrFail($id);

        if ($request->has('start_time')) {
            $request->merge(['start_time' => Exam::normalizeTime($request->input('start_time'))]);
        }
        if ($request->has('end_time')) {
            $request->merge(['end_time' => Exam::normalizeTime($request->input('end_time'))]);
        }

        $data = $request->validate([
            'title' => 'required|string|max:200',
            'exam_type' => ['required', Rule::in(['midterm', 'final'])],
            'exam_date' => 'required|date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i|after:start_time',
            'total_score' => 'required|numeric|min:1|max:1000',
            'duration_minutes' => 'nullable|integer|min:1|max:600',
            'venue' => 'nullable|string|max:150',
            'description' => 'nullable|string',
            'status' => ['nullable', Rule::in(['draft', 'scheduled', 'results_pending'])],
        ]);

        if (! empty($data['start_time']) && empty($data['end_time']) && ! empty($data['duration_minutes'])) {
            $data['end_time'] = Exam::computeEndTime($data['start_time'], (int) $data['duration_minutes']);
        }

        $exam = Exam::create($data + [
            'course_offering_id' => $offering->id,
            'course_id' => $offering->course_id,
            'semester_id' => $offering->semester_id,
            'teacher_id' => $teacher->id,
            'status' => $data['status'] ?? 'scheduled',
        ]);
        $exam->is_published = $exam->isResultsPublished();
        $exam->display_status = $exam->displayStatus(0);

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

        if ($request->has('start_time')) {
            $request->merge(['start_time' => Exam::normalizeTime($request->input('start_time'))]);
        }
        if ($request->has('end_time')) {
            $request->merge(['end_time' => Exam::normalizeTime($request->input('end_time'))]);
        }

        $data = $request->validate([
            'course_offering_id' => 'sometimes|integer|exists:course_offerings,id',
            'title' => 'sometimes|string|max:200',
            'exam_type' => ['sometimes', Rule::in(['midterm', 'final'])],
            'exam_date' => 'sometimes|date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'total_score' => 'sometimes|numeric|min:1|max:1000',
            'duration_minutes' => 'nullable|integer|min:1|max:600',
            'venue' => 'nullable|string|max:150',
            'description' => 'nullable|string',
            'status' => ['sometimes', Rule::in(['draft', 'scheduled', 'ongoing', 'results_pending', 'completed', 'published', 'cancelled'])],
        ]);

        if (isset($data['course_offering_id']) && (int) $data['course_offering_id'] !== (int) $exam->course_offering_id) {
            if (! $this->teacherAuth->isOfferingAuthorized($teacher, (int) $data['course_offering_id'])) {
                return $this->forbidden('Unauthorized to assign this exam to the selected class or course.');
            }
            if ($exam->results()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot change course or class after student exam scores have been recorded.',
                ], 422);
            }
            $newOffering = CourseOffering::findOrFail((int) $data['course_offering_id']);
            $data['course_id'] = $newOffering->course_id;
            $data['semester_id'] = $newOffering->semester_id;
        }

        if (isset($data['total_score'])) {
            $maxExisting = $exam->results()->whereNotNull('score')->max('score');
            if ($maxExisting !== null && (float) $data['total_score'] < (float) $maxExisting) {
                return response()->json([
                    'success' => false,
                    'message' => "Total marks cannot be less than an already recorded score ({$maxExisting}).",
                ], 422);
            }
        }

        $effectiveStart = array_key_exists('start_time', $data) ? $data['start_time'] : $exam->start_time;
        $effectiveDuration = array_key_exists('duration_minutes', $data) ? $data['duration_minutes'] : $exam->duration_minutes;
        $hasExplicitEnd = array_key_exists('end_time', $data) && $data['end_time'] !== null;

        if (array_key_exists('start_time', $data) && $data['start_time'] === null && ! array_key_exists('end_time', $data)) {
            $data['end_time'] = null;
        } elseif ($hasExplicitEnd && $effectiveStart !== null && $data['end_time'] <= $effectiveStart) {
            return response()->json([
                'success' => false,
                'message' => 'End time must be after start time.',
            ], 422);
        } elseif ($effectiveStart !== null && $effectiveDuration !== null
            && (array_key_exists('start_time', $data) || array_key_exists('duration_minutes', $data) || $exam->end_time === null)
            && ! $hasExplicitEnd) {
            $data['end_time'] = Exam::computeEndTime($effectiveStart, (int) $effectiveDuration);
        }

        if (isset($data['status'])) {
            if ($data['status'] === 'published' && ! $exam->results_published_at) {
                $data['results_published_at'] = now();
            } elseif (in_array($data['status'], ['draft', 'scheduled', 'results_pending', 'cancelled'], true)) {
                $data['results_published_at'] = null;
            }
        }

        $oldMaxScore = (float) ($exam->total_score ?: 100);
        $exam->update($data);

        if (isset($data['total_score']) && (float) $data['total_score'] !== $oldMaxScore) {
            $newMaxScore = (float) ($exam->total_score ?: 100);
            foreach ($exam->results()->whereNotNull('score')->get() as $resultRow) {
                $resultRow->update([
                    'grade' => Exam::calculateLetterGrade((float) $resultRow->score, $newMaxScore),
                ]);
            }
        }

        $fresh = $exam->fresh();
        $fresh->is_published = $fresh->isResultsPublished();
        $fresh->display_status = $fresh->displayStatus();

        return response()->json(['success' => true, 'message' => 'Exam updated successfully.', 'data' => $fresh]);
    }

    /** POST /api/teacher/exams/{examId}/scores */
    public function storeExamScores(Request $request, int $examId): JsonResponse
    {
        $teacher = $this->teacher($request);
        $exam = Exam::findOrFail($examId);
        if (! $this->access->teacherCanManage($teacher, $exam)) {
            return $this->forbidden('Unauthorized to record scores for this exam.');
        }

        $maxScore = (float) ($exam->total_score ?: 100);
        $allowedStudentIds = $this->getEnrolledStudentIdsForExam($exam);

        if ($request->has('scores') && is_array($request->input('scores'))) {
            $data = $request->validate([
                'scores' => 'required|array',
                'scores.*.student_id' => 'required|integer|exists:students,id',
                'scores.*.score' => "required|numeric|min:0|max:{$maxScore}",
                'scores.*.remarks' => 'nullable|string|max:255',
            ]);

            foreach ($data['scores'] as $item) {
                if (! in_array((int) $item['student_id'], $allowedStudentIds, true)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'One or more students are not enrolled in this exam\'s class or course.',
                    ], 422);
                }
            }

            $saved = [];
            foreach ($data['scores'] as $item) {
                $score = (float) $item['score'];
                $grade = Exam::calculateLetterGrade($score, $maxScore);
                $result = \App\Models\ExamResult::updateOrCreate(
                    [
                        'exam_id' => $exam->id,
                        'student_id' => $item['student_id'],
                    ],
                    [
                        'score' => $score,
                        'grade' => $grade,
                        'remarks' => $item['remarks'] ?? null,
                    ]
                );
                $saved[] = $result;
            }

            return response()->json([
                'success' => true,
                'message' => count($saved) . ' exam score(s) recorded successfully.',
                'data' => $saved,
            ], 200);
        }

        $data = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'score' => "required|numeric|min:0|max:{$maxScore}",
            'remarks' => 'nullable|string|max:255',
            'grade' => 'nullable|string|max:10',
        ]);

        if (! in_array((int) $data['student_id'], $allowedStudentIds, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Student is not enrolled in this exam\'s class or course.',
            ], 422);
        }

        $score = (float) $data['score'];
        $grade = ! empty($data['grade']) ? $data['grade'] : Exam::calculateLetterGrade($score, $maxScore);

        $result = \App\Models\ExamResult::updateOrCreate(
            [
                'exam_id' => $exam->id,
                'student_id' => $data['student_id'],
            ],
            [
                'score' => $score,
                'grade' => $grade,
                'remarks' => $data['remarks'] ?? null,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Exam score recorded successfully.',
            'data' => $result->load('student.user'),
        ], 200);
    }

    /** PATCH /api/teacher/exams/{examId}/publish */
    public function publishExam(Request $request, int $examId): JsonResponse
    {
        $teacher = $this->teacher($request);
        $exam = Exam::findOrFail($examId);
        if (! $this->access->teacherCanManage($teacher, $exam)) {
            return $this->forbidden('Unauthorized to publish this exam.');
        }

        $resultsCount = $exam->results()->whereNotNull('score')->count();
        if ($resultsCount === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot publish exam results because no student scores have been entered yet.',
            ], 422);
        }

        $exam->update([
            'status' => 'published',
            'results_published_at' => now(),
        ]);

        $fresh = $exam->fresh();
        $fresh->is_published = true;
        $fresh->display_status = $fresh->displayStatus($resultsCount);

        return response()->json([
            'success' => true,
            'message' => 'Exam results published successfully.',
            'data' => $fresh,
        ], 200);
    }

    /** PATCH /api/teacher/exams/{examId}/unpublish */
    public function unpublishExam(Request $request, int $examId): JsonResponse
    {
        $teacher = $this->teacher($request);
        $exam = Exam::findOrFail($examId);
        if (! $this->access->teacherCanManage($teacher, $exam)) {
            return $this->forbidden('Unauthorized to unpublish this exam.');
        }

        $exam->update([
            'status' => 'scheduled',
            'results_published_at' => null,
        ]);

        $fresh = $exam->fresh();
        $fresh->is_published = false;
        $fresh->display_status = $fresh->displayStatus();

        return response()->json([
            'success' => true,
            'message' => 'Exam results unpublished successfully.',
            'data' => $fresh,
        ], 200);
    }

    /** DELETE /api/teacher/exams/{examId} */
    public function deleteExam(Request $request, int $examId): JsonResponse
    {
        $teacher = $this->teacher($request);
        $exam = Exam::findOrFail($examId);
        if (! $this->access->teacherCanManage($teacher, $exam)) {
            return $this->forbidden('Unauthorized to delete this exam.');
        }
        if ($exam->results()->exists() && ! $request->boolean('force')) {
            return response()->json([
                'success' => false,
                'message' => 'This exam already has ' . $exam->results()->count() . ' student result(s). Confirmation is required to delete.',
                'has_results' => true,
            ], 409);
        }
        $exam->results()->delete();
        $exam->delete();

        return response()->json(['success' => true, 'message' => 'Exam deleted successfully.']);
    }

    protected function calculateExamGrade(float $score, float $maxScore): string
    {
        return Exam::calculateLetterGrade($score, $maxScore);
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
            'is_required' => $q->is_required !== false,
            'option_a' => $q->option_a,
            'option_b' => $q->option_b,
            'option_c' => $q->option_c,
            'option_d' => $q->option_d,
            'options' => $options,
            'option_count' => count($options),
            'correct_answer' => $q->correct_answer,
            'grading_guidance' => $q->effectiveGuidance(),
        ];
    }

    protected function quizPayload(Quiz $quiz): array
    {
        $quiz->load(['courseOffering.course', 'courseOffering.classRoom', 'courseOffering.semester.academicYear']);
        $questions = QuizQuestion::where('quiz_id', $quiz->id)
            ->orderBy('question_order')
            ->get()
            ->each->makeVisible(['correct_answer', 'grading_guidance'])
            ->map(fn ($q) => $this->formatQuestion($q))
            ->values();

        $pendingCount = QuizAttempt::where('quiz_id', $quiz->id)
            ->where('is_reset', false)
            ->where(function ($q) {
                $q->where('grading_status', 'pending_grading')
                    ->orWhere('status', 'submitted');
            })
            ->count();

        $overridesCount = QuizStudentOverride::where('quiz_id', $quiz->id)
            ->where('is_active', true)
            ->count();

        return [
            'id' => $quiz->id,
            'title' => $quiz->title,
            'description' => $quiz->description,
            'status' => $quiz->status,
            'duration_minutes' => $quiz->duration_minutes,
            'start_at' => $quiz->start_at,
            'end_at' => $quiz->end_at,
            'max_attempts' => $quiz->max_attempts,
            'scoring_method' => $quiz->scoring_method ?: 'highest',
            'allow_late_submission' => (bool) $quiz->allow_late_submission,
            'reopened_at' => $quiz->reopened_at,
            'reopened_until' => $quiz->reopened_until,
            'version' => (int) ($quiz->version ?: 1),
            'course_offering_id' => $quiz->course_offering_id,
            'course_name' => $quiz->courseOffering?->course?->name,
            'course_code' => $quiz->courseOffering?->course?->code,
            'class_name' => $quiz->courseOffering?->classRoom?->name,
            'semester_name' => $quiz->courseOffering?->semester?->name,
            'total_questions' => $questions->count(),
            'total_marks' => round((float) $questions->sum('marks'), 2),
            'stored_total_score' => (float) $quiz->total_score,
            'attempts_count' => QuizAttempt::where('quiz_id', $quiz->id)->count(),
            'pending_grading_count' => $pendingCount,
            'overrides_count' => $overridesCount,
            'questions' => $questions,
        ];
    }

    /** Keep quizzes.total_score equal to the sum of active question marks. */
    protected function syncTotal(Quiz $quiz): void
    {
        $sum = (float) QuizQuestion::where('quiz_id', $quiz->id)->sum('marks');
        if ($sum > 0) {
            $quiz->update(['total_score' => $sum]);
        }
    }

    /** Snapshot existing attempts before altering active questions so historical attempts remain exact. */
    protected function preserveAttemptSnapshotsBeforeQuestionChange(Quiz $quiz): void
    {
        $attempts = QuizAttempt::where('quiz_id', $quiz->id)->get();
        if ($attempts->isEmpty()) {
            return;
        }
        foreach ($attempts as $attempt) {
            $this->grading->ensureAttemptSnapshot($attempt);
        }
        $quiz->increment('version');
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

        if ($request->has('max_attempts') && ((string) $request->input('max_attempts') === '0' || $request->input('max_attempts') === '')) {
            $request->merge(['max_attempts' => null]);
        }

        $data = $request->validate([
            'title' => 'sometimes|string|max:200',
            'description' => 'nullable|string',
            'duration_minutes' => 'sometimes|integer|min:1|max:300',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date',
            'max_attempts' => ['sometimes', 'nullable', 'integer', Rule::in(Quiz::ALLOWED_MAX_ATTEMPTS)],
            'scoring_method' => ['sometimes', Rule::in(Quiz::SCORING_METHODS)],
            'allow_late_submission' => 'sometimes|boolean',
            'status' => ['sometimes', Rule::in(['draft', 'published', 'closed'])],
        ]);

        $start = array_key_exists('start_at', $data) ? $data['start_at'] : $quiz->start_at;
        $end = array_key_exists('end_at', $data) ? $data['end_at'] : $quiz->end_at;
        if ($start && $end && strtotime((string) $end) < strtotime((string) $start)) {
            throw ValidationException::withMessages(['end_at' => 'End time must be after the start time.']);
        }

        if (($data['status'] ?? null) === 'published') {
            $preview = clone $quiz;
            $preview->fill($data);
            $errors = $this->publishErrors($preview);
            if ($errors) {
                return response()->json([
                    'success' => false,
                    'message' => 'Quiz cannot be published: '.$errors[0],
                    'errors' => ['quiz' => $errors],
                ], 422);
            }
        }

        $quiz->update($data);

        return response()->json(['success' => true, 'message' => 'Quiz updated successfully.', 'data' => $this->quizPayload($quiz->fresh())]);
    }

    /** PATCH /api/teacher/quizzes/{quizId}/unpublish */
    public function unpublishQuiz(Request $request, int $quizId): JsonResponse
    {
        $quiz = $this->authorizedQuiz($request, $quizId);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }

        $quiz->update(['status' => 'draft']);

        return response()->json([
            'success' => true,
            'message' => 'Quiz unpublished and moved to draft. Existing attempts and scores were preserved.',
            'data' => $this->quizPayload($quiz->fresh()),
        ]);
    }

    /** POST /api/teacher/quizzes/{quizId}/reopen */
    public function reopenQuiz(Request $request, int $quizId): JsonResponse
    {
        $quiz = $this->authorizedQuiz($request, $quizId);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }

        $data = $request->validate([
            'end_at' => 'nullable|date',
            'reopened_until' => 'nullable|date',
            'allow_late_submission' => 'nullable|boolean',
            'reason' => 'nullable|string|max:1000',
        ]);

        $rawEnd = $data['reopened_until'] ?? $data['end_at'] ?? null;
        $newEnd = $rawEnd ? Carbon::parse((string) $rawEnd) : null;
        if ($newEnd && $quiz->start_at && $newEnd->lt($quiz->start_at)) {
            throw ValidationException::withMessages(['end_at' => 'Reopen deadline must be after the quiz start time.']);
        }

        $fresh = $this->availability->reopenQuiz(
            $quiz,
            $this->teacher($request),
            $request->user(),
            $newEnd,
            array_key_exists('allow_late_submission', $data) ? $data['allow_late_submission'] : null,
            $data['reason'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Quiz reopened successfully.',
            'data' => $this->quizPayload($fresh),
        ]);
    }

    /** DELETE /api/teacher/quizzes/{quizId} */
    public function deleteQuiz(Request $request, int $quizId): JsonResponse
    {
        $quiz = $this->authorizedQuiz($request, $quizId);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }

        if (QuizAttempt::where('quiz_id', $quiz->id)->exists()) {
            $quiz->update(['status' => 'archived']);
            $quiz->delete();

            return response()->json([
                'success' => true,
                'message' => 'Quiz archived safely; historical student attempts and scores were preserved.',
                'archived' => true,
            ]);
        }

        $quiz->forceDelete();

        return response()->json([
            'success' => true,
            'message' => 'Quiz deleted successfully.',
            'archived' => false,
        ]);
    }

    /** POST /api/teacher/quizzes/{quizId}/questions */
    public function storeQuestion(Request $request, int $quizId): JsonResponse
    {
        $quiz = $this->authorizedQuiz($request, $quizId);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }

        $data = $this->validatedQuestion($request);

        $question = DB::transaction(function () use ($quiz, $data) {
            $lockedQuiz = Quiz::whereKey($quiz->id)->lockForUpdate()->firstOrFail();
            $this->preserveAttemptSnapshotsBeforeQuestionChange($lockedQuiz);
            $next = (int) QuizQuestion::where('quiz_id', $lockedQuiz->id)->max('question_order') + 1;

            return QuizQuestion::create($data + ['quiz_id' => $lockedQuiz->id, 'question_order' => $next]);
        });

        $this->syncTotal($quiz->fresh());

        return response()->json([
            'success' => true,
            'message' => 'Question added to quiz successfully.',
            'data' => $this->formatQuestion($question->makeVisible(['correct_answer', 'grading_guidance'])),
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

        $data = $this->validatedQuestion($request);

        DB::transaction(function () use ($quiz, $question, $data) {
            $lockedQuiz = Quiz::whereKey($quiz->id)->lockForUpdate()->firstOrFail();
            $this->preserveAttemptSnapshotsBeforeQuestionChange($lockedQuiz);
            $question->update($data);
        });

        $this->syncTotal($quiz->fresh());

        return response()->json([
            'success' => true,
            'message' => 'Question updated successfully.',
            'data' => $this->formatQuestion($question->fresh()->makeVisible(['correct_answer', 'grading_guidance'])),
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

        DB::transaction(function () use ($question, $quiz) {
            $lockedQuiz = Quiz::whereKey($quiz->id)->lockForUpdate()->firstOrFail();
            $hasHistory = QuizAttempt::where('quiz_id', $lockedQuiz->id)->exists()
                || QuizAnswer::where('question_id', $question->id)->exists();

            $this->preserveAttemptSnapshotsBeforeQuestionChange($lockedQuiz);

            if ($hasHistory) {
                $question->delete();
            } else {
                $question->forceDelete();
            }

            $this->resequence($lockedQuiz->id, QuizQuestion::where('quiz_id', $lockedQuiz->id)->orderBy('question_order')->pluck('id')->all());
        });
        $this->syncTotal($quiz->fresh());

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

        $copy = DB::transaction(function () use ($question, $quiz) {
            $lockedQuiz = Quiz::whereKey($quiz->id)->lockForUpdate()->firstOrFail();
            $this->preserveAttemptSnapshotsBeforeQuestionChange($lockedQuiz);
            $next = (int) QuizQuestion::where('quiz_id', $lockedQuiz->id)->max('question_order') + 1;

            return QuizQuestion::create(array_merge(
                $question->makeVisible(['correct_answer', 'grading_guidance'])->only([
                    'quiz_id', 'question_text', 'question_type', 'marks', 'is_required',
                    'option_a', 'option_b', 'option_c', 'option_d', 'correct_answer', 'grading_guidance',
                ]),
                ['question_order' => $next]
            ));
        });
        $this->syncTotal($quiz->fresh());

        return response()->json([
            'success' => true,
            'message' => 'Question duplicated successfully.',
            'data' => $this->formatQuestion($copy->makeVisible(['correct_answer', 'grading_guidance'])),
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

        $data = $request->validate(['order' => 'required|array|min:1', 'order.*' => 'integer']);
        $existing = QuizQuestion::where('quiz_id', $quiz->id)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $requested = array_map('intval', $data['order']);

        if (count($requested) !== count($existing) || array_diff($requested, $existing) || array_diff($existing, $requested)) {
            throw ValidationException::withMessages(['order' => 'The order must list every active question of this quiz exactly once.']);
        }

        DB::transaction(function () use ($quiz, $requested) {
            $lockedQuiz = Quiz::whereKey($quiz->id)->lockForUpdate()->firstOrFail();
            $this->preserveAttemptSnapshotsBeforeQuestionChange($lockedQuiz);
            $this->resequence($lockedQuiz->id, $requested);
        });

        return response()->json(['success' => true, 'message' => 'Questions reordered successfully.', 'data' => $this->quizPayload($quiz->fresh())]);
    }

    /** Two-phase update on active questions cleanly preserves logical 1..N ordering. */
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

        $questions = QuizQuestion::where('quiz_id', $quiz->id)->orderBy('question_order')->get()->each->makeVisible(['correct_answer', 'grading_guidance']);
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
                    // All short_answer questions are graded manually by the teacher.
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
            'version' => (int) ($quiz->version ?: 1),
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
            'is_required' => 'nullable|boolean',
            'option_a' => 'nullable|string|max:500',
            'option_b' => 'nullable|string|max:500',
            'option_c' => 'nullable|string|max:500',
            'option_d' => 'nullable|string|max:500',
            'correct_answer' => [Rule::requiredIf($type !== 'short_answer'), 'nullable', 'string', 'max:500'],
            'grading_guidance' => 'nullable|string|max:2000',
        ], [], ['marks' => 'marks']);

        $data['question_text'] = trim($data['question_text']);
        $data['is_required'] = array_key_exists('is_required', $data) ? (bool) $data['is_required'] : true;
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
            $data['grading_guidance'] = isset($data['grading_guidance']) && trim((string) $data['grading_guidance']) !== ''
                ? trim((string) $data['grading_guidance'])
                : null;
        } elseif ($type === 'true_false') {
            $correct = strtolower(trim((string) $data['correct_answer']));
            if (! in_array($correct, ['true', 'false'], true)) {
                throw ValidationException::withMessages(['correct_answer' => 'The correct answer must be True or False.']);
            }
            $data['correct_answer'] = $correct;
            $data['option_a'] = $data['option_b'] = $data['option_c'] = $data['option_d'] = null;
            $data['grading_guidance'] = isset($data['grading_guidance']) && trim((string) $data['grading_guidance']) !== ''
                ? trim((string) $data['grading_guidance'])
                : null;
        } else {
            $data['option_a'] = $data['option_b'] = $data['option_c'] = $data['option_d'] = null;
            $guidance = isset($data['grading_guidance']) && trim((string) $data['grading_guidance']) !== ''
                ? trim((string) $data['grading_guidance'])
                : null;
            $expected = isset($data['correct_answer']) && trim((string) $data['correct_answer']) !== ''
                ? trim((string) $data['correct_answer'])
                : null;
            $effective = $guidance ?? $expected;
            $data['grading_guidance'] = $effective;
            $data['correct_answer'] = $expected ?? ($effective !== null ? mb_substr($effective, 0, 500) : null);
        }

        return $data;
    }

    /* ================================================================== */
    /* Quiz attempts, Short Answer manual grading, and Overrides          */
    /* ================================================================== */

    /** GET /api/teacher/quizzes/{quizId}/attempts */
    public function quizAttempts(Request $request, int $quizId): JsonResponse
    {
        $quiz = $this->authorizedQuiz($request, $quizId);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }

        $allAttempts = QuizAttempt::with('student.user')
            ->where('quiz_id', $quiz->id)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();

        $byStudent = $allAttempts->groupBy('student_id');
        $defaultMax = (float) (QuizQuestion::where('quiz_id', $quiz->id)->sum('marks') ?: $quiz->total_score);

        $rows = $allAttempts->map(function (QuizAttempt $a) use ($quiz, $byStudent, $defaultMax) {
            $pendingQCount = QuizAnswer::where('quiz_attempt_id', $a->id)
                ->where(function ($q) {
                    $q->where('grading_status', 'pending_grading')
                        ->orWhere(fn ($sq) => $sq->whereNull('is_correct')->whereNull('marks_obtained'));
                })
                ->count();
            $pending = $a->isPendingManualGrading() || $pendingQCount > 0;
            $effectiveStudentScore = $this->grading->calculateStudentQuizScore($quiz, $byStudent->get($a->student_id, collect()));

            return [
                'id' => $a->id,
                'student_id' => $a->student_id,
                'student_code' => $a->student?->student_code,
                'student_name' => $a->student?->user?->name ?? $a->student?->english_name,
                'attempt_number' => $a->attempt_number,
                'status' => $a->status,
                'grading_status' => $pending ? 'pending_grading' : ($a->grading_status ?: ($a->isFinalized() ? 'graded' : 'not_submitted')),
                'score' => $pending ? null : $a->score,
                'auto_graded_score' => $a->auto_graded_score,
                'manual_graded_score' => $a->manual_graded_score,
                'total_marks' => (float) ($a->total_marks_snapshot ?: $defaultMax),
                'effective_student_score' => $effectiveStudentScore,
                'started_at' => $a->started_at,
                'submitted_at' => $a->submitted_at,
                'graded_at' => $a->graded_at,
                'is_late' => (bool) $a->is_late,
                'is_reset' => (bool) $a->is_reset,
                'pending_manual_grading' => $pending,
                'pending_questions_count' => $pendingQCount,
            ];
        })->values();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /** GET /api/teacher/quiz-attempts/{attemptId} */
    public function showQuizAttempt(Request $request, int $attemptId): JsonResponse
    {
        $attempt = QuizAttempt::with(['quiz', 'student.user', 'grader'])->findOrFail($attemptId);
        if (! $attempt->quiz || ! $this->access->teacherCanManage($this->teacher($request), $attempt->quiz)) {
            return $this->forbidden('Unauthorized to view this quiz attempt.');
        }

        $questions = $this->presentAttemptQuestionsForTeacher($attempt);
        $studentAttempts = QuizAttempt::where('quiz_id', $attempt->quiz_id)
            ->where('student_id', $attempt->student_id)
            ->get();
        $effectiveScore = $this->grading->calculateStudentQuizScore($attempt->quiz, $studentAttempts);
        $pendingCount = collect($questions)->where('needs_manual_grading', true)->count();
        $pending = $attempt->isPendingManualGrading() || $pendingCount > 0;

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $attempt->id,
                'quiz_id' => $attempt->quiz_id,
                'quiz_title' => $attempt->quiz->title,
                'scoring_method' => $attempt->quiz->scoring_method ?: 'highest',
                'student_id' => $attempt->student_id,
                'student_code' => $attempt->student?->student_code,
                'student_name' => $attempt->student?->user?->name ?? $attempt->student?->english_name,
                'attempt_number' => $attempt->attempt_number,
                'status' => $attempt->status,
                'grading_status' => $pending ? 'pending_grading' : ($attempt->grading_status ?: 'auto_graded'),
                'score' => $pending ? null : $attempt->score,
                'auto_graded_score' => $attempt->auto_graded_score,
                'manual_graded_score' => $attempt->manual_graded_score,
                'effective_student_score' => $effectiveScore,
                'total_marks' => (float) ($attempt->total_marks_snapshot ?: collect($questions)->sum('marks')),
                'started_at' => $attempt->started_at,
                'submitted_at' => $attempt->submitted_at,
                'graded_at' => $attempt->graded_at,
                'graded_by_name' => $attempt->grader?->name,
                'is_late' => (bool) $attempt->is_late,
                'is_reset' => (bool) $attempt->is_reset,
                'pending_manual_grading' => $pending,
                'pending_questions_count' => $pendingCount,
                'questions' => $questions,
            ],
        ]);
    }

    /**
     * Build question-by-question grading payload for a single attempt.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function presentAttemptQuestionsForTeacher(QuizAttempt $attempt): array
    {
        $answers = QuizAnswer::with('grader')->where('quiz_attempt_id', $attempt->id)->get()->keyBy('question_id');
        $snapshots = $this->grading->resolveAttemptQuestions($attempt);

        return collect($snapshots)->map(function (array $q) use ($answers) {
            $qId = (int) ($q['id'] ?? 0);
            $a = $answers->get($qId);
            $qType = strtolower((string) ($q['question_type'] ?? 'multiple_choice'));
            $isPending = $a !== null && ($a->grading_status === 'pending_grading' || ($qType === 'short_answer' && $a->is_correct === null && $a->marks_obtained === null));

            return [
                'question_id' => $qId,
                'answer_id' => $a?->id,
                'number' => (int) ($q['question_order'] ?? 1),
                'question_text' => (string) ($q['question_text'] ?? ''),
                'question_type' => $qType,
                'marks' => (float) ($q['marks'] ?? 1),
                'is_required' => array_key_exists('is_required', $q) ? (bool) $q['is_required'] : true,
                'correct_answer' => $q['correct_answer'] ?? null,
                'grading_guidance' => $q['grading_guidance'] ?? ($q['correct_answer'] ?? null),
                'student_answer' => $a?->answer,
                'grading_status' => $a ? ($a->grading_status ?: ($isPending ? 'pending_grading' : 'auto_graded')) : 'unanswered',
                'is_correct' => $isPending ? null : $a?->is_correct,
                'marks_obtained' => $isPending ? null : $a?->marks_obtained,
                'teacher_feedback' => $a?->teacher_feedback,
                'graded_by' => $a?->graded_by,
                'graded_by_name' => $a?->grader?->name,
                'graded_at' => $a?->graded_at,
                'needs_manual_grading' => $isPending,
            ];
        })->values()->all();
    }

    /** PATCH /api/teacher/quiz-answers/{answerId}/grade  body: { marks_obtained, feedback? } */
    public function gradeAnswer(Request $request, int $answerId): JsonResponse
    {
        $answer = QuizAnswer::with('question', 'attempt.quiz')->findOrFail($answerId);
        $quiz = $answer->attempt?->quiz;
        if (! $quiz || ! $this->access->teacherCanManage($this->teacher($request), $quiz)) {
            return $this->forbidden('Unauthorized to grade this answer.');
        }

        $max = (float) ($answer->question_snapshot['marks'] ?? $answer->question?->marks ?? 0);
        $data = $request->validate([
            'marks_obtained' => "required|numeric|min:0|max:$max",
            'feedback' => 'nullable|string|max:2000',
            'teacher_feedback' => 'nullable|string|max:2000',
        ]);

        $feedback = array_key_exists('teacher_feedback', $data)
            ? $data['teacher_feedback']
            : ($data['feedback'] ?? null);

        $gradedAnswer = $this->grading->gradeAnswer($answer, (float) $data['marks_obtained'], $feedback, (int) $request->user()->id);
        $attempt = $gradedAnswer->attempt->fresh();
        $studentAttempts = QuizAttempt::where('quiz_id', $quiz->id)->where('student_id', $attempt->student_id)->get();

        return response()->json([
            'success' => true,
            'message' => 'Answer graded successfully.',
            'data' => [
                'answer_id' => $gradedAnswer->id,
                'marks_obtained' => $gradedAnswer->marks_obtained,
                'grading_status' => $gradedAnswer->grading_status,
                'teacher_feedback' => $gradedAnswer->teacher_feedback,
                'graded_at' => $gradedAnswer->graded_at,
                'attempt_score' => $attempt->isPendingManualGrading() ? null : $attempt->score,
                'auto_graded_score' => $attempt->auto_graded_score,
                'manual_graded_score' => $attempt->manual_graded_score,
                'attempt_status' => $attempt->status,
                'attempt_grading_status' => $attempt->grading_status,
                'effective_student_score' => $this->grading->calculateStudentQuizScore($quiz, $studentAttempts),
            ],
        ]);
    }

    /** POST /api/teacher/quiz-attempts/{attemptId}/grade */
    public function gradeAttemptAnswers(Request $request, int $attemptId): JsonResponse
    {
        $attempt = QuizAttempt::with('quiz')->findOrFail($attemptId);
        if (! $attempt->quiz || ! $this->access->teacherCanManage($this->teacher($request), $attempt->quiz)) {
            return $this->forbidden('Unauthorized to grade this quiz attempt.');
        }
        if ($attempt->status === 'in_progress') {
            return response()->json(['success' => false, 'message' => 'Cannot grade an attempt that has not been submitted yet.'], 422);
        }

        $items = $request->has('grades')
            ? $request->validate([
                'grades' => 'required|array|min:1',
                'grades.*.answer_id' => 'nullable|integer',
                'grades.*.question_id' => 'nullable|integer',
                'grades.*.marks_obtained' => 'required|numeric|min:0',
                'grades.*.feedback' => 'nullable|string|max:2000',
                'grades.*.teacher_feedback' => 'nullable|string|max:2000',
            ])['grades']
            : [$request->validate([
                'answer_id' => 'nullable|integer',
                'question_id' => 'nullable|integer',
                'marks_obtained' => 'required|numeric|min:0',
                'feedback' => 'nullable|string|max:2000',
                'teacher_feedback' => 'nullable|string|max:2000',
            ])];

        $snapshotsById = collect($this->grading->resolveAttemptQuestions($attempt))
            ->keyBy(fn ($q) => (int) ($q['id'] ?? 0));

        DB::transaction(function () use ($attempt, $items, $snapshotsById, $request) {
            foreach ($items as $item) {
                $answer = null;
                if (! empty($item['answer_id'])) {
                    $answer = QuizAnswer::where('quiz_attempt_id', $attempt->id)
                        ->whereKey((int) $item['answer_id'])
                        ->firstOrFail();
                } elseif (! empty($item['question_id'])) {
                    $qId = (int) $item['question_id'];
                    if (! $snapshotsById->has($qId)) {
                        throw ValidationException::withMessages(['question_id' => ["Question {$qId} does not belong to this attempt."]]);
                    }
                    $answer = QuizAnswer::firstOrCreate(
                        ['quiz_attempt_id' => $attempt->id, 'question_id' => $qId],
                        [
                            'answer' => '',
                            'grading_status' => 'pending_grading',
                            'question_snapshot' => $snapshotsById->get($qId),
                        ]
                    );
                } else {
                    throw ValidationException::withMessages(['grades' => ['Each grading item must specify answer_id or question_id.']]);
                }

                $feedback = array_key_exists('teacher_feedback', $item)
                    ? $item['teacher_feedback']
                    : ($item['feedback'] ?? null);

                $this->grading->gradeAnswer($answer, (float) $item['marks_obtained'], $feedback, (int) $request->user()->id);
            }
        });

        return $this->showQuizAttempt($request, $attemptId);
    }

    /** Resolve enrolled students for a quiz's offering (plus any with existing attempts or overrides). */
    protected function eligibleStudentIdsForQuiz(Quiz $quiz): array
    {
        $enrolledQuery = \App\Models\CourseEnrollment::query();
        if ($quiz->course_offering_id) {
            $enrolledQuery->where(function ($q) use ($quiz) {
                $q->where('course_offering_id', $quiz->course_offering_id)
                    ->orWhere(function ($sq) use ($quiz) {
                        $sq->whereNull('course_offering_id')
                            ->where('course_id', $quiz->course_id)
                            ->where('semester_id', $quiz->semester_id);
                    });
            });
        } else {
            $enrolledQuery->where('course_id', $quiz->course_id)
                ->where('semester_id', $quiz->semester_id);
        }

        $enrolledIds = $enrolledQuery->where('status', 'enrolled')->pluck('student_id')->all();
        $attemptIds = QuizAttempt::where('quiz_id', $quiz->id)->pluck('student_id')->all();
        $overrideIds = QuizStudentOverride::where('quiz_id', $quiz->id)->pluck('student_id')->all();

        return collect(array_merge($enrolledIds, $attemptIds, $overrideIds))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** GET /api/teacher/quizzes/{quizId}/students */
    public function quizStudents(Request $request, int $quizId): JsonResponse
    {
        $quiz = $this->authorizedQuiz($request, $quizId);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }

        $studentIds = $this->eligibleStudentIdsForQuiz($quiz);
        $students = Student::with('user')->whereIn('id', $studentIds ?: [0])->orderBy('english_name')->orderBy('first_name')->get();

        $rows = $students->map(function (Student $st) use ($quiz) {
            $access = $this->availability->evaluateStudentAccess($quiz, $st);
            $effectiveScore = $this->grading->calculateStudentQuizScore($quiz, $access['attempts']);
            $latest = $access['active_attempts']->first() ?? $access['attempts']->first();
            $override = $access['override'];

            return [
                'student_id' => $st->id,
                'student_code' => $st->student_code,
                'name' => $st->user?->name ?? $st->english_name ?? trim(($st->first_name ?? '').' '.($st->last_name ?? '')),
                'email' => $st->user?->email,
                'attempts_used' => $access['attempts_used'],
                'max_attempts' => $access['max_attempts'],
                'attempts_remaining' => $access['attempts_remaining'],
                'effective_score' => $effectiveScore,
                'latest_attempt_status' => $latest?->status,
                'pending_manual_grading' => $access['active_attempts']->contains(fn (QuizAttempt $a) => $a->isPendingManualGrading()),
                'has_override' => (bool) $override,
                'override' => $override ? [
                    'id' => $override->id,
                    'extra_attempts' => (int) $override->extra_attempts,
                    'extended_end_at' => $override->extended_end_at,
                    'allow_late_submission' => (bool) $override->allow_late_submission,
                    'attempts_reset_at' => $override->attempts_reset_at,
                    'reason' => $override->reason,
                ] : null,
            ];
        })->values();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /** GET /api/teacher/quizzes/{quizId}/overrides */
    public function quizOverrides(Request $request, int $quizId): JsonResponse
    {
        $quiz = $this->authorizedQuiz($request, $quizId);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }

        $overrides = QuizStudentOverride::with(['student.user', 'grantor'])
            ->where('quiz_id', $quiz->id)
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (QuizStudentOverride $ov) => [
                'id' => $ov->id,
                'quiz_id' => $ov->quiz_id,
                'student_id' => $ov->student_id,
                'student_code' => $ov->student?->student_code,
                'student_name' => $ov->student?->user?->name ?? $ov->student?->english_name,
                'extra_attempts' => (int) $ov->extra_attempts,
                'extended_end_at' => $ov->extended_end_at,
                'allow_late_submission' => (bool) $ov->allow_late_submission,
                'attempts_reset_at' => $ov->attempts_reset_at,
                'reason' => $ov->reason,
                'is_active' => (bool) $ov->is_active,
                'granted_by_name' => $ov->grantor?->name,
                'updated_at' => $ov->updated_at,
            ])
            ->values();

        return response()->json(['success' => true, 'data' => $overrides]);
    }

    /** POST /api/teacher/quizzes/{quizId}/overrides */
    public function storeQuizOverride(Request $request, int $quizId): JsonResponse
    {
        $quiz = $this->authorizedQuiz($request, $quizId);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }

        $data = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'grant_extra_attempts' => 'nullable|integer|min:1|max:50',
            'extra_attempts' => 'nullable|integer|min:0|max:50',
            'extended_end_at' => 'nullable|date',
            'allow_late_submission' => 'nullable|boolean',
            'reset_attempts' => 'nullable|boolean',
            'reason' => 'nullable|string|max:1000',
        ]);

        $student = Student::findOrFail((int) $data['student_id']);
        $eligibleIds = $this->eligibleStudentIdsForQuiz($quiz);
        if (! in_array($student->id, $eligibleIds, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Student is not enrolled in this quiz\'s course offering.',
            ], 422);
        }

        if (
            empty($data['grant_extra_attempts'])
            && ! array_key_exists('extra_attempts', $data)
            && ! array_key_exists('extended_end_at', $data)
            && ! array_key_exists('allow_late_submission', $data)
            && empty($data['reset_attempts'])
        ) {
            throw ValidationException::withMessages([
                'override' => ['Specify at least one override action (extra attempt, deadline extension, late submission, or reset attempts).'],
            ]);
        }

        if (! empty($data['extended_end_at']) && $quiz->start_at && Carbon::parse((string) $data['extended_end_at'])->lt($quiz->start_at)) {
            throw ValidationException::withMessages(['extended_end_at' => 'Extended deadline must be after quiz start time.']);
        }

        $override = $this->availability->applyStudentOverride(
            $quiz,
            $student,
            $this->teacher($request),
            $request->user(),
            $data
        );

        return response()->json([
            'success' => true,
            'message' => 'Student quiz permission saved.',
            'data' => $override->load(['student.user', 'grantor']),
        ], 201);
    }

    /** DELETE /api/teacher/quizzes/{quizId}/overrides/{studentId} */
    public function revokeQuizOverride(Request $request, int $quizId, int $studentId): JsonResponse
    {
        $quiz = $this->authorizedQuiz($request, $quizId);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }

        $student = Student::findOrFail($studentId);
        $revoked = $this->availability->revokeStudentOverride(
            $quiz,
            $student,
            $this->teacher($request),
            $request->user(),
            $request->input('reason')
        );

        if (! $revoked) {
            return response()->json(['success' => false, 'message' => 'No active override found for this student.'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Student override revoked.']);
    }

    /** GET /api/teacher/quizzes/{quizId}/audit-logs */
    public function quizAuditLogs(Request $request, int $quizId): JsonResponse
    {
        $quiz = $this->authorizedQuiz($request, $quizId);
        if ($quiz instanceof JsonResponse) {
            return $quiz;
        }

        $logs = QuizOverrideLog::with(['student.user', 'actor'])
            ->where('quiz_id', $quiz->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (QuizOverrideLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'reason' => $log->reason,
                'student_id' => $log->student_id,
                'student_name' => $log->student?->user?->name ?? $log->student?->english_name,
                'acted_by' => $log->acted_by,
                'acted_by_name' => $log->actor?->name,
                'previous_setting' => $log->previous_setting,
                'new_setting' => $log->new_setting,
                'created_at' => $log->created_at,
            ])
            ->values();

        return response()->json(['success' => true, 'data' => $logs]);
    }
}
