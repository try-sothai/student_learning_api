<?php

namespace App\Models;

use App\Support\PersonNameHelper;
use App\Support\YearLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'students';

    protected $fillable = [
        'user_id',
        'student_id',
        'student_code',
        'khmer_name',
        'english_name',
        'place_of_birth',
        'program_id',
        'department_id',
        'class_id',
        'first_name',
        'last_name',
        'date_of_birth',
        'gender',
        'nationality',
        'phone',
        'address',
        'current_address',
        'major',
        'year_level',
        'current_academic_year_id',
        'current_semester_id',
        'current_study_year',
        'current_semester_number',
        'curriculum_version',
        'progression_status',
        'last_progressed_at',
        'advisor',
        'enrollment_date',
        'status',
    ];

    /**
     * Keeps the pre-existing API response key `student_id`
     * while the database column is `student_code`, plus standardized Year Level fields.
     */
    protected $appends = [
        'student_id',
        'current_address',
        'year_level_code',
        'year_level_name',
        'year_level_khmer',
        'year_level_label',
    ];

    protected static function booted(): void
    {
        static::creating(function (Student $student) {
            if (empty($student->student_code) && !empty($student->attributes['student_id'] ?? null)) {
                $student->student_code = $student->attributes['student_id'];
                unset($student->attributes['student_id']);
            }
            if (empty($student->student_code)) {
                $nextId = (static::withTrashed()->max('id') ?? 0) + 1;
                $student->student_code = 'STD' . str_pad((string) $nextId, 3, '0', STR_PAD_LEFT);
            }
        });

        static::saving(function (Student $student) {
            if (array_key_exists('year_level', $student->attributes) && $student->attributes['year_level'] !== null) {
                $raw = trim((string) $student->attributes['year_level']);
                if ($raw === '') {
                    $student->attributes['year_level'] = null;
                } else {
                    $canonical = YearLevel::storageValue($raw);
                    if ($canonical !== null) {
                        $student->attributes['year_level'] = $canonical;
                    }
                }
            }
        });
    }

    public function setStudentIdAttribute($value): void
    {
        $this->attributes['student_code'] = $value;
    }

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date:Y-m-d',
            'enrollment_date' => 'date:Y-m-d',
            'current_academic_year_id' => 'integer',
            'current_semester_id' => 'integer',
            'current_study_year' => 'integer',
            'current_semester_number' => 'integer',
            'last_progressed_at' => 'datetime',
        ];
    }

    public function resolveDisplayName(): string
    {
        return PersonNameHelper::displayName(
            $this->english_name,
            $this->khmer_name,
            $this->first_name,
            $this->last_name,
            (string) ($this->student_code ?? '')
        );
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

    public function resolveAcademicYearName(): ?string
    {
        if ($this->relationLoaded('classRoom') && $this->classRoom) {
            if ($this->classRoom->relationLoaded('academicYear')) {
                return $this->classRoom->academicYear?->name;
            }

            return $this->classRoom->academicYear()->value('name');
        }

        if ($this->class_id) {
            return $this->classRoom()->with('academicYear:id,name')->first()?->academicYear?->name;
        }

        if ($this->relationLoaded('courseEnrollments')) {
            $latest = $this->courseEnrollments->sortByDesc('semester_id')->first();

            return $latest?->semester?->academicYear?->name;
        }

        return $this->courseEnrollments()
            ->with('semester.academicYear:id,name')
            ->orderByDesc('semester_id')
            ->first()?->semester?->academicYear?->name;
    }

    public function resolveSemesterName(): ?string
    {
        if ($this->relationLoaded('courseEnrollments')) {
            $active = $this->courseEnrollments
                ->where('status', 'enrolled')
                ->sortByDesc('semester_id')
                ->first() ?? $this->courseEnrollments->sortByDesc('semester_id')->first();

            return $active?->semester?->name;
        }

        return $this->courseEnrollments()
            ->with('semester:id,name')
            ->where('status', 'enrolled')
            ->orderByDesc('semester_id')
            ->first()?->semester?->name
            ?? $this->courseEnrollments()->with('semester:id,name')->orderByDesc('semester_id')->first()?->semester?->name;
    }

    protected function academicYear(): Attribute
    {
        return Attribute::get(fn () => $this->attributes['academic_year'] ?? $this->resolveAcademicYearName());
    }

    protected function semester(): Attribute
    {
        return Attribute::get(fn () => $this->attributes['semester'] ?? $this->resolveSemesterName());
    }

    public function scopeForYearLevel(Builder $query, mixed $yearLevel): Builder
    {
        $variants = YearLevel::dbVariants($yearLevel);
        if ($variants === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('year_level', $variants);
    }

    public function scopeMissingYearLevel(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('year_level')->orWhere('year_level', '');
        });
    }

    protected function studentId(): Attribute
    {
        return Attribute::get(fn () => $this->student_code);
    }

    protected function currentAddress(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attributes['address'] ?? null,
            set: fn ($value) => ['address' => $value],
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function familyInformation(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StudentFamilyInformation::class);
    }

    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(StudentEmergencyContact::class);
    }

    public function classRoom(): BelongsTo
    {
        return $this->belongsTo(ClassRoom::class, 'class_id');
    }

    /**
     * Courses the student is/was enrolled in.
     */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(
            Course::class,
            'course_enrollments'
        )->withPivot([
            'course_offering_id',
            'semester_id',
            'year_level',
            'enrollment_date',
            'status',
            'final_score',
            'grade',
        ])->withTimestamps();
    }

    public function courseEnrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    public function attendanceDetails(): HasMany
    {
        return $this->hasMany(AttendanceDetail::class);
    }

    public function assignmentSubmissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    public function quizAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function examResults(): HasMany
    {
        return $this->hasMany(ExamResult::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function gpaRecords(): HasMany
    {
        return $this->hasMany(GpaRecord::class);
    }

    public function performanceRecords(): HasMany
    {
        return $this->hasMany(PerformanceRecord::class);
    }

    public function learningAnalytics(): HasMany
    {
        return $this->hasMany(LearningAnalytic::class);
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }

    public function riskAssessments(): HasMany
    {
        return $this->hasMany(RiskAssessment::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(StudentNote::class);
    }

    public function currentAcademicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'current_academic_year_id');
    }

    public function currentSemester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'current_semester_id');
    }

    public function progressionHistories(): HasMany
    {
        return $this->hasMany(StudentProgressionHistory::class, 'student_id');
    }

    public function retakes(): HasMany
    {
        return $this->hasMany(StudentCourseRetake::class, 'student_id');
    }
}