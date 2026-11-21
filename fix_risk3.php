<?php
$file = 'D:/Development/Projects/student_learning_api/app/Http/Controllers/AdminAnalyticsController.php';
$content = file_get_contents($file);

$content = str_replace(
    "\$uniqueStudentIds = RiskAssessment::select('student_id')->distinct()->get()->pluck('student_id');\n        \$low = RiskAssessment::whereIn('student_id', \$uniqueStudentIds)->where('risk_level', 'low')->distinct('student_id')->count('student_id');",
    "\$low = RiskAssessment::whereIn('id', function(\$query) { \$query->select(DB::raw('MAX(id)'))->from('risk_assessments')->groupBy('student_id'); })->where('risk_level', 'low')->count();",
    $content
);
$content = str_replace(
    "\$medium = RiskAssessment::whereIn('student_id', \$uniqueStudentIds)->where('risk_level', 'medium')->distinct('student_id')->count('student_id');",
    "\$medium = RiskAssessment::whereIn('id', function(\$query) { \$query->select(DB::raw('MAX(id)'))->from('risk_assessments')->groupBy('student_id'); })->where('risk_level', 'medium')->count();",
    $content
);
$content = str_replace(
    "\$high = RiskAssessment::whereIn('student_id', \$uniqueStudentIds)->where('risk_level', 'high')->distinct('student_id')->count('student_id');",
    "\$high = RiskAssessment::whereIn('id', function(\$query) { \$query->select(DB::raw('MAX(id)'))->from('risk_assessments')->groupBy('student_id'); })->where('risk_level', 'high')->count();",
    $content
);
file_put_contents($file, $content);
