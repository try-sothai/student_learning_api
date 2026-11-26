<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_offering_id',
        'venue',
        'course_id',
        'teacher_id',
        'semester_id',
        'title',
        'exam_type',
        'exam_date',
        'start_time',
        'end_time',
        'total_score',
        'duration_minutes',
        'description',
        'status',
        'results_published_at',
    ];

    protected function casts(): array
    {
        return [
            'exam_date' => 'date:Y-m-d',
            'total_score' => 'float',
            'duration_minutes' => 'integer',
            'results_published_at' => 'datetime',
        ];
    }

    public function isResultsPublished(): bool
    {
        return in_array($this->status, ['published', 'completed'], true) || ! is_null($this->results_published_at);
    }

    public function displayStatus(?int $resultsCount = null): string
    {
        $status = strtolower((string) ($this->status ?? 'scheduled'));

        if ($status === 'cancelled') {
            return 'Cancelled';
        }
        if ($status === 'draft') {
            return 'Draft';
        }
        if ($status === 'published' || ! is_null($this->results_published_at)) {
            return 'Results Published';
        }

        $hasScores = $resultsCount !== null
            ? $resultsCount > 0
            : ($this->relationLoaded('results')
                ? $this->results->contains(fn ($r) => $r->score !== null)
                : $this->results()->whereNotNull('score')->exists());

        if ($status === 'completed') {
            return $hasScores ? 'Results Published' : 'Completed';
        }
        if ($status === 'results_pending') {
            return 'Results Pending';
        }

        $isPastDate = $this->exam_date && \Carbon\Carbon::parse($this->exam_date)->startOfDay()->lt(now()->startOfDay());
        if ($isPastDate || $hasScores) {
            return 'Results Pending';
        }

        return 'Scheduled';
    }

    public static function normalizeTime(?string $time): ?string
    {
        if ($time === null) {
            return null;
        }
        $trimmed = trim($time);
        if ($trimmed === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $trimmed, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            if ($h >= 0 && $h <= 23 && $min >= 0 && $min <= 59) {
                return sprintf('%02d:%02d', $h, $min);
            }
        }

        return $trimmed;
    }

    public static function computeEndTime(?string $startTime, ?int $durationMinutes): ?string
    {
        $normalized = self::normalizeTime($startTime);
        if ($normalized === null || $durationMinutes === null || $durationMinutes <= 0) {
            return null;
        }
        if (! preg_match('/^\d{2}:\d{2}$/', $normalized)) {
            return null;
        }

        try {
            return \Carbon\Carbon::createFromFormat('H:i', $normalized)
                ->addMinutes($durationMinutes)
                ->format('H:i');
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function calculateLetterGrade(float $score, float $maxScore): string
    {
        if ($maxScore <= 0) {
            return 'N/A';
        }
        $pct = ($score / $maxScore) * 100;

        return match (true) {
            $pct >= 90 => 'A',
            $pct >= 85 => 'A-',
            $pct >= 80 => 'B+',
            $pct >= 75 => 'B',
            $pct >= 70 => 'B-',
            $pct >= 65 => 'C+',
            $pct >= 60 => 'C',
            $pct >= 50 => 'D',
            default => 'F',
        };
    }

    public static function calculatePassStatus(float $score, float $maxScore): string
    {
        if ($maxScore <= 0) {
            return 'N/A';
        }

        return (($score / $maxScore) * 100) >= 50.0 ? 'Pass' : 'Fail';
    }

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(ExamResult::class);
    }
}
