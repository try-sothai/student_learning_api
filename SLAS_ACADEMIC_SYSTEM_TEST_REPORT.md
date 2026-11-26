# SLAS Academic System Test Report

## 1. Test Execution Summary

| Test Suite | Runner | Tests | Assertions | Status |
|---|---|---:|---:|---|
| `MasterAcademicWorkflowTest` | PHPUnit 11.5.56 (PHP 8.4.15) | 2 | 63 | **PASS** |
| `AcademicStructureRefactoringTest` | PHPUnit 11.5.56 (PHP 8.4.15) | 2 | 45 | **PASS** |
| `ProgramManagementApiTest` | PHPUnit 11.5.56 (PHP 8.4.15) | 1 | 30 | **PASS** |
| `CourseCategoryManagementTest` | PHPUnit 11.5.56 (PHP 8.4.15) | 6 | 71 | **PASS** |
| **Total Backend Academic Suite** | **PHPUnit 11.5.56** | **11** | **209** | **100% PASS** |
| Android Unit Tests (`testDebugUnitTest`) | Gradle (`gradlew.bat`) | All Suites + `StudentGpaAcademicHistoryUnitTest` | All | **PASS** |
| Android APK Build (`assembleDebug`) | Gradle (`gradlew.bat`) | Debug APK Compilation & Resource Linking | — | **PASS** |

---

## 2. Verified Scenarios in `MasterAcademicWorkflowTest`

### 2.1 Academic Structure, Study Curriculum & Flexible Calendar (`test_academic_structure_curriculum_and_flexible_calendar_with_audits`)
- **Four Academic Entities:** Verified creation and linking of Faculty (`FOSAT`), Majors (`IT`, `DS`), Course Categories (`PROG`, `DB`), and Courses (`IT101`, `IT102`, `IT201`).
- **Study Curriculum Configuration:** Configured curriculum items across Year 1 Semester 1, Year 1 Semester 2, and Year 2 Semester 1 via `POST /api/curriculum` and verified structured `Year 1`–`Year 4` retrieval via `GET /api/programs/{program}/curriculum`.
- **Variable Semester Durations per Major:** Configured a 4-month Semester 1 schedule (`2026-09-01` to `2026-12-31`) for `IT` and a 3-month accelerated Semester 1 schedule (`2026-09-01` to `2026-11-30`) for `DS` via `POST /api/academic-calendar/periods`.
- **Flexible Date Modification & Audit Trail:** Modified `IT` Semester 1 end date to `2026-12-15` via `PUT /api/academic-calendar/periods/{id}` and verified that `academic_calendar_audits` recorded the previous end date, new end date, admin user ID, and reason, and that `GET /api/academic-calendar/audits` returned the complete audit history.

### 2.2 Automatic Individual Semester Progression, Cross-Cohort Retakes & Year 1–Year 4 GPA History (`test_automatic_individual_progression_cross_cohort_retake_and_full_gpa_history`)
- **Individual Progression Isolation:**
  - **Student B** (1 finalized grade, 1 provisional/pending grade): Confirmed `current_semester_id` remained in Semester 1 (`progressed = false`), and both `GET /api/dashboard/student` and `GET /api/student/gpa-overview` returned `null` / `"N/A"` for Semester GPA rather than a partial GPA.
  - **Student A** (all Semester 1 grades finalized: `92/A` and `84/B+`): Confirmed immediate automatic progression to Year 1 Semester 2 upon finalizing the second grade (`Grade::saved`), automatic enrollment in Year 1 Semester 2 curriculum courses (`CS103`, `CS104`), and zero premature enrollments in Year 2, Year 3, or Year 4 courses.
  - **Idempotency:** Ran `php artisan slas:reconcile-student-progression --student_id={studentA}` after progression and verified zero duplicate `student_progression_histories` or `course_enrollments` rows.
- **Failed Course & Automatic Progression (`Student C`):**
  - Finalized `CS101` with `86 (A-)` and `CS102` with `42 (F)`.
  - Verified that **Student C still progressed automatically to Year 1 Semester 2**, while `CS102` was automatically recorded in `student_course_retakes` with `status = 'required'` and `original_letter_grade = 'F'`.
- **Cross-Cohort Retake Enrollment with Junior Class:**
  - Created a junior cohort (`CS-Cohort-2027-Junior`) offering `CS102` in Semester 2.
  - Verified `GET /api/student/retakes` listed `CS102` as requiring a retake with the junior cohort offering available.
  - Enrolled Student C into the junior cohort offering via `POST /api/student/retakes/enroll` (`enrollment_type = 'retake'`, `attempt_number = 2`).
  - Finalized Student C's retake grade in Semester 2 (`78 / B`).
  - Verified that the **original failed grade (`42 / F`, `attempt_number = 1`) in Semester 1 remained completely untouched**, the retake grade was stored separately in Semester 2 (`attempt_number = 2`, `is_retake = true`), and `student_course_retakes.status` updated to `'passed'`.
- **Year 1–Year 4 GPA Academic History (`GET /api/student/gpa-history`):**
  - Verified all 4 year tabs (`Year 1`..`Year 4`) were returned.
  - Verified `Year 1 — Semester 1` (`Completed`) returned `semester_average_display = "64.00"` and `semester_gpa_display = "1.85"`.
  - Verified `Year 1 — Semester 2` (`Current`, with ungraded regular courses) returned `semester_average_display = "N/A"` and `semester_gpa_display = "N/A"`.
  - Verified `Year 2`, `Year 3`, and `Year 4` (`Future`/`Planned`) returned their planned curriculum courses (`CS201`, `CS301`, `CS401`) with `score_display = "N/A"`, `grade_display = "N/A"`, `semester_average_display = "N/A"`, and `semester_gpa_display = "N/A"`.
