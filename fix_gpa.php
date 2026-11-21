<?php
$file = 'D:/Development/Projects/student_learning_api/app/Http/Controllers/AdminAnalyticsController.php';
$content = file_get_contents($file);
$content = str_replace(
    "\$avgGpa = GpaRecord::where('semester_id', \$sem->id)->avg('gpa') ?? 0;",
    "\$avgGpa = GpaRecord::where('semester_id', \$sem->id)->avg('gpa') ?? 0;\n            // Map 0-4 GPA scale to 0-100% scale for charting visual compatibility\n            \$avgGpaMapped = \$avgGpa > 0 ? (\$avgGpa / 4.0) * 100 : 0;",
    $content
);
$content = str_replace(
    "'avg_gpa' => round(\$avgGpa, 2),",
    "'avg_gpa' => round(\$avgGpaMapped, 2),\n                'actual_gpa' => round(\$avgGpa, 2),",
    $content
);
file_put_contents($file, $content);
