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
                // Or fallback to direct course.teacher_id legacy relationship
                ->orWhereHas('course', function (Builder $cq) use ($teacher) {
                    $cq->where('teacher_id', $teacher->id);
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
