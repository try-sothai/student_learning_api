<?php

namespace App\Services;

use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Grade;
use App\Models\Student;
use App\Models\StudentCourseRetake;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetakeManagementService
{
    /**
     * Retrieve all failed courses requiring retake (or currently being retaken / completed) for a student,
     * along with available cross-cohort / junior CourseOfferings where the student can enroll.
     *
     * @return array<string, mixed>
     */
    public function getStudentRetakeOverview(Student $student): array
    {
        // Sync any finalized failed grades that may not have a retake ledger entry yet
        $this->syncFailedGradesToRetakeLedger($student);

        $retakes = StudentCourseRetake::with([
            'course.category',
            'failedSemester.academicYear',
            'failedEnrollment.courseOffering.classRoom',
            'failedGrade',
            'retakeSemester.academicYear',
            'retakeOffering.classRoom',
            'retakeOffering.semester.academicYear',
            'retakeOffering.activeTeachers.user',
            'retakeEnrollment',
            'retakeGrade',
        ])
            ->where('student_id', $student->id)
            ->orderByDesc('id')
            ->get();

        $items = $retakes->map(function (StudentCourseRetake $r) use ($student) {
            $availableOfferings = CourseOffering::with(['classRoom', 'semester.academicYear', 'program', 'activeTeachers.user'])
                ->where('course_id', $r->course_id)
                ->where('status', 'active')
                ->when($r->failed_semester_id, fn ($q) => $q->where('semester_id', '!=', $r->failed_semester_id))
                ->orderByDesc('semester_id')
                ->get()
                ->map(fn (CourseOffering $o) => [
                    'course_offering_id' => (int) $o->id,
                    'course_id' => (int) $o->course_id,
                    'section_name' => $o->section_name ?: ($o->classRoom?->name ?? 'Section A'),
                    'class_id' => $o->class_id,
                    'class_name' => $o->classRoom?->name ?? $o->section_name,
                    'class_code' => $o->classRoom?->code ?? '',
                    'study_year' => (int) $o->study_year,
                    'semester_id' => (int) $o->semester_id,
                    'semester_name' => $o->semester?->name ?? '',
                    'academic_year_id' => $o->academic_year_id ?: $o->semester?->academic_year_id,
                    'academic_year_name' => $o->semester?->academicYear?->name ?? '',
                    'program_name' => $o->program?->name ?? '',
                    'teachers' => $o->activeTeachers->map(fn ($t) => $t->user?->name ?? trim($t->first_name . ' ' . $t->last_name))->values()->all(),
                ])
                ->values()
                ->all();

            return [
                'id' => (int) $r->id,
                'student_id' => (int) $r->student_id,
                'course_id' => (int) $r->course_id,
                'course_code' => $r->course?->course_code ?? $r->course?->code ?? '',
                'course_name' => $r->course?->course_name ?? $r->course?->name ?? '',
                'credits' => (float) ($r->course?->credit ?? $r->course?->credits ?? 3.0),
                'status' => (string) $r->status,
                'attempt_number' => (int) $r->attempt_number,
                'original_attempt' => [
                    'enrollment_id' => $r->failed_enrollment_id,
                    'grade_id' => $r->failed_grade_id,
                    'study_year' => $r->failed_study_year,
                    'semester_id' => $r->failed_semester_id,
                    'semester_name' => $r->failedSemester?->name ?? '',
                    'academic_year' => $r->failedSemester?->academicYear?->name ?? '',
                    'score' => $r->original_score,
                    'letter_grade' => $r->original_letter_grade ?? 'F',
                    'result' => 'Failed',
                ],
                'retake_attempt' => $r->retake_enrollment_id ? [
                    'enrollment_id' => $r->retake_enrollment_id,
                    'course_offering_id' => $r->retake_offering_id,
                    'class_name' => $r->retakeOffering?->classRoom?->name ?? $r->retakeOffering?->section_name ?? '',
                    'study_year' => $r->retakeOffering?->study_year,
                    'semester_id' => $r->retake_semester_id,
                    'semester_name' => $r->retakeSemester?->name ?? $r->retakeOffering?->semester?->name ?? '',
                    'academic_year' => $r->retakeSemester?->academicYear?->name ?? $r->retakeOffering?->semester?->academicYear?->name ?? '',
                    'score' => $r->retake_score,
                    'letter_grade' => $r->retake_letter_grade,
                    'result' => $r->status === 'passed' ? 'Passed' : ($r->retake_score !== null ? 'Failed' : 'In Progress'),
                ] : null,
                'available_offerings_count' => count($availableOfferings),
                'available_offerings' => $availableOfferings,
            ];
        })->values()->all();

        $requiredCount = collect($items)->where('status', 'required')->count();
        $enrolledCount = collect($items)->where('status', 'enrolled')->count();
        $passedCount = collect($items)->where('status', 'passed')->count();

        return [
            'student_id' => (int) $student->id,
            'required_count' => $requiredCount,
            'enrolled_count' => $enrolledCount,
            'passed_count' => $passedCount,
            'summary' => [
                'required_count' => $requiredCount,
                'enrolled_count' => $enrolledCount,
                'passed_count' => $passedCount,
                'total_count' => count($items),
            ],
            'retakes' => $items,
        ];
    }

    /**
     * Enroll a student who failed a course into a junior / cross-cohort CourseOffering.
     * Preserves the original failed CourseEnrollment and original Grade untouched.
     */
    public function enrollInRetakeOffering(Student $student, int $courseOfferingId): array
    {
        return DB::transaction(function () use ($student, $courseOfferingId) {
            $offering = CourseOffering::with(['course', 'semester.academicYear', 'classRoom'])->findOrFail($courseOfferingId);

            if (strtolower((string) $offering->status) !== 'active') {
                throw ValidationException::withMessages([
                    'course_offering_id' => ['Selected course offering is not active.'],
                ]);
            }

            $this->syncFailedGradesToRetakeLedger($student);

            // Find the original failed grade / enrollment for this course
            $failedGrade = Grade::where('student_id', $student->id)
                ->where('course_id', $offering->course_id)
                ->whereIn('status', GpaCalculationService::FINALIZED_STATUSES)
                ->where(function ($q) {
                    $q->where('letter_grade', 'F')->orWhere('total_score', '<', GpaCalculationService::PASSING_SCORE);
                })
                ->orderByDesc('attempt_number')
                ->orderByDesc('id')
                ->first();

            $failedEnrollment = CourseEnrollment::where('student_id', $student->id)
                ->where('course_id', $offering->course_id)
                ->where(function ($q) use ($failedGrade) {
                    $q->where('status', 'failed')->orWhere('grade', 'F');
                    if ($failedGrade) {
                        $q->orWhere('semester_id', $failedGrade->semester_id);
                    }
                })
                ->orderByDesc('attempt_number')
                ->orderByDesc('id')
                ->first();

            if (! $failedGrade && ! $failedEnrollment) {
                throw ValidationException::withMessages([
                    'course_id' => ['Student does not have a failed record requiring a retake for this course.'],
                ]);
            }

            $failedSemesterId = (int) ($failedGrade?->semester_id ?: $failedEnrollment?->semester_id ?: 0);
            if ($failedSemesterId > 0 && (int) $offering->semester_id === $failedSemesterId) {
                throw ValidationException::withMessages([
                    'course_offering_id' => ['Retake offering must be in a subsequent semester, not the same semester as the original failed attempt.'],
                ]);
            }

            // Determine attempt number
            $prevMaxAttempt = max(
                (int) CourseEnrollment::where('student_id', $student->id)->where('course_id', $offering->course_id)->max('attempt_number'),
                (int) Grade::where('student_id', $student->id)->where('course_id', $offering->course_id)->max('attempt_number'),
                1
            );

            // Idempotency check: if already enrolled in this offering / semester for this course, return existing
            $existingRetakeEnrollment = CourseEnrollment::where('student_id', $student->id)
                ->where('course_id', $offering->course_id)
                ->where('semester_id', $offering->semester_id)
                ->first();

            if ($existingRetakeEnrollment) {
                if ((int) $existingRetakeEnrollment->course_offering_id !== (int) $offering->id) {
                    $existingRetakeEnrollment->update(['course_offering_id' => $offering->id]);
                }
                $retakeEnrollment = $existingRetakeEnrollment;
            } else {
                $nextAttempt = $prevMaxAttempt + 1;
                $retakeEnrollment = CourseEnrollment::create([
                    'student_id' => $student->id,
                    'course_id' => $offering->course_id,
                    'course_offering_id' => $offering->id,
                    'academic_year_id' => $offering->academic_year_id ?: $offering->semester?->academic_year_id,
                    'semester_id' => $offering->semester_id,
                    'semester_number' => $offering->semester?->semester_number,
                    'year_level' => (int) ($student->current_study_year ?: (\App\Support\YearLevel::parse($student->year_level) ?? $offering->study_year ?? 1)),
                    'enrollment_date' => now()->toDateString(),
                    'status' => 'enrolled',
                    'enrollment_type' => 'retake',
                    'attempt_number' => $nextAttempt,
                    'original_enrollment_id' => $failedEnrollment?->id,
                    'retake_status' => 'enrolled',
                ]);
            }

            if ($failedEnrollment) {
                $failedEnrollment->update(['retake_status' => 'enrolled']);
            }

            $retakeRecord = StudentCourseRetake::updateOrCreate(
                [
                    'student_id' => $student->id,
                    'course_id' => $offering->course_id,
                    'failed_semester_id' => $failedSemesterId ?: null,
                ],
                [
                    'program_id' => $student->program_id,
                    'failed_enrollment_id' => $failedEnrollment?->id,
                    'failed_grade_id' => $failedGrade?->id,
                    'failed_study_year' => $failedEnrollment?->year_level ?: $failedGrade?->study_year ?: 1,
                    'failed_semester_number' => $failedEnrollment?->semester_number ?: $failedGrade?->semester_number ?: 1,
                    'original_score' => $failedGrade?->total_score ?? $failedEnrollment?->final_score,
                    'original_letter_grade' => $failedGrade?->letter_grade ?? $failedEnrollment?->grade ?? 'F',
                    'retake_enrollment_id' => $retakeEnrollment->id,
                    'retake_offering_id' => $offering->id,
                    'retake_semester_id' => $offering->semester_id,
                    'attempt_number' => (int) $retakeEnrollment->attempt_number,
                    'status' => 'enrolled',
                ]
            );

            return [
                'retake' => $retakeRecord->fresh(['course', 'failedSemester', 'retakeOffering.classRoom', 'retakeSemester']),
                'enrollment' => $retakeEnrollment->fresh(['course', 'courseOffering.classRoom', 'semester.academicYear']),
            ];
        });
    }

    private function syncFailedGradesToRetakeLedger(Student $student): void
    {
        $failedGrades = Grade::where('student_id', $student->id)
            ->whereIn('status', GpaCalculationService::FINALIZED_STATUSES)
            ->where(function ($q) {
                $q->where('letter_grade', 'F')->orWhere('total_score', '<', GpaCalculationService::PASSING_SCORE);
            })
            ->get();

        foreach ($failedGrades as $fg) {
            $enr = CourseEnrollment::where('student_id', $student->id)
                ->where('course_id', $fg->course_id)
                ->where('semester_id', $fg->semester_id)
                ->first();

            StudentCourseRetake::firstOrCreate(
                [
                    'student_id' => $student->id,
                    'course_id' => $fg->course_id,
                    'failed_semester_id' => $fg->semester_id,
                ],
                [
                    'program_id' => $student->program_id,
                    'failed_enrollment_id' => $enr?->id,
                    'failed_grade_id' => $fg->id,
                    'failed_study_year' => $fg->study_year ?: ($enr?->year_level ?: 1),
                    'failed_semester_number' => $fg->semester_number ?: ($enr?->semester_number ?: 1),
                    'original_score' => $fg->total_score,
                    'original_letter_grade' => $fg->letter_grade ?: 'F',
                    'attempt_number' => ((int) ($fg->attempt_number ?: 1)) + 1,
                    'status' => 'required',
                ]
            );
        }
    }
}
