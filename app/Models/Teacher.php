<?php

namespace App\Models;

use App\Support\PersonNameHelper;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Teacher extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'teachers';

    protected $fillable = [
        'user_id',
        'teacher_id',
        'teacher_code',
        'khmer_name',
        'english_name',
        'department_id',
        'first_name',
        'last_name',
        'date_of_birth',
        'hire_date',
        'gender',
        'phone',
        'office_location_id',
        'position',
        'work_type',
        'specialization',
        'biography',
        'status',
    ];

    /**
     * Keeps the pre-existing API response key `teacher_id` (the teacher code)
     * plus computed profile accessors (`office_location`, `specializations`, `bio`).
     */
    protected $appends = ['teacher_id', 'office_location', 'specializations', 'bio'];

    protected static function booted(): void
    {
        static::creating(function (Teacher $teacher) {
            if (empty($teacher->teacher_code) && !empty($teacher->attributes['teacher_id'] ?? null)) {
                $teacher->teacher_code = $teacher->attributes['teacher_id'];
                unset($teacher->attributes['teacher_id']);
            }
            if (empty($teacher->teacher_code)) {
                $nextId = (static::withTrashed()->max('id') ?? 0) + 1;
                $teacher->teacher_code = 'TCH' . str_pad((string) $nextId, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function setTeacherIdAttribute($value): void
    {
        $this->attributes['teacher_code'] = $value;
    }

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date:Y-m-d',
            'hire_date' => 'date:Y-m-d',
        ];
    }

    public function resolveDisplayName(): string
    {
        return PersonNameHelper::displayName(
            $this->english_name,
            $this->khmer_name,
            $this->first_name,
            $this->last_name,
            (string) ($this->teacher_code ?? '')
        );
    }

    protected function teacherId(): Attribute
    {
        return Attribute::get(fn () => $this->teacher_code);
    }

    protected function officeLocation(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->office_location_id) {
                return null;
            }
            if ($this->relationLoaded('office')) {
                return $this->getRelation('office')?->display_name;
            }

            return $this->office()->value('display_name');
        });
    }

    protected function bio(): Attribute
    {
        return Attribute::get(fn () => $this->biography);
    }

    protected function specializations(): Attribute
    {
        return Attribute::get(function () {
            $names = $this->relationLoaded('specializationItems')
                ? $this->specializationItems
                    ->sortBy(fn ($item) => $item->pivot?->id ?? PHP_INT_MAX)
                    ->pluck('name')
                    ->values()
                    ->all()
                : $this->specializationItems()->pluck('name')->values()->all();

            if ($names === [] && ! empty($this->specialization)) {
                return array_values(array_filter(array_map('trim', explode(',', (string) $this->specialization))));
            }

            return $names;
        });
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(OfficeLocation::class, 'office_location_id');
    }

    public function specializationItems(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Specialization::class, 'teacher_specialization')
            ->withPivot('id')
            ->orderBy('teacher_specialization.id')
            ->withTimestamps();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function courseOfferings(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(CourseOffering::class, 'course_offering_teachers')
            ->withPivot(['id', 'role', 'status', 'assigned_at', 'ended_at'])
            ->withTimestamps();
    }

    public function activeCourseOfferings(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->courseOfferings()->wherePivot('status', 'active');
    }

    public function courseOfferingTeachers(): HasMany
    {
        return $this->hasMany(CourseOfferingTeacher::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function classes(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(ClassRoom::class, 'class_teacher', 'teacher_id', 'class_id')
            ->withPivot('course_id')
            ->withTimestamps();
    }

    public function facultyReviews(): HasMany
    {
        return $this->hasMany(FacultyReview::class);
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

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }
}