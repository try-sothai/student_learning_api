# SLAS — Complete System Audit & Academic Structure Rebuild Plan

Generated: 2026-11-21
Scope: Laravel Backend (`D:\Development\Projects\student_learning_api`), MySQL Database (`student_learning_analytics`), Android Client (`D:\Development\Projects\studentlearnanalytics`).

---

## 1. Existing Academic & Organizational Hierarchy

### Current Schema Observed
1. **`academic_years` (5 rows)**: `id, name, start_date, end_date, status`
2. **`semesters` (10 rows)**: `id, academic_year_id, name, semester_number, start_date, end_date, status` (`academic_year_id -> academic_years.id [ON DELETE RESTRICT]`)
3. **`departments` (4 rows)**: `id, name, code, description, status`
4. **`programs` (8 rows)**: `id, department_id, code, name, duration_years, status` (`department_id -> departments.id [ON DELETE RESTRICT]`)
5. **`courses` (11 rows)**: `id, department_id, teacher_id, academic_year, semester, day_of_week, start_time, end_time, room, name, code, course_name, course_code, department, description, credits, credit, status, deleted_at`
6. **`classes` (22 rows)**: `id, department_id, academic_year_id, course_id, teacher_id, name, code, year_level, day_of_week, start_time, end_time, room, status`
7. **`course_offerings` (83 rows)**: `id, course_id, class_id, semester_id, academic_year_id, program_id, study_year, section_name, status, deleted_at`
8. **`course_offering_teachers` (83 rows)**: `id, course_offering_id, teacher_id, role, status, assigned_at, ended_at`
9. **`class_teacher` (5 rows)**: `id, class_id, teacher_id, course_id`

### Structural Gaps Identified
- **Missing Building & Floor hierarchy**: There are NO `buildings` or `floors` tables (`office_locations` is only for teacher office strings). `classes` currently uses a plain text `room` column instead of referencing `building_id` and `floor_id`, and lacks a `capacity` field.
- **Room vs Class semantic mismatch**: In SLAS target operations, the physical room (`Class A`) **is** the `ClassRoom` inside a `Building -> Floor -> Class` tree (e.g., `Building A -> Floor 1 -> Class A`). A separate fictional `"Room 101"` layer creates duplicate/confusing classroom data.
- **Missing normalized `class_schedules` table**: Schedule fields (`day_of_week`, `start_time`, `end_time`) are flat single-slot columns on `classes` / `courses`. One physical Class (`Class A`) cannot hold multiple non-overlapping weekly sessions across different courses (e.g., Data Science Mon 6–9 PM, Android App Tue 6–9 PM, Network Admin Thu 6–9 PM) with DB-level or API-level overlap conflict checks.
- **Legacy `users` orphan rows**: Live DB audit detected `1` `users(role='teacher')` row without a matching `teachers` row, `3` `users(role='student')` rows without matching `students` rows, `1` `assignments` row with `course_offering_id = NULL`, and `users(role='admin') = 2` vs `admins = 1`.

---

## 2. Database Foreign Key & Dependency Map for Teacher & Student Cleanup

Live database row counts before cleanup:
- Core preserved structure:
  - `admins`: 1 (`users` admin count: 2)
  - `departments`: 4
  - `programs`: 8
  - `academic_years`: 5
  - `semesters`: 10
  - `courses`: 11 (must `SET teacher_id = NULL`, preserve courses)
  - `classes`: 22 (must `SET teacher_id = NULL`, preserve classes)
  - `course_offerings`: 83 (links courses/classes/semesters/academic years — preserve `course_offerings` structure or allow admin reassignment cleanly without orphaned teachers)
- Accounts to delete in Phase 3:
  - `teachers`: 9 (`TCH001`, `TCH002`, `TCH003`, `TCH004`, `TCH0010`, `TCH123`, `TCH0SANG`, `TCH0NGIM`, `TCH0HUR`) + `10` users with `role = 'teacher'`
  - `students`: 35 + `38` users with `role = 'student'`

