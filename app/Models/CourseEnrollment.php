<?php

namespace App\Models;

use App\Support\YearLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseEnrollment extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'course_id',
        'course_offering_id',
        'academic_year_id',
        'semester_id',
        'semester_number',
        'year_level',
        'enrollment_date',
        'status',
        'enrollment_type',
        'attempt_number',
        'original_enrollment_id',
        'retake_status',
        'final_score',
        'grade',
        'finalized_at',
    ];

    protected $appends = ['year_level_code', 'year_level_label'];

    protected function casts(): array
    {
        return [
            'academic_year_id' => 'integer',
            'semester_number' => 'integer',
            'year_level' => 'integer',
            'attempt_number' => 'integer',
            'original_enrollment_id' => 'integer',
            'enrollment_date' => 'date',
            'final_score' => 'float',
            'finalized_at' => 'datetime',
        ];
    }

    protected function yearLevelCode(): Attribute
    {
        return Attribute::get(fn () => YearLevel::code($this->year_level ?? $this->courseOffering?->study_year));
    }

    protected function yearLevelLabel(): Attribute
    {
        return Attribute::get(fn () => YearLevel::label($this->year_level ?? $this->courseOffering?->study_year));
    }

    public function scopeForYearLevel(Builder $query, mixed $yearLevel): Builder
    {
        $parsed = YearLevel::parse($yearLevel);
        if ($parsed === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($parsed) {
            $q->where('year_level', $parsed)
                ->orWhere(function (Builder $sub) use ($parsed) {
                    $sub->whereNull('year_level')
                        ->whereHas('courseOffering', fn (Builder $oq) => $oq->where('study_year', $parsed));
                });
        });
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id');
    }

    public function originalEnrollment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_enrollment_id');
    }
}
