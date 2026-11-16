<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class StudentAuthorizationService
{
    /**
     * Resolve the student profile from the authenticated user.
     */
    public function resolveStudent(User $user): ?Student
    {
        return $user->student;
    }

    /**
     * Ensure queries are scoped to the authenticated student's active enrollments.
     */
    public function scopeToStudentEnrollments(Builder $query, int $studentId): Builder
    {
        return $query->whereHas('enrollments', function (Builder $q) use ($studentId) {
            $q->where('student_id', $studentId)
              ->where('status', 'enrolled');
        });
    }

    /**
     * Scope a learning record (e.g., AttendanceDetail, AssignmentSubmission) to the student.
     */
    public function scopeLearningRecordToStudent(Builder $query, int $studentId): Builder
    {
        return $query->where('student_id', $studentId);
    }
}
