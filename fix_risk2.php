<?php
$file = 'D:/Development/Projects/student_learning_api/app/Http/Controllers/AdminAnalyticsController.php';
$content = file_get_contents($file);

$content = str_replace(
    "whereIn('student_id', \$uniqueStudentIds)->where('risk_level', 'low')->groupBy('student_id')->get()->count();",
    "whereIn('student_id', \$uniqueStudentIds)->where('risk_level', 'low')->distinct('student_id')->count('student_id');",
    $content
);
$content = str_replace(
    "whereIn('student_id', \$uniqueStudentIds)->where('risk_level', 'medium')->groupBy('student_id')->get()->count();",
    "whereIn('student_id', \$uniqueStudentIds)->where('risk_level', 'medium')->distinct('student_id')->count('student_id');",
    $content
);
$content = str_replace(
    "whereIn('student_id', \$uniqueStudentIds)->where('risk_level', 'high')->groupBy('student_id')->get()->count();",
    "whereIn('student_id', \$uniqueStudentIds)->where('risk_level', 'high')->distinct('student_id')->count('student_id');",
    $content
);
file_put_contents($file, $content);
