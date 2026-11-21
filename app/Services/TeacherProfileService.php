<?php

namespace App\Services;

use App\Models\OfficeLocation;
use App\Models\Specialization;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;

class TeacherProfileService
{
    /**
     * Returns office locations grouped by building name, optionally filtered by building, room, or display name.
     *
     * @return array<int, array{building: string, rooms: array<int, array{id: int, building: string, room_number: string, display_name: string}>}>
     */
    public function groupedOfficeLocations(?string $search = null): array
    {
        $query = OfficeLocation::query()->orderBy('building')->orderBy('room_number');
        $term = trim((string) $search);
        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('building', 'like', "%{$term}%")
                    ->orWhere('room_number', 'like', "%{$term}%")
                    ->orWhere('display_name', 'like', "%{$term}%");
            });
        }

        $grouped = [];
        foreach ($query->get(['id', 'building', 'room_number', 'display_name']) as $loc) {
            $grouped[$loc->building][] = [
                'id' => $loc->id,
                'building' => $loc->building,
                'room_number' => $loc->room_number,
                'display_name' => $loc->display_name,
            ];
        }

        $out = [];
        foreach ($grouped as $building => $rooms) {
            $out[] = [
                'building' => $building,
                'rooms' => $rooms,
            ];
        }

        return $out;
    }

    public function findOrCreateOfficeLocation(
        string $building,
        string $roomNumber,
        ?int $createdByUserId = null
    ): array {
        $buildingClean = trim(preg_replace('/\s+/', ' ', $building) ?? '');
        $roomClean = trim(preg_replace('/\s+/', ' ', $roomNumber) ?? '');

        if (preg_match('/^\d+[A-Za-z]?$/', $roomClean)) {
            $roomClean = 'Room ' . $roomClean;
        }

        $displayName = "{$buildingClean}, {$roomClean}";

        $existing = OfficeLocation::whereRaw('LOWER(display_name) = ?', [mb_strtolower($displayName)])->first();
        if ($existing) {
            return ['location' => $existing, 'created' => false];
        }

        $location = OfficeLocation::create([
            'building' => $buildingClean,
            'room_number' => $roomClean,
            'display_name' => $displayName,
            'created_by' => $createdByUserId,
        ]);

        return ['location' => $location, 'created' => true];
    }

    public function resolveOfficeLocationId(
        ?int $officeLocationId,
        ?string $officeLocationText,
        ?int $createdByUserId = null
    ): ?int {
        if ($officeLocationId && $officeLocationId > 0) {
            $found = OfficeLocation::find($officeLocationId);
            if ($found) {
                return $found->id;
            }
        }

        $text = trim((string) $officeLocationText);
        if ($text === '') {
            return null;
        }

        $exact = OfficeLocation::whereRaw('LOWER(display_name) = ?', [mb_strtolower($text)])->first();
        if ($exact) {
            return $exact->id;
        }

        $building = 'Campus Building';
        $room = $text;
        if (str_contains($text, ',')) {
            [$b, $r] = array_map('trim', explode(',', $text, 2));
            if ($b !== '' && $r !== '') {
                $building = $b;
                $room = $r;
            }
        } elseif (str_contains($text, ' - ')) {
            [$b, $r] = array_map('trim', explode(' - ', $text, 2));
            if ($b !== '' && $r !== '') {
                $building = $b;
                $room = $r;
            }
        } elseif (str_contains($text, '→')) {
            [$b, $r] = array_map('trim', explode('→', $text, 2));
            if ($b !== '' && $r !== '') {
                $building = $b;
                $room = $r;
            }
        }

        return $this->findOrCreateOfficeLocation($building, $room, $createdByUserId)['location']->id;
    }

    public function findOrCreateSpecialization(
        string $name,
        ?int $departmentId = null,
        ?int $createdByUserId = null
    ): array {
        $clean = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $existing = Specialization::whereRaw('LOWER(name) = ?', [mb_strtolower($clean)])->first();
        if ($existing) {
            return ['specialization' => $existing, 'created' => false];
        }

        $spec = Specialization::create([
            'name' => $clean,
            'department_id' => $departmentId,
            'created_by' => $createdByUserId,
        ]);

        return ['specialization' => $spec, 'created' => true];
    }

    /**
     * Parses mixed array or comma-separated specialization names, creates catalog entries if needed,
     * syncs pivot rows, and updates the backwards-compatible `teachers.specialization` summary text.
     */
    public function syncSpecializations(
        Teacher $teacher,
        array|string|null $specializationsInput,
        ?string $fallbackString = null,
        ?int $createdByUserId = null
    ): void {
        $rawList = [];
        if (is_array($specializationsInput)) {
            foreach ($specializationsInput as $entry) {
                if (is_numeric($entry)) {
                    $found = Specialization::find((int) $entry);
                    if ($found) {
                        $rawList[] = $found->name;
                    }
                } elseif (is_string($entry)) {
                    $trimmed = trim($entry);
                    if ($trimmed !== '') {
                        $rawList[] = $trimmed;
                    }
                }
            }
        } elseif (is_string($specializationsInput)) {
            foreach (explode(',', $specializationsInput) as $part) {
                $trimmed = trim($part);
                if ($trimmed !== '') {
                    $rawList[] = $trimmed;
                }
            }
        } elseif (is_string($fallbackString) && trim($fallbackString) !== '') {
            foreach (explode(',', $fallbackString) as $part) {
                $trimmed = trim($part);
                if ($trimmed !== '') {
                    $rawList[] = $trimmed;
                }
            }
        }

        // Preserve order while deduplicating case-insensitively across English & Khmer Unicode.
        $seen = [];
        $ids = [];
        $names = [];
        foreach ($rawList as $item) {
            $key = mb_strtolower($item);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $spec = $this->findOrCreateSpecialization($item, $teacher->department_id, $createdByUserId)['specialization'];
            $ids[] = $spec->id;
            $names[] = $spec->name;
        }

        DB::transaction(function () use ($teacher, $ids, $names) {
            $teacher->specializationItems()->detach();
            if ($ids !== []) {
                $teacher->specializationItems()->attach($ids);
            }
            $teacher->forceFill([
                'specialization' => $names !== [] ? implode(', ', $names) : null,
            ])->save();
        });
    }

    public function cleanBiography(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $trimmed = trim($normalized);

        return $trimmed === '' ? null : $trimmed;
    }
}
