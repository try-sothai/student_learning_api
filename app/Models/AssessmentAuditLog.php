<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentAuditLog extends Model
{
    protected $fillable = [
        'assessment_type',
        'assessment_id',
        'student_id',
        'class_id',
        'teacher_id',
        'performed_by',
        'action_type',
        'previous_deadline',
        'new_deadline',
        'previous_attempt_limit',
        'new_attempt_limit',
        'previous_settings',
        'new_settings',
        'reason',
        'teacher_notes',
    ];

    protected function casts(): array
    {
        return [
            'assessment_id' => 'integer',
            'student_id' => 'integer',
            'class_id' => 'integer',
            'teacher_id' => 'integer',
            'performed_by' => 'integer',
            'previous_deadline' => 'datetime',
            'new_deadline' => 'datetime',
            'previous_attempt_limit' => 'integer',
            'new_attempt_limit' => 'integer',
            'previous_settings' => 'array',
            'new_settings' => 'array',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function classRoom(): BelongsTo
    {
        return $this->belongsTo(ClassRoom::class, 'class_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
