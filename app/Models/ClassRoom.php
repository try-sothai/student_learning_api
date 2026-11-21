<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Maps to the `classes` table. Named ClassRoom because `Class` is a reserved word in PHP.
 */
class ClassRoom extends Model
{
    use HasFactory;

    protected $table = 'classes';

    protected $fillable = [
        'department_id',
        'academic_year_id',
        'building_id',
        'floor_id',
        'course_id',
        'teacher_id',
        'name',
        'code',
        'year_level',
        'day_of_week',
        'start_time',
        'end_time',
        'room',
        'capacity',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'year_level' => 'integer',
            'capacity' => 'integer',
        ];
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ClassSchedule::class, 'class_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'class_id');
    }

    public function offerings(): HasMany
    {
        return $this->hasMany(CourseOffering::class, 'class_id');
    }
}
