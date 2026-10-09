<?php

return [
    'log_name' => env('GOOGLE_APPLICATION_NAME', 'application'),
    'project_id' => env('GOOGLE_APPLICATION_PROJECT'),
    'key_file_path' => env('GOOGLE_APPLICATION_CREDENTIALS'),
    'excluded_routes' => [],

    // Masked as [REDACTED] in every log entry (headers, bodies, context, job payloads, URLs).
    // A key matches by word: `password` also covers `user_password`, `newPassword`, `passwords`.
    // Set to [] to disable redaction.
    'redact_keys' => [
        'authorization',
        'cookie',
        'password',
        'passwd',
        'pwd',
        'token',
        'otp',
        'pin',
        'secret',
        'signature',
        'api_key',
        'apikey',
        'private_key',
        'credential',
        'card_number',
        'cvv',
        'cvc',
    ],
];