### Full Foreign Key Dependency Graph
Because several FKs use `ON DELETE RESTRICT` rather than `CASCADE`, deleting `teachers` or `students` directly fails unless dependents are removed in child-to-parent order:

1. **Assessment & Exam Child Tables (Student & Teacher dependents)**:
   - `assignment_attempts` (0) -> `assignments.id`
   - `assignment_questions` (0) -> `assignments.id [CASCADE]`
   - `assignment_submissions` (627) -> `assignments.id [CASCADE]`, `students.id [CASCADE]`
   - `assignments` (97) -> `teachers.id`, `courses.id [CASCADE]`
   - `quiz_answers` (406) -> `quiz_attempts.id [CASCADE]`, `quiz_questions.id [CASCADE]`
   - `quiz_attempts` (631) -> `quizzes.id [CASCADE]`, `students.id [CASCADE]`
   - `quiz_questions` (83) -> `quizzes.id [CASCADE]`
   - `quizzes` (97) -> **`teachers.id [ON DELETE RESTRICT]`**, `courses.id [RESTRICT]`
   - `exam_results` (624) -> `exams.id [CASCADE]`, `students.id [CASCADE]`
   - `exams` (96) -> **`teachers.id [ON DELETE RESTRICT]`**, `courses.id [RESTRICT]`
2. **Attendance Tables**:
   - `attendance_details` (1534) -> `attendance.id [CASCADE]`, `students.id [CASCADE]`
   - `attendance` (161) -> `teachers.id [SET NULL]`, `students.id [SET NULL]`, `marked_by -> users.id [SET NULL]`
   - `attendances` (0) -> `students.id [CASCADE]`, `marked_by -> users.id [SET NULL]`
3. **Enrollment & Teaching Assignment Tables**:
   - `course_enrollments` (1141) -> `students.id [CASCADE]`
   - `course_student` (0) -> `students.id`
   - `course_offering_teachers` (83) -> `teachers.id [CASCADE]`
   - `class_teacher` (5) -> `teachers.id [CASCADE]`
   - Nullable references to clear: `courses.teacher_id`, `classes.teacher_id`
4. **Grades, Analytics, Predictions, Risk, Recommendations & Reviews**:
   - `grades` (400) -> `students.id [CASCADE]`
   - `gpa_records` (140) -> `students.id [CASCADE]`
   - `performance_records` (400) -> `students.id [CASCADE]`
   - `learning_analytics` (100) -> `students.id [CASCADE]`
   - `predictions` (0) -> `students.id [CASCADE]`
   - `risk_assessments` (1120) -> `students.id [CASCADE]`
   - `recommendations` (0) -> `students.id [CASCADE]`
   - `faculty_reviews` (0) -> `teachers.id [CASCADE]`
5. **Profiles, Family, Imports, Incidents, Notifications & Reports**:
   - `student_emergency_contacts` (14) -> `students.id [CASCADE]`
   - `student_family_information` (17) -> `students.id [CASCADE]`
   - `student_notes` (0) -> `students.id [CASCADE]`, `users.id [CASCADE]`
   - `student_import_rows` (75) & `student_imports` (17)
   - `incidents` (0) -> `student_id`, `reporter_id`, `assigned_to`
   - `teacher_specialization` (4) -> `teachers.id [CASCADE]`
   - `specializations` (15, optional user-created `created_by`), `office_locations` (14, `created_by`) -> preserve lookup tables or `SET NULL` on `created_by`
   - `reports` (1) -> `student_id`, `teacher_id`, `generated_by` (remove teacher/student-specific report rows)
   - `notifications` (10) -> `user_id` (delete notifications belonging to deleted teacher/student `user_id`s)
   - `personal_access_tokens` (58) -> revoke/delete tokens where `tokenable_id` belongs to deleted teacher/student `user_id`s.

---

## 3. Broken / Incomplete Android & Backend Integrations Found

