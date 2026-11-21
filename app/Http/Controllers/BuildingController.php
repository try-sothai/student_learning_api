<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Floor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BuildingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Building::with(['floors' => function ($q) {
            $q->withCount('classes')->orderBy('floor_number');
        }])->withCount(['floors', 'classes']);

        if ($request->filled('status') && strtolower($request->status) !== 'all') {
            $query->where('status', strtolower($request->status));
        }
        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%");
            });
        }

        $buildings = $query->orderBy('name')->get();

        return response()->json([
            'message' => 'Buildings retrieved successfully.',
            'data' => $buildings,
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120|unique:buildings,name',
            'code' => 'nullable|string|max:30|unique:buildings,code',
            'description' => 'nullable|string|max:1000',
            'status' => 'nullable|string|in:active,inactive',
            'total_floors' => 'nullable|integer|min:0|max:50',
        ]);

        $building = DB::transaction(function () use ($validated) {
            $building = Building::create([
                'name' => trim($validated['name']),
                'code' => ! empty($validated['code']) ? strtoupper(trim($validated['code'])) : null,
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'] ?? 'active',
            ]);

            $totalFloors = (int) ($validated['total_floors'] ?? 0);
            for ($i = 1; $i <= $totalFloors; $i++) {
                Floor::create([
                    'building_id' => $building->id,
                    'floor_number' => $i,
                    'name' => 'Floor ' . $i,
                    'status' => 'active',
                ]);
            }

            return $building;
        });

        return response()->json([
            'message' => 'Building created successfully.',
            'data' => $building->load('floors')->loadCount(['floors', 'classes']),
        ], 201);
    }

    public function show(Building $building): JsonResponse
    {
        return response()->json([
            'message' => 'Building retrieved successfully.',
            'data' => $building->load(['floors.classes', 'classes.floor'])->loadCount(['floors', 'classes']),
        ], 200);
    }

    public function update(Request $request, Building $building): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('buildings', 'name')->ignore($building->id)],
            'code' => ['nullable', 'string', 'max:30', Rule::unique('buildings', 'code')->ignore($building->id)],
            'description' => 'nullable|string|max:1000',
            'status' => 'nullable|string|in:active,inactive',
        ]);

        $building->update([
            'name' => trim($validated['name']),
            'code' => ! empty($validated['code']) ? strtoupper(trim($validated['code'])) : null,
            'description' => $validated['description'] ?? $building->description,
            'status' => $validated['status'] ?? $building->status,
        ]);

        return response()->json([
            'message' => 'Building updated successfully.',
            'data' => $building->fresh()->load('floors')->loadCount(['floors', 'classes']),
        ], 200);
    }

    public function destroy(Building $building): JsonResponse
    {
        if ($building->classes()->exists()) {
            return response()->json([
                'message' => 'Cannot delete building while classes are assigned to it.',
            ], 422);
        }

        $building->delete();

        return response()->json([
            'message' => 'Building deleted successfully.',
        ], 200);
    }
}
