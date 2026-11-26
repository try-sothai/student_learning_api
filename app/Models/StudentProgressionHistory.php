<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentProgressionHistory extends Model
{
    protected $table = 'student_progression_histories';

    protected $fillable = [
        'student_id',
        'program_id',
        'from_academic_year_id',
        'from_semester_id',
        'from_study_year',
        'from_semester_number',
        'to_academic_year_id',
        'to_semester_id',
        'to_study_year',
        'to_semester_number',
        'passed_courses_count',
        'failed_courses_count',
        'enrolled_next_courses_count',
        'status',
        'trigger_source',
        'notes',
        'progressed_at',
    ];

    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'program_id' => 'integer',
            'from_academic_year_id' => 'integer',
            'from_semester_id' => 'integer',
            'from_study_year' => 'integer',
            'from_semester_number' => 'integer',
            'to_academic_year_id' => 'integer',
            'to_semester_id' => 'integer',
            'to_study_year' => 'integer',
            'to_semester_number' => 'integer',
            'passed_courses_count' => 'integer',
            'failed_courses_count' => 'integer',
            'enrolled_next_courses_count' => 'integer',
            'progressed_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function fromSemester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'from_semester_id');
    }

    public function toSemester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'to_semester_id');
    }
}
