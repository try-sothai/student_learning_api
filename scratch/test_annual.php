<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$controller = new App\Http\Controllers\DashboardController();
$req = Illuminate\Http\Request::create('/api/admin/dashboard/annual-analytics', 'GET');
$res = $controller->annualAnalytics($req);

echo $res->getContent() . "\n";
