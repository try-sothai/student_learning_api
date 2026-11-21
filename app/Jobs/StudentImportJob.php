<?php

namespace App\Jobs;

use App\Exceptions\DuplicateStudentException;
use App\Models\StudentImport;
use App\Models\StudentImportRow;
use App\Services\StudentImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Creates students for every "valid" row of a confirmed import, chunk by chunk
 * (one transaction per student, progress saved per chunk). Safe to retry: rows
 * already imported are no longer "valid" and are never processed twice.
 */
class StudentImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 900;

    public function __construct(public int $importId)
    {
    }

    public function handle(StudentImportService $service): void
    {
        $import = StudentImport::find($this->importId);
        if (! $import || $import->isFinished()) {
            return;
        }

        $import->update([
            'status' => StudentImport::PROCESSING,
            'started_at' => $import->started_at ?? now(),
            'failure_message' => null,
        ]);

        $chunk = config('student_import.chunk_size');
        $cancelled = false;

        // chunkById re-queries by primary key so rows updated inside the loop don't shift pages
        StudentImportRow::where('student_import_id', $import->id)
            ->where('status', 'valid')
            ->chunkById($chunk, function ($rows) use ($import, $service, &$cancelled) {
                if (StudentImport::whereKey($import->id)->value('cancel_requested')) {
                    $cancelled = true;

                    return false;
                }

                foreach ($rows as $row) {
                    $this->processRow($row, $service);
                }
                $service->refreshCounters($import);
            });

        $service->refreshCounters($import);
        $import->refresh();

        if ($cancelled || $import->cancel_requested) {
            $import->update(['status' => StudentImport::CANCELLED, 'finished_at' => now()]);
            StudentImportRow::where('student_import_id', $import->id)->update(['temp_password_encrypted' => null]);
        } else {
            $runtimeFailed = StudentImportRow::where('student_import_id', $import->id)
                ->where('status', 'failed')
                ->count();
            if ($import->success_rows === 0 && $runtimeFailed > 0) {
                $import->update([
                    'status' => StudentImport::FAILED,
                    'failure_message' => 'Import failed: student records could not be saved and were rolled back.',
                    'finished_at' => now(),
                ]);
            } else {
                $import->update([
                    'status' => $import->failed_rows > 0 || $import->skipped_rows > 0
                        ? StudentImport::COMPLETED_WITH_ERRORS : StudentImport::COMPLETED,
                    'finished_at' => now(),
                ]);
            }
        }

        $service->deleteStoredFile($import);
    }

    private function processRow(StudentImportRow $row, StudentImportService $service): void
    {
        $supplied = null;
        if ($row->temp_password_encrypted) {
            $decoded = decrypt($row->temp_password_encrypted);
            $supplied = str_starts_with($decoded, 'P:') ? substr($decoded, 2) : null;
        }

        try {
            $result = $service->registerStudent($row->data, $supplied);
            $row->update([
                'status' => 'imported',
                'student_id' => $result['student']->id,
                'errors' => null,
                // Only generated temporary passwords are kept (encrypted) for one-time download.
                'temp_password_encrypted' => $result['temp_password'] ? encrypt('G:' . $result['temp_password']) : null,
            ]);
        } catch (DuplicateStudentException $e) {
            $row->update(['status' => 'duplicate', 'errors' => [$e->getMessage()], 'temp_password_encrypted' => null]);
        } catch (Throwable $e) {
            report($e);
            $row->update([
                'status' => 'failed',
                'errors' => ['Could not save this student: database error'],
                'temp_password_encrypted' => null,
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        $import = StudentImport::find($this->importId);
        if ($import && ! $import->isFinished()) {
            app(StudentImportService::class)->refreshCounters($import);
            $import->update([
                'status' => StudentImport::FAILED,
                'failure_message' => mb_substr($e->getMessage(), 0, 500),
                'finished_at' => now(),
            ]);
        }
    }
}
