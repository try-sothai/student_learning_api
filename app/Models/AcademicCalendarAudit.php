<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicCalendarAudit extends Model
{
    protected $table = 'academic_calendar_audits';

    protected $fillable = [
        'major_academic_period_id',
        'semester_id',
        'program_id',
        'previous_start_date',
        'new_start_date',
        'previous_end_date',
        'new_end_date',
        'previous_status',
        'new_status',
        'changed_by',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'major_academic_period_id' => 'integer',
            'semester_id' => 'integer',
            'program_id' => 'integer',
            'changed_by' => 'integer',
            'previous_start_date' => 'date:Y-m-d',
            'new_start_date' => 'date:Y-m-d',
            'previous_end_date' => 'date:Y-m-d',
            'new_end_date' => 'date:Y-m-d',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(MajorAcademicPeriod::class, 'major_academic_period_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
