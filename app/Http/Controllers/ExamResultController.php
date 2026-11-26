<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamResult;
use App\Services\AssessmentAccessService;
use App\Services\TeacherAuthorizationService;
use Illuminate\Http\Request;

class ExamResultController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = ExamResult::with(['exam.course', 'student.user']);

        if ($user && $user->role === 'student') {
            $studentId = $user->student?->id;
            if (! $studentId) {
                return response()->json(['message' => 'Student profile not found.'], 404);
            }
            $query->where('student_id', $studentId)
                  ->whereHas('exam', fn ($q) => $q->whereIn('status', ['published', 'completed'])->orWhereNotNull('results_published_at'));
        } elseif ($user && $user->role === 'teacher') {
            $teacher = $user->teacher;
            if ($teacher) {
                $offeringIds = app(TeacherAuthorizationService::class)->getAuthorizedOfferingsQuery($teacher)->pluck('id');
                $query->whereHas('exam', fn ($q) => $q->whereIn('course_offering_id', $offeringIds)->orWhere('teacher_id', $teacher->id));
            }
        }

        if ($request->has('exam_id')) {
            $query->where('exam_id', $request->exam_id);
        }

        if ($request->has('student_id') && (! $user || $user->role !== 'student')) {
            $query->where('student_id', $request->student_id);
        }

        $results = $query->latest()->get();

        return response()->json([
            'message' => 'Exam results retrieved successfully.',
            'data' => $results,
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'exam_id' => 'required|integer|exists:exams,id',
            'student_id' => 'required|integer|exists:students,id',
            'score' => 'required|numeric|min:0',
            'grade' => 'nullable|string|max:10',
            'remarks' => 'nullable|string|max:255',
        ]);

        $exam = Exam::findOrFail($validated['exam_id']);
        $maxScore = (float) ($exam->total_score ?: 100);

        if ((float) $validated['score'] > $maxScore) {
            return response()->json([
                'message' => "Score cannot exceed exam total score of {$maxScore}.",
                'errors' => ['score' => ["Score cannot exceed exam total score of {$maxScore}."]],
            ], 422);
        }

        $user = $request->user();
        if ($user && $user->role === 'teacher') {
            $teacher = $user->teacher;
            if (! $teacher || ! app(AssessmentAccessService::class)->teacherCanManage($teacher, $exam)) {
                return response()->json(['message' => 'Unauthorized to record results for this exam.'], 403);
            }
        }

        $isEnrolled = \App\Models\CourseEnrollment::where('student_id', $validated['student_id'])
            ->when(
                $exam->course_offering_id,
                fn ($q) => $q->where(function ($sub) use ($exam) {
                    $sub->where('course_offering_id', $exam->course_offering_id)
                        ->orWhere(function ($legacy) use ($exam) {
                            $legacy->whereNull('course_offering_id')
                                   ->where('course_id', $exam->course_id)
                                   ->where('semester_id', $exam->semester_id);
                        });
                }),
                fn ($q) => $q->where('course_id', $exam->course_id)
                             ->where('semester_id', $exam->semester_id)
            )
            ->exists();

        if (! $isEnrolled) {
            return response()->json([
                'message' => 'Student is not enrolled in this exam\'s class or course.',
            ], 422);
        }

        $score = (float) $validated['score'];
        $grade = ! empty($validated['grade']) ? $validated['grade'] : Exam::calculateLetterGrade($score, $maxScore);

        $result = ExamResult::updateOrCreate(
            [
                'exam_id' => $validated['exam_id'],
                'student_id' => $validated['student_id'],
            ],
            [
                'score' => $score,
                'grade' => $grade,
                'remarks' => $validated['remarks'] ?? null,
            ]
        );

        return response()->json([
            'message' => 'Exam result recorded successfully.',
            'data' => $result->load(['exam', 'student']),
        ], 201);
    }

    public function show(Request $request, ExamResult $examResult)
    {
        $user = $request->user();
        if ($user && $user->role === 'student') {
            if ($examResult->student_id !== $user->student?->id || ! $examResult->exam?->isResultsPublished()) {
                return response()->json(['message' => 'Record not found.'], 404);
            }
        } elseif ($user && $user->role === 'teacher') {
            $teacher = $user->teacher;
            if (! $teacher || ! app(AssessmentAccessService::class)->teacherCanManage($teacher, $examResult->exam)) {
                return response()->json(['message' => 'Unauthorized.'], 403);
            }
        }

        return response()->json([
            'message' => 'Exam result retrieved successfully.',
            'data' => $examResult->load(['exam.course', 'student.user']),
        ], 200);
    }

    public function update(Request $request, ExamResult $examResult)
    {
        $exam = $examResult->exam;
        $maxScore = (float) ($exam->total_score ?: 100);

        $validated = $request->validate([
            'score' => "required|numeric|min:0|max:{$maxScore}",
            'grade' => 'nullable|string|max:10',
            'remarks' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        if ($user && $user->role === 'teacher') {
            $teacher = $user->teacher;
            if (! $teacher || ! app(AssessmentAccessService::class)->teacherCanManage($teacher, $exam)) {
                return response()->json(['message' => 'Unauthorized to modify results for this exam.'], 403);
            }
        }

        $score = (float) $validated['score'];
        $grade = ! empty($validated['grade']) ? $validated['grade'] : $this->calculateGrade($score, $maxScore);

        $examResult->update([
            'score' => $score,
            'grade' => $grade,
            'remarks' => $validated['remarks'] ?? null,
        ]);

        return response()->json([
            'message' => 'Exam result updated successfully.',
            'data' => $examResult->fresh()->load(['exam', 'student']),
        ], 200);
    }

    public function destroy(Request $request, ExamResult $examResult)
    {
        $user = $request->user();
        if ($user && $user->role === 'teacher') {
            $teacher = $user->teacher;
            if (! $teacher || ! app(AssessmentAccessService::class)->teacherCanManage($teacher, $examResult->exam)) {
                return response()->json(['message' => 'Unauthorized to delete results for this exam.'], 403);
            }
        }

        $examResult->delete();

        return response()->json([
            'message' => 'Exam result deleted successfully.',
        ], 200);
    }

    private function calculateGrade(float $score, float $maxScore): string
    {
        if ($maxScore <= 0) return 'N/A';
        $pct = ($score / $maxScore) * 100;
        return match (true) {
            $pct >= 90 => 'A',
            $pct >= 85 => 'A-',
            $pct >= 80 => 'B+',
            $pct >= 75 => 'B',
            $pct >= 70 => 'B-',
            $pct >= 65 => 'C+',
            $pct >= 60 => 'C',
            $pct >= 50 => 'D',
            default => 'F',
        };
    }
}
