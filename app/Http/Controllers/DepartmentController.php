<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DepartmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Department::withCount(['students', 'teachers', 'courses', 'classes', 'programs']);

        if ($request->filled('search')) {
            $q = trim((string) $request->input('search'));
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        if ($request->filled('status')) {
            $status = strtolower(trim((string) $request->input('status')));
            if (in_array($status, ['active', 'inactive'], true)) {
                $query->whereRaw('LOWER(status) = ?', [$status]);
            }
        }

        $departments = $query
            ->orderByRaw("CASE WHEN LOWER(status) = 'active' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();

        return response()->json([
            'message' => 'Departments retrieved successfully.',
            'data' => $departments,
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeInputs($request);

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'code' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:2000',
            'status' => 'nullable|string|in:active,inactive',
        ]);

        $name = trim($validated['name']);
        $this->ensureUniqueName($name);

        $rawCode = trim((string) ($validated['code'] ?? ''));
        if ($rawCode !== '') {
            $code = strtoupper($rawCode);
            $this->ensureUniqueCode($code);
        } else {
            $code = $this->generateUniqueCode($name);
        }

        $description = isset($validated['description']) && trim((string) $validated['description']) !== ''
            ? trim((string) $validated['description'])
            : null;

        $department = Department::create([
            'name' => $name,
            'code' => $code,
            'description' => $description,
            'status' => strtolower($validated['status'] ?? 'active'),
        ]);

        $department->loadCount(['students', 'teachers', 'courses', 'classes', 'programs']);

        return response()->json([
            'message' => 'Category created successfully.',
            'data' => $department,
        ], 201);
    }

    public function show(Department $department): JsonResponse
    {
        return response()->json([
            'message' => 'Department retrieved successfully.',
            'data' => $department->load(['classes', 'courses'])
                ->loadCount(['students', 'teachers', 'courses', 'classes', 'programs']),
        ], 200);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $this->normalizeInputs($request);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'code' => 'sometimes|nullable|string|max:20',
            'description' => 'sometimes|nullable|string|max:2000',
            'status' => 'sometimes|required|string|in:active,inactive',
        ]);

        $newName = array_key_exists('name', $validated)
            ? trim((string) $validated['name'])
            : $department->name;

        if (array_key_exists('name', $validated)) {
            $this->ensureUniqueName($newName, $department->id);
        }

        $newCode = $department->code;
        if (array_key_exists('code', $validated)) {
            $trimmedCode = trim((string) ($validated['code'] ?? ''));
            if ($trimmedCode !== '') {
                $newCode = strtoupper($trimmedCode);
                $this->ensureUniqueCode($newCode, $department->id);
            }
        }

        $newDescription = $department->description;
        if (array_key_exists('description', $validated)) {
            $trimmedDesc = trim((string) ($validated['description'] ?? ''));
            $newDescription = $trimmedDesc !== '' ? $trimmedDesc : null;
        }

        $newStatus = array_key_exists('status', $validated)
            ? strtolower((string) $validated['status'])
            : $department->status;

        $oldName = $department->name;

        DB::transaction(function () use ($department, $newName, $newCode, $newDescription, $newStatus, $oldName) {
            $department->update([
                'name' => $newName,
                'code' => $newCode,
                'description' => $newDescription,
                'status' => $newStatus,
            ]);

            if ($oldName !== $newName) {
                Course::where('department_id', $department->id)->update(['department' => $newName]);
            }
        });

        $fresh = $department->fresh()->loadCount(['students', 'teachers', 'courses', 'classes', 'programs']);

        return response()->json([
            'message' => 'Category updated successfully.',
            'data' => $fresh,
        ], 200);
    }

    public function destroy(Department $department): JsonResponse
    {
        $dependencies = [
            'courses' => $department->courses()->count(),
            'classes' => $department->classes()->count(),
            'students' => $department->students()->count(),
            'teachers' => $department->teachers()->count(),
            'programs' => $department->programs()->count(),
            'class_schedules' => $department->classSchedules()->count(),
            'incidents' => $department->incidents()->count(),
            'specializations' => $department->specializations()->count(),
        ];

        $totalLinked = array_sum($dependencies);
        if ($totalLinked > 0) {
            $parts = [];
            $labels = [
                'courses' => ['course', 'courses'],
                'classes' => ['class', 'classes'],
                'students' => ['student', 'students'],
                'teachers' => ['teacher', 'teachers'],
                'programs' => ['program', 'programs'],
                'class_schedules' => ['schedule', 'schedules'],
                'incidents' => ['incident', 'incidents'],
                'specializations' => ['specialization', 'specializations'],
            ];

            foreach ($dependencies as $key => $count) {
                if ($count > 0) {
                    $label = $count === 1 ? $labels[$key][0] : $labels[$key][1];
                    $parts[] = "{$count} {$label}";
                }
            }

            $summary = implode(', ', $parts);

            return response()->json([
                'message' => "Cannot delete category \"{$department->name}\" because it is linked to {$summary}. Deactivate it instead to preserve existing relationships.",
                'can_deactivate' => true,
                'dependencies' => $dependencies,
            ], 422);
        }

        $department->delete();

        return response()->json([
            'message' => 'Category deleted successfully.',
        ], 200);
    }

    private function normalizeInputs(Request $request): void
    {
        $updates = [];

        if ($request->has('name') && is_string($request->input('name'))) {
            $updates['name'] = trim($request->input('name'));
        }
        if ($request->has('code') && is_string($request->input('code'))) {
            $updates['code'] = trim($request->input('code'));
        }
        if ($request->has('description') && is_string($request->input('description'))) {
            $updates['description'] = trim($request->input('description'));
        }
        if ($request->has('status') && is_string($request->input('status'))) {
            $trimmedStatus = strtolower(trim($request->input('status')));
            $updates['status'] = $trimmedStatus === '' ? null : $trimmedStatus;
        }

        if ($updates !== []) {
            $request->merge($updates);
        }
    }

    private function ensureUniqueName(string $name, ?int $ignoreId = null): void
    {
        $query = Department::whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => ['A category with this name already exists.'],
            ]);
        }
    }

    private function ensureUniqueCode(string $code, ?int $ignoreId = null): void
    {
        $query = Department::whereRaw('LOWER(code) = ?', [mb_strtolower($code)]);
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'code' => ['A category with this code already exists.'],
            ]);
        }
    }

    private function generateUniqueCode(string $name): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', strtoupper($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $base = '';
        if (count($words) >= 2) {
            foreach ($words as $word) {
                $base .= substr($word, 0, 1);
                if (strlen($base) >= 6) {
                    break;
                }
            }
        } elseif (count($words) === 1) {
            $base = substr($words[0], 0, 6);
        }

        if ($base === '') {
            $base = 'CAT';
        }

        $candidate = substr($base, 0, 20);
        $suffix = 2;

        while (Department::whereRaw('LOWER(code) = ?', [strtolower($candidate)])->exists()) {
            $suffixStr = (string) $suffix;
            $candidate = substr($base, 0, 20 - strlen($suffixStr)) . $suffixStr;
            $suffix++;
        }

        return $candidate;
    }
}
