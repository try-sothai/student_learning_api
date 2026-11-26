<?php

namespace App\Services;

use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\YearLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Single source of truth for "who may see / manage which assessment".
 *
 * Academic chain used by the existing schema:
 *   Department -> Academic Year -> Semester -> Class -> Course
 *   course_offerings (course + class + semester + academic year)  == teaching assignment unit
 *   course_offering_teachers (teacher <-> offering)               == who teaches it
 *   course_enrollments.course_offering_id (student <-> offering)  == who attends it
 *   assignments / quizzes / exams .course_offering_id             == what the offering assesses
 *
 * Legacy rows that still have a NULL course_offering_id fall back to
 * (course_id, semester_id) so no existing record is lost or exposed too widely.
 */
class AssessmentAccessService
{
    public function __construct(protected TeacherAuthorizationService $teacherAuth) {}

    /* ------------------------------------------------------------------ */
    /* Student side                                                       */
    /* ------------------------------------------------------------------ */

    /** @return array<int> offering IDs the student is actively enrolled in. */
    public function studentOfferingIds(Student $student): array
    {
        return CourseEnrollment::where('student_id', $student->id)
            ->where('status', 'enrolled')
            ->whereNotNull('course_offering_id')
            ->pluck('course_offering_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** Restrict an assessment query (assignments / quizzes / exams) to what the student may access. */
    public function scopeForStudent(Builder $query, Student $student): Builder
    {
        $offeringIds = $this->studentOfferingIds($student);

        return $query->where(function (Builder $q) use ($offeringIds, $student) {
            $q->whereIn($q->getModel()->getTable().'.course_offering_id', $offeringIds ?: [0])
                ->orWhere(function (Builder $legacy) use ($student) {
                    $table = $legacy->getModel()->getTable();
                    $legacy->whereNull("$table.course_offering_id")
                        ->whereExists(function ($sub) use ($student, $table) {
                            $sub->selectRaw('1')->from('course_enrollments')
                                ->whereColumn('course_enrollments.course_id', "$table.course_id")
                                ->whereColumn('course_enrollments.semester_id', "$table.semester_id")
                                ->where('course_enrollments.student_id', $student->id)
                                ->where('course_enrollments.status', 'enrolled');
                        });
                });
        });
    }

    public function studentCanAccess(Student $student, Model $assessment): bool
    {
        return $this->scopeForStudent($assessment->newQuery(), $student)
            ->whereKey($assessment->getKey())
            ->exists();
    }

    /** Whether an assessment is released to students at all (never drafts). */
    public function isVisibleStatus(string $type, ?string $status): bool
    {
        $status = strtolower((string) $status);

        return match ($type) {
            'quiz' => in_array($status, ['published', 'open', 'closed'], true),
            'exam' => ! in_array($status, ['draft', 'cancelled'], true),
            default => in_array($status, ['published', 'closed'], true),
        };
    }

    /* ------------------------------------------------------------------ */
    /* Teacher side                                                       */
    /* ------------------------------------------------------------------ */

    public function scopeForTeacher(Builder $query, Teacher $teacher): Builder
    {
        $ids = $this->teacherAuth->getAuthorizedOfferingIds($teacher);
        $table = $query->getModel()->getTable();

        return $query->whereIn("$table.course_offering_id", $ids ?: [0]);
    }

    public function teacherCanManage(Teacher $teacher, Model $assessment): bool
    {
        $offeringId = $assessment->course_offering_id;
        if ($offeringId) {
            return $this->teacherAuth->isOfferingAuthorized($teacher, (int) $offeringId);
        }

        // Legacy row without an offering: the teacher must own it AND be assigned to that course.
        return (int) $assessment->teacher_id === (int) $teacher->id
            && $this->teacherAuth->isCourseAuthorized($teacher, (int) $assessment->course_id);
    }

    public function resolveUserScope(User $user): array
    {
        return [
            'student' => $user->role === 'student' ? $user->student : null,
            'teacher' => $user->role === 'teacher' ? $this->teacherAuth->resolveTeacher($user) : null,
            'admin' => $user->role === 'admin',
        ];
    }

    /** Teaching assignments of a teacher as readable dropdown rows. */
    public function teachingAssignments(Teacher $teacher): array
    {
        return $this->teacherAuth->getAuthorizedOfferingsQuery($teacher)
            ->with(['course', 'classRoom', 'semester.academicYear', 'academicYear'])
            ->withCount(['enrollments as student_count' => fn ($q) => $q->where('status', 'enrolled')])
            ->get()
            ->map(function (CourseOffering $o) {
                $ay = $o->academicYear ?? $o->semester?->academicYear;
                $yl = YearLevel::parse($o->study_year ?? $o->classRoom?->year_level ?? $o->course?->year_level);

                return [
                    'course_offering_id' => $o->id,
                    'academic_year_id' => $ay?->id ?? $o->academic_year_id,
                    'academic_year_name' => $ay?->name,
                    'semester_id' => $o->semester_id,
                    'semester_name' => $o->semester?->name,
                    'class_id' => $o->class_id,
                    'class_name' => $o->classRoom?->name ?? $o->section_name,
                    'class_code' => $o->classRoom?->code,
                    'year_level' => $yl,
                    'year_level_code' => YearLevel::code($yl),
                    'year_level_label' => YearLevel::label($yl),
                    'course_id' => $o->course_id,
                    'course_name' => $o->course?->name,
                    'course_code' => $o->course?->code,
                    'student_count' => $o->student_count,
                    'status' => $o->status,
                ];
            })
            ->values()
            ->all();
    }
}
