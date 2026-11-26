<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_attempt_id',
        'question_id',
        'answer',
        'is_correct',
        'marks_obtained',
        'grading_status',
        'teacher_feedback',
        'graded_by',
        'graded_at',
        'question_snapshot',
    ];

    protected $attributes = [
        'grading_status' => 'pending_grading',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'marks_obtained' => 'float',
            'graded_at' => 'datetime',
            'question_snapshot' => 'array',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class, 'quiz_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuizQuestion::class, 'question_id')->withTrashed();
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }
}
