<?php

namespace App\Rules;

use App\Support\YearLevel;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidYearLevel implements ValidationRule
{
    public function __construct(private ?int $maxYears = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $parsed = YearLevel::parse($value);
        if ($parsed === null) {
            $fail("The {$attribute} must be a valid student year level (Y1, Y2, Y3, or Y4).");

            return;
        }

        if ($this->maxYears !== null && $parsed > $this->maxYears) {
            $fail("The {$attribute} exceeds the program duration ({$this->maxYears} years).");
        }
    }
}
