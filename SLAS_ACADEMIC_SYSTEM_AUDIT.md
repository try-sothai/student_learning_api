# SLAS Academic System Audit Report

**Audit Date:** 2026-11-26  
**System:** Student Learning Analytics System (SLAS)  
**Components Audited:**
1. **MySQL Database** (`student_learning_analytics` — 64 tables)
2. **Laravel 12 Backend & REST API** (`D:\Development\Projects\student_learning_api`)
3. **Android Student & Teacher Applications** (`D:\Development\Projects\studentlearnanalytics`)

---

## 1. Existing Architecture Overview

SLAS is a multi-tier academic management and learning analytics platform:
- **Database Layer:** MySQL 8.4 (`student_learning_analytics`) storing institutional academic structure, users, enrollments, assessments, grades, GPA records, and analytics.
- **Backend & REST API Layer:** Laravel 12 with Laravel Sanctum token authentication and Role-Based Access Control (`role:admin`, `role:teacher`, `role:student`), serving both the Web Frontend (Blade/Livewire, which must remain untouched) and the Android mobile applications over `/api/*`.
- **Android Mobile Layer:** Native Java Android application (`com.social7.studentlearnanalytics`) supporting Admin, Teacher, and Student roles via Retrofit2/OkHttp3 and Gson.

---

## 2. Existing Relevant Database Tables & Schema Audit

| Table Name | Row Count | Primary Key | Purpose & Key Columns |
|---|---:|---|---|
| `departments` | 2 | `id` | **Faculties** (`id`, `name`, `code`, `description`, `status`). Currently holds `Faculty of Science and Technology (FOSAT, id=14)` and `Faculty of Business Administration (FBA, id=16)`. |
| `programs` | 9 | `id` | **Majors** (`id`, `department_id` FK -> `departments.id`, `code`, `name`, `description`, `duration_years`, `status`). Holds active majors `CS`, `IT`, `DS`, `SE` under `FOSAT` and `BA`, `BBA` under `FBA` (plus inactive legacy codes `BSCS`, `BSE`, `BSIT`). |
| `course_categories` | 0 | `id` | **Course Categories** (`id`, `name`, `code`, `description`, `status`). Independent from Faculty and Major. |
| `courses` | 11 | `id` | **Course Catalog** (`id`, `department_id`, `course_category_id`, `code`/`course_code`, `name`/`course_name`, `credit`/`credits`, `year_level`, `description`, `status`, `deleted_at`). |
| `course_program` | 11 | `id` | Many-to-many pivot linking `courses` to `programs` (Majors) with unique(`course_id`, `program_id`). |
| `academic_years` | 5 | `id` | Institutional Academic Years (`id`, `name`, `start_date`, `end_date`, `status`). |
| `semesters` | 10 | `id` | Institutional Semesters (`id`, `academic_year_id`, `name`, `semester_number`, `start_date`, `end_date`, `status`) with unique(`academic_year_id`, `semester_number`). |
| `classes` | 22 | `id` | Cohort/Section classrooms (`id`, `department_id`, `academic_year_id`, `building_id`, `floor_id`, `course_id`, `teacher_id`, `name`, `code`, `year_level`, `capacity`, `status`). |
| `students` | 1 | `id` | Student profile (`id`, `user_id`, `department_id`, `program_id`, `class_id`, `student_code`, `khmer_name`, `english_name`, `first_name`, `last_name`, `gender`, `year_level`, `status`, `deleted_at`). |
| `course_offerings` | 85 | `id` | Concrete teaching instance of a course (`id`, `course_id`, `class_id`, `semester_id`, `academic_year_id`, `program_id`, `study_year`, `section_name`, `status`, `deleted_at`). |
| `course_offering_teachers` | 3 | `id` | Pivot linking teachers to `course_offerings` (`course_offering_id`, `teacher_id`, `role`, `status`, `assigned_at`, `ended_at`). |
| `course_enrollments` | 5 | `id` | Student enrollment in a course offering (`id`, `course_offering_id`, `student_id`, `course_id`, `semester_id`, `year_level`, `enrollment_date`, `status`, `final_score`, `grade`). Unique index on (`student_id`, `course_id`, `semester_id`). |
| `grades` | 0 | `id` | Course grade record (`id`, `course_offering_id`, `student_id`, `course_id`, `semester_id`, `assignment_score`, `quiz_score`, `attendance_score`, `midterm_score`, `final_exam_score`, `total_score`, `letter_grade`, `grade_point`, `status`). Unique index on (`student_id`, `course_id`, `semester_id`). |
| `gpa_records` | 0 | `id` | Per-semester student GPA summary (`id`, `student_id`, `semester_id`, `gpa`, `total_credits`, `earned_credits`, `academic_standing`). Unique index on (`student_id`, `semester_id`). |
| `attendance`, `attendance_details` | 2 / 2 | `id` | Offering-scoped attendance sessions and student attendance statuses. |
| `assignments`, `assignment_submissions` | 2 / 2 | `id` | Offering-scoped assignments and versioned student submissions. |
| `quizzes`, `quiz_questions`, `quiz_attempts`, `quiz_answers` | 5 / 13 / 9 / 19 | `id` | Offering-scoped quizzes and student attempts. |
| `exams`, `exam_results` | 2 / 2 | `id` | Offering-scoped midterm/final exams and student scores. |

