<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AssignmentSubmissionController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendanceDetailController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClassRoomController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\CourseEnrollmentController;
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
use App\Http\Controllers\QuizAnswerController;
use App\Http\Controllers\QuizAttemptController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\QuizQuestionController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RiskAssessmentController;
use App\Http\Controllers\SemesterController;
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
Route::apiResource('departments', DepartmentController::class);
Route::apiResource('academic-years', AcademicYearController::class);
Route::apiResource('semesters', SemesterController::class);
Route::apiResource('classes', ClassRoomController::class);

Route::apiResource('students', StudentController::class);
Route::apiResource('teachers', TeacherController::class);

Route::apiResource('courses', CourseController::class);
Route::apiResource('course-enrollments', CourseEnrollmentController::class);

Route::apiResource('attendance', AttendanceController::class);
Route::apiResource('attendance-details', AttendanceDetailController::class);

Route::apiResource('assignments', AssignmentController::class);
Route::apiResource('assignment-submissions', AssignmentSubmissionController::class);

Route::apiResource('quizzes', QuizController::class);
Route::apiResource('quiz-questions', QuizQuestionController::class);
Route::apiResource('quiz-attempts', QuizAttemptController::class);
Route::apiResource('quiz-answers', QuizAnswerController::class);

Route::apiResource('exams', ExamController::class);
Route::apiResource('exam-results', ExamResultController::class);

Route::apiResource('learning-analytics', LearningAnalyticController::class);
Route::apiResource('predictions', PredictionController::class);
Route::apiResource('risk-assessments', RiskAssessmentController::class);
Route::apiResource('recommendations', RecommendationController::class);

Route::apiResource('notifications', NotificationController::class);
Route::apiResource('reports', ReportController::class);

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
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'update']);

    // Student Endpoints (enforces student profile presence with 404 in controller)
    Route::get('/dashboard/student', [DashboardController::class, 'student']);
    Route::get('/student/attendance', [DashboardController::class, 'studentAttendance']);
    Route::get('/student/assignments', [DashboardController::class, 'studentAssignments']);
    Route::get('/student/quizzes', [DashboardController::class, 'studentQuizzes']);
    Route::get('/student/exams', [DashboardController::class, 'studentExams']);
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
            Route::get('/course-offerings', [\App\Http\Controllers\TeacherModuleController::class, 'courseOfferings']);
            Route::get('/course-offerings/{id}', [\App\Http\Controllers\TeacherModuleController::class, 'courseOfferingDetail']);
            Route::get('/course-offerings/{id}/students', [\App\Http\Controllers\TeacherModuleController::class, 'courseOfferingStudents']);
            Route::get('/course-offerings/{id}/attendance', [\App\Http\Controllers\TeacherModuleController::class, 'courseOfferingAttendance']);
            Route::get('/course-offerings/{id}/assignments', [\App\Http\Controllers\TeacherModuleController::class, 'courseOfferingAssignments']);
            Route::get('/course-offerings/{id}/quizzes', [\App\Http\Controllers\TeacherModuleController::class, 'courseOfferingQuizzes']);
            Route::get('/course-offerings/{id}/scores', [\App\Http\Controllers\TeacherModuleController::class, 'courseOfferingScores']);
        });
    });

    Route::get('/notifications/unread-count', [NotificationController::class, 'apiUnreadCount']);

    // Admin Only Protected Endpoints
    Route::middleware('role:admin')->group(function () {
        Route::get('/dashboard/admin', [DashboardController::class, 'admin']);
        Route::get('/admin/dashboard/overview', [DashboardController::class, 'adminOverview']);
        Route::get('/admin/dashboard/user-growth', [DashboardController::class, 'adminUserGrowth']);
        Route::get('/admin/dashboard/system-status', [DashboardController::class, 'adminSystemStatus']);
        Route::get('/admin/dashboard/statistics', [DashboardController::class, 'adminStatistics']);
        Route::get('/admin/dashboard/annual-analytics', [DashboardController::class, 'annualAnalytics']);

        Route::get('/admin/analytics/enrollment', [\App\Http\Controllers\AdminAnalyticsController::class, 'enrollment']);
        Route::get('/admin/analytics/performance', [\App\Http\Controllers\AdminAnalyticsController::class, 'performance']);
        Route::get('/admin/analytics/attendance', [\App\Http\Controllers\AdminAnalyticsController::class, 'attendance']);
        Route::get('/admin/analytics/student-risk', [\App\Http\Controllers\AdminAnalyticsController::class, 'studentRisk']);

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
});
