<?php

namespace App\Support;

/**
 * Single source of truth for SLAS Student Year Levels (Y1–Y4).
 *
 * Keeps Academic Year (e.g. "2026-2027") strictly separate from
 * Student Year Level (Y1 = Year 1 / ឆ្នាំទី១ .. Y4 = Year 4 / ឆ្នាំទី៤).
 */
final class YearLevel
{
    private const KHMER_TO_ARABIC = [
        '១' => '1',
        '២' => '2',
        '៣' => '3',
        '៤' => '4',
        '៥' => '5',
        '៦' => '6',
        '៧' => '7',
        '៨' => '8',
        '៩' => '9',
        '០' => '0',
    ];

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        $configured = config('slas.year_levels');
        if (is_array($configured) && $configured !== []) {
            return $configured;
        }

        return [
            1 => ['number' => 1, 'code' => 'Y1', 'name' => 'Year 1', 'ordinal' => 'First Year', 'khmer' => 'ឆ្នាំទី១', 'label' => 'Y1 — Year 1', 'bilingual' => 'Y1 — Year 1 / ឆ្នាំទី១'],
            2 => ['number' => 2, 'code' => 'Y2', 'name' => 'Year 2', 'ordinal' => 'Second Year', 'khmer' => 'ឆ្នាំទី២', 'label' => 'Y2 — Year 2', 'bilingual' => 'Y2 — Year 2 / ឆ្នាំទី២'],
            3 => ['number' => 3, 'code' => 'Y3', 'name' => 'Year 3', 'ordinal' => 'Third Year', 'khmer' => 'ឆ្នាំទី៣', 'label' => 'Y3 — Year 3', 'bilingual' => 'Y3 — Year 3 / ឆ្នាំទី៣'],
            4 => ['number' => 4, 'code' => 'Y4', 'name' => 'Year 4', 'ordinal' => 'Fourth Year', 'khmer' => 'ឆ្នាំទី៤', 'label' => 'Y4 — Year 4', 'bilingual' => 'Y4 — Year 4 / ឆ្នាំទី៤'],
        ];
    }

    public static function maxLevel(): int
    {
        $all = self::all();
        $keys = array_keys($all);

        return max(4, (int) config('slas.max_year_level', $keys !== [] ? max($keys) : 4));
    }

    /**
     * Structured options for API lookups and dropdowns.
     *
     * @return array<int, array{number: int, code: string, name: string, khmer_name: string, label: string, bilingual_label: string}>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::all() as $num => $def) {
            $out[] = [
                'number' => (int) ($def['number'] ?? $num),
                'code' => (string) $def['code'],
                'name' => (string) $def['name'],
                'khmer_name' => (string) $def['khmer'],
                'label' => (string) $def['label'],
                'bilingual_label' => (string) $def['bilingual'],
            ];
        }

        return $out;
    }

    /**
     * Keyed options for Blade <select> dropdowns using numeric value ("1".."4").
     *
     * @return array<string, string>
     */
    public static function selectOptions(?string $locale = null): array
    {
        $out = [];
        foreach (self::all() as $num => $def) {
            $out[(string) $num] = match ($locale) {
                'km' => "{$def['code']} — {$def['khmer']}",
                'en' => (string) $def['label'],
                default => (string) $def['bilingual'],
            };
        }

        return $out;
    }

    /**
     * Keyed options using canonical Y-codes ("Y1".."Y4").
     *
     * @return array<string, string>
     */
    public static function codeSelectOptions(?string $locale = null): array
    {
        $out = [];
        foreach (self::all() as $def) {
            $out[(string) $def['code']] = match ($locale) {
                'km' => "{$def['code']} — {$def['khmer']}",
                'en' => (string) $def['label'],
                default => (string) $def['bilingual'],
            };
        }

        return $out;
    }

    /**
     * Parse any supported representation into canonical integer 1..max (or null if invalid/empty).
     * Never guesses from academic year strings like "2026-2027".
     */
    public static function parse(mixed $value, ?int $max = null): ?int
    {
        if ($value === null || is_bool($value) || is_array($value) || is_object($value)) {
            return null;
        }

        $maxAllowed = $max !== null ? min($max, self::maxLevel()) : self::maxLevel();

        if (is_int($value) || is_float($value)) {
            $num = (int) $value;
            if ((float) $num !== (float) $value) {
                return null;
            }

            return ($num >= 1 && $num <= $maxAllowed) ? $num : null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        // Convert Khmer numerals to ASCII digits before pattern checks.
        $normalized = strtr($raw, self::KHMER_TO_ARABIC);

        // Reject academic year strings such as 2025-2026 or 2026–2027.
        if (preg_match('/\d{4}/', $normalized)) {
            return null;
        }

        $candidate = null;

        if (ctype_digit($normalized)) {
            $candidate = (int) $normalized;
        } elseif (preg_match('/^y\s*0*([1-9]\d*)(?:\b|[\s—–\-\/:|].*)$/iu', $normalized, $m)) {
            $candidate = (int) $m[1];
        } elseif (preg_match('/^(?:year|yr|level)\s*0*([1-9]\d*)(?:\s*[\(\[]?\s*y\1\s*[\)\]]?)?$/iu', $normalized, $m)) {
            $candidate = (int) $m[1];
        } elseif (preg_match('/^([1-9]\d*)(?:st|nd|rd|th)?\s+year$/iu', $normalized, $m)) {
            $candidate = (int) $m[1];
        } elseif (preg_match('/^ឆ្នាំទី\s*0*([1-9]\d*)$/u', $normalized, $m)) {
            $candidate = (int) $m[1];
        } else {
            $lower = mb_strtolower(preg_replace('/\s+/', ' ', $normalized));
            $candidate = match ($lower) {
                'first year', 'year one', 'freshman' => 1,
                'second year', 'year two', 'sophomore' => 2,
                'third year', 'year three', 'junior' => 3,
                'fourth year', 'year four', 'senior' => 4,
                default => null,
            };
        }

        if ($candidate === null || $candidate < 1 || $candidate > $maxAllowed) {
            return null;
        }

        return isset(self::all()[$candidate]) ? $candidate : null;
    }

    public static function isValid(mixed $value, ?int $max = null): bool
    {
        return self::parse($value, $max) !== null;
    }

    public static function storageValue(mixed $value): ?string
    {
        $n = self::parse($value);

        return $n !== null ? (string) $n : null;
    }

    public static function definition(mixed $value): ?array
    {
        $n = self::parse($value);

        return $n !== null ? (self::all()[$n] ?? null) : null;
    }

    public static function code(mixed $value): ?string
    {
        return self::definition($value)['code'] ?? null;
    }

    public static function name(mixed $value): ?string
    {
        return self::definition($value)['name'] ?? null;
    }

    public static function khmer(mixed $value): ?string
    {
        return self::definition($value)['khmer'] ?? null;
    }

    public static function label(mixed $value, ?string $locale = null): ?string
    {
        $def = self::definition($value);
        if (! $def) {
            return null;
        }

        $loc = $locale ?? app()->getLocale();

        return $loc === 'km'
            ? "{$def['code']} — {$def['khmer']}"
            : (string) $def['label'];
    }

    public static function bilingual(mixed $value): ?string
    {
        return self::definition($value)['bilingual'] ?? null;
    }

    /**
     * All plausible persisted variants in `students.year_level` for safe query matching.
     *
     * @return array<int, string>
     */
    public static function dbVariants(mixed $value): array
    {
        $def = self::definition($value);
        if (! $def) {
            return [];
        }

        $n = (string) $def['number'];
        $code = (string) $def['code'];
        $name = (string) $def['name'];

        return array_values(array_unique([
            $n,
            $code,
            strtolower($code),
            $name,
            strtolower($name),
            strtoupper($name),
            (string) $def['khmer'],
        ]));
    }
}
