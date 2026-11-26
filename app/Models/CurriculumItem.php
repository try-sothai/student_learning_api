<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurriculumItem extends Model
{
    protected $table = 'curriculum_items';

    protected $fillable = [
        'program_id',
        'course_id',
        'course_category_id',
        'curriculum_version',
        'study_year',
        'semester_number',
        'semester_name',
        'credits',
        'is_required',
        'display_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'program_id' => 'integer',
            'course_id' => 'integer',
            'course_category_id' => 'integer',
            'study_year' => 'integer',
            'semester_number' => 'integer',
            'credits' => 'float',
            'is_required' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CourseCategory::class, 'course_category_id');
    }
}
