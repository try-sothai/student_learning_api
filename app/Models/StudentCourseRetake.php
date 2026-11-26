<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentCourseRetake extends Model
{
    protected $table = 'student_course_retakes';

    protected $fillable = [
        'student_id',
        'course_id',
        'program_id',
        'failed_enrollment_id',
        'failed_grade_id',
        'failed_semester_id',
        'failed_study_year',
        'failed_semester_number',
        'original_score',
        'original_letter_grade',
        'retake_enrollment_id',
        'retake_offering_id',
        'retake_semester_id',
        'retake_grade_id',
        'retake_score',
        'retake_letter_grade',
        'attempt_number',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'course_id' => 'integer',
            'program_id' => 'integer',
            'failed_enrollment_id' => 'integer',
            'failed_grade_id' => 'integer',
            'failed_semester_id' => 'integer',
            'failed_study_year' => 'integer',
            'failed_semester_number' => 'integer',
            'original_score' => 'float',
            'retake_enrollment_id' => 'integer',
            'retake_offering_id' => 'integer',
            'retake_semester_id' => 'integer',
            'retake_grade_id' => 'integer',
            'retake_score' => 'float',
            'attempt_number' => 'integer',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function failedEnrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class, 'failed_enrollment_id');
    }

    public function failedGrade(): BelongsTo
    {
        return $this->belongsTo(Grade::class, 'failed_grade_id');
    }

    public function failedSemester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'failed_semester_id');
    }

    public function retakeEnrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class, 'retake_enrollment_id');
    }

    public function retakeOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'retake_offering_id');
    }

    public function retakeSemester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'retake_semester_id');
    }

    public function retakeGrade(): BelongsTo
    {
        return $this->belongsTo(Grade::class, 'retake_grade_id');
    }
}
