# Student Learning Analytics System (SLAS)
## Student Module API Specification
**Specification Version:** 1.0.0
**Phase:** Phase 0 – Project Audit & Architecture Mapping

---

## 1. Authentication & Security Architecture

### 1.1 Sanctum Bearer Authentication
All endpoints require a valid Sanctum bearer token in the `Authorization` header.

### 1.2 Student Profile Resolution
1. User must possess the `student` role.
2. Authenticated user's student record is resolved via `User::student`.
3. If no active student profile exists, return `404 Not Found`.
4. All database queries must be strictly scoped to the resolved `student_id`. No endpoint will accept `student_id` as a request parameter for data retrieval.

---

## 2. API Endpoints (Prefix: `/api/student/module/` to avoid conflicts with legacy endpoints)

### 2.1 GET `/api/student/module/dashboard`
Fetches the student dashboard summary.

**Response `200 OK`:**
```json
{
  "success": true,
  "data": {
    "profile": {
      "name": "John Doe",
      "student_code": "STU001",
      "program": "BSCS",
      "cohort": "Generation 10"
    },
    "current_period": {
      "academic_year": "2026-2027",
      "study_year": 2,
      "semester": "Semester 1"
    },
    "metrics": {
      "enrolled_courses": 5,
      "overall_attendance_rate": 88.5,
      "pending_assignments": 2,
      "cumulative_gpa": 3.4
    }
  }
}
```

### 2.2 GET `/api/student/module/academic-journey`
Returns the 4-year timeline (Year 1 to Year 4) with semesters and courses.

### 2.3 GET `/api/student/module/courses`
Lists enrolled courses, filterable by `academic_year_id`, `study_year`, and `semester_id`.

### 2.4 GET `/api/student/module/courses/{course_offering_id}`
Details of a specific course offering, including teacher info and overall progress.

### 2.5 GET `/api/student/module/attendance`
Attendance summary and history. Can filter by `semester_id` or `course_offering_id`.

### 2.6 GET `/api/student/module/grades`
Academic results and GPA overview. Can filter by `semester_id`.

### 2.7 GET `/api/student/module/grades/courses/{course_offering_id}`
Assessment breakdown (attendance, homework, midterm, final, etc.) for a specific course.

### 2.8 GET `/api/student/module/analytics`
Personal learning analytics (attendance rate, assignment completion, quiz performance, GPA trend).

### 2.9 GET `/api/student/module/recommendations`
Smart recommendations based on low attendance, upcoming deadlines, or poor quiz scores.
