<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProgramController extends Controller
{
    private const COMPUTING_PROGRAM_CODES = ['CS', 'BSCS', 'SE', 'BSE', 'IT', 'BSIT'];

    public function index(Request $request): JsonResponse
    {
        $query = Program::with('department:id,code,name')
            ->withCount(['students', 'courses', 'courseOfferings']);

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($sub) use ($search) {
                $sub->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('department', function ($dq) use ($search) {
                        $dq->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                    });
            });
        }

        $deptIdRaw = $request->input('department_id', $request->input('faculty_id'));
        if ($deptIdRaw !== null && $deptIdRaw !== '') {
            if (! ctype_digit((string) $deptIdRaw) && ! is_int($deptIdRaw)) {
                return response()->json([
                    'message' => 'Invalid faculty/department filter.',
                    'errors' => ['department_id' => ['Department ID must be an integer.']],
                ], 422);
            }
            $query->where('department_id', (int) $deptIdRaw);
        }

        if ($request->filled('status')) {
            $status = strtolower(trim((string) $request->input('status')));
            if (in_array($status, ['active', 'inactive'], true)) {
                $query->whereRaw('LOWER(status) = ?', [$status]);
            }
        } elseif ($request->boolean('active_only')) {
            $query->whereRaw("LOWER(status) = 'active'");
        }

        $items = $query->orderBy('name')->get()->map(fn (Program $p) => $this->formatProgram($p))->values();

        return response()->json([
            'message' => 'Majors retrieved successfully.',
            'data' => $items,
        ], 200);
    }

    public function byDepartment(Request $request, Department $department): JsonResponse
    {
        $query = Program::with('department:id,code,name')
            ->withCount(['students', 'courses', 'courseOfferings']);

        if (! $request->boolean('include_inactive')) {
            $query->whereRaw("LOWER(status) = 'active'");
        }

        $deptCode = strtoupper(trim((string) $department->code));
        $isComputingFaculty = in_array($deptCode, ['CS', 'IT'], true);

        if (! $request->boolean('strict') && $isComputingFaculty) {
            $computingDeptIds = Department::whereIn('code', ['CS', 'IT'])->pluck('id')->all();
            $query->where(function ($q) use ($department, $computingDeptIds) {
                $q->where('department_id', $department->id)
                    ->orWhere(function ($sub) use ($computingDeptIds) {
                        $sub->whereIn('department_id', $computingDeptIds)
                            ->whereIn('code', self::COMPUTING_PROGRAM_CODES);
                    });
            });
        } else {
            $query->where('department_id', $department->id);
        }

        $items = $query->orderBy('name')->get()->map(fn (Program $p) => $this->formatProgram($p))->values();

        return response()->json([
            'message' => 'Majors retrieved successfully.',
            'data' => $items,
            'meta' => [
                'department_id' => (int) $department->id,
                'faculty_id' => (int) $department->id,
                'faculty_code' => (string) $department->code,
                'faculty_name' => (string) $department->name,
                'count' => $items->count(),
            ],
        ], 200);
    }

    public function courses(Request $request, Program $program): JsonResponse
    {
        $program->load('department:id,code,name');
        $query = $program->courses()->with(['departmentRelation:id,code,name', 'category:id,code,name']);

        if (! $request->boolean('include_inactive')) {
            $query->whereRaw("LOWER(COALESCE(courses.status, 'active')) = 'active'");
        }

        $courses = $query->orderBy('courses.course_code')->get()->map(fn ($c) => [
            'id' => (int) $c->id,
            'code' => (string) ($c->course_code ?? $c->code ?? ''),
            'course_code' => (string) ($c->course_code ?? $c->code ?? ''),
            'name' => (string) ($c->course_name ?? $c->name ?? ''),
            'course_name' => (string) ($c->course_name ?? $c->name ?? ''),
            'credit' => (float) ($c->credit ?? $c->credits ?? 3.0),
            'credits' => (int) round((float) ($c->credit ?? $c->credits ?? 3.0)),
            'status' => (string) ($c->status ?? 'active'),
            'department_id' => (int) ($c->department_id ?? $program->department_id),
            'course_category_id' => $c->course_category_id !== null ? (int) $c->course_category_id : null,
            'course_category_name' => $c->category?->name,
            'course_category_code' => $c->category?->code,
        ])->values();

        return response()->json([
            'message' => 'Major courses retrieved successfully.',
            'data' => $courses,
            'meta' => [
                'program_id' => (int) $program->id,
                'major_id' => (int) $program->id,
                'major_code' => (string) $program->code,
                'major_name' => (string) $program->name,
                'faculty_id' => (int) $program->department_id,
                'faculty_name' => $program->department?->name,
                'count' => $courses->count(),
            ],
        ], 200);
    }

    public function show(Program $program): JsonResponse
    {
        $program->load('department:id,code,name')
            ->loadCount(['students', 'courses', 'courseOfferings']);

        return response()->json([
            'message' => 'Major retrieved successfully.',
            'data' => $this->formatProgram($program),
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeInputs($request);

        $validated = $request->validate([
            'department_id' => 'required|integer|exists:departments,id',
            'name' => 'required|string|max:150',
            'code' => 'nullable|string|max:30',
            'description' => 'nullable|string|max:1000',
            'duration_years' => 'nullable|integer|min:1|max:6',
            'status' => 'nullable|string|in:active,inactive',
        ], [], [
            'department_id' => 'faculty',
        ]);

        $department = Department::findOrFail((int) $validated['department_id']);
        $name = trim($validated['name']);
        $this->ensureUniqueNameInDepartment($department->id, $name);

        $rawCode = trim((string) ($validated['code'] ?? ''));
        if ($rawCode !== '') {
            $code = strtoupper($rawCode);
            $this->ensureUniqueCode($code);
        } else {
            $code = $this->generateUniqueCode($department, $name);
        }

        $program = Program::create([
            'department_id' => $department->id,
            'code' => $code,
            'name' => $name,
            'description' => $validated['description'] ?? null,
            'duration_years' => (int) ($validated['duration_years'] ?? 4),
            'status' => strtolower($validated['status'] ?? 'active'),
        ]);

        $program->load('department:id,code,name')
            ->loadCount(['students', 'courses', 'courseOfferings']);

        return response()->json([
            'message' => 'Major created successfully.',
            'data' => $this->formatProgram($program),
        ], 201);
    }

    public function update(Request $request, Program $program): JsonResponse
    {
        $this->normalizeInputs($request);

        $validated = $request->validate([
            'department_id' => 'sometimes|required|integer|exists:departments,id',
            'name' => 'sometimes|required|string|max:150',
            'code' => 'sometimes|nullable|string|max:30',
            'description' => 'nullable|string|max:1000',
            'duration_years' => 'sometimes|required|integer|min:1|max:6',
            'status' => 'sometimes|required|string|in:active,inactive',
        ], [], [
            'department_id' => 'faculty',
        ]);

        $newDeptId = array_key_exists('department_id', $validated)
            ? (int) $validated['department_id']
            : (int) $program->department_id;

        $newName = array_key_exists('name', $validated)
            ? trim((string) $validated['name'])
            : (string) $program->name;

        if (array_key_exists('name', $validated) || array_key_exists('department_id', $validated)) {
            $this->ensureUniqueNameInDepartment($newDeptId, $newName, $program->id);
        }

        $newCode = (string) $program->code;
        if (array_key_exists('code', $validated)) {
            $trimmedCode = trim((string) ($validated['code'] ?? ''));
            if ($trimmedCode !== '') {
                $newCode = strtoupper($trimmedCode);
                $this->ensureUniqueCode($newCode, $program->id);
            }
        }

        $program->update([
            'department_id' => $newDeptId,
            'name' => $newName,
            'code' => $newCode,
            'description' => array_key_exists('description', $validated)
                ? $validated['description']
                : $program->description,
            'duration_years' => array_key_exists('duration_years', $validated)
                ? (int) $validated['duration_years']
                : (int) $program->duration_years,
            'status' => array_key_exists('status', $validated)
                ? strtolower((string) $validated['status'])
                : (string) $program->status,
        ]);

        $program->load('department:id,code,name')
            ->loadCount(['students', 'courses', 'courseOfferings']);

        return response()->json([
            'message' => 'Major updated successfully.',
            'data' => $this->formatProgram($program),
        ], 200);
    }

    public function destroy(Program $program): JsonResponse
    {
        $studentCount = $program->students()->count();
        $courseCount = $program->courses()->count();
        $offeringCount = $program->courseOfferings()->count();

        if ($studentCount > 0 || $courseCount > 0 || $offeringCount > 0) {
            $parts = [];
            if ($studentCount > 0) {
                $parts[] = $studentCount === 1 ? '1 student' : "{$studentCount} students";
            }
            if ($courseCount > 0) {
                $parts[] = $courseCount === 1 ? '1 course' : "{$courseCount} courses";
            }
            if ($offeringCount > 0) {
                $parts[] = $offeringCount === 1 ? '1 course offering' : "{$offeringCount} course offerings";
            }
            $summary = implode(' and ', $parts);

            return response()->json([
                'message' => "Cannot delete major \"{$program->name}\" because it is linked to {$summary}. Deactivate it instead.",
                'can_deactivate' => true,
                'dependencies' => [
                    'students' => $studentCount,
                    'courses' => $courseCount,
                    'course_offerings' => $offeringCount,
                ],
            ], 422);
        }

        $program->delete();

        return response()->json([
            'message' => 'Major deleted successfully.',
        ], 200);
    }

    private function normalizeInputs(Request $request): void
    {
        $updates = [];

        if (! $request->filled('department_id') && $request->filled('faculty_id')) {
            $updates['department_id'] = $request->input('faculty_id');
        }
        if ($request->has('name') && is_string($request->input('name'))) {
            $updates['name'] = trim($request->input('name'));
        }
        if ($request->has('code') && is_string($request->input('code'))) {
            $updates['code'] = trim($request->input('code'));
        }
        if ($request->has('description') && is_string($request->input('description'))) {
            $trimmedDesc = trim($request->input('description'));
            $updates['description'] = $trimmedDesc === '' ? null : $trimmedDesc;
        }
        if ($request->has('status') && is_string($request->input('status'))) {
            $trimmed = strtolower(trim($request->input('status')));
            $updates['status'] = $trimmed === '' ? null : $trimmed;
        }

        if ($updates !== []) {
            $request->merge($updates);
        }
    }

    private function ensureUniqueNameInDepartment(int $departmentId, string $name, ?int $ignoreId = null): void
    {
        $query = Program::where('department_id', $departmentId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => ['A major with this name already exists in the selected faculty.'],
            ]);
        }
    }

    private function ensureUniqueCode(string $code, ?int $ignoreId = null): void
    {
        $query = Program::whereRaw('LOWER(code) = ?', [mb_strtolower($code)]);
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'code' => ['A major with this code already exists.'],
            ]);
        }
    }

    private function generateUniqueCode(Department $department, string $name): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', strtoupper($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $abbr = '';
        if (count($words) >= 2) {
            foreach ($words as $w) {
                $abbr .= substr($w, 0, 1);
                if (strlen($abbr) >= 8) {
                    break;
                }
            }
        } elseif (count($words) === 1) {
            $abbr = substr($words[0], 0, 8);
        }

        if ($abbr === '') {
            $abbr = 'MAJ';
        }

        $deptCode = preg_replace('/[^A-Za-z0-9]/', '', strtoupper((string) $department->code));
        $base = $abbr;
        if (Program::whereRaw('LOWER(code) = ?', [strtolower($base)])->exists() && $deptCode !== '') {
            $base = substr($deptCode, 0, 10) . '-' . $abbr;
        }

        $candidate = substr($base, 0, 30);
        $suffix = 2;
        while (Program::whereRaw('LOWER(code) = ?', [strtolower($candidate)])->exists()) {
            $suffixStr = (string) $suffix;
            $candidate = substr($base, 0, 30 - strlen($suffixStr)) . $suffixStr;
            $suffix++;
        }

        return $candidate;
    }

    private function formatProgram(Program $p): array
    {
        $dept = $p->department;

        return [
            'id' => (int) $p->id,
            'department_id' => (int) $p->department_id,
            'faculty_id' => (int) $p->department_id,
            'code' => (string) $p->code,
            'name' => (string) $p->name,
            'description' => $p->description,
            'duration_years' => (int) ($p->duration_years ?? 4),
            'status' => (string) ($p->status ?? 'active'),
            'department_name' => $dept?->name,
            'faculty_name' => $dept?->name,
            'department_code' => $dept?->code,
            'faculty_code' => $dept?->code,
            'students_count' => (int) ($p->students_count ?? 0),
            'courses_count' => (int) ($p->courses_count ?? 0),
            'offerings_count' => (int) ($p->course_offerings_count ?? 0),
        ];
    }
}
