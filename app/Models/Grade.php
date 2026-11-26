<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Grade extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'course_id',
        'course_offering_id',
        'semester_id',
        'study_year',
        'semester_number',
        'assignment_score',
        'quiz_score',
        'attendance_score',
        'midterm_score',
        'final_exam_score',
        'total_score',
        'letter_grade',
        'grade_point',
        'status',
        'attempt_number',
        'is_retake',
        'original_grade_id',
        'finalized_at',
    ];

    public const FINALIZED_STATUSES = ['finalized', 'official', 'published'];

    protected static function booted(): void
    {
        static::saving(function (Grade $grade) {
            if ($grade->total_score !== null) {
                $mapped = \App\Services\GpaCalculationService::mapScoreToGrade((float) $grade->total_score);
                if (empty($grade->letter_grade) || strtoupper((string) $grade->letter_grade) === 'N/A') {
                    $grade->letter_grade = $mapped['letter_grade'];
                }
                if ($grade->grade_point === null) {
                    $grade->grade_point = \App\Services\GpaCalculationService::mapLetterToGradePoint($grade->letter_grade, (float) $grade->total_score);
                }
            }
            if (in_array(strtolower((string) $grade->status), self::FINALIZED_STATUSES, true) && ! $grade->finalized_at) {
                $grade->finalized_at = now();
            }

            // Auto-detect retake metadata if an enrollment or previous grade exists for this student + course
            if (! $grade->exists && $grade->student_id && $grade->course_id) {
                $enr = CourseEnrollment::where('student_id', $grade->student_id)
                    ->where('course_id', $grade->course_id)
                    ->when($grade->semester_id, fn ($q) => $q->where('semester_id', $grade->semester_id))
                    ->first();

                if ($enr) {
                    if (! $grade->course_offering_id && $enr->course_offering_id) {
                        $grade->course_offering_id = $enr->course_offering_id;
                    }
                    if (! $grade->study_year && $enr->year_level) {
                        $grade->study_year = (int) $enr->year_level;
                    }
                    if (! $grade->semester_number && $enr->semester_number) {
                        $grade->semester_number = (int) $enr->semester_number;
                    }
                    if ($enr->enrollment_type === 'retake' || (int) $enr->attempt_number > 1) {
                        $grade->is_retake = true;
                        $grade->attempt_number = max((int) ($grade->attempt_number ?: 1), (int) $enr->attempt_number);
                    }
                }

                $prevFailedGrade = self::where('student_id', $grade->student_id)
                    ->where('course_id', $grade->course_id)
                    ->when($grade->semester_id, fn ($q) => $q->where('semester_id', '!=', $grade->semester_id))
                    ->orderByDesc('attempt_number')
                    ->orderByDesc('id')
                    ->first();

                if ($prevFailedGrade) {
                    $grade->is_retake = true;
                    $grade->original_grade_id = $grade->original_grade_id ?: $prevFailedGrade->id;
                    $grade->attempt_number = max((int) ($grade->attempt_number ?: 1), ((int) ($prevFailedGrade->attempt_number ?: 1)) + 1);
                }
            }
        });

        static::saved(function (Grade $grade) {
            if (! $grade->isFinalized() || ! $grade->student_id) {
                return;
            }
            try {
                app(\App\Services\AcademicProgressionService::class)->evaluateAndProgressStudent(
                    (int) $grade->student_id,
                    'grade_finalized',
                    $grade->semester_id ? (int) $grade->semester_id : null
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Auto progression hook error: ' . $e->getMessage());
            }
        });
    }

    public function isFinalized(): bool
    {
        return in_array(strtolower((string) $this->status), self::FINALIZED_STATUSES, true)
            && $this->total_score !== null;
    }

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class);
    }

    protected function casts(): array
    {
        return [
            'study_year' => 'integer',
            'semester_number' => 'integer',
            'assignment_score' => 'float',
            'quiz_score' => 'float',
            'attendance_score' => 'float',
            'midterm_score' => 'float',
            'final_exam_score' => 'float',
            'total_score' => 'float',
            'grade_point' => 'float',
            'attempt_number' => 'integer',
            'is_retake' => 'boolean',
            'original_grade_id' => 'integer',
            'finalized_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }
}
