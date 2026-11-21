<?php

namespace App\Http\Controllers;

use App\Models\Specialization;
use App\Services\TeacherProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Admin-managed searchable catalogs for Teacher Office Locations and Specializations. */
class TeacherProfileCatalogController extends Controller
{
    public function __construct(private TeacherProfileService $profiles)
    {
    }

    public function lookups(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $specQuery = Specialization::query()->orderBy('name');
        if ($search !== '') {
            $specQuery->where('name', 'like', "%{$search}%");
        }

        $grouped = $this->profiles->groupedOfficeLocations($search !== '' ? $search : null);
        $flatOffices = [];
        foreach ($grouped as $group) {
            foreach ($group['rooms'] as $room) {
                $flatOffices[] = $room;
            }
        }

        return response()->json([
            'data' => [
                'departments' => DB::table('departments')->orderBy('name')->get(['id', 'code', 'name']),
                'office_locations' => $flatOffices,
                'office_locations_grouped' => $grouped,
                'specializations' => $specQuery->get(['id', 'name', 'department_id']),
            ],
        ]);
    }

    public function storeOfficeLocation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'building' => 'required|string|max:100',
            'room_number' => 'required|string|max:60',
        ]);

        $res = $this->profiles->findOrCreateOfficeLocation(
            $validated['building'],
            $validated['room_number'],
            $request->user()?->id
        );

        return response()->json([
            'message' => $res['created'] ? 'Office location added.' : 'Existing office location selected.',
            'data' => $res['location'],
        ], $res['created'] ? 201 : 200);
    }

    public function storeSpecialization(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'department_id' => 'nullable|integer|exists:departments,id',
        ]);

        $res = $this->profiles->findOrCreateSpecialization(
            $validated['name'],
            $validated['department_id'] ?? null,
            $request->user()?->id
        );

        return response()->json([
            'message' => $res['created'] ? 'Specialization added.' : 'Existing specialization selected.',
            'data' => $res['specialization'],
        ], $res['created'] ? 201 : 200);
    }
}
