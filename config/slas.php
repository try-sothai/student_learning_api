<?php

return [
    // Standardized initial passwords for SLAS user provisioning.
    'default_teacher_password' => env('TEACHER_DEFAULT_PASSWORD', 'TCH@12345'),
    'default_student_password' => env('STUDENT_DEFAULT_PASSWORD', 'STD@12345'),

    // Canonical university Student Year Levels (separate from Academic Year e.g. 2026-2027).
    'max_year_level' => 4,
    'year_levels' => [
        1 => [
            'number' => 1,
            'code' => 'Y1',
            'name' => 'Year 1',
            'ordinal' => 'First Year',
            'khmer' => 'ឆ្នាំទី១',
            'label' => 'Y1 — Year 1',
            'bilingual' => 'Y1 — Year 1 / ឆ្នាំទី១',
        ],
        2 => [
            'number' => 2,
            'code' => 'Y2',
            'name' => 'Year 2',
            'ordinal' => 'Second Year',
            'khmer' => 'ឆ្នាំទី២',
            'label' => 'Y2 — Year 2',
            'bilingual' => 'Y2 — Year 2 / ឆ្នាំទី២',
        ],
        3 => [
            'number' => 3,
            'code' => 'Y3',
            'name' => 'Year 3',
            'ordinal' => 'Third Year',
            'khmer' => 'ឆ្នាំទី៣',
            'label' => 'Y3 — Year 3',
            'bilingual' => 'Y3 — Year 3 / ឆ្នាំទី៣',
        ],
        4 => [
            'number' => 4,
            'code' => 'Y4',
            'name' => 'Year 4',
            'ordinal' => 'Fourth Year',
            'khmer' => 'ឆ្នាំទី៤',
            'label' => 'Y4 — Year 4',
            'bilingual' => 'Y4 — Year 4 / ឆ្នាំទី៤',
        ],
    ],
];
