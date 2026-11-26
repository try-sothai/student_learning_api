<?php

namespace App\Services;

use App\Models\CourseEnrollment;
use App\Models\CurriculumItem;
use App\Models\GpaRecord;
use App\Models\Grade;
use App\Models\MajorAcademicPeriod;
use App\Models\Semester;
use App\Models\Student;
use App\Support\YearLevel;

class GpaCalculationService
{
    public const FINALIZED_STATUSES = ['finalized', 'official', 'published'];
    public const PASSING_SCORE = 50.0;

    /**
     * Official SLAS institutional 4.0 grading scale.
     *
     * @return array{letter_grade: string, grade_point: float, passed: bool}
     */
    public static function mapScoreToGrade(?float $score): array
    {
        if ($score === null) {
            return ['letter_grade' => 'N/A', 'grade_point' => 0.0, 'passed' => false];
        }

        return match (true) {
            $score >= 90.0 => ['letter_grade' => 'A', 'grade_point' => 4.0, 'passed' => true],
            $score >= 85.0 => ['letter_grade' => 'A-', 'grade_point' => 3.7, 'passed' => true],
            $score >= 80.0 => ['letter_grade' => 'B+', 'grade_point' => 3.3, 'passed' => true],
            $score >= 75.0 => ['letter_grade' => 'B', 'grade_point' => 3.0, 'passed' => true],
            $score >= 70.0 => ['letter_grade' => 'B-', 'grade_point' => 2.7, 'passed' => true],
            $score >= 65.0 => ['letter_grade' => 'C+', 'grade_point' => 2.3, 'passed' => true],
            $score >= 60.0 => ['letter_grade' => 'C', 'grade_point' => 2.0, 'passed' => true],
            $score >= 50.0 => ['letter_grade' => 'D', 'grade_point' => 1.0, 'passed' => true],
            default => ['letter_grade' => 'F', 'grade_point' => 0.0, 'passed' => false],
        };
    }

    public static function mapLetterToGradePoint(?string $letterGrade, ?float $fallbackScore = null): ?float
    {
        if ($letterGrade !== null && trim($letterGrade) !== '' && strtoupper(trim($letterGrade)) !== 'N/A') {
            $normalized = strtoupper(trim($letterGrade));
            $mapped = match ($normalized) {
                'A+', 'A' => 4.0,
                'A-' => 3.7,
                'B+' => 3.3,
                'B' => 3.0,
                'B-' => 2.7,
                'C+' => 2.3,
                'C' => 2.0,
                'C-' => 1.7,
                'D+', 'D', 'D-' => 1.0,
                'F' => 0.0,
                default => null,
            };
            if ($mapped !== null) {
                return $mapped;
            }
        }

        if ($fallbackScore !== null) {
            return self::mapScoreToGrade($fallbackScore)['grade_point'];
        }

        return null;
    }

    public static function isGradeFinalized(?Grade $grade): bool
    {
        if (! $grade) {
            return false;
        }

        return in_array(strtolower((string) $grade->status), self::FINALIZED_STATUSES, true)
            && $grade->total_score !== null;
    }

    public static function isGradePassed(?Grade $grade): bool
    {
        if (! self::isGradeFinalized($grade)) {
            return false;
        }
        $letter = strtoupper(trim((string) ($grade->letter_grade ?? '')));
        if ($letter !== '' && $letter !== 'N/A') {
            return $letter !== 'F';
        }

        return (float) $grade->total_score >= self::PASSING_SCORE;
    }

    public static function academicStanding(?float $gpa): ?string
    {
        if ($gpa === null) {
            return null;
        }

        return match (true) {
            $gpa >= 3.50 => "Dean's List",
            $gpa >= 2.00 => 'Good Standing',
            default => 'Academic Probation',
        };
    }

