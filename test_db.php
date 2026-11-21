<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$count = App\Models\LearningAnalytic::count();
$periods = App\Models\LearningAnalytic::distinct()->pluck('period_type')->toJson();
echo "Count: $count, Periods: $periods\n";
