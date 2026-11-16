<?php

namespace App\Models;

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

    protected function casts(): array
    {
        return [
            'study_year' => 'integer',
        ];
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
