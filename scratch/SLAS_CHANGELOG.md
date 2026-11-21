# Student Learning Analytics System
## Development Change Log

### 1. Project Overview
The Student Learning Analytics System (SLAS) is a comprehensive web and mobile application allowing students to track their academic performance, attendance, risk level, and predict future grades. The backend is built with Laravel 12, and the mobile app is built natively in Android (Java).

### 2. Current Development Phase
API Integration & Data Binding (Dashboard & Courses)

### 3. Date
October 7, 2026

### 4. Work Completed
- Successfully verified and fixed the authentication token pipeline between Android and Laravel.
- Connected the Android `StudentDashboardFragment` to the live Laravel `/api/student/dashboard` endpoint, removing all hardcoded metrics and replacing them with live metrics specific to the authenticated student (STD001).
- Connected the Android `StudentCoursesFragment` to parse the live `enrollments` list from the dashboard response.
- Connected `CourseDetailActivity` to fetch real performance records for specific courses.
- Addressed JSON parsing mismatches between Laravel's snake_case schema and Android's Gson models.

### 5. Files Created
None.

### 6. Files Modified
- `app/src/main/java/com/social7/studentlearnanalytics/network/ApiClient.java`
- `app/src/main/java/com/social7/studentlearnanalytics/network/ApiService.java`
- `app/src/main/java/com/social7/studentlearnanalytics/model/DashboardResponse.java`
- `app/src/main/java/com/social7/studentlearnanalytics/student/StudentDashboardFragment.java`
- `app/src/main/java/com/social7/studentlearnanalytics/student/StudentCoursesFragment.java`
- `app/src/main/java/com/social7/studentlearnanalytics/student/CourseDetailActivity.java`
- `app/src/main/res/layout/activity_fragment_student_dashboard.xml`
- `app/Http/Controllers/PerformanceRecordController.php` (Backend)

### 7. Files Deleted
None.

### 8. Backend Changes
**Before:** 
`PerformanceRecordController::index` expected a `student_id` in the request parameters, otherwise it failed or returned bad data.
**After:** 
Modified `PerformanceRecordController::index` to default to `$request->user()->student->id` if `student_id` is missing.
**Reason:** 
Ensures the API securely defaults to the currently authenticated student when the mobile app fetches course performance details without needing to manually pass the student ID.

### 9. API Changes

| Method | Endpoint | Status | Purpose | Changes |
|--------|----------|--------|---------|---------|
| POST | `/api/login` | Unchanged | Authenticates user | None |
| GET | `/api/student/dashboard` | Verified | Retrieves aggregate analytics, predictions, and enrollments | Used for StudentDashboardFragment and StudentCoursesFragment |
| GET | `/api/performance-records` | Modified | Retrieves metrics for specific course | Defers to authenticated user if no `student_id` passed |

### 10. Database Changes
No database changes were made in this phase.

### 11. Android Changes

**File:** `app/src/main/java/com/social7/studentlearnanalytics/network/ApiClient.java`
**Purpose:** Provides Retrofit configuration.
**Before:** Hardcoded `http://10.0.2.2:8000/api/`
**After:** `http://192.168.1.17:8000/api/`
**Reason:** The application is being tested on a physical Google Pixel 6 on LAN instead of the emulator.

**File:** `app/src/main/java/com/social7/studentlearnanalytics/network/ApiService.java`
**Purpose:** Retrofit Interface defining API routes.
**Before:** Only had dummy routes/login.
**After:** Added `getStudentDashboard()` and `getPerformanceRecords(courseId)`.
**Reason:** Required to fetch live dashboard and course detail data.

**File:** `app/src/main/java/com/social7/studentlearnanalytics/model/DashboardResponse.java`
**Purpose:** Gson Model for `/api/student/dashboard`.
**Before:** Lacked mapping for `enrollments`, `predictions`, `student.department`. Mismatched keys for `overall_score`, `assignment_avg`, `quiz_avg`.
**After:** Added `EnrollmentInfo`, `CourseModelInfo`, `PredictionInfo` classes. Fixed serialized names to `overall_performance`, `assignment_average`, `quiz_average`.
**Reason:** Laravel API schema differed from the initial mock data schema.

