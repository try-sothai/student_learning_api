<?php

namespace App\Models;

use App\Support\YearLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'department_id',
        'course_category_id',
        'year_level',
        'teacher_id',
        'name',
        'code',
        'course_code',
        'course_name',
        'department',
        'description',
        'credit',
        'credits',
        'academic_year',
        'semester',
        'day_of_week',
        'start_time',
        'end_time',
        'room',
        'status',
    ];

    protected $appends = ['course_code', 'course_name', 'code', 'name', 'credits', 'year_level_code', 'year_level_name', 'year_level_khmer', 'year_level_label'];

    protected function casts(): array
    {
        return [
            'year_level' => 'integer',
            'credit' => 'float',
            'credits' => 'float',
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

        return $query->where(function (Builder $q) use ($parsed) {
            $q->where('year_level', $parsed)
                ->orWhereHas('offerings', fn (Builder $oq) => $oq->where('study_year', $parsed));
        });
    }

    protected function courseCode(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attributes['course_code'] ?? $this->attributes['code'] ?? null,
            set: fn ($value) => [
                'course_code' => $value,
                'code' => $value,
            ]
        );
    }

    protected function code(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attributes['code'] ?? $this->attributes['course_code'] ?? null,
            set: fn ($value) => [
                'code' => $value,
                'course_code' => $value,
            ]
        );
    }

    protected function courseName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attributes['course_name'] ?? $this->attributes['name'] ?? null,
            set: fn ($value) => [
                'course_name' => $value,
                'name' => $value,
            ]
        );
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attributes['name'] ?? $this->attributes['course_name'] ?? null,
            set: fn ($value) => [
                'name' => $value,
                'course_name' => $value,
            ]
        );
    }

    protected function credits(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attributes['credit'] ?? $this->attributes['credits'] ?? null,
            set: fn ($value) => [
                'credit' => $value,
                'credits' => $value,
            ]
        );
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function departmentRelation(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /** Course Category (type of course) - separate from Faculty and Major. */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CourseCategory::class, 'course_category_id');
    }

    /** Majors this course is assigned to (shared courses belong to several). */
    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(Program::class, 'course_program')->withTimestamps();
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function offerings(): HasMany
    {
        return $this->hasMany(CourseOffering::class, 'course_id');
    }

    public function classes(): HasMany
    {
        return $this->hasMany(ClassRoom::class, 'course_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'course_enrollments')
            ->withPivot([
                'semester_id',
                'enrollment_date',
                'status',
                'final_score',
                'grade',
            ])
            ->withTimestamps();
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    public function attendanceSessions(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class);
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function performanceRecords(): HasMany
    {
        return $this->hasMany(PerformanceRecord::class);
    }
}