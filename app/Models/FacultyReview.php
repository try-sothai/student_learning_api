<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacultyReview extends Model
{
    protected $fillable = [
        'teacher_id', 'semester_id', 'reviewer_id', 'rating', 'status', 'comments', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['rating' => 'float', 'reviewed_at' => 'datetime'];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
