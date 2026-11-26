<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizOverrideLog extends Model
{
    protected $fillable = [
        'quiz_id',
        'student_id',
        'teacher_id',
        'acted_by',
        'action',
        'reason',
        'previous_setting',
        'new_setting',
    ];

    protected function casts(): array
    {
        return [
            'previous_setting' => 'array',
            'new_setting' => 'array',
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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }
}
