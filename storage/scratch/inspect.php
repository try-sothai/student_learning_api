<?php

$pdo = new PDO('sqlite:' . __DIR__ . '/rehearsal.sqlite');
foreach ([
    'SELECT id, student_code, department_id, class_id, year_level FROM students',
    'SELECT id, teacher_code, department_id FROM teachers',
    'SELECT id, admin_code FROM admins',
    'SELECT * FROM departments',
    'SELECT id, name, start_date, end_date, status FROM academic_years',
    'SELECT id, academic_year_id, name, semester_number, start_date, end_date FROM semesters',
    'SELECT id, department_id, academic_year_id, name, code, year_level FROM classes',
    'SELECT id, email, role, status FROM users',
] as $sql) {
    echo "\n> $sql\n";
    foreach ($pdo->query($sql, PDO::FETCH_ASSOC) as $row) {
        echo json_encode($row) . "\n";
    }
}
echo "\nstudents columns: ";
echo implode(',', array_column($pdo->query('PRAGMA table_info(students)')->fetchAll(PDO::FETCH_ASSOC), 'name')) . "\n";