---

## 3. Existing Relationships

- **Faculty → Major:** `Department::programs()` / `Department::majors()` (`1:N` via `programs.department_id`).
- **Major ↔ Course:** `Program::courses()` / `Course::programs()` (`M:N` via `course_program`).
- **Course Category → Course:** `CourseCategory::courses()` (`1:N` via `courses.course_category_id`).
- **Academic Year → Semester:** `AcademicYear::semesters()` (`1:N` via `semesters.academic_year_id`).
- **Course → Course Offering:** `Course::offerings()` (`1:N` via `course_offerings.course_id`), scoped to `program_id`, `academic_year_id`, `semester_id`, `study_year`, and `class_id`.
- **Course Offering → Course Enrollment:** `CourseOffering::enrollments()` (`1:N` via `course_enrollments.course_offering_id`).
- **Student → Grades & GPA:** `Student::grades()` (`1:N`), `Student::gpaRecords()` (`1:N`).

---

## 4. Existing API Endpoints

- **Academic Structure:**
  - `GET/POST/PUT/DELETE /api/departments` & `/api/faculties`
  - `GET /api/departments/{department}/programs` & `/api/faculties/{department}/majors`
  - `GET/POST/PUT/DELETE /api/programs` & `/api/majors`
  - `GET /api/programs/{program}/courses` & `/api/majors/{program}/courses`
  - `GET/POST/PUT/DELETE /api/course-categories`
  - `GET/POST/PUT/DELETE /api/courses` & `POST /api/courses/{course}/majors`
  - `GET/POST/PUT/DELETE /api/academic-years` & `/api/semesters`
- **Student Module & GPA:**
  - `GET /api/dashboard/student` (`DashboardController@student`)
  - `GET /api/student/module/dashboard` (`StudentModuleController@dashboard`)
  - `GET /api/student/module/academic-journey` (`StudentModuleController@academicJourney`)
  - `GET /api/student/module/courses` (`StudentModuleController@courses`)
  - `GET /api/student/module/grades` (`StudentModuleController@gradesOverview`)
  - `GET /api/student/module/grades/courses/{id}` (`StudentModuleController@gradesCourse`)
  - `GET /api/student/module/comparisons` (`StudentModuleController@comparisons`)
  - `GET/POST/PUT/DELETE /api/grades` (`GradeController`)
  - `GET/POST/PUT/DELETE /api/gpa-records` (`GpaRecordController`)

---

## 5. Existing Android GPA Implementation

1. **`StudentDashboardFragment.java`**:
   - Displays `cardGpa` with `tvMetricGpa` (bound to `data.getGpaFormatted()`) and `tvGpaSub` (bound to `gpaSummary.academicStanding`).
   - Clicking `cardGpa` currently opens `GradesOverviewActivity.class`.
