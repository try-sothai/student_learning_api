<?php

namespace App\Services;

use App\Models\AssignmentSubmission;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Safely resolves, authorizes, and describes uploaded student submission files
 * without exposing raw server filesystem paths.
 */
class SubmissionFileService
{
    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
    private const DOC_EXTENSIONS = ['doc', 'docx'];

    /**
     * Resolve stored file handle and metadata from known Laravel disks.
     * Returns null when no valid/safe stored file exists.
     */
    public function resolveStoredFile(AssignmentSubmission $submission): ?array
    {
        $relativePath = $this->sanitizeRelativePath($submission->file_path);
        if ($relativePath === null) {
            return null;
        }

        foreach (['public', 'local'] as $diskName) {
            $disk = Storage::disk($diskName);
            if ($disk->exists($relativePath)) {
                $fullPath = $disk->path($relativePath);
                $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
                $fileName = $this->resolveFriendlyFileName($submission, $relativePath);
                if ($ext === '' && $fileName !== '') {
                    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                }

                $mime = $submission->mime_type ?: ($disk->mimeType($relativePath) ?: $this->guessMimeFromExtension($ext));
                $size = $submission->file_size ?: (is_file($fullPath) ? (int) filesize($fullPath) : null);

                return [
                    'disk' => $diskName,
                    'relative_path' => $relativePath,
                    'full_path' => $fullPath,
                    'file_name' => $fileName,
                    'extension' => $ext,
                    'mime_type' => $mime,
                    'file_size' => $size,
                    'file_size_label' => $this->formatBytes($size),
                    'file_type' => $this->friendlyFileType($ext, $mime),
                    'preview_type' => $this->previewType($ext, $mime),
                ];
            }
        }

        return null;
    }

    /**
     * Produce API-safe metadata for submission list, detail, and grading screens.
     */
    public function describeForApi(AssignmentSubmission $submission, string $audience = 'teacher'): array
    {
        $prefix = $audience === 'student' ? '/api/student/submissions' : '/api/teacher/submissions';
        $rawPath = trim((string) ($submission->file_path ?? ''));
        $hasAttachmentReference = $rawPath !== '' && strcasecmp($rawPath, 'Submitted online') !== 0;

        $resolved = $this->resolveStoredFile($submission);
        if ($resolved !== null) {
            return [
                'has_file' => true,
                'file_exists' => true,
                'file_name' => $resolved['file_name'],
                'file_extension' => strtoupper($resolved['extension']),
                'file_type' => $resolved['file_type'],
                'mime_type' => $resolved['mime_type'],
                'file_size' => $resolved['file_size'],
                'file_size_label' => $resolved['file_size_label'],
                'preview_type' => $resolved['preview_type'],
                'view_url' => "{$prefix}/{$submission->id}/file",
                'download_url' => "{$prefix}/{$submission->id}/file?download=1",
                // Compatibility display string without raw storage paths
                'file_url' => $resolved['file_name'],
            ];
        }

        if ($hasAttachmentReference) {
            $safeName = $this->resolveFriendlyFileName($submission, $rawPath);
            $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
            $mime = $submission->mime_type ?: $this->guessMimeFromExtension($ext);
            return [
                'has_file' => true,
                'file_exists' => false,
                'file_name' => $safeName !== '' ? $safeName : 'Missing attachment',
                'file_extension' => strtoupper($ext),
                'file_type' => $this->friendlyFileType($ext, $mime),
                'mime_type' => $mime,
                'file_size' => $submission->file_size,
                'file_size_label' => $this->formatBytes($submission->file_size),
                'preview_type' => 'missing',
                'view_url' => "{$prefix}/{$submission->id}/file",
                'download_url' => "{$prefix}/{$submission->id}/file?download=1",
                'file_url' => $safeName !== '' ? $safeName : 'Missing attachment',
            ];
        }

        return [
            'has_file' => false,
            'file_exists' => false,
            'file_name' => 'Submitted online (no file)',
            'file_extension' => '',
            'file_type' => 'Online text / No attachment',
            'mime_type' => '',
            'file_size' => null,
            'file_size_label' => '',
            'preview_type' => 'none',
            'view_url' => null,
            'download_url' => null,
            'file_url' => 'Submitted online',
        ];
    }

