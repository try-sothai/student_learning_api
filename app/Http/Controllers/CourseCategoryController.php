<?php

namespace App\Http\Controllers;

use App\Models\CourseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Course Categories = the TYPE of a course (General Education, Major Core, ...).
 * Separate from Faculty (departments) and Major (programs).
 */
class CourseCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CourseCategory::withCount('courses');

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

        $items = $query
            ->orderByRaw("CASE WHEN LOWER(status) = 'active' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get()
            ->map(fn (CourseCategory $c) => $this->format($c))
            ->values();

        return response()->json([
            'message' => 'Course categories retrieved successfully.',
            'data' => $items,
        ], 200);
    }

    public function show(CourseCategory $courseCategory): JsonResponse
    {
        $courseCategory->loadCount('courses');

        return response()->json([
            'message' => 'Course category retrieved successfully.',
            'data' => $this->format($courseCategory),
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalize($request);

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'code' => 'nullable|string|max:30',
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

        $category = CourseCategory::create([
            'name' => $name,
            'code' => $code,
            'description' => $description,
            'status' => strtolower($validated['status'] ?? 'active'),
        ]);
        $category->loadCount('courses');

        return response()->json([
            'message' => 'Course category created successfully.',
            'data' => $this->format($category),
        ], 201);
    }

    public function update(Request $request, CourseCategory $courseCategory): JsonResponse
    {
        $this->normalize($request);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'code' => 'sometimes|nullable|string|max:30',
            'description' => 'sometimes|nullable|string|max:2000',
            'status' => 'sometimes|required|string|in:active,inactive',
        ]);

        $name = array_key_exists('name', $validated) ? trim((string) $validated['name']) : $courseCategory->name;
        if (array_key_exists('name', $validated)) {
            $this->ensureUniqueName($name, $courseCategory->id);
        }

        $code = $courseCategory->code;
        if (array_key_exists('code', $validated)) {
            $trimmed = trim((string) ($validated['code'] ?? ''));
            if ($trimmed !== '') {
                $code = strtoupper($trimmed);
                $this->ensureUniqueCode($code, $courseCategory->id);
            }
        }

        $description = $courseCategory->description;
        if (array_key_exists('description', $validated)) {
            $trimmed = trim((string) ($validated['description'] ?? ''));
            $description = $trimmed !== '' ? $trimmed : null;
        }

        $courseCategory->update([
            'name' => $name,
            'code' => $code,
            'description' => $description,
            'status' => array_key_exists('status', $validated)
                ? strtolower((string) $validated['status'])
                : $courseCategory->status,
        ]);

        $fresh = $courseCategory->fresh()->loadCount('courses');

        return response()->json([
            'message' => 'Course category updated successfully.',
            'data' => $this->format($fresh),
        ], 200);
    }

    public function destroy(CourseCategory $courseCategory): JsonResponse
    {
        $courses = $courseCategory->courses()->count();

        if ($courses > 0) {
            $label = $courses === 1 ? 'course' : 'courses';

            return response()->json([
                'message' => "Cannot delete category \"{$courseCategory->name}\" because it is linked to {$courses} {$label}. Deactivate it instead to preserve existing relationships.",
                'can_deactivate' => true,
                'dependencies' => ['courses' => $courses],
            ], 422);
        }

        $courseCategory->delete();

        return response()->json(['message' => 'Course category deleted successfully.'], 200);
    }

    private function format(CourseCategory $c): array
    {
        return [
            'id' => (int) $c->id,
            'name' => (string) $c->name,
            'code' => (string) ($c->code ?? ''),
            'description' => $c->description,
            'status' => strtolower((string) ($c->status ?: 'active')),
            'courses_count' => (int) ($c->courses_count ?? 0),
            'created_at' => $c->created_at,
            'updated_at' => $c->updated_at,
        ];
    }

    private function normalize(Request $request): void
    {
        $updates = [];
        foreach (['name', 'code', 'description'] as $key) {
            if ($request->has($key) && is_string($request->input($key))) {
                $updates[$key] = trim($request->input($key));
            }
        }
        if ($request->has('status') && is_string($request->input('status'))) {
            $s = strtolower(trim($request->input('status')));
            $updates['status'] = $s === '' ? null : $s;
        }
        if ($updates !== []) {
            $request->merge($updates);
        }
    }

    private function ensureUniqueName(string $name, ?int $ignoreId = null): void
    {
        $query = CourseCategory::whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => ['A course category with this name already exists.'],
            ]);
        }
    }

    private function ensureUniqueCode(string $code, ?int $ignoreId = null): void
    {
        $query = CourseCategory::whereRaw('LOWER(code) = ?', [mb_strtolower($code)]);
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'code' => ['A course category with this code already exists.'],
            ]);
        }
    }

    private function generateUniqueCode(string $name): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', strtoupper($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $base = '';
        if (count($words) >= 2) {
            foreach ($words as $w) {
                $base .= substr($w, 0, 1);
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

        $candidate = substr($base, 0, 30);
        $suffix = 2;
        while (CourseCategory::whereRaw('LOWER(code) = ?', [strtolower($candidate)])->exists()) {
            $s = (string) $suffix;
            $candidate = substr($base, 0, 30 - strlen($s)) . $s;
            $suffix++;
        }

        return $candidate;
    }
}
