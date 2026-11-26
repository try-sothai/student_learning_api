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

class CourseOffering extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'course_offerings';

    protected $fillable = [
        'course_id',
        'class_id',
        'semester_id',
        'academic_year_id',
        'program_id',
        'study_year',
        'section_name',
        'status',
    ];

    protected $appends = ['year_level', 'year_level_code', 'year_level_name', 'year_level_khmer', 'year_level_label'];

    protected function casts(): array
    {
        return [
            'study_year' => 'integer',
        ];
    }

    protected function yearLevel(): Attribute
    {
        return Attribute::get(fn () => $this->study_year ? (int) $this->study_year : null);
    }

    protected function yearLevelCode(): Attribute
    {
        return Attribute::get(fn () => YearLevel::code($this->study_year));
    }

    protected function yearLevelName(): Attribute
    {
        return Attribute::get(fn () => YearLevel::name($this->study_year));
    }

    protected function yearLevelKhmer(): Attribute
    {
        return Attribute::get(fn () => YearLevel::khmer($this->study_year));
    }

    protected function yearLevelLabel(): Attribute
    {
        return Attribute::get(fn () => YearLevel::label($this->study_year));
    }

    public function scopeForYearLevel(Builder $query, mixed $yearLevel): Builder
    {
        $parsed = YearLevel::parse($yearLevel);
        if ($parsed === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('study_year', $parsed);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function classRoom(): BelongsTo
    {
        return $this->belongsTo(ClassRoom::class, 'class_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(Teacher::class, 'course_offering_teachers')
            ->withPivot(['id', 'role', 'status', 'assigned_at', 'ended_at'])
            ->withTimestamps();
    }

    public function activeTeachers(): BelongsToMany
    {
        return $this->teachers()->wherePivot('status', 'active');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class, 'course_offering_id');
    }

    public function attendanceSessions(): HasMany
    {
        return $this->hasMany(Attendance::class, 'course_offering_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'course_offering_id');
    }

    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class, 'course_offering_id');
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class, 'course_offering_id');
    }

    public function riskAssessments(): HasMany
    {
        return $this->hasMany(RiskAssessment::class, 'course_offering_id');
    }
}