2. **`GradesOverviewActivity.java` (`activity_grades_overview.xml`)**:
   - Calls `GET /api/student/module/grades` (`getStudentModuleGradesOverview(null)`).
   - Displays a summary card (`Passed`, `Pending`, `Failed`, `GPA`) and a flat `RecyclerView` of all courses across all semesters without current-period header metadata (`Current Academic Year`, `Current Study Year`, `Current Semester`), without `Semester Average`, and without a `"View All"` button to open the Year 1–Year 4 GPA Academic History screen.
3. **`GpaProgressActivity.java` (`activity_gpa_progress.xml`)**:
   - Only displays three static text cards (`Cumulative GPA`, `Semester GPA`, `Completed Credits`) with no Year 1–Year 4 tabs and no semester course tables.
4. **`AcademicJourneyActivity.java` (`activity_academic_journey.xml`)**:
   - Calls `GET /api/student/module/academic-journey`, which only queries `CourseOffering::whereHas('enrollments', ...)`—meaning it only shows enrolled semesters without scores, letter grades, semester averages, semester GPAs, or future planned curriculum years.

---

## 6. Missing Functionality & Conflicting Structures Identified

1. **Missing Study Curriculum Table (`curriculum_courses` / `study_curricula`):**
   - Currently `course_program` only links `course_id` and `program_id` without `study_year` (`1..4`), `semester_number` (`1..N`), `curriculum_version`, `is_required` (Required vs Elective), `display_order`, or `credits_override`.
   - As a result, the system cannot store a structured Year 1–Year 4 study plan per Major or return planned future courses (`Score: N/A`, `Grade: N/A`, `Semester Average: N/A`, `Semester GPA: N/A`) for years the student has not studied yet.
2. **Missing Major-Specific Academic Calendar & Audit History:**
   - `semesters` is global per `academic_year_id` (`unique(academic_year_id, semester_number)`). There is no table for Major-specific academic calendar periods (`major_academic_periods`) allowing different Majors and Study Years to have independent semester start/end dates and durations (3 months, 4 months, 6 months, etc.), nor a calendar change history table (`academic_calendar_audits`) recording `previous_start_date`, `new_start_date`, `previous_end_date`, `new_end_date`, `changed_by`, and `reason`.
3. **Missing Individual Automatic Semester Progression:**
   - `students` has `year_level`, `department_id`, `program_id`, and `class_id`, but lacks explicit `current_academic_year_id`, `current_semester_id`, `current_study_year`, ` current_semester_number`, and `progression_status` tracking.
   - There is no event/service triggered when a student's final remaining `Grade` in their current semester is finalized (`status` in `['finalized', 'official', 'published']`), nor a progression history/audit table (`student_progression_histories`) or scheduled reconciliation command.
4. **Missing Retake Management & Cross-Cohort Enrollment Tracking:**
   - When a student fails a course (`letter_grade === 'F'` or `total_score < 50`), there is no retake tracking record (`student_course_retakes`) or enrollment attempt metadata (`enrollment_type = 'regular'|'retake'`, `attempt_number`, `original_enrollment_id`, `original_grade_id`) on `course_enrollments` and `grades`.
   - Note that `course_enrollments` has `unique(student_id, course_id, semester_id)` and `grades` has `unique(student_id, course_id, semester_id)`. Since a retake occurs in a *different* (later) `semester_id` with a junior cohort's `CourseOffering`, the existing `(student_id, course_id, semester_id)` unique constraint naturally allows one attempt per semester while preventing duplicate enrollments in the same semester! Adding `enrollment_type`, `attempt_number`, `original_enrollment_id` to `course_enrollments` and `attempt_number`, `is_retake`, `original_grade_id` to `grades` cleanly supports full retake history without breaking any existing queries.
5. **Inconsistent Dashboard GPA Card Semantics:**
   - `DashboardController@student` falls back to `cumulativeGpa` even when the student's current semester has no finalized grades, or uses `earned_credits ?: 1.0` when `earned_credits` is 0.
   - Requirement Phase 8.1: The GPA dashboard card must display the student's **current semester GPA** when officially available (or `"N/A"` / `"Unavailable"` when not yet available, never fabricated from incomplete or provisional grades).

---

## 7. Required Database Changes (Additive & Non-Destructive)

All changes will be implemented via an additive migration (`2026_11_26_150001_add_curriculum_calendar_progression_and_retake_tables.php`):

