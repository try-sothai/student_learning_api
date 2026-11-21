<?php
$file = 'D:/Development/Projects/student_learning_api/app/Http/Controllers/AdminAnalyticsController.php';
$content = file_get_contents($file);
$content = str_replace(
    "\$totalStudents = CourseEnrollment::whereHas('semester', function(\$q) use (\$ay) {\n                \$q->where('academic_year_id', \$ay->id);\n            })->distinct('student_id')->count('student_id');",
    "\$totalStudents = CourseEnrollment::whereHas('semester', function(\$q) use (\$ay) {\n                \$q->where('academic_year_id', \$ay->id);\n            })->distinct('student_id')->count('student_id');\n            if(\$totalStudents == 0 && \$ay->status == 'active') { \$totalStudents = \App\Models\Student::count(); }",
    $content
);
file_put_contents($file, $content);
