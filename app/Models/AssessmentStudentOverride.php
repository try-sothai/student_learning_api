<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentStudentOverride extends Model
{
    protected $fillable = [
        'assessment_type',
        'assessment_id',
        'student_id',
        'class_id',
        'teacher_id',
        'granted_by',
        'allow_submission',
        'allow_resubmission',
        'allow_late_submission',
        'additional_attempts',
        'extended_deadline',
        'attempts_reset_at',
        'reason',
        'teacher_notes',
        'is_active',
        'revoked_at',
        'revoked_by',
        'revoke_reason',
    ];

    protected $attributes = [
        'allow_submission' => true,
        'allow_resubmission' => false,
        'allow_late_submission' => false,
        'additional_attempts' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'assessment_id' => 'integer',
            'student_id' => 'integer',
            'class_id' => 'integer',
            'allow_submission' => 'boolean',
            'allow_resubmission' => 'boolean',
            'allow_late_submission' => 'boolean',
            'additional_attempts' => 'integer',
            'extended_deadline' => 'datetime',
            'attempts_reset_at' => 'datetime',
            'is_active' => 'boolean',
            'revoked_at' => 'datetime',
        ];
    }

    public function scopeForAssessment(Builder $query, string $type, int $assessmentId): Builder
    {
        return $query->where('assessment_type', strtolower($type))
            ->where('assessment_id', $assessmentId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
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

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
