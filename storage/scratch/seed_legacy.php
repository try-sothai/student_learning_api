<?php

// Scratch helper: inserts legacy-shaped rows into the rehearsal DB.
$pdo = new PDO('sqlite:' . __DIR__ . '/rehearsal.sqlite');
$now = date('Y-m-d H:i:s');

$pdo->exec("INSERT INTO users (id,name,email,password,role,created_at,updated_at) VALUES
 (1,'Legacy Student','s1@x.test','x','student','$now','$now'),
 (2,'Legacy Student Two','s2@x.test','x','student','$now','$now'),
 (3,'Legacy Teacher','t1@x.test','x','teacher','$now','$now'),
 (4,'Legacy Admin','a1@x.test','x','admin','$now','$now')");

$pdo->exec("INSERT INTO students (user_id,student_id,first_name,last_name,date_of_birth,gender,department,academic_year,year_level,semester,status,created_at,updated_at) VALUES
 (1,'STD001','Legacy','Student','2004-05-14','Male','Computer Science','2026-2027','Year 3','Semester 1','enrolled','$now','$now'),
 (2,'STD002','Legacy','Two','2004-05-14','Female','computer science','2026-2027','3','Semester 2','enrolled','$now','$now')");

$pdo->exec("INSERT INTO teachers (user_id,teacher_id,first_name,last_name,department,status,created_at,updated_at) VALUES
 (3,'TCH001','Legacy','Teacher','Computer Science','active','$now','$now')");

$pdo->exec("INSERT INTO admins (user_id,admin_id,first_name,last_name,status,created_at,updated_at) VALUES
 (4,'ADM001','Legacy','Admin','active','$now','$now')");

echo "legacy rows inserted\n";
