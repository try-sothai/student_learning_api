<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\AcademicCalendarController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AssignmentSubmissionController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendanceDetailController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClassRoomController;
use App\Http\Controllers\CourseCategoryController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\CourseEnrollmentController;
use App\Http\Controllers\CurriculumController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\ExamResultController;
use App\Http\Controllers\GpaRecordController;
use App\Http\Controllers\GradeController;
use App\Http\Controllers\LearningAnalyticController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PerformanceRecordController;
use App\Http\Controllers\PredictionController;
use App\Http\Controllers\ProgramController;
use App\Http\Controllers\QuizAnswerController;
use App\Http\Controllers\QuizAttemptController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\QuizQuestionController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RiskAssessmentController;
use App\Http\Controllers\SemesterController;
use App\Http\Controllers\StudentAcademicHistoryController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TeacherController;

/*
|--------------------------------------------------------------------------
| Public Auth Routes
|--------------------------------------------------------------------------
*/
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'loginApi']);

/*
|--------------------------------------------------------------------------
| System Resources & Catalog APIs
|--------------------------------------------------------------------------
*/
// Protected resources are registered inside the auth:sanctum group below.
// Assessment resources are NOT public: they are registered below inside the
// auth:sanctum group with role/ownership checks (see "Assessment resources").