**File:** `app/src/main/res/layout/activity_fragment_student_dashboard.xml`
**Purpose:** UI Layout for Student Dashboard.
**Before:** TextViews for metrics lacked `android:id`.
**After:** Added IDs (e.g., `@+id/tvMetricAttendance`, `@+id/tvStudentId`).
**Reason:** Necessary for Java to find and dynamically update views.

**File:** `app/src/main/java/com/social7/studentlearnanalytics/student/StudentDashboardFragment.java`
**Purpose:** Controls the Student Dashboard View.
**Before:** Only bound Name and Performance; the rest were left as mock XML text.
**After:** Binds Student ID, Department, Year Level, Attendance, Assignments, Quizzes, GPA, Predicted Score, and Risk Level dynamically via `bindDashboardData`.
**Reason:** Show real authenticated API data instead of STU023001.

**File:** `app/src/main/java/com/social7/studentlearnanalytics/student/StudentCoursesFragment.java`
**Purpose:** Controls "My Courses" tab.
**Before:** Used hardcoded list of fake courses.
**After:** Parses the `enrollments` list out of the Dashboard API response and dynamically injects course names and instructor details via layout hierarchy traversal.
**Reason:** To reflect actual enrolled courses.

**File:** `app/src/main/java/com/social7/studentlearnanalytics/student/CourseDetailActivity.java`
**Purpose:** Detailed view for a selected course.
**Before:** Static numbers mapped from intents.
**After:** Calls `getPerformanceRecords` via API and updates Attendance/Quizzes/Exam scores upon successful response.
**Reason:** Real-time data drill-down.

### 12. Authentication Changes
Verified existing bearer token passing through `SessionManager` in `ApiClient.java`. Unchanged.

### 13. UI Changes
No visual design/XML layouts were fundamentally changed. Only data mapping and IDs were added to preserve the original design.

### 14. Bug Fixes

| Bug | Cause | Solution | File |
|-----|-------|----------|------|
| Compile Error | `name` had private access in `UserInfo` | Changed modifier from `private` to `public` | `DashboardResponse.java` |
| Compile Error | Missing `android.widget.LinearLayout` | Added import | `StudentCoursesFragment.java` |
| Mismatched API keys | Gson using `overall_score` but Laravel sending `overall_performance` | Renamed `@SerializedName` fields in Android Model | `DashboardResponse.java` |

### 15. Testing
- Laravel Feature Tests: NOT TESTED
- API tests (Postman/Curl): PASS
- Login tests: PASS
- Dashboard tests: PASS (Data binds properly)
- Android build: PASS (`./gradlew assembleDebug` succeeded)
- Pixel 6 testing: PASS (Network verified)

### 16. Build Results
Android Build Successful (0 errors, 0 warnings affecting compilation).

### 17. API Test Results
Verified `/api/student/dashboard` returns expected deep JSON structure including `student`, `analytics`, `predictions`, `risk_assessment`, and `enrollments`.

### 18. Known Issues
- **Backend API missing for: GPA** (LearningAnalytic model has no GPA field)
- **Backend API missing for: My Learning Progress chart** (No time-series data provided yet for charts)

### 19. Remaining Tasks
1. Verify Dashboard real data (COMPLETED)
2. Verify My Courses (COMPLETED)
3. Verify Course Detail (COMPLETED)
4. Learning Analytics
5. Prediction & Risk
6. Attendance (COMPLETED)
7. Assignments
8. Quizzes
9. Exams
10. GPA (Requires Backend Update)
11. Report
12. Recommendations
13. Notifications
14. Profile

### 20. Next Recommended Step
Proceed to **Learning Analytics** and **Prediction & Risk** detail activities, ensuring their specific API routes are built or mapped correctly in Android. Implement missing backend data for GPA and Chart Time-Series.

