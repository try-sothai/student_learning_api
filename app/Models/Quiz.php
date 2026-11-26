<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quiz extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const ALLOWED_MAX_ATTEMPTS = [1, 2, 3, 5];

    public const SCORING_METHODS = ['highest', 'latest', 'average'];

    protected $fillable = [
        'course_id',
        'course_offering_id',
        'teacher_id',
        'semester_id',
        'title',
        'description',
        'total_score',
        'duration_minutes',
        'start_at',
        'end_at',
        'max_attempts',
        'scoring_method',
        'allow_late_submission',
        'reopened_at',
        'reopened_until',
        'reopened_by',
        'version',
        'status',
    ];

    protected $attributes = [
        'max_attempts' => 1,
        'scoring_method' => 'highest',
        'allow_late_submission' => false,
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'total_score' => 'float',
            'duration_minutes' => 'integer',
            'max_attempts' => 'integer',
            'allow_late_submission' => 'boolean',
            'version' => 'integer',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'reopened_at' => 'datetime',
            'reopened_until' => 'datetime',
        ];
    }

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('question_order');
    }

    public function allQuestions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->withTrashed()->orderBy('question_order');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function studentOverrides(): HasMany
    {
        return $this->hasMany(QuizStudentOverride::class);
    }

    public function overrideLogs(): HasMany
    {
        return $this->hasMany(QuizOverrideLog::class)->latest('id');
    }
}
