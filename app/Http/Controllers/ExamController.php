<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Services\AssessmentAccessService;
use App\Services\TeacherAuthorizationService;
use Illuminate\Http\Request;

class ExamController extends Controller
{
    use \App\Http\Controllers\Concerns\ScopesAssessmentVisibility;

    public function index(Request $request)
    {
        $query = $this->scopeVisible(Exam::with(['course', 'teacher', 'semester']), $request, 'exam');

        if ($request->has('course_id')) {
            $query->where('course_id', $request->course_id);
        }

        if ($request->has('teacher_id')) {
            $query->where('teacher_id', $request->teacher_id);
        }

        if ($request->has('semester_id')) {
            $query->where('semester_id', $request->semester_id);
        }

        $exams = $query->latest()->get()->map(function (Exam $exam) use ($request) {
            $exam->is_published = $exam->isResultsPublished();
            $exam->display_status = $exam->displayStatus();
            if ($request->user() && $request->user()->role === 'student') {
                $studentId = $request->user()->student?->id;
                if ($exam->isResultsPublished() && $studentId) {
                    $exam->load(['results' => fn ($q) => $q->where('student_id', $studentId)]);
                } else {
                    $exam->setRelation('results', collect());
                }
            }
            return $exam;
        });

        return response()->json([
            'message' => 'Exams retrieved successfully.',
            'data' => $exams,
        ], 200);
    }