    /**
     * Resolve the student's current active period (Study Year, Semester Number, Semester ID, Academic Year ID).
     *
     * @return array{study_year: int, semester_number: int, semester_id: int|null, semester_name: string, academic_year_id: int|null, academic_year_name: string}
     */
    public function resolveCurrentPeriod(Student $student): array
    {
        $student->loadMissing(['classRoom.academicYear', 'currentSemester.academicYear', 'currentAcademicYear']);

        $studyYear = (int) ($student->current_study_year ?: (YearLevel::parse($student->year_level) ?? 1));
        $semId = $student->current_semester_id ? (int) $student->current_semester_id : null;
        $ayId = $student->current_academic_year_id ? (int) $student->current_academic_year_id : null;
        $semNumber = $student->current_semester_number ? (int) $student->current_semester_number : null;

        // If student does not have explicit current_semester_id, inspect their active enrollments matching their current study year first
        $enrollments = CourseEnrollment::where('student_id', $student->id)
            ->with(['semester.academicYear', 'courseOffering.semester.academicYear', 'courseOffering.academicYear'])
            ->orderByDesc('id')
            ->get();

        if (! $semId && $enrollments->isNotEmpty()) {
            $matchingYearEnrollment = $enrollments->first(function (CourseEnrollment $e) use ($studyYear) {
                $eYear = (int) ($e->year_level ?: ($e->courseOffering?->study_year ?: 0));

                return $eYear === $studyYear && in_array(strtolower((string) $e->status), ['enrolled', 'active', 'completed', 'failed'], true);
            });

            $chosen = $matchingYearEnrollment
                ?? $enrollments->first(fn (CourseEnrollment $e) => in_array(strtolower((string) $e->status), ['enrolled', 'active'], true))
                ?? $enrollments->first();

            if ($chosen) {
                $semId = (int) ($chosen->semester_id ?: $chosen->courseOffering?->semester_id ?: 0) ?: null;
                $ayId = $ayId ?: ((int) ($chosen->academic_year_id ?: $chosen->courseOffering?->academic_year_id ?: $chosen->semester?->academic_year_id ?: 0) ?: null);
                $semNumber = $semNumber ?: ($chosen->semester_number ?: $chosen->semester?->semester_number ?: 1);
                if (! $student->current_study_year && ($chosen->year_level || $chosen->courseOffering?->study_year)) {
                    $studyYear = (int) ($chosen->year_level ?: $chosen->courseOffering?->study_year);
                }
            }
        }

        $semester = $semId ? Semester::with('academicYear')->find($semId) : null;
        if (! $semester) {
            $semester = Semester::with('academicYear')->where('status', 'active')->orderBy('id')->first()
                ?? Semester::with('academicYear')->orderByDesc('id')->first();
            $semId = $semester?->id;
        }

        $semNumber = $semNumber ?: ($semester?->semester_number ?: 1);
        $ayId = $ayId ?: ($semester?->academic_year_id ?: $student->classRoom?->academic_year_id);
        $ayName = $semester?->academicYear?->name
            ?? $student->currentAcademicYear?->name
            ?? $student->classRoom?->academicYear?->name
            ?? $student->resolveAcademicYearName()
            ?? '2026-2027';
        $semName = $semester?->name ?? ('Semester ' . $semNumber);

        return [
            'study_year' => max(1, $studyYear),
            'semester_number' => max(1, (int) $semNumber),
            'semester_id' => $semId ? (int) $semId : null,
            'semester_name' => (string) $semName,
            'academic_year_id' => $ayId ? (int) $ayId : null,
            'academic_year_name' => (string) $ayName,
        ];
    }

    /**
     * Recalculate and persist official GpaRecord for a student + semester when grades are finalized.
     * Does NOT overwrite or fabricate GPA when required enrolled courses in that semester are still pending.
     */
    public function recalculateAndSyncSemesterGpa(Student $student, int $semesterId): ?GpaRecord
    {
        $summary = $this->calculateSemesterSummary($student, $semesterId);

        if ($summary['enrolled_count'] === 0 && $summary['finalized_count'] === 0) {
            return null;
        }

        // Only persist an official GpaRecord when all enrolled courses in the semester have finalized grades
        // (or if there are finalized grades and 0 pending enrollments).
        if (! $summary['is_complete'] || $summary['semester_gpa'] === null) {
            return GpaRecord::where('student_id', $student->id)->where('semester_id', $semesterId)->first();
        }

        $semester = Semester::find($semesterId);

        return GpaRecord::updateOrCreate(
            [
                'student_id' => $student->id,
                'semester_id' => $semesterId,
            ],
            [
                'academic_year_id' => $semester?->academic_year_id,
                'study_year' => $summary['study_year'],
                'semester_number' => $semester?->semester_number ?? $summary['semester_number'],
                'gpa' => $summary['semester_gpa'],
                'semester_average' => $summary['semester_average'],
                'total_credits' => $summary['total_credits'],
                'earned_credits' => $summary['earned_credits'],
                'academic_standing' => self::academicStanding($summary['semester_gpa']),
                'is_official' => true,
            ]
        );
    }