1. **`curriculum_items` table** (Major Study Curriculum):
   - `id`, `program_id` (FK -> `programs.id`), `course_id` (FK -> `courses.id`), `course_category_id` (nullable FK -> `course_categories.id`), `curriculum_version` (string, default `'2026'`), `study_year` (tinyint `1..6`), `semester_number` (tinyint `1..6`), `semester_name` (nullable string), `credits` (decimal `4,1`, nullable), `is_required` (boolean, default `true`), `display_order` (smallint, default `1`), `status` (string, default `'active'`), timestamps.
   - Unique index on `['program_id', 'curriculum_version', 'study_year', 'semester_number', 'course_id']`.
2. **`major_academic_periods` table** (Flexible Major-Specific Academic Calendar):
   - `id`, `program_id` (FK -> `programs.id`), `academic_year_id` (FK -> `academic_years.id`), `semester_id` (nullable FK -> `semesters.id`), `study_year` (tinyint `1..6`), `semester_number` (tinyint `1..6`), `semester_name` (string), `sequence_order` (smallint), `start_date` (date), `end_date` (date), `status` (string: `'upcoming'`, `'active'`, `'completed'`, `'inactive'`), `notes` (nullable text), timestamps.
   - Unique index on `['program_id', 'academic_year_id', 'study_year', 'semester_number']`.
3. **`academic_calendar_audits` table** (Calendar Change History):
   - `id`, `major_academic_period_id` (nullable FK -> `major_academic_periods.id`), `semester_id` (nullable FK -> `semesters.id`), `program_id` (nullable FK -> `programs.id`), `previous_start_date` (nullable date), `new_start_date` (nullable date), `previous_end_date` (nullable date), `new_end_date` (nullable date), `changed_by` (nullable FK -> `users.id`), `reason` (nullable text), `created_at`, `updated_at`.
4. **Additive columns on `students`:**
   - `current_academic_year_id` (nullable FK -> `academic_years.id`)
   - `current_semester_id` (nullable FK -> `semesters.id`)
   - `current_study_year` (nullable tinyint)
   - `current_semester_number` (nullable tinyint)
   - `curriculum_version` (nullable string, default `'2026'`)
   - `progression_status` (string, default `'in_progress'`)
   - `last_progressed_at` (nullable timestamp)
5. **Additive columns on `course_enrollments`:**
   - `academic_year_id` (nullable FK -> `academic_years.id`)
   - `enrollment_type` (string, default `'regular'` — `'regular'` or `'retake'`)
   - `attempt_number` (tinyint unsigned, default `1`)
   - `original_enrollment_id` (nullable FK -> `course_enrollments.id`)
   - `retake_status` (nullable string — `'not_required'`, `'required'`, `'enrolled'`, `'completed'`, `'failed'`)
   - `finalized_at` (nullable timestamp)
6. **Additive columns on `grades`:**
   - `attempt_number` (tinyint unsigned, default `1`)
   - `is_retake` (boolean, default `false`)
   - `original_grade_id` (nullable FK -> `grades.id`)
   - `finalized_at` (nullable timestamp)
7. **Additive columns on `gpa_records`:**
   - `academic_year_id` (nullable FK -> `academic_years.id`)
   - `study_year` (nullable tinyint unsigned)
   - `semester_number` (nullable tinyint unsigned)
   - `semester_average` (nullable decimal `5,2`)
   - `is_official` (boolean, default `true`)
8. **`student_progression_histories` table** (Progression Audit Log):
   - `id`, `student_id` (FK -> `students.id`), `program_id` (nullable FK -> `programs.id`), `from_academic_year_id` (nullable), `from_semester_id` (nullable), `from_study_year` (nullable tinyint), `from_semester_number` (nullable tinyint), `to_academic_year_id` (nullable), `to_semester_id` (nullable), `to_study_year` (nullable tinyint), `to_semester_number` (nullable tinyint), `passed_courses_count` (smallint default 0), `failed_courses_count` (smallint default 0), `enrolled_next_courses_count` (smallint default 0), `status` (string: `'progressed'`, `'pending_configuration'`, `'completed_program'`), `trigger_source` (string: `'grade_finalized'`, `'scheduled_reconciliation'`, `'admin_manual'`), `notes` (nullable text), `progressed_at` (timestamp), timestamps.
   - Unique idempotency key on `['student_id', 'from_semester_id', 'from_study_year', 'from_semester_number', 'status']` (or checked transactionally with row lock).
