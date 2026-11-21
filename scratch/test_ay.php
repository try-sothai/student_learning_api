<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$years = App\Models\AcademicYear::all();
foreach ($years as $y) {
    echo "ID: {$y->id}, Name: {$y->name}, Start: " . ($y->start_date ? $y->start_date->toDateString() : 'N/A') . ", End: " . ($y->end_date ? $y->end_date->toDateString() : 'N/A') . ", Status: {$y->status}\n";
}
