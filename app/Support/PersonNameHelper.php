<?php

namespace App\Support;

/**
 * Shared name normalization & display fallback helper for Students and Teachers.
 * Ensures Khmer Name OR English Name alone is valid, whitespace-only is rejected,
 * and missing-language columns are stored as null rather than fabricated.
 */
class PersonNameHelper
{
    public static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Resolves display name with fallback: English Name -> Khmer Name -> first/last -> fallback.
     */
    public static function displayName(
        ?string $englishName,
        ?string $khmerName,
        ?string $firstName = null,
        ?string $lastName = null,
        string $fallback = ''
    ): string {
        $en = self::clean($englishName);
        if ($en !== null) {
            return $en;
        }

        $km = self::clean($khmerName);
        if ($km !== null) {
            return $km;
        }

        $combined = self::clean(trim(($firstName ?? '') . ' ' . ($lastName ?? '')));
        if ($combined !== null) {
            return $combined;
        }

        return $fallback;
    }

    /**
     * Splits a non-empty canonical display name into [first_name, last_name] without fabricating data.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitFirstLast(string $displayName): array
    {
        $parts = preg_split('/\s+/u', trim($displayName), 2) ?: [$displayName];
        $first = trim((string) ($parts[0] ?? ''));
        $last = trim((string) ($parts[1] ?? ''));

        return [$first, $last !== '' ? $last : $first];
    }
}
