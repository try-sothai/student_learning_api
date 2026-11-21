<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$years = App\Models\AcademicYear::orderBy('start_date', 'asc')->get();

foreach ($years as $ay) {
    $startDate = \Carbon\Carbon::parse($ay->start_date)->startOfDay();
    $endDate = \Carbon\Carbon::parse($ay->end_date)->endOfDay();

    $regTotal = App\Models\User::whereBetween('created_at', [$startDate, $endDate])->count();
    $regStu = App\Models\User::where('role', 'student')->whereBetween('created_at', [$startDate, $endDate])->count();
    $regTch = App\Models\User::where('role', 'teacher')->whereBetween('created_at', [$startDate, $endDate])->count();
    $regAdm = App\Models\User::where('role', 'admin')->whereBetween('created_at', [$startDate, $endDate])->count();

    $enrolled = App\Models\CourseEnrollment::whereHas('semester', function ($q) use ($ay) {
        $q->where('academic_year_id', $ay->id);
    })->distinct('student_id')->count('student_id');

    $allUserCount = App\Models\User::where('created_at', '<=', $endDate)->count();
    $allStudentCount = App\Models\Student::where('created_at', '<=', $endDate)->count();

    echo "AY ID: {$ay->id} | Name: {$ay->name} | Start: {$ay->start_date->toDateString()} | End: {$ay->end_date->toDateString()} | Status: {$ay->status}\n";
    echo "  -> DB reg in range: total={$regTotal}, stu={$regStu}, tch={$regTch}, adm={$regAdm}\n";
    echo "  -> DB cumulative created by end_date: users={$allUserCount}, students={$allStudentCount}\n";
    echo "  -> DB enrolled students for AY: {$enrolled}\n\n";
}
