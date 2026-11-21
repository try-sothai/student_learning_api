<?php

return [
    // Standardized initial passwords for SLAS user provisioning.
    'default_teacher_password' => env('TEACHER_DEFAULT_PASSWORD', 'TCH@12345'),
    'default_student_password' => env('STUDENT_DEFAULT_PASSWORD', 'STD@12345'),
];
