<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\CourseOfferingTeacher;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TeacherAuthorizationService
{
    /**
     * Resolve the Teacher model for an authenticated user.
     */
    public function resolveTeacher(User $user): ?Teacher
    {
        return $user->teacher ?? Teacher::where('user_id', $user->id)->first();
    }

    /**
     * Get a query builder of course offerings authorized for the teacher.
     */
    public function getAuthorizedOfferingsQuery(Teacher $teacher): Builder
    {
        return CourseOffering::query()
            ->where(function (Builder $query) use ($teacher) {
                // Via active course_offering_teachers assignment
                $query->whereHas('teachers', function (Builder $tq) use ($teacher) {
                    $tq->where('teachers.id', $teacher->id)
                        ->where('course_offering_teachers.status', 'active');
                })
                // Legacy fallback: course.teacher_id only counts for offerings that have NO
                // active teacher assigned, so a co-section taught by someone else is never exposed.
                ->orWhere(function (Builder $legacy) use ($teacher) {
                    $legacy->whereHas('course', fn (Builder $cq) => $cq->where('teacher_id', $teacher->id))
                        ->whereDoesntHave('teachers', fn (Builder $tq) => $tq->where('course_offering_teachers.status', 'active'));
                });
            });
    }

    /**
     * Return array of all authorized course offering IDs for a teacher.
     *
     * @return array<int>
     */
    public function getAuthorizedOfferingIds(Teacher $teacher): array
    {
        return $this->getAuthorizedOfferingsQuery($teacher)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Return array of all authorized course IDs for a teacher.
     *
     * @return array<int>
     */
    public function getAuthorizedCourseIds(Teacher $teacher): array
    {
        $fromOfferings = $this->getAuthorizedOfferingsQuery($teacher)
            ->pluck('course_id')
            ->filter()
            ->map(fn ($id) => (int) $id);

        $direct = Course::where('teacher_id', $teacher->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        return $fromOfferings->merge($direct)->unique()->values()->all();
    }

    /**
     * Return array of all authorized student IDs for a teacher.
     *
     * @return array<int>
     */
    public function getAuthorizedStudentIds(Teacher $teacher): array
    {
        $offeringIds = $this->getAuthorizedOfferingIds($teacher);
        $courseIds = $this->getAuthorizedCourseIds($teacher);

        if (empty($offeringIds) && empty($courseIds)) {
            return [];
        }

        return \App\Models\CourseEnrollment::query()
            ->where(function (Builder $q) use ($offeringIds, $courseIds) {
                if (! empty($offeringIds)) {
                    $q->orWhereIn('course_offering_id', $offeringIds);
                }
                if (! empty($courseIds)) {
                    $q->orWhereIn('course_id', $courseIds);
                }
            })
            ->pluck('student_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Check if a student is enrolled in any of the teacher's authorized offerings or courses.
     */
    public function isStudentAuthorized(Teacher $teacher, int $studentId): bool
    {
        $offeringIds = $this->getAuthorizedOfferingIds($teacher);
        $courseIds = $this->getAuthorizedCourseIds($teacher);

        return \App\Models\CourseEnrollment::where('student_id', $studentId)
            ->where(function (Builder $q) use ($offeringIds, $courseIds) {
                $q->whereRaw('1 = 0');
                if (! empty($offeringIds)) {
                    $q->orWhereIn('course_offering_id', $offeringIds);
                }
                if (! empty($courseIds)) {
                    $q->orWhereIn('course_id', $courseIds);
                }
            })
            ->exists();
    }

    /**
     * Check if a specific Course Offering is authorized for the teacher.
     */
    public function isOfferingAuthorized(Teacher $teacher, int $offeringId): bool
    {
        return $this->getAuthorizedOfferingsQuery($teacher)
            ->where('id', $offeringId)
            ->exists();
    }

    /**
     * Check if a course is assigned to the teacher.
     */
    public function isCourseAuthorized(Teacher $teacher, int $courseId): bool
    {
        return CourseOfferingTeacher::where('teacher_id', $teacher->id)
            ->where('status', 'active')
            ->whereHas('courseOffering', fn ($q) => $q->where('course_id', $courseId))
            ->exists()
            || Course::where('id', $courseId)->where('teacher_id', $teacher->id)->exists();
    }

    /**
     * Check if a class section is assigned to the teacher.
     */
    public function isClassAuthorized(Teacher $teacher, int $classId): bool
    {
        return CourseOfferingTeacher::where('teacher_id', $teacher->id)
            ->where('status', 'active')
            ->whereHas('courseOffering', fn ($q) => $q->where('class_id', $classId))
            ->exists()
            || DB::table('class_teacher')->where('teacher_id', $teacher->id)->where('class_id', $classId)->exists();
    }

    /**
     * Safely replace Teacher A with Teacher B for a Course Offering.
     * Preserves offering identity, student enrollments, and all learning records.
     */
    public function transferTeacherAssignment(
        int $offeringId,
        int $currentTeacherId,
        int $newTeacherId,
        string $role = 'primary'
    ): CourseOfferingTeacher {
        return DB::transaction(function () use ($offeringId, $currentTeacherId, $newTeacherId, $role) {
            $offering = CourseOffering::findOrFail($offeringId);

            // 1. Mark existing assignment as ended
            CourseOfferingTeacher::where('course_offering_id', $offering->id)
                ->where('teacher_id', $currentTeacherId)
                ->where('status', 'active')
                ->update([
                    'status' => 'transferred',
                    'ended_at' => now(),
                    'updated_at' => now(),
                ]);

            // 2. Create or activate new teacher assignment
            $newAssignment = CourseOfferingTeacher::updateOrCreate(
                [
                    'course_offering_id' => $offering->id,
                    'teacher_id' => $newTeacherId,
                ],
                [
                    'role' => $role,
                    'status' => 'active',
                    'assigned_at' => now(),
                    'ended_at' => null,
                    'updated_at' => now(),
                ]
            );

            // 3. Keep legacy course teacher_id synchronized if single course
            if ($offering->course_id) {
                Course::where('id', $offering->course_id)->update(['teacher_id' => $newTeacherId]);
            }

            return $newAssignment;
        });
    }
}