    /**
     * Calculate exact semester metrics for a student in a specific semester.
     *
     * @return array<string, mixed>
     */
    public function calculateSemesterSummary(Student $student, int $semesterId, ?int $forStudyYear = null): array
    {
        $semester = Semester::with('academicYear')->find($semesterId);

        $enrollments = CourseEnrollment::with(['course.category', 'courseOffering.semester.academicYear'])
            ->where('student_id', $student->id)
            ->where(function ($q) use ($semesterId) {
                $q->where('semester_id', $semesterId)
                    ->orWhereHas('courseOffering', fn ($oq) => $oq->where('semester_id', $semesterId));
            })
            ->whereNotIn('status', ['dropped', 'cancelled'])
            ->orderBy('id')
            ->get();

        $grades = Grade::with(['course.category', 'courseOffering.semester.academicYear'])
            ->where('student_id', $student->id)
            ->where(function ($q) use ($semesterId) {
                $q->where('semester_id', $semesterId)
                    ->orWhereHas('courseOffering', fn ($oq) => $oq->where('semester_id', $semesterId));
            })
            ->orderBy('id')
            ->get();

        $gradesByCourse = $grades->keyBy('course_id');
        $gradesByOffering = $grades->whereNotNull('course_offering_id')->keyBy('course_offering_id');

        $coursesData = [];
        $seenCourseIds = [];
        $studyYear = $forStudyYear;

        foreach ($enrollments as $enr) {
            $grade = ($enr->course_offering_id && $gradesByOffering->has($enr->course_offering_id))
                ? $gradesByOffering->get($enr->course_offering_id)
                : $gradesByCourse->get($enr->course_id);

            $course = $enr->course ?? $enr->courseOffering?->course ?? $grade?->course;
            if (! $course) {
                continue;
            }
            $seenCourseIds[$course->id] = true;

            $resolvedYear = (int) ($enr->year_level ?: ($enr->courseOffering?->study_year ?: ($grade?->study_year ?: ($studyYear ?: (YearLevel::parse($student->year_level) ?? 1)))));
            if ($studyYear === null) {
                $studyYear = $resolvedYear;
            }

            $coursesData[] = $this->formatCourseRow($course, $enr, $grade, $semester);
        }

        // Also include any standalone grades for this semester that didn't have an enrollment row
        foreach ($grades as $grade) {
            if (isset($seenCourseIds[$grade->course_id])) {
                continue;
            }
            $course = $grade->course ?? $grade->courseOffering?->course;
            if (! $course) {
                continue;
            }
            $seenCourseIds[$course->id] = true;
            if ($studyYear === null) {
                $studyYear = (int) ($grade->study_year ?: ($grade->courseOffering?->study_year ?: (YearLevel::parse($student->year_level) ?? 1)));
            }
            $coursesData[] = $this->formatCourseRow($course, null, $grade, $semester);
        }

        $enrolledCount = count($coursesData);
        $finalizedCount = 0;
        $pendingCount = 0;
        $passedCount = 0;
        $failedCount = 0;

        $scoreSum = 0.0;
        $weightedPoints = 0.0;
        $totalCredits = 0.0;
        $earnedCredits = 0.0;
        $gradedCredits = 0.0;

        foreach ($coursesData as $row) {
            $cr = (float) ($row['credits'] ?: 3.0);
            $totalCredits += $cr;

            if ($row['is_finalized']) {
                $finalizedCount++;
                $scoreSum += (float) $row['score'];
                $gp = (float) $row['grade_point'];
                $weightedPoints += ($gp * $cr);
                $gradedCredits += $cr;

                if ($row['passed']) {
                    $passedCount++;
                    $earnedCredits += $cr;
                } else {
                    $failedCount++;
                }
            } else {
                $pendingCount++;
            }
        }

        $isComplete = $enrolledCount > 0 && $pendingCount === 0 && $finalizedCount === $enrolledCount;

        // Check if an explicit GpaRecord exists in DB
        $existingGpaRec = GpaRecord::where('student_id', $student->id)->where('semester_id', $semesterId)->first();

        $semesterAverage = $isComplete && $finalizedCount > 0
            ? round($scoreSum / $finalizedCount, 2)
            : ($existingGpaRec && $existingGpaRec->semester_average !== null && $pendingCount === 0
                ? round((float) $existingGpaRec->semester_average, 2)
                : null);

        $semesterGpa = null;
        if ($isComplete && $gradedCredits > 0) {
            $semesterGpa = round($weightedPoints / $gradedCredits, 2);
        } elseif ($enrolledCount === 0 && $existingGpaRec) {
            $semesterGpa = round((float) $existingGpaRec->gpa, 2);
            $totalCredits = (float) $existingGpaRec->total_credits;
            $earnedCredits = (float) $existingGpaRec->earned_credits;
            $isComplete = true;
        }

        return [
            'semester_id' => $semesterId,
            'semester_name' => $semester?->name ?? 'Semester',
            'semester_number' => (int) ($semester?->semester_number ?? 1),
            'academic_year_id' => $semester?->academic_year_id,
            'academic_year_name' => $semester?->academicYear?->name ?? '',
            'study_year' => $studyYear ?? (YearLevel::parse($student->year_level) ?? 1),
            'state' => $isComplete ? 'Completed' : ($enrolledCount > 0 ? 'Current' : 'Planned'),
            'is_complete' => $isComplete,
            'enrolled_count' => $enrolledCount,
            'finalized_count' => $finalizedCount,
            'pending_count' => $pendingCount,
            'passed_count' => $passedCount,
            'failed_count' => $failedCount,
            'total_credits' => round($totalCredits, 1),
            'earned_credits' => round($earnedCredits, 1),
            'semester_average' => $semesterAverage,
            'semester_average_display' => $semesterAverage !== null ? number_format($semesterAverage, 2, '.', '') : 'N/A',
            'semester_gpa' => $semesterGpa,
            'semester_gpa_display' => $semesterGpa !== null ? number_format($semesterGpa, 2, '.', '') : 'N/A',
            'academic_standing' => self::academicStanding($semesterGpa),
            'courses' => $coursesData,
        ];
    }

