<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuizAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_id',
        'student_id',
        'attempt_number',
        'started_at',
        'submitted_at',
        'score',
        'auto_graded_score',
        'manual_graded_score',
        'total_marks_snapshot',
        'quiz_version',
        'questions_snapshot',
        'is_late',
        'is_reset',
        'override_id',
        'graded_by',
        'graded_at',
        'status',
        'grading_status',
    ];

    protected $attributes = [
        'grading_status' => 'not_submitted',
        'quiz_version' => 1,
        'is_late' => false,
        'is_reset' => false,
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'graded_at' => 'datetime',
            'score' => 'float',
            'auto_graded_score' => 'float',
            'manual_graded_score' => 'float',
            'total_marks_snapshot' => 'float',
            'quiz_version' => 'integer',
            'questions_snapshot' => 'array',
            'is_late' => 'boolean',
            'is_reset' => 'boolean',
            'override_id' => 'integer',
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

    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class, 'quiz_attempt_id');
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    public function override(): BelongsTo
    {
        return $this->belongsTo(QuizStudentOverride::class, 'override_id');
    }

    public function isPendingManualGrading(): bool
    {
        return $this->grading_status === 'pending_grading' || $this->status === 'submitted';
    }

    public function isFinalized(): bool
    {
        if ($this->status === 'in_progress') {
            return false;
        }
        if ($this->isPendingManualGrading()) {
            return false;
        }

        return in_array($this->status, ['completed', 'graded', 'timed_out'], true);
    }
}
