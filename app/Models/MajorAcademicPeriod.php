<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MajorAcademicPeriod extends Model
{
    protected $table = 'major_academic_periods';

    protected $fillable = [
        'program_id',
        'academic_year_id',
        'semester_id',
        'study_year',
        'semester_number',
        'semester_name',
        'sequence_order',
        'start_date',
        'end_date',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'program_id' => 'integer',
            'academic_year_id' => 'integer',
            'semester_id' => 'integer',
            'study_year' => 'integer',
            'semester_number' => 'integer',
            'sequence_order' => 'integer',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(AcademicCalendarAudit::class, 'major_academic_period_id');
    }
}
