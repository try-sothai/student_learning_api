<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Attendance;
use App\Models\ClassRoom;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Program;
use App\Models\RiskAssessment;
use App\Models\Semester;
use App\Models\Teacher;
use App\Support\YearLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class TeacherDashboardService
{
    public function __construct(
        protected TeacherAuthorizationService $authService
    ) {}

    /**
     * Calculate the 4 primary dashboard metrics for an authorized teacher.
     *
     * @param array<string, mixed> $filters
     * @return array{total_students: int, my_courses: int, at_risk_students: int, pending_scores: int}
     */
    public function getSummaryMetrics(Teacher $teacher, array $filters = []): array
    {
        $offeringsQuery = $this->authService->getAuthorizedOfferingsQuery($teacher);

        $this->applyScopeFilters($offeringsQuery, $filters);

        $offeringIds = (clone $offeringsQuery)->pluck('id')->all();
        $courseIds = (clone $offeringsQuery)->pluck('course_id')->filter()->unique()->all();

        // 1. Total Students: distinct enrolled students across authorized offerings
        $totalStudents = 0;
        if (! empty($offeringIds)) {
            $totalStudents = CourseEnrollment::query()
                ->whereIn('course_offering_id', $offeringIds)
                ->where('status', 'enrolled')
                ->distinct('student_id')
                ->count('student_id');
        } elseif (! empty($courseIds)) {
            $totalStudents = CourseEnrollment::query()
                ->whereIn('course_id', $courseIds)
                ->where('status', 'enrolled')
                ->distinct('student_id')
                ->count('student_id');
        }

        // 2. My Courses: distinct courses currently assigned to the teacher in scope
        $hasActiveFilter = ! empty(array_filter($filters, fn ($v) => $v !== null && $v !== ''));
        $myCoursesCount = count($courseIds);
        if ($myCoursesCount === 0 && ! $hasActiveFilter) {
            $myCoursesCount = Course::where('teacher_id', $teacher->id)
                ->whereNull('deleted_at')
                ->count();
        }

        // 3. At-Risk Students: medium and high risk students in teacher's assigned courses/offerings
        $atRiskStudents = 0;
        if (! empty($offeringIds)) {
            $atRiskStudents = RiskAssessment::query()
                ->where(function (Builder $q) use ($offeringIds, $courseIds) {
                    $q->whereIn('course_offering_id', $offeringIds)
                      ->orWhereIn('course_id', $courseIds);
                })
                ->whereIn('risk_level', ['medium', 'high'])
                ->whereIn('status', ['open', 'active', 'monitoring'])
                ->distinct('student_id')
                ->count('student_id');
        } elseif (! empty($courseIds)) {
            $atRiskStudents = RiskAssessment::query()
                ->whereIn('course_id', $courseIds)
                ->whereIn('risk_level', ['medium', 'high'])
                ->whereIn('status', ['open', 'active', 'monitoring'])
                ->distinct('student_id')
                ->count('student_id');
        }

        // 4. Pending Scores: ungraded submitted assignments + scheduled/unmarked attendance sessions
        $pendingAssignments = 0;
        if (! empty($offeringIds)) {
            $pendingAssignments = AssignmentSubmission::query()
                ->whereHas('assignment', function (Builder $q) use ($offeringIds, $courseIds) {
                    $q->whereIn('course_offering_id', $offeringIds)
                      ->orWhereIn('course_id', $courseIds);
                })
                ->where('is_latest', true)
                ->whereIn('status', ['submitted', 'pending', 'late'])
                ->whereNull('score')
                ->count();
        } elseif (! empty($courseIds)) {
            $pendingAssignments = AssignmentSubmission::query()
                ->whereHas('assignment', fn (Builder $q) => $q->whereIn('course_id', $courseIds))
                ->where('is_latest', true)
                ->whereIn('status', ['submitted', 'pending', 'late'])
                ->whereNull('score')
                ->count();
        }

        $pendingAttendance = 0;
        if (! empty($offeringIds) || ! empty($courseIds)) {
            $pendingAttendance = Attendance::query()
                ->where(function (Builder $q) use ($offeringIds, $courseIds) {
                    if (! empty($offeringIds)) {
                        $q->whereIn('course_offering_id', $offeringIds);
                    }
                    if (! empty($courseIds)) {
                        $q->orWhereIn('course_id', $courseIds);
                    }
                })
                ->where('status', 'scheduled')
                ->count();
        } elseif (! $hasActiveFilter) {
            $pendingAttendance = Attendance::where('teacher_id', $teacher->id)
                ->where('status', 'scheduled')
                ->count();
        }

        $pendingScores = $pendingAssignments + $pendingAttendance;

        return [
            'total_students' => (int) $totalStudents,
            'my_courses' => (int) $myCoursesCount,
            'at_risk_students' => (int) $atRiskStudents,
            'pending_scores' => (int) $pendingScores,
        ];
    }

    /**
     * Get cascading dynamic filter options strictly populated from the teacher's authorized offerings.
     *
     * @return array<string, mixed>
     */
    public function getAuthorizedFilters(Teacher $teacher): array
    {
        $offerings = $this->authService->getAuthorizedOfferingsQuery($teacher)
            ->with(['course', 'classRoom', 'semester.academicYear', 'program', 'academicYear'])
            ->get();

        $academicYears = collect();
        $semesters = collect();
        $programs = collect();
        $courses = collect();
        $classes = collect();
        $studyYears = collect();

        foreach ($offerings as $offering) {
            if ($offering->semester?->academicYear) {
                $ay = $offering->semester->academicYear;
                $academicYears->put($ay->id, [
                    'id' => $ay->id,
                    'name' => $ay->name,
                    'status' => $ay->status,
                ]);
            } elseif ($offering->academicYear) {
                $ay = $offering->academicYear;
                $academicYears->put($ay->id, [
                    'id' => $ay->id,
                    'name' => $ay->name,
                    'status' => $ay->status,
                ]);
            }

            if ($offering->semester) {
                $s = $offering->semester;
                $semesters->put($s->id, [
                    'id' => $s->id,
                    'academic_year_id' => $s->academic_year_id,
                    'name' => $s->name,
                    'semester_number' => $s->semester_number,
                    'status' => $s->status,
                ]);
            }

            if ($offering->program) {
                $p = $offering->program;
                $programs->put($p->id, [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name,
                ]);
            }

            if ($offering->course) {
                $c = $offering->course;
                $courses->put($c->id, [
                    'id' => $c->id,
                    'code' => $c->code ?? $c->course_code,
                    'name' => $c->name ?? $c->course_name,
                ]);
            }

            if ($offering->classRoom) {
                $cl = $offering->classRoom;
                $classes->put($cl->id, [
                    'id' => $cl->id,
                    'code' => $cl->code,
                    'name' => $cl->name,
                    'year_level' => $cl->year_level,
                    'year_level_code' => YearLevel::code($cl->year_level),
                    'year_level_label' => YearLevel::label($cl->year_level),
                ]);
            }

            if ($offering->study_year) {
                $parsedYear = YearLevel::parse($offering->study_year) ?? (int) $offering->study_year;
                $studyYears->put($parsedYear, [
                    'year' => $parsedYear,
                    'code' => YearLevel::code($parsedYear),
                    'name' => YearLevel::name($parsedYear) ?? ('Year ' . $parsedYear),
                    'label' => YearLevel::label($parsedYear),
                ]);
            }
        }

        // If offerings had empty programs, pull programs from courses/departments
        if ($programs->isEmpty()) {
            $courseDeptIds = Course::whereIn('id', $courses->keys())->pluck('department_id')->filter()->unique();
            $deptPrograms = Program::whereIn('department_id', $courseDeptIds)->get();
            foreach ($deptPrograms as $dp) {
                $programs->put($dp->id, [
                    'id' => $dp->id,
                    'code' => $dp->code,
                    'name' => $dp->name,
                ]);
            }
        }

        // Generations mapping based on active academic years
        $generations = collect([
            ['id' => 10, 'name' => 'Generation 10'],
            ['id' => 11, 'name' => 'Generation 11'],
            ['id' => 12, 'name' => 'Generation 12'],
        ]);

        return [
            'generations' => $generations->values()->all(),
            'programs' => $programs->values()->all(),
            'academic_years' => $academicYears->values()->all(),
            'study_years' => $studyYears->sortBy('year')->values()->all(),
            'year_levels' => YearLevel::options(),
            'semesters' => $semesters->values()->all(),
            'courses' => $courses->values()->all(),
            'classes' => $classes->values()->all(),
        ];
    }

    /**
     * Apply filter constraints to the offerings query.
     *
     * @param array<string, mixed> $filters
     */
    public function applyScopeFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['academic_year_id'])) {
            $query->where(function (Builder $q) use ($filters) {
                $q->where('academic_year_id', $filters['academic_year_id'])
                  ->orWhereHas('semester', fn (Builder $sq) => $sq->where('academic_year_id', $filters['academic_year_id']));
            });
        }

        if (! empty($filters['semester_id'])) {
            $query->where('semester_id', $filters['semester_id']);
        }

        if (! empty($filters['course_id'])) {
            $query->where('course_id', $filters['course_id']);
        }

        if (! empty($filters['class_id'])) {
            $query->where('class_id', $filters['class_id']);
        }

        if (! empty($filters['program_id'])) {
            $query->where('program_id', $filters['program_id']);
        }

        $ylFilter = YearLevel::parse($filters['year_level'] ?? $filters['study_year'] ?? null);
        if ($ylFilter !== null) {
            $query->where('study_year', $ylFilter);
        } elseif (! empty($filters['study_year'])) {
            $query->where('study_year', $filters['study_year']);
        }
    }
}