1. **`ClassManagementFragment.java` (Android)**:
   - `loadScheduleData()` (lines 556–583) displays 100% hardcoded mock schedule cards (`BSIT 4-A`, `Prof. Alan Santos`, `Lab 101`). Not connected to Laravel API.
   - `loadClassroomData()` (lines 633–660) displays 100% hardcoded mock classroom rows (`Lab 101`, `Lecture Hall A`, etc.). Not connected to Laravel API.
   - `AddClassroomActivity.java` validates locally and only adds an in-memory card without persisting to MySQL.
   - `AddClassScheduleActivity.java` validates locally without calling a backend schedule API and uses free classroom strings unrelated to `Building -> Floor -> Class`.
2. **Missing Admin UI for Foundational Setup Steps**:
   - **Academic Year & Semester Creation**: `AcademicYearController` and `SemesterController` exist on the backend (`GET/POST/PUT/DELETE /academic-years`, `/semesters`), and `AdminSystemConfigActivity.java` currently hardcodes `{"AY 2024-2025", ...}` spinners with a dummy toast instead of loading/creating real Academic Years and Semesters via API!
   - **Department Creation**: `DepartmentController` exists on backend (`/departments`), but Admin Android UI has no screen/dialog to create/edit Departments.
   - **Building & Floor Management**: Neither backend tables/endpoints nor Android UI exist yet for `Building` -> `Floor` -> `Class`.
   - **Class Enrollment UI**: Verify `BulkClassEnrollmentActivity` and `ClassManagementFragment` Tab 4 work cleanly against `course_offerings` / `students.class_id` / `course_enrollments` with zero hardcoded sample students.
3. **Authentication & Empty-State Safety**:
   - `AuthController::register()` returns HTTP 403 (`Public student registration is disabled`) — already compliant; keep disabled.
   - When `Teachers = 0` and `Students = 0`, Admin dashboard (`DashboardController::adminOverview`, `AdminUserController::statistics`, `AdminReportsAnalyticsController::overview`), Teacher endpoints, and Android RecyclerViews/spinners must return `0` / empty lists gracefully without `NullPointerException`, division-by-zero, or stale dummy strings.
4. **Notification Bell / Drawer Links & Layout Clipping**:
   - Inspect card layouts (`item_admin_class*.xml`, `fragment_admin_classes.xml`) and Teacher/Student Attendance, Schedule, GPA/Grades, and Analytics screens to fix horizontal clipping/wrapping and ensure real API binding.

---

## 4. Safe Cleanup & Rebuild Plan

1. **Pre-Cleanup Backup (Phase 0B)**: Dump full MySQL `student_learning_analytics` database to `database/backups/slas_pre_cleanup_<timestamp>.sql` and verify file exists, is non-empty, and is readable.
2. **Safe Transactional Cleanup (Phase 3)**: Execute an idempotent Artisan command / SQL transaction deleting child dataset rows in FK dependency order, deleting `teachers`, `students`, and `users` (`role in ('teacher', 'student')`), nullifying `courses.teacher_id` and `classes.teacher_id`, and preserving `admins` + admin user account(s), `departments`, `programs`, `academic_years`, `semesters`, `courses`, and `classes`. Do NOT create any new teachers or students.
3. **Academic Structure & Schedule Migration (Phase 4–6)**: Add `buildings`, `floors`, `classes.(building_id, floor_id, capacity)`, and `class_schedules` with overlap conflict validation (`same class` OR `same teacher` on overlapping day/time).
4. **Admin/Teacher/Student Android & API Integration (Phase 8–15)**: Wire all missing Admin CRUD flows (Academic Years, Semesters, Departments, Buildings, Floors, Classes, Course Offerings, Assign Teacher, Class Schedules, Students, Class Enrollment), remove all hardcoded mock arrays in `ClassManagementFragment` and `AdminSystemConfigActivity`, verify Student & Teacher schedule/attendance/grading/analytics endpoints, fix layout clipping, and run full backend + Android tests and device verification.
