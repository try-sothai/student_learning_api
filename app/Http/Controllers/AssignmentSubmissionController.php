<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Services\AssessmentAccessService;
use App\Services\SubmissionFileService;
use App\Support\PersonNameHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class AssignmentSubmissionController extends Controller
{
    public function __construct(
        protected AssessmentAccessService $access,
        protected SubmissionFileService $submissionFiles
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $scope = $this->access->resolveUserScope($user);
        $query = AssignmentSubmission::with(['assignment.course', 'student.user']);

        if ($scope['student']) {
            // A student can only ever list their own submissions, whatever student_id is sent.
            $query->where('student_id', $scope['student']->id);
        } elseif ($scope['teacher']) {
            $query->whereHas('assignment', fn ($q) => $this->access->scopeForTeacher($q, $scope['teacher']));
            if ($request->has('student_id')) {
                $query->where('student_id', $request->student_id);
            }
        } elseif ($request->has('student_id')) {
            $query->where('student_id', $request->student_id);
        }

        if ($request->has('assignment_id')) {
            $query->where('assignment_id', $request->assignment_id);
        }

        return response()->json([
            'message' => 'Assignment submissions retrieved successfully.',
            'data' => $query->latest()->get(),
        ], 200);
    }

    public function store(Request $request)
    {
        $student = $request->user()?->student;
        if (! $student) {
            return response()->json(['message' => 'Only students can submit assignments.'], 403);
        }

        $validated = $request->validate([
            'assignment_id' => 'required|integer|exists:assignments,id',
            'file_path' => 'nullable|string',
            'submission_file' => 'nullable|file|mimes:pdf,doc,docx,zip,png,jpg,jpeg,txt|max:10240',
        ]);

        // The assignment must be published AND belong to a course offering this student is enrolled in.
        $assignment = Assignment::findOrFail($validated['assignment_id']);
        if (! in_array($assignment->status, ['published'], true) || ! $this->access->studentCanAccess($student, $assignment)) {
            return response()->json(['message' => 'Assignment not found or you are not enrolled in this course.'], 404);
        }

        $existing = AssignmentSubmission::where('assignment_id', $assignment->id)->where('student_id', $student->id)->first();
        if ($existing && $existing->status === 'graded') {
            return response()->json(['message' => 'This submission has already been graded and can no longer be changed.'], 409);
        }

        $filePath = $validated['file_path'] ?? null;
        $originalFileName = $existing?->original_file_name;
        $mimeType = $existing?->mime_type;
        $fileSize = $existing?->file_size;

        if ($request->hasFile('submission_file')) {
            $uploaded = $request->file('submission_file');
            $filePath = $uploaded->store('submissions', 'public');
            $originalFileName = $uploaded->getClientOriginalName();
            $mimeType = $uploaded->getClientMimeType() ?: $uploaded->getMimeType();
            $fileSize = $uploaded->getSize();
        }

        $isLate = $assignment->due_date && now()->gt($assignment->due_date);

        $submission = AssignmentSubmission::updateOrCreate(
            ['assignment_id' => $assignment->id, 'student_id' => $student->id],
            [
                'submitted_at' => now(),
                'file_path' => $filePath ?? $existing?->file_path,
                'original_file_name' => $originalFileName,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
                'status' => $isLate ? 'late' : 'submitted',
            ]
        );

        return response()->json([
            'message' => 'Assignment submitted successfully.',
            'data' => $submission->load(['assignment', 'student']),
        ], 201);
    }

    public function show(Request $request, AssignmentSubmission $assignmentSubmission)
    {
        $this->authorizeSubmission($request, $assignmentSubmission);

        return response()->json([
            'message' => 'Assignment submission retrieved successfully.',
            'data' => $assignmentSubmission->load(['assignment.course', 'student.user']),
        ], 200);
    }

    public function update(Request $request, AssignmentSubmission $assignmentSubmission)
    {
        $this->authorizeSubmission($request, $assignmentSubmission, manage: true);

        $validated = $request->validate([
            'score' => 'nullable|numeric|min:0',
            'feedback' => 'nullable|string',
            'status' => 'nullable|string|in:submitted,graded,returned,late',
        ]);

        $assignmentSubmission->update($validated);

        return response()->json([
            'message' => 'Assignment submission updated successfully.',
            'data' => $assignmentSubmission->fresh()->load(['assignment', 'student']),
        ], 200);
    }

    public function destroy(Request $request, AssignmentSubmission $assignmentSubmission)
    {
        $this->authorizeSubmission($request, $assignmentSubmission, manage: true);
        $assignmentSubmission->delete();

        return response()->json([
            'message' => 'Assignment submission deleted successfully.',
        ], 200);
    }

    /**
     * GET /api/student/submissions
     * Lists the authenticated student's own assignment submissions (never trusts query student_id).
     */
    public function mySubmissions(Request $request): JsonResponse
    {
        $student = $request->user()?->student;
        if (! $student) {
            return response()->json([
                'success' => false,
                'message' => 'Student profile not found.',
            ], 404);
        }

        $submissions = AssignmentSubmission::with([
            'assignment.course',
            'assignment.courseOffering.classRoom',
            'student.user',
        ])
            ->where('student_id', $student->id)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AssignmentSubmission $s) => $this->presentStudentSubmission($s))
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Student submissions retrieved successfully.',
            'data' => $submissions,
        ], 200);
    }

    /**
     * GET /api/student/submissions/{assignmentSubmission}
     * Returns detailed submission metadata + file preview info + published grade only.
     */
    public function showStudentSubmission(Request $request, AssignmentSubmission $assignmentSubmission): JsonResponse
    {
        $this->authorizeStudentOwnership($request, $assignmentSubmission);
        $assignmentSubmission->load([
            'assignment.course',
            'assignment.courseOffering.classRoom',
            'student.user',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Submission detail retrieved successfully.',
            'data' => $this->presentStudentSubmission($assignmentSubmission),
        ], 200);
    }

    /**
     * GET /api/student/submissions/{assignmentSubmission}/file
     * Securely streams or downloads the authenticated student's own submitted file.
     */
    public function streamMySubmissionFile(Request $request, AssignmentSubmission $assignmentSubmission): HttpResponse
    {
        $this->authorizeStudentOwnership($request, $assignmentSubmission);

        return $this->submissionFiles->streamFileResponse($assignmentSubmission, $request->boolean('download'));
    }

    private function authorizeStudentOwnership(Request $request, AssignmentSubmission $submission): void
    {
        $student = $request->user()?->student;
        if (! $student || (int) $submission->student_id !== (int) $student->id) {
            abort(response()->json([
                'success' => false,
                'message' => 'Unauthorized to access this submission.',
            ], 403));
        }
    }

    private function presentStudentSubmission(AssignmentSubmission $s): array
    {
        $fileMeta = $this->submissionFiles->describeForApi($s, 'student');
        $student = $s->student;
        $assignment = $s->assignment;
        $course = $assignment?->course;

        $studentName = PersonNameHelper::displayName(
            $student?->english_name ?: trim(($student?->first_name ?? '') . ' ' . ($student?->last_name ?? '')),
            $student?->khmer_name,
            $student?->user?->name ?? 'Student'
        );

        $rawStatus = strtolower((string) ($s->status ?? 'submitted'));
        $isPublished = $s->score !== null && $rawStatus === 'graded';

        $submissionStatus = match ($rawStatus) {
            'late' => 'Submitted Late',
            'graded' => 'Submitted & Graded',
            default => 'Submitted On Time',
        };

        $gradingStatus = $isPublished ? 'Published (Graded)' : 'Waiting for Teacher Grading';

        return array_merge([
            'id' => $s->id,
            'assignment_id' => $s->assignment_id,
            'student_id' => $s->student_id,
            'student_code' => $student?->student_code ?? ('STU' . $s->student_id),
            'student_name' => $studentName,
            'assignment_title' => $assignment?->title ?? 'Assignment',
            'assessment_type' => ucfirst(str_replace('_', ' ', (string) ($assignment?->assignment_type ?? 'assignment'))),
            'course_code' => $course?->code ?? $course?->course_code ?? 'COURSE',
            'course_name' => $course?->name ?? $course?->course_name ?? 'Course',
            'class_name' => $assignment?->courseOffering?->classRoom?->name ?? '',
            'max_score' => (float) ($assignment?->total_score ?? 100),
            'total_score' => (float) ($assignment?->total_score ?? 100),
            'submitted_at' => $s->submitted_at ? \Carbon\Carbon::parse($s->submitted_at)->format('M d, Y h:i A') : '-',
            'submitted_at_iso' => $s->submitted_at?->toIso8601String(),
            'raw_status' => $rawStatus,
            'submission_status' => $submissionStatus,
            'grading_status' => $gradingStatus,
            'is_published' => $isPublished,
            // Never leak unpublished draft scores or comments to students
            'score' => $isPublished ? (float) $s->score : null,
            'feedback' => $isPublished ? ($s->feedback ?? '') : '',
            'published_at' => $isPublished && $s->updated_at ? \Carbon\Carbon::parse($s->updated_at)->format('M d, Y h:i A') : null,
            'can_resubmit' => ! $isPublished && strtolower((string) ($assignment?->status ?? 'published')) !== 'closed',
            'version_label' => 'Latest Submission',
        ], $fileMeta);
    }

    /** Students may read only their own; teachers manage submissions of their own offerings; admins anything. */
    private function authorizeSubmission(Request $request, AssignmentSubmission $submission, bool $manage = false): void
    {
        $scope = $this->access->resolveUserScope($request->user());

        if ($scope['admin']) {
            return;
        }
        if ($scope['student'] && ! $manage && (int) $submission->student_id === (int) $scope['student']->id) {
            return;
        }
        if ($scope['teacher'] && $submission->assignment && $this->access->teacherCanManage($scope['teacher'], $submission->assignment)) {
            return;
        }

        abort(404, 'Record not found.');
    }
}
