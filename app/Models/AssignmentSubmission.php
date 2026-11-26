<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'assignment_id',
        'student_id',
        'version_number',
        'submitted_at',
        'is_late',
        'score',
        'feedback',
        'status',
        'is_latest',
        'override_id',
        'file_path',
        'original_file_name',
        'mime_type',
        'file_size',
        'submission_text',
    ];

    protected $attributes = [
        'version_number' => 1,
        'is_late' => false,
        'is_latest' => true,
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'submitted_at' => 'datetime',
            'is_late' => 'boolean',
            'score' => 'float',
            'is_latest' => 'boolean',
            'override_id' => 'integer',
            'file_size' => 'integer',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function override(): BelongsTo
    {
        return $this->belongsTo(AssessmentStudentOverride::class, 'override_id');
    }
}
