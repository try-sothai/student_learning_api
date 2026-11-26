<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuizQuestion extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'quiz_id',
        'question_text',
        'question_type',
        'marks',
        'is_required',
        'question_order',
        'option_a',
        'option_b',
        'option_c',
        'option_d',
        'correct_answer',
        'grading_guidance',
    ];

    protected $attributes = [
        'is_required' => true,
    ];

    /**
     * The answer key must never leak to students through the API.
     * Teacher/admin endpoints can call ->makeVisible('correct_answer').
     */
    protected $hidden = ['correct_answer', 'grading_guidance'];

    protected function casts(): array
    {
        return [
            'marks' => 'float',
            'is_required' => 'boolean',
            'question_order' => 'integer',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class, 'question_id');
    }

    public function effectiveGuidance(): ?string
    {
        $guidance = trim((string) ($this->grading_guidance ?? ''));
        if ($guidance !== '') {
            return $guidance;
        }

        $correct = trim((string) ($this->correct_answer ?? ''));

        return $correct !== '' ? $correct : null;
    }

    /**
     * Immutable snapshot representation stored with quiz_attempts / quiz_answers.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'id' => (int) $this->id,
            'question_order' => (int) $this->question_order,
            'question_text' => (string) $this->question_text,
            'question_type' => (string) $this->question_type,
            'marks' => (float) $this->marks,
            'is_required' => $this->is_required !== false,
            'option_a' => $this->option_a,
            'option_b' => $this->option_b,
            'option_c' => $this->option_c,
            'option_d' => $this->option_d,
            'correct_answer' => $this->correct_answer,
            'grading_guidance' => $this->effectiveGuidance(),
        ];
    }
}
