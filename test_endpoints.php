<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$controller = new \App\Http\Controllers\AdminAnalyticsController();
$request = \Illuminate\Http\Request::create('/api/admin/analytics/enrollment', 'GET');
echo "Enrollment:\n" . $controller->enrollment($request)->getContent() . "\n\n";

$request = \Illuminate\Http\Request::create('/api/admin/analytics/performance', 'GET');
echo "Performance:\n" . $controller->performance($request)->getContent() . "\n\n";

$request = \Illuminate\Http\Request::create('/api/admin/analytics/attendance', 'GET');
echo "Attendance:\n" . $controller->attendance($request)->getContent() . "\n\n";

$request = \Illuminate\Http\Request::create('/api/admin/analytics/student-risk', 'GET');
echo "Risk:\n" . $controller->studentRisk($request)->getContent() . "\n\n";
