<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$controller = new App\Http\Controllers\DashboardController();
$request = new Illuminate\Http\Request();

echo "--- OVERVIEW ---\n";
echo $controller->adminOverview($request)->getContent() . "\n\n";

echo "--- USER GROWTH ---\n";
echo $controller->adminUserGrowth($request)->getContent() . "\n\n";

echo "--- SYSTEM STATUS ---\n";
echo $controller->adminSystemStatus($request)->getContent() . "\n\n";

echo "--- STATISTICS ---\n";
echo $controller->adminStatistics($request)->getContent() . "\n\n";

echo "--- COMBINED ADMIN DASHBOARD ---\n";
echo $controller->admin($request)->getContent() . "\n\n";