9. **`student_course_retakes` table** (Retake Eligibility & Attempt Ledger):
   - `id`, `student_id` (FK -> `students.id`), `course_id` (FK -> `courses.id`), `program_id` (nullable FK -> `programs.id`), `failed_enrollment_id` (nullable FK -> `course_enrollments.id`), `failed_grade_id` (nullable FK -> `grades.id`), `failed_semester_id` (nullable FK -> `semesters.id`), `failed_study_year` (nullable tinyint), `original_score` (nullable decimal `5,2`), `original_letter_grade` (nullable string), `retake_enrollment_id` (nullable FK -> `course_enrollments.id`), `retake_offering_id` (nullable FK -> `course_offerings.id`), `retake_semester_id` (nullable FK -> `semesters.id`), `retake_score` (nullable decimal `5,2`), `retake_letter_grade` (nullable string), `attempt_number` (tinyint default 1), `status` (string: `'required'`, `'enrolled'`, `'passed'`, `'failed'`), timestamps.

---

## 8. Required Backend Changes

1. **Models:**
   - Create `CurriculumItem`, `MajorAcademicPeriod`, `AcademicCalendarAudit`, `StudentProgressionHistory`, `StudentCourseRetake`.
   - Extend `Student`, `CourseEnrollment`, `Grade`, `GpaRecord`, `Program`, `Semester` with new fillable fields and relationships.
2. **Services:**
   - **`GpaCalculationService`**:
     - Single source of truth for score-to-letter-grade mapping (`A=4.0 [90-100]`, `A-=3.7 [85-89.99]`, `B+=3.3 [80-84.99]`, `B=3.0 [75-79.99]`, `B-=2.7 [70-74.99]`, `C+=2.3 [65-69.99]`, `C=2.0 [60-64.99]`, `D=1.0 [50-59.99]`, `F=0.0 [<50]`).
     - Calculates Semester Average ($\frac{\sum \text{score}_i}{N}$) and credit-weighted Semester GPA ($\frac{\sum (\text{grade\_point}_i \times \text{credit}_i)}{\sum \text{credit}_i}$) strictly from officially finalized grades (`status` in `['finalized', 'official', 'published']`).
     - Never treats `N/A` or pending grades as zero; marks semester GPA as official/complete only when all required enrolled courses in that semester have finalized grades (or returns `is_complete = false` and `semester_gpa = null` when incomplete, while preserving individual finalized course scores).
     - Preserves both original and retake attempts in history; configurable retake policy (`config('slas.gpa_retake_policy', 'preserve_both')`).
   - **`AcademicProgressionService`**:
     - Evaluates an individual student inside a `DB::transaction()` with `Student::whereKey($studentId)->lockForUpdate()`.
     - Checks all active/required `CourseEnrollment` rows in the student's current semester.
     - Verify every required enrollment has an officially finalized `Grade` (`status` in `['finalized', 'official', 'published']` and `total_score !== null`).
     - Any failed course (`letter_grade === 'F'` or `total_score < 50`) is automatically recorded in `student_course_retakes` (`status = 'required'`) and `course_enrollments.retake_status = 'required'`, **without blocking progression**.
     - Determines the next `(study_year, semester_number)` from the student's Major curriculum sequence (`curriculum_items`) or `major_academic_periods` sequence—never hardcoding "2 semesters = 1 academic year".
     - Updates student's current period (`year_level`, `current_study_year`, `current_semester_number`, `current_semester_id`, `current_academic_year_id`, `progression_status`, `last_progressed_at`), creates next-semester `CourseEnrollment` rows if matching `CourseOffering` rows exist (or logs `pending_configuration` if offerings are not yet opened), and records `StudentProgressionHistory`.
   - **`RetakeManagementService`**:
     - Lists a student's failed courses requiring retake, discovers available junior/cross-cohort `CourseOffering` instances teaching that `course_id`, and enrolls the student in the junior `CourseOffering` with `enrollment_type = 'retake'`, `attempt_number = $prevAttempt + 1`, `original_enrollment_id`, preserving the original failed enrollment and grade intact.
