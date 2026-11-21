<?php
$file = 'D:/Development/Projects/student_learning_api/app/Http/Controllers/AdminAnalyticsController.php';
$content = file_get_contents($file);
$content = preg_replace("/\\\$activeLearners = .*?\\\$atRiskStudents/s", "\$activeLearners = \App\Models\AttendanceDetail::whereHas('attendance.semester', function(\$q) use (\$ay) {\n                \$q->where('academic_year_id', \$ay->id);\n            })->where('status', 'present')->distinct('student_id')->count('student_id');\n\n            \$atRiskStudents", $content);
file_put_contents($file, $content);
