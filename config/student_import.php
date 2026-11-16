<?php

return [
    // Maximum upload size in kilobytes.
    'max_file_kb' => (int) env('STUDENT_IMPORT_MAX_KB', 5120),

    // Maximum data rows accepted in a single file.
    'max_rows' => (int) env('STUDENT_IMPORT_MAX_ROWS', 5000),

    // Rows processed per database transaction / spreadsheet read chunk.
    'chunk_size' => (int) env('STUDENT_IMPORT_CHUNK', 200),

    // Storage disk (private, not web accessible) and directory for uploads.
    'disk' => env('STUDENT_IMPORT_DISK', 'local'),
    'directory' => 'student-imports',
];
