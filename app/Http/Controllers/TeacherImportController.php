<?php

namespace App\Http\Controllers;

use App\Services\TeacherAccountService;
use App\Support\PersonNameHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin CSV / Excel Teacher Bulk Import.
 * Creates Teacher profile + linked User login account transactionally per valid row,
 * assigns role='teacher' and hashed initial password, rejects duplicates, and reports per-row errors.
 */
class TeacherImportController extends Controller
{
    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'teacher_id', 'khmer_name', 'english_name', 'first_name', 'last_name', 'email',
                'date_of_birth', 'hire_date', 'department', 'position', 'work_type', 'phone', 'gender', 'status',
            ]);
            fputcsv($out, [
                'TCH0NGIM', 'ងឹម សុខា', 'Ngim Sokha', '', '', '',
                '1990-05-14', '2024-09-01', 'Computer Science', 'Lecturer', 'Full-time', '012345678', 'Male', 'active',
            ]);
            fclose($out);
        }, 'teacher_import_template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function import(Request $request, TeacherAccountService $accounts): JsonResponse
    {
        $rows = [];

        if ($request->hasFile('file')) {
            $request->validate([
                'file' => ['required', 'file', 'max:5120', 'extensions:csv,xlsx,xls', 'mimes:csv,txt,xlsx,xls'],
            ]);
            $rows = $this->parseSpreadsheetFile($request->file('file')->getRealPath());
        } else {
            $validated = $request->validate([
                'rows' => 'required|array|min:1|max:500',
                'rows.*' => 'required|array',
            ]);
            foreach ($validated['rows'] as $idx => $rowData) {
                $normalized = [];
                foreach ($rowData as $k => $v) {
                    $normalized[strtolower(trim((string) $k))] = is_string($v) ? trim($v) : $v;
                }
                $normalized['_row_number'] = $idx + 1;
                $rows[] = $normalized;
            }
        }

        if ($rows === []) {
            return response()->json([
                'message' => 'No data rows found in the uploaded teacher file.',
            ], 422);
        }

        $seenCodes = [];
        $seenEmails = [];
        $createdTeachers = [];
        $errors = [];

        foreach ($rows as $row) {
            $rowNumber = (int) ($row['_row_number'] ?? 0);
            $rawCode = trim((string) ($row['teacher_id'] ?? $row['teacher_code'] ?? $row['login_id'] ?? ''));
            $normCode = $accounts->normalizeCode($rawCode);
            $rawEmail = trim((string) ($row['email'] ?? ''));
            $normEmail = $accounts->normalizeEmail($rawEmail);

            $rowErrors = [];
            if ($normCode === '') {
                $rowErrors[] = 'teacher_id is required.';
            } elseif (isset($seenCodes[$normCode])) {
                $rowErrors[] = "Duplicate teacher_id '{$normCode}' in import batch.";
            }

            $khmerName = PersonNameHelper::clean((string) ($row['khmer_name'] ?? ''));
            $englishName = PersonNameHelper::clean((string) ($row['english_name'] ?? ''));
            $firstName = PersonNameHelper::clean((string) ($row['first_name'] ?? ''));
            $lastName = PersonNameHelper::clean((string) ($row['last_name'] ?? ''));
            $fullName = PersonNameHelper::clean((string) ($row['name'] ?? $row['full_name'] ?? ''));

            if ($khmerName === null && $englishName === null && $firstName === null && $fullName === null) {
                $rowErrors[] = 'At least one name (khmer_name, english_name, or first_name) is required.';
            }

            if ($normEmail !== null) {
                if (! filter_var($normEmail, FILTER_VALIDATE_EMAIL)) {
                    $rowErrors[] = 'Invalid email format.';
                } elseif (isset($seenEmails[$normEmail])) {
                    $rowErrors[] = "Duplicate email '{$normEmail}' in import batch.";
                }
            }

            if ($rowErrors !== []) {
                $errors[] = [
                    'row' => $rowNumber,
                    'teacher_id' => $normCode !== '' ? $normCode : $rawCode,
                    'errors' => $rowErrors,
                ];
                continue;
            }

            $seenCodes[$normCode] = true;
            if ($normEmail !== null) {
                $seenEmails[$normEmail] = true;
            }

            try {
                $provisioned = $accounts->createTeacherWithAccount([
                    'teacher_id' => $normCode,
                    'khmer_name' => $khmerName,
                    'english_name' => $englishName,
                    'name' => $fullName,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $normEmail,
                    'date_of_birth' => ! empty($row['date_of_birth']) ? $row['date_of_birth'] : null,
                    'hire_date' => ! empty($row['hire_date']) ? $row['hire_date'] : null,
                    'department' => $row['department'] ?? 'Computer Science',
                    'position' => $row['position'] ?? 'Lecturer',
                    'work_type' => $row['work_type'] ?? 'Full-time',
                    'phone' => $row['phone'] ?? null,
                    'gender' => $row['gender'] ?? 'Male',
                    'status' => $row['status'] ?? 'active',
                ], $request->user()?->id);

                $createdTeachers[] = [
                    'row' => $rowNumber,
                    'teacher_db_id' => $provisioned['teacher']->id,
                    'user_id' => $provisioned['user']->id,
                    'teacher_id' => $provisioned['login_id'],
                    'login_id' => $provisioned['login_id'],
                    'name' => $provisioned['user']->name,
                    'email' => $provisioned['user']->email,
                    'role' => 'teacher',
                    'initial_password' => $provisioned['initial_password'],
                    'account_status' => $provisioned['account_status'],
                    'account_linked' => true,
                ];
            } catch (ValidationException $ve) {
                $msgs = [];
                foreach ($ve->errors() as $fieldMsgs) {
                    foreach ($fieldMsgs as $m) {
                        $msgs[] = $m;
                    }
                }
                $errors[] = [
                    'row' => $rowNumber,
                    'teacher_id' => $normCode,
                    'errors' => $msgs !== [] ? $msgs : [$ve->getMessage()],
                ];
            } catch (\Throwable $e) {
                $errors[] = [
                    'row' => $rowNumber,
                    'teacher_id' => $normCode,
                    'errors' => [$e->getMessage()],
                ];
            }
        }

        $importedCount = count($createdTeachers);
        $failedCount = count($errors);
        $status = $importedCount > 0 ? ($failedCount > 0 ? 207 : 201) : 422;

        return response()->json([
            'message' => $importedCount > 0
                ? "Teacher import finished: {$importedCount} created, {$failedCount} failed."
                : 'Teacher import failed validation. No records were created.',
            'data' => [
                'total_rows' => count($rows),
                'imported_count' => $importedCount,
                'failed_count' => $failedCount,
                'created_teachers' => $createdTeachers,
                'errors' => $errors,
            ],
        ], $status);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseSpreadsheetFile(string $realPath): array
    {
        $spreadsheet = IOFactory::load($realPath);
        $sheet = $spreadsheet->getActiveSheet();
        $rawRows = $sheet->toArray(null, true, true, false);
        if (count($rawRows) < 2) {
            return [];
        }

        $headerRow = array_shift($rawRows);
        $headers = array_map(function ($h) {
            $clean = preg_replace('/^\xEF\xBB\xBF/', '', (string) $h);
            return strtolower(str_replace([' ', '-'], '_', trim($clean ?? '')));
        }, $headerRow);

        $out = [];
        foreach ($rawRows as $index => $cells) {
            $nonEmpty = array_filter($cells, fn ($v) => trim((string) $v) !== '');
            if ($nonEmpty === []) {
                continue;
            }
            $assoc = ['_row_number' => $index + 2];
            foreach ($headers as $colIdx => $key) {
                if ($key !== '') {
                    $assoc[$key] = isset($cells[$colIdx]) ? trim((string) $cells[$colIdx]) : '';
                }
            }
            $out[] = $assoc;
        }

        return $out;
    }
}
