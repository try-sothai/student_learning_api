<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends Model
{
    protected $fillable = ['department_id', 'code', 'name', 'description', 'duration_years', 'status'];

    protected function casts(): array
    {
        return [
            'department_id' => 'integer',
            'duration_years' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'program_id');
    }

    public function courseOfferings(): HasMany
    {
        return $this->hasMany(CourseOffering::class, 'program_id');
    }

    /** Courses assigned to this Major (many-to-many, shared courses supported). */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_program')->withTimestamps();
    }

    public function curriculumItems(): HasMany
    {
        return $this->hasMany(CurriculumItem::class, 'program_id');
    }

    public function academicPeriods(): HasMany
    {
        return $this->hasMany(MajorAcademicPeriod::class, 'program_id');
    }
}
