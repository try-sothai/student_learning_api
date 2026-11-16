# Student Learning Analytics System (SLAS)
## Student Database Architecture & Relationship Audit
**Document Version:** 1.0.0
**Phase:** Phase 0 – Project Audit & Architecture Mapping

---

## 1. Executive Summary

This audit verifies the database relationships required to support the complete Student Module. The database has already been upgraded in the Teacher Module implementation to include `course_offerings` and `course_offering_teachers`.

The key goal for the Student Module is to enforce strict data isolation based on the authenticated student's valid enrollments, leveraging the newly introduced `course_offerings` entity where appropriate.

## 2. Student Data Ownership & Access Path

The data access path for any student is strictly hierarchical:

`User (auth:sanctum)` -> `Student Profile` -> `Course Enrollments` -> `Course Offerings` -> `Learning Records`

### 2.1 Enrollment Architecture

- **`students`**: Linked 1:1 to `users`. Contains `program_id`, `department_id`, and `class_id`.
- **`course_enrollments`**: Associates a student with a course.
  - Required fields: `student_id`, `course_id`, `semester_id`.
  - Added in Teacher phase: `course_offering_id` (nullable, but should be populated for new offerings).

### 2.2 Learning Records Architecture

Students must only see records tied to their valid `course_offering_id` and `student_id`.

- **`attendance_details`**: Links to `attendance` (which links to `course_offering_id`) and `student_id`.
- **`assignment_submissions`**: Links to `assignment` (which links to `course_offering_id`) and `student_id`.
- **`quiz_attempts`**: Links to `quiz` (which links to `course_offering_id`) and `student_id`.
- **`grades`**: Links to `course_offering_id` and `student_id`.

## 3. Strict Authorization Enforcement

1. **Never trust `student_id` passed from the Android client.**
   - The backend MUST derive `student_id` from `$request->user()->student->id`.
2. **Never expose unauthorized records.**
   - All queries must be scoped: `where('student_id', $authenticatedStudentId)`.
3. **Draft Grades Isolation.**
   - Grades with status = 'draft' or 'provisional' must be clearly marked or excluded from official GPA, depending on university rules.

## 4. Academic Journey Modeling

To construct the "My Academic Journey", we query `course_enrollments` joined with `semesters`, `academic_years`, and `courses`.
- Group by `academic_year_id` and `study_year`.
- Nested grouping by `semester_id`.
- Determine status (Completed, Current, Upcoming) by comparing `academic_years.status` and `semesters.status`.
