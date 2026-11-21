<?php

namespace App\Http\Controllers;

use App\Models\Floor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FloorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Floor::with('building')->withCount('classes');

        if ($request->filled('building_id')) {
            $query->where('building_id', (int) $request->building_id);
        }
        if ($request->filled('status') && strtolower($request->status) !== 'all') {
            $query->where('status', strtolower($request->status));
        }

        $floors = $query->orderBy('building_id')->orderBy('floor_number')->get();

        return response()->json([
            'message' => 'Floors retrieved successfully.',
            'data' => $floors,
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'building_id' => 'required|integer|exists:buildings,id',
            'floor_number' => [
                'required',
                'integer',
                'min:-5',
                'max:100',
                Rule::unique('floors', 'floor_number')->where(fn ($q) => $q->where('building_id', $request->building_id)),
            ],
            'name' => 'nullable|string|max:80',
            'status' => 'nullable|string|in:active,inactive',
        ]);

        $num = (int) $validated['floor_number'];
        $floor = Floor::create([
            'building_id' => (int) $validated['building_id'],
            'floor_number' => $num,
            'name' => ! empty($validated['name']) ? trim($validated['name']) : ($num === 0 ? 'Ground Floor' : 'Floor ' . $num),
            'status' => $validated['status'] ?? 'active',
        ]);

        return response()->json([
            'message' => 'Floor created successfully.',
            'data' => $floor->load('building')->loadCount('classes'),
        ], 201);
    }

    public function show(Floor $floor): JsonResponse
    {
        return response()->json([
            'message' => 'Floor retrieved successfully.',
            'data' => $floor->load(['building', 'classes'])->loadCount('classes'),
        ], 200);
    }

    public function update(Request $request, Floor $floor): JsonResponse
    {
        $buildingId = (int) ($request->input('building_id', $floor->building_id));
        $validated = $request->validate([
            'building_id' => 'sometimes|integer|exists:buildings,id',
            'floor_number' => [
                'required',
                'integer',
                'min:-5',
                'max:100',
                Rule::unique('floors', 'floor_number')
                    ->where(fn ($q) => $q->where('building_id', $buildingId))
                    ->ignore($floor->id),
            ],
            'name' => 'nullable|string|max:80',
            'status' => 'nullable|string|in:active,inactive',
        ]);

        $num = (int) $validated['floor_number'];
        $floor->update([
            'building_id' => $buildingId,
            'floor_number' => $num,
            'name' => ! empty($validated['name']) ? trim($validated['name']) : ($num === 0 ? 'Ground Floor' : 'Floor ' . $num),
            'status' => $validated['status'] ?? $floor->status,
        ]);

        return response()->json([
            'message' => 'Floor updated successfully.',
            'data' => $floor->fresh()->load('building')->loadCount('classes'),
        ], 200);
    }

    public function destroy(Floor $floor): JsonResponse
    {
        if ($floor->classes()->exists()) {
            return response()->json([
                'message' => 'Cannot delete floor while classes are assigned to it.',
            ], 422);
        }

        $floor->delete();

        return response()->json([
            'message' => 'Floor deleted successfully.',
        ], 200);
    }
}
