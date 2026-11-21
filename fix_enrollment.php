<?php
$file = 'D:/Development/Projects/student_learning_api/app/Http/Controllers/AdminAnalyticsController.php';
$content = file_get_contents($file);
$content = preg_replace("/\\\$series\\[\\] = \\[.+?'academic_year'.+?'at_risk_students'.+?\\];/s",
"\$series[] = [
                'academic_year' => \$ay->name,
                'total_students' => \$totalStudents,
                'active_learners' => \$activeLearners > \$totalStudents ? \$totalStudents : \$activeLearners,
                'at_risk_students' => \$atRiskStudents > \$totalStudents ? \$totalStudents : \$atRiskStudents
            ];", $content);
file_put_contents($file, $content);