    /**
     * Build complete Year 1 - Year 4 GPA Academic History for a student,
     * combining historical completed semesters, current enrolled semester,
     * future planned curriculum courses (with N/A scores/grades/averages/GPA),
     * and separate original vs retake attempts.
     *
     * @return array<string, mixed>
     */
    public function buildFullAcademicHistory(Student $student): array
    {
        $student->loadMissing(['department', 'program', 'classRoom.academicYear', 'user']);
        $currentPeriod = $this->resolveCurrentPeriod($student);
        $currentStudyYear = $currentPeriod['study_year'];
        $currentSemesterId = $currentPeriod['semester_id'];

        $durationYears = max(4, (int) ($student->program?->duration_years ?: config('slas.max_year_level', 4)));
        $programId = $student->program_id ? (int) $student->program_id : null;

        // 1. Load all curriculum items for the student's Major
        $curriculumItems = collect();
        if ($programId && \Illuminate\Support\Facades\Schema::hasTable('curriculum_items')) {
            $curriculumItems = CurriculumItem::with(['course.category', 'category'])
                ->where('program_id', $programId)
                ->where('status', 'active')
                ->orderBy('study_year')
                ->orderBy('semester_number')
                ->orderBy('display_order')
                ->orderBy('id')
                ->get();
        }

        // 2. Load Major-specific academic calendar periods if configured
        $calendarPeriods = collect();
        if ($programId && \Illuminate\Support\Facades\Schema::hasTable('major_academic_periods')) {
            $calendarPeriods = MajorAcademicPeriod::with(['academicYear', 'semester'])
                ->where('program_id', $programId)
                ->where('status', '!=', 'inactive')
                ->orderBy('study_year')
                ->orderBy('sequence_order')
                ->orderBy('semester_number')
                ->get();
        }

        // 3. Load all student enrollments and grades across all semesters
        $allEnrollments = CourseEnrollment::with(['course.category', 'semester.academicYear', 'courseOffering.semester.academicYear', 'courseOffering.classRoom'])
            ->where('student_id', $student->id)
            ->whereNotIn('status', ['dropped', 'cancelled'])
            ->orderBy('id')
            ->get();

        $allGrades = Grade::with(['course.category', 'semester.academicYear', 'courseOffering.semester.academicYear'])
            ->where('student_id', $student->id)
            ->orderBy('id')
            ->get();

        // Group actual studied semesters by (study_year, semester_id)
        $studiedSemestersByYear = [];
        foreach ($allEnrollments as $enr) {
            $semId = (int) ($enr->semester_id ?: $enr->courseOffering?->semester_id ?: 0);
            if ($semId <= 0) {
                continue;
            }
            $sYear = (int) ($enr->year_level ?: ($enr->courseOffering?->study_year ?: $currentStudyYear));
            if ($sYear < 1) {
                $sYear = 1;
            }
            $studiedSemestersByYear[$sYear][$semId] = true;
        }
        foreach ($allGrades as $gr) {
            $semId = (int) ($gr->semester_id ?: $gr->courseOffering?->semester_id ?: 0);
            if ($semId <= 0) {
                continue;
            }
            $sYear = (int) ($gr->study_year ?: ($gr->courseOffering?->study_year ?: $currentStudyYear));
            if ($sYear < 1) {
                $sYear = 1;
            }
            $studiedSemestersByYear[$sYear][$semId] = true;
        }

        $yearsOutput = [];
        $allCompletedSemesterGpas = [];
        $totalWeightedPoints = 0.0;
        $totalWeightedCredits = 0.0;
        $totalEarnedCredits = 0.0;

        for ($yearNum = 1; $yearNum <= $durationYears; $yearNum++) {
            $yearSemesters = [];
            $coveredSemesterNumbers = [];

            // A. Actual studied/enrolled semesters for this Study Year
            $semIds = array_keys($studiedSemestersByYear[$yearNum] ?? []);
            sort($semIds);

            foreach ($semIds as $semId) {
                $semSummary = $this->calculateSemesterSummary($student, (int) $semId, $yearNum);
                $semNum = (int) ($semSummary['semester_number'] ?: 1);
                $coveredSemesterNumbers[$semNum] = true;

                // Attach calendar dates if configured for this Major + Study Year + Semester Number
                $calPeriod = $calendarPeriods->first(fn (MajorAcademicPeriod $p) => (int) $p->study_year === $yearNum && ((int) $p->semester_id === (int) $semId || (int) $p->semester_number === $semNum));
                $semObj = Semester::find($semId);
                $semSummary['start_date'] = $calPeriod?->start_date?->toDateString() ?? $semObj?->start_date?->toDateString();
                $semSummary['end_date'] = $calPeriod?->end_date?->toDateString() ?? $semObj?->end_date?->toDateString();
                $semSummary['is_current_semester'] = ((int) $semId === (int) $currentSemesterId && $yearNum === $currentStudyYear);
                if ($semSummary['is_current_semester'] && ! $semSummary['is_complete']) {
                    $semSummary['state'] = 'Current';
                }

                if ($semSummary['is_complete'] && $semSummary['semester_gpa'] !== null) {
                    $allCompletedSemesterGpas[] = $semSummary['semester_gpa'];
                    $cr = (float) ($semSummary['total_credits'] ?: 0.0);
                    $totalWeightedPoints += ((float) $semSummary['semester_gpa']) * $cr;
                    $totalWeightedCredits += $cr;
                    $totalEarnedCredits += (float) ($semSummary['earned_credits'] ?: 0.0);
                }

                $yearSemesters[] = $semSummary;
            }

            // B. Planned Curriculum semesters for this Study Year that the student has not yet studied
            $yearCurriculumBySem = $curriculumItems->where('study_year', $yearNum)->groupBy('semester_number');
            $yearCalendarBySem = $calendarPeriods->where('study_year', $yearNum)->groupBy('semester_number');

            $plannedSemNumbers = $yearCurriculumBySem->keys()
                ->merge($yearCalendarBySem->keys())
                ->unique()
                ->sort()
                ->values();

            // If a future or unconfigured year has no curriculum rows yet, still expose configured semesters or default Semester 1 & Semester 2 placeholders if curriculum exists for other years
            foreach ($plannedSemNumbers as $semNum) {
                $semNumInt = (int) $semNum;
                if (isset($coveredSemesterNumbers[$semNumInt])) {
                    // Merge any remaining planned curriculum courses in the current/active semester that are not yet enrolled
                    foreach ($yearSemesters as &$existingSem) {
                        if ((int) $existingSem['semester_number'] === $semNumInt && ! $existingSem['is_complete']) {
                            $enrolledCourseIds = collect($existingSem['courses'])->pluck('course_id')->all();
                            foreach ($yearCurriculumBySem->get($semNumInt, collect()) as $ci) {
                                if (! $ci->course || in_array((int) $ci->course_id, $enrolledCourseIds, true)) {
                                    continue;
                                }
                                $existingSem['courses'][] = $this->formatPlannedCurriculumRow($ci, $yearNum, $semNumInt);
                            }
                        }
                    }
                    unset($existingSem);
                    continue;
                }

                $semItems = $yearCurriculumBySem->get($semNumInt, collect());
                $calPeriod = $yearCalendarBySem->get($semNumInt)?->first();
                $semName = $calPeriod?->semester_name
                    ?: ($semItems->first()?->semester_name ?: ('Semester ' . $semNumInt));

                $plannedCourses = [];
                $plannedCredits = 0.0;
                foreach ($semItems as $ci) {
                    if (! $ci->course) {
                        continue;
                    }
                    $row = $this->formatPlannedCurriculumRow($ci, $yearNum, $semNumInt);
                    $plannedCredits += (float) $row['credits'];
                    $plannedCourses[] = $row;
                }

                $yearSemesters[] = [
                    'semester_id' => $calPeriod?->semester_id,
                    'semester_name' => $semName,
                    'semester_number' => $semNumInt,
                    'academic_year_id' => $calPeriod?->academic_year_id,
                    'academic_year_name' => $calPeriod?->academicYear?->name ?? '',
                    'study_year' => $yearNum,
                    'start_date' => $calPeriod?->start_date?->toDateString(),
                    'end_date' => $calPeriod?->end_date?->toDateString(),
                    'state' => 'Planned',
                    'is_current_semester' => false,
                    'is_complete' => false,
                    'enrolled_count' => 0,
                    'finalized_count' => 0,
                    'pending_count' => count($plannedCourses),
                    'passed_count' => 0,
                    'failed_count' => 0,
                    'total_credits' => round($plannedCredits, 1),
                    'earned_credits' => 0.0,
                    'semester_average' => null,
                    'semester_average_display' => 'N/A',
                    'semester_gpa' => null,
                    'semester_gpa_display' => 'N/A',
                    'academic_standing' => null,
                    'courses' => $plannedCourses,
                ];
            }

            usort($yearSemesters, fn (array $a, array $b) => ($a['semester_number'] <=> $b['semester_number']) ?: (($a['semester_id'] ?? 999999) <=> ($b['semester_id'] ?? 999999)));

            $yearState = 'Future';
            if ($yearNum < $currentStudyYear) {
                $yearState = 'Completed';
            } elseif ($yearNum === $currentStudyYear) {
                $allSemComplete = ! empty($yearSemesters) && collect($yearSemesters)->every(fn ($s) => $s['is_complete']);
                $yearState = $allSemComplete ? 'Completed' : 'Current';
            } elseif (collect($yearSemesters)->contains(fn ($s) => $s['enrolled_count'] > 0)) {
                $yearState = 'Current';
            }

            $yearsOutput[] = [
                'study_year' => $yearNum,
                'year_level' => $yearNum,
                'year_level_code' => YearLevel::code($yearNum),
                'year_level_name' => YearLevel::name($yearNum) ?? ('Year ' . $yearNum),
                'year_level_label' => YearLevel::label($yearNum) ?? ('Y' . $yearNum . ' — Year ' . $yearNum),
                'state' => $yearState,
                'is_current_year' => $yearNum === $currentStudyYear,
                'semesters_count' => count($yearSemesters),
                'semesters' => $yearSemesters,
            ];
        }

        // Current semester detail
        $currentSemesterSummary = $currentSemesterId
            ? $this->calculateSemesterSummary($student, (int) $currentSemesterId, $currentStudyYear)
            : null;

        $cumulativeGpa = $totalWeightedCredits > 0
            ? round($totalWeightedPoints / $totalWeightedCredits, 2)
            : (! empty($allCompletedSemesterGpas) ? round(array_sum($allCompletedSemesterGpas) / count($allCompletedSemesterGpas), 2) : null);

        // Fallback to GpaRecord table if no enrollment-linked finalized grades were found (e.g. legacy seeded GpaRecords)
        if ($cumulativeGpa === null) {
            $gpaRecords = GpaRecord::where('student_id', $student->id)->orderBy('semester_id')->get();
            if ($gpaRecords->isNotEmpty()) {
                $wp = 0.0;
                $wc = 0.0;
                foreach ($gpaRecords as $gr) {
                    $cr = (float) ($gr->earned_credits ?: $gr->total_credits ?: 0.0);
                    if ($cr > 0) {
                        $wp += ((float) $gr->gpa) * $cr;
                        $wc += $cr;
                    }
                    $totalEarnedCredits += (float) ($gr->earned_credits ?: 0.0);
                }
                $cumulativeGpa = $wc > 0 ? round($wp / $wc, 2) : round((float) $gpaRecords->avg('gpa'), 2);
            }
        }

        $currentSemesterGpa = $currentSemesterSummary ? $currentSemesterSummary['semester_gpa'] : null;
        $currentSemesterAverage = $currentSemesterSummary ? $currentSemesterSummary['semester_average'] : null;

        return [
            'student' => [
                'id' => (int) $student->id,
                'student_code' => (string) $student->student_code,
                'name' => $student->resolveDisplayName(),
                'faculty_id' => $student->department_id ? (int) $student->department_id : null,
                'faculty_name' => $student->department?->name ?? '',
                'faculty_code' => $student->department?->code ?? '',
                'major_id' => $student->program_id ? (int) $student->program_id : null,
                'major_name' => $student->program?->name ?? ($student->major ?? ''),
                'major_code' => $student->program?->code ?? '',
                'class_id' => $student->class_id ? (int) $student->class_id : null,
                'class_name' => $student->classRoom?->name ?? '',
                'current_academic_year_id' => $currentPeriod['academic_year_id'],
                'current_academic_year' => $currentPeriod['academic_year_name'],
                'current_study_year' => $currentStudyYear,
                'year_level_code' => YearLevel::code($currentStudyYear),
                'year_level_name' => YearLevel::name($currentStudyYear),
                'year_level_label' => YearLevel::label($currentStudyYear),
                'current_semester_id' => $currentPeriod['semester_id'],
                'current_semester_number' => $currentPeriod['semester_number'],
                'current_semester' => $currentPeriod['semester_name'],
                'progression_status' => (string) ($student->progression_status ?: 'in_progress'),
            ],
            'summary' => [
                'current_semester_gpa' => $currentSemesterGpa,
                'current_semester_gpa_display' => $currentSemesterGpa !== null ? number_format($currentSemesterGpa, 2, '.', '') : 'N/A',
                'current_semester_average' => $currentSemesterAverage,
                'current_semester_average_display' => $currentSemesterAverage !== null ? number_format($currentSemesterAverage, 2, '.', '') : 'N/A',
                'cumulative_gpa' => $cumulativeGpa,
                'cumulative_gpa_display' => $cumulativeGpa !== null ? number_format($cumulativeGpa, 2, '.', '') : 'N/A',
                'completed_credits' => round($totalEarnedCredits, 1),
                'academic_standing' => self::academicStanding($currentSemesterGpa ?? $cumulativeGpa),
            ],
            'current_semester' => $currentSemesterSummary,
            'years' => $yearsOutput,
        ];
    }