    /**
     * Stream an authorized submission file securely (inline preview or attachment download).
     */
    public function streamFileResponse(AssignmentSubmission $submission, bool $download = false): HttpResponse
    {
        $resolved = $this->resolveStoredFile($submission);

        if ($resolved === null) {
            return response()->json([
                'success' => false,
                'message' => 'Submitted file is missing or cannot be accessed on the server.',
            ], 404);
        }

        $headers = [
            'Content-Type' => $resolved['mime_type'],
            'Cache-Control' => 'private, no-store',
        ];

        if ($download) {
            return response()->download($resolved['full_path'], $resolved['file_name'], $headers);
        }

        $safeFileName = str_replace(['"', "\r", "\n"], '', $resolved['file_name']);
        $headers['Content-Disposition'] = 'inline; filename="' . $safeFileName . '"';

        return response()->file($resolved['full_path'], $headers);
    }

    /**
     * Attach safe file metadata to a submission model returned in student assignment endpoints
     * and ensure unpublished draft grades/feedback are never exposed to students.
     */
    public function decorateStudentSubmissionModel(AssignmentSubmission $submission): AssignmentSubmission
    {
        $meta = $this->describeForApi($submission, 'student');
        $isPublished = $submission->score !== null && strtolower((string) $submission->status) === 'graded';

        foreach ([
            'has_file',
            'file_exists',
            'file_name',
            'file_extension',
            'file_type',
            'mime_type',
            'file_size',
            'file_size_label',
            'preview_type',
            'view_url',
            'download_url',
        ] as $key) {
            $submission->setAttribute($key, $meta[$key]);
        }
        $submission->setAttribute('is_published', $isPublished);
        if (! $isPublished) {
            $submission->setAttribute('score', null);
            $submission->setAttribute('feedback', null);
        }

        return $submission;
    }

    private function sanitizeRelativePath(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $clean = str_replace('\\', '/', trim($raw));
        if ($clean === '' || str_contains($clean, "\0") || str_contains($clean, '..')) {
            return null;
        }
        if (str_starts_with($clean, '/') || preg_match('/^[A-Za-z]:/', $clean)) {
            return null;
        }
        if (str_starts_with($clean, 'storage/')) {
            $clean = substr($clean, strlen('storage/'));
        }
        // Reject raw schemes like http:// or file://
        if (preg_match('/^[a-z]+:\/\//i', $clean)) {
            return null;
        }

        return $clean !== '' ? $clean : null;
    }

    private function resolveFriendlyFileName(AssignmentSubmission $submission, string $path): string
    {
        $orig = trim((string) ($submission->original_file_name ?? ''));
        if ($orig !== '') {
            return basename(str_replace('\\', '/', $orig));
        }

        $base = basename(str_replace('\\', '/', $path));
        return $base !== '' ? $base : 'submission_file';
    }

    public function previewType(string $ext, string $mime): string
    {
        if (in_array($ext, self::IMAGE_EXTENSIONS, true) || str_starts_with(strtolower($mime), 'image/')) {
            return 'image';
        }
        if ($ext === 'pdf' || strtolower($mime) === 'application/pdf') {
            return 'pdf';
        }
        if (in_array($ext, self::DOC_EXTENSIONS, true)
            || str_contains(strtolower($mime), 'wordprocessingml')
            || strtolower($mime) === 'application/msword') {
            return 'document';
        }
        return 'unsupported';
    }

    public function friendlyFileType(string $ext, string $mime): string
    {
        return match ($ext) {
            'pdf' => 'PDF Document',
            'png' => 'PNG Image',
            'jpg', 'jpeg' => 'JPEG Image',
            'webp' => 'WebP Image',
            'doc' => 'Word Document (.doc)',
            'docx' => 'Word Document (.docx)',
            'zip' => 'ZIP Archive',
            'txt' => 'Text File (.txt)',
            default => $ext !== '' ? (strtoupper($ext) . ' File') : ($mime !== '' ? $mime : 'File'),
        };
    }

    private function guessMimeFromExtension(string $ext): string
    {
        return match ($ext) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'zip' => 'application/zip',
            'txt' => 'text/plain',
            default => 'application/octet-stream',
        };
    }

    private function formatBytes(?int $bytes): string
    {
        if ($bytes === null || $bytes < 0) {
            return '';
        }
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return number_format($bytes / 1048576, 2) . ' MB';
    }
}