    public function store(Request $request)
    {
        if ($request->has('start_time')) {
            $request->merge(['start_time' => Exam::normalizeTime($request->input('start_time'))]);
        }
        if ($request->has('end_time')) {
            $request->merge(['end_time' => Exam::normalizeTime($request->input('end_time'))]);
        }

        $validated = $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
            'teacher_id' => 'required|integer|exists:teachers,id',
            'semester_id' => 'required|integer|exists:semesters,id',
            'course_offering_id' => 'nullable|integer|exists:course_offerings,id',
            'title' => 'required|string|max:200',
            'exam_type' => 'required|string|in:midterm,final,quiz,assignment',
            'exam_date' => 'required|date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i|after:start_time',
            'total_score' => 'required|numeric|min:0',
            'duration_minutes' => 'nullable|integer|min:1',
            'venue' => 'nullable|string|max:150',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:draft,scheduled,ongoing,results_pending,completed,published,cancelled',
        ]);

        $user = $request->user();
        if ($user && $user->role === 'teacher') {
            $teacher = app(TeacherAuthorizationService::class)->resolveTeacher($user);
            $authorized = $teacher && (
                (! empty($validated['course_offering_id']) && app(TeacherAuthorizationService::class)->isOfferingAuthorized($teacher, (int) $validated['course_offering_id']))
                || (empty($validated['course_offering_id']) && app(TeacherAuthorizationService::class)->isCourseAuthorized($teacher, (int) $validated['course_id']))
            );
            if (! $authorized) {
                return response()->json(['message' => 'Unauthorized to create an exam for this course or class.'], 403);
            }
            $validated['teacher_id'] = $teacher->id;
        }

        $duration = $validated['duration_minutes'] ?? 120;
        $endTime = $validated['end_time'] ?? (! empty($validated['start_time']) ? Exam::computeEndTime($validated['start_time'], (int) $duration) : null);

        $status = $validated['status'] ?? 'scheduled';
        $exam = Exam::create([
            'course_offering_id' => $validated['course_offering_id'] ?? null,
            'course_id' => $validated['course_id'],
            'teacher_id' => $validated['teacher_id'],
            'semester_id' => $validated['semester_id'],
            'title' => $validated['title'],
            'exam_type' => $validated['exam_type'],
            'exam_date' => $validated['exam_date'],
            'start_time' => $validated['start_time'] ?? null,
            'end_time' => $endTime,
            'total_score' => $validated['total_score'],
            'duration_minutes' => $duration,
            'venue' => $validated['venue'] ?? null,
            'description' => $validated['description'] ?? null,
            'status' => $status,
            'results_published_at' => $status === 'published' ? now() : null,
        ]);
        $exam->is_published = $exam->isResultsPublished();
        $exam->display_status = $exam->displayStatus(0);

        return response()->json([
            'message' => 'Exam created successfully.',
            'data' => $exam->load(['course', 'teacher', 'semester']),
        ], 201);
    }

    public function show(Request $request, Exam $exam)
    {
        $this->authorizeVisible($exam, $request, 'exam');
        if ($request->user()->role === 'student') {
            $studentId = $request->user()->student?->id;
            if ($exam->isResultsPublished() && $studentId) {
                $exam->load(['course', 'teacher', 'semester', 'results' => fn ($q) => $q->where('student_id', $studentId)]);
            } else {
                $exam->load(['course', 'teacher', 'semester']);
                $exam->setRelation('results', collect());
            }
        } else {
            $exam->load(['course', 'teacher', 'semester', 'results.student.user']);
        }
        $exam->is_published = $exam->isResultsPublished();
        $exam->display_status = $exam->displayStatus();

        return response()->json([
            'message' => 'Exam retrieved successfully.',
            'data' => $exam,
        ], 200);
    }

    public function update(Request $request, Exam $exam)
    {
        $user = $request->user();
        if ($user && $user->role === 'teacher') {
            $teacher = app(TeacherAuthorizationService::class)->resolveTeacher($user);
            if (! $teacher || ! app(AssessmentAccessService::class)->teacherCanManage($teacher, $exam)) {
                return response()->json(['message' => 'Unauthorized to modify this exam.'], 403);
            }
        }

        if ($request->has('start_time')) {
            $request->merge(['start_time' => Exam::normalizeTime($request->input('start_time'))]);
        }
        if ($request->has('end_time')) {
            $request->merge(['end_time' => Exam::normalizeTime($request->input('end_time'))]);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'exam_type' => 'required|string|in:midterm,final,quiz,assignment',
            'exam_date' => 'required|date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'total_score' => 'required|numeric|min:0',
            'duration_minutes' => 'nullable|integer|min:1',
            'venue' => 'nullable|string|max:150',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:draft,scheduled,ongoing,results_pending,completed,published,cancelled',
        ]);

        if (isset($validated['status'])) {
            if ($validated['status'] === 'published' && ! $exam->results_published_at) {
                $validated['results_published_at'] = now();
            } elseif (in_array($validated['status'], ['draft', 'scheduled', 'results_pending', 'cancelled'], true)) {
                $validated['results_published_at'] = null;
            }
        }

        $effectiveStart = array_key_exists('start_time', $validated) ? $validated['start_time'] : $exam->start_time;
        $effectiveDuration = array_key_exists('duration_minutes', $validated) ? $validated['duration_minutes'] : $exam->duration_minutes;
        if (! empty($validated['end_time']) && $effectiveStart !== null && $validated['end_time'] <= $effectiveStart) {
            return response()->json(['message' => 'End time must be after start time.'], 422);
        }
        if ($effectiveStart !== null && $effectiveDuration !== null && empty($validated['end_time'])) {
            $validated['end_time'] = Exam::computeEndTime($effectiveStart, (int) $effectiveDuration);
        }

        $exam->update($validated);
        $fresh = $exam->fresh()->load(['course', 'teacher', 'semester']);
        $fresh->is_published = $fresh->isResultsPublished();
        $fresh->display_status = $fresh->displayStatus();

        return response()->json([
            'message' => 'Exam updated successfully.',
            'data' => $fresh,
        ], 200);
    }

    public function destroy(Request $request, Exam $exam)
    {
        $user = $request->user();
        if ($user && $user->role === 'teacher') {
            $teacher = app(TeacherAuthorizationService::class)->resolveTeacher($user);
            if (! $teacher || ! app(AssessmentAccessService::class)->teacherCanManage($teacher, $exam)) {
                return response()->json(['message' => 'Unauthorized to delete this exam.'], 403);
            }
        }

        $exam->delete();

        return response()->json([
            'message' => 'Exam deleted successfully.',
        ], 200);
    }
}