    private function formatCourseRow($course, ?CourseEnrollment $enr, ?Grade $grade, ?Semester $semester): array
    {
        $isFinalized = self::isGradeFinalized($grade);
        $score = $isFinalized ? round((float) $grade->total_score, 2) : null;

        $mapped = $score !== null ? self::mapScoreToGrade($score) : ['letter_grade' => 'N/A', 'grade_point' => 0.0, 'passed' => false];
        $letter = $isFinalized
            ? (! empty($grade->letter_grade) && strtoupper($grade->letter_grade) !== 'N/A' ? strtoupper($grade->letter_grade) : $mapped['letter_grade'])
            : 'N/A';
        $gradePoint = $isFinalized
            ? ($grade->grade_point !== null ? (float) $grade->grade_point : (self::mapLetterToGradePoint($letter, $score) ?? 0.0))
            : null;
        $passed = $isFinalized && self::isGradePassed($grade);

        $attemptNumber = (int) ($grade?->attempt_number ?: ($enr?->attempt_number ?: 1));
        $enrollmentType = strtolower((string) ($enr?->enrollment_type ?: ($grade?->is_retake ? 'retake' : 'regular')));
        $isRetake = $enrollmentType === 'retake' || (bool) ($grade?->is_retake) || $attemptNumber > 1;
        $credits = (float) ($course->credit ?? $course->credits ?? 3.0);

        $statusLabel = 'Pending';
        if ($isFinalized) {
            $statusLabel = $passed ? 'Passed' : 'Failed';
        } elseif ($grade && strtolower((string) $grade->status) === 'provisional') {
            $statusLabel = 'Provisional';
        } elseif ($grade && strtolower((string) $grade->status) === 'draft') {
            $statusLabel = 'Pending';
        }

        return [
            'course_id' => (int) $course->id,
            'course_offering_id' => (int) ($enr?->course_offering_id ?: ($grade?->course_offering_id ?: 0)) ?: null,
            'enrollment_id' => $enr?->id ? (int) $enr->id : null,
            'grade_id' => $grade?->id ? (int) $grade->id : null,
            'course_code' => (string) ($course->course_code ?? $course->code ?? ''),
            'course_name' => (string) ($course->course_name ?? $course->name ?? ''),
            'course_category_id' => $course->course_category_id ? (int) $course->course_category_id : null,
            'course_category_name' => $course->category?->name,
            'credits' => $credits,
            'semester_id' => $semester?->id,
            'semester_name' => $semester?->name ?? '',
            'academic_year' => $semester?->academicYear?->name ?? '',
            'score' => $score,
            'total_score' => $score,
            'score_display' => $score !== null ? number_format($score, 2, '.', '') : 'N/A',
            'letter_grade' => $isFinalized ? $letter : null,
            'grade_display' => $isFinalized ? $letter : 'N/A',
            'grade_point' => $gradePoint,
            'is_finalized' => $isFinalized,
            'is_planned' => false,
            'passed' => $passed,
            'requires_retake' => $isFinalized && ! $passed,
            'status' => $isFinalized ? strtolower((string) $grade->status) : 'pending',
            'result_status' => $statusLabel,
            'enrollment_type' => $isRetake ? 'retake' : 'regular',
            'is_retake' => $isRetake,
            'attempt_number' => $attemptNumber,
            'attempt_label' => $isRetake ? ('Retake ' . max(1, $attemptNumber - 1)) : 'Original',
            'original_enrollment_id' => $enr?->original_enrollment_id,
            'original_grade_id' => $grade?->original_grade_id,
        ];
    }