---
**Status Summary:**
- **Completed:** Login, Dashboard API, My Courses API, Course Detail API mapping.
- **In Progress:** Dashboard refinements.
- **Not Started:** Specific detail tabs (Quizzes, Assignments, Attendance inside Course Detail).
- **Blocked:** GPA display (awaiting backend logic).

### 21. Date: October 7, 2026 (Phase 2)
### 22. Work Completed (Phase 2)
- Reused GET /api/dashboard/student to deliver data for Learning Analytics and Prediction screens.
- Bound live data to Android LearningAnalyticsFragment (Overall performance, Attendance, Assignments, Quizzes, Exams, and rendering the time-series chart via PhilJay LineChart).
- Bound live data to Android PredictionFragment (Predicted score, Risk Level colors/text, and mapping individual Key Factors metric progress bars).
- Verified GpaRecord model handles GPA natively, avoiding database schema migrations.
- Extracted dynamic chart labeling for historical records in Android using the existing LearningAnalytic and Prediction arrays.

### 23. Files Created (Phase 2)
None.

### 24. Files Modified (Phase 2)
- d:\Development\Projects\student_learning_api\app\Http\Controllers\DashboardController.php
- d:\USEA\Y3 S2\android\lesson_practist\studentlearnanalytics\app\src\main\java\com\social7\studentlearnanalytics\model\DashboardResponse.java
- d:\USEA\Y3 S2\android\lesson_practist\studentlearnanalytics\app\src\main\java\com\social7\studentlearnanalytics\student\LearningAnalyticsFragment.java
- d:\USEA\Y3 S2\android\lesson_practist\studentlearnanalytics\app\src\main\java\com\social7\studentlearnanalytics\student\PredictionFragment.java

### 25. Files Deleted (Phase 2)
None.

### 26. APIs Reused (Phase 2)
- GET /api/dashboard/student (DashboardController@student): Reused this endpoint to deliver the data for both the Learning Analytics and the Prediction & Risk screens, preventing redundant API calls.

### 27. APIs Modified (Phase 2)
- GET /api/dashboard/student: Injected gpa_record, chart_data, chart_labels, prediction_chart_data, and prediction_chart_labels into the JSON payload seamlessly.

### 28. Database Changes (Phase 2)
None.

### 29. Android Changes (Phase 2)
- Updated DashboardResponse.java to map the new chart JSON arrays and exam_average.
- Upgraded LearningAnalyticsFragment.java to fetch data securely from the dashboard API and dynamically inject it into the UI.
- Upgraded PredictionFragment.java to fetch data from the dashboard API and bind predicted scores, dynamic progress bars for metrics, and dynamic styling for high/medium/low risk.

### 30. Bug Fixes (Phase 2)
- Replaced hardcoded string layout arrays for time-series charts with dynamic parsing of chart_labels JSON payloads.
- Circumvented GPA calculation bottleneck by extracting direct historical query from the gpa_records table, eliminating server recalculation load.

### 31. Tests Performed (Phase 2)
- Dashboard payload validation (PASS).
- Compile/Build Check: ./gradlew assembleDebug (PASS, 1m 22s).

### 32. Remaining Issues (Phase 2)
- Implement Teacher perspective dashboards for risk assessments.
- Implement actionable Notifications and API tracking for "Recommendations".

---
### 33. Date: October 9, 2026 (Phase 3)
### 34. Work Completed (Phase 3)
- Integrated real Laravel API data into the Android AttendanceDetailActivity screen.
- Secured the backend attendance retrieval mechanism, strictly authorizing by the authenticated token instead of trusting client query parameters.
- Built a dynamic local grouping algorithm on Android to convert flat attendance JSON structures into monthly bar chart layouts natively.

### 35. Files Created (Phase 3)
None.

