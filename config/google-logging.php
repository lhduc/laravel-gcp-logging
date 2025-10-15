<?php

return [
    'log_name' => env('GOOGLE_APPLICATION_NAME', 'application'),
    'project_id' => env('GOOGLE_APPLICATION_PROJECT'),
    'key_file_path' => env('GOOGLE_APPLICATION_CREDENTIALS'),
    'excluded_routes' => [],
];