    private function formatPlannedCurriculumRow(CurriculumItem $ci, int $studyYear, int $semesterNumber): array
    {
        $course = $ci->course;
        $credits = (float) ($ci->credits ?? $course?->credit ?? $course?->credits ?? 3.0);
        $cat = $ci->category ?? $course?->category;

        return [
            'curriculum_item_id' => (int) $ci->id,
            'course_id' => (int) $ci->course_id,
            'course_offering_id' => null,
            'enrollment_id' => null,
            'grade_id' => null,
            'course_code' => (string) ($course?->course_code ?? $course?->code ?? ''),
            'course_name' => (string) ($course?->course_name ?? $course?->name ?? ''),
            'course_category_id' => $cat?->id ? (int) $cat->id : null,
            'course_category_name' => $cat?->name,
            'credits' => $credits,
            'is_required' => (bool) $ci->is_required,
            'study_year' => $studyYear,
            'semester_number' => $semesterNumber,
            'semester_id' => null,
            'semester_name' => $ci->semester_name ?: ('Semester ' . $semesterNumber),
            'academic_year' => '',
            'score' => null,
            'total_score' => null,
            'score_display' => 'N/A',
            'letter_grade' => null,
            'grade_display' => 'N/A',
            'grade_point' => null,
            'is_finalized' => false,
            'is_planned' => true,
            'passed' => false,
            'requires_retake' => false,
            'status' => 'planned',
            'result_status' => 'Planned',
            'enrollment_type' => 'planned',
            'is_retake' => false,
            'attempt_number' => 0,
            'attempt_label' => 'Planned',
            'original_enrollment_id' => null,
            'original_grade_id' => null,
        ];
    }
}
