<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Semester extends Model
{
    use HasFactory;

    protected $fillable = [
        'academic_year_id',
        'name',
        'semester_number',
        'start_date',
        'end_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'semester_number' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $semester) {
            if ($semester->semester_number === null) {
                if (preg_match('/(\d+)/', (string) $semester->name, $m)) {
                    $semester->semester_number = (int) $m[1];
                } else {
                    $semester->semester_number = 1;
                }
            }
        });
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function courseEnrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function gpaRecords(): HasMany
    {
        return $this->hasMany(GpaRecord::class);
    }
}
