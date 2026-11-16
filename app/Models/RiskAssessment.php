<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskAssessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'course_id',
        'course_offering_id',
        'semester_id',
        'risk_level',
        'risk_score',
        'risk_factors',
        'assessment_date',
        'status',
    ];

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class);
    }

    protected function casts(): array
    {
        return [
            'risk_score' => 'float',
            'risk_factors' => 'array',
            'assessment_date' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }
}
