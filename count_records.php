<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo 'Students: ' . \App\Models\Student::count() . "\n";
echo 'Enrollments: ' . \App\Models\CourseEnrollment::count() . "\n";
echo 'Risk Assessments: ' . \App\Models\RiskAssessment::count() . "\n";
echo 'Attendance: ' . \App\Models\Attendance::count() . "\n";
echo 'Assignments: ' . \App\Models\Assignment::count() . "\n";
echo 'Quizzes: ' . \App\Models\Quiz::count() . "\n";
echo 'Exams: ' . \App\Models\Exam::count() . "\n";
