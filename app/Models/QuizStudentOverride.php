<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizStudentOverride extends Model
{
    protected $fillable = [
        'quiz_id',
        'student_id',
        'teacher_id',
        'granted_by',
        'extra_attempts',
        'extended_end_at',
        'allow_late_submission',
        'attempts_reset_at',
        'reason',
        'is_active',
    ];

    protected $attributes = [
        'extra_attempts' => 0,
        'allow_late_submission' => false,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'extra_attempts' => 'integer',
            'extended_end_at' => 'datetime',
            'allow_late_submission' => 'boolean',
            'attempts_reset_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
