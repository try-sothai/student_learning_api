<?php
$file = 'D:/Development/Projects/student_learning_api/app/Http/Controllers/AdminAnalyticsController.php';
$content = file_get_contents($file);
$content = str_replace("avg('marks_obtained')", "avg('score')", $content);
file_put_contents($file, $content);
