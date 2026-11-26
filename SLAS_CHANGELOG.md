# SLAS Changelog — Academic Structure, Curriculum, Calendar, Automatic Progression, Retakes & GPA History

## [2026-11-26] Master Academic System Enhancement

### Added (Laravel Backend & MySQL Database)
- **Migrations:**
  - `database/migrations/2026_11_26_100001_create_course_categories_and_course_program_tables.php`
  - `database/migrations/2026_11_26_150001_add_curriculum_calendar_progression_and_retake_tables.php`
- **Models:**
  - `app/Models/CourseCategory.php`
  - `app/Models/CurriculumItem.php`
  - `app/Models/MajorAcademicPeriod.php`
  - `app/Models/AcademicCalendarAudit.php`
  - `app/Models/StudentProgressionHistory.php`
  - `app/Models/StudentCourseRetake.php`
- **Services:**
  - `app/Services/GpaCalculationService.php`
  - `app/Services/AcademicProgressionService.php`
  - `app/Services/RetakeManagementService.php`
- **Controllers:**
  - `app/Http/Controllers/CourseCategoryController.php`
  - `app/Http/Controllers/CurriculumController.php`
  - `app/Http/Controllers/AcademicCalendarController.php`
  - `app/Http/Controllers/StudentAcademicHistoryController.php`
- **Console Commands:**
  - `app/Console/Commands/NormalizeAcademicStructure.php` (`slas:normalize-academic-structure`)
  - `app/Console/Commands/ReconcileStudentProgression.php` (`slas:reconcile-student-progression`)
  - `app/Console/Commands/SyncCurriculumAndCalendar.php` (`slas:sync-curriculum-and-calendar`)
- **Automated Feature Tests:**
  - `tests/Feature/AcademicStructureRefactoringTest.php`
  - `tests/Feature/ProgramManagementApiTest.php`
  - `tests/Feature/CourseCategoryManagementTest.php`
  - `tests/Feature/MasterAcademicWorkflowTest.php`

### Updated (Laravel Backend)
- `app/Models/Student.php`, `app/Models/Program.php`, `app/Models/Course.php`, `app/Models/CourseEnrollment.php`, `app/Models/Grade.php`, `app/Models/GpaRecord.php`, `app/Models/Semester.php`, `app/Models/ClassRoom.php`
- `app/Http/Controllers/DashboardController.php`, `app/Http/Controllers/StudentModuleController.php`, `app/Http/Controllers/GradeController.php`, `app/Http/Controllers/SemesterController.php`, `app/Http/Controllers/CourseController.php`, `app/Http/Controllers/ProgramController.php`, `app/Http/Controllers/DepartmentController.php`
- `routes/api.php`, `routes/console.php`

### Updated (Android Application)
- `app/src/main/java/com/social7/studentlearnanalytics/network/ApiService.java`
- `app/src/main/java/com/social7/studentlearnanalytics/model/DashboardResponse.java`
- `app/src/main/java/com/social7/studentlearnanalytics/student/grades/GradesOverviewActivity.java`
- `app/src/main/java/com/social7/studentlearnanalytics/student/grades/GradesAdapter.java`
- `app/src/main/java/com/social7/studentlearnanalytics/student/academic/GpaProgressActivity.java`
- `app/src/main/res/layout/activity_grades_overview.xml`
- `app/src/main/res/layout/activity_gpa_progress.xml`
- `app/src/test/java/com/social7/studentlearnanalytics/StudentGpaAcademicHistoryUnitTest.java`

### Untouched (Per Critical Rule 1)
- Zero modifications made during this task to any Web Frontend Blade views (`resources/views/**`), Web routes (`routes/web.php`), or Web CSS/JS assets.
