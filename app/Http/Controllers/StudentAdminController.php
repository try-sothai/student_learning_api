<?php

namespace App\Http\Controllers;

use App\Exceptions\DuplicateStudentException;
use App\Models\Student;
use App\Models\StudentImportRow;
use App\Services\StudentExportService;
use App\Services\StudentImportReportService;
use App\Services\StudentImportService;
use App\Services\StudentImportValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** University Add Student form, dropdown lookups and student export (admin only). */
class StudentAdminController extends Controller
{
    /** Real database records for the Add Student / Export dropdowns. */
    public function lookups(): JsonResponse
    {
        return response()->json(['data' => [
            'departments' => DB::table('departments')->orderBy('name')->get(['id', 'code', 'name']),
            'programs' => DB::table('programs')->where('status', 'active')->orderBy('name')
                ->get(['id', 'department_id', 'code', 'name', 'duration_years']),
            'academic_years' => DB::table('academic_years')->orderByDesc('id')->get(['id', 'name', 'status']),
            'semesters' => DB::table('semesters')->orderBy('academic_year_id')->orderBy('semester_number')
                ->get(['id', 'academic_year_id', 'name', 'semester_number']),
        ]]);
    }

    public function store(Request $request, StudentImportService $service, StudentImportValidationService $validator): JsonResponse
    {
        if ($request->exists('email') && trim((string) $request->input('email')) === '') {
            $request->merge(['email' => null]);
        }
        if ($request->exists('date_of_birth') && trim((string) $request->input('date_of_birth')) === '') {
            $request->merge(['date_of_birth' => null]);
        }

        $in = $request->validate([
            'khmer_name' => 'nullable|string|max:150',
            'english_name' => 'nullable|string|max:150',
            'email' => ['nullable', 'email', 'max:255', \Illuminate\Validation\Rule::unique('users', 'email')],
            'gender' => 'required|string',
            'date_of_birth' => 'nullable|string',
            'place_of_birth' => 'nullable|string|max:190',
            'current_address' => 'nullable|string|max:255',
            'student_identifier' => 'required|string|max:50',
            'password' => 'nullable|string|max:100',
            'department_id' => 'required|integer|exists:departments,id',
            'program_id' => 'required|integer|exists:programs,id',
            'year_level' => 'required|integer|min:1',
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'semester_id' => 'nullable|integer|exists:semesters,id',
            'father_khmer_name' => 'nullable|string|max:150',
            'father_english_name' => 'nullable|string|max:150',
            'father_phone' => 'nullable|string|max:30',
            'mother_khmer_name' => 'nullable|string|max:150',
            'mother_english_name' => 'nullable|string|max:150',
            'mother_phone' => 'nullable|string|max:30',
            'emergency_contact_name' => 'nullable|string|max:150',
            'emergency_relationship' => 'nullable|string|max:80',
            'emergency_contact_phone' => 'nullable|string|max:30',
            'emergency_contact_address' => 'nullable|string|max:255',
        ]);

        if (\App\Support\PersonNameHelper::clean($in['khmer_name'] ?? null) === null
            && \App\Support\PersonNameHelper::clean($in['english_name'] ?? null) === null) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['khmer_name' => ['At least one of Khmer Name or English Name is required.']],
            ], 422);
        }

        // Reuse the import rules by translating ids into the template's codes.
        $in['department_code'] = DB::table('departments')->where('id', $in['department_id'])->value('code');
        $in['program_code'] = DB::table('programs')->where('id', $in['program_id'])->value('code');
        $in['academic_year_code'] = DB::table('academic_years')->where('id', $in['academic_year_id'])->value('name');
        $in['semester_code'] = ! empty($in['semester_id'])
            ? (string) DB::table('semesters')->where('id', $in['semester_id'])->value('semester_number') : '';

        $row = $validator->normalize($in);
        $result = $validator->validate($row);
        if ($result['errors']) {
            return response()->json(['message' => 'Validation failed.', 'errors' => ['student' => $result['errors']]], 422);
        }

        try {
            $created = $service->registerStudent($result['data']);
        } catch (DuplicateStudentException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['student_identifier' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'message' => 'Student created successfully.',
            'data' => [
                'id' => $created['student']->id,
                'student_identifier' => $created['student']->student_code,
                'username' => $created['student']->student_code,
                'role' => 'student',
                'initial_password' => $created['temp_password'],
                'temporary_password' => $created['temp_password'],
            ],
        ], 201);
    }

    /** Admin-approved password reset when an imported student's password is missing or unknown. */
    public function resetPassword(Request $request, string $student)
    {
        $record = ctype_digit($student)
            ? Student::find((int) $student)
            : Student::whereRaw('LOWER(student_code) = ?', [mb_strtolower(trim($student))])->first();

        if (! $record) {
            return response()->json(['message' => 'Student record not found.'], 404);
        }

        $validated = $request->validate([
            'password' => 'nullable|string|min:8|max:100',
            'format' => 'nullable|in:json,csv',
        ]);

        $user = $record->user;
        if (! $user) {
            return response()->json(['message' => 'No user account is linked to this student.'], 422);
        }

        $supplied = isset($validated['password']) && $validated['password'] !== '' ? $validated['password'] : null;
        $generated = $supplied === null;
        $plain = $generated ? (string) config('student_import.default_initial_password', 'STD@12345') : $supplied;

        DB::transaction(function () use ($user, $record, $plain) {
            $user->forceFill(['password' => Hash::make($plain)])->save();
            $user->tokens()->delete();
            StudentImportRow::where('student_id', $record->id)->update(['temp_password_encrypted' => null]);
        });

        Log::info('Admin reset student password', [
            'admin_id' => $request->user()->id,
            'student_id' => $record->id,
            'student_code' => $record->student_code,
            'generated' => $generated,
        ]);

        if (($validated['format'] ?? 'json') === 'csv') {
            $filename = 'student_credentials_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $record->student_code) . '.csv';

            return response()->streamDownload(function () use ($record, $user, $plain, $generated) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['student_id', 'username', 'english_name', 'role', 'new_initial_password']);
                fputcsv($out, [
                    StudentImportReportService::safe($record->student_code),
                    StudentImportReportService::safe($record->student_code),
                    StudentImportReportService::safe($record->english_name),
                    $user->role,
                    $generated ? StudentImportReportService::safe($plain) : '[custom password set by admin]',
                ]);
                fclose($out);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        return response()->json([
            'message' => 'Student password has been reset successfully.',
            'data' => [
                'student_id' => $record->id,
                'student_identifier' => $record->student_code,
                'student_code' => $record->student_code,
                'username' => $record->student_code,
                'role' => $user->role,
                'password_generated' => $generated,
                'temporary_password' => $generated ? $plain : null,
            ],
        ]);
    }

    public function export(Request $request, StudentExportService $export)
    {
        $request->validate([
            'format' => 'nullable|in:xlsx,csv',
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
        ]);
        $format = $request->input('format', 'xlsx');
        $filters = StudentExportService::filtersFrom($request);
        Log::info('Student export', ['by' => $request->user()->id, 'format' => $format, 'filters' => $filters]);

        if ($format === 'csv') {
            return response()->streamDownload($export->csv($filters), 'students_export.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'slas_exp_');
        $export->xlsx($filters, $tmp);

        return response()->download($tmp, 'students_export.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
