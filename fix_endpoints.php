<?php
$file = 'D:/Development/Projects/student_learning_api/app/Http/Controllers/AdminAnalyticsController.php';
$content = file_get_contents($file);
$content = str_replace("attendance.course.semesters", "attendance.semester", $content);
$content = str_replace("assignment.course.semesters", "assignment.semester", $content);
$content = str_replace("quiz.course.semesters", "quiz.semester", $content);
$content = str_replace("exam.course.semesters", "exam.semester", $content);
file_put_contents($file, $content);
