<?php

namespace App\Models;

use App\Support\YearLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    protected $appends = ['year_level_code', 'year_level_name', 'year_level_khmer', 'year_level_label'];

    protected static function booted(): void
    {
        static::saving(function (self $classRoom) {
            if (empty($classRoom->code) && ! empty($classRoom->name)) {
                $classRoom->code = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '-', (string) $classRoom->name));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'year_level' => 'integer',
            'capacity' => 'integer',
        ];
    }

    protected function yearLevelCode(): Attribute
    {
        return Attribute::get(fn () => YearLevel::code($this->year_level));
    }

    protected function yearLevelName(): Attribute
    {
        return Attribute::get(fn () => YearLevel::name($this->year_level));
    }

    protected function yearLevelKhmer(): Attribute
    {
        return Attribute::get(fn () => YearLevel::khmer($this->year_level));
    }

    protected function yearLevelLabel(): Attribute
    {
        return Attribute::get(fn () => YearLevel::label($this->year_level));
    }

    public function scopeForYearLevel(Builder $query, mixed $yearLevel): Builder
    {
        $parsed = YearLevel::parse($yearLevel);
        if ($parsed === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('year_level', $parsed);
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
