# ឯកសារពន្យល់ទំព័រទាំងអស់នៃកម្មវិធី Android SLAS (Student Learning Analytics System)

> [!NOTE]
> ឯកសារនេះត្រូវបានរៀបចំឡើងជាភាសាខ្មែរសម្រាប់ការប្រើប្រាស់ ការបង្ហាញប្រព័ន្ធ (System Demo) និងការការពារគម្រោង **Student Learning Analytics System (SLAS)** ដោយផ្អែកលើកូដពិតប្រាកដនៃ Android Application (`com.social7.studentlearnanalytics`), Laravel 12 Backend API និងមូលដ្ឋានទិន្នន័យ MySQL (`student_learning_analytics`) បន្ទាប់ពីការត្រួតពិនិត្យ និងកែលម្អទូទាំងប្រព័ន្ធ។

---

## រចនាសម្ព័ន្ធរួមនៃកម្មវិធី Android SLAS

កម្មវិធី Android ប្រើប្រាស់ស្ថាបត្យកម្ម **Role-Based Access Control (RBAC)** ចែកចេញជា ៣ តួនាទីសំខាន់គឺ **Student (និស្សិត)**, **Teacher (គ្រូបង្រៀន)** និង **Admin (អ្នកគ្រប់គ្រងប្រព័ន្ធ)** ដែលផ្ទៀងផ្ទាត់សិទ្ធិតាមរយៈ **Laravel Sanctum Token** និង `RoleGuard`។ ទិន្នន័យទាំងអស់ត្រូវបានគ្រប់គ្រងនៅ **Laravel Backend** ជា Single Source of Truth ហើយផ្ទេរតាម Retrofit API (`ApiService.java`) ក្នុងទម្រង់ JSON។

សរុបទំព័រទាំងអស់ក្នុង Android មាន **១២១ ឯកសារ (`121` `*Activity.java` / `*Fragment.java` files = ១២០ ថ្នាក់ Activity/Fragment ពិតប្រាកដ + `EditUserActivity.java` ដែលជា Legacy stub ៤ បន្ទាត់)** ចែកចេញជា ៥ ក្រុមធំៗ៖
1. **ក្រុមទី ១៖ ការចូលប្រើប្រាស់ និងសុវត្ថិភាព (Authentication & Security)** — ៤ ទំព័រ (`#1–4`)
2. **ក្រុមទី ២៖ តួនាទីនិស្សិត (Student Role)** — ៣៧ ទំព័រ (`#5–41`)
3. **ក្រុមទី ៣៖ តួនាទីគ្រូបង្រៀន (Teacher Role)** — ២៨ ឯកសារ (`#42–69`៖ ២៥ ទំព័រប្រើប្រាស់ + ៣ ទំព័រ Legacy/Unregistered)
4. **ក្រុមទី ៤៖ តួនាទីអ្នកគ្រប់គ្រងប្រព័ន្ធ (Admin Role)** — ៥១ ឯកសារ (`#70–120`៖ ៤៧ ទំព័រប្រើប្រាស់ + ៤ Legacy Redirects/Stub, រួមទាំង `EditUserActivity.java`)
5. **ក្រុមទី ៥៖ ទំព័រដើមគំរូ / Legacy Template (Shared / Entry Template)** — ១ ទំព័រ (`MainActivity`, `#121`)

---

# ក្រុមទី ១៖ ការចូលប្រើប្រាស់ និងសុវត្ថិភាព (Authentication & Security)

### 1. `SplashActivity` (`auth/SplashActivity.java` • Layout: `activity_splash.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រស្វាគមន៍ និងពិនិត្យសម័យចូលប្រើប្រាស់ (Splash Screen)
- **តួនាទីប្រើប្រាស់៖** អ្នកប្រើប្រាស់ទូទៅ (All Roles / Unauthenticated)
- **គោលបំណង និងមុខងារសំខាន់៖** ជាទំព័រដំបូងគេ (`LAUNCHER`) ពេលបើកកម្មវិធី ដើម្បីបង្ហាញរូបសញ្ញា SLAS និងពិនិត្យស្ថានភាព Token/Session ក្នុង `SessionManager`។
- **ធាតុផ្សំ UI៖** រូបសញ្ញាកម្មវិធី ចំណងជើង SLAS ពាក្យស្លោក និងផ្ទៃពណ៌ Gradient (`bg_gradient_splash`)។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ស្ថានភាព Auto-check session (`Logged In` vs `Logged Out`)។
- **ប៊ូតុង និងសកម្មភាព៖** មិនមានប៊ូតុងចុចទេ — ដំណើរការបន្តដោយស្វ័យប្រវត្តិក្រោយពន្យារពេលខ្លី។
- **ប្រភពទិន្នន័យ៖** `SessionManager` (`EncryptedSharedPreferences`) ក្នុងម៉ាស៊ីន។
- **ចំណុចតភ្ជាប់ API៖** មិនប្រើ API ដោយផ្ទាល់ (ប្រើសម័យដែលបានរក្សាទុក)។
- **តារាង Database ពាក់ព័ន្ធ៖** មិនប៉ះ Database ដោយផ្ទាល់។
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** ចេញពី Launcher → បើមាន Session ទៅ `AdminMainActivity`, `TeacherMainActivity`, ឬ `StudentMainActivity` តាម `user_role`; បើគ្មានទៅ `LoginActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ (System Demo)៖** *"នេះគឺជាទំព័របើកដំបូងរបស់កម្មវិធី SLAS។ វាពិនិត្យ Token សុវត្ថិភាពក្នុង EncryptedSharedPreferences ដោយស្វ័យប្រវត្តិ ដើម្បីនាំអ្នកប្រើប្រាស់ទៅកាន់ផ្ទាំងការងារតាមតួនាទី (Student, Teacher ឬ Admin) ដោយមិនបាច់ Login ម្តងទៀត។"*

---

### 2. `LoginActivity` (`auth/LoginActivity.java` • Layout: `activity_login.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រចូលប្រើប្រាស់ប្រព័ន្ធ (Sign In)
- **តួនាទីប្រើប្រាស់៖** និស្សិត គ្រូបង្រៀន និងអ្នកគ្រប់គ្រងប្រព័ន្ធ
- **គោលបំណង និងមុខងារសំខាន់៖** ផ្ទៀងផ្ទាត់អត្តសញ្ញាណតាម Email ឬលេខសម្គាល់ (Student/Teacher/Admin Code) និងពាក្យសម្ងាត់ ដើម្បីទទួលយក Sanctum Bearer Token ពី Laravel។
- **ធាតុផ្សំ UI៖** ប្រអប់បញ្ចូល `Email / ID`, ប្រអប់ `Password` (មានប៊ូតុងបើក/បិទភ្នែក), តំណ `Forgot Password?`, ប៊ូតុង `Login`, និង ProgressBar។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ស្ថានភាព Loading ពេលហៅ API, ស្ថានភាព Error (Validation error / `401 Invalid credentials` / Account inactive)។
- **ប៊ូតុង និងសកម្មភាព៖**
  - `btnLogin`: ផ្ទៀងផ្ទាត់ទិន្នន័យ ហៅ `POST /api/login` រក្សាទុក Token + Role + User/Student ID ក្នុង `SessionManager`។
  - `tvForgotPassword`: បើកទៅ `ForgotPasswordActivity`។
- **ប្រភពទិន្នន័យ៖** Laravel Auth API (`AuthController`) + ផ្ទៀងផ្ទាត់ Profile (`getUser`)។
- **ចំណុចតភ្ជាប់ API៖** `POST /api/login`, `GET /api/user`
- **តារាង Database ពាក់ព័ន្ធ៖** `users`, `personal_access_tokens`, `students`, `teachers`, `admins`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `SplashActivity` ឬ Logout → ទៅ `StudentMainActivity`, `TeacherMainActivity`, ឬ `AdminMainActivity`, ឬ `ForgotPasswordActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រ Login នេះប្រើ Laravel Sanctum Authentication។ នៅពេលបញ្ចូល Email ឬ Student/Teacher Code ប្រព័ន្ធនឹងបញ្ជូនទៅ API ហើយផ្ទៀងផ្ទាត់តួនាទីនៅ Backend រួចបើកចូលផ្ទាំងតាមសិទ្ធិពិតប្រាកដ។"*

---

### 3. `ForgotPasswordActivity` (`auth/ForgotPasswordActivity.java` • Layout: `activity_forgot_password.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រស្នើសុំកំណត់ពាក្យសម្ងាត់ឡើងវិញ (Forgot Password)
- **តួនាទីប្រើប្រាស់៖** អ្នកប្រើប្រាស់ទូទៅដែលភ្លេចពាក្យសម្ងាត់
- **គោលបំណង និងមុខងារសំខាន់៖** ផ្ទៀងផ្ទាត់ទម្រង់អ៊ីមែលរបស់អ្នកប្រើប្រាស់ និងពន្យល់គោលការណ៍ផ្លាស់ប្តូរពាក្យសម្ងាត់តាមរយៈ System Administrator (ដោយសារប្រព័ន្ធ SLAS កំណត់ឱ្យ Admin គ្រប់គ្រងការ Reset Password ដើម្បីសុវត្ថិភាព)។ *(បានកែលម្អក្នុង Audit នេះដោយដាក់ Validation អ៊ីមែល និងបង្ហាញការណែនាំពិតប្រាកដជំនួសសារ Toast ក្លែងក្លាយ។)*
- **ធាតុផ្សំ UI៖** រូបសញ្ញា SLAS, អត្ថបទណែនាំ, ប្រអប់អ៊ីមែល (`edtForgotEmail`), ប៊ូតុង `btnSendReset`។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Validation Error លើ `edtForgotEmail` បើទទេ ឬខុសទម្រង់អ៊ីមែល; Material Dialog បង្ហាញការណែនាំទាក់ទង Admin។
- **ប៊ូតុង និងសកម្មភាព៖**
  - `btnSendReset`: ពិនិត្យអ៊ីមែលតាម `ValidationUtils.isValidEmail()` រួចបើកផ្ទាំងប្រាប់នីតិវិធី Reset Password តាម Admin។
- **ប្រភពទិន្នន័យ៖** Validation ក្នុង Client (គ្មាន Public Email Reset Endpoint)។
- **ចំណុចតភ្ជាប់ API៖** គ្មាន (ការ Reset Password ធ្វើឡើងដោយ Admin តាម `POST /api/admin/students/{id}/reset-password` ឬ Edit User)។
- **តារាង Database ពាក់ព័ន្ធ៖** ពាក់ព័ន្ធដោយប្រយោលជាមួយ `users`។
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `LoginActivity` → ចុច Back ត្រឡប់ទៅ `LoginActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ដើម្បីការពារសុវត្ថិភាពគណនីនិស្សិត និងគ្រូ ប្រព័ន្ធ SLAS តម្រូវឱ្យមានការផ្ទៀងផ្ទាត់ទម្រង់អ៊ីមែល និងណែនាំឱ្យទាក់ទង System Admin ដើម្បីធ្វើការ Reset Password ពី User Management។"*

---

### 4. `ChangePasswordActivity` (`auth/ChangePasswordActivity.java` • Layout: `activity_change_password.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រផ្លាស់ប្តូរពាក្យសម្ងាត់ (Change Password)
- **តួនាទីប្រើប្រាស់៖** និស្សិត គ្រូបង្រៀន និងអ្នកគ្រប់គ្រងប្រព័ន្ធ (Authenticated Users)
- **គោលបំណង និងមុខងារសំខាន់៖** អនុញ្ញាតឱ្យអ្នកប្រើប្រាស់ដែលបាន Login រួចផ្លាស់ប្តូរពាក្យសម្ងាត់ផ្ទាល់ខ្លួនដោយសុវត្ថិភាព។
- **ធាតុផ្សំ UI៖** ប៊ូតុងត្រឡប់ក្រោយ, ប្រអប់ Current Password, New Password, Confirm Password, និងប៊ូតុង Save Password។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Field error Validation (មិនតិចជាង 8 តួអក្សរ ត្រូវគ្នានឹង Confirm Password), Success Toast / Server error Toast។
- **ប៊ូតុង និងសកម្មភាព៖**
  - `btnBack`: បិទទំព័រ។
  - `btnSavePassword`: ហៅ `POST /api/change-password` ដោយបញ្ជូនពាក្យសម្ងាត់ចាស់ និងថ្មី។
- **ប្រភពទិន្នន័យ៖** Laravel Auth API (`AuthController::changePassword`)។
- **ចំណុចតភ្ជាប់ API៖** `POST /api/change-password`
- **តារាង Database ពាក់ព័ន្ធ៖** `users` (កែប្រែ column `password`)
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `ProfileFragment` (Student), `TeacherProfileOverviewFragment`/`TeacherProfileFragment` (Teacher), `AdminProfileFragment` (Admin) → ត្រឡប់ទៅទំព័រដើមវិញក្រោយរក្សាទុក។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រនេះប្រើរួមសម្រាប់ទាំង ៣ តួនាទី ដើម្បីប្តូរពាក្យសម្ងាត់ដោយផ្ទៀងផ្ទាត់ពាក្យសម្ងាត់ចាស់នៅលើ Laravel Server មុននឹងអនុញ្ញាតឱ្យប្តូរថ្មី។"*

---

# ក្រុមទី ២៖ តួនាទីនិស្សិត (Student Role)

### 5. `StudentMainActivity` (`student/StudentMainActivity.java` • Layout: `activity_student_main.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងមេរបស់និស្សិត (Student Portal Shell)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** ជា Container មេគ្រប់គ្រង Navigation Drawer, Bottom Navigation Bar, Header ទូទៅ និងការផ្ទុក Fragments របស់និស្សិតទាំងអស់។
- **ធាតុផ្សំ UI៖** `DrawerLayout`, Navigation Drawer Header & Menu (`nav_header` + បញ្ជីមុខងារ), `BottomNavigationView` (`student_bottom_menu.xml`), `FrameLayout` (`fragment_container`), ផ្លាកចំនួន Notification មិនទាន់អាន។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Bottom Tabs ៥ (Home/Dashboard, Courses, Analytics, Prediction, Profile) + Drawer Items (Assessments, Recommendations, Reports, Notifications, Settings, Logout) + Unread Notification Badge។
- **ប៊ូតុង និងសកម្មភាព៖**
  - Bottom Navigation Tabs: ប្តូរ Fragment រហ័ស។
  - Drawer Items: បើកទៅ `StudentAssessmentFragment`, `StudentRecommendationFragment`, `ReportFragment`, `NotificationFragment`, `StudentSettingsFragment`, និង Logout (ហៅ `POST /api/logout`)។
- **ប្រភពទិន្នន័យ៖** `SessionManager`, `GET /api/profile`, `GET /api/notifications`។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/profile`, `GET /api/notifications`, `POST /api/logout`
- **តារាង Database ពាក់ព័ន្ធ៖** `users`, `students`, `notifications`, `personal_access_tokens`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `SplashActivity` ឬ `LoginActivity` → ផ្ទុករាល់ Student Fragments; ពេល Logout → `LoginActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"នេះគឺជាស៊ុមមេនៃ Portal និស្សិត ដែលរួមបញ្ចូល Bottom Navigation សម្រាប់មុខងារប្រចាំថ្ងៃ និង Side Drawer សម្រាប់ចូលមើលកិច្ចការ របាយការណ៍ ការណែនាំ និងការជូនដំណឹង។"*

---

### 6. `StudentDashboardFragment` (`student/StudentDashboardFragment.java` • Layout: `activity_fragment_student_dashboard.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងទំព័រដើមនិស្សិត (Student Dashboard)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញសង្ខេបលទ្ធផលសិក្សាសំខាន់ៗរបស់និស្សិតដូចជា GPA, ភាគរយវត្តមាន, កម្រិតហានិភ័យ (Risk Level), កាតចូលរហ័ស (Quick Access), តារាងវឌ្ឍនភាព, និងសកម្មភាពថ្មីៗ។
- **ធាតុផ្សំ UI៖** សារស្វាគមន៍ + រូបតំណាង Notification + ម៉ឺនុយ, កាត KPI ២x២ (GPA, Attendance, Courses, Predicted/Risk), Quick Action Grid, ក្រាហ្វ LineChart វឌ្ឍនភាព, ប៊ូតុងមើលមុខវិជ្ជា និងសកម្មភាពថ្មីៗ។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** កាត Summary, ផ្លាក Risk (`Low`, `Medium`, `High`, `Not available yet`), Empty State ពេលគ្មានទិន្នន័យគ្រប់គ្រាន់, Filter Semester។
- **ប៊ូតុង និងសកម្មភាព៖**
  - កាត/ប៊ូតុង Quick Access: បើកទៅ `AttendanceOverviewActivity`, `AssessmentDetailActivity`, `QuizDetailActivity`, `GradesOverviewActivity`, `LearningAnalyticsActivity`, `AcademicJourneyActivity`, `PredictionDetailActivity`, `RiskAnalysisActivity`, `SmartRecommendationsActivity`, `RecentActivitiesActivity`, `ExamDetailActivity`, `GpaProgressActivity`, `AllFeaturesActivity`។
  - ចុចលើ Course: បើក `CourseDetailActivity`។
