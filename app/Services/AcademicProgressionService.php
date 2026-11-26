<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CurriculumItem;
use App\Models\Grade;
use App\Models\MajorAcademicPeriod;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentCourseRetake;
use App\Models\StudentProgressionHistory;
use App\Support\YearLevel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AcademicProgressionService
{
    public function __construct(
        protected GpaCalculationService $gpaService
    ) {}

    /**
     * Evaluate and automatically progress an individual student if all required courses
     * in their current semester have officially finalized Final Grades.
     *
     * Rules enforced:
     *  - Evaluated per individual student (never waits for classmates or semester end date).
     *  - Draft or provisional grades do NOT count as finalized.
     *  - A valid score of 0.0 counts as finalized (F / Failed).
     *  - Failed courses NEVER block progression; they are recorded in student_course_retakes.
     *  - Next academic period is resolved from the Major's configured curriculum / academic periods
     *    (never hardcoding 2 semesters = 1 academic year).
     *  - Idempotent and transactionally locked (`lockForUpdate`) to prevent duplicate enrollments.
     *
     * @return array<string, mixed>
     */
    public function evaluateAndProgressStudent(int $studentId, string $triggerSource = 'grade_finalized', ?int $targetFromSemesterId = null): array
    {
        return DB::transaction(function () use ($studentId, $triggerSource, $targetFromSemesterId) {
            /** @var Student|null $student */
            $student = Student::whereKey($studentId)->lockForUpdate()->first();
            if (! $student) {
                return ['eligible' => false, 'progressed' => false, 'reason' => 'Student not found.'];
            }

            if (in_array(strtolower((string) $student->status), ['graduated', 'suspended', 'inactive'], true)) {
                return ['eligible' => false, 'progressed' => false, 'reason' => "Student status is {$student->status}."];
            }

            $currentPeriod = $this->gpaService->resolveCurrentPeriod($student);
            $fromSemesterId = $targetFromSemesterId ?: $currentPeriod['semester_id'];
            $fromStudyYear = $currentPeriod['study_year'];
            $fromSemesterNumber = $currentPeriod['semester_number'];

            if (! $fromSemesterId) {
                return ['eligible' => false, 'progressed' => false, 'reason' => 'No active semester found for student.'];
            }

            $fromSemester = Semester::find($fromSemesterId);
            if ($fromSemester && $fromSemester->semester_number) {
                $fromSemesterNumber = (int) $fromSemester->semester_number;
            }
            $fromAyId = $fromSemester?->academic_year_id ?: $currentPeriod['academic_year_id'];

            // Fetch all active/required regular enrollments for this student in fromSemesterId
            $enrollments = CourseEnrollment::with(['course', 'courseOffering'])
                ->where('student_id', $student->id)
                ->where(function ($q) use ($fromSemesterId) {
                    $q->where('semester_id', $fromSemesterId)
                        ->orWhereHas('courseOffering', fn ($oq) => $oq->where('semester_id', $fromSemesterId));
                })
                ->whereNotIn('status', ['dropped', 'cancelled'])
                ->get();

            if ($enrollments->isEmpty()) {
                return [
                    'eligible' => false,
                    'progressed' => false,
                    'reason' => 'Student has no enrolled courses in the current semester.',
                    'from_semester_id' => $fromSemesterId,
                ];
            }

            $grades = Grade::where('student_id', $student->id)
                ->where(function ($q) use ($fromSemesterId) {
                    $q->where('semester_id', $fromSemesterId)
                        ->orWhereHas('courseOffering', fn ($oq) => $oq->where('semester_id', $fromSemesterId));
                })
                ->get();

            $gradesByOffering = $grades->whereNotNull('course_offering_id')->keyBy('course_offering_id');
            $gradesByCourse = $grades->keyBy('course_id');

            $pendingCourses = [];
            $passedCount = 0;
            $failedCount = 0;
            $finalizedGrades = [];

            foreach ($enrollments as $enr) {
                $grade = ($enr->course_offering_id && $gradesByOffering->has($enr->course_offering_id))
                    ? $gradesByOffering->get($enr->course_offering_id)
                    : $gradesByCourse->get($enr->course_id);

                if (! GpaCalculationService::isGradeFinalized($grade)) {
                    $pendingCourses[] = [
                        'course_id' => (int) $enr->course_id,
                        'course_code' => $enr->course?->course_code ?? $enr->course?->code ?? '',
                        'course_name' => $enr->course?->course_name ?? $enr->course?->name ?? '',
                        'grade_status' => $grade?->status ?? 'missing',
                    ];
                    continue;
                }

                $finalizedGrades[] = [$enr, $grade];
            }

            // Record any finalized grades onto their enrollment rows and register retakes for failed courses immediately
            foreach ($finalizedGrades as [$enr, $grade]) {
                $score = (float) $grade->total_score;
                $mapped = GpaCalculationService::mapScoreToGrade($score);
                $letter = ! empty($grade->letter_grade) && strtoupper($grade->letter_grade) !== 'N/A'
                    ? strtoupper($grade->letter_grade)
                    : $mapped['letter_grade'];
                $gp = $grade->grade_point !== null ? (float) $grade->grade_point : $mapped['grade_point'];
                $passed = GpaCalculationService::isGradePassed($grade);

                if ($grade->letter_grade !== $letter || $grade->grade_point === null || ! $grade->finalized_at) {
                    Grade::withoutEvents(function () use ($grade, $letter, $gp, $fromStudyYear, $fromSemesterNumber) {
                        $grade->update([
                            'letter_grade' => $letter,
                            'grade_point' => $gp,
                            'study_year' => $grade->study_year ?: $fromStudyYear,
                            'semester_number' => $grade->semester_number ?: $fromSemesterNumber,
                            'finalized_at' => $grade->finalized_at ?: now(),
                        ]);
                    });
                }

                $enr->update([
                    'final_score' => $score,
                    'grade' => $letter,
                    'status' => $passed ? 'completed' : 'failed',
                    'retake_status' => $passed ? ($enr->enrollment_type === 'retake' ? 'completed' : 'not_required') : 'required',
                    'finalized_at' => $enr->finalized_at ?: now(),
                ]);

                if ($passed) {
                    $passedCount++;
                    // If this was a retake enrollment, mark the StudentCourseRetake ledger row as passed
                    if ($enr->enrollment_type === 'retake' || $grade->is_retake) {
                        StudentCourseRetake::where('student_id', $student->id)
                            ->where('course_id', $enr->course_id)
                            ->whereIn('status', ['required', 'enrolled', 'failed'])
                            ->update([
                                'retake_enrollment_id' => $enr->id,
                                'retake_offering_id' => $enr->course_offering_id,
                                'retake_semester_id' => $fromSemesterId,
                                'retake_grade_id' => $grade->id,
                                'retake_score' => $score,
                                'retake_letter_grade' => $letter,
                                'status' => 'passed',
                            ]);
                    }
                } else {
                    $failedCount++;
                    $this->recordFailedCourseForRetake($student, $enr, $grade, $fromSemesterId, $fromStudyYear, $fromSemesterNumber, $score, $letter);
                }
            }

            // If any required course in the current semester is still pending, student remains pending
            if (! empty($pendingCourses)) {
                return [
                    'eligible' => false,
                    'progressed' => false,
                    'reason' => count($pendingCourses) . ' enrolled course(s) still have pending or unfinalized Final Grades.',
                    'from_semester_id' => $fromSemesterId,
                    'from_study_year' => $fromStudyYear,
                    'from_semester_number' => $fromSemesterNumber,
                    'pending_courses' => $pendingCourses,
                ];
            }

            // All required courses in fromSemesterId are officially finalized!
            // 1. Recalculate & persist official Semester GPA
            $this->gpaService->recalculateAndSyncSemesterGpa($student, (int) $fromSemesterId);

            // 2. Check idempotency: has this student already progressed from this exact semester?
            $alreadyProgressed = StudentProgressionHistory::where('student_id', $student->id)
                ->where('from_semester_id', $fromSemesterId)
                ->where('from_study_year', $fromStudyYear)
                ->where('from_semester_number', $fromSemesterNumber)
                ->whereIn('status', ['progressed', 'completed_program'])
                ->first();

            if ($alreadyProgressed) {
                return [
                    'eligible' => true,
                    'progressed' => false,
                    'already_progressed' => true,
                    'reason' => 'Student has already progressed from this semester.',
                    'progression_history_id' => $alreadyProgressed->id,
                    'to_study_year' => $alreadyProgressed->to_study_year,
                    'to_semester_number' => $alreadyProgressed->to_semester_number,
                    'to_semester_id' => $alreadyProgressed->to_semester_id,
                ];
            }

            // 3. Determine the next academic period from Major curriculum & academic calendar sequence
            $nextPeriod = $this->resolveNextAcademicPeriod($student, $fromStudyYear, $fromSemesterNumber, $fromSemesterId, $fromAyId);

            if ($nextPeriod['status'] === 'completed_program') {
                $student->update([
                    'progression_status' => 'completed_program',
                    'last_progressed_at' => now(),
                ]);

                $hist = StudentProgressionHistory::create([
                    'student_id' => $student->id,
                    'program_id' => $student->program_id,
                    'from_academic_year_id' => $fromAyId,
                    'from_semester_id' => $fromSemesterId,
                    'from_study_year' => $fromStudyYear,
                    'from_semester_number' => $fromSemesterNumber,
                    'passed_courses_count' => $passedCount,
                    'failed_courses_count' => $failedCount,
                    'enrolled_next_courses_count' => 0,
                    'status' => 'completed_program',
                    'trigger_source' => $triggerSource,
                    'notes' => $nextPeriod['notes'],
                    'progressed_at' => now(),
                ]);

                return [
                    'eligible' => true,
                    'progressed' => true,
                    'status' => 'completed_program',
                    'progression_history_id' => $hist->id,
                    'passed_courses_count' => $passedCount,
                    'failed_courses_count' => $failedCount,
                ];
            }

            if ($nextPeriod['status'] === 'pending_configuration') {
                $student->update([
                    'progression_status' => 'eligible_pending_next_period_config',
                ]);

                $hist = StudentProgressionHistory::updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'from_semester_id' => $fromSemesterId,
                        'from_study_year' => $fromStudyYear,
                        'from_semester_number' => $fromSemesterNumber,
                        'status' => 'pending_configuration',
                    ],
                    [
                        'program_id' => $student->program_id,
                        'from_academic_year_id' => $fromAyId,
                        'to_study_year' => $nextPeriod['to_study_year'],
                        'to_semester_number' => $nextPeriod['to_semester_number'],
                        'to_semester_id' => $nextPeriod['to_semester_id'],
                        'to_academic_year_id' => $nextPeriod['to_academic_year_id'],
                        'passed_courses_count' => $passedCount,
                        'failed_courses_count' => $failedCount,
                        'enrolled_next_courses_count' => 0,
                        'trigger_source' => $triggerSource,
                        'notes' => $nextPeriod['notes'],
                        'progressed_at' => now(),
                    ]
                );

                return [
                    'eligible' => true,
                    'progressed' => false,
                    'status' => 'pending_configuration',
                    'reason' => $nextPeriod['notes'],
                    'progression_history_id' => $hist->id,
                    'passed_courses_count' => $passedCount,
                    'failed_courses_count' => $failedCount,
                ];
            }

            $toStudyYear = (int) $nextPeriod['to_study_year'];
            $toSemesterNumber = (int) $nextPeriod['to_semester_number'];
            $toSemesterId = $nextPeriod['to_semester_id'] ? (int) $nextPeriod['to_semester_id'] : null;
            $toAyId = $nextPeriod['to_academic_year_id'] ? (int) $nextPeriod['to_academic_year_id'] : $fromAyId;

            // 4. Enroll student into the next period's Course Offerings according to curriculum & offering availability
            $enrolledNextCount = $this->enrollStudentInNextPeriodOfferings(
                $student,
                $toStudyYear,
                $toSemesterNumber,
                $toSemesterId,
                $toAyId
            );

            // 5. Update student's current academic profile
            $studentUpdates = [
                'year_level' => YearLevel::storageValue($toStudyYear) ?? (string) $toStudyYear,
                'current_study_year' => $toStudyYear,
                'current_semester_number' => $toSemesterNumber,
                'progression_status' => 'progressed',
                'last_progressed_at' => now(),
            ];
            if ($toSemesterId) {
                $studentUpdates['current_semester_id'] = $toSemesterId;
            }
            if ($toAyId) {
                $studentUpdates['current_academic_year_id'] = $toAyId;
            }
            $student->update($studentUpdates);

            // Remove any earlier pending_configuration audit for this from_semester
            StudentProgressionHistory::where('student_id', $student->id)
                ->where('from_semester_id', $fromSemesterId)
                ->where('from_study_year', $fromStudyYear)
                ->where('from_semester_number', $fromSemesterNumber)
                ->where('status', 'pending_configuration')
                ->delete();

            $hist = StudentProgressionHistory::create([
                'student_id' => $student->id,
                'program_id' => $student->program_id,
                'from_academic_year_id' => $fromAyId,
                'from_semester_id' => $fromSemesterId,
                'from_study_year' => $fromStudyYear,
                'from_semester_number' => $fromSemesterNumber,
                'to_academic_year_id' => $toAyId,
                'to_semester_id' => $toSemesterId,
                'to_study_year' => $toStudyYear,
                'to_semester_number' => $toSemesterNumber,
                'passed_courses_count' => $passedCount,
                'failed_courses_count' => $failedCount,
                'enrolled_next_courses_count' => $enrolledNextCount,
                'status' => 'progressed',
                'trigger_source' => $triggerSource,
                'notes' => $nextPeriod['notes'],
                'progressed_at' => now(),
            ]);

            return [
                'eligible' => true,
                'progressed' => true,
                'status' => 'progressed',
                'progression_history_id' => $hist->id,
                'from_study_year' => $fromStudyYear,
                'from_semester_number' => $fromSemesterNumber,
                'from_semester_id' => $fromSemesterId,
                'to_study_year' => $toStudyYear,
                'to_semester_number' => $toSemesterNumber,
                'to_semester_id' => $toSemesterId,
                'to_academic_year_id' => $toAyId,
                'passed_courses_count' => $passedCount,
                'failed_courses_count' => $failedCount,
                'enrolled_next_courses_count' => $enrolledNextCount,
            ];
        });
    }

    /**
     * Reconcile all active students (fallback scheduled command / admin trigger).
     *
     * @return array{evaluated: int, progressed: int, details: array<int, array<string, mixed>>}
     */
    public function reconcileAllEligibleStudents(string $triggerSource = 'scheduled_reconciliation'): array
    {
        $studentIds = Student::where('status', 'enrolled')->pluck('id');
        $progressed = 0;
        $details = [];

        foreach ($studentIds as $sid) {
            try {
                $res = $this->evaluateAndProgressStudent((int) $sid, $triggerSource);
                if (! empty($res['progressed'])) {
                    $progressed++;
                }
                $details[(int) $sid] = $res;
            } catch (\Throwable $e) {
                Log::error("Progression reconciliation error for student {$sid}: " . $e->getMessage());
                $details[(int) $sid] = ['eligible' => false, 'progressed' => false, 'error' => $e->getMessage()];
            }
        }

        return [
            'evaluated' => $studentIds->count(),
            'progressed' => $progressed,
            'details' => $details,
        ];
    }

    private function recordFailedCourseForRetake(
        Student $student,
        CourseEnrollment $enr,
        Grade $grade,
        int $semesterId,
        int $studyYear,
        int $semesterNumber,
        float $score,
        string $letter
    ): StudentCourseRetake {
        $attemptNumber = (int) ($grade->attempt_number ?: ($enr->attempt_number ?: 1));

        // If this failed attempt was itself a retake, update the existing retake row or create the next retake requirement
        $existing = StudentCourseRetake::where('student_id', $student->id)
            ->where('course_id', $enr->course_id)
            ->where('failed_enrollment_id', $enr->id)
            ->first();

        if ($existing) {
            $existing->update([
                'failed_grade_id' => $grade->id,
                'original_score' => $score,
                'original_letter_grade' => $letter,
            ]);

            return $existing;
        }

        return StudentCourseRetake::updateOrCreate(
            [
                'student_id' => $student->id,
                'course_id' => $enr->course_id,
                'failed_semester_id' => $semesterId,
            ],
            [
                'program_id' => $student->program_id,
                'failed_enrollment_id' => $enr->id,
                'failed_grade_id' => $grade->id,
                'failed_study_year' => $studyYear,
                'failed_semester_number' => $semesterNumber,
                'original_score' => $score,
                'original_letter_grade' => $letter,
                'attempt_number' => $attemptNumber + 1,
                'status' => 'required',
            ]
        );
    }

    /**
     * Resolve the next academic period from the student's Major curriculum sequence
     * and Major academic calendar periods (never hardcoding 2 semesters = 1 year).
     *
     * @return array{status: string, to_study_year: int|null, to_semester_number: int|null, to_semester_id: int|null, to_academic_year_id: int|null, notes: string}
     */
    public function resolveNextAcademicPeriod(
        Student $student,
        int $fromStudyYear,
        int $fromSemesterNumber,
        int $fromSemesterId,
        ?int $fromAyId
    ): array {
        $programId = $student->program_id ? (int) $student->program_id : null;
        $maxYears = (int) ($student->program?->duration_years ?: config('slas.max_year_level', 4));

        // 1. Check Major-specific academic calendar periods sequence first
        if ($programId && \Illuminate\Support\Facades\Schema::hasTable('major_academic_periods')) {
            $periods = MajorAcademicPeriod::where('program_id', $programId)
                ->where('status', '!=', 'inactive')
                ->orderBy('study_year')
                ->orderBy('sequence_order')
                ->orderBy('semester_number')
                ->orderBy('start_date')
                ->get();

            if ($periods->isNotEmpty()) {
                $nextPeriod = $periods->first(function (MajorAcademicPeriod $p) use ($fromStudyYear, $fromSemesterNumber, $fromSemesterId) {
                    if ($p->semester_id && (int) $p->semester_id === $fromSemesterId) {
                        return false;
                    }
                    if ((int) $p->study_year > $fromStudyYear) {
                        return true;
                    }

                    return (int) $p->study_year === $fromStudyYear && (int) $p->semester_number > $fromSemesterNumber;
                });

                if ($nextPeriod) {
                    $resolvedSemId = $nextPeriod->semester_id ?: $this->findMatchingSemesterId($nextPeriod->academic_year_id, (int) $nextPeriod->semester_number, $fromSemesterId);

                    return [
                        'status' => 'ready',
                        'to_study_year' => (int) $nextPeriod->study_year,
                        'to_semester_number' => (int) $nextPeriod->semester_number,
                        'to_semester_id' => $resolvedSemId,
                        'to_academic_year_id' => (int) $nextPeriod->academic_year_id,
                        'notes' => "Progressed via Major academic calendar to Year {$nextPeriod->study_year} {$nextPeriod->semester_name}.",
                    ];
                }
            }
        }

        // 2. Check Major curriculum sequence (distinct (study_year, semester_number) pairs)
        if ($programId && \Illuminate\Support\Facades\Schema::hasTable('curriculum_items')) {
            $curriculumSteps = CurriculumItem::where('program_id', $programId)
                ->where('status', 'active')
                ->select('study_year', 'semester_number')
                ->distinct()
                ->orderBy('study_year')
                ->orderBy('semester_number')
                ->get();

            if ($curriculumSteps->isNotEmpty()) {
                $nextStep = $curriculumSteps->first(function ($step) use ($fromStudyYear, $fromSemesterNumber) {
                    if ((int) $step->study_year > $fromStudyYear) {
                        return true;
                    }

                    return (int) $step->study_year === $fromStudyYear && (int) $step->semester_number > $fromSemesterNumber;
                });

                if (! $nextStep) {
                    $maxCurrYear = (int) $curriculumSteps->max('study_year');
                    if ($fromStudyYear >= $maxCurrYear) {
                        return [
                            'status' => 'completed_program',
                            'to_study_year' => null,
                            'to_semester_number' => null,
                            'to_semester_id' => null,
                            'to_academic_year_id' => null,
                            'notes' => 'Student completed all configured curriculum periods for their Major.',
                        ];
                    }
                } else {
                    $toYear = (int) $nextStep->study_year;
                    $toSemNum = (int) $nextStep->semester_number;
                    [$toSemId, $toAyId] = $this->resolveTargetSemesterAndAcademicYear($student, $toYear, $toSemNum, $fromStudyYear, $fromSemesterId, $fromAyId);

                    if (! $toSemId) {
                        return [
                            'status' => 'pending_configuration',
                            'to_study_year' => $toYear,
                            'to_semester_number' => $toSemNum,
                            'to_semester_id' => null,
                            'to_academic_year_id' => $toAyId,
                            'notes' => "Student is eligible for Year {$toYear} Semester {$toSemNum}, but the target semester record has not been configured yet.",
                        ];
                    }

                    return [
                        'status' => 'ready',
                        'to_study_year' => $toYear,
                        'to_semester_number' => $toSemNum,
                        'to_semester_id' => $toSemId,
                        'to_academic_year_id' => $toAyId,
                        'notes' => "Progressed via Major curriculum sequence to Year {$toYear} Semester {$toSemNum}.",
                    ];
                }
            }
        }

        // 3. Check available CourseOfferings or Semesters in the database
        [$toYear, $toSemNum, $toSemId, $toAyId] = $this->resolveFromAvailableOfferingsOrSemesters(
            $student,
            $fromStudyYear,
            $fromSemesterNumber,
            $fromSemesterId,
            $fromAyId,
            $maxYears
        );

        if ($toYear !== null && $toYear > $maxYears) {
            return [
                'status' => 'completed_program',
                'to_study_year' => null,
                'to_semester_number' => null,
                'to_semester_id' => null,
                'to_academic_year_id' => null,
                'notes' => "Student completed Year {$fromStudyYear} Semester {$fromSemesterNumber}.",
            ];
        }

        if (! $toSemId) {
            return [
                'status' => 'pending_configuration',
                'to_study_year' => $toYear,
                'to_semester_number' => $toSemNum,
                'to_semester_id' => null,
                'to_academic_year_id' => $toAyId,
                'notes' => "Student completed Year {$fromStudyYear} Semester {$fromSemesterNumber}, but the next semester/academic period is not configured in the database.",
            ];
        }

        return [
            'status' => 'ready',
            'to_study_year' => $toYear,
            'to_semester_number' => $toSemNum,
            'to_semester_id' => $toSemId,
            'to_academic_year_id' => $toAyId,
            'notes' => "Progressed to Year {$toYear} Semester {$toSemNum}.",
        ];
    }

    private function findMatchingSemesterId(?int $academicYearId, int $semesterNumber, int $excludeSemesterId): ?int
    {
        if ($academicYearId) {
            $sem = Semester::where('academic_year_id', $academicYearId)
                ->where('semester_number', $semesterNumber)
                ->first();
            if ($sem) {
                return (int) $sem->id;
            }
        }

        $fallback = Semester::where('id', '!=', $excludeSemesterId)
            ->where('semester_number', $semesterNumber)
            ->orderBy('id')
            ->first();

        return $fallback ? (int) $fallback->id : null;
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function resolveTargetSemesterAndAcademicYear(
        Student $student,
        int $toYear,
        int $toSemNum,
        int $fromStudyYear,
        int $fromSemesterId,
        ?int $fromAyId
    ): array {
        // 1. Check if there is a CourseOffering for this program + toYear + semester_number
        $offeringQuery = CourseOffering::query()
            ->where('study_year', $toYear)
            ->where('semester_id', '!=', $fromSemesterId)
            ->whereHas('semester', fn ($sq) => $sq->where('semester_number', $toSemNum));

        if ($student->program_id) {
            $progOffering = (clone $offeringQuery)->where('program_id', $student->program_id)->orderBy('id')->first();
            if ($progOffering) {
                return [(int) $progOffering->semester_id, (int) ($progOffering->academic_year_id ?: $progOffering->semester?->academic_year_id)];
            }
        }

        $anyOffering = $offeringQuery->orderBy('id')->first();
        if ($anyOffering) {
            return [(int) $anyOffering->semester_id, (int) ($anyOffering->academic_year_id ?: $anyOffering->semester?->academic_year_id)];
        }

        // 2. If staying in the same Study Year, look for the next semester_number in the current AcademicYear first
        if ($toYear === $fromStudyYear && $fromAyId) {
            $sameAySem = Semester::where('academic_year_id', $fromAyId)
                ->where('semester_number', $toSemNum)
                ->where('id', '!=', $fromSemesterId)
                ->first();
            if ($sameAySem) {
                return [(int) $sameAySem->id, (int) $sameAySem->academic_year_id];
            }
        }

        // 3. Look for a subsequent Semester by start_date or ID matching semester_number
        $fromSem = Semester::find($fromSemesterId);
        $nextSemQuery = Semester::where('semester_number', $toSemNum)->where('id', '!=', $fromSemesterId);
        if ($fromSem && $fromSem->start_date) {
            $byDate = (clone $nextSemQuery)->where('start_date', '>', $fromSem->start_date)->orderBy('start_date')->first();
            if ($byDate) {
                return [(int) $byDate->id, (int) $byDate->academic_year_id];
            }
        }

        $byId = (clone $nextSemQuery)->where('id', '>', $fromSemesterId)->orderBy('id')->first()
            ?? $nextSemQuery->orderByDesc('id')->first();

        return [$byId ? (int) $byId->id : null, $byId ? (int) $byId->academic_year_id : $fromAyId];
    }

    /**
     * @return array{0: int|null, 1: int|null, 2: int|null, 3: int|null}
     */
    private function resolveFromAvailableOfferingsOrSemesters(
        Student $student,
        int $fromStudyYear,
        int $fromSemesterNumber,
        int $fromSemesterId,
        ?int $fromAyId,
        int $maxYears
    ): array {
        // Check if another semester exists in the same Academic Year with semester_number > fromSemesterNumber
        if ($fromAyId) {
            $nextInSameAy = Semester::where('academic_year_id', $fromAyId)
                ->where('semester_number', '>', $fromSemesterNumber)
                ->orderBy('semester_number')
                ->first();
            if ($nextInSameAy) {
                return [$fromStudyYear, (int) $nextInSameAy->semester_number, (int) $nextInSameAy->id, (int) $nextInSameAy->academic_year_id];
            }
        }

        // Otherwise advance to next Study Year, Semester 1
        $nextYear = $fromStudyYear + 1;
        if ($nextYear > $maxYears) {
            return [$nextYear, 1, null, null];
        }

        [$semId, $ayId] = $this->resolveTargetSemesterAndAcademicYear($student, $nextYear, 1, $fromStudyYear, $fromSemesterId, $fromAyId);

        return [$nextYear, 1, $semId, $ayId];
    }

    /**
     * Automatically create regular CourseEnrollments for the student's new academic period
     * based on configured CurriculumItems and available CourseOfferings.
     */
    private function enrollStudentInNextPeriodOfferings(
        Student $student,
        int $toStudyYear,
        int $toSemesterNumber,
        ?int $toSemesterId,
        ?int $toAyId
    ): int {
        if (! $toSemesterId) {
            return 0;
        }

        $programId = $student->program_id ? (int) $student->program_id : null;

        // 1. Identify planned course IDs from curriculum_items if configured for (program_id, toStudyYear, toSemesterNumber)
        $plannedCourseIds = [];
        if ($programId && \Illuminate\Support\Facades\Schema::hasTable('curriculum_items')) {
            $plannedCourseIds = CurriculumItem::where('program_id', $programId)
                ->where('study_year', $toStudyYear)
                ->where('semester_number', $toSemesterNumber)
                ->where('status', 'active')
                ->where('is_required', true)
                ->orderBy('display_order')
                ->pluck('course_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        // 2. Find matching active CourseOfferings for toSemesterId
        $offeringsQuery = CourseOffering::where('semester_id', $toSemesterId)
            ->where('status', 'active');

        if (! empty($plannedCourseIds)) {
            $offerings = (clone $offeringsQuery)->whereIn('course_id', $plannedCourseIds)->get();

            // For any planned curriculum course in this target period that does not yet have a CourseOffering row,
            // auto-provision an active CourseOffering so the student can be enrolled into the next semester immediately.
            $existingOfferingCourseIds = $offerings->pluck('course_id')->map(fn ($id) => (int) $id)->all();
            foreach ($plannedCourseIds as $plannedCid) {
                if (! in_array($plannedCid, $existingOfferingCourseIds, true)) {
                    $courseModel = Course::find($plannedCid);
                    $createdOffering = CourseOffering::create([
                        'course_id' => $plannedCid,
                        'program_id' => $programId ?: $courseModel?->program_id,
                        'academic_year_id' => $toAyId,
                        'semester_id' => $toSemesterId,
                        'class_id' => $student->class_id,
                        'study_year' => $toStudyYear,
                        'status' => 'active',
                    ]);
                    $offerings->push($createdOffering);
                }
            }
        } else {
            // Fall back to offerings matching student's program_id and study_year in that semester
            $offerings = (clone $offeringsQuery)
                ->where('study_year', $toStudyYear)
                ->when($programId, fn ($q) => $q->where(function ($sq) use ($programId) {
                    $sq->where('program_id', $programId)->orWhereNull('program_id');
                }))
                ->get();
        }

        $enrolledCount = 0;
        foreach ($offerings->unique('course_id') as $offering) {
            $existing = CourseEnrollment::where('student_id', $student->id)
                ->where('course_id', $offering->course_id)
                ->where('semester_id', $toSemesterId)
                ->first();

            if ($existing) {
                continue;
            }

            CourseEnrollment::create([
                'student_id' => $student->id,
                'course_id' => $offering->course_id,
                'course_offering_id' => $offering->id,
                'academic_year_id' => $offering->academic_year_id ?: $toAyId,
                'semester_id' => $toSemesterId,
                'semester_number' => $toSemesterNumber,
                'year_level' => $toStudyYear,
                'enrollment_date' => now()->toDateString(),
                'status' => 'enrolled',
                'enrollment_type' => 'regular',
                'attempt_number' => 1,
                'retake_status' => 'not_required',
            ]);
            $enrolledCount++;
        }

        return $enrolledCount;
    }
}
