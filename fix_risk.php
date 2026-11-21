<?php
$file = 'D:/Development/Projects/student_learning_api/app/Http/Controllers/AdminAnalyticsController.php';
$content = file_get_contents($file);

$content = str_replace(
    "\$low = RiskAssessment::where('risk_level', 'low')->count();",
    "\$uniqueStudentIds = RiskAssessment::select('student_id')->distinct()->get()->pluck('student_id');\n        \$low = RiskAssessment::whereIn('student_id', \$uniqueStudentIds)->where('risk_level', 'low')->groupBy('student_id')->get()->count();",
    $content
);
$content = str_replace(
    "\$medium = RiskAssessment::where('risk_level', 'medium')->count();",
    "\$medium = RiskAssessment::whereIn('student_id', \$uniqueStudentIds)->where('risk_level', 'medium')->groupBy('student_id')->get()->count();",
    $content
);
$content = str_replace(
    "\$high = RiskAssessment::where('risk_level', 'high')->count();",
    "\$high = RiskAssessment::whereIn('student_id', \$uniqueStudentIds)->where('risk_level', 'high')->groupBy('student_id')->get()->count();",
    $content
);
file_put_contents($file, $content);
