<?php

namespace App\Http\Controllers;

use App\Exceptions\ImportFileException;
use App\Jobs\StudentImportJob;
use App\Models\StudentImport;
use App\Models\StudentImportRow;
use App\Services\StudentImportReportService;
use App\Services\StudentImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Admin-only bulk student import (routes live in the role:admin group). */
class StudentImportController extends Controller
{
    public function __construct(
        private StudentImportService $imports,
        private StudentImportReportService $reports,
    ) {
    }

    public function template()
    {
        return response()->streamDownload(function () {
            $tmp = tempnam(sys_get_temp_dir(), 'slas_tpl_');
            $this->reports->templateXlsx($tmp);
            $stream = fopen($tmp, 'rb');
            fpassthru($stream);
            fclose($stream);
            @unlink($tmp);
        }, 'student_import_template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function templateCsv()
    {
        return response()->streamDownload($this->reports->templateCsv(), 'student_import_template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:' . config('student_import.max_file_kb'), 'extensions:xlsx,xls,csv', 'mimes:xlsx,xls,csv,txt'],
        ], [
            'file.max' => 'The file is larger than ' . config('student_import.max_file_kb') . ' KB.',
            'file.extensions' => 'Only .xlsx, .xls, and .csv files are supported.',
            'file.mimes' => 'Only .xlsx, .xls, and .csv files are supported.',
        ]);

        try {
            $import = $this->imports->createPreview($request->file('file'), $request->user());
        } catch (ImportFileException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'File validated. Nothing has been saved yet - review the preview and confirm.',
            'data' => $import->load('user:id,name')->toApi() + [
                'preview_rows' => $import->rows()->orderBy('row_number')->limit(100)->get()->map->toApi(),
            ],
        ], 201);
    }

    public function confirm(StudentImport $import): JsonResponse
    {
        if ($import->valid_rows < 1) {
            return response()->json(['message' => 'There are no valid rows to import.'], 422);
        }

        // atomic pending -> processing prevents double confirmation / duplicate imports
        $claimed = StudentImport::whereKey($import->id)->where('status', StudentImport::PENDING)
            ->update(['status' => StudentImport::PROCESSING]);
        if (! $claimed) {
            return response()->json(['message' => 'This import has already been confirmed, cancelled or finished.'], 409);
        }

        StudentImportJob::dispatchSync($import->id);

        $fresh = $import->fresh()->load('user:id,name')->toApi();
        if (($fresh['status'] ?? null) === StudentImport::FAILED) {
            return response()->json([
                'message' => $fresh['failure_message'] ?? 'Student import failed. No records were saved.',
                'data' => $fresh,
            ], 500);
        }

        $message = $fresh['has_generated_credentials']
            ? 'Import processed. Generated temporary passwords are available once from the credentials endpoint.'
            : 'Import processed. Progress is available from the status endpoint.';

        return response()->json([
            'message' => $message,
            'data' => $fresh,
        ], 202);
    }

    public function status(StudentImport $import): JsonResponse
    {
        return response()->json(['data' => $import->load('user:id,name')->toApi()]);
    }

    public function cancel(StudentImport $import): JsonResponse
    {
        if ($import->isFinished()) {
            return response()->json(['message' => 'This import is already finished.'], 409);
        }

        if ($import->status === StudentImport::PENDING || ! $import->started_at) {
            $import->update(['status' => StudentImport::CANCELLED, 'finished_at' => now(), 'cancel_requested' => true]);
            StudentImportRow::where('student_import_id', $import->id)->update(['temp_password_encrypted' => null]);
            $this->imports->deleteStoredFile($import);
        } else {
            $import->update(['cancel_requested' => true]);
        }

        return response()->json(['message' => 'Import cancellation requested.', 'data' => $import->fresh()->load('user:id,name')->toApi()]);
    }

    public function index(Request $request): JsonResponse
    {
        $q = StudentImport::with('user:id,name')->latest('id');

        if ($s = trim((string) $request->query('search', ''))) {
            $q->where(function ($w) use ($s) {
                $w->where('original_filename', 'like', "%{$s}%")
                    ->orWhere('id', ctype_digit($s) ? (int) $s : 0)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$s}%"));
            });
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($request->filled('date_from')) {
            $q->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $q->whereDate('created_at', '<=', $request->query('date_to'));
        }

        $p = $q->paginate(min(max((int) $request->query('per_page', 10), 1), 100));

        return response()->json([
            'data' => $p->getCollection()->map->toApi()->values(),
            'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage(), 'total' => $p->total()],
        ]);
    }

    public function show(StudentImport $import): JsonResponse
    {
        return response()->json(['data' => $import->load('user:id,name')->toApi()]);
    }

    /** Row-level results. ?status=invalid,duplicate,failed (default: all problem rows). */
    public function errors(Request $request, StudentImport $import): JsonResponse
    {
        return $this->rowsResponse($request, $import, ['invalid', 'duplicate', 'failed']);
    }

    public function rows(Request $request, StudentImport $import): JsonResponse
    {
        return $this->rowsResponse($request, $import, null);
    }

    private function rowsResponse(Request $request, StudentImport $import, ?array $default): JsonResponse
    {
        $statuses = $request->filled('status') ? explode(',', (string) $request->query('status')) : $default;
        $q = StudentImportRow::where('student_import_id', $import->id)->orderBy('row_number');
        if ($statuses) {
            $q->whereIn('status', $statuses);
        }
        $p = $q->paginate(min(max((int) $request->query('per_page', 20), 1), 200));

        return response()->json([
            'data' => $p->getCollection()->map->toApi()->values(),
            'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage(), 'total' => $p->total()],
        ]);
    }

    public function errorReport(StudentImport $import)
    {
        return response()->streamDownload(
            $this->reports->rowsCsv($import, ['invalid', 'duplicate', 'failed']),
            "import_{$import->id}_error_report.csv",
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }

    public function successReport(StudentImport $import)
    {
        return response()->streamDownload(
            $this->reports->rowsCsv($import, ['imported']),
            "import_{$import->id}_successful_records.csv",
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }

    /** Generated temporary passwords, downloadable ONCE after the import has finished. */
    public function credentials(Request $request, StudentImport $import)
    {
        if (! $import->isFinished()) {
            return response()->json(['message' => 'Credentials are available after the import finishes.'], 409);
        }
        Log::info('Student import credentials downloaded', ['import_id' => $import->id, 'by' => $request->user()->id]);

        return response()->streamDownload(
            $this->reports->credentialsCsv($import),
            "import_{$import->id}_temporary_credentials.csv",
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }
}