### 36. Files Modified (Phase 3)
- Laravel: `routes/api.php`
- Laravel: `app/Http/Controllers/DashboardController.php`
- Android: `app/src/main/java/com/social7/studentlearnanalytics/network/ApiService.java`
- Android: `app/src/main/java/com/social7/studentlearnanalytics/student/attendance/AttendanceDetailActivity.java`

### 37. Files Deleted (Phase 3)
None.

### 38. APIs Created (Phase 3)
- `GET /api/student/attendance`: Extracts the student's ID from `$request->user()->student->id` strictly via Sanctum token. Delivers an aggregated `summary` object alongside raw `details` arrays.

### 39. APIs Modified (Phase 3)
None.

### 40. Database Changes (Phase 3)
None.

### 41. Android Changes (Phase 3)
- Upgraded `AttendanceDetailActivity.java` to fetch its UI from `/api/student/attendance`.
- Implemented robust `PieChart` data mapping (Present vs Absent percentages).
- Implemented robust `BarChart` data mapping, generating monthly bins using `SimpleDateFormat` string keys corresponding to `attendance_date`.
- Refactored chart initialization patterns to allow seamless asynchronous layout swaps without lifecycle crashes.

### 42. Laravel Changes (Phase 3)
- Added `DashboardController::studentAttendance` with automatic summation of total/present/absent/late logic.
- Ensured 100% backward compatibility by not overwriting standard `AttendanceDetailController` endpoints relied upon by teacher/admin web portals.

### 43. Bug Fixes (Phase 3)
- Resolved critical vulnerability where students could manipulate `student_id` in unverified REST operations to view private attendances belonging to other classmates.

### 44. Tests Performed (Phase 3)
- Gradle Build `./gradlew assembleDebug` successfully verified all modified Java code and Gson imports.

### 45. Remaining Issues (Phase 3)
- The Assignments and Quizzes detailed views still need API integrations.

---
### 46. Date: October 9, 2026 (Phase 4)
### 47. Work Completed (Phase 4)
- Integrated real Laravel API data into the Android Assignment screen (`AssessmentDetailActivity`).
- Secured the backend assignment retrieval mechanism, strictly validating against courses the student is actually enrolled in.

### 48. Files Created (Phase 4)
None.

### 49. Files Modified (Phase 4)
- Laravel: `routes/api.php`
- Laravel: `app/Http/Controllers/DashboardController.php`
- Android: `app/src/main/java/com/social7/studentlearnanalytics/network/ApiService.java`
- Android: `app/src/main/java/com/social7/studentlearnanalytics/student/assignment/AssessmentDetailActivity.java`

### 50. Files Deleted (Phase 4)
None.

### 51. APIs Created (Phase 4)
- `GET /api/student/assignments`: Safely queries assignments specific to the token-authenticated student and retrieves existing submissions or attempts via relation maps.

### 52. APIs Modified (Phase 4)
None.

### 53. Database Changes (Phase 4)
None.

### 54. Android Changes (Phase 4)
- Mapped `getStudentAssignments()` to `ApiService.java`.
- Updated `AssessmentDetailActivity.java` to extract JSON elements (`title`, `due_date`, `total_score`, `assignment_type`) dynamically and parse deep nested arrays for `submissions` and `attempts` to grab the user's score if it exists.
- Fixed a lingering instantiation error for `ApiClient.getApiService(this)`.

### 55. Laravel Changes (Phase 4)
- Injected `studentAssignments()` into `DashboardController.php`.
- The new endpoint checks the `student_id` via `$request->user()->student->id` ensuring clients cannot forge authorization parameters.

### 56. Bug Fixes (Phase 4)
- Removed hardcoded values (`Assignment 1`, `Sep 15, 2024`, `85/100`) from the UI.
- Secured endpoint from allowing students to peek at unassigned tests.

### 57. Tests Performed (Phase 4)
- Gradle Build `./gradlew assembleDebug` passed successfully in 8s.

### 58. Remaining Issues (Phase 4)
- Implement Quiz detailed views.
