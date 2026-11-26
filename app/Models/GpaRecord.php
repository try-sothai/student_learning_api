<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GpaRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'academic_year_id',
        'semester_id',
        'study_year',
        'semester_number',
        'gpa',
        'semester_average',
        'total_credits',
        'earned_credits',
        'academic_standing',
        'is_official',
    ];

    protected function casts(): array
    {
        return [
            'academic_year_id' => 'integer',
            'study_year' => 'integer',
            'semester_number' => 'integer',
            'gpa' => 'float',
            'semester_average' => 'float',
            'total_credits' => 'float',
            'earned_credits' => 'float',
            'is_official' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }
}