- **ប្រភពទិន្នន័យ៖** Laravel Dashboard & Notification Count API (`DashboardController::student`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/dashboard`, `GET /api/notifications/unread-count`, `GET /api/semesters`
- **តារាង Database ពាក់ព័ន្ធ៖** `students`, `course_enrollments`, `courses`, `attendance_details`, `assignments`, `assignment_submissions`, `quizzes`, `quiz_attempts`, `exams`, `exam_results`, `gpa_records`, `learning_analytics`, `risk_assessments`, `predictions`, `notifications`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** ផ្ទាំងលំនាំដើមក្នុង `StudentMainActivity` → បើកទៅគ្រប់ទំព័រមុខងារនិស្សិត។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រ Dashboard និស្សិតបង្ហាញពិន្ទុ GPA វត្តមាន និងកម្រិតហានិភ័យដែលគណនាចេញពីទិន្នន័យពិតនៅ Laravel រួមទាំងកាតផ្លូវកាត់ទៅមុខវិជ្ជា កិច្ចការ ប្រឡង និងការវិភាគការសិក្សា។"*

---

### 7. `NavigationMenuActivity` (`student/NavigationMenuActivity.java` • Layout: `activity_navigation_menu.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រម៉ឺនុយមុខងារទាំងអស់របស់និស្សិត (Navigation Menu)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញបញ្ជីមុខងារសំខាន់ៗ និងព័ត៌មាន Profile របស់និស្សិតក្នុងទម្រង់បើកពេញអេក្រង់។
- **ធាតុផ្សំ UI៖** Header ព័ត៌មានគណនី, បញ្ជីម៉ឺនុយកាត (Risk Analysis, Recommendations, Assignments/Quizzes, Learning Report), ប៊ូតុង Logout។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Unread Notification count badge។
- **ប៊ូតុង និងសកម្មភាព៖** ចុចបើក `RiskAnalysisActivity`, `RecommendationDetailActivity`, `AssessmentDetailActivity`, `LearningReportActivity`, ត្រឡប់ទៅ `StudentMainActivity` ឬ Logout។
- **ប្រភពទិន្នន័យ៖** Profile & Notifications API។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/profile`, `GET /api/notifications`
- **តារាង Database ពាក់ព័ន្ធ៖** `users`, `students`, `notifications`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `StudentDashboardFragment` → ទៅកាន់ទំព័រលម្អិតផ្សេងៗ ឬ `LoginActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រម៉ឺនុយពេញអេក្រង់សម្រាប់ឱ្យនិស្សិតចូលទៅមើលមុខងារវិភាគ របាយការណ៍ និងកិច្ចការបានងាយស្រួល។"*

---

### 8. `AllFeaturesActivity` (`student/AllFeaturesActivity.java` • Layout: `activity_all_features.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រមុខងារសិក្សាទាំងអស់ (All Features Directory)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** ប្រមូលផ្តុំតំណភ្ជាប់ទាំង ៦ មុខងារធំៗរបស់និស្សិតនៅកន្លែងតែមួយ។
- **ធាតុផ្សំ UI៖** ប៊ូតុងត្រឡប់ក្រោយ និងកាត ៦ (`itemAttendance`, `itemAssignments`, `itemQuizzes`, `itemAnalytics`, `itemRecommendations`, `itemReports`)។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** មិនមានស្ថានភាព Loading (Static directory cards)។
- **ប៊ូតុង និងសកម្មភាព៖** ចុចលើកាតនីមួយៗបើកទៅ `AttendanceDetailActivity`, `AssessmentDetailActivity`, `QuizDetailActivity`, `OverallPerformanceActivity`, `RiskAnalysisActivity`, `LearningReportActivity`។
- **ប្រភពទិន្នន័យ៖** ម៉ឺនុយក្នុងកម្មវិធី។
- **ចំណុចតភ្ជាប់ API៖** គ្មាន (ទំព័រនាំផ្លូវ)។
- **តារាង Database ពាក់ព័ន្ធ៖** គ្មាន។
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `StudentDashboardFragment` → ទៅកាន់ ៦ Activities ខាងលើ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រនេះជួយឱ្យនិស្សិតមើលឃើញ និងបើកមុខងារសិក្សាទាំងអស់ក្នុងផ្ទាំងតែមួយ។"*

---

### 9. `RecentActivitiesActivity` (`student/RecentActivitiesActivity.java` • Layout: `activity_recent_activities.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រសកម្មភាពសិក្សាថ្មីៗ (Recent Activities)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញបញ្ជីព្រឹត្តិការណ៍សិក្សាថ្មីៗរបស់និស្សិត (ការដាក់កិច្ចការ Quiz ប្រឡង និងវត្តមាន)។
- **ធាតុផ្សំ UI៖** Header, Filter/Category tabs, `RecyclerView` (`item_recent_activity`), Empty State។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ស្ថានភាព Type badge (Assignment, Quiz, Exam, Attendance) និងពេលវេលា។
- **ប៊ូតុង និងសកម្មភាព៖** ចុចលើសកម្មភាពនីមួយៗនាំទៅ `AssessmentDetailActivity`, `QuizDetailActivity`, `ExamDetailActivity`, `AttendanceDetailActivity` ឬ `CourseDetailActivity`។
- **ប្រភពទិន្នន័យ៖** Dashboard API។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/dashboard`
- **តារាង Database ពាក់ព័ន្ធ៖** `assignment_submissions`, `quiz_attempts`, `exam_results`, `attendance_details`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `StudentDashboardFragment` → ទៅកាន់ទំព័រលម្អិតតាមប្រភេទសកម្មភាព។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ប្រព័ន្ធប្រមូលសកម្មភាពចុងក្រោយទាំងអស់របស់និស្សិតដាក់ក្នុង Timeline តែមួយ ដើម្បីងាយតាមដាន និងចុចចូលមើលលម្អិត។"*

---

### 10. `StudentCoursesFragment` (`student/StudentCoursesFragment.java` • Layout: `fragment_student_courses.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងបញ្ជីមុខវិជ្ជារបស់និស្សិត (My Courses)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញមុខវិជ្ជាទាំងអស់ដែលនិស្សិតកំពុងចុះឈ្មោះរៀន រួមជាមួយឈ្មោះគ្រូ បន្ទប់រៀន កាលវិភាគ និងវឌ្ឍនភាពពិន្ទុ។
- **ធាតុផ្សំ UI៖** Header, ប្រអប់ស្វែងរក, ផ្ទាំងត្រងឆមាស, `RecyclerView` កាតមុខវិជ្ជា, Empty/Error State។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ផ្លាក Core/Elective, ក្រេឌីត, ភាគរយវត្តមាន និងពិន្ទុ។
- **ប៊ូតុង និងសកម្មភាព៖** ចុចលើកាតមុខវិជ្ជាបើក `CourseDetailActivity` ដោយភ្ជាប់ `course_id` និងព័ត៌មានមុខវិជ្ជា។
- **ប្រភពទិន្នន័យ៖** Student Dashboard & Enrollments API។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/dashboard`
- **តារាង Database ពាក់ព័ន្ធ៖** `course_enrollments`, `courses`, `course_offerings`, `class_schedules`, `teachers`, `classes`, `buildings`, `floors`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Bottom Tab ២ ក្នុង `StudentMainActivity` → `CourseDetailActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"និស្សិតអាចមើលមុខវិជ្ជាដែលខ្លួនបានចុះឈ្មោះ គ្រូបង្រៀន បន្ទប់រៀន និងម៉ោងសិក្សាពិតប្រាកដពីតារាង `course_enrollments` និង `class_schedules`។"*

---

### 11. `CourseDetailActivity` (`student/CourseDetailActivity.java` • Layout: `activity_course_detail.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រព័ត៌មានលម្អិតមុខវិជ្ជា (Course Detail)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញព័ត៌មានលម្អិតនៃមុខវិជ្ជាមួយ រួមមាន គ្រូបង្រៀន វត្តមានតាមមុខវិជ្ជា បញ្ជី Assignments, Quizzes និង Exams សម្រាប់មុខវិជ្ជានោះ។
- **ធាតុផ្សំ UI៖** ក្បាលមុខវិជ្ជា (Code, Name, Teacher, Credits, Schedule, Room), ផ្ទាំង Tabs (`Overview`, `Attendance`, `Assignments`, `Quizzes`, `Exams`), `RecyclerView` ទិន្នន័យតាម Tab។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ភាគរយវត្តមានតាមមុខវិជ្ជា ស្ថានភាពដាក់កិច្ចការ និងពិន្ទុ Quiz/Exam។
- **ប៊ូតុង និងសកម្មភាព៖** ប្តូរ Tab ដើម្បីទាញយក និងមើលទិន្នន័យតាមមុខវិជ្ជា; ប៊ូតុងត្រឡប់ក្រោយ។
- **ប្រភពទិន្នន័យ៖** Teacher Info + Student Course-Filtered APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teachers/{id}`, `GET /api/student/attendance?course_id=...`, `GET /api/student/assignments?course_id=...`, `GET /api/student/quizzes?course_id=...`, `GET /api/student/exams?course_id=...`
- **តារាង Database ពាក់ព័ន្ធ៖** `courses`, `teachers`, `attendance`, `attendance_details`, `assignments`, `assignment_submissions`, `quizzes`, `quiz_attempts`, `exams`, `exam_results`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `StudentCoursesFragment`, `StudentDashboardFragment`, `StudentRecommendationFragment` → បិទត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រនេះបង្ហាញព័ត៌មានសិក្សាទាំងអស់ក្នុងមុខវិជ្ជាតែមួយ រាប់ចាប់ពីវត្តមាន កិច្ចការ Quizzes រហូតដល់ការប្រឡង និងព័ត៌មានគ្រូបង្រៀន។"*

---

### 12. `AcademicJourneyActivity` (`student/academic/AcademicJourneyActivity.java` • Layout: `activity_academic_journey.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រដំណើរសិក្សាតាមឆ្នាំ និងឆមាស (Academic Journey)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញប្រវត្តិ និងផ្លូវសិក្សាបែងចែកតាមឆ្នាំសិក្សា (`Year 1`–`Year 4`) និងឆមាស (`Completed`, `Current`, `Upcoming`)។
- **ធាតុផ្សំ UI៖** ផ្ទាំង Header, កាតសង្ខេប, បញ្ជីឆ្នាំសិក្សា និងឆមាសជាមួយមុខវិជ្ជាតាមឆមាស។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ផ្លាក `Completed`, `Current`, `Upcoming` តាមស្ថានភាពឆមាសក្នុង DB។
- **ប៊ូតុង និងសកម្មភាព៖** បើក/បិទមើលមុខវិជ្ជាក្នុងឆមាសនីមួយៗ និងប៊ូតុង Back។
- **ប្រភពទិន្នន័យ៖** Student Module API (`StudentModuleController::academicJourney`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/module/academic-journey`
- **តារាង Database ពាក់ព័ន្ធ៖** `course_offerings`, `course_enrollments`, `semesters`, `academic_years`, `courses`, `classes`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `StudentDashboardFragment` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"បង្ហាញដំណើរវិវឌ្ឍន៍ការសិក្សារបស់និស្សិតពីឆ្នាំទី ១ ដល់ឆ្នាំទី ៤ ដោយផ្ទៀងផ្ទាត់ស្ថានភាពឆមាសពីប្រព័ន្ធសិក្សាពិត។"*

---

### 13. `GpaProgressActivity` (`student/academic/GpaProgressActivity.java` • Layout: `activity_gpa_progress.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រតាមដានវឌ្ឍនភាព GPA (GPA Progress & Peer Comparison)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញ GPA សរុប ក្រាហ្វ GPA តាមឆមាស និងការប្រៀបធៀបលទ្ធផលធៀបនឹងមធ្យមភាគថ្នាក់/កម្មវិធី។
- **ធាតុផ្សំ UI៖** កាត Cumulative GPA, LineChart/BarChart និន្នាការ GPA, កាតប្រៀបធៀបលទ្ធផល និងបញ្ជី GPA តាមឆមាស។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ផ្លាកកម្រិត GPA និងស្ថានភាព Empty ប្រសិនបើមិនទាន់មានកំណត់ត្រាពិន្ទុ។
- **ប៊ូតុង និងសកម្មភាព៖** ប៊ូតុងត្រឡប់ក្រោយ ការផ្លាស់ប្តូរការមើលឆមាស។
- **ប្រភពទិន្នន័យ៖** Student Module Dashboard & Comparisons API។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/module/dashboard`, `GET /api/student/module/comparisons`
- **តារាង Database ពាក់ព័ន្ធ៖** `gpa_records`, `grades`, `semesters`, `course_enrollments`, `student_notes`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `StudentDashboardFragment` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"បង្ហាញវឌ្ឍនភាពពិន្ទុ GPA របស់និស្សិតតាមឆមាសនីមួយៗ និងប្រៀបធៀបកម្រិតសិក្សាជាមួយមធ្យមភាគក្រុមសិក្សាដោយរក្សាភាពឯកជនរបស់និស្សិតផ្សេងទៀត។"*

---

### 14. `GradesOverviewActivity` (`student/grades/GradesOverviewActivity.java` • Layout: `activity_grades_overview.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រសង្ខេបពិន្ទុ និងនិទ្ទេស (Grades Overview)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញពិន្ទុសរុប និងនិទ្ទេស (`A`–`F`) សម្រាប់គ្រប់មុខវិជ្ជាដែលនិស្សិតរៀន រួមជាមួយការគណនាពី Assignments/Quizzes/Exams ផ្ទាល់។
- **ធាតុផ្សំ UI៖** កាត GPA សរុប ផ្ទាំងបញ្ជីមុខវិជ្ជាជាមួយ Letter Grade និងភាគរយពិន្ទុ។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ផ្លាកនិទ្ទេសពណ៌ (`A`, `B+`, `Pending` ឬ `N/A`)។
- **ប៊ូតុង និងសកម្មភាព៖** ចុចលើមុខវិជ្ជាណាមួយដើម្បីបើក `CourseGradeActivity`។
- **ប្រភពទិន្នន័យ៖** Student Grades Overview API។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/module/grades`
- **តារាង Database ពាក់ព័ន្ធ៖** `grades`, `gpa_records`, `assignments`, `assignment_submissions`, `quizzes`, `quiz_attempts`, `exams`, `exam_results`, `course_enrollments`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `StudentDashboardFragment` → `CourseGradeActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"បើកមើលពិន្ទុ និងនិទ្ទេសគ្រប់មុខវិជ្ជា រួចអាចចុចចូលមើលការបំបែកពិន្ទុលម្អិតក្នុងមុខវិជ្ជានីមួយៗ។"*

---

### 15. `CourseGradeActivity` (`student/grades/CourseGradeActivity.java` • Layout: `activity_course_grade.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រពិន្ទុលម្អិតតាមមុខវិជ្ជា (Course Grade Breakdown)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញលម្អិតសមាសភាពពិន្ទុទាំងអស់នៃមុខវិជ្ជាមួយ (Assignments, Quizzes, Midterm, Final, Attendance និង Composite Score)។
- **ធាតុផ្សំ UI៖** កាតពិន្ទុសរុប + និទ្ទេស, ProgressBars តាមផ្នែក និងបញ្ជីកិច្ចការ/ប្រឡងក្នុងមុខវិជ្ជា។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ពិន្ទុជាប់/ធ្លាក់ ឬមិនទាន់ដាក់ពិន្ទុ។
- **ប៊ូតុង និងសកម្មភាព៖** ប៊ូតុងត្រឡប់ក្រោយ។
- **ប្រភពទិន្នន័យ៖** Student Course Grade API។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/module/grades/{courseOfferingId}`
- **តារាង Database ពាក់ព័ន្ធ៖** `grades`, `assignment_submissions`, `quiz_attempts`, `exam_results`, `attendance_details`, `course_offerings`
- **ការតភ្ជាប់ទំព័ន្ធ (Navigation)៖** មកពី `GradesOverviewActivity` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"បង្ហាញការគណនាពិន្ទុតាមសមាសភាគនីមួយៗក្នុងមុខវិជ្ជា ជួយឱ្យនិស្សិតដឹងថាចំណុចណាខ្លួនធ្វើបានល្អ ឬត្រូវកែលម្អ។"*

---

### 16. `AttendanceDetailActivity` (`student/attendance/AttendanceDetailActivity.java` • Layout: `activity_attendance_detail.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រប្រវត្តិវត្តមានលម្អិត (Attendance Detail)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញកំណត់ត្រាវត្តមានទាំងអស់របស់និស្សិតតាមកាលបរិច្ឆេទ និងមុខវិជ្ជា (`Present`, `Late`, `Absent`, `Excused`)។
- **ធាតុផ្សំ UI៖** កាតសង្ខេបវត្តមាន, បញ្ជី Filter ស្ថានភាព និងបញ្ជីវត្តមានប្រចាំថ្ងៃ។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ផ្លាកពណ៌ (`Present` បៃតង, `Late` លឿងទុំ, `Absent` ក្រហម, `Excused` ស្វាយ)។
- **ប៊ូតុង និងសកម្មភាព៖** ត្រងតាមស្ថានភាព និងប៊ូតុង Back / ទៅកាន់ Dashboard។
- **ប្រភពទិន្នន័យ៖** Student Dashboard & Attendance APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/dashboard`, `GET /api/student/attendance`
- **តារាង Database ពាក់ព័ន្ធ៖** `attendance`, `attendance_details`, `courses`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `AllFeaturesActivity`, `RecentActivitiesActivity`, `OverallPerformanceActivity`, `NotificationFragment`, `RecommendationDetailActivity` → ត្រឡប់ក្រោយ ឬ `StudentMainActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"បង្ហាញកាលបរិច្ឆេទវត្តមានលម្អិតជាក់ស្តែងដែលគ្រូបានហៅឈ្មោះរួចនៅក្នុងប្រព័ន្ធ។"*

---

### 17. `AttendanceOverviewActivity` (`student/attendance/AttendanceOverviewActivity.java` • Layout: `activity_attendance_overview.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រសង្ខេបវត្តមានតាមមុខវិជ្ជា (Attendance Overview)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញភាគរយវត្តមានរួម និងការសង្ខេបវត្តមានតាមមុខវិជ្ជានីមួយៗ។
- **ធាតុផ្សំ UI៖** កាត KPI វត្តមានរួម (Present/Late/Absent/Excused) និងបញ្ជីកាតមុខវិជ្ជា (`CourseAttendanceAdapter`)។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ភាគរយវត្តមាន និងកម្រិតសុវត្ថិភាព (`>= 75%` ល្អ vs ហានិភ័យ)។
- **ប៊ូតុង និងសកម្មភាព៖** ចុចលើកាតមុខវិជ្ជាបើក `CourseAttendanceActivity` ដោយភ្ជាប់ Offering ID។
- **ប្រភពទិន្នន័យ៖** Student Module Attendance API។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/module/attendance`
- **តារាង Database ពាក់ព័ន្ធ៖** `attendance`, `attendance_details`, `course_offerings`, `courses`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `StudentDashboardFragment` → `CourseAttendanceActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"បង្ហាញទិដ្ឋភាពរួមនៃវត្តមាន និងបែងចែកតាមមុខវិជ្ជា ដើម្បីឱ្យនិស្សិតចុចចូលពិនិត្យវេនសិក្សាតាមមុខវិជ្ជាណាមួយ។"*

---

### 18. `CourseAttendanceActivity` (`student/attendance/CourseAttendanceActivity.java` • Layout: `activity_course_attendance.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រវត្តមានតាមមុខវិជ្ជា (Course Attendance History)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញប្រវត្តិវត្តមានគ្រប់វគ្គសិក្សាសម្រាប់មុខវិជ្ជា (`courseOfferingId`) ជាក់លាក់មួយ។
- **ធាតុផ្សំ UI៖** ចំណងជើងមុខវិជ្ជា ភាគរយវត្តមាន និងបញ្ជីថ្ងៃសិក្សា។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Status pill តាមវេនសិក្សា (`Present`, `Late`, `Absent`, `Excused`)។
- **ប៊ូតុង និងសកម្មភាព៖** ប៊ូតុង Back។
- **ប្រភពទិន្នន័យ៖** Student Course Attendance API។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/module/attendance/{courseOfferingId}`
- **តារាង Database ពាក់ព័ន្ធ៖** `attendance`, `attendance_details`, `course_offerings`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `AttendanceOverviewActivity` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"បង្ហាញតារាងវត្តមានលម្អិតនៃមុខវិជ្ជាមួយ ដោយរាប់ចំនួនម៉ោងមករៀន យឺត និងអវត្តមាន។"*

---

### 19. `StudentAssessmentFragment` (`student/StudentAssessmentFragment.java` • Layout: `fragment_student_assessment.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងការវាយតម្លៃ និងកិច្ចការនិស្សិត (My Assessments Portal)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** ជាមជ្ឈមណ្ឌលគ្រប់គ្រងកិច្ចការទាំងអស់របស់និស្សិត រួមមាន Assignments/Homework, Quizzes, Exams និង Submitted Versions (Submissions History)។
- **ធាតុផ្សំ UI៖** Header, ប្រអប់ស្វែងរក, Sub-tabs ៤ (`Assignments`, `Quizzes`, `Exams`, `Submissions`), កាតសង្ខេប, `RecyclerView`។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ផ្លាកស្ថានភាព Universal Reopen (ដូចជា `Open`, `Reopened`, `Expired`, `Submitted`, `Graded`, `Saved Draft`) និងចំនួន Attempt/Version។
- **ប៊ូតុង និងសកម្មភាព៖**
  - ចុច Assignment/Homework: បើក `SubmitAssignmentActivity` (ឬមើល Submission)។
  - ចុច Quiz: បើក `TakeQuizActivity` ឬ `QuizDetailActivity`។
  - ចុច Submission Row: បើក `StudentSubmissionDetailActivity` ឬ `SubmissionFileViewerActivity`។
- **ប្រភពទិន្នន័យ៖** Student Assessments & Submissions APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/assignments`, `GET /api/student/quizzes`, `GET /api/student/exams`, `GET /api/student/submissions`
- **តារាង Database ពាក់ព័ន្ធ៖** `assignments`, `assignment_submissions`, `assessment_student_overrides`, `quizzes`, `quiz_attempts`, `exams`, `exam_results`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Drawer ក្នុង `StudentMainActivity` → `SubmitAssignmentActivity`, `TakeQuizActivity`, `StudentSubmissionDetailActivity`, `SubmissionFileViewerActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ផ្ទាំងនេះបង្ហាញគ្រប់កិច្ចការ Quiz ប្រឡង និងឯកសារដែលនិស្សិតបានដាក់រួច រួមទាំងសិទ្ធិដាក់យឺត ឬធ្វើឡើងវិញដែលគ្រូបានបើកឱ្យ។"*

---

### 20. `AssessmentDetailActivity` (`student/assignment/AssessmentDetailActivity.java` • Layout: `activity_assessment_detail.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័របញ្ជីកិច្ចការ និងលំហាត់ (Assignments & Homework List)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញបញ្ជី Assignments និង Homework របស់និស្សិត ជាមួយតម្រងស្ថានភាព និងការចូលដាក់កិច្ចការ។
- **ធាតុផ្សំ UI៖** Header, តម្រង Course / Type / Status, `RecyclerView` បញ្ជីកិច្ចការ។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ផ្លាក `Pending`, `Submitted`, `Late`, `Reopened`, `Expired`, `Graded` តាមចម្លើយពី Laravel Server។
- **ប៊ូតុង និងសកម្មភាព៖** ចុចលើកិច្ចការដើម្បីបើក `SubmitAssignmentActivity` ឬពិនិត្យលទ្ធផល។
- **ប្រភពទិន្នន័យ៖** Assignments & Quizzes APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/assignments`, `GET /api/student/quizzes`
- **តារាង Database ពាក់ព័ន្ធ៖** `assignments`, `assignment_submissions`, `assessment_student_overrides`, `courses`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `StudentDashboardFragment`, `AllFeaturesActivity`, `NavigationMenuActivity`, `RecentActivitiesActivity` → `SubmitAssignmentActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"បង្ហាញបញ្ជីកិច្ចការទាំងអស់ដោយប្រើស្ថានភាព និងពេលកំណត់ផុតកំណត់ (Effective Deadline) ដែលបញ្ជូនពី Laravel។"*

---

### 21. `SubmitAssignmentActivity` (`student/assignment/SubmitAssignmentActivity.java` • Layout: `activity_submit_assignment.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រដាក់កិច្ចការ និងមើលប្រវត្តិការដាក់ស្នើ (Submit Assignment / Homework)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** អនុញ្ញាតឱ្យនិស្សិតអានសេចក្តីណែនាំ មើលសិទ្ធិ Reopen/Late/Resubmit សរសេរអត្ថបទ ភ្ជាប់ឯកសារ (PDF/Image/Word) ដាក់ស្នើថ្មី (Version ថ្មីដោយមិនលុប Version ចាស់) និងមើលប្រវត្តិការដាក់ស្នើមុនៗ។
- **ធាតុផ្សំ UI៖** កាតព័ត៌មានកិច្ចការ, ផ្លាកសិទ្ធិ Universal Reopen & Deadline, ប៊ូតុងជ្រើសឯកសារ, ប្រអប់អត្ថបទ, ប៊ូតុង Submit, បញ្ជី/ប៊ូតុង Submission History។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** បង្ហាញយ៉ាងច្បាស់រវាង `Expired / Locked` (បិទប៊ូតុង Submit) និង `Reopened / Allow Resubmission / Late Allowed`, ព្រមទាំង Attempt/Submission count ពី Server។
- **ប៊ូតុង និងសកម្មភាព៖**
  - `btnChooseFile`: ជ្រើសរើសឯកសារពីទូរស័ព្ទ។
  - `btnSubmitAssignment`: បញ្ជូន Multipart Upload (`submitAssignmentMultipart` / `submitAssignmentMultipartWithText` / `submitAssignment`)។
  - `btnViewSubmissionHistory` / ជួរ History: បើក `StudentSubmissionDetailActivity` ឬ `SubmissionFileViewerActivity` ដើម្បីមើល Version ១ និង Version ២។
- **ប្រភពទិន្នន័យ៖** Universal Reopen Status + Submission History + Upload APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/assignments/{id}`, `GET /api/assessments/{type}/{id}/reopen-status`, `GET /api/assessments/{type}/{id}/submissions`, `POST /api/student/assignments/{id}/submit`
- **តារាង Database ពាក់ព័ន្ធ៖** `assignments`, `assignment_submissions`, `assessment_student_overrides`, `assessment_audit_logs`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `AssessmentDetailActivity` ឬ `StudentAssessmentFragment` → `StudentSubmissionDetailActivity`, `SubmissionFileViewerActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រនេះអនុវត្តតាមគោលការណ៍ Universal Reopen Architecture ទាំងស្រុង៖ និស្សិតមិនអាចបំពាន Deadline បានទេ បើ Server មិនបើកសិទ្ធិឱ្យ។ ពេលគ្រូបើក Reopen ឬ Allow Resubmit និស្សិតអាចដាក់ Version ថ្មីដោយរក្សាទុកឯកសារ និងពិន្ទុ Version ចាស់ទាំងអស់។"*

---

### 22. `StudentSubmissionDetailActivity` (`student/assignment/StudentSubmissionDetailActivity.java` • Layout: `activity_student_submission_detail.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រមើលព័ត៌មានលម្អិតនៃកិច្ចការដែលបានដាក់ (Student Submission Detail)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញឯកសារដែលនិស្សិតបានដាក់ (រូបភាព Thumbnail ឬទំព័រទី ១ របស់ PDF), ស្ថានភាព, មតិយោបល់របស់គ្រូ និងពិន្ទុផ្លូវការ (លុះត្រាតែគ្រូបាន Publish `graded` ប៉ុណ្ណោះ បើនៅ Draft វាបង្ហាញ `Waiting for Teacher Grading`)។
- **ធាតុផ្សំ UI៖** កាតព័ត៌មានការដាក់ស្នើ (Version #, Time, Status), ប្រអប់ Inline File Preview, ប៊ូតុង ៣ សកម្មភាពឯកសារ, កាត Grade & Feedback។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ផ្លាក `Latest` vs `Archived`, ស្ថានភាព `Waiting for Teacher Grading` vs `Published Score`។
- **ប៊ូតុង និងសកម្មភាព៖** `Full Screen` (`SubmissionFileViewerActivity`), `Open File` (Intent Open), `Download` (`SubmissionFileViewerHelper.saveToDownloads`)។
- **ប្រភពទិន្នន័យ៖** Student Submission Detail API (`StudentAssignmentController::submissionDetail`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/submissions/{submissionId}`
- **តារាង Database ពាក់ព័ន្ធ៖** `assignment_submissions`, `assignments`, `courses`, `students`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `SubmitAssignmentActivity`, `StudentAssessmentFragment` → `SubmissionFileViewerActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"និស្សិតអាចមើល Version នីមួយៗនៃកិច្ចការដែលខ្លួនបានដាក់ មើលឯកសារ PDF/រូបភាព ទាញយក និងមើលពិន្ទុ/Feedback ដែលគ្រូបាន Publish។"*

---

### 23. `QuizDetailActivity` (`student/quiz/QuizDetailActivity.java` • Layout: `activity_quiz_detail.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័របញ្ជី Quizzes របស់និស្សិត (Quizzes List)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញបញ្ជី Quiz ទាំងអស់របស់និស្សិត រួមទាំងចំនួនដងដែលអាចធ្វើបាន (`Attempts`), ម៉ោងកំណត់, និងពិន្ទុ។
- **ធាតុផ្សំ UI៖** Filter តាមមុខវិជ្ជា/ស្ថានភាព, `RecyclerView` កាត Quiz, Empty/Error State។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ផ្លាក `Open`, `In Progress`, `Completed`, `Reopened`, `Max Attempts Reached`, `Closed`។
- **ប៊ូតុង និងសកម្មភាព៖** ចុចលើ Quiz ណាមួយដើម្បីបើក `TakeQuizActivity` ដោយភ្ជាប់ `quiz_id`។
- **ប្រភពទិន្នន័យ៖** Student Quizzes API (`StudentQuizController::index`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/quizzes`
- **តារាង Database ពាក់ព័ន្ធ៖** `quizzes`, `quiz_attempts`, `assessment_student_overrides`, `quiz_student_overrides`, `courses`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `StudentDashboardFragment`, `AllFeaturesActivity`, `NotificationFragment`, `RecommendationDetailActivity` → `TakeQuizActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"បង្ហាញ Quiz ទាំងអស់ដែលបើកឱ្យនិស្សិតធ្វើ រួមទាំងចំនួនដងនៅសល់ និងសិទ្ធិ Extra Attempts ពីប្រព័ន្ធ Universal Reopen។"*

---

### 24. `TakeQuizActivity` (`student/quiz/TakeQuizActivity.java` • Layout: `activity_take_quiz.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រធ្វើ Quiz និងពិនិត្យប្រវត្តិ Attempt (Take Quiz & Attempt History)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** អនុញ្ញាតឱ្យនិស្សិតមើលព័ត៌មាន Quiz + ប្រវត្តិ Attempt ចាស់ៗទាំងអស់ ចាប់ផ្តើមធ្វើ Quiz (`Start Quiz` / `Resume`) រក្សាទុកចម្លើយដោយស្វ័យប្រវត្តិ (`Save Answers`), ដាក់ស្នើ Quiz (`Submit Quiz`), និងពិនិត្យមើលចម្លើយ Attempt នីមួយៗ (`Review Attempt`) ដោយមិនបាត់បង់ពិន្ទុចាស់ឡើយ។
- **ធាតុផ្សំ UI៖** កាតសេចក្តីណែនាំ Quiz & Universal Reopen Badge, នាឡិការាប់ថយក្រោយ (Timer), បញ្ជីសំណួរ (`MCQ`, `True/False`, `Short Answer`), កាតប្រវត្តិ Attempt ចាស់ៗ, ប៊ូតុង Start / Submit / Review។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Loading, Attempt Limit / Locked state, Active Quiz state, Review Answer state (ពណ៌បៃតងត្រូវ / ក្រហមខុស / Pending Teacher Grade), និង Error notice (`404`/`403`)។
- **ប៊ូតុង និងសកម្មភាព៖**
  - `Start / Resume Quiz`: ហៅ `POST /api/student/quizzes/{id}/start`។
  - ជ្រើសរើស/វាយចម្លើយ: រក្សាទុកតាម `POST /api/student/quiz-attempts/{attemptId}/save-answers`។
  - `Submit Quiz`: ហៅ `POST /api/student/quiz-attempts/{attemptId}/submit`។
  - ចុច Review Attempt ចាស់: ហៅ `GET /api/student/quiz-attempts/{attemptId}/review`។
- **ប្រភពទិន្នន័យ៖** Student Quiz Detail, Start, Save, Submit & Review APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/quizzes/{id}`, `POST /api/student/quizzes/{id}/start`, `POST /api/student/quiz-attempts/{attemptId}/save-answers`, `POST /api/student/quiz-attempts/{attemptId}/submit`, `GET /api/student/quiz-attempts/{attemptId}/review`
- **តារាង Database ពាក់ព័ន្ធ៖** `quizzes`, `quiz_questions`, `quiz_attempts`, `quiz_answers`, `assessment_student_overrides`, `quiz_student_overrides`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `QuizDetailActivity`, `StudentAssessmentFragment` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រនេះជាប្រព័ន្ធ Quiz ពេញលេញ៖ នៅពេលគ្រូបន្ថែម Extra Attempts និស្សិតអាចចុចធ្វើ Attempt ថ្មី (ឧ. Attempt #2) ខណៈ Attempt #1 និងចម្លើយចាស់នៅរក្សាទុកទាំងស្រុងសម្រាប់ពិនិត្យមើលឡើងវិញ។"*

---

### 25. `ExamDetailActivity` (`student/ExamDetailActivity.java` • Layout: `activity_exam_detail.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រកាលវិភាគ និងលទ្ធផលប្រឡងនិស្សិត (Exam Schedule & Results)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញបញ្ជីការប្រឡង (`Midterm`, `Final`, `Practical`) របស់និស្សិត កាលបរិច្ឆេទ បន្ទប់ប្រឡង និងលទ្ធផលពិន្ទុប្រសិនបើគ្រូបាន Publish។
- **ធាតុផ្សំ UI៖** Filter tabs, `RecyclerView` កាតប្រឡង, Empty State។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ផ្លាក `Upcoming`, `Completed`, `Score Published`, `Pending Results`។
- **ប៊ូតុង និងសកម្មភាព៖** ចុចលើកាតប្រឡងដើម្បីមើលព័ត៌មានលម្អិត (កាលវិភាគ បន្ទប់ ពិន្ទុ និងកំណត់សម្គាល់គ្រូ)។
- **ប្រភពទិន្នន័យ៖** Student Exams API (`StudentPortalController::exams`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/exams`
- **តារាង Database ពាក់ព័ន្ធ៖** `exams`, `exam_results`, `courses`, `course_offerings`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `StudentDashboardFragment`, `NotificationFragment`, `RecentActivitiesActivity` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"បង្ហាញការប្រឡងផ្លូវការ (Midterm/Final) និងពិន្ទុប្រឡងដែលគ្រូបានបញ្ចូល និង Publish រួច។"*

---

### 26. `LearningAnalyticsFragment` (`student/LearningAnalyticsFragment.java` • Layout: `activity_fragment_learning_analytics.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងវិភាគការសិក្សានិស្សិត (Learning Analytics Tab)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញក្រាហ្វវិភាគនិន្នាការសមត្ថភាពសិក្សារបស់និស្សិត រួមផ្សំទិន្នន័យវត្តមាន កិច្ចការ Quizzes និងប្រឡង។
- **ធាតុផ្សំ UI៖** Filter ពេលវេលា/មុខវិជ្ជា, LineChart និន្នាការសិក្សា, កាតម៉ែត្រសកម្មភាពទាំង ៤។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Empty chart message ពេលមិនទាន់មានទិន្នន័យសិក្សា។
- **ប៊ូតុង និងសកម្មភាព៖** ប្តូរ Period (`Weekly`, `Monthly`, `Semester`) ដើម្បីគណនាក្រាហ្វថ្មី។
- **ប្រភពទិន្នន័យ៖** Dashboard, Trend, Attendance, Assignments, Quizzes, Exams APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/dashboard`, `GET /api/learning-analytics/trend`, `GET /api/student/attendance`, `GET /api/student/assignments`, `GET /api/student/quizzes`, `GET /api/student/exams`
- **តារាង Database ពាក់ព័ន្ធ៖** `learning_analytics`, `attendance_details`, `assignment_submissions`, `quiz_attempts`, `exam_results`, `grades`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Bottom Tab ៣ ក្នុង `StudentMainActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទោះបីតារាង `learning_analytics` មិនទាន់មានជួរដេកស្វ័យប្រវត្តិក៏ដោយ Laravel `StudentAnalyticsCalculator` គណនានិន្នាការផ្ទាល់ចេញពីទិន្នន័យវត្តមាន កិច្ចការ និងប្រឡងពិតប្រាកដ។"*

---

### 27. `LearningAnalyticsActivity` (`student/analytics/LearningAnalyticsActivity.java` • Layout: `activity_learning_analytics.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រវិភាគសមត្ថភាពសិក្សាស៊ីជម្រៅ (Deep Learning Analytics)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញផ្ទាំងវិភាគស៊ីជម្រៅសម្រាប់ Student Module រួមទាំងភាពខ្លាំង និងចំណុចខ្សោយតាមមុខវិជ្ជា។
- **ធាតុផ្សំ UI៖** Header, ក្រាហ្វសមត្ថភាព, កាតភាគរយធាតុផ្សំពិន្ទុ។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** `Strong`, `Needs Attention`, `Insufficient Data` state។
- **ប៊ូតុង និងសកម្មភាព៖** ប៊ូតុងត្រឡប់ក្រោយ តម្រងរយៈពេល។
- **ប្រភពទិន្នន័យ៖** Student Module Analytics API។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/module/analytics`
- **តារាង Database ពាក់ព័ន្ធ៖** `learning_analytics`, `attendance_details`, `assignment_submissions`, `quiz_attempts`, `exam_results`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `StudentDashboardFragment` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"បង្ហាញការវិភាគការសិក្សាកម្រិតម៉ូឌុល ដើម្បីប្រាប់និស្សិតអំពីទំនាក់ទំនងរវាងវត្តមាន និងពិន្ទុកិច្ចការ។"*

---

### 28. `LearningProgressDetailActivity` (`student/LearningProgressDetailActivity.java` • Layout: `activity_learning_progress_detail.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រវឌ្ឍនភាពសិក្សាលម្អិត (Learning Progress Detail)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញក្រាហ្វ និងភាគរយនៃការបញ្ចប់កិច្ចការ វត្តមាន និង Quiz ដោយអាចត្រងតាមឆមាស។
- **ធាតុផ្សំ UI៖** Semester Spinner, LineChart, Progress Cards។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ភាគរយវឌ្ឍនភាពតាមសមាសភាពនីមួយៗ។
- **ប៊ូតុង និងសកម្មភាព៖** ប្តូរឆមាស, ប៊ូតុងត្រឡប់ក្រោយ។
- **ប្រភពទិន្នន័យ៖** Student Dashboard Filtered & Analytics Trend APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/dashboard?semester_id=...`, `GET /api/learning-analytics/trend`
- **តារាង Database ពាក់ព័ន្ធ៖** `learning_analytics`, `assignments`, `assignment_submissions`, `quizzes`, `quiz_attempts`, `semesters`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** ចូលពីផ្ទាំង Dashboard/Analytics → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ផ្តល់ក្រាហ្វវឌ្ឍនភាពសិក្សាតាមឆមាស ជួយនិស្សិតតាមដានការរីកចម្រើនផ្ទាល់ខ្លួន។"*

---

### 29. `OverallPerformanceActivity` (`student/OverallPerformanceActivity.java` • Layout: `activity_overall_performance.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រលទ្ធផលសិក្សារួម (Overall Performance)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បូកសរុបពិន្ទុរួមពីគ្រប់ប្រភព (Attendance, Assignments, Quizzes, Exams) និងបង្ហាញតំណទៅទំព័រលម្អិតតាមផ្នែក។
- **ធាតុផ្សំ UI៖** កាតសរុប GPA/Score, Semester Selector, កាតម៉ែត្រ ៤ ផ្នែក និងបញ្ជីមុខវិជ្ជា។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** កម្រិតសមត្ថភាព និង Empty state។
- **ប៊ូតុង និងសកម្មភាព៖** ចុចចូល `AttendanceDetailActivity`, `AssessmentDetailActivity`, `QuizDetailActivity`, `RecommendationDetailActivity`, `CourseDetailActivity` ឬ `StudentMainActivity`។
- **ប្រភពទិន្នន័យ៖** Filtered Student Dashboard API។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/dashboard?semester_id=...`
- **តារាង Database ពាក់ព័ន្ធ៖** `grades`, `gpa_records`, `attendance_details`, `assignment_submissions`, `quiz_attempts`, `exam_results`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `AllFeaturesActivity` → ទៅកាន់ទំព័រលម្អិតតាមប្រភេទ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"បង្ហាញលទ្ធផលសិក្សាទូទៅក្នុងឆមាសដែលបានជ្រើស និងភ្ជាប់ទៅទំព័រលម្អិតនៃសកម្មភាពនីមួយៗ។"*

---

### 30. `PredictionFragment` (`student/PredictionFragment.java` • Layout: `activity_fragment_prediction.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងការទស្សន៍ទាយ និងហានិភ័យនិស្សិត (Performance Prediction Tab)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញពិន្ទុទស្សន៍ទាយ (`Predicted Score`) កម្រិតហានិភ័យ (`Risk Level`) និងកត្តាជះឥទ្ធិពលសម្រាប់និស្សិត។
- **ធាតុផ្សំ UI៖** កាតពិន្ទុទស្សន៍ទាយ, Risk Status Card, LineChart និន្នាការទស្សន៍ទាយ, ប៊ូតុងចូលលម្អិត។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** `Low`, `Medium`, `High Risk` ឬ `Not available yet` ពេលមិនទាន់មានកំណត់ត្រាពិន្ទុ/វត្តមានគ្រប់គ្រាន់។
- **ប៊ូតុង និងសកម្មភាព៖** បើក `PredictionDetailActivity`, `RiskAnalysisActivity`, ឬ `RecommendationDetailActivity`។
- **ប្រភពទិន្នន័យ៖** Student Dashboard API។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/dashboard`
- **តារាង Database ពាក់ព័ន្ធ៖** `predictions`, `risk_assessments`, `recommendations`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Bottom Tab ៤ ក្នុង `StudentMainActivity` → `PredictionDetailActivity`, `RiskAnalysisActivity`, `RecommendationDetailActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ផ្ទាំងនេះបង្ហាញលទ្ធផលវិភាគហានិភ័យសិក្សា (Early Warning) ដែលជួយឱ្យនិស្សិតដឹងមុនប្រសិនបើមានហានិភ័យធ្លាក់ពិន្ទុ ឬអវត្តមានច្រើន។"*

---

### 31. `PredictionDetailActivity` (`student/PredictionDetailActivity.java` • Layout: `activity_prediction_detail.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រលម្អិតការទស្សន៍ទាយលទ្ធផល (Prediction Detail)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញលម្អិតការទស្សន៍ទាយពិន្ទុ និងកម្រិតទំនុកចិត្តតាមមុខវិជ្ជានីមួយៗ។
- **ធាតុផ្សំ UI៖** កាតពិន្ទុទស្សន៍ទាយសរុប Confidence badge និងបញ្ជីការទស្សន៍ទាយតាមមុខវិជ្ជា។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** កម្រិត Risk/Confidence និង Empty state បើគ្មានទិន្នន័យទស្សន៍ទាយ។
- **ប៊ូតុង និងសកម្មភាព៖** ប៊ូតុង Back។
- **ប្រភពទិន្នន័យ៖** Student Predictions & Risk API (`StudentModuleController::predictionsAndRisk`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/module/predictions-risk`
- **តារាង Database ពាក់ព័ន្ធ៖** `predictions`, `risk_assessments`, `courses`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `StudentDashboardFragment` ឬ `PredictionFragment` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"បង្ហាញលម្អិតពិន្ទុដែលព្យាករណ៍សម្រាប់ប្រឡងបញ្ចប់ឆមាសតាមមុខវិជ្ជា និងហេតុផលដែលជះឥទ្ធិពល។"*

---

### 32. `RiskAnalysisActivity` (`student/RiskAnalysisActivity.java` • Layout: `activity_risk_analysis.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រវិភាគហានិភ័យសិក្សា (Risk Analysis)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញកត្តាហានិភ័យជាក់លាក់ (ឧ. វត្តមានទាប ពិន្ទុ Quiz/Assignment ទាប) និងដំបូន្មានកែតម្រូវ។
- **ធាតុផ្សំ UI៖** Risk Level Banner, បញ្ជីកត្តាហានិភ័យ (Risk Factors), ស្ថានភាពគ្មានហានិភ័យ / មិនទាន់មានទិន្នន័យ។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ផ្លាកពណ៌ (`Low Risk`, `Medium Risk`, `High Risk`)។
- **ប៊ូតុង និងសកម្មភាព៖** ប៊ូតុង Back។
- **ប្រភពទិន្នន័យ៖** Student Dashboard API។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/dashboard`
- **តារាង Database ពាក់ព័ន្ធ៖** `risk_assessments`, `attendance_details`, `grades`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `StudentDashboardFragment`, `PredictionFragment`, `AllFeaturesActivity`, `NavigationMenuActivity`, `NotificationFragment` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ពន្យល់មូលហេតុដែលនិស្សិតត្រូវបានវាយតម្លៃកម្រិតហានិភ័យ ដើម្បីកុំឱ្យនិស្សិតងឿងឆ្ងល់ និងអាចកែលម្អទាន់ពេលវេលា។"*

---

### 33. `RecommendationFragment` (`student/RecommendationFragment.java` • Layout: `fragment_recommendations.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងបញ្ជីការណែនាំទូទៅ (Recommendations List Fragment)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញបញ្ជីការណែនាំពីគ្រូ ឬប្រព័ន្ធជូននិស្សិត។
- **ធាតុផ្សំ UI៖** `RecyclerView` កាតការណែនាំ, Empty State។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** `Pending` / `Completed` / `Priority` badges។
- **ប៊ូតុង និងសកម្មភាព៖** ចុចលើការណែនាំដើម្បីបើក `RecommendationDetailActivity`។
- **ប្រភពទិន្នន័យ៖** Recommendations API។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/recommendations`
- **តារាង Database ពាក់ព័ន្ធ៖** `recommendations`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** ភ្ជាប់ជាមួយ `RecommendationDetailActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"បង្ហាញការណែនាំសិក្សាដែលផ្ញើមកពីគ្រូបង្រៀន ឬប្រព័ន្ធវិភាគ។"*

---

### 34. `StudentRecommendationFragment` (`student/StudentRecommendationFragment.java` • Layout: `fragment_student_recommendations.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងផែនការណែនាំការសិក្សារបស់និស្សិត (Student Recommendations)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញការណែនាំជាកាតមានតម្រង អនុញ្ញាតឱ្យនិស្សិតអានព័ត៌មានលម្អិត បើកមុខវិជ្ជា បើកផែនការលម្អិត (`RecommendationDetailActivity`), និងគូសចំណាំថា *"បានបញ្ចប់ (Completed)"*។ *(បានកែលម្អក្នុង Audit នេះដោយបន្ថែមប៊ូតុង `"Full Action Plan"` ទៅ `RecommendationDetailActivity`។)*
- **ធាតុផ្សំ UI៖** កាតសង្ខេប (`Total`, `Pending`, `Completed`), Filter chips, `EditText` ស្វែងរក, `RecyclerView` កាតណែនាំ។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Priority (`High`, `Medium`, `Low`), Status (`Pending`, `Completed`)។
- **ប៊ូតុង និងសកម្មភាព៖**
  - ចុចកាត/Review: បើកព័ត៌មានលម្អិត + ប៊ូតុង `Open Course Details` (`CourseDetailActivity`) និង `Full Action Plan` (`RecommendationDetailActivity`)។
  - `Mark Completed`: ហៅ `PUT /api/recommendations/{id}` ធ្វើបច្ចុប្បន្នភាពស្ថានភាពជា `completed` ក្នុង DB។
- **ប្រភពទិន្នន័យ៖** Recommendations & Dashboard APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/recommendations`, `PUT /api/recommendations/{id}`, `GET /api/student/dashboard`
- **តារាង Database ពាក់ព័ន្ធ៖** `recommendations`, `courses`, `students`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Drawer ក្នុង `StudentMainActivity` → `CourseDetailActivity`, `RecommendationDetailActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"និស្សិតអាចតាមដានដំបូន្មានដែលគ្រូផ្តល់ឱ្យ ស្វែងរកតាមមុខវិជ្ជា ចុចចូលមើលមុខវិជ្ជាពាក់ព័ន្ធ ឬប្តូរស្ថានភាពជា Completed។"*

---

### 35. `RecommendationDetailActivity` (`student/RecommendationDetailActivity.java` • Layout: `activity_recommendation_detail.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រផែនការណែនាំលម្អិត (Recommendation Action Plan Detail)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញផែនការណែនាំជាជំហានៗតាមឆមាស និងតំណផ្ទាល់ទៅមើល Quiz, វត្តមាន, Analytics និងកាលវិភាគសិក្សា។
- **ធាតុផ្សំ UI៖** Header, Semester Selector, កាតការណែនាំលម្អិត Dynamic & Action Cards។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Status badges + Priority tags។
- **ប៊ូតុង និងសកម្មភាព៖** `View Performance Details`, `View Quiz Details` (`QuizDetailActivity`), `View Attendance` (`AttendanceDetailActivity`), `View Schedule/Resources`, `Track Progress`។
- **ប្រភពទិន្នន័យ៖** Filtered Dashboard, Semesters, Recommendations APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/dashboard`, `GET /api/semesters`, `GET /api/recommendations`, `PUT /api/recommendations/{id}`
- **តារាង Database ពាក់ព័ន្ធ៖** `recommendations`, `semesters`, `courses`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `StudentRecommendationFragment`, `PredictionFragment`, `NotificationFragment`, `NavigationMenuActivity` → `QuizDetailActivity`, `AttendanceDetailActivity`, `CourseDetailActivity`, `StudentMainActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"មិនត្រឹមតែអានដំបូន្មានប៉ុណ្ណោះទេ ទំព័រនេះភ្ជាប់ប៊ូតុងឱ្យនិស្សិតចុចចូលធ្វើ Quiz ឬពិនិត្យវត្តមានភ្លាមៗតែម្តង។"*

---

### 36. `SmartRecommendationsActivity` (`student/recommendations/SmartRecommendationsActivity.java` • Layout: `activity_smart_recommendations.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រដំបូន្មានឆ្លាតវៃតាមការវិភាគ (Smart Recommendations)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញការណែនាំឆ្លាតវៃដែលបង្កើតចេញពីស្ថានភាពសិក្សាបច្ចុប្បន្នរបស់និស្សិត។
- **ធាតុផ្សំ UI៖** បញ្ជីកាតដំបូន្មានឆ្លាតវៃ និង Empty State។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Category & Priority tags។
- **ប៊ូតុង និងសកម្មភាព៖** ប៊ូតុងត្រឡប់ក្រោយ។
- **ប្រភពទិន្នន័យ៖** Student Module Recommendations API។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/module/recommendations`
- **តារាង Database ពាក់ព័ន្ធ៖** `recommendations`, `risk_assessments`, `learning_analytics`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `StudentDashboardFragment` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ផ្តល់ការណែនាំផ្នែកសិក្សាដោយស្វ័យប្រវត្តិផ្អែកលើទិន្នន័យសិក្សារបស់និស្សិត។"*

---

### 37. `ReportFragment` (`student/ReportFragment.java` • Layout: `activity_learning_report.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងរបាយការណ៍សិក្សានិស្សិត (Student Report Tab)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញរបាយការណ៍សិក្សារួមតាមឆមាស និងអនុញ្ញាតឱ្យបង្កើត/ទាញយកឯកសារ **PDF** នៃរបាយការណ៍ការសិក្សាផ្ទាល់ខ្លួន។
- **ធាតុផ្សំ UI៖** Header, Semester Spinner, កាតសង្ខេប (GPA, Attendance, Courses), ប៊ូតុង Export PDF Report។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Loading និង PDF Export Confirmation។
- **ប៊ូតុង និងសកម្មភាព៖** ជ្រើសឆមាស ហើយចុច Export PDF ដោយប្រើ Android `PdfDocument`។
- **ប្រភពទិន្នន័យ៖** Dashboard Filtered & Semesters APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/dashboard?semester_id=...`, `GET /api/semesters`
- **តារាង Database ពាក់ព័ន្ធ៖** `students`, `gpa_records`, `grades`, `attendance_details`, `semesters`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Drawer ក្នុង `StudentMainActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"និស្សិតអាចទាញយករបាយការណ៍លទ្ធផលសិក្សាផ្ទាល់ខ្លួនជា PDF ដោយត្រងតាមឆមាសបាន។"*

---

### 38. `LearningReportActivity` (`student/LearningReportActivity.java` • Layout: `activity_learning_report.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័ររបាយការណ៍សិក្សាពេញលេញ និងនាំចេញ PDF (Learning Report Activity)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** ជា Activity ដាច់ដោយឡែកសម្រាប់មើល និងបង្កើត PDF របាយការណ៍សិក្សានិស្សិតពេលបើកពី Dashboard ឬ Notification។
- **ធាតុផ្សំ UI៖** Semester Spinner, តារាងលទ្ធផលមុខវិជ្ជា, ប៊ូតុង Export PDF។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** PDF generation feedback ក្នុង Downloads។
- **ប៊ូតុង និងសកម្មភាព៖** Back, Semester Filter, Export PDF។
- **ប្រភពទិន្នន័យ៖** Dashboard Filtered & Semesters APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/dashboard?semester_id=...`, `GET /api/semesters`
- **តារាង Database ពាក់ព័ន្ធ៖** `students`, `gpa_records`, `grades`, `attendance_details`
- **ការតភ្ជាប់ទំព័ន្ធ (Navigation)៖** មកពី `AllFeaturesActivity`, `NavigationMenuActivity`, `NotificationFragment` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"គាំទ្រការបង្កើតឯកសារ PDF ដែលមានពុម្ពអក្សរខ្មែរ/អង់គ្លេសត្រឹមត្រូវចេញពីទិន្នន័យឆមាសពិតប្រាកដ។"*

---

### 39. `NotificationFragment` (`student/NotificationFragment.java` • Layout: `activity_fragment_notifications.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងការជូនដំណឹងរបស់និស្សិត (Student Notifications)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញសារជូនដំណឹងផ្ទាល់ខ្លួនរបស់និស្សិត (`All`, `Unread`, `Important`) និងអនុញ្ញាតឱ្យចុចទៅកាន់ទំព័រពាក់ព័ន្ធដោយស្វ័យប្រវត្តិ។
- **ធាតុផ្សំ UI៖** Tabs (`All`, `Unread`, `Important`), ប៊ូតុង `Mark All Read`, `RecyclerView`, ProgressBar, Empty State។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ចំណុច Unread Dot, ចំនួនជូនដំណឹងលើ Tab, Important highlights (Security/Risk/Exam/Recommendation)។
- **ប៊ូតុង និងសកម្មភាព៖**
  - ចុច Notification: កត់ត្រា `PATCH /api/notifications/{id}/read` រួចបើកទំព័រពាក់ព័ន្ធ (`QuizDetailActivity`, `ExamDetailActivity`, `AttendanceDetailActivity`, `AssessmentDetailActivity`, `CourseDetailActivity`, `RiskAnalysisActivity`, ឬ `RecommendationDetailActivity`)។
  - `btnMarkAllNotificationsRead`: ហៅ `POST /api/notifications/read-all`។
- **ប្រភពទិន្នន័យ៖** Notifications API (`NotificationController`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/notifications`, `PATCH /api/notifications/{id}/read`, `POST /api/notifications/read-all`
- **តារាង Database ពាក់ព័ន្ធ៖** `notifications`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Drawer ក្នុង `StudentMainActivity` → ទំព័រពាក់ព័ន្ធតាមប្រភេទជូនដំណឹង។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ពេលមានសារជូនដំណឹងអំពីកិច្ចការ Quiz ឬហានិភ័យ និស្សិតចុចលើសារនោះភ្លាមនឹងរត់ទៅទំព័រមុខងារនោះដោយស្វ័យប្រវត្តិ។"*

---

### 40. `ProfileFragment` (`student/ProfileFragment.java` • Layout: `activity_fragment_profile.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងប្រវត្តិរូបរបស់និស្សិត (Student Profile)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញអត្តសញ្ញាណនិស្សិត (`Student Code`, `Faculty`, `Year Level`, `Academic Year`) និងម៉ឺនុយគណនី។
- **ធាតុផ្សំ UI៖** Header, ផ្លាកមហាវិទ្យាល័យ ឆ្នាំសិក្សា និងបញ្ជីកាត ៦ (`Personal Info`, `Academic Info`, `Change Password`, `App Settings`, `Help & Support`, `Logout`)។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Dialog បង្ហាញព័ត៌មានផ្ទាល់ខ្លួន/ការសិក្សា, Logout Confirmation។
- **ប៊ូតុង និងសកម្មភាព៖**
  - `cardPersonalInfo` / `cardAcademicInfo`: បង្ហាញព័ត៌មានពិតពី API។
  - `cardChangePassword` → `ChangePasswordActivity`។
  - `cardAppSettings` → `AdminAppearanceActivity` (ប្តូរភាសា ខ្មែរ/អង់គ្លេស និង Theme)។
  - `cardLogout`: ហៅ `POST /api/logout` សម្អាត `SessionManager` → `LoginActivity`។
- **ប្រភពទិន្នន័យ៖** Profile API & `SessionManager`។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/profile`, `POST /api/logout`
- **តារាង Database ពាក់ព័ន្ធ៖** `users`, `students`, `departments`, `programs`, `classes`, `academic_years`, `personal_access_tokens`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Bottom Tab ៥ ក្នុង `StudentMainActivity` → `ChangePasswordActivity`, `AdminAppearanceActivity`, `LoginActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"និស្សិតអាចពិនិត្យព័ត៌មានសិក្សា ផ្លាស់ប្តូរពាក្យសម្ងាត់ ប្តូរភាសាខ្មែរ/អង់គ្លេស ឬចាកចេញពីប្រព័ន្ធដោយសុវត្ថិភាព។"*

---

### 41. `StudentSettingsFragment` (`student/StudentSettingsFragment.java` • Layout: `fragment_student_settings.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងការកំណត់របស់និស្សិត (Student Settings)
- **តួនាទីប្រើប្រាស់៖** Student
- **គោលបំណង និងមុខងារសំខាន់៖** ផ្តល់ជម្រើសកំណត់គណនី សុវត្ថិភាព និងរូបរាងកម្មវិធីសម្រាប់និស្សិត។
- **ធាតុផ្សំ UI៖** កាត ៤ (`Change Password`, `Notification Preferences`, `Appearance & Language`, `About SLAS Student Portal`)។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Dialog ព័ត៌មានការជូនដំណឹង និងព័ត៌មានកំណែប្រព័ន្ធ។
- **ប៊ូតុង និងសកម្មភាព៖**
  - `cardStudentChangePassword` → `ChangePasswordActivity`
  - `cardStudentAppearance` → `AdminAppearanceActivity` (ប្តូរ Theme & ភាសា)
- **ប្រភពទិន្នន័យ៖** App SharedPreferences។
- **ចំណុចតភ្ជាប់ API៖** គ្មាន។
- **តារាង Database ពាក់ព័ន្ធ៖** គ្មាន។
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Drawer ក្នុង `StudentMainActivity` → `ChangePasswordActivity`, `AdminAppearanceActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"សម្រាប់ឱ្យនិស្សិតប្តូរភាសា (ខ្មែរ/អង់គ្លេស) និងពាក្យសម្ងាត់ផ្ទាល់ខ្លួន។"*

---

# ក្រុមទី ៣៖ តួនាទីគ្រូបង្រៀន (Teacher Role)

### 42. `TeacherMainActivity` (`teacher/TeacherMainActivity.java` • Layout: `activity_teacher_main.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងមេរបស់គ្រូបង្រៀន (Teacher Portal Shell)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** ស៊ុមមេគ្រប់គ្រង Bottom Navigation, Side Drawer (`nav_teacher_drawer_menu.xml`), Fragment container និងការចាកចេញរបស់គ្រូ។
- **ធាតុផ្សំ UI៖** `DrawerLayout`, Navigation Drawer items, `BottomNavigationView` (`teacher_bottom_menu.xml`), Fragment container។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Bottom Tabs ៥ (Dashboard, Students, Courses, Reports, Profile) និង Drawer គ្រប់គ្រង (Assessments, Scores, Attendance, Analytics, Risk, Recommendations, Notifications, Logout)។
- **ប៊ូតុង និងសកម្មភាព៖** ប្តូរ Fragment តាម Tab/Drawer និង Logout ហៅ `POST /api/logout`។
- **ប្រភពទិន្នន័យ៖** `SessionManager`, Laravel Logout API។
- **ចំណុចតភ្ជាប់ API៖** `POST /api/logout`
- **តារាង Database ពាក់ព័ន្ធ៖** `users`, `teachers`, `personal_access_tokens`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `LoginActivity` / `SplashActivity` → ផ្ទុកគ្រប់ Teacher Fragments; Logout → `LoginActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"នេះគឺជា Portal មេរបស់គ្រូបង្រៀន ដែលគ្រប់គ្រងការហៅឈ្មោះ ដាក់កិច្ចការ បង្កើត Quiz ដាក់ពិន្ទុ និងតាមដាននិស្សិតមានហានិភ័យ។"*

---

### 43. `TeacherDashboardFragment` (`teacher/TeacherDashboardFragment.java` • Layout: `fragment_teacher_dashboard.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងទំព័រដើមគ្រូបង្រៀន (Teacher Dashboard)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញម៉ែត្រសង្ខេបទាំង ៤ (Total Students, My Courses, At-Risk Students, Pending Scores), តម្រង Dynamic តាមឆ្នាំសិក្សា/ឆមាស/ថ្នាក់ និងក្រាហ្វ Engagement របស់និស្សិត។
- **ធាតុផ្សំ UI៖** កាត KPI ៤, Spinners តម្រង Cascading, BarChart/Chart ប្រៀបធៀបសកម្មភាពសិក្សា ៤។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Dynamic Scope Chips និងចំនួន KPI ពិតតាម Offerings ដែលគ្រូបង្រៀន។
- **ប៊ូតុង និងសកម្មភាព៖** ចុចលើកាត KPI នីមួយៗនាំទៅ `StudentListFragment`, `CourseManagementFragment`, `PredictionRiskFragment`, ឬ `ManageScoresFragment`។
- **ប្រភពទិន្នន័យ៖** Teacher Dashboard & Filters API (`TeacherDashboardService`, `TeacherAnalyticsService`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teacher/dashboard/summary`, `GET /api/teacher/dashboard/filters`, `GET /api/teacher/analytics`, `GET /api/academic-years`, `GET /api/semesters`, `GET /api/classes`
- **តារាង Database ពាក់ព័ន្ធ៖** `teachers`, `course_offerings`, `course_offering_teachers`, `course_enrollments`, `assignment_submissions`, `attendance`, `risk_assessments`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** ផ្ទាំងលំនាំដើមក្នុង `TeacherMainActivity` → ចូលទៅ `StudentListFragment`, `CourseManagementFragment`, `PredictionRiskFragment`, `ManageScoresFragment`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រ Dashboard គ្រូបង្ហាញតែទិន្នន័យមុខវិជ្ជា និងថ្នាក់ដែលគ្រូនោះត្រូវបានចាត់តាំងបង្រៀនប៉ុណ្ណោះ (Teacher Scoping Authorization)។"*

---

### 44. `StudentListFragment` (`teacher/StudentListFragment.java` • Layout: `fragment_teacher_students.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងបញ្ជីនិស្សិតក្នុងថ្នាក់បង្រៀន (My Students List)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញបញ្ជីនិស្សិតទាំងអស់ក្នុងថ្នាក់/មុខវិជ្ជាដែលគ្រូបង្រៀន ជាមួយការស្វែងរក តម្រង Year Level/Course និងកាត GPA/Attendance/Risk។
- **ធាតុផ្សំ UI៖** ប្រអប់ស្វែងរក តម្រង Year Level/Course, `RecyclerView` កាតនិស្សិត, Empty State។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ផ្លាក Year Level (`Y1`–`Y4`), ផ្លាក Risk (`Good`, `Attention`, `At Risk`), GPA និងវត្តមាន។
- **ប៊ូតុង និងសកម្មភាព៖** ចុចលើនិស្សិតបើក `StudentDetailActivity` ឬប៊ូតុងរបាយការណ៍បើក `StudentReportActivity`។
- **ប្រភពទិន្នន័យ៖** Teacher Students API (`TeacherModuleController::students`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teacher/students`
- **តារាង Database ពាក់ព័ន្ធ៖** `students`, `course_enrollments`, `course_offerings`, `gpa_records`, `attendance_details`, `risk_assessments`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Tab ក្នុង `TeacherMainActivity` → `StudentReportActivity` / `StudentDetailActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"គ្រូអាចរកនិស្សិតម្នាក់ៗតាមមុខវិជ្ជា ឬកម្រិតឆ្នាំ ហើយចុចមើលប្រវត្តិលម្អិត ឬសរសេរកំណត់សម្គាល់/ដំបូន្មាន។"*

---

### 45. `StudentDetailActivity` (`teacher/student/StudentDetailActivity.java` • Layout: `activity_student_detail.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រព័ត៌មានលម្អិតនិស្សិតសម្រាប់គ្រូ (Teacher's Student Detail)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញប្រវត្តិសិក្សា ពិន្ទុ ក្រាហ្វវិភាគ និងប្រវត្តិសកម្មភាពរបស់និស្សិតម្នាក់ក្នុងថ្នាក់គ្រូ។ *(បានកែលម្អក្នុង Audit នេះឱ្យទទួលទាំង `student` object និង `student_id` ដោយផ្ទាល់ពី ManageScores, Attendance និង PredictionRisk។)*
- **ធាតុផ្សំ UI៖** ក្បាលព័ត៌មាននិស្សិត (ឈ្មោះ កូដ ដេប៉ាតឺម៉ង់ អ៊ីមែល GPA), ផ្ទាំង Tabs ៤ (`Overview`, `Scores`, `Analytics`, `History`), `RecyclerView` ពិន្ទុ, `LineChart` វិភាគ។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Loading text លើក្រាហ្វ, ផ្លាកពិន្ទុ និងកំណត់ត្រា។
- **ប៊ូតុង និងសកម្មភាព៖** ប្តូរផ្ទាំង Tabs ទាំង ៤ និងប៊ូតុង Back។
- **ប្រភពទិន្នន័យ៖** Teacher Student Detail API (`TeacherModuleController::studentDetail`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teacher/students/{studentId}`
- **តារាង Database ពាក់ព័ន្ធ៖** `students`, `users`, `grades`, `assignment_submissions`, `quiz_attempts`, `exam_results`, `attendance_details`, `risk_assessments`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `StudentListFragment`, `ManageScoresFragment`, `AttendanceFragment`, `PredictionRiskFragment` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រនេះផ្តល់ទិដ្ឋភាព ៣៦០ ដឺក្រេអំពីនិស្សិតម្នាក់សម្រាប់គ្រូបង្រៀន ចាប់ពីព័ត៌មានផ្ទាល់ខ្លួន ពិន្ទុកិច្ចការ ដល់ក្រាហ្វការវិវឌ្ឍ។"*

---

### 46. `StudentReportActivity` (`teacher/student/StudentReportActivity.java` • Layout: `activity_student_report.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័ររបាយការណ៍ និងការផ្ញើដំបូន្មានទៅនិស្សិត (Teacher Student Report & Recommendations)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញរបាយការណ៍លទ្ធផលនិស្សិត និងអនុញ្ញាតឱ្យគ្រូសរសេរដំបូន្មាន/កំណត់សម្គាល់ (`Recommendation`) ផ្ញើទៅនិស្សិតម្នាក់នោះ។
- **ធាតុផ្សំ UI៖** កាតពិន្ទុ និងវត្តមាននិស្សិត, ប្រអប់សរសេរដំបូន្មាន ជម្រើស Priority និងប៊ូតុង Save Note / Send Recommendation។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Loading & Success Toast។
- **ប៊ូតុង និងសកម្មភាព៖** ហៅ `POST /api/teacher/recommendations` រក្សាទុកដំបូន្មានថ្មីក្នុង DB។
- **ប្រភពទិន្នន័យ៖** Teacher Student Detail & Recommendation APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teacher/students/{id}`, `POST /api/teacher/recommendations`
- **តារាង Database ពាក់ព័ន្ធ៖** `students`, `recommendations`, `teachers`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `StudentListFragment` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"គ្រូអាចពិនិត្យរបាយការណ៍និស្សិតរួចផ្ញើដំបូន្មានសិក្សាផ្ទាល់ខ្លួន ដែលនឹងបង្ហាញភ្លាមនៅក្នុង Portal និស្សិត។"*

---

### 47. `CourseManagementFragment` (`teacher/CourseManagementFragment.java` • Layout: `fragment_teacher_course_management.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងមុខវិជ្ជាបង្រៀនរបស់គ្រូ (Teacher My Courses)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញមុខវិជ្ជា និង Offerings ទាំងអស់ដែលគ្រូកំពុងបង្រៀន ជាមួយតម្រង Active/Archived, Search, Semester និង Sort។
- **ធាតុផ្សំ UI៖** Tabs (`Active`, `Archived`), ប្រអប់ស្វែងរក, Dropdown តម្រង ៣, `RecyclerView` កាតមុខវិជ្ជា។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ចំនួននិស្សិត, ចំនួន At-Risk, ចំនួន Pending Grading និងកាលវិភាគ។
- **ប៊ូតុង និងសកម្មភាព៖** ចុចលើមុខវិជ្ជាបើក **`TeacherCourseDetailActivity`** (បានកែលម្អពី AlertDialog មកជា Activity ថ្មីពេញលេញ) ឬចុចផ្លូវកាត់ទៅបញ្ជីនិស្សិត/វិភាគថ្នាក់។
- **ប្រភពទិន្នន័យ៖** Teacher Course Offerings API (`TeacherModuleController::courses`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teacher/courses`
- **តារាង Database ពាក់ព័ន្ធ៖** `course_offerings`, `course_offering_teachers`, `courses`, `classes`, `class_schedules`, `course_enrollments`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Bottom Tab ៣ ក្នុង `TeacherMainActivity` → `TeacherCourseDetailActivity`, `StudentListFragment`, `ClassAnalyticsFragment`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"គ្រូមើលឃើញតែមុខវិជ្ជាដែលខ្លួនទទួលបន្ទុក រួចអាចចុចចូលទំព័រលម្អិតដើម្បីមើលកាលវិភាគ បន្ទប់រៀន និស្សិត ឬក្រាហ្វវិភាគថ្នាក់។"*

---

### 48. `TeacherCourseDetailActivity` (`teacher/TeacherCourseDetailActivity.java` • Layout: `activity_teacher_course_detail.xml`) ★ *ទំព័រថ្មីក្នុង Audit*
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រព័ត៌មានលម្អិតមុខវិជ្ជាបង្រៀនរបស់គ្រូ (Teacher Course Offering Detail)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** ជំនួស AlertDialog អត្ថបទសាមញ្ញ ទៅជាផ្ទាំង Material Design ពេញលេញបង្ហាញលម្អិតមុខវិជ្ជាបង្រៀនរបស់គ្រូ និងផ្តល់ប៊ូតុងចូលមើលនិស្សិត និងវិភាគថ្នាក់។
- **ធាតុផ្សំ UI៖** Toolbar + Back button, Hero Summary Card (Course Code, Name, Student Count Pill), Offering & Schedule Card (Department, Semester, Schedule, Room), Bottom Action Bar។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Student Count pill, RoleGuard ការពារតួនាទី Teacher។
- **ប៊ូតុង និងសកម្មភាព៖**
  - `View Students`: បញ្ជូនលទ្ធផលត្រឡប់ទៅ `CourseManagementFragment` ដើម្បីបើក `StudentListFragment` ត្រងតាម `course_id`។
  - `Class Analytics`: បើក `ClassAnalyticsFragment` ត្រងតាម `course_id`។
- **ប្រភពទិន្នន័យ៖** ទទួល `Course` Serializable ពី `Teacher/CourseManagementFragment`។
- **ចំណុចតភ្ជាប់ API៖** ទិន្នន័យចេញពី `GET /api/teacher/courses`។
- **តារាង Database ពាក់ព័ន្ធ៖** `courses`, `course_offerings`, `class_schedules`, `departments`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `teacher/CourseManagementFragment` → ត្រឡប់លទ្ធផលទៅ `StudentListFragment` ឬ `ClassAnalyticsFragment`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រថ្មីនេះជំនួស AlertDialog ចាស់ ជួយឱ្យគ្រូមើលព័ត៌មានមុខវិជ្ជា កាលវិភាគ និងបន្ទប់រៀនបានស្អាត ហើយចុចចូលមើលបញ្ជីនិស្សិត ឬក្រាហ្វថ្នាក់ភ្លាម។"*

---

### 49. `TeacherAssessmentFragment` (`teacher/assessment/TeacherAssessmentFragment.java` • Layout: `fragment_teacher_assessment.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងគ្រប់គ្រងកិច្ចការ Quiz និងប្រឡងរបស់គ្រូ (Teacher Assessments Overview)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** មជ្ឈមណ្ឌលបង្កើត គ្រប់គ្រង ពិនិត្យកិច្ចការសិស្ស បើក **Universal Reopen Manager**, កែ Quizzes និងដាក់ពិន្ទុប្រឡង។
- **ធាតុផ្សំ UI៖** កាតម៉ែត្រ ៦ (`Assignments`, `Quizzes`, `Exams`, `Published`, `Drafts`, `Pending Grading`), Sub-tabs (`All/Assignments`, `Homework`, `Quizzes`, `Exams`, `Submissions`), ប៊ូតុង `+ Add Assessment`។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ផ្លាក `Published`, `Draft`, `Closed`, `Pending Grading`, Submitted versions count។
- **ប៊ូតុង និងសកម្មភាព៖**
  - `+ Add`: បើក `AddAssessmentActivity`
  - Assignment/Homework Action: ដាក់ពិន្ទុ (`EnterScoreActivity`), មើល/គ្រប់គ្រងសិទ្ធិ Reopen (`AssessmentReopenManagerActivity`), លុប (`DELETE /api/teacher/assignments/{id}`)
  - Quiz Action: បើក `QuizBuilderActivity`, Publish (`POST /api/teacher/quizzes/{id}/publish`), Reopen (`AssessmentReopenManagerActivity`)
  - Exam Action: បើក `TeacherExamDetailActivity`, កែ (`EditAssessmentActivity`), លុប (`DELETE /api/teacher/exams/{id}`)
  - Submission View: មើលឯកសារ (`SubmissionFileViewerActivity`) ឬដាក់ពិន្ទុ (`EnterScoreActivity`)។
- **ប្រភពទិន្នន័យ៖** Teacher Assessments Overview API (`TeacherModuleController::assessmentsOverview`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teacher/assessments/overview`, `DELETE /api/teacher/assignments/{id}`, `POST /api/teacher/quizzes/{id}/publish`, `DELETE /api/teacher/exams/{id}`, `POST /api/teacher/submissions/{id}/grade`
- **តារាង Database ពាក់ព័ន្ធ៖** `assignments`, `assignment_submissions`, `quizzes`, `quiz_questions`, `quiz_attempts`, `exams`, `exam_results`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Drawer ក្នុង `TeacherMainActivity` → `AddAssessmentActivity`, `QuizBuilderActivity`, `AssessmentReopenManagerActivity`, `EnterScoreActivity`, `SubmissionFileViewerActivity`, `TeacherExamDetailActivity`, `EditAssessmentActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"គ្រូបង្រៀនប្រើផ្ទាំងនេះដើម្បីគ្រប់គ្រងគ្រប់ប្រភេទវាយតម្លៃ (Assignment, Homework, Quiz, Exam) រួមទាំងបើក Universal Reopen Manager ដើម្បីផ្តល់សិទ្ធិដាក់យឺត ឬធ្វើឡើងវិញ។"*

---

### 50. `AddAssessmentActivity` (`teacher/assessment/AddAssessmentActivity.java` • Layout: `activity_add_assessment.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័របង្កើតកិច្ចការ Quiz ឬប្រឡងថ្មី (Create Assessment)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** អនុញ្ញាតឱ្យគ្រូបង្កើត `Assignment`, `Homework`, `Quiz`, ឬ `Exam` ដោយជ្រើស Course Offering ពិតប្រាកដដែលខ្លួនបង្រៀន ពេលផុតកំណត់ ពិន្ទុអតិបរមា និងចំនួន Attempt។
- **ធាតុផ្សំ UI៖** Dropdown ប្រភេទ (Assignment/Homework/Quiz/Exam), Dropdown Course Offering, ចំណងជើង សេចក្តីណែនាំ Date/Time Picker ពិន្ទុ Max Attempts និងប៊ូតុង Save។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** បង្ហាញ Field តាមប្រភេទដែលរើស (ឧ. Duration & Attempt limit សម្រាប់ Quiz, Room & Exam Date សម្រាប់ Exam)។
- **ប៊ូតុង និងសកម្មភាព៖**
  - បង្កើត Quiz: ហៅ `POST /api/teacher/quizzes` ហើយបើកបន្តទៅ `QuizBuilderActivity` ដើម្បីបញ្ចូលសំណួរ។
  - បង្កើត Assignment/Homework: ហៅ `POST /api/teacher/assignments`។
  - បង្កើត Exam: ហៅ `POST /api/teacher/exams`។
- **ប្រភពទិន្នន័យ៖** Teaching Assignments API & Assessment Store APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teacher/teaching-assignments`, `POST /api/teacher/assignments`, `POST /api/teacher/quizzes`, `POST /api/teacher/exams`
- **តារាង Database ពាក់ព័ន្ធ៖** `assignments`, `quizzes`, `exams`, `course_offerings`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `TeacherAssessmentFragment` → `QuizBuilderActivity` (ប្រសិនបើជា Quiz) ឬត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រតែមួយបង្កើតបានគ្រប់ប្រភេទវាយតម្លៃ ដោយចងភ្ជាប់ទៅ `course_offering_id` ពិតរបស់គ្រូប៉ុណ្ណោះ។"*

---

### 51. `EditAssessmentActivity` (`teacher/assessment/EditAssessmentActivity.java` • Layout: `activity_edit_exam.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រកែសម្រួលកាលវិភាគប្រឡង (Edit Exam)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** អនុញ្ញាតឱ្យគ្រូកែសម្រួលចំណងជើង ប្រភេទ ថ្ងៃម៉ោង បន្ទប់ និងពិន្ទុប្រឡង (`Exam`)។
- **ធាតុផ្សំ UI៖** ប្រអប់កែទិន្នន័យ Exam, Date/Time Pickers, ប៊ូតុង Update Exam។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Field Validation & Save indicator។
- **ប៊ូតុង និងសកម្មភាព៖** ហៅ `PUT /api/teacher/exams/{id}`។
- **ប្រភពទិន្នន័យ៖** Exam Detail & Teaching Assignments APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teacher/exams/{id}`, `GET /api/teacher/teaching-assignments`, `PUT /api/teacher/exams/{id}`
- **តារាង Database ពាក់ព័ន្ធ៖** `exams`, `course_offerings`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `TeacherAssessmentFragment`, `TeacherExamDetailActivity` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"អនុញ្ញាតឱ្យគ្រូកែប្រែថ្ងៃ ឬបន្ទប់ប្រឡងដោយមិនបាត់បង់លទ្ធផលដែលបានកត់ត្រា។"*

---

### 52. `QuizBuilderActivity` (`teacher/assessment/QuizBuilderActivity.java` • Layout: `activity_quiz_builder.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រគ្រប់គ្រង និងសាងសង់សំណួរ Quiz (Quiz Builder & Management)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** បន្ថែម/កែ/ស្ទួន/តម្រៀប/លុបសំណួរ Quiz (`Multiple Choice`, `True/False`, `Short Answer`), Publish/Unpublish, គ្រប់គ្រងនិស្សិត និងពិន្ទុ Quiz Attempts (រួមទាំងកែពិន្ទុសំណួរ Short Answer), មើល Audit Logs និងបើក `AssessmentReopenManagerActivity`។
- **ធាតុផ្សំ UI៖** Header + Status Badge, កាតសង្ខេប Total Marks/Questions, បញ្ជីសំណួរ (`QuizBuilderQuestionAdapter`), Dialog បន្ថែមសំណួរ (`dialog_quiz_question_editor`), កាត Universal Reopen, បញ្ជី Attempts & Audit Logs។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** `Draft`, `Published`, `Reopened`, `Total Marks`, `Pending Manual Grading`។
- **ប៊ូតុង និងសកម្មភាព៖**
  - `+ Add Question` / Edit / Duplicate / Reorder / Delete Question
  - `Publish` / `Unpublish` / `Delete Quiz`
  - `Universal Reopen & Permissions` → បើក `AssessmentReopenManagerActivity`
  - ដាក់ពិន្ទុ Attempt Answer (`gradeTeacherQuizAnswer`) ឬ Grant/Revoke Override។
- **ប្រភពទិន្នន័យ៖** Quiz Management API Suite (`QuizController`, `QuizQuestionController`, `QuizAttemptController`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teacher/quizzes/{id}`, `POST /api/teacher/quizzes/{id}/questions`, `PUT/DELETE /api/teacher/quiz-questions/{id}`, `POST .../duplicate`, `POST .../reorder`, `POST .../publish`, `POST .../unpublish`, `GET .../attempts`, `POST /api/teacher/quiz-answers/{id}/grade`, `GET .../audit-logs`, `POST .../overrides`
- **តារាង Database ពាក់ព័ន្ធ៖** `quizzes`, `quiz_questions`, `quiz_attempts`, `quiz_answers`, `quiz_student_overrides`, `quiz_override_logs`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `AddAssessmentActivity`, `TeacherAssessmentFragment`, `ManageScoresFragment` → `AssessmentReopenManagerActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"គ្រូអាចសាងសង់សំណួរ Quiz ចម្រុះប្រភេទ Publish ឱ្យនិស្សិតធ្វើ ដាក់ពិន្ទុសំណួរសរសេរដោយដៃ និងបើក Universal Reopen Manager ភ្លាមៗពីទីនេះ។"*

---

### 53. `AssessmentReopenManagerActivity` (`teacher/assessment/AssessmentReopenManagerActivity.java` • Layout: `activity_assessment_reopen_manager.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រគ្រប់គ្រងសិទ្ធិបើកឡើងវិញ ដាក់យឺត និងធ្វើម្តងទៀត (Universal Reopen Manager) ★ *ស្នូលសំខាន់*
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** ចំណុចប្រតិបត្តិការតែមួយសម្រាប់ **Assignment, Homework និង Quiz** ដើម្បីបើកសិទ្ធិដាក់យឺត (Late), ពន្យារថ្ងៃផុតកំណត់ (New Deadline), អនុញ្ញាតឱ្យដាក់ស្នើឡើងវិញ (Resubmit), បន្ថែមចំនួនដង Quiz (`+ Extra Attempts`), ដកសិទ្ធិវិញ (`Revoke`), មើលប្រវត្តិ Submission/Attempt ចាស់ៗ និងមើល Audit Logs។
- **ធាតុផ្សំ UI៖** Header + តម្រងប្រភេទ (`Assignment`, `Homework`, `Quiz`), កាតព័ត៌មានរួម Assessment, ប៊ូតុង Assessment-level actions (Reopen/Extend/Audit Logs), ប្រអប់ស្វែងរកនិស្សិត, `RecyclerView` (`item_reopen_student_row`) ជាមួយ FlowLayout ប៊ូតុងសកម្មភាពលើនិស្សិតម្នាក់ៗ។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ផ្លាកទាំង ៧ របស់ `ReopenPermissionHelper` (`Not Submitted`, `In Progress`, `Submitted`, `Late Allowed`, `Resubmit Allowed`, `Retake Allowed`, `Revoked/Blocked`), កាត Effective Deadline, Used/Max Attempts។
- **ប៊ូតុង និងសកម្មភាព៖**
  - `Reopen`: បើក Dialog ជ្រើសថ្ងៃ+ម៉ោង (`DatePicker` + `TimePicker`), ចំនួន Extra Attempts, មូលហេតុ → `POST /api/assessments/{type}/{id}/reopen`
  - `Extend Deadline`: `POST /api/assessments/{type}/{id}/extend-deadline`
  - `+ Attempts`: `POST /api/assessments/{type}/{id}/grant-attempts`
  - `Revoke`: `POST /api/assessments/{type}/{id}/revoke`
  - `History`: ទាញមើល Submissions (`getTeacherAssessmentSubmissionHistory`) ឬ Quiz Attempts (`getTeacherQuizAttemptHistory`) ដោយអាចបើក `SubmissionFileViewerActivity` ឬ `EnterScoreActivity`
  - `Audit Logs`: `GET /api/assessments/{type}/{id}/audit-logs`
- **ប្រភពទិន្នន័យ៖** Universal Reopen API (`AssessmentReopenController` + `AssessmentReopenService`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/assessments/{type}/{id}/reopen-status`, `POST .../reopen`, `POST .../extend-deadline`, `POST .../grant-attempts`, `POST .../revoke`, `GET .../submissions`, `GET .../attempts`, `GET .../audit-logs`
- **តារាង Database ពាក់ព័ន្ធ៖** `assessment_student_overrides`, `assessment_audit_logs`, `assignments`, `assignment_submissions`, `quizzes`, `quiz_attempts`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `TeacherAssessmentFragment`, `QuizBuilderActivity` → `SubmissionFileViewerActivity`, `EnterScoreActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"នេះគឺជាម៉ូឌុល Universal Reopen៖ គ្រូអាចផ្តល់សិទ្ធិឱ្យនិស្សិតម្នាក់ជាក់លាក់ដោយមិនប៉ះពាល់និស្សិតដទៃ កត់ត្រា Audit Log គ្រប់សកម្មភាព និងរក្សាប្រវត្តិឯកសារ/ពិន្ទុចាស់ទាំងអស់។"*

---

### 54. `EnterScoreActivity` (`teacher/assessment/EnterScoreActivity.java` • Layout: `activity_enter_score.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រដាក់ពិន្ទុ និងពិនិត្យឯកសារនិស្សិត (Grade Submission)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** អនុញ្ញាតឱ្យគ្រូមើលឯកសារកិច្ចការនិស្សិតដោយផ្ទាល់ (PDF Thumbnail / Image), បញ្ចូលពិន្ទុ និងមតិយោបល់ ហើយរក្សាទុកជា `Draft (Save Score)` ឬ `Publish Grade (Submit & Publish)`។
- **ធាតុផ្សំ UI៖** កាតព័ត៌មាននិស្សិត/កិច្ចការ, Inline File Preview Box + ប៊ូតុង Full Screen / Open / Download, ប្រអប់ពិន្ទុ & Feedback, ប៊ូតុង Save Draft / Publish Score។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ផ្លាក `Saved Draft` vs `Graded / Published` និង Version pill។
- **ប៊ូតុង និងសកម្មភាព៖**
  - `btnFullScreen` → `SubmissionFileViewerActivity`
  - `btnSaveDraft` / `btnPublish`: ហៅ `POST /api/teacher/submissions/{id}/grade` ជាមួយ flag `publish`.
- **ប្រភពទិន្នន័យ៖** Teacher Submission Detail & Grading APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teacher/submissions/{id}`, `POST /api/teacher/submissions/{id}/grade`
- **តារាង Database ពាក់ព័ន្ធ៖** `assignment_submissions`, `assignments`, `grades`, `notifications`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `TeacherAssessmentFragment`, `ManageScoresFragment`, `AssessmentReopenManagerActivity`, `SubmissionFileViewerActivity` → `SubmissionFileViewerActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"គ្រូអាចមើលកិច្ចការនិស្សិត និងដាក់ពិន្ទុជា Draft ទុកសិន ឬ Publish ភ្លាមៗ ដែលនឹងបង្ហាញពិន្ទុជូននិស្សិតភ្លាម។"*

---

### 55. `SubmissionFileViewerActivity` (`teacher/assessment/SubmissionFileViewerActivity.java` • Layout: `activity_submission_file_viewer.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័របើកមើលឯកសារកិច្ចការពេញអេក្រង់ (Submission File Viewer)
- **តួនាទីប្រើប្រាស់៖** Teacher និង Student (Shared Authorized Access)
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញឯកសារកិច្ចការក្នុងទម្រង់ពេញអេក្រង់ជាមួយ **Pinch-to-Zoom / Double-tap** សម្រាប់រូបភាព និងការរុករកទំព័រ (`Previous/Next Page`) សម្រាប់ឯកសារ **PDF** ព្រមទាំង Open With ឬ Download។
- **ធាតុផ្សំ UI៖** Toolbar, `ZoomableImageView`, ប៊ូតុងប្តូរទំព័រ PDF, ប៊ូតុង Download/Open File, ប៊ូតុង Grade Submission (សម្រាប់ Teacher)។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ProgressBar ពេលទាញ File stream, Notice card ប្រសិនបើជាឯកសារ DOCX ដែលតម្រូវឱ្យបើកតាម External Viewer។
- **ប៊ូតុង និងសកម្មភាព៖** ពង្រីកបង្រួមរូបភាព ប្តូរទំព័រ PDF ទាញយកទុកក្នុងទូរស័ព្ទ ឬចុច Grade ទៅ `EnterScoreActivity`។
- **ប្រភពទិន្នន័យ៖** Authorized Streaming File Download Endpoint (`SubmissionFileService`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/student/submissions/{id}`, `GET /api/teacher/submissions/{id}`, plus authenticated stream URL
- **តារាង Database ពាក់ព័ន្ធ៖** `assignment_submissions`, `assignments`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `SubmitAssignmentActivity`, `StudentSubmissionDetailActivity`, `StudentAssessmentFragment`, `TeacherAssessmentFragment`, `AssessmentReopenManagerActivity`, `EnterScoreActivity` → `EnterScoreActivity` ឬត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ឯកសារកិច្ចការត្រូវបានរក្សាទុកជា Private Storage លើ Server និងបើកមើលបានតែតាម Authenticated Stream Endpoint ប៉ុណ្ណោះ ជាមួយមុខងារពង្រីករូបភាព និងបើកមើលទំព័រ PDF ផ្ទាល់ក្នុង App។"*

---

### 56. `TeacherExamDetailActivity` (`teacher/assessment/TeacherExamDetailActivity.java` • Layout: `activity_teacher_exam_detail.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រគ្រប់គ្រង និងបញ្ចូលពិន្ទុប្រឡង (Teacher Exam Detail & Scoring)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញបញ្ជីនិស្សិតចូលប្រឡងសម្រាប់ `Exam` មួយ អនុញ្ញាតឱ្យគ្រូបញ្ចូល/កែពិន្ទុប្រឡងម្នាក់ៗ (`ExamStudentScoreAdapter`), Publish ឬ Unpublish លទ្ធផល និងលុប/កែប្រឡង។
- **ធាតុផ្សំ UI៖** កាតព័ត៌មាន Exam, បញ្ជីពិន្ទុនិស្សិត (`RecyclerView`), Dialog បញ្ចូលពិន្ទុ (`dialog_enter_exam_score`), ប៊ូតុង Publish/Unpublish/Edit/Delete។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** `Draft` vs `Published`, Absent/Completed status badge, Pass/Fail metrics។
- **ប៊ូតុង និងសកម្មភាព៖**
  - ចុចនិស្សិត: បញ្ចូលពិន្ទុ → `POST /api/teacher/exams/{id}/scores`
  - `Publish` / `Unpublish`: `POST .../publish` / `POST .../unpublish`
  - `Edit`: បើក `EditAssessmentActivity`
  - `Delete`: `DELETE /api/teacher/exams/{id}/force`
- **ប្រភពទិន្នន័យ៖** Teacher Exam Management API Suite (`TeacherAssessmentController`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teacher/exams/{id}`, `POST /api/teacher/exams/{id}/scores`, `POST .../publish`, `POST .../unpublish`, `DELETE .../force`
- **តារាង Database ពាក់ព័ន្ធ៖** `exams`, `exam_results`, `students`, `course_offerings`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `TeacherAssessmentFragment`, `ManageScoresFragment` → `EditAssessmentActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រនេះអនុញ្ញាតឱ្យគ្រូបញ្ចូលពិន្ទុប្រឡង Midterm/Final ដល់និស្សិតគ្រប់រូប រួច Publish ឱ្យនិស្សិតមើលឃើញជាផ្លូវការ។"*

---

### 57. `AttendanceFragment` (`teacher/attendance/AttendanceFragment.java` • Layout: `fragment_attendance.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងគ្រប់គ្រង និងហៅឈ្មោះវត្តមាន (Teacher Attendance Management)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** អនុញ្ញាតឱ្យគ្រូមើលក្រាហ្វវត្តមានរួម ហៅឈ្មោះនិស្សិតតាមថ្ងៃ (`Present`, `Late`, `Absent`, `Excused`), មើលប្រវត្តិ Sessions ចាស់ៗ និងមើលភាគរយវត្តមាននិស្សិតម្នាក់ៗ។ *(បានកែលម្អក្នុង Audit នេះឱ្យបើកផ្ទាល់ទៅ `StudentDetailActivity`។)*
- **ធាតុផ្សំ UI៖** Tabs ៤ (`Overview/Mark`, `Records`, `Students Breakdown`, `Export`), `PieChart` វត្តមាន, បញ្ជីនិស្សិតហៅឈ្មោះ (`Mark All Present` + Status Chips), ប្រអប់ស្វែងរក, `RecyclerView`។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Course Offering dropdown, ផ្លាកភាគរយវត្តមានតាមនិស្សិត (`Rate %`)។
- **ប៊ូតុង និងសកម្មភាព៖**
  - `Mark All Present` & Radio/Status Buttons → `POST /api/teacher/attendance/submit`
  - ចុចនិស្សិតក្នុង Breakdown: បង្ហាញសង្ខេបវត្តមាន + ប៊ូតុង **`View Full Student Profile`** (`StudentDetailActivity`)
  - Export buttons: បើក `ReportsFragment`។
- **ប្រភពទិន្នន័យ៖** Teacher Attendance Overview & Submit APIs (`TeacherModuleController`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teacher/attendance/overview`, `POST /api/teacher/attendance/submit`
- **តារាង Database ពាក់ព័ន្ធ៖** `attendance`, `attendance_details`, `course_offerings`, `course_enrollments`, `students`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Drawer ក្នុង `TeacherMainActivity` → `StudentDetailActivity`, `ReportsFragment`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"គ្រូអាចចុច Mark All Present រួចកែតែនិស្សិតអវត្តមាន ឬយឺត បន្ទាប់មករក្សាទុកចូល Database ភ្លាម ហើយក្រាហ្វ និងវត្តមាននិស្សិតនឹងបច្ចុប្បន្នភាពដោយស្វ័យប្រវត្តិ។"*

---

### 58. `ManageScoresFragment` (`teacher/ManageScoresFragment.java` • Layout: `fragment_manage_scores.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងគ្រប់គ្រងពិន្ទុ និងតារាងពិន្ទុរួម (Teacher Manage Scores & Gradebook)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញកិច្ចការដែលរង់ចាំការដាក់ពិន្ទុ (`Pending Grading`), បញ្ជី Assignments/Quizzes/Exams និងតារាងពិន្ទុរួម (`Gradebook`) គណនាដោយស្វ័យប្រវត្តិ។ *(បានកែលម្អក្នុង Audit នេះឱ្យបើកផ្ទាល់ទៅ `StudentDetailActivity` ពីជួរ Gradebook។)*
- **ធាតុផ្សំ UI៖** Dropdown មុខវិជ្ជា, Sub-tabs ៥ (`Pending`, `Assignments`, `Quizzes`, `Exams`, `Gradebook`), ប្រអប់ស្វែងរក `RecyclerView`។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ផ្លាក `Letter Grade` (`A`–`F`, `N/A`), `Pending Scores` counter។
- **ប៊ូតុង និងសកម្មភាព៖**
  - ចុច Pending Submission → `EnterScoreActivity`
  - ចុច Quiz → `QuizBuilderActivity`
  - ចុច Exam → `TeacherExamDetailActivity`
  - ចុច Gradebook Row → សង្ខេបពិន្ទុ + ប៊ូតុង **`View Full Student Profile`** (`StudentDetailActivity`)
- **ប្រភពទិន្នន័យ៖** Teacher Assessments Overview & Grading APIs (`TeacherModuleController::assessmentsOverview`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teacher/assessments/overview`
- **តារាង Database ពាក់ព័ន្ធ៖** `assignment_submissions`, `quiz_attempts`, `exam_results`, `grades`, `course_offerings`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Drawer ក្នុង `TeacherMainActivity` / `TeacherDashboardFragment` → `EnterScoreActivity`, `QuizBuilderActivity`, `TeacherExamDetailActivity`, `StudentDetailActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"តារាង Gradebook ទាញពិន្ទុពិតពី Assignments, Quizzes និង Midterm/Final មកបូកសរុបជា Letter Grade ជូនគ្រូ ហើយចុចមើលប្រវត្តិនិស្សិតលម្អិតបានភ្លាម។"*

---

### 59. `ClassAnalyticsFragment` (`teacher/ClassAnalyticsFragment.java` • Layout: `fragment_teacher_analytics.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងវិភាគទិន្នន័យថ្នាក់រៀន (Teacher Class Analytics)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញក្រាហ្វប្រៀបធៀបសមត្ថភាពថ្នាក់រៀន ការបែងចែកកម្រិតនិស្សិត និងអត្រាចូលរួមសិក្សាតាមមុខវិជ្ជា។
- **ធាតុផ្សំ UI៖** កាតម៉ែត្រ, BarChart/LineChart, តម្រង Course, ផ្លូវកាត់ទៅ Attendance និង Risk Analysis។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Scope chips និង Empty State ពេលគ្មានទិន្នន័យ។
- **ប៊ូតុង និងសកម្មភាព៖** ចុចចូល `AttendanceFragment` ឬ `PredictionRiskFragment`។
- **ប្រភពទិន្នន័យ៖** Filtered Teacher Dashboard & Engagement API (`TeacherDashboardService`, `TeacherAnalyticsService`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teacher/analytics` (ឬ `/api/teacher/dashboard`)
- **តារាង Database ពាក់ព័ន្ធ៖** `course_offerings`, `course_enrollments`, `attendance_details`, `assignment_submissions`, `quiz_attempts`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Drawer ក្នុង `TeacherMainActivity` ឬ `TeacherCourseDetailActivity` → `AttendanceFragment`, `PredictionRiskFragment`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ជួយគ្រូមើលជារួមថាក្នុងថ្នាក់ដែលខ្លួនបង្រៀន អត្រាដាក់កិច្ចការ Quiz និងវត្តមានស្ថិតក្នុងកម្រិតណា។"*

---

### 60. `PredictionRiskFragment` (`teacher/PredictionRiskFragment.java` • Layout: `fragment_prediction_risk.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងតាមដានហានិភ័យ និងការព្យាករណ៍សិស្ស (Teacher Prediction & Risk)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញបញ្ជីនិស្សិតមានហានិភ័យ (`High/Medium Risk`), ការព្យាករណ៍ពិន្ទុប្រឡង (`Exam Predictions`), និងការវិភាគហានិភ័យតាមមុខវិជ្ជា រួមជាមួយ PieChart។ *(បានកែលម្អក្នុង Audit នេះឱ្យបើកផ្ទាល់ទៅ `StudentDetailActivity` ពីកាត At-Risk Student។)*
- **ធាតុផ្សំ UI៖** PieChart សមាមាត្រ Risk, ផ្ទាំង Sub-tabs (`At-Risk Students`, `Exam Predictions`, `Course Risk Analysis`), ប្រអប់ស្វែងរក, Filter pills (`All`, `High Risk`, `Medium`), `RecyclerView`។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ផ្លាកពណ៌ក្រហម/លឿងតាមកម្រិតហានិភ័យ ពិន្ទុ Risk Score (%) និងមូលហេតុហានិភ័យ។
- **ប៊ូតុង និងសកម្មភាព៖** ចុចលើនិស្សិតមានហានិភ័យ: បង្ហាញរោគវិនិច្ឆ័យ + ប៊ូតុង **`View Full Student Profile`** បើក `StudentDetailActivity`។
- **ប្រភពទិន្នន័យ៖** Teacher Risk Predictions API (`TeacherModuleController::riskPredictions`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teacher/risk-predictions`
- **តារាង Database ពាក់ព័ន្ធ៖** `risk_assessments`, `predictions`, `students`, `courses`
- **ការតភ្ជាប់ទំព័ន្ធ (Navigation)៖** Drawer ក្នុង `TeacherMainActivity` → `StudentDetailActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ប្រព័ន្ធជួយសម្គាល់និស្សិតណាខ្លះដែលប្រឈមហានិភ័យធ្លាក់ក្នុងមុខវិជ្ជារបស់គ្រូ ដើម្បីចាត់វិធានការជួយសង្គ្រោះទាន់ពេល។"*

---

### 61. `PredictionEvaluationFragment` (`teacher/PredictionEvaluationFragment.java` • Layout: `fragment_prediction_evaluation.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងវាយតម្លៃភាពត្រឹមត្រូវនៃការទស្សន៍ទាយ (Prediction Evaluation)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញម៉ែត្រភាពត្រឹមត្រូវ (`Accuracy`, `MAE`, `RMSE`, `Precision`, `Recall`) របស់ម៉ូដែល ML ធៀបនឹងលទ្ធផលប្រឡងពិត។
- **ធាតុផ្សំ UI៖** កាតម៉ែត្រវាយតម្លៃ, `BarChart`/`LineChart` ប្រៀបធៀប, បញ្ជីសម័យវាយតម្លៃ។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Empty State ពេលមិនទាន់មានទិន្នន័យវាយតម្លៃ ML។
- **ប៊ូតុង និងសកម្មភាព៖** ប្តូរការមើលម៉ែត្រ និង Refresh។
- **ប្រភពទិន្នន័យ៖** Teacher Risk Predictions & Model Eval API។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teacher/risk-predictions`
- **តារាង Database ពាក់ព័ន្ធ៖** `predictions`, `exam_results`, `learning_analytics`
- **ការតភ្ជាប់ទំព័ន្ធ (Navigation)៖** Drawer ក្នុង `TeacherMainActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"បង្ហាញពីភាពសុក្រឹតរបស់ម៉ូដែលទស្សន៍ទាយពិន្ទុធៀបនឹងពិន្ទុពិត។"*

---

### 62. `RecommendationFragment` (`teacher/RecommendationFragment.java` • Layout: `fragment_recommendations.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងផ្តល់ដំបូន្មាន និងអន្តរាគមន៍សិស្សរបស់គ្រូ (Teacher Recommendations)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញការណែនាំដែលគ្រូបានបង្កើត និងអនុញ្ញាតឱ្យគ្រូសរសេរដំបូន្មានថ្មីផ្ញើទៅនិស្សិតដែលមានហានិភ័យ ឬត្រូវការជំនួយ។
- **ធាតុផ្សំ UI៖** `RecyclerView` កាតដំបូន្មាន, ប៊ូតុងបង្កើតដំបូន្មានថ្មី Dialog (រើសនិស្សិត មុខវិជ្ជា Priority សារណែនាំ)។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Priority (`High`, `Medium`, `Low`), Status (`Pending`, `Completed`)។
- **ប៊ូតុង និងសកម្មភាព៖** បង្កើត Recommendation ហៅ `POST /api/teacher/recommendations`។
- **ប្រភពទិន្នន័យ៖** Teacher Risk Predictions & Recommendation Create APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teacher/risk-predictions`, `POST /api/teacher/recommendations`
- **តារាង Database ពាក់ព័ន្ធ៖** `recommendations`, `students`, `courses`, `teachers`
- **ការតភ្ជាប់ទំព័ន្ធ (Navigation)៖** Drawer ក្នុង `TeacherMainActivity`。
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"គ្រូអាចផ្ញើផែនការសិក្សា ឬការណែនាំផ្ទាល់ខ្លួនទៅនិស្សិត ដែលនឹងលេចឡើងក្នុងគណនីនិស្សិតភ្លាម។"*

---

### 63. `ReportsFragment` (`teacher/ReportsFragment.java` • Layout: `fragment_teacher_reports.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងរបាយការណ៍គ្រូបង្រៀន (Teacher Reports & Export)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញរបាយការណ៍លទ្ធផល និងវត្តមានតាមមុខវិជ្ជាបង្រៀនរបស់គ្រូ ព្រមទាំងអនុញ្ញាតឱ្យនាំចេញជារបាយការណ៍ PDF/CSV ក្នុងទូរស័ព្ទ។
- **ធាតុផ្សំ UI៖** Course Selector, Metrics Summary Cards, Export PDF / CSV Buttons, Preview Table។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Export progress & saved path feedback។
- **ប៊ូតុង និងសកម្មភាព៖** ទាញរបាយការណ៍ពី API និងបង្កើតឯកសារ Export។
- **ប្រភពទិន្នន័យ៖** Teacher Analytics & Course Offerings APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teacher/analytics`, `GET /api/teacher/courses`
- **តារាង Database ពាក់ព័ន្ធ៖** `course_offerings`, `course_enrollments`, `attendance_details`, `grades`, `reports`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Bottom Tab ៤ ក្នុង `TeacherMainActivity` ឬមកពី `AttendanceFragment`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"គ្រូអាចបង្កើតរបាយការណ៍បូកសរុបវត្តមាន និងពិន្ទុប្រចាំមុខវិជ្ជាបានយ៉ាងលឿន។"*

---

### 64. `NotificationFragment` (`teacher/NotificationFragment.java` • Layout: `fragment_teacher_notifications.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងការជូនដំណឹងគ្រូបង្រៀន (Teacher Notifications)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញសារជូនដំណឹងសម្រាប់គ្រូ (ដូចជាការដាក់ស្នើកិច្ចការរបស់និស្សិត ការជូនដំណឹងថ្នាក់) និងគាំទ្រការគូសចំណាំថាអានរួច។
- **ធាតុផ្សំ UI៖** Tabs (`All`, `Unread`, `Important`), `Mark All Read`, `RecyclerView`។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Unread Dot, Category icons។
- **ប៊ូតុង និងសកម្មភាព៖** ចុចលើសារដើម្បីគូសថាអានរួច (`PATCH /api/notifications/{id}/read`) និង `Mark All Read` (`POST /api/notifications/read-all`)។
- **ប្រភពទិន្នន័យ៖** Notifications API។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/notifications`, `PATCH /api/notifications/{id}/read`, `POST /api/notifications/read-all`
- **តារាង Database ពាក់ព័ន្ធ៖** `notifications`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Drawer ក្នុង `TeacherMainActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ប្រមូលរាល់សារជូនដំណឹងពាក់ព័ន្ធនឹងថ្នាក់បង្រៀនរបស់គ្រូ។"*

---

### 65. `TeacherProfileOverviewFragment` (`teacher/TeacherProfileOverviewFragment.java` • Layout: `fragment_teacher_profile_overview.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងព័ត៌មានប្រវត្តិរូបសង្ខេបគ្រូបង្រៀន (Teacher Profile Overview)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញព័ត៌មានប្រវត្តិរូបគ្រូបង្រៀន (ឈ្មោះជាភាសាខ្មែរ/អង់គ្លេស កូដគ្រូ មហាវិទ្យាល័យ មុខតំណែង ការិយាល័យ ជំនាញឯកទេស) ដោយទាញពី Server ផ្ទាល់។
- **ធាតុផ្សំ UI៖** Avatar, Name/ID/Status Header Card, Professional Details Card, ប៊ូតុង Edit Profile & Change Password & Change Language។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** `Active` badge, Khmer/English display helper (`PersonNameDisplayHelper`)។
- **ប៊ូតុង និងសកម្មភាព៖** `Edit Profile` → `TeacherProfileFragment`; `Change Password` → `ChangePasswordActivity`។
- **ប្រភពទិន្នន័យ៖** Profile API (`AuthController::profile`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/profile`
- **តារាង Database ពាក់ព័ន្ធ៖** `users`, `teachers`, `departments`, `office_locations`, `specializations`, `teacher_specialization`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Bottom Tab ៥ ក្នុង `TeacherMainActivity` → `TeacherProfileFragment`, `ChangePasswordActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"គ្រូអាចពិនិត្យព័ត៌មានការងារ បន្ទប់ការិយាល័យ និងជំនាញបង្រៀនរបស់ខ្លួនពីមូលដ្ឋានទិន្នន័យពិត។"*

---

### 66. `TeacherProfileFragment` (`teacher/TeacherProfileFragment.java` • Layout: `fragment_teacher_profile.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងកែសម្រួលប្រវត្តិរូបគ្រូបង្រៀន (Edit Teacher Profile)
- **តួនាទីប្រើប្រាស់៖** Teacher
- **គោលបំណង និងមុខងារសំខាន់៖** អនុញ្ញាតឱ្យគ្រូកែសម្រួលឈ្មោះ អ៊ីមែល លេខទូរស័ព្ទ ថ្ងៃខែឆ្នាំកំណើត និងភេទ ហើយរក្សាទុកចូល Database។
- **ធាតុផ្សំ UI៖** ប្រអប់កែទិន្នន័យ DatePicker ភេទ RadioGroup, Read-only ID/Department, ប៊ូតុង Save / Cancel / Change Password។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Validation error states & Success Toast។
- **ប៊ូតុង និងសកម្មភាព៖**
  - `btnTeacherSaveProfile`: ហៅ `PUT /api/profile` រួចធ្វើបច្ចុប្បន្នភាព `SessionManager`។
  - `btnTeacherCancelProfile`: ត្រឡប់ទៅ `TeacherProfileOverviewFragment`។
  - `btnTeacherChangePassword`: បើក `ChangePasswordActivity`។
- **ប្រភពទិន្នន័យ៖** Profile Get & Update APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/profile`, `PUT /api/profile`
- **តារាង Database ពាក់ព័ន្ធ៖** `users`, `teachers`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `TeacherProfileOverviewFragment` → ត្រឡប់ទៅ `TeacherProfileOverviewFragment` ឬ `ChangePasswordActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"អនុញ្ញាតឱ្យគ្រូកែទិន្នន័យទំនាក់ទំនងផ្ទាល់ខ្លួនដោយរក្សាកូដគ្រូ និងមហាវិទ្យាល័យជា Read-only (ការពារដោយ Admin)។"*

---

### 67–69. ទំព័រ Legacy ទទេ និងទំព័រចាស់ (Legacy Empty & Deprecated Pages)
- **67. `ClassAnalyticsActivity` (`teacher/analytics/ClassAnalyticsActivity.java`)** & **68. `AttendanceActivity` (`teacher/attendance/AttendanceActivity.java`)**: ជា Activity កូដទទេ (6 បន្ទាត់) ជំនាន់ដំបូង ដែលត្រូវបានជំនួសទាំងស្រុងដោយ `ClassAnalyticsFragment` និង `AttendanceFragment` និងមិនត្រូវបានចុះបញ្ជីក្នុង `AndroidManifest.xml` ឬហៅប្រើទៀតទេ។
- **69. `AssessmentListActivity` (`teacher/assessment/AssessmentListActivity.java` • Layout: `activity_assessment_detail.xml`)**: ជា Activity បឋមចាស់ដែលជំនួសដោយ `TeacherAssessmentFragment` (មុខងារពេញលេញជាង)។

---

# ក្រុមទី ៤៖ តួនាទីអ្នកគ្រប់គ្រងប្រព័ន្ធ (Admin Role)

> [!NOTE]
> លេខរៀងទំព័ររួម៖ ផ្ទាំងមុខងារ Admin ទាំង ៥១ ឯកសារ ត្រូវបានរៀបរាប់គ្រប់ទំព័រ (រាប់បន្តពីលេខ ៧០ ដល់ ១២០ រួមទាំង ៤ ឯកសារ Legacy/Redirect `117–120` និង `MainActivity` ទី ១២១) ដើម្បីធានាថាឯកសារគ្របដណ្តប់គ្រប់ឯកសារ `*Activity.java` និង `*Fragment.java` ទាំង ១២១ នៅក្នុងកូដ (១២០ ថ្នាក់ Activity/Fragment ពិតប្រាកដ + `EditUserActivity.java` មិនមែនជា Activity)។

### 70. `AdminMainActivity` (`admin/AdminMainActivity.java` • Layout: `activity_admin_main.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងមេរបស់អ្នកគ្រប់គ្រងប្រព័ន្ធ (Admin Portal Shell)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** ស៊ុមមេរបស់ Admin គ្រប់គ្រង Bottom Navigation (5 Tabs), Side Navigation Drawer (១៣ មុខងារ + ក្រុម Analytics Accordion), និងការចាកចេញពីប្រព័ន្ធ។
- **ធាតុផ្សំ UI៖** `DrawerLayout`, Profile Header, Drawer Items (Dashboard, Users, Courses, Classes, Reports, Notifications, Settings, Help, About, Expandable Analytics group, Logout Button), `BottomNavigationView` (`admin_bottom_menu.xml`), `FrameLayout`។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Active Drawer highlight sync (`syncAdminNavState`), `RoleGuard` ការពារតួនាទី Admin។
- **ប៊ូតុង និងសកម្មភាព៖** ប្តូរគ្រប់ Admin Fragments និង Logout ហៅ `POST /api/logout`។
- **ប្រភពទិន្នន័យ៖** `SessionManager`, Laravel Auth API។
- **ចំណុចតភ្ជាប់ API៖** `POST /api/logout`
- **តារាង Database ពាក់ព័ន្ធ៖** `users`, `admins`, `personal_access_tokens`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `LoginActivity` / `SplashActivity` → ផ្ទុកគ្រប់ Admin Fragments និង Settings/About; Logout → `LoginActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"នេះជាមជ្ឈមណ្ឌលគ្រប់គ្រងប្រព័ន្ធ SLAS ទាំងមូលរបស់ Admin ដោយមាន Bottom Bar សម្រាប់ការងារស្នូល និង Side Drawer សម្រាប់ការគ្រប់គ្រងថ្នាក់ ការវិភាគ ML និងឧប្បត្តិហេតុ។"*

---

### 71. `AdminDashboardFragment` (`admin/AdminDashboardFragment.java` • Layout: `fragment_admin_dashboard.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងទំព័រដើមអ្នកគ្រប់គ្រងប្រព័ន្ធ (Admin Dashboard)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញទិន្នន័យសង្ខេបទូទាំងសាកលវិទ្យាល័យ (Total Users, Students, Teachers, Courses), ក្រាហ្វ Enrollment Analytics, Performance, Attendance, Risk Distribution, System Health (API/MySQL/ML) និង Recent Users។ *(បានកែលម្អក្នុង Audit នេះឱ្យគណនា YoY Growth % ពិតប្រាកដជំនួសតម្លៃក្លែងក្លាយ `5.0%`។)*
- **ធាតុផ្សំ UI៖** Header + Profile Avatar + Notification Bell, តម្រង Academic Year & Year Level, KPI Summary Cards, `LineChart` User & Enrollment Trend, `BarChart`/`PieChart`, System Status Badges, Quick Access Buttons។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Online/Degraded System badges, YoY growth %, Empty states។
- **ប៊ូតុង និងសកម្មភាព៖** ចុចលើ Notification Bell → `NotificationFragment`; Profile Avatar → `AdminProfileFragment`; កាតផ្សេងៗ → `UserManagementFragment`, `ClassManagementFragment`, `ReportsFragment`, `MlModelEvaluationFragment`, `SystemStatisticsFragment`។
- **ប្រភពទិន្នន័យ៖** Admin Dashboard & Analytics APIs (`AdminAnalyticsController`, `DashboardController`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/admin/dashboard`, `GET /api/admin/analytics/enrollment`, `GET /api/admin/analytics/performance`, `GET /api/admin/analytics/attendance`, `GET /api/admin/analytics/student-risk`
- **តារាង Database ពាក់ព័ន្ធ៖** `users`, `students`, `teachers`, `admins`, `courses`, `classes`, `course_enrollments`, `attendance_details`, `grades`, `risk_assessments`, `academic_years`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** ផ្ទាំងលំនាំដើមក្នុង `AdminMainActivity` → ទំព័រគ្រប់គ្រងទាំងអស់។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"Dashboard Admin បង្ហាញស្ថានភាពសាកលវិទ្យាល័យទាំងមូលជា Real-time ចាប់ពីចំនួននិស្សិត គ្រូ មុខវិជ្ជា ដល់ស្ថានភាព Database MySQL និងសេវា ML។"*

---

### 72. `UserManagementFragment` (`admin/UserManagementFragment.java` • Layout: `fragment_admin_users.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងគ្រប់គ្រងអ្នកប្រើប្រាស់ទាំងអស់ (User Management: Students, Teachers, Admins, Faculty Evaluation)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** គ្រប់គ្រងគណនី និងទម្រង់អ្នកប្រើប្រាស់គ្រប់តួនាទី (`Students`, `Teachers`, `Admins`, និង `Faculty Evaluations & Workload`) រួមទាំងបង្កើត កែ ស្វែងរក ត្រងតាម Year Level/Department, Import CSV/Excel, Assign Courses/Classes និងដាក់ពិន្ទុវាយតម្លៃគ្រូ។
- **ធាតុផ្សំ UI៖** Sub-tabs ៤ (`Students`, `Teachers`, `Admins`, `Evaluations`), Statistic Pills, ប្រអប់ស្វែងរក, Filter dropdowns, `RecyclerView`, ប៊ូតុង `+ Add`, `Import`, `Export`។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ចំនួន Active/Inactive, Year Level badges (`Y1`–`Y4`), Faculty Workload badges។
- **ប៊ូតុង និងសកម្មភាព៖**
  - Student Tab: `+ Add` (`AddStudentActivity`), `Import` (`StudentImportActivity`), `History` (`StudentImportHistoryActivity`), `Export` (`StudentExportDialog`), ចុចជួរ (`AdminStudentDetailActivity`), Edit (`EditStudentActivity`)
  - Teacher Tab: `+ Add` (`AddTeacherActivity`), `Import Teachers`, ចុចជួរ (`AdminTeacherDetailActivity`), Edit (`EditTeacherActivity`), Delete
  - Admin Tab: `+ Add` (`AddAdminActivity`), ចុចជួរ (`AdminDetailActivity`), Edit (`EditAdminActivity`), Delete
  - Faculty Tab: ចាត់តាំង Course/Class, ដាក់ពិន្ទុ Review (`submitFacultyReview`)
- **ប្រភពទិន្នន័យ៖** Admin User, Teacher, & Faculty Evaluator APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/admin/users/statistics`, `GET /api/admin/users`, `GET/POST/PUT/DELETE /api/teachers`, `/api/faculty-evaluators/*`, `PATCH /api/admin/users/{id}/status`
- **តារាង Database ពាក់ព័ន្ធ៖** `users`, `students`, `teachers`, `admins`, `departments`, `programs`, `classes`, `faculty_reviews`, `course_offering_teachers`, `class_teacher`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Bottom Tab ២ ក្នុង `AdminMainActivity` → រាល់ Add/Edit/Detail/Import Activities។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"Admin អាចគ្រប់គ្រងគ្រប់តួអង្គក្នុងប្រព័ន្ធពីកន្លែងតែមួយ រាប់ចាប់ពីបន្ថែមនិស្សិតម្តងមួយ ឬ Import ជា File Excel/CSV រហូតដល់ចាត់តាំងមុខវិជ្ជាឱ្យគ្រូ។"*

---

### 73. `AddStudentActivity` (`admin/users/AddStudentActivity.java` • Layout: `activity_add_user.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រចុះឈ្មោះនិស្សិតថ្មី (Add Student University Record)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** ចុះឈ្មោះនិស្សិតថ្មីចូលប្រព័ន្ធដោយភ្ជាប់ឈ្មោះខ្មែរ/អង់គ្លេស កូដនិស្សិត អ៊ីមែល ពាក្យសម្ងាត់ មហាវិទ្យាល័យ ជំនាញ (Major), Year Level (`Y1`–`Y4`), Academic Year, ថ្នាក់រៀន និងព័ត៌មានគ្រួសារ/ទំនាក់ទំនងបន្ទាន់។
- **ធាតុផ្សំ UI៖** Cascading Spinners (Department → Program, Academic Year → Class), ប្រអប់ព័ត៌មានផ្ទាល់ខ្លួន និងគ្រួសារ, DatePicker DOB, ប៊ូតុង Create Student។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Field Validation (`StoreStudentRequest`) + Year Level mismatch warning ប្រសិនបើឆ្នាំនិស្សិតខុសពីថ្នាក់រៀន។
- **ប៊ូតុង និងសកម្មភាព៖** ហៅ `POST /api/admin/students` ដើម្បីបង្កើតទាំង `users`, `students`, `student_family_information` និង `student_emergency_contacts` ក្នុង Transaction តែមួយ។
- **ប្រភពទិន្នន័យ៖** Student Lookups & Admin Student Creation APIs (`StudentAdminController`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/admin/students/lookups`, `POST /api/admin/students`
- **តារាង Database ពាក់ព័ន្ធ៖** `users`, `students`, `departments`, `programs`, `classes`, `academic_years`, `student_family_information`, `student_emergency_contacts`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `UserManagementFragment` (ឬ `AddUserActivity`) → ត្រឡប់ក្រោយជាមួយ `RESULT_OK`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រចុះឈ្មោះនិស្សិតគាំទ្រព័ត៌មាននិស្សិតខ្មែរពេញលេញ (ឈ្មោះខ្មែរ និងអង់គ្លេស ជំនាញ ឆ្នាំសិក្សា គ្រួសារ និងទំនាក់ទំនងបន្ទាន់) ដោយ Dropdown Program និង Class ផ្លាស់ប្តូរដោយស្វ័យប្រវត្តិតាម Department ដែលបានរើស។"*

---

### 74. `AdminStudentDetailActivity` (`admin/users/AdminStudentDetailActivity.java` • Layout: `activity_admin_student_detail.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រព័ត៌មានលម្អិតនិស្សិត (Admin Student Dossier)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញប្រវត្តិសិក្សា ទិន្នន័យគ្រួសារ វត្តមាន និងកំណត់សម្គាល់របស់ Admin (`Student Notes`) ព្រមទាំងអាចបិទ/បើកគណនី Reset Password និងកែទិន្នន័យនិស្សិត។
- **ធាតុផ្សំ UI៖** Header Avatar + Status Pill + Menu, ផ្ទាំង Tabs ៤ (`Overview`, `Academic & Family`, `Attendance History`, `Admin Notes`), Bottom Buttons (`Deactivate/Activate`, `Edit`)។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Loading & Empty Notes State, Reset Password Dialog, Clipboard Copy Credentials។
- **ប៊ូតុង និងសកម្មភាព៖**
  - Overflow Menu → Reset Password (`POST /api/admin/users/{id}/reset-password`)
  - Tab Notes → Add/Edit/Delete Note (`POST/PUT/DELETE /api/admin/students/{id}/notes`)
  - `btnDeactivateAccount` → Toggle Status (`PATCH /api/admin/users/{id}/status`)
  - `btnEditStudent` → `EditStudentActivity`
- **ប្រភពទិន្នន័យ៖** Admin User Details & Student Notes APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/admin/users/{id}`, `PATCH /api/admin/users/{id}/status`, `POST /api/admin/students/{id}/reset-password`, `GET/POST /api/admin/students/{id}/notes`, `PUT/DELETE /api/admin/student-notes/{id}`
- **តារាង Database ពាក់ព័ន្ធ៖** `users`, `students`, `student_family_information`, `student_emergency_contacts`, `attendance_details`, `gpa_records`, `student_notes`, `course_enrollments`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `UserManagementFragment`, `AdminClassDetailActivity`, `IncidentResolutionWorkspaceFragment` → `EditStudentActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"Admin អាចពិនិត្យសំណុំឯកសារនិស្សិតទាំងមូល រួមទាំងកត់ត្រា Student Notes, មើលប្រវត្តិវត្តមាន និងធ្វើការ Reset Password ជូននិស្សិតនៅទីនេះ។"*

---

### 75. `EditStudentActivity` (`admin/users/EditStudentActivity.java` • Layout: `activity_edit_student.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រកែសម្រួលព័ត៌មាននិស្សិត (Edit Student)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** កែសម្រួលព័ត៌មាននិស្សិត (ឈ្មោះខ្មែរ/អង់គ្លេស អ៊ីមែល ទូរស័ព្ទ DOB ភេទ Faculty Program Year Level Class Status ព័ត៌មានគ្រួសារ និង Emergency Contact)។
- **ធាតុផ្សំ UI៖** Cascading Spinners, DatePicker, TextInputs, RadioGroup Gender/Status, ប៊ូតុង Save។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Field Validation & Year Level mismatch check។
- **ប៊ូតុង និងសកម្មភាព៖** ហៅ `PUT /api/admin/users/{id}` រក្សាទុកការកែប្រែ។
- **ប្រភពទិន្នន័យ៖** Student Lookups & User Update APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/admin/students/lookups`, `GET /api/admin/users/{id}`, `PUT /api/admin/users/{id}`
- **តារាង Database ពាក់ព័ន្ធ៖** `users`, `students`, `student_family_information`, `student_emergency_contacts`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `UserManagementFragment`, `AdminStudentDetailActivity` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"កែប្រែទិន្នន័យនិស្សិត និងផ្ទេរឆ្នាំសិក្សា/ថ្នាក់រៀនដោយរក្សាប្រវត្តិការសិក្សាចាស់ដដែល។"*

---

### 76. `StudentImportActivity` (`admin/users/StudentImportActivity.java` • Layout: `activity_student_import.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រជ្រើសរើសឯកសារបញ្ចូលនិស្សិតជាក្រុម (Bulk Student Import — Upload)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** ទាញយកគំរូ Excel/CSV Template និងផ្ទុកឯកសារបញ្ជីនិស្សិតទៅ Server ដើម្បីត្រួតពិនិត្យមុន Import។
- **ធាតុផ្សំ UI៖** ប៊ូតុង Download `.xlsx` / `.csv` Template, ប្រអប់រើស File, ប៊ូតុង Upload & Preview, ProgressBar។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Upload progress & File format validation។
- **ប៊ូតុង និងសកម្មភាព៖** Download Template (`downloadImportTemplateXlsx`/`Csv`), Upload Preview (`POST /api/admin/students/import/preview` → បើក `StudentImportPreviewActivity`)។
- **ប្រភពទិន្នន័យ៖** Student Import API (`StudentImportController` + `StudentImportValidationService`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/admin/students/import/template.xlsx`, `GET .../template.csv`, `POST /api/admin/students/import/preview`
- **តារាង Database ពាក់ព័ន្ធ៖** `student_imports`, `student_import_rows`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `UserManagementFragment` → `StudentImportPreviewActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ជំនួសការវាយបញ្ចូលម្តងម្នាក់ៗ Admin អាចទាញយក Template រួចបញ្ចូលនិស្សិតរាប់រយនាក់តាម Excel ឬ CSV ដោយមានប្រព័ន្ធផ្ទៀងផ្ទាត់កំហុសជាមុន។"*

---

### 77. `StudentImportPreviewActivity` (`admin/users/StudentImportPreviewActivity.java` • Layout: `activity_student_import_preview.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រផ្ទៀងផ្ទាត់ទិន្នន័យមុនបញ្ចូលនិស្សិត (Import Preview)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញជួរដេកត្រឹមត្រូវ (`Valid Rows`) និងជួរមានបញ្ហា (`Error Rows`) ទាញយករបាយការណ៍កំហុស បោះបង់ ឬបញ្ជាក់ការ Import (`Confirm Import`)។
- **ធាតុផ្សំ UI៖** Summary Counters (`Total`, `Valid`, `Errors`), Filter tabs, `RecyclerView` (`StudentImportRowAdapter`), ប៊ូតុង Confirm Import / Cancel / Error Report។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Row validation error reasons តាមជួរនីមួយៗ។
- **ប៊ូតុង និងសកម្មភាព៖** Download Error Report, Cancel (`POST .../cancel`), Confirm (`POST .../confirm` → `StudentImportProgressActivity`)។
- **ប្រភពទិន្នន័យ៖** Student Import Preview & Confirm APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/admin/students/import/{id}/rows`, `GET .../errors`, `POST .../cancel`, `POST .../confirm`
- **តារាង Database ពាក់ព័ន្ធ៖** `student_imports`, `student_import_rows`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `StudentImportActivity` → `StudentImportProgressActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ប្រព័ន្ធត្រួតពិនិត្យអ៊ីមែលស្ទួន លេខកូដស្ទួន និងជំនាញមិនត្រឹមត្រូវមុននឹងបង្កើតគណនីនិស្សិតពិតប្រាកដ។"*

---

### 78. `StudentImportProgressActivity` (`admin/users/StudentImportProgressActivity.java` • Layout: `activity_student_import_progress.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រដំណើរការ Import និងទាញយកព័ត៌មានគណនី (Import Progress & Credentials)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** តាមដានដំណើរការបង្កើតនិស្សិតជា Real-time ហើយពេលចប់ អាចទាញយកឯកសារ Credentials (Username & Temporary Password) និង Error Report។
- **ធាតុផ្សំ UI៖** ProgressBar, Status counter, ប៊ូតុង Download Credentials, Error Report, Cancel។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Polling status (`Pending`, `Processing`, `Completed`, `Failed`)។
- **ប៊ូតុង និងសកម្មភាព៖** Download Credentials CSV / Error Report។
- **ប្រភពទិន្នន័យ៖** Import Status & Export APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/admin/students/import/{id}`, `GET .../rows`, `GET .../credentials`, `GET .../errors`, `POST .../cancel`
- **តារាង Database ពាក់ព័ន្ធ៖** `student_imports`, `student_import_rows`, `users`, `students`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `StudentImportPreviewActivity` → ត្រឡប់ទៅ `UserManagementFragment`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"បង្ហាញវឌ្ឍនភាពជាក់ស្តែង និងបង្កើតឯកសារ Credentials សម្រាប់ចែកជូននិស្សិតថ្មីប្រើ Login ដំបូង។"*

---

### 79. `StudentImportHistoryActivity` (`admin/users/StudentImportHistoryActivity.java` • Layout: `activity_student_import_history.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រប្រវត្តិនៃការ Import និស្សិត (Import History)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញប្រវត្តិ Batch នៃការ Import និស្សិតទាំងអស់ កាលបរិច្ឆេទ អ្នកធ្វើ និងទាញយក Error Report ប្រសិនបើមាន។
- **ធាតុផ្សំ UI៖** `RecyclerView` (`StudentImportHistoryAdapter`), Empty State។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Import status badges (`Completed`, `Partial`, `Cancelled`)។
- **ប៊ូតុង និងសកម្មភាព៖** ទាញយក Error Report តាម Batch។
- **ប្រភពទិន្នន័យ៖** Student Imports History API។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/admin/students/imports`, `GET /api/admin/students/import/{id}/errors`
- **តារាង Database ពាក់ព័ន្ធ៖** `student_imports`, `student_import_rows`, `users`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `UserManagementFragment` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"រក្សាប្រវត្តិគ្រប់ Batch Import សម្រាប់សវនកម្ម និងពិនិត្យកំហុសឡើងវិញ។"*

---

### 80. `AddTeacherActivity` (`admin/teachers/AddTeacherActivity.java` • Layout: `activity_add_teacher.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័របន្ថែមគ្រូបង្រៀនថ្មី (Add Teacher)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** បង្កើតគណនី និងប្រវត្តិរូបគ្រូបង្រៀនថ្មីដោយប្រើ `TeacherProfileFormHelper` (ឈ្មោះខ្មែរ ឬអង់គ្លេស, Email Optional, Department Spinner, Position, Work Type, Office Picker, Specializations Multi-Select Chips, Hire Date/DOB Pickers)។
- **ធាតុផ្សំ UI៖** `TextInputLayout`, Spinners, ChipGroup Specializations, DatePickers, Status RadioGroup, ប៊ូតុង Create Teacher។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Khmer/English name rule validation & duplicate checking។
- **ប៊ូតុង និងសកម្មភាព៖** ជ្រើស Office/Specializations, រក្សាទុក → `POST /api/teachers`។
- **ប្រភពទិន្នន័យ៖** Teacher Catalog Lookups & Teacher Creation API (`TeacherProfileService`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teacher-profile/lookups`, `POST /api/teachers`
- **តារាង Database ពាក់ព័ន្ធ៖** `teachers`, `users`, `departments`, `office_locations`, `specializations`, `teacher_specialization`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `UserManagementFragment` → ត្រឡប់ក្រោយ `RESULT_OK`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"Admin បង្កើតប្រវត្តិរូបគ្រូបង្រៀនជាមួយបន្ទប់ការិយាល័យ និងជំនាញច្រើនមុខ ដែលអាចបង្កើត Login Account ភ្លាមៗបាន។"*

---

### 81. `AdminTeacherDetailActivity` (`admin/teachers/AdminTeacherDetailActivity.java` • Layout: `activity_admin_teacher_detail.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រព័ត៌មានលម្អិតគ្រូបង្រៀន (Admin Teacher Detail)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញព័ត៌មានគ្រូ ស្ថានភាពគណនី Login Link (`Linked`, `Not Created`, `Role Mismatch`, `No Password Set`), មុខវិជ្ជាបង្រៀន ថ្នាក់ទទួលបន្ទុក និងសកម្មភាព **Create/Repair Login Account** ឬ Deactivate/Edit។ *(បានលុប fallback `"Mr. Chan"` ក្លែងក្លាយក្នុង Audit។)*
- **ធាតុផ្សំ UI៖** ក្បាល Avatar + Name + Code + Status, ផ្ទាំង Tabs ៤ (`Overview`, `Courses`, `Classes`, `Notes`), កាត Login Provisioning, Bottom Buttons (`Deactivate`, `Edit`, `Create/Repair Account`)។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ស្ថានភាព Link ជាមួយតារាង `users` ពិតប្រាកដ។
- **ប៊ូតុង និងសកម្មភាព៖**
  - `btnRepairTeacherAccount`: ហៅ `POST /api/teachers/{id}/provision-account`
  - `btnDeactivateTeacherAccount`: ហៅ `PATCH /api/admin/users/{id}/status`
  - `btnEditTeacher` → `EditTeacherActivity`
- **ប្រភពទិន្នន័យ៖** Teacher Detail & Provisioning API (`TeacherAccountService`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teachers/{id}`, `GET /api/admin/users/{id}`, `POST /api/teachers/{id}/provision-account`, `PATCH /api/admin/users/{id}/status`
- **តារាង Database ពាក់ព័ន្ធ៖** `teachers`, `users`, `departments`, `office_locations`, `specializations`, `course_offering_teachers`, `class_teacher`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `UserManagementFragment` → `EditTeacherActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រនេះអនុញ្ញាតឱ្យ Admin ត្រួតពិនិត្យទាំងប្រវត្តិរូបគ្រូ និងគណនី Login ហើយអាចជួសជុល/បង្កើតគណនី Login ឱ្យគ្រូដោយចុចតែមួយដង។"*

---

### 82. `EditTeacherActivity` (`admin/teachers/EditTeacherActivity.java` • Layout: `activity_edit_teacher.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រកែសម្រួលព័ត៌មានគ្រូបង្រៀន (Edit Teacher)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** កែសម្រួលប្រវត្តិរូបគ្រូ (ឈ្មោះខ្មែរ/អង់គ្លេស អ៊ីមែល ទូរស័ព្ទ មហាវិទ្យាល័យ មុខតំណែង ប្រភេទការងារ Office Specializations Hire Date Status)។ *(បានលុប fallback `"Mr. Chan"` ក្លែងក្លាយក្នុង Audit។)*
- **ធាតុផ្សំ UI៖** Fields + Spinners + ChipGroup + DatePickers, ប៊ូតុង Update Teacher។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Field Validation & Save State។
- **ប៊ូតុង និងសកម្មភាព៖** ហៅ `PUT /api/teachers/{id}` + `PUT /api/admin/users/{id}`។
- **ប្រភពទិន្នន័យ៖** Teacher Detail & Update APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teachers/{id}`, `GET /api/teacher-profile/lookups`, `PUT /api/teachers/{id}`, `PUT /api/admin/users/{id}`
- **តារាង Database ពាក់ព័ន្ធ៖** `teachers`, `users`, `teacher_specialization`, `office_locations`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `AdminTeacherDetailActivity`, `UserManagementFragment` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"កែប្រែមុខតំណែង ការិយាល័យ និងជំនាញបង្រៀនរបស់គ្រូបានគ្រប់ពេលវេលា។"*

---

### 83. `AddAdminActivity` (`admin/users/AddAdminActivity.java` • Layout: `activity_add_admin.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័របន្ថែមអ្នកគ្រប់គ្រងប្រព័ន្ធថ្មី (Add Admin)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** បង្កើតគណនី Admin ថ្មី (ឈ្មោះ អ៊ីមែល ទូរស័ព្ទ ពាក្យសម្ងាត់ កម្រិតសិទ្ធិ និងស្ថានភាព Active/Inactive)។
- **ធាតុផ្សំ UI៖** `EditText` Inputs, Role Level Spinner, Status RadioGroup, ប៊ូតុង Save។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Email & Password validation។
- **ប៊ូតុង និងសកម្មភាព៖** ហៅ `POST /api/admin/users` ជាមួយ `role="admin"`។
- **ប្រភពទិន្នន័យ៖** Admin User Store API (`AdminUserController::store`)។
- **ចំណុចតភ្ជាប់ API៖** `POST /api/admin/users`
- **តារាង Database ពាក់ព័ន្ធ៖** `users`, `admins`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `UserManagementFragment` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"អនុញ្ញាតឱ្យ Admin បង្កើតគណនីអ្នកគ្រប់គ្រងប្រព័ន្ធបន្ថែម។"*

---

### 84. `AdminDetailActivity` (`admin/users/AdminDetailActivity.java` • Layout: `activity_admin_detail.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រព័ត៌មានលម្អិតអ្នកគ្រប់គ្រង (Admin Detail)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញប្រវត្តិរូប Admin សិទ្ធិប្រើប្រាស់ ស្ថានភាពគណនី និងបើក/បិទ ឬកែសម្រួលគណនី។ *(បានលុប fallback `"Mr. Chan"` ក្លែងក្លាយក្នុង Audit។)*
- **ធាតុផ្សំ UI៖** Tabs ៤ (`Overview`, `Permissions`, `Activity Log`, `Notes`), Profile Card, Bottom Action Buttons (`Deactivate`, `Edit Admin`)។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** `Active` / `Inactive` Status badge។
- **ប៊ូតុង និងសកម្មភាព៖** Toggle Status (`PATCH /api/admin/users/{id}/status`), `Edit` (`EditAdminActivity`)។
- **ប្រភពទិន្នន័យ៖** Admin User API។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/admin/users/{id}`, `PATCH /api/admin/users/{id}/status`
- **តារាង Database ពាក់ព័ន្ធ៖** `users`, `admins`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `UserManagementFragment` → `EditAdminActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"បង្ហាញព័ត៌មានគណនី Admin ម្នាក់ៗ និងអាចប្តូរស្ថានភាពសកម្ម/អសកម្ម។"*

---

### 85. `EditAdminActivity` (`admin/users/EditAdminActivity.java` • Layout: `activity_edit_admin.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រកែសម្រួលអ្នកគ្រប់គ្រង (Edit Admin)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** កែឈ្មោះ អ៊ីមែល ទូរស័ព្ទ និងស្ថានភាពរបស់ Admin។
- **ធាតុផ្សំ UI៖** Input fields + Status RadioGroup + Update Button។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Validation & Save result feedback។
- **ប៊ូតុង និងសកម្មភាព៖** ហៅ `PUT /api/admin/users/{id}`។
- **ប្រភពទិន្នន័យ៖** Admin User Update API។
- **ចំណុចតភ្ជាប់ API៖** `PUT /api/admin/users/{id}`
- **តារាង Database ពាក់ព័ន្ធ៖** `users`, `admins`
- **ការតភ្ជាប់ទំព័ន្ធ (Navigation)៖** មកពី `AdminDetailActivity`, `UserManagementFragment` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"កែសម្រួលព័ត៌មានគណនីអ្នកគ្រប់គ្រង។"*

---

### 86. `CourseManagementFragment` (`admin/CourseManagementFragment.java` • Layout: `fragment_course_management.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងគ្រប់គ្រងមុខវិជ្ជា និងកម្មវិធីសិក្សា (Admin Course Management)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** គ្រប់គ្រងកាតាឡុកមុខវិជ្ជាទាំងអស់ (`Courses Catalog`), ប្រភេទតាមមហាវិទ្យាល័យ (`Categories`), សវនកម្មកម្មវិធីសិក្សា (`Curriculum Audit`) និងការចាត់តាំងគ្រូ (`Course Assignments`)។
- **ធាតុផ្សំ UI៖** Tabs ៤, ប៊ូតុង `+ Add Course`, Filter Dropdowns (Department, Program, Academic Year, Semester, Status), Search Box, `RecyclerView`, Pagination Controls។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** `Active`/`Inactive` pills, Missing Teacher warnings, Credits & Enrollment counts។
- **ប៊ូតុង និងសកម្មភាព៖**
  - `+ Add Course`: បើក `AddCourseActivity`
  - ចុចលើមុខវិជ្ជា: បើក **`AdminCourseDetailActivity`** *(បានដំឡើងពី AlertDialog មកជា Activity ថ្មីពេញលេញក្នុង Audit នេះ!)*
  - Assign Teacher: បើក `AssignTeacherClassActivity`
  - Quick Status/Delete actions។
- **ប្រភពទិន្នន័យ៖** Admin Course Catalog API (`AdminCourseController`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/admin/courses`, `GET /api/teachers/{id}`, `PATCH /api/admin/courses/{id}/status`, `DELETE /api/admin/courses/{id}`
- **តារាង Database ពាក់ព័ន្ធ៖** `courses`, `departments`, `programs`, `academic_years`, `semesters`, `teachers`, `course_offerings`, `course_enrollments`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Bottom Tab ៣ ក្នុង `AdminMainActivity` → `AddCourseActivity`, `AdminCourseDetailActivity`, `AssignTeacherClassActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"Admin អាចគ្រប់គ្រងកាតាឡុកមុខវិជ្ជា ត្រងតាមជំនាញ និងឆមាស ហើយចុចចូល `AdminCourseDetailActivity` ដើម្បីមើល កែ ឬលុបមុខវិជ្ជា។"*

---

### 87. `AddCourseActivity` (`admin/courses/AddCourseActivity.java` • Layout: `activity_add_course.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័របង្កើត និងកែសម្រួលមុខវិជ្ជា (Add / Edit Course)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** បង្កើតមុខវិជ្ជាថ្មី ឬកែមុខវិជ្ជាដែលមានស្រាប់ ដោយជ្រើស Department, Major, Academic Year, Semester, Teacher តាម `RecordSelectorActivity`, កម្រិត Year Level, Credits និង Syllabus Description។
- **ធាតុផ្សំ UI៖** Input Fields, Selector Pickers (launch `RecordSelectorActivity`), AutoComplete Year Level & Status, Save Button។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Add Mode vs Edit Mode (`EXTRA_EDIT_COURSE`), Required validation។
- **ប៊ូតុង និងសកម្មភាព៖** ចុច Pickers → `RecordSelectorActivity`; ចុច Save → `POST /api/admin/courses` ឬ `PUT /api/admin/courses/{id}`។
- **ប្រភពទិន្នន័យ៖** Admin Course Store/Update APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/teachers/{id}`, `POST /api/admin/courses`, `PUT /api/admin/courses/{id}`
- **តារាង Database ពាក់ព័ន្ធ៖** `courses`, `departments`, `programs`, `academic_years`, `semesters`, `teachers`, `course_offerings`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `CourseManagementFragment`, `AdminCourseDetailActivity` → `RecordSelectorActivity` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រនេះប្រើ RecordSelectorActivity ដើម្បីឱ្យ Admin ជ្រើសមហាវិទ្យាល័យ ជំនាញ ឆ្នាំសិក្សា និងគ្រូបានត្រឹមត្រូវ ១០០% ចៀសវាងការវាយអក្សរខុស។"*

---

### 88. `AdminCourseDetailActivity` (`admin/courses/AdminCourseDetailActivity.java` • Layout: `activity_admin_course_detail.xml`) ★ *ទំព័រថ្មីក្នុង Audit*
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រព័ត៌មានលម្អិតមុខវិជ្ជាសម្រាប់ Admin (Admin Course Detail)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** ជំនួស AlertDialog ចាស់ ទៅជាផ្ទាំង Material Design ពេញលេញបង្ហាញព័ត៌មានមុខវិជ្ជា និងសកម្មភាពគ្រប់គ្រង។
- **ធាតុផ្សំ UI៖** Top Bar, Hero Card (Code, Name, Status Badge, Credits/Year/Enrolled Pills), Academic Card (Faculty, Academic Year, Semester, Teacher, Classes), Syllabus Card, Bottom Buttons (`Edit Course`, `Set Active/Inactive`, `Delete`)។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Active/Inactive status color, RoleGuard Admin verification។
- **ប៊ូតុង និងសកម្មភាព៖**
  - `btnCourseDetailEdit`: បើក `AddCourseActivity` ក្នុង Edit Mode
  - `btnCourseDetailToggleStatus`: ហៅ `PATCH /api/admin/courses/{id}/status`
  - `btnCourseDetailDelete`: Confirmation Dialog → `DELETE /api/admin/courses/{id}`
- **ប្រភពទិន្នន័យ៖** `Course` object ពីកាតាឡុក + Admin Course Status/Delete APIs។
- **ចំណុចតភ្ជាប់ API៖** `PATCH /api/admin/courses/{id}/status`, `DELETE /api/admin/courses/{id}`
- **តារាង Database ពាក់ព័ន្ធ៖** `courses`, `course_offerings`, `course_enrollments`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `admin/CourseManagementFragment` និង `CourseCurriculumAuditFragment` → `AddCourseActivity` ឬត្រឡប់ `RESULT_OK`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រលម្អិតថ្មីនេះបង្ហាញទិន្នន័យមុខវិជ្ជាបានពេញលេញ ស្អាត ហើយអាចកែ បិទ/បើកស្ថានភាព ឬលុបបានដោយសុវត្ថិភាព។"*

---

### 89. `ClassManagementFragment` (`admin/classes/ClassManagementFragment.java` • Layout: `fragment_admin_classes.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងគ្រប់គ្រងថ្នាក់រៀន កាលវិភាគ អគារ និងការចុះឈ្មោះចូលថ្នាក់ (Class Management)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** មជ្ឈមណ្ឌលគ្រប់គ្រង ៥ ផ្នែកធំៗ (`Classes Directory`, `Class Schedules`, `Buildings & Floors`, `Teacher Assignment`, `Class Enrollments`)។
- **ធាតុផ្សំ UI៖** Sub-tabs ៥, Search inputs, Day-of-week Filter Chips, KPI Summary Cards, `RecyclerView` តាម Tab, ប៊ូតុង Action តាម Tab (`+ Add Class`, `+ Add Schedule`, `+ Add Building`, `+ Assign Teacher`, `+ Bulk Enroll`)។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Building → Floor → Room hierarchy, Schedule Conflict Status, Capacity Utilization %, Empty/Loading states។
- **ប៊ូតុង និងសកម្មភាព៖**
  - ចុចលើ Class Directory Item: បើក **`AdminClassDetailActivity`** *(បានដំឡើងពី AlertDialog មកជា Activity ថ្មីពេញលេញក្នុង Audit នេះ!)*
  - Action Buttons: បើក `AddClassActivity`, `AddClassScheduleActivity`, `AddBuildingActivity`, `AssignTeacherClassActivity`, `BulkClassEnrollmentActivity`, Delete Schedule/Add Floor។
- **ប្រភពទិន្នន័យ៖** ClassRoom, Schedule, Building, Enrollment & Academic Structure APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/classes`, `GET /api/classes/{id}`, `GET /api/class-schedules`, `DELETE /api/class-schedules/{id}`, `GET /api/buildings`, `POST /api/floors`, `GET /api/admin/academic-structure`, `GET /api/class-enrollments`
- **តារាង Database ពាក់ព័ន្ធ៖** `classes`, `buildings`, `floors`, `class_schedules`, `course_enrollments`, `course_offerings`, `class_teacher`, `departments`, `academic_years`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Drawer ក្នុង `AdminMainActivity` / `AdminDashboardFragment` → `AdminClassDetailActivity`, `AddClassActivity`, `AddClassScheduleActivity`, `AddBuildingActivity`, `AssignTeacherClassActivity`, `BulkClassEnrollmentActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"គ្រប់គ្រងរចនាសម្ព័ន្ធសិក្សាទាំងមូលចាប់ពីអគារ ជាន់ ថ្នាក់រៀន កាលវិភាគប្រចាំសប្តាហ៍ គ្រូប្រចាំថ្នាក់ រហូតដល់ការចុះឈ្មោះនិស្សិតចូលថ្នាក់។"*

---

### 90. `AdminClassDetailActivity` (`admin/classes/AdminClassDetailActivity.java` • Layout: `activity_admin_class_detail.xml`) ★ *ទំព័រថ្មីក្នុង Audit*
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រព័ត៌មានលម្អិតថ្នាក់រៀន និងបញ្ជីនិស្សិតក្នុងថ្នាក់ (Admin Class Detail)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** ជំនួស AlertDialog ទាញយកទិន្នន័យថ្នាក់លម្អិតពី API មកបង្ហាញជាកាតស្អាត រួមមាន អគារ/ជាន់ កម្រិតឆ្នាំ មុខវិជ្ជា និងគ្រូប្រចាំថ្នាក់ ព្រមទាំង **បញ្ជីនិស្សិតទាំងអស់ក្នុងថ្នាក់** ដែលអាចចុចចូលមើល `AdminStudentDetailActivity` ផ្ទាល់។
- **ធាតុផ្សំ UI៖** Toolbar, Hero Card (Code, Name, Status, Year Level, Seats Capacity), Location & Academic Card, Courses & Teachers Card, Enrolled Students Roster Card, Bottom Buttons (`Edit / Assign Floor`, `Bulk Enroll`)។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Real-time Seat count (`Enrolled / Capacity Seats`), Empty roster notice, RoleGuard។
- **ប៊ូតុង និងសកម្មភាព៖**
  - ចុចលើនិស្សិតក្នុងបញ្ជី → បើក `AdminStudentDetailActivity`
  - `btnClassDetailEdit` → បើក `AddClassActivity`
  - `btnClassDetailEnroll` → បើក `BulkClassEnrollmentActivity`
- **ប្រភពទិន្នន័យ៖** Class Detail API (`ClassRoomController::show`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/classes/{id}`
- **តារាង Database ពាក់ព័ន្ធ៖** `classes`, `course_offerings`, `course_offering_teachers`, `students`, `buildings`, `floors`, `departments`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `ClassManagementFragment` → `AdminStudentDetailActivity`, `AddClassActivity`, `BulkClassEnrollmentActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រថ្មីនេះជួយ Admin មើលទីតាំងថ្នាក់ មុខវិជ្ជាបង្រៀន និងបញ្ជីនិស្សិតទាំងអស់ក្នុងថ្នាក់បានច្បាស់លាស់ ព្រមទាំងចុចចូលមើលប្រវត្តិរូបនិស្សិតម្នាក់ៗ ឬបញ្ចូលនិស្សិតបន្ថែម។"*

---

### 91. `AddClassActivity` (`admin/classes/AddClassActivity.java` • Layout: `activity_add_class.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័របង្កើត និងកែសម្រួលថ្នាក់រៀន (Add / Edit Class)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** បង្កើត ឬកែថ្នាក់រៀន ដោយចងភ្ជាប់ Department → Program, Academic Year → Semester, Building → Floor, Year Level (`Y1`–`Y4`) និង Capacity។
- **ធាតុផ្សំ UI៖** Input Fields, Selector Triggers (`RecordSelectorActivity`), Year Level & Status dropdowns, Save/Cancel Buttons។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Building → Floor dependent selection (ប្តូរ Building សម្អាត Floor ចាស់)។
- **ប៊ូតុង និងសកម្មភាព៖** `POST /api/classes` ឬ `PUT /api/classes/{id}`។
- **ប្រភពទិន្នន័យ៖** Class Create/Update API (`ClassRoomController`)។
- **ចំណុចតភ្ជាប់ API៖** `POST /api/classes`, `PUT /api/classes/{id}`
- **តារាង Database ពាក់ព័ន្ធ៖** `classes`, `departments`, `programs`, `academic_years`, `buildings`, `floors`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `ClassManagementFragment`, `AdminClassDetailActivity` → `RecordSelectorActivity` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"គាំទ្រឋានានុក្រម Building → Floor → Class ដើម្បីធានាថាកាលវិភាគ និងបន្ទប់សិក្សាមិនជាន់គ្នា។"*

---

### 92. `AddClassScheduleActivity` (`admin/classes/AddClassScheduleActivity.java` • Layout: `activity_add_class_schedule.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័របង្កើត និងកែសម្រួលកាលវិភាគសិក្សា (Add / Edit Class Schedule)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** កំណត់កាលវិភាគបង្រៀន (Class, Course, Teacher, Building, Floor, Room, Day of Week, Start/End Time) ជាមួយការផ្ទៀងផ្ទាត់ការជាន់ម៉ោង/គ្រូ/បន្ទប់នៅ Server។
- **ធាតុផ្សំ UI៖** Pickers (`RecordSelectorActivity`), Time Pickers, Day Dropdown, Save Button។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Server Conflict `422` validation message ប្រសិនបើម៉ោងប៉ះគ្នា។
- **ប៊ូតុង និងសកម្មភាព៖** ហៅ `POST /api/class-schedules` ឬ `PUT /api/class-schedules/{id}`។
- **ប្រភពទិន្នន័យ៖** Class Schedule API (`ClassScheduleController`)។
- **ចំណុចតភ្ជាប់ API៖** `POST /api/class-schedules`, `PUT /api/class-schedules/{id}`
- **តារាង Database ពាក់ព័ន្ធ៖** `class_schedules`, `classes`, `courses`, `teachers`, `buildings`, `floors`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `ClassManagementFragment` → `RecordSelectorActivity` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"រៀបចំកាលវិភាគសិក្សាប្រចាំសប្តាហ៍ ដែលនឹងបង្ហាញបន្ទប់ និងម៉ោងសិក្សាដោយស្វ័យប្រវត្តិក្នុងគណនីនិស្សិត និងគ្រូ។"*

---

### 93. `AddBuildingActivity` (`admin/classes/AddBuildingActivity.java` • Layout: `activity_add_building.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័របន្ថែម និងកែសម្រួលអគារសិក្សា (Add / Edit Building)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** បង្កើត ឬកែអគារសិក្សា លេខកូដអគារ និងចំនួនជាន់ (បង្កើតជាន់ដោយស្វ័យប្រវត្តិនៅ Backend)។
- **ធាតុផ្សំ UI៖** Code, Name, Number of Floors, Description, Status Dropdown, Save Button។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Duplicate name/code validation message។
- **ប៊ូតុង និងសកម្មភាព៖** ហៅ `POST /api/buildings` ឬ `PUT /api/buildings/{id}`។
- **ប្រភពទិន្នន័យ៖** Building API (`BuildingController`)។
- **ចំណុចតភ្ជាប់ API៖** `POST /api/buildings`, `PUT /api/buildings/{id}`
- **តារាង Database ពាក់ព័ន្ធ៖** `buildings`, `floors`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `ClassManagementFragment` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"បង្កើតអគារ និងបង្កើតជាន់ដោយស្វ័យប្រវត្តិសម្រាប់ចងភ្ជាប់បន្ទប់រៀន។"*

---

### 94. `AssignTeacherClassActivity` (`admin/classes/AssignTeacherClassActivity.java` • Layout: `activity_assign_teacher_class.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រចាត់តាំងគ្រូទៅមុខវិជ្ជា ឬថ្នាក់រៀន (Assign Teacher to Course/Class)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** ចាត់តាំងគ្រូបង្រៀនទៅមុខវិជ្ជា/Offerings (`assignFacultyCourses`) ឬថ្នាក់រៀន (`assignFacultyClasses`)។
- **ធាតុផ្សំ UI៖** Pickers ជ្រើសគ្រូ មុខវិជ្ជា/ថ្នាក់ ប៊ូតុង Confirm Assignment។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Assignment Scope (`Course` vs `Class`) & Confirmation feedback។
- **ប៊ូតុង និងសកម្មភាព៖** ហៅ `POST /api/faculty-evaluators/teachers/{id}/courses` ឬ `/classes`។
- **ប្រភពទិន្នន័យ៖** Faculty Evaluator Assignment API (`FacultyEvaluatorController`)។
- **ចំណុចតភ្ជាប់ API៖** `POST /api/faculty-evaluators/teachers/{id}/courses`, `POST /api/faculty-evaluators/teachers/{id}/classes`
- **តារាង Database ពាក់ព័ន្ធ៖** `teachers`, `course_offering_teachers`, `class_teacher`, `course_offerings`, `classes`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `CourseManagementFragment`, `ClassManagementFragment` → `RecordSelectorActivity` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ការចាត់តាំងគ្រូនៅទំព័រនេះផ្តល់សិទ្ធិឱ្យគ្រូនោះអាចមើលឃើញ និងគ្រប់គ្រងមុខវិជ្ជា/ថ្នាក់នោះនៅក្នុង Teacher App ភ្លាម។"*

---

### 95. `BulkClassEnrollmentActivity` (`admin/classes/BulkClassEnrollmentActivity.java` • Layout: `activity_bulk_class_enrollment.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រចុះឈ្មោះនិស្សិតចូលថ្នាក់ជាក្រុម (Bulk Class Enrollment & File Import)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** ជ្រើសថ្នាក់រៀន ស្វែងរកនិស្សិតដែលមានលក្ខណៈសម្បត្តិគ្រប់គ្រាន់ (`Eligible Students`), គូសធីកបញ្ចូលម្តងច្រើននាក់ ឬ Import CSV/Excel ដើម្បីចុះឈ្មោះនិស្សិតចូលថ្នាក់ និងមុខវិជ្ជាក្នុងថ្នាក់នោះដោយស្វ័យប្រវត្តិ។
- **ធាតុផ្សំ UI៖** Class Picker Card, ប្រអប់ស្វែងរក, ប៊ូតុង Select All / Deselect All, `RecyclerView` Checkboxes (`item_bulk_enroll_student`), Load More Pagination, ប៊ូតុង Confirm & Import CSV។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Already Enrolled indicator, Ineligible reason hint, Inactive class warning (`err_inactive_class`), Selected Counter។
- **ប៊ូតុង និងសកម្មភាព៖**
  - Select Class → `RecordSelectorActivity`
  - `Confirm` → `POST /api/class-enrollments/bulk`
  - `Import CSV` → `previewClassEnrollmentImport` + `confirmClassEnrollmentImport`។
- **ប្រភពទិន្នន័យ៖** Class Enrollment API (`ClassEnrollmentController`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/class-enrollments/eligible-students`, `POST /api/class-enrollments/bulk`, `POST /api/class-enrollments/import/preview`, `POST /api/class-enrollments/import/confirm`
- **តារាង Database ពាក់ព័ន្ធ៖** `classes`, `students`, `course_enrollments`, `course_offerings`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `ClassManagementFragment`, `AdminClassDetailActivity` → `RecordSelectorActivity` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"Admin អាចចុះឈ្មោះនិស្សិតច្រើននាក់ចូលថ្នាក់រៀនតែមួយដង ដោយប្រព័ន្ធពិនិត្យសិទ្ធិ និងបង្កើត Enrollment ចូលមុខវិជ្ជារបស់ថ្នាក់នោះដោយស្វ័យប្រវត្តិ។"*

---

### 96. `RecordSelectorActivity` (`admin/classes/RecordSelectorActivity.java` • Layout: `activity_record_selector.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រស្វែងរក និងជ្រើសរើសទិន្នន័យ (Universal Record Selector)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** ជា Picker រួមសម្រាប់ជ្រើស `Department`, `Program`, `Academic Year`, `Semester`, `Class`, `Course`, `Teacher`, `Student`, `Building`, `Floor` ដោយមានការស្វែងរក Live Search ជាមួយ Debounce លើ API។
- **ធាតុផ្សំ UI៖** Toolbar, ប្រអប់ស្វែងរក, `RecyclerView` (`item_selectable_record`), Empty/Loading States។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Type-specific metadata subtitles។
- **ប៊ូតុង និងសកម្មភាព៖** ចុចលើជួរណាមួយបញ្ជូន `RESULT_OK` (`RESULT_ID`, `RESULT_TITLE`, `RESULT_RAW_JSON`) ត្រឡប់ទៅ Activity ដើម។
- **ប្រភពទិន្នន័យ៖** Lookup & Catalog APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/class-enrollments/lookups`, `GET /api/teachers`, `GET /api/admin/courses`, `GET /api/students`, `GET /api/buildings`, `GET /api/floors`
- **តារាង Database ពាក់ព័ន្ធ៖** `departments`, `programs`, `academic_years`, `semesters`, `classes`, `courses`, `teachers`, `students`, `buildings`, `floors`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** ហៅជា `StartActivityForResult` ពី `AddCourseActivity`, `AddClassActivity`, `AddClassScheduleActivity`, `AssignTeacherClassActivity`, `BulkClassEnrollmentActivity`, `CourseManagementFragment` → ត្រឡប់ Result។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រ Reusable Picker នេះធានាការភ្ជាប់ Foreign Keys រវាងមហាវិទ្យាល័យ ថ្នាក់ អគារ និងគ្រូឱ្យត្រឹមត្រូវទាំងស្រុង។"*

---

### 97. `CourseCurriculumAuditFragment` (`admin/classes/CourseCurriculumAuditFragment.java` • Layout: `fragment_admin_curriculum_audit.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងសវនកម្មកម្មវិធីសិក្សា (Curriculum Audit)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** ត្រួតពិនិត្យមុខវិជ្ជាទាំងអស់ដើម្បីរកមុខវិជ្ជាដែលខ្វះគ្រូបង្រៀន (`Missing Teacher`) ឬខ្វះ Syllabus (`Missing Syllabus`)។ *(បានកែលម្អទាំងស្រុងក្នុង Audit នេះ លុបទិន្នន័យក្លែងក្លាយ Engr. John Reyes ហើយភ្ជាប់ទៅ `getCoursesCatalog()` និងបើក `AdminCourseDetailActivity`។)*
- **ធាតុផ្សំ UI៖** កាតសង្ខេប ៤ (`Reviewed`, `Assigned`, `Missing Teacher/Syllabus`, `Issues Found`), Search Box, `RecyclerView` (`CurriculumAuditAdapter`)។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ផ្លាក `Verified`, `Missing Teacher`, `Missing Syllabus`។
- **ប៊ូតុង និងសកម្មភាព៖** ចុចលើមុខវិជ្ជាដើម្បីបើក `AdminCourseDetailActivity` កែសម្រួល/ចាត់តាំងគ្រូ។
- **ប្រភពទិន្នន័យ៖** Admin Courses Catalog API (`AdminCourseController`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/admin/courses`
- **តារាង Database ពាក់ព័ន្ធ៖** `courses`, `teachers`, `departments`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មុខងារត្រួតពិនិត្យកម្មវិធីសិក្សា → `AdminCourseDetailActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ជួយ Admin ស្វែងរកមុខវិជ្ជាណាដែលមិនទាន់មានគ្រូបង្រៀន ឬមិនទាន់មាន Syllabus ពី Database ពិតប្រាកដ។"*

---

### 98. `IncidentResolutionWorkspaceFragment` (`admin/classes/IncidentResolutionWorkspaceFragment.java` • Layout: `fragment_admin_incident_workspace.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងដោះស្រាយឧប្បត្តិហេតុ និងករណីនិស្សិត (Incident Resolution Workspace)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** តាមដាន និងដោះស្រាយករណីសិក្សា/វិន័យ/ហានិភ័យបន្ទាន់ (`Open`, `Under Review`, `Resolved`, `High Priority`) និងប្តូរស្ថានភាពករណី។
- **ធាតុផ្សំ UI៖** កាតស្ថិតិ ៤, Search Box, `RecyclerView` (`IncidentWorkspaceAdapter`), ប៊ូតុងទៅ `ReportsFragment`។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Priority (`High`, `Medium`, `Low`) និង Resolution Status (`Open`, `In Review`, `Resolved`)។
- **ប៊ូតុង និងសកម្មភាព៖** ចុចលើករណីដើម្បីធ្វើបច្ចុប្បន្នភាពស្ថានភាព (`PUT /api/incidents/{id}`) ឬចុចលើឈ្មោះនិស្សិតបើក `AdminStudentDetailActivity`។
- **ប្រភពទិន្នន័យ៖** Incidents API (`IncidentController`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/incidents`, `PUT /api/incidents/{id}`
- **តារាង Database ពាក់ព័ន្ធ៖** `incidents`, `students`, `users`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Drawer (`Incident Workspace`) ក្នុង `AdminMainActivity` → `AdminStudentDetailActivity`, `ReportsFragment`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ផ្តល់កន្លែងការងារសម្រាប់ Admin ចាត់ចែងដោះស្រាយបញ្ហាពាក់ព័ន្ធនឹងនិស្សិត និងបើកមើលប្រវត្តិរូបនិស្សិតនោះភ្លាម។"*

---

### 99. `ReportsFragment` (`admin/ReportsFragment.java` • Layout: `fragment_admin_reports.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងរបាយការណ៍ និងវិភាគទូទាំងប្រព័ន្ធ (Admin Reports & Analytics Hub)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** មជ្ឈមណ្ឌលវិភាគ និងរបាយការណ៍ ៤ ផ្នែក (`Overview`, `Students`, `Teachers`, `Courses`) ជាមួយ Sub-tabs និងក្រាហ្វ Line/Bar/Pie Charts ទាំងអស់ភ្ជាប់ទិន្នន័យពិតពី `AdminReportsAnalyticsController`។
- **ធាតុផ្សំ UI៖** Primary Tabs ៤, Sub-tabs ៤ តាមផ្នែកនីមួយៗ, Spinners ឆ្នាំសិក្សា/ឆមាស, Charts ជាច្រើន (`LineChart`, `PieChart`, `BarChart`), `RecyclerView` rosters, ប៊ូតុង Export Report។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Dynamic KPI Cards, Attendance % (`present+late`), Teacher Workload (`Normal`, `Underloaded`, `Overloaded`), Pass Rate %។
- **ប៊ូតុង និងសកម្មភាព៖** ប្តូរ Primary/Sub Tabs ត្រងឆ្នាំ/ឆមាស និងចុច Export → `GenerateReportActivity`។
- **ប្រភពទិន្នន័យ៖** Admin Reports & Analytics API Suite (`AdminReportsAnalyticsController`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/reports/analytics/lookups`, `GET /api/reports/analytics/overview`, `GET /api/reports/analytics/attendance`, `GET /api/reports/analytics/teachers`, `GET /api/reports/analytics/courses`
- **តារាង Database ពាក់ព័ន្ធ៖** `students`, `teachers`, `courses`, `classes`, `course_enrollments`, `attendance_details`, `grades`, `gpa_records`, `faculty_reviews`, `academic_years`, `semesters`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Bottom Tab ៤ ក្នុង `AdminMainActivity` → `GenerateReportActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័ររបាយការណ៍ធំជាងគេក្នុង Admin App បង្ហាញក្រាហ្វ និងស្ថិតិលម្អិតគ្រប់ផ្នែក (និស្សិត គ្រូ មុខវិជ្ជា វត្តមាន) ព្រមទាំងភ្ជាប់ទៅ Generate Report សម្រាប់នាំចេញ។"*

---

### 100. `StudentReportActivity` (`admin/reports/StudentReportActivity.java` • Layout: `activity_admin_student_report.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័ររបាយការណ៍លម្អិតនិស្សិត (Admin Student Report)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញទិដ្ឋភាពរបាយការណ៍និស្សិត (`Overview`, `Enrollment`, `Performance`, `Attendance`) ជាមួយ LineChart និង PieChart ពិតប្រាកដ។ *(បានជួសជុលទាំងស្រុងក្នុង Audit នេះ លុបទិន្នន័យក្លែងក្លាយ `"2,845"` និងភ្ជាប់ API ពិត!)*
- **ធាតុផ្សំ UI៖** Tabs ៤, Spinners ឆ្នាំសិក្សា/ឆមាស, KPI Cards, `LineChart` (Enrollment / Attendance Rate), `PieChart` (Departments / GPA Distribution / Attendance Breakdown), ប៊ូតុង Export / View Details។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Center text បង្ហាញចំនួននិស្សិតពិត, Empty state chart handler។
- **ប៊ូតុង និងសកម្មភាព៖**
  - `btnExportStudentReport` → `GenerateReportActivity` (`type="Students"`)
  - `btnViewStudentReportDetails` → Dialog បង្ហាញ Year Level Breakdown & Enrolled Roster ពិតប្រាកដ។
- **ប្រភពទិន្នន័យ៖** Admin Reports Analytics API (`AdminReportsAnalyticsController`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/reports/analytics/lookups`, `GET /api/reports/analytics/overview`, `GET /api/reports/analytics/attendance`
- **តារាង Database ពាក់ព័ន្ធ៖** `students`, `departments`, `course_enrollments`, `gpa_records`, `attendance_details`, `academic_years`, `semesters`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Activity ឯករាជ្យសម្រាប់របាយការណ៍និស្សិត → `GenerateReportActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"បង្ហាញក្រាហ្វ និងភាគរយនិស្សិតពិតតាមមហាវិទ្យាល័យ និន្នាការ GPA និងវត្តមាន ដោយត្រងបានតាមឆ្នាំសិក្សា និងឆមាស។"*

---

### 101. `CourseReportActivity` (`admin/reports/CourseReportActivity.java` • Layout: `activity_course_report.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័ររបាយការណ៍លម្អិតមុខវិជ្ជា (Admin Course Report)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញស្ថិតិមុខវិជ្ជា, Top 5 Courses តាម Tab (`Enrollment`, `Completion`, `Performance`) និង `BarChart` តាមឆមាស/អត្រាជាប់។ *(បានជួសជុលទាំងស្រុងក្នុង Audit នេះ លុបទិន្នន័យក្លែងក្លាយ `"6,245"` / `"CS101"` static ហើយភ្ជាប់ API ពិត!)*
- **ធាតុផ្សំ UI៖** Tabs ៤, Spinners ឆ្នាំ/ឆមាស, KPI ៤, Dynamic Top 5 Courses container, `BarChart`, Bottom Action Buttons។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Progress bars dynamic colors, Empty state notice។
- **ប៊ូតុង និងសកម្មភាព៖**
  - `btnExportCourseReport` → `GenerateReportActivity` (`type="Courses"`)
  - `btnViewCourseReportDetails` → បង្ហាញបញ្ជីមុខវិជ្ជាទាំងអស់ (Enrolled, Completion %, Pass Rate %) ពី API។
- **ប្រភពទិន្នន័យ៖** Admin Reports Course Analytics API។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/reports/analytics/lookups`, `GET /api/reports/analytics/courses`
- **តារាង Database ពាក់ព័ន្ធ៖** `courses`, `course_enrollments`, `grades`, `semesters`, `academic_years`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Activity ឯករាជ្យសម្រាប់របាយការណ៍មុខវិជ្ជា → `GenerateReportActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"Admin អាចមើលមុខវិជ្ជាទាំង ៥ ដែលមានអ្នករៀនច្រើនជាងគេ ឬអត្រាបញ្ចប់ខ្ពស់បំផុត តាមទិន្នន័យពិតពី Database។"*

---

### 102. `GenerateReportActivity` (`admin/reports/GenerateReportActivity.java` • Layout: `activity_generate_report.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័របង្កើត និងនាំចេញរបាយការណ៍ (Generate & Export Admin Report)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** អនុញ្ញាតឱ្យ Admin ជ្រើសប្រភេទរបាយការណ៍ (`Students`, `Teachers`, `Courses`, `Attendance`, `Academic`), តម្រងឆ្នាំ/ឆមាស/មហាវិទ្យាល័យ/កម្រិតឆ្នាំ ហើយ Generate preview ឬ Export ទាញយកជា **CSV/PDF**។
- **ធាតុផ្សំ UI៖** Spinners តម្រង, RadioGroup Format (PDF/CSV), Preview Section, ប៊ូតុង Generate / Export។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Download progress & File saved feedback។
- **ប៊ូតុង និងសកម្មភាព៖** `POST /api/reports/analytics/generate` និង `POST /api/reports/analytics/export`។
- **ប្រភពទិន្នន័យ៖** Admin Report Generator & Exporter API។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/reports/analytics/lookups`, `POST /api/reports/analytics/generate`, `POST /api/reports/analytics/export`
- **តារាង Database ពាក់ព័ន្ធ៖** `reports`, `students`, `teachers`, `courses`, `attendance_details`, `grades`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `ReportsFragment`, `StudentReportActivity`, `CourseReportActivity`, `AdminBackupDataActivity` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រនេះបង្កើត និងទាញយកឯកសាររបាយការណ៍ផ្លូវការចេញពី Backend API ដោយគាំទ្រការត្រងលម្អិត។"*

---

### 103. `MlModelEvaluationFragment` (`admin/MlModelEvaluationFragment.java` • Layout: `fragment_ml_model_evaluation.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងវាយតម្លៃម៉ូដែល ML និង Risk Analytics (Admin ML & Risk Evaluation)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញលទ្ធផលវាយតម្លៃម៉ូដែល AI/ML, $R^2$ Score, Error metrics, Confusion/Feature Importance និងប្រវត្តិវាយតម្លៃទូទាំងប្រព័ន្ធ។
- **ធាតុផ្សំ UI៖** Metric Cards, Charts, Searchable Evaluation History RecyclerView។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Accuracy badges & Empty State ប្រសិនបើ ML records ទទេ។
- **ប៊ូតុង និងសកម្មភាព៖** ត្រង/ស្វែងរកសម័យវាយតម្លៃ និង Refresh។
- **ប្រភពទិន្នន័យ៖** Admin Model Evaluation API (`AdminAnalyticsController::modelEvaluation`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/admin/analytics/model-evaluation`
- **តារាង Database ពាក់ព័ន្ធ៖** `predictions`, `risk_assessments`, `exam_results`, `learning_analytics`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Drawer (`ML & Risk Analytics`) ក្នុង `AdminMainActivity` / `AdminDashboardFragment`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ផ្តល់ទិន្នន័យសវនកម្មលើគុណភាពនៃការទស្សន៍ទាយរបស់ម៉ូដែល ML ក្នុង SLAS។"*

---

### 104. `SystemStatisticsFragment` (`admin/SystemStatisticsFragment.java` • Layout: `fragment_system_statistics.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងស្ថិតិទូទៅនៃប្រព័ន្ធ (System Statistics)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញតួលេខសរុបទូទាំងសាកលវិទ្យាល័យ បែងចែកនិស្សិតតាម Year Level (`Y1`–`Y4`), កាត Risk Distribution និង Grade Distribution (`A`–`F`)។
- **ធាតុផ្សំ UI៖** Statistics KPI Grid, Charts និន្នាការ/បែងចែកនិទ្ទេស, Year Level Breakdown Cards។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Real counts & percentages ពី `DashboardController::adminStatistics`។
- **ប៊ូតុង និងសកម្មភាព៖** Refresh ស្ថិតិ និង Back។
- **ប្រភពទិន្នន័យ៖** Admin Statistics API。
- **ចំណុចតភ្ជាប់ API៖** `GET /api/admin/dashboard/statistics`
- **តារាង Database ពាក់ព័ន្ធ៖** `students`, `teachers`, `courses`, `departments`, `classes`, `predictions`, `risk_assessments`, `grades`, `users`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Drawer (`System Statistics`) ក្នុង `AdminMainActivity` / `AdminDashboardFragment`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"បង្ហាញស្ថិតិរចនាសម្ព័ន្ធសរុប ការបែងចែកនិទ្ទេស និងកម្រិតហានិភ័យទូទាំងប្រព័ន្ធ។"*

---

### 105. `NotificationFragment` (`admin/NotificationFragment.java` • Layout: `fragment_admin_notifications.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងគ្រប់គ្រងការជូនដំណឹងរបស់ Admin (Admin Notifications Center)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញសារជូនដំណឹងពិតប្រាកដពី Laravel API, ត្រងតាមប្រភេទ (`All`, `Unread`, `System`, `User`, `Course`), ស្វែងរក, Mark All Read, Delete Read និងចុចមើលលម្អិត។ *(បានជួសជុលទាំងស្រុងក្នុង Audit នេះ លុបទិន្នន័យក្លែងក្លាយ "Ms. Dany" / "Mr. Vanna" ហើយភ្ជាប់ទៅ Notifications API ពិត!)*
- **ធាតុផ្សំ UI៖** Top bar + Unread Badge + Overflow Menu, Filter Chips ៥, Search Box, Filter Dialog Icon, `RecyclerView`, Empty/Error/No-Match States។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Unread Dot, Group Headers, Dynamic Chip Counters (`All (n)`, `Unread (n)`, `System (n)`, `User (n)`, `Course (n)`)។
- **ប៊ូតុង និងសកម្មភាព៖**
  - ចុច Notification → ហៅ `PATCH /api/notifications/{id}/read` រួចបើក `NotificationDetailActivity`
  - Overflow Menu → `Mark All as Read` (`POST /api/notifications/read-all`), `Delete Read` (`DELETE /api/notifications/{id}`), `Notification Settings` (`NotificationSettingsActivity`)
  - Reset Search/Filters button។
- **ប្រភពទិន្នន័យ៖** Notifications API (`NotificationController`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/notifications`, `PATCH /api/notifications/{id}/read`, `POST /api/notifications/read-all`, `DELETE /api/notifications/{id}`
- **តារាង Database ពាក់ព័ន្ធ៖** `notifications`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Drawer (`Notifications`) ក្នុង `AdminMainActivity` / Bell icon → `NotificationDetailActivity`, `NotificationSettingsActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រនេះទាញការជូនដំណឹងពិតពី Backend អនុញ្ញាតឱ្យ Admin ស្វែងរក ត្រងតាមប្រភេទ គូសអានទាំងអស់ ឬលុបសារដែលបានអានរួច។"*

---

### 106. `NotificationDetailActivity` (`admin/NotificationDetailActivity.java` • Layout: `activity_notification_detail.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រព័ត៌មានលម្អិតនៃការជូនដំណឹង (Admin Notification Detail)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញចំណងជើង សារពេញលេញ Metadata (ID, Category, Group, Timestamp, Read/Unread Status) និងប៊ូតុង Mark as Read / Delete Notification លើ API។ *(បានជួសជុលទាំងស្រុងក្នុង Audit នេះ លុបទិន្នន័យក្លែងក្លាយ Ms. Dany / Approve / Reject ហើយភ្ជាប់ API ពិត!)*
- **ធាតុផ្សំ UI៖** Header Back, Notification Summary Card, Metadata Card, Action Card (`Mark as Read`, `Delete`)។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Badge ពណ៌បៃតង `Read` vs ទឹកក្រូច `Unread`។
- **ប៊ូតុង និងសកម្មភាព៖**
  - `Mark as Read`: `PATCH /api/notifications/{id}/read`
  - `Delete`: Confirmation Dialog → `DELETE /api/notifications/{id}` រួចបញ្ជូន `RESULT_OK` ត្រឡប់ទៅ Inbox។
- **ប្រភពទិន្នន័យ៖** `Notification` Object & Notifications API។
- **ចំណុចតភ្ជាប់ API៖** `PATCH /api/notifications/{id}/read`, `DELETE /api/notifications/{id}`
- **តារាង Database ពាក់ព័ន្ធ៖** `notifications`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `admin/NotificationFragment` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"បង្ហាញខ្លឹមសារ Notification ពេញលេញ ស្ថានភាពអាន/មិនទាន់អាន និងអនុញ្ញាតឱ្យគូសអាន ឬលុបសារចេញពី Database ដោយផ្ទាល់។"*

---

### 107. `AdminProfileFragment` (`admin/AdminProfileFragment.java` • Layout: `fragment_admin_profile.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងប្រវត្តិរូបអ្នកគ្រប់គ្រងប្រព័ន្ធ (Admin Profile)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញឈ្មោះ អ៊ីមែល តួនាទី ស្ថានភាពគណនី និងចំនួនអ្នកប្រើប្រាស់សរុប (`Total Users`) ពី Laravel API រួមទាំងប៊ូតុង Edit Profile, Change Password និង Logout។ *(បានជួសជុលក្នុង Audit នេះ លុប `admin@university.edu` / `1,234` ក្លែងក្លាយ និងជួសជុលប៊ូតុង Edit Profile ឱ្យបើក `ProfileSettingsActivity`!)*
- **ធាតុផ្សំ UI៖** Header Purple Gradient, កាត KPI ២ (`Total Users`, `Account Status`), Details Card (Full Name, Email, Role, Account Updated), ប៊ូតុង ៣។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Active/Inactive Status, live sync in `onResume`។
- **ប៊ូតុង និងសកម្មភាព៖**
  - `btnEditProfile` → `ProfileSettingsActivity`
  - `btnProfileChangePassword` → `ChangePasswordActivity`
  - `btnProfileLogout` → Logout API + Clear Session → `LoginActivity`
- **ប្រភពទិន្នន័យ៖** Profile & Admin Statistics APIs + `SessionManager`។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/profile`, `GET /api/admin/dashboard/statistics`, `POST /api/logout`
- **តារាង Database ពាក់ព័ន្ធ៖** `users`, `admins`, `personal_access_tokens`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** ចូលពី Profile Icon ក្នុង `AdminDashboardFragment` → `ProfileSettingsActivity`, `ChangePasswordActivity`, `LoginActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"Admin អាចមើលព័ត៌មានគណនីពិតរបស់ខ្លួន កែប្រវត្តិរូប ប្តូរពាក្យសម្ងាត់ ឬ Logout ដោយដក Token នៅ Server ចេញ។"*

---

### 108. `SystemSettingsFragment` (`admin/SystemSettingsFragment.java` • Layout: `fragment_system_settings.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ផ្ទាំងការកំណត់ប្រព័ន្ធ Admin (Admin Settings Hub)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** ម៉ឺនុយចែកចាយទៅគ្រប់ទំព័រកំណត់គណនី និងប្រព័ន្ធទាំង ៨ រួមទាំងប៊ូតុង Logout។ *(បានធ្វើបច្ចុប្បន្នភាព Logout ឱ្យហៅ `POST /api/logout`។)*
- **ធាតុផ្សំ UI៖** កាតម៉ឺនុយ ៩ (`Profile Settings`, `Account Security`, `Notification Settings`, `Appearance & Language`, `Academic System Config`, `User Roles Policy`, `Database Status & Export`, `About System`, `Logout`)។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Logout Confirmation Material Dialog។
- **ប៊ូតុង និងសកម្មភាព៖** បើកទៅ ៨ Activities កំណត់ និង Logout។
- **ប្រភពទិន្នន័យ៖** Navigation Menu & `SessionManager` / `Logout API`។
- **ចំណុចតភ្ជាប់ API៖** `POST /api/logout`
- **តារាង Database ពាក់ព័ន្ធ៖** `personal_access_tokens`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** Bottom Tab ៥ ក្នុង `AdminMainActivity` → ៨ Settings Activities ឬ `LoginActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ប្រមូលផ្តុំរាល់ការកំណត់សុវត្ថិភាព ភាសា ឆ្នាំសិក្សា គោលការណ៍សិទ្ធិ និងស្ថានភាពមូលដ្ឋានទិន្នន័យនៅកន្លែងតែមួយ។"*

---

### 109. `ProfileSettingsActivity` (`admin/users/ProfileSettingsActivity.java` • Layout: `activity_profile_settings.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រកែសម្រួលប្រវត្តិរូបផ្ទាល់ខ្លួន Admin (Admin Profile Settings)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** មើល និងកែសម្រួលឈ្មោះ អ៊ីមែល លេខទូរស័ព្ទ ថ្ងៃកំណើត និងភេទរបស់ Admin បច្ចុប្បន្ន ហើយរក្សាទុកចូល Database។
- **ធាតុផ្សំ UI៖** Avatar, Name/Role/Status Header, Form EditTexts, DatePicker DOB, RadioGroup Gender, ប៊ូតុង Save / Cancel / Change Password។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Session & API Sync។
- **ប៊ូតុង និងសកម្មភាព៖** `btnSaveProfileSettings` → `PUT /api/profile`; `btnChangePassword` → `AccountSecurityActivity`។
- **ប្រភពទិន្នន័យ៖** Profile API (`AuthController`) + `SessionManager`។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/profile`, `PUT /api/profile`
- **តារាង Database ពាក់ព័ន្ធ៖** `users`, `admins`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `AdminMainActivity`, `AdminProfileFragment`, `SystemSettingsFragment` → `AccountSecurityActivity` ឬត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"អនុញ្ញាតឱ្យ Admin កែព័ត៌មានគណនីផ្ទាល់ខ្លួនចូល MySQL និងធ្វើបច្ចុប្បន្នភាព Session ក្នុងទូរស័ព្ទភ្លាម។"*

---

### 110. `AccountSecurityActivity` (`admin/users/AccountSecurityActivity.java` • Layout: `activity_account_security.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រសុវត្ថិភាពគណនី Admin (Account Security & Password)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** ប្តូរពាក្យសម្ងាត់គណនី Admin និងពិនិត្យវិធានសុវត្ថិភាពគណនី។
- **ធាតុផ្សំ UI៖** Current/New/Confirm Password Inputs, Password Rule Hints, Save Button។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Validation & Server error handling។
- **ប៊ូតុង និងសកម្មភាព៖** ហៅ `POST /api/change-password`។
- **ប្រភពទិន្នន័យ៖** Laravel Auth API។
- **ចំណុចតភ្ជាប់ API៖** `POST /api/change-password`
- **តារាង Database ពាក់ព័ន្ធ៖** `users`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `SystemSettingsFragment`, `ProfileSettingsActivity` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ការពារគណនី Admin ដោយផ្លាស់ប្តូរពាក្យសម្ងាត់តាម API ដែលតម្រូវឱ្យបញ្ជាក់ពាក្យសម្ងាត់ចាស់។"*

---

### 111. `NotificationSettingsActivity` (`admin/users/NotificationSettingsActivity.java` • Layout: `activity_notification_settings.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រកំណត់ចំណូលចិត្តការជូនដំណឹង (Notification Preferences)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** រក្សាទុកការបិទ/បើកប្រភេទជូនដំណឹងក្នុងម៉ាស៊ីន និងម៉ោងស្ងាត់ (`Quiet Hours` TimePicker) ក្នុង `SharedPreferences` (`SLAS_NotifSettings`)។
- **ធាតុផ្សំ UI៖** `SwitchMaterial` ១០ គ្រាប់ (Push, Email, Quiet Hours), Time Pickers (`Start`, `End`), ប៊ូតុង Save / Cancel។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Quiet Hours toggle animation។
- **ប៊ូតុង និងសកម្មភាព៖** បើក `TimePickerDialog` និងរក្សាទុកក្នុង `SharedPreferences`។
- **ប្រភពទិន្នន័យ៖** Device Local Preferences (`SLAS_NotifSettings`)។
- **ចំណុចតភ្ជាប់ API៖** គ្មាន (ការកំណត់ក្នុងឧបករណ៍)។
- **តារាង Database ពាក់ព័ន្ធ៖** គ្មាន។
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `SystemSettingsFragment`, `admin/NotificationFragment` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"អនុញ្ញាតឱ្យកំណត់ម៉ោងស្ងាត់ និងប្រភេទជូនដំណឹងដែលចង់ទទួលលើទូរស័ព្ទ។"*

---

### 112. `AdminAppearanceActivity` (`admin/AdminAppearanceActivity.java` • Layout: `activity_admin_appearance_settings.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រកំណត់រូបរាង និងភាសាប្រព័ន្ធ (Appearance & Bilingual English/Khmer Settings)
- **តួនាទីប្រើប្រាស់៖** Shared (Admin, Student, Teacher)
- **គោលបំណង និងមុខងារសំខាន់៖** ប្តូរភាសាកម្មវិធីរវាង **English (US)** និង **ភាសាខ្មែរ (KM)** តាម `AppLanguageManager`, ប្តូរ Theme (`Light`, `Dark`, `System`) និងទំហំអក្សរ (`Standard`, `Medium`, `Large`)។
- **ធាតុផ្សំ UI៖** RadioGroup Theme, Spinners Language & Font Size, ប៊ូតុង Save Preferences។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Live locale update & recreate/refresh UI។
- **ប៊ូតុង និងសកម្មភាព៖** រក្សាទុកក្នុង `LanguagePreferences` និងប្តូរ `AppCompatDelegate` / `Locale` ភ្លាមៗ។
- **ប្រភពទិន្នន័យ៖** `LanguagePreferences` (`SharedPreferences`) និង `res/values-km/strings.xml`។
- **ចំណុចតភ្ជាប់ API៖** គ្មាន (Client Localization System)។
- **តារាង Database ពាក់ព័ន្ធ៖** គ្មាន។
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `SystemSettingsFragment` (Admin), `ProfileFragment` & `StudentSettingsFragment` (Student) → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"SLAS គាំទ្រពីរភាសាពេញលេញ (អង់គ្លេស និងខ្មែរ) រួមទាំង Font ខ្មែរលើក្រាហ្វ MPAndroidChart និងរបាយការណ៍ PDF ផងដែរ។"*

---

### 113. `AdminSystemConfigActivity` (`admin/AdminSystemConfigActivity.java` • Layout: `activity_admin_system_config_settings.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រកំណត់រចនាសម្ព័ន្ធសិក្សាប្រព័ន្ធ (Academic Years, Semesters & Departments Config)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញ និងបង្កើត **Academic Years**, **Semesters** និង **Departments / Faculties** ចូលក្នុងមូលដ្ឋានទិន្នន័យដោយផ្ទាល់។
- **ធាតុផ្សំ UI៖** Forms / Dialogs សម្រាប់បង្កើតឆ្នាំសិក្សា ឆមាស (ដោយជ្រើសឆ្នាំសិក្សាពិត) និងមហាវិទ្យាល័យ, បញ្ជីទិន្នន័យបច្ចុប្បន្ន។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Active status badges & Validation。
- **ប៊ូតុង និងសកម្មភាព៖** `createAcademicYear` (`POST /api/academic-years`), `createSemester` (`POST /api/semesters`), `createDepartment` (`POST /api/departments`)។
- **ប្រភពទិន្នន័យ៖** Academic Years, Semesters, & Departments APIs។
- **ចំណុចតភ្ជាប់ API៖** `GET/POST /api/academic-years`, `GET/POST /api/semesters`, `POST /api/departments`
- **តារាង Database ពាក់ព័ន្ធ៖** `academic_years`, `semesters`, `departments`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `SystemSettingsFragment` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"Admin បង្កើតឆ្នាំសិក្សា ឆមាស និងមហាវិទ្យាល័យថ្មីពីទូរស័ព្ទ ដែលនឹងបង្ហាញជា Dropdown ប្រើទូទាំងប្រព័ន្ធភ្លាម។"*

---

### 114. `AdminUserRolesSettingsActivity` (`admin/AdminUserRolesSettingsActivity.java` • Layout: `activity_admin_user_roles_settings.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រពិនិត្យតួនាទី និងគោលការណ៍សិទ្ធិប្រព័ន្ធ (User Roles & RBAC Policy Inspection)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញតួនាទីផ្លូវការទាំង ៣ របស់ SLAS (`Admin`, `Teacher`, `Student`) ជាមួយចំនួនគណនីពិត (Active/Total) ពី API និងម៉ាទ្រីសសិទ្ធិដែលអនុវត្តនៅ Laravel Server (`EnsureUserHasRole`)។ *(បានកែលម្អក្នុង Audit នេះ លុបប៊ូតុងបង្កើត Role ក្លែងក្លាយ ហើយភ្ជាប់ទៅ `getUserStatistics()` ពិត!)*
- **ធាតុផ្សំ UI៖** System Access Roles Card (បង្ហាញចំនួន Admin, Teacher, Student ពិត), Read-only Module Matrix Card, Middleware Enforcement Card, ប៊ូតុង `RBAC Policy` & `Close`។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Live counts (`X Admins (Y Active)`, `Teachers`, `Students`)។
- **ប៊ូតុង និងសកម្មភាព៖** ចុច `RBAC Policy` បង្ហាញការពន្យល់គោលការណ៍សុវត្ថិភាព Laravel Sanctum + `EnsureUserHasRole`។
- **ប្រភពទិន្នន័យ៖** Admin Users Statistics API (`AdminUserController::statistics`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/admin/users/statistics`
- **តារាង Database ពាក់ព័ន្ធ៖** `users`, `admins`, `teachers`, `students`
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `SystemSettingsFragment` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រនេះបង្ហាញចំនួនគណនីពិតតាមតួនាទី និងពន្យល់ថាការគ្រប់គ្រងសិទ្ធិទាំងអស់ត្រូវបានចាក់សោការពារយ៉ាងតឹងរ៉ឹងនៅ Backend Middleware។"*

---

### 115. `AdminBackupDataActivity` (`admin/AdminBackupDataActivity.java` • Layout: `activity_admin_backup_data_settings.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រពិនិត្យស្ថានភាព Database និងនាំចេញទិន្នន័យ (Live Database Diagnostics & Export)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញស្ថានភាព Database MySQL ជាក់ស្តែងពី Server (`MySQL 8.4.7`, ទំហំ MB, ចំនួន Tables/Rows, Latency ms, Entity totals) និងពន្យល់គោលការណ៍ Server-side backup រួមទាំងតំណភ្ជាប់ទៅទាញយករបាយការណ៍ CSV/PDF។ *(បានកែលម្អទាំងស្រុងក្នុង Audit នេះ លុប "PostgreSQL 15 / 142.8 MB / slas_backup_20260310.sql" ក្លែងក្លាយ!)*
- **ធាតុផ្សំ UI៖** Grid Status Cards (`Size MB`, `Total Rows & Tables`, `Status & Latency ms`, `DB Engine`), Entity Summary Card, ប៊ូតុង `Refresh Live Database Status` & `Export Reports`។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** Online/Offline Live Diagnostics, Schema name (`student_learning_analytics`)។
- **ប៊ូតុង និងសកម្មភាព៖**
  - `btnCreateBackup` (`Refresh Live Database Status`): ហៅ `GET /api/admin/dashboard/system-status` + `GET /api/admin/dashboard/statistics`
  - `btnDownloadBackup1` (`Export Reports`): បើក `GenerateReportActivity`។
- **ប្រភពទិន្នន័យ៖** Admin System Status & Statistics APIs (`DashboardController::adminSystemStatus`)។
- **ចំណុចតភ្ជាប់ API៖** `GET /api/admin/dashboard/system-status`, `GET /api/admin/dashboard/statistics`
- **តារាង Database ពាក់ព័ន្ធ៖** `information_schema.TABLES` + តារាង SLAS ទាំង ៦២
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `SystemSettingsFragment` → `GenerateReportActivity`។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"ទំព័រនេះទាញព័ត៌មាន MySQL Live Diagnostics ផ្ទាល់ពី Backend (ប្រភេទ MySQL, ទំហំ MB ពិត, ៦២ តារាង) ហើយផ្តល់ផ្លូវកាត់ទៅ Export របាយការណ៍ CSV/PDF ដោយមិនបំភ្លៃការងារ Backup របស់ Server ឡើយ។"*

---

### 116. `AdminAboutSystemActivity` (`admin/AdminAboutSystemActivity.java` • Layout: `activity_admin_about_system.xml`)
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រអំពីប្រព័ន្ធ SLAS (About System)
- **តួនាទីប្រើប្រាស់៖** Admin
- **គោលបំណង និងមុខងារសំខាន់៖** បង្ហាញឈ្មោះគម្រោង កំណែកម្មវិធី បច្ចេកវិទ្យា (Android Java, Laravel 12, MySQL) និងព័ត៌មានអ្នកអភិវឌ្ឍន៍។
- **ធាតុផ្សំ UI៖** Header Back, System Logo & Metadata Cards។
- **កាត ផ្ទាំង ផ្លាកសញ្ញា និងស្ថានភាព៖** ទំព័រព័ត៌មានថេរ។
- **ប៊ូតុង និងសកម្មភាព៖** `btnBackAbout` (បិទទំព័រ)។
- **ប្រភពទិន្នន័យ៖** Resource strings/XML។
- **ចំណុចតភ្ជាប់ API៖** គ្មាន។
- **តារាង Database ពាក់ព័ន្ធ៖** គ្មាន។
- **ការតភ្ជាប់ទំព័រ (Navigation)៖** មកពី `AdminMainActivity`, `SystemSettingsFragment` → ត្រឡប់ក្រោយ។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"បង្ហាញព័ត៌មានកំណែកម្មវិធី និងស្ថាបត្យកម្មបច្ចេកទេសនៃ SLAS។"*

---

### 117–120. ទំព័របន្តដំណើរ និងគំរូ Legacy (Legacy Redirects & Placeholders)
- **117. `AddUserActivity` (`admin/users/AddUserActivity.java`)**: ទំព័រ Redirect ចាស់ — បើក `AddStudentActivity` ដោយស្វ័យប្រវត្តិ (32 បន្ទាត់)។
- **118. `EditUserActivity` (`admin/users/EditUserActivity.java`)**: កូដចាស់ទទេ (4 បន្ទាត់ មិនមែនជា Activity class) ត្រូវបានជំនួសដោយ `EditStudentActivity` / `EditTeacherActivity` / `EditAdminActivity`។
- **119. `TeacherManagementFragment` (`admin/TeacherManagementFragment.java`)**: Placeholder Redirect — បើក `UserManagementFragment` (Tab Teachers) ដោយស្វ័យប្រវត្តិ ដើម្បីរក្សាភាពត្រូវគ្នាជាមួយ Navigation Drawer ចាស់។
- **120. `FacultyEvaluationReviewFragment` (`admin/classes/FacultyEvaluationReviewFragment.java`)**: Placeholder Redirect — បើក `UserManagementFragment` (Tab ៣: Faculty Evaluations) ដោយស្វ័យប្រវត្តិ។

---

# ក្រុមទី ៥៖ ទំព័ររួម និងទំព័រដើមគំរូ (Shared Entry Template)

### 121. `MainActivity` (`MainActivity.java` • Layout: `activity_main.xml`) *(ឯកសារទី ១២១ ក្នុងបញ្ជី `*Activity.java`/`*Fragment.java` ឬទំព័រ Activity/Fragment ពិតប្រាកដទី ១២០ បើមិនរាប់ `EditUserActivity.java`)*
- **ឈ្មោះជាភាសាខ្មែរ៖** ទំព័រគំរូដើម Android (Legacy Template Activity)
- **តួនាទីប្រើប្រាស់៖** មិនប្រើក្នុងលំហូរកម្មវិធីពិត (ត្រឹម Template ដំបូងពេលបង្កើតគម្រោង Android; Launcher ពិតប្រើ `auth/SplashActivity`)។
- **របៀបពន្យល់ពេលបង្ហាញប្រព័ន្ធ៖** *"កម្មវិធីចាប់ផ្តើមពី `SplashActivity` ទៅ `LoginActivity` មិនមែន `MainActivity` នេះទេ; ទុកក្នុងគម្រោងដើម្បីមិនលុបឯកសារដើម។"*

---

# សេណារីយ៉ូបង្ហាញប្រព័ន្ធ SLAS ទាំងមូលពេលការពារ (Live Demo Script)

ដើម្បីបង្ហាញសមត្ថភាពប្រព័ន្ធឱ្យបានរលូន និងទទួលពិន្ទុខ្ពស់ពេល Demo សូមធ្វើតាមលំដាប់ ៤ ជំហានខាងក្រោម៖

1. **ជំហានទី ១ — ការចូលប្រើប្រាស់ និងគ្រប់គ្រងរចនាសម្ព័ន្ធ (Admin Portal):**
   - បើក App ពី `SplashActivity` → `LoginActivity` ចូលជា **Admin**។
   - បង្ហាញ `AdminDashboardFragment` (ស្ថិតិរួម YoY Growth %, System Status MySQL 8.4.7)។
   - បើក `UserManagementFragment` → បង្ហាញ `AdminStudentDetailActivity` (មាន Tabs ៤ + Notes) និង `AdminTeacherDetailActivity` (មាន Account Link Status & Repair)។
   - បើក `CourseManagementFragment` → ចុចលើមុខវិជ្ជាបើក **`AdminCourseDetailActivity`** → បន្ទាប់មកបើក `ClassManagementFragment` → ចុចលើថ្នាក់បើក **`AdminClassDetailActivity`** បង្ហាញបញ្ជីនិស្សិតក្នុងថ្នាក់។
   - បង្ហាញ `ReportsFragment` / `CourseReportActivity` / `StudentReportActivity` / `NotificationFragment` (ទិន្នន័យពិត ១០០%) និង `AdminBackupDataActivity` (Live MySQL status)។

2. **ជំហានទី ២ — ការគ្រប់គ្រងការបង្រៀន និង Universal Reopen (Teacher Portal):**
   - Logout → Login ជា **Teacher**។
   - បង្ហាញ `TeacherDashboardFragment` (KPI + Engagement scoped តាមថ្នាក់គ្រូ)។
   - បើក `CourseManagementFragment` → ចុចមុខវិជ្ជាបើក **`TeacherCourseDetailActivity`** → ចុច `View Students` / `Class Analytics`។
   - បើក `AttendanceFragment` ហៅឈ្មោះនិស្សិត (`Mark All Present` + Submit) រួចចុចមើល `StudentDetailActivity`។
   - បើក `TeacherAssessmentFragment` → ចូល `QuizBuilderActivity` (បង្ហាញសំណួរចម្រុះ) និងចូល **`AssessmentReopenManagerActivity`**៖ បង្ហាញការផ្តល់សិទ្ធិ Reopen / Deadline Extension / `+ Extra Attempts` ដល់និស្សិតម្នាក់, មើល Audit Log, និងដកសិទ្ធិ `Revoke`។

3. **ជំហានទី ៣ — បទពិសោធន៍និស្សិត (Student Portal):**
   - Logout → Login ជា **Student**។
   - បង្ហាញ `StudentDashboardFragment` (GPA, ភាគរយវត្តមាន, Risk badge, Quick Actions)។
   - ចូល `SubmitAssignmentActivity`: បង្ហាញស្ថានភាព Reopen/Resubmit ដាក់ស្នើ Version ថ្មី និងចូល `StudentSubmissionDetailActivity` / `SubmissionFileViewerActivity` បង្ហាញទាំង PDF Version ចាស់ និងថ្មី + Pinch-to-Zoom។
   - ចូល `TakeQuizActivity`: បង្ហាញការធ្វើ Attempt ថ្មី និង Review Attempt ចាស់ទាំងអស់ដោយមិនបាត់ពិន្ទុ។
   - ចូល `PredictionFragment`, `StudentRecommendationFragment` → `RecommendationDetailActivity`, និង `LearningReportActivity` (Export PDF មានភាសាខ្មែរ/អង់គ្លេស)។

4. **ជំហានទី ៤ — ការប្តូរភាសា (Bilingual Khmer / English Demo):**
   - ចូល `AdminAppearanceActivity` ប្តូរពី English ទៅ **ភាសាខ្មែរ (Khmer)** ដើម្បីបង្ហាញ UI និង Charts គាំទ្រភាសាខ្មែរពេញលេញ។