3. **Automatic Triggers & Scheduled Fallback:**
   - Hook `Grade::saved` model observer / event so whenever a `Grade` transitions to or is saved with `status` in `['finalized', 'official', 'published']`, `GpaCalculationService::recalculateSemesterGpa()` and `AcademicProgressionService::evaluateAndProgressStudent()` run immediately for that individual student (even before the scheduled semester end date).
   - Register artisan command `slas:reconcile-student-progression` and schedule it in `routes/console.php` (`Schedule::command('slas:reconcile-student-progression')->hourly()`).
4. **Controllers & API Endpoints:**
   - `CurriculumController`: CRUD and query by Major (`GET/POST/PUT/DELETE /api/curricula`, `GET /api/programs/{program}/curriculum`, `GET /api/majors/{program}/curriculum`).
   - `AcademicCalendarController`: CRUD for Major academic periods and date updates with audit logging (`GET/POST/PUT/DELETE /api/academic-calendar/periods`, `GET /api/academic-calendar/audits`).
   - `StudentAcademicHistoryController`:
     - `GET /api/student/gpa-overview` & `GET /api/student/module/gpa-overview` (Current semester GPA, current semester courses, scores, grades, semester average, academic profile).
     - `GET /api/student/gpa-history` & `GET /api/student/module/gpa-history` (Complete Year 1–Year 4 GPA Academic History including completed years, current year, future planned curriculum courses with `N/A`, and retake attempts).
     - `GET /api/student/progression-status` & `GET /api/student/retakes` & `POST /api/student/retakes/enroll`.

---

## 9. Required Android Changes

1. **Dashboard GPA Card (`StudentDashboardFragment.java` & `DashboardResponse.java`):**
   - Bind `cardGpa` to the student's current semester GPA (`gpa_summary.current_semester_gpa` / `semester_gpa`), displaying `"N/A"` or `"Unavailable"` when not yet finalized.
   - Tapping `cardGpa` opens `GradesOverviewActivity` (GPA Detail screen for the current semester).
2. **Current Semester GPA Detail Screen (`GradesOverviewActivity.java` & `activity_grades_overview.xml`):**
   - Display current academic context header (`Academic Year`, `Study Year`, `Semester`), the current semester's courses table/cards (`Course Name`, `Course Score`, `Grade`, attempt badge if retake), footer/summary (`Semester Average`, `Semester GPA`), and a prominent **"View All"** button in the header/card that opens the full Year 1–Year 4 GPA Academic History screen (`GpaProgressActivity`).
3. **GPA Academic History – View All Screen (`GpaProgressActivity.java` & `activity_gpa_progress.xml`):**
   - Implement the 4 Year selection tabs (**Year 1**, **Year 2**, **Year 3**, **Year 4**) always visible and selectable.
   - For the selected Study Year, display all configured semesters for the student's Major.
   - For each semester card, render:
     - Semester title & state badge (`Completed`, `Current`, `Upcoming / Planned`)
     - Table of courses (`Course Name`, `Score`, `Grade`, plus `[Retake #]` or `[Original - Failed]` badge when applicable)
     - For future planned curriculum courses: display actual planned course name with `Score: N/A`, `Grade: N/A`, `Semester Average: N/A`, `Semester GPA: N/A`.
     - Semester footer row showing **Semester Average** and **Semester GPA**.

---

## 10. Risks & Backward-Compatibility Considerations

- **Web Frontend Protection:** No Blade view (`resources/views/**`), web route (`routes/web.php`), or web asset (`public/**`, `resources/js/**`, `resources/css/**`) will be modified.
- **API Contract Preservation:** Existing JSON keys in `/api/dashboard/student`, `/api/student/module/dashboard`, `/api/student/module/grades`, and `/api/student/module/academic-journey` will be preserved; new fields will be added additively.
- **Database Preservation:** All migrations are strictly additive (`Schema::create` / `Schema::table` with nullable columns). A full `mysqldump` backup is created prior to running migrations.
