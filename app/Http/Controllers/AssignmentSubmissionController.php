<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Services\AssessmentAccessService;
use App\Services\AssessmentReopenService;
use App\Services\SubmissionFileService;
use App\Support\PersonNameHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class AssignmentSubmissionController extends Controller
{
    public function __construct(
        protected AssessmentAccessService $access,
        protected SubmissionFileService $submissionFiles,
        protected AssessmentReopenService $reopenService,
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

        if ($request->boolean('latest_only')) {
            $query->where('is_latest', true);
        }

        return response()->json([
            'message' => 'Assignment submissions retrieved successfully.',
            'data' => $query->orderByDesc('version_number')->orderByDesc('id')->get(),
        ], 200);
    }

    public function store(Request $request)
    {
        $student = $request->user()?->student;
        if (! $student) {
            return response()->json([
                'success' => false,
                'message' => 'Only students can submit assignments.',
            ], 403);
        }

        $validated = $request->validate([
            'assignment_id' => 'required|integer|exists:assignments,id',
            'file_path' => 'nullable|string|max:500',
            'submission_text' => 'nullable|string|max:20000',
            'submission_file' => 'nullable|file|mimes:pdf,doc,docx,zip,png,jpg,jpeg,txt|max:10240',
        ]);

        // Pre-upload file if attached before acquiring row locks, clean up if rejected.
        $uploadedMeta = null;
        if ($request->hasFile('submission_file')) {
            $uploaded = $request->file('submission_file');
            $uploadedMeta = [
                'file_path' => $uploaded->store('submissions', 'public'),
                'original_file_name' => $uploaded->getClientOriginalName(),
                'mime_type' => $uploaded->getClientMimeType() ?: $uploaded->getMimeType(),
                'file_size' => $uploaded->getSize(),
            ];
        }

        $txResult = DB::transaction(function () use ($validated, $student, $uploadedMeta) {
            $assignment = Assignment::whereKey($validated['assignment_id'])->lockForUpdate()->firstOrFail();

            if (! $this->access->studentCanAccess($student, $assignment)) {
                return ['error' => 'Assignment not found or you are not enrolled in this course.', 'status' => 404];
            }

            $status = strtolower((string) $assignment->status);
            if (in_array($status, ['draft', 'archived'], true)) {
                return ['error' => 'Assignment not found or you are not enrolled in this course.', 'status' => 404];
            }

            $existingRows = AssignmentSubmission::where('assignment_id', $assignment->id)
                ->where('student_id', $student->id)
                ->lockForUpdate()
                ->orderByDesc('version_number')
                ->orderByDesc('id')
                ->get();

            $access = $this->reopenService->evaluateAssignmentAccess($assignment, $student);

            if (! $access['can_submit']) {
                $httpStatus = 422;
                if ($access['used_attempts'] > 0 && (! $access['allow_resubmission'] || $access['remaining_attempts'] <= 0)) {
                    $httpStatus = 409;
                } elseif (! $access['allow_submission']) {
                    $httpStatus = 403;
                }

                return [
                    'error' => $access['blocked_reason'] ?: 'You do not have permission to submit this assignment.',
                    'status' => $httpStatus,
                    'errors' => ['permission' => [$access['blocked_reason'] ?: 'Submission not permitted.']],
                ];
            }

            $latest = $existingRows->first();
            $incomingFileName = $uploadedMeta['original_file_name'] ?? null;
            $incomingFileSize = $uploadedMeta['file_size'] ?? null;
            $incomingText = $validated['submission_text'] ?? null;
            $incomingFilePath = $uploadedMeta['file_path'] ?? ($validated['file_path'] ?? null);

            if ($latest && $latest->submitted_at && $latest->submitted_at->diffInSeconds(now()) <= 2) {
                $overrideAfterLatest = $access['override_model']
                    && $access['override_model']->updated_at
                    && $access['override_model']->updated_at->gt($latest->submitted_at);

                $samePayload = $latest->original_file_name === $incomingFileName
                    && (int) ($latest->file_size ?? 0) === (int) ($incomingFileSize ?? 0)
                    && (string) ($latest->submission_text ?? '') === (string) ($incomingText ?? '')
                    && (string) ($latest->file_path ?? '') === (string) ($incomingFilePath ?? '');

                if (! $overrideAfterLatest && $samePayload) {
                    return [
                        'error' => 'Duplicate submission detected. Please wait before submitting again.',
                        'status' => 409,
                    ];
                }
            }

            if ($existingRows->isNotEmpty()) {
                AssignmentSubmission::where('assignment_id', $assignment->id)
                    ->where('student_id', $student->id)
                    ->where('is_latest', true)
                    ->update(['is_latest' => false]);
            }

            $nextVersion = ((int) $existingRows->max('version_number')) + 1;
            $isLate = $access['window_state'] === 'late_open';

            $submission = AssignmentSubmission::create([
                'assignment_id' => $assignment->id,
                'student_id' => $student->id,
                'version_number' => $nextVersion,
                'submitted_at' => now(),
                'is_late' => $isLate,
                'status' => $isLate ? 'late' : 'submitted',
                'is_latest' => true,
                'override_id' => $access['override_model']?->id,
                'file_path' => $incomingFilePath,
                'original_file_name' => $incomingFileName,
                'mime_type' => $uploadedMeta['mime_type'] ?? null,
                'file_size' => $incomingFileSize,
                'submission_text' => $incomingText,
            ]);

            return ['submission' => $submission];
        });

        if (isset($txResult['error'])) {
            if ($uploadedMeta && ! empty($uploadedMeta['file_path'])) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($uploadedMeta['file_path']);
            }

            $body = [
                'success' => false,
                'message' => $txResult['error'],
            ];
            if (! empty($txResult['errors'])) {
                $body['errors'] = $txResult['errors'];
            }

            return response()->json($body, $txResult['status']);
        }

        /** @var AssignmentSubmission $submission */
        $submission = $txResult['submission'];

        return response()->json([
            'success' => true,
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

        $versionNumber = (int) ($s->version_number ?: 1);
        $isLatest = (bool) ($s->is_latest ?? true);
        $canResubmit = $assignment && $student
            ? (bool) $this->reopenService->evaluateAssignmentAccess($assignment, $student)['can_resubmit']
            : (! $isPublished && strtolower((string) ($assignment?->status ?? 'published')) !== 'closed');

        $uploadedFiles = [];
        if (! empty($fileMeta['has_file'])) {
            $uploadedFiles[] = [
                'file_name' => $fileMeta['file_name'],
                'file_extension' => $fileMeta['file_extension'],
                'file_type' => $fileMeta['file_type'],
                'mime_type' => $fileMeta['mime_type'],
                'file_size' => $fileMeta['file_size'],
                'file_size_label' => $fileMeta['file_size_label'],
                'preview_type' => $fileMeta['preview_type'],
                'file_exists' => $fileMeta['file_exists'],
                'view_url' => $fileMeta['view_url'],
                'download_url' => $fileMeta['download_url'],
            ];
        }

        $versionLabel = $versionNumber > 1
            ? ('Version ' . $versionNumber . ($isLatest ? ' (Latest)' : ''))
            : ($isLatest ? 'Latest Submission' : 'Version 1');

        return array_merge([
            'id' => $s->id,
            'submission_id' => $s->id,
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
            'is_late' => (bool) ($s->is_late || $rawStatus === 'late'),
            'is_latest' => $isLatest,
            'version_number' => $versionNumber,
            'uploaded_files' => $uploadedFiles,
            'submission_text' => $s->submission_text,
            // Never leak unpublished draft scores or comments to students
            'score' => $isPublished ? (float) $s->score : null,
            'feedback' => $isPublished ? ($s->feedback ?? '') : '',
            'published_at' => $isPublished && $s->updated_at ? \Carbon\Carbon::parse($s->updated_at)->format('M d, Y h:i A') : null,
            'can_resubmit' => $canResubmit,
            'version_label' => $versionLabel,
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
