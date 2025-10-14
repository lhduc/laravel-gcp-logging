<?php

return [
    'log_name' => env('GOOGLE_LOG_NAME', 'application'),
    'project_id' => env('GOOGLE_PROJECT_ID'),
    'key_file_path' => env('GOOGLE_APPLICATION_CREDENTIALS'),
    'excluded_routes' => [
        'sanctum/csrf-cookie',
    ],
];
