<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$controller = new App\Http\Controllers\AdminUserController();

echo "--- USER STATISTICS ---\n";
$req1 = Illuminate\Http\Request::create('/api/admin/users/statistics', 'GET');
echo $controller->statistics($req1)->getContent() . "\n\n";

echo "--- USER LIST (STUDENTS) ---\n";
$req2 = Illuminate\Http\Request::create('/api/admin/users?role=student&status=all', 'GET');
echo $controller->index($req2)->getContent() . "\n\n";

echo "--- USER LIST (TEACHERS) ---\n";
$req3 = Illuminate\Http\Request::create('/api/admin/users?role=teacher&status=all', 'GET');
echo $controller->index($req3)->getContent() . "\n\n";

echo "--- USER LIST (ADMINS) ---\n";
$req4 = Illuminate\Http\Request::create('/api/admin/users?role=admin&status=all', 'GET');
echo $controller->index($req4)->getContent() . "\n\n";