/*
|--------------------------------------------------------------------------
| Authenticated Routes & Role-Based Access Control (RBAC)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logoutApi']);
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::post('/profile', [AuthController::class, 'updateProfile']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    Route::get('/notifications/unread-count', [NotificationController::class, 'apiUnreadCount']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'update']);
    Route::apiResource('notifications', NotificationController::class);

    Route::apiResource('departments', DepartmentController::class)->only(['index', 'show']);
    Route::apiResource('faculties', DepartmentController::class)->only(['index', 'show'])->parameters(['faculties' => 'department']);
    Route::get('departments/{department}/programs', [ProgramController::class, 'byDepartment'])->whereNumber('department');
    Route::get('faculties/{department}/majors', [ProgramController::class, 'byDepartment'])->whereNumber('department');
    Route::apiResource('programs', ProgramController::class)->only(['index', 'show']);
    Route::apiResource('majors', ProgramController::class)->only(['index', 'show'])->parameters(['majors' => 'program']);
    Route::get('programs/{program}/courses', [ProgramController::class, 'courses'])->whereNumber('program');
    Route::get('majors/{program}/courses', [ProgramController::class, 'courses'])->whereNumber('program');
    Route::get('programs/{program}/curriculum', [CurriculumController::class, 'byProgram'])->whereNumber('program');
    Route::get('majors/{program}/curriculum', [CurriculumController::class, 'byProgram'])->whereNumber('program');
    Route::apiResource('curricula', CurriculumController::class)->only(['index'])->parameters(['curricula' => 'curriculum']);
    Route::get('academic-calendar/periods', [AcademicCalendarController::class, 'index']);
    Route::get('academic-calendar/periods/{period}', [AcademicCalendarController::class, 'show'])->whereNumber('period');
    Route::get('academic-calendar/audits', [AcademicCalendarController::class, 'audits']);
    Route::apiResource('course-categories', CourseCategoryController::class)->only(['index', 'show'])->parameters(['course-categories' => 'courseCategory']);
    Route::apiResource('academic-years', AcademicYearController::class)->only(['index', 'show']);
    Route::apiResource('semesters', SemesterController::class)->only(['index', 'show']);
    Route::apiResource('classes', ClassRoomController::class)->only(['index', 'show'])->parameters(['classes' => 'classRoom']);
    Route::apiResource('buildings', \App\Http\Controllers\BuildingController::class)->only(['index', 'show']);
    Route::apiResource('floors', \App\Http\Controllers\FloorController::class)->only(['index', 'show']);
    Route::apiResource('class-schedules', \App\Http\Controllers\ClassScheduleController::class)->only(['index']);

    Route::apiResource('students', StudentController::class)->only(['index', 'show']);
    Route::apiResource('teachers', TeacherController::class)->only(['index', 'show']);

    Route::apiResource('courses', CourseController::class)->only(['index', 'show']);
    Route::apiResource('course-enrollments', CourseEnrollmentController::class)->only(['index', 'show']);

    Route::apiResource('attendance', AttendanceController::class)->only(['index', 'show']);
    Route::apiResource('attendance-details', AttendanceDetailController::class)->only(['index', 'show']);
    Route::apiResource('reports', ReportController::class)->only(['index', 'show']);

    // Student Endpoints (enforces student profile presence with 404 in controller)
    Route::get('/dashboard/student', [DashboardController::class, 'student']);
    Route::get('/student/attendance', [DashboardController::class, 'studentAttendance']);
    Route::get('/student/assignments', [DashboardController::class, 'studentAssignments']);
    Route::get('/student/quizzes', [DashboardController::class, 'studentQuizzes']);
    Route::get('/student/exams', [DashboardController::class, 'studentExams']);
    Route::get('/student/gpa-overview', [StudentAcademicHistoryController::class, 'gpaOverview']);
    Route::get('/student/gpa-history', [StudentAcademicHistoryController::class, 'gpaHistory']);
    Route::get('/student/progression-status', [StudentAcademicHistoryController::class, 'progressionStatus']);
    Route::get('/student/retakes', [StudentAcademicHistoryController::class, 'retakes']);
    Route::post('/student/retakes/enroll', [StudentAcademicHistoryController::class, 'enrollRetake']);

    // Assessment resources. Reads are scoped per role inside the controllers
    // (student: enrolled offerings only, teacher: own offerings only, admin: all).
    Route::apiResource('assignments', AssignmentController::class)->only(['index', 'show']);
    Route::apiResource('quizzes', QuizController::class)->only(['index', 'show']);
    Route::apiResource('exams', ExamController::class)->only(['index', 'show']);
    Route::apiResource('exam-results', ExamResultController::class)->only(['index', 'show']);
    Route::apiResource('assignment-submissions', AssignmentSubmissionController::class)->only(['index', 'show', 'store']);

    // Student quiz flow (identity from token; one attempt holds all answers).
    Route::middleware('role:student')->prefix('student')->group(function () {
        Route::get('/quizzes/{id}', [\App\Http\Controllers\StudentQuizController::class, 'show']);
        Route::post('/quizzes/{id}/start', [\App\Http\Controllers\StudentQuizController::class, 'start']);
        Route::put('/quiz-attempts/{attemptId}/answers', [\App\Http\Controllers\StudentQuizController::class, 'saveAnswers']);
        Route::post('/quiz-attempts/{attemptId}/submit', [\App\Http\Controllers\StudentQuizController::class, 'submit']);
        Route::get('/quiz-attempts/{attemptId}/review', [\App\Http\Controllers\StudentQuizController::class, 'review']);
        Route::get('/submissions', [AssignmentSubmissionController::class, 'mySubmissions']);
        Route::get('/submissions/{assignmentSubmission}', [AssignmentSubmissionController::class, 'showStudentSubmission'])->whereNumber('assignmentSubmission');
        Route::get('/submissions/{assignmentSubmission}/file', [AssignmentSubmissionController::class, 'streamMySubmissionFile'])->whereNumber('assignmentSubmission');

        // Universal Reopen, Retake & Submission History (Student self-scoped)
        $reopenCtrl = \App\Http\Controllers\AssessmentReopenController::class;
        Route::get('/assessments/{assessmentType}/{assessmentId}/reopen-status', [$reopenCtrl, 'studentReopenStatus'])->whereNumber('assessmentId');
        Route::get('/assessments/{assessmentType}/{assessmentId}/submission-history', [$reopenCtrl, 'studentSubmissionHistory'])->whereNumber('assessmentId');
        Route::get('/assessments/quiz/{assessmentId}/attempt-history', [$reopenCtrl, 'studentQuizAttemptHistory'])->whereNumber('assessmentId');
    });

    // Raw CRUD / grading tables: administrators only. Teachers use /teacher/* (offering-scoped).
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('assignments', AssignmentController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('quizzes', QuizController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('exams', ExamController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('assignment-submissions', AssignmentSubmissionController::class)->only(['update', 'destroy']);
        Route::apiResource('quiz-questions', QuizQuestionController::class);
        Route::apiResource('quiz-attempts', QuizAttemptController::class);
        Route::apiResource('quiz-answers', QuizAnswerController::class);
        Route::apiResource('exam-results', ExamResultController::class)->only(['store', 'update', 'destroy']);
    });
    Route::get('/student/my-analytics', [LearningAnalyticController::class, 'myAnalytics']);

    // New Student Module API (Phase 2-9)
    Route::prefix('student/module')->middleware('role:student,admin')->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\StudentModuleController::class, 'dashboard']);
        Route::get('/academic-journey', [\App\Http\Controllers\StudentModuleController::class, 'academicJourney']);
        Route::get('/courses', [\App\Http\Controllers\StudentModuleController::class, 'courses']);
        Route::get('/courses/{id}', [\App\Http\Controllers\StudentModuleController::class, 'courseDetail']);
        Route::get('/attendance', [\App\Http\Controllers\StudentModuleController::class, 'attendanceOverview']);
        Route::get('/attendance/courses/{id}', [\App\Http\Controllers\StudentModuleController::class, 'attendanceCourse']);
        Route::get('/grades', [\App\Http\Controllers\StudentModuleController::class, 'gradesOverview']);
        Route::get('/grades/courses/{id}', [\App\Http\Controllers\StudentModuleController::class, 'gradesCourse']);
        Route::get('/gpa-overview', [StudentAcademicHistoryController::class, 'gpaOverview']);
        Route::get('/gpa-history', [StudentAcademicHistoryController::class, 'gpaHistory']);
        Route::get('/progression-status', [StudentAcademicHistoryController::class, 'progressionStatus']);
        Route::get('/retakes', [StudentAcademicHistoryController::class, 'retakes']);
        Route::post('/retakes/enroll', [StudentAcademicHistoryController::class, 'enrollRetake']);
        Route::get('/analytics', [\App\Http\Controllers\StudentModuleController::class, 'analytics']);
        Route::get('/recommendations', [\App\Http\Controllers\StudentModuleController::class, 'recommendations']);
        Route::get('/predictions-risk', [\App\Http\Controllers\StudentModuleController::class, 'predictionsAndRisk']);
        Route::get('/comparisons', [\App\Http\Controllers\StudentModuleController::class, 'comparisons']);
    });

    // Teacher & Admin Role Protected Endpoints
    Route::middleware('role:teacher,admin')->group(function () {
        Route::get('/dashboard/teacher', [DashboardController::class, 'teacher']);

        Route::prefix('teacher')->group(function () {
            Route::get('/dashboard', [\App\Http\Controllers\TeacherModuleController::class, 'dashboard']);
            Route::get('/filters', [\App\Http\Controllers\TeacherModuleController::class, 'filters']);
            Route::get('/analytics', [\App\Http\Controllers\TeacherModuleController::class, 'analytics']);
            Route::get('/students', [\App\Http\Controllers\TeacherModuleController::class, 'students']);
            Route::get('/students/{studentId}', [\App\Http\Controllers\TeacherModuleController::class, 'studentDetail']);
            Route::get('/attendance-overview', [\App\Http\Controllers\TeacherModuleController::class, 'attendanceOverview']);
            Route::get('/assessments-overview', [\App\Http\Controllers\TeacherModuleController::class, 'assessmentsOverview']);
            Route::get('/risk-predictions', [\App\Http\Controllers\TeacherModuleController::class, 'riskPredictions']);
            Route::post('/recommendations', [\App\Http\Controllers\TeacherModuleController::class, 'storeRecommendation']);
            Route::patch('/recommendations/{id}', [\App\Http\Controllers\TeacherModuleController::class, 'updateRecommendation']);
            Route::get('/course-offerings', [\App\Http\Controllers\TeacherModuleController::class, 'courseOfferings']);
            Route::get('/course-offerings/{id}', [\App\Http\Controllers\TeacherModuleController::class, 'courseOfferingDetail']);
            Route::get('/course-offerings/{id}/students', [\App\Http\Controllers\TeacherModuleController::class, 'courseOfferingStudents']);
            Route::get('/course-offerings/{id}/attendance', [\App\Http\Controllers\TeacherModuleController::class, 'courseOfferingAttendance']);
            Route::post('/course-offerings/{id}/attendance', [\App\Http\Controllers\TeacherModuleController::class, 'storeAttendance']);
            Route::get('/course-offerings/{id}/assignments', [\App\Http\Controllers\TeacherModuleController::class, 'courseOfferingAssignments']);
            Route::post('/course-offerings/{id}/assignments', [\App\Http\Controllers\TeacherModuleController::class, 'storeAssignment']);
            Route::put('/assignments/{assignmentId}', [\App\Http\Controllers\TeacherModuleController::class, 'updateAssignment']);
            Route::delete('/assignments/{assignmentId}', [\App\Http\Controllers\TeacherModuleController::class, 'deleteAssignment']);
            Route::get('/submissions/{submissionId}', [\App\Http\Controllers\TeacherModuleController::class, 'showSubmission'])->whereNumber('submissionId');
            Route::get('/submissions/{submissionId}/file', [\App\Http\Controllers\TeacherModuleController::class, 'streamSubmissionFile'])->whereNumber('submissionId');
            Route::post('/submissions/{submissionId}/grade', [\App\Http\Controllers\TeacherModuleController::class, 'gradeSubmission'])->whereNumber('submissionId');
            Route::get('/course-offerings/{id}/quizzes', [\App\Http\Controllers\TeacherModuleController::class, 'courseOfferingQuizzes']);
            Route::post('/course-offerings/{id}/quizzes', [\App\Http\Controllers\TeacherModuleController::class, 'storeQuiz']);

            // Teaching assignments + offering-scoped assessment management
            $ta = \App\Http\Controllers\TeacherAssessmentController::class;
            Route::get('/teaching-assignments', [$ta, 'teachingAssignments']);
            Route::get('/course-offerings/{id}/exams', [$ta, 'offeringExams']);
            Route::post('/course-offerings/{id}/exams', [$ta, 'storeExam']);
            Route::get('/exams/{examId}', [$ta, 'showExam']);
            Route::put('/exams/{examId}', [$ta, 'updateExam']);
            Route::delete('/exams/{examId}', [$ta, 'deleteExam']);
            Route::post('/exams/{examId}/scores', [$ta, 'storeExamScores']);
            Route::patch('/exams/{examId}/publish', [$ta, 'publishExam']);
            Route::post('/exams/{examId}/publish', [$ta, 'publishExam']);
            Route::patch('/exams/{examId}/unpublish', [$ta, 'unpublishExam']);
            Route::get('/quizzes/{quizId}', [$ta, 'showQuiz']);
            Route::put('/quizzes/{quizId}', [$ta, 'updateQuiz']);
            Route::delete('/quizzes/{quizId}', [$ta, 'deleteQuiz']);
            Route::get('/quizzes/{quizId}/attempts', [$ta, 'quizAttempts']);
            Route::get('/quiz-attempts/{attemptId}', [$ta, 'showQuizAttempt']);
            Route::post('/quiz-attempts/{attemptId}/grade', [$ta, 'gradeAttemptAnswers']);
            Route::post('/quizzes/{quizId}/questions', [$ta, 'storeQuestion']);
            Route::put('/quizzes/{quizId}/questions/reorder', [$ta, 'reorderQuestions']);
            Route::patch('/quizzes/{quizId}/publish', [$ta, 'publishQuiz']);
            Route::patch('/quizzes/{quizId}/unpublish', [$ta, 'unpublishQuiz']);
            Route::post('/quizzes/{quizId}/reopen', [$ta, 'reopenQuiz']);
            Route::get('/quizzes/{quizId}/students', [$ta, 'quizStudents']);
            Route::get('/quizzes/{quizId}/overrides', [$ta, 'quizOverrides']);
            Route::post('/quizzes/{quizId}/overrides', [$ta, 'storeQuizOverride']);
            Route::delete('/quizzes/{quizId}/overrides/{studentId}', [$ta, 'revokeQuizOverride']);
            Route::get('/quizzes/{quizId}/audit-logs', [$ta, 'quizAuditLogs']);
            Route::put('/quiz-questions/{questionId}', [$ta, 'updateQuestion']);
            Route::delete('/quiz-questions/{questionId}', [$ta, 'deleteQuestion']);
            Route::post('/quiz-questions/{questionId}/duplicate', [$ta, 'duplicateQuestion']);
            Route::patch('/quiz-answers/{answerId}/grade', [$ta, 'gradeAnswer']);
            Route::get('/course-offerings/{id}/scores', [\App\Http\Controllers\TeacherModuleController::class, 'courseOfferingScores']);
            Route::post('/course-offerings/{id}/scores', [\App\Http\Controllers\TeacherModuleController::class, 'storeScore']);

            // Universal Reopen, Extension, Additional Attempts, Revocation, History & Audit Logs
            $reopenCtrl = \App\Http\Controllers\AssessmentReopenController::class;
            Route::get('/assessments/audit-logs', [$reopenCtrl, 'auditLogs']);
            Route::post('/assessments/reopen', [$reopenCtrl, 'reopen']);
            Route::post('/assessments/extend-deadline', [$reopenCtrl, 'extendDeadline']);
            Route::post('/assessments/grant-attempts', [$reopenCtrl, 'grantAttempts']);
            Route::post('/assessments/revoke-reopen', [$reopenCtrl, 'revokeReopen']);
            Route::get('/assessments/{assessmentType}/{assessmentId}/reopen-status', [$reopenCtrl, 'getReopenStatus'])->whereNumber('assessmentId');
            Route::get('/assessments/{assessmentType}/{assessmentId}/submission-history', [$reopenCtrl, 'submissionHistory'])->whereNumber('assessmentId');
            Route::get('/assessments/quiz/{assessmentId}/attempt-history', [$reopenCtrl, 'quizAttemptHistory'])->whereNumber('assessmentId');
            Route::delete('/assessments/{assessmentType}/{assessmentId}/overrides/{studentId}', [$reopenCtrl, 'revokeStudentOverride'])
                ->whereNumber('assessmentId')
                ->whereNumber('studentId');
        });
    });

    Route::get('/my-schedules', [\App\Http\Controllers\ClassScheduleController::class, 'mySchedules']);

    Route::middleware('role:teacher,admin')->group(function () {
        Route::apiResource('attendance', AttendanceController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('attendance-details', AttendanceDetailController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('reports', ReportController::class)->only(['store', 'update', 'destroy']);
    });

    // Admin Only Protected Endpoints
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('departments', DepartmentController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('faculties', DepartmentController::class)->only(['store', 'update', 'destroy'])->parameters(['faculties' => 'department']);
        Route::apiResource('programs', ProgramController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('majors', ProgramController::class)->only(['store', 'update', 'destroy'])->parameters(['majors' => 'program']);
        Route::apiResource('course-categories', CourseCategoryController::class)->only(['store', 'update', 'destroy'])->parameters(['course-categories' => 'courseCategory']);
        Route::apiResource('curricula', CurriculumController::class)->only(['store', 'update', 'destroy'])->parameters(['curricula' => 'curriculum']);
        Route::post('curriculum', [CurriculumController::class, 'store']);
        Route::put('curriculum/{curriculum}', [CurriculumController::class, 'update'])->whereNumber('curriculum');
        Route::delete('curriculum/{curriculum}', [CurriculumController::class, 'destroy'])->whereNumber('curriculum');
        Route::post('academic-calendar/periods', [AcademicCalendarController::class, 'store']);
        Route::put('academic-calendar/periods/{period}', [AcademicCalendarController::class, 'update'])->whereNumber('period');
        Route::delete('academic-calendar/periods/{period}', [AcademicCalendarController::class, 'destroy'])->whereNumber('period');
        Route::post('admin/students/{student}/retakes/enroll', [StudentAcademicHistoryController::class, 'enrollRetake'])->whereNumber('student');
        Route::post('courses/{course}/majors', [CourseController::class, 'assignMajors'])->whereNumber('course');
        Route::put('courses/{course}/majors', [CourseController::class, 'assignMajors'])->whereNumber('course');
        Route::apiResource('academic-years', AcademicYearController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('semesters', SemesterController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('classes', ClassRoomController::class)->only(['store', 'update', 'destroy'])->parameters(['classes' => 'classRoom']);
        Route::apiResource('course-enrollments', CourseEnrollmentController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('buildings', \App\Http\Controllers\BuildingController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('floors', \App\Http\Controllers\FloorController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('class-schedules', \App\Http\Controllers\ClassScheduleController::class)->only(['store', 'update', 'destroy']);
        Route::get('/dashboard/admin', [DashboardController::class, 'admin']);
        Route::get('/admin/academic-structure', [ClassRoomController::class, 'academicStructure']);
        Route::get('/admin/dashboard/overview', [DashboardController::class, 'adminOverview']);
        Route::get('/admin/dashboard/user-growth', [DashboardController::class, 'adminUserGrowth']);
        Route::get('/admin/dashboard/system-status', [DashboardController::class, 'adminSystemStatus']);
        Route::get('/admin/dashboard/statistics', [DashboardController::class, 'adminStatistics']);
        Route::get('/admin/dashboard/annual-analytics', [DashboardController::class, 'annualAnalytics']);

        Route::get('/admin/analytics/enrollment', [\App\Http\Controllers\AdminAnalyticsController::class, 'enrollment']);
        Route::get('/admin/analytics/performance', [\App\Http\Controllers\AdminAnalyticsController::class, 'performance']);
        Route::get('/admin/analytics/attendance', [\App\Http\Controllers\AdminAnalyticsController::class, 'attendance']);
        Route::get('/admin/analytics/student-risk', [\App\Http\Controllers\AdminAnalyticsController::class, 'studentRisk']);
        Route::get('/admin/analytics/model-evaluation', [\App\Http\Controllers\AdminAnalyticsController::class, 'modelEvaluation']);

        Route::get('/admin/users/statistics', [\App\Http\Controllers\AdminUserController::class, 'statistics']);
        Route::get('/admin/users', [\App\Http\Controllers\AdminUserController::class, 'index']);
        Route::get('/admin/users/{user}', [\App\Http\Controllers\AdminUserController::class, 'show']);
        Route::post('/admin/users', [\App\Http\Controllers\AdminUserController::class, 'store']);
        Route::put('/admin/users/{user}', [\App\Http\Controllers\AdminUserController::class, 'update']);
        Route::patch('/admin/users/{user}/status', [\App\Http\Controllers\AdminUserController::class, 'updateStatus']);
        Route::delete('/admin/users/{user}', [\App\Http\Controllers\AdminUserController::class, 'destroy']);

        Route::get('/admin/users/{user}/notes', [\App\Http\Controllers\StudentNoteController::class, 'index']);
        Route::post('/admin/users/{user}/notes', [\App\Http\Controllers\StudentNoteController::class, 'store']);
        Route::put('/admin/users/{user}/notes/{noteId}', [\App\Http\Controllers\StudentNoteController::class, 'update']);
        Route::delete('/admin/users/{user}/notes/{noteId}', [\App\Http\Controllers\StudentNoteController::class, 'destroy']);

        Route::apiResource('admins', AdminController::class);
        Route::apiResource('students', StudentController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('teachers', TeacherController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('courses', CourseController::class)->only(['store', 'update', 'destroy']);
        Route::patch('/courses/{course}/status', [CourseController::class, 'updateStatus']);

        // Teacher profile catalogs, account repair & bulk import
        Route::get('/admin/teachers/lookups', [\App\Http\Controllers\TeacherProfileCatalogController::class, 'lookups']);
        Route::post('/admin/teachers/{teacher}/provision-account', [TeacherController::class, 'provisionAccount']);
        Route::get('/admin/teachers/import/template', [\App\Http\Controllers\TeacherImportController::class, 'template']);
        Route::post('/admin/teachers/import', [\App\Http\Controllers\TeacherImportController::class, 'import']);
        Route::post('/admin/office-locations', [\App\Http\Controllers\TeacherProfileCatalogController::class, 'storeOfficeLocation']);
        Route::post('/admin/specializations', [\App\Http\Controllers\TeacherProfileCatalogController::class, 'storeSpecialization']);

        // Student bulk import / export / university Add Student form
        Route::prefix('admin/students')->group(function () {
            $import = \App\Http\Controllers\StudentImportController::class;
            $admin = \App\Http\Controllers\StudentAdminController::class;

            Route::get('lookups', [$admin, 'lookups']);
            Route::post('/', [$admin, 'store']);
            Route::post('{student}/reset-password', [$admin, 'resetPassword']);
            Route::match(['get', 'post'], 'export', [$admin, 'export']);

            Route::get('import/template', [$import, 'template']);
            Route::get('import/template-csv', [$import, 'templateCsv']);
            Route::post('import/preview', [$import, 'preview']);
            Route::post('import/{import}/confirm', [$import, 'confirm']);
            Route::get('import/{import}/status', [$import, 'status']);
            Route::post('import/{import}/cancel', [$import, 'cancel']);

            Route::get('imports', [$import, 'index']);
            Route::get('imports/{import}', [$import, 'show']);
            Route::get('imports/{import}/rows', [$import, 'rows']);
            Route::get('imports/{import}/errors', [$import, 'errors']);
            Route::get('imports/{import}/error-report', [$import, 'errorReport']);
            Route::get('imports/{import}/successful-report', [$import, 'successReport']);
            Route::get('imports/{import}/credentials', [$import, 'credentials']);
        });

        // Reports & Analytics Module (Overview, Workload, Courses, Report Generation & Export)
        Route::prefix('admin/reports-analytics')->controller(\App\Http\Controllers\AdminReportsAnalyticsController::class)->group(function () {
            Route::get('/lookups', 'lookups');
            Route::get('/overview', 'overview');
            Route::get('/attendance', 'attendance');
            Route::get('/teachers', 'teachers');
            Route::get('/courses', 'courses');
            Route::match(['get', 'post'], '/generate', 'generate');
            Route::match(['get', 'post'], '/export', 'export');
        });

        // Class Bulk Student Enrollment & CSV/Excel Import
        Route::prefix('admin/class-enrollments')->controller(\App\Http\Controllers\ClassEnrollmentController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/lookups', 'lookups');
            Route::get('/classes/{classRoom}/students', 'studentsForClass');
            Route::post('/classes/{classRoom}/bulk-enroll', 'bulkEnroll');
            Route::get('/template', 'templateCsv');
            Route::post('/import/preview', 'importPreview');
            Route::post('/import/confirm', 'importConfirm');
        });

        // Faculty Evaluators (teachers + assignments + reviews)
        Route::prefix('faculty-evaluators')->controller(\App\Http\Controllers\FacultyEvaluatorController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/statistics', 'statistics');
            Route::get('/{teacher}', 'show');
            Route::put('/{teacher}', 'update');
            Route::get('/{teacher}/courses', 'courses');
            Route::get('/{teacher}/classes', 'classes');
            Route::post('/{teacher}/assign-courses', 'assignCourses');
            Route::post('/{teacher}/assign-classes', 'assignClasses');
            Route::delete('/{teacher}/assignments', 'removeAssignments');
            Route::get('/{teacher}/reviews', 'reviews');
            Route::post('/{teacher}/reviews', 'submitReview');
        });
    });

    // Protected Academic Records
    Route::apiResource('grades', GradeController::class);
    Route::apiResource('gpa-records', GpaRecordController::class);
    Route::apiResource('performance-records', PerformanceRecordController::class);

    // Protected Analytics, Predictions, Risk Assessments & Recommendations
    Route::apiResource('learning-analytics', LearningAnalyticController::class)->only(['index', 'show']);
    Route::apiResource('predictions', PredictionController::class)->only(['index', 'show']);
    Route::apiResource('risk-assessments', RiskAssessmentController::class)->only(['index', 'show']);
    Route::apiResource('recommendations', RecommendationController::class)->only(['index', 'show', 'update']);

    Route::middleware('role:teacher,admin')->group(function () {
        Route::apiResource('learning-analytics', LearningAnalyticController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('predictions', PredictionController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('risk-assessments', RiskAssessmentController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('recommendations', RecommendationController::class)->only(['store', 'destroy']);

        Route::get('/incidents', [\App\Http\Controllers\IncidentController::class, 'index']);
        Route::get('/incidents/{incident}', [\App\Http\Controllers\IncidentController::class, 'show']);
        Route::post('/incidents', [\App\Http\Controllers\IncidentController::class, 'store']);
    });

    Route::middleware('role:admin')->group(function () {
        Route::put('/incidents/{incident}', [\App\Http\Controllers\IncidentController::class, 'update']);
        Route::patch('/incidents/{incident}', [\App\Http\Controllers\IncidentController::class, 'update']);
        Route::delete('/incidents/{incident}', [\App\Http\Controllers\IncidentController::class, 'destroy']);
    });
});
