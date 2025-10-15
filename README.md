## Laravel GCP Logging

Reusable package that wires a Google Cloud Logging channel into Laravel, adds correlation IDs, and captures HTTP client, queue, and request telemetry.

### Installation

```bash
composer require lhduc/laravel-gcp-logging
```

### Publish configuration

```bash
php artisan vendor:publish --provider="Lhduc\LaravelGcpLogging\Providers\GoogleLoggingServiceProvider" --tag=config
```

Configure `config/google-logging.php` or the matching environment variables (`GOOGLE_PROJECT_ID`, `GOOGLE_APPLICATION_CREDENTIALS`, `GOOGLE_APPLICATION_NAME`).

### Enable channel

Update `config/logging.php`:

```php
'channels' => [
    // ...
    'google' => [
        'driver' => 'custom',
        'via' => Lhduc\LaravelGcpLogging\Logging\GoogleLogger::class,
        'level' => env('LOG_LEVEL', 'debug'),
        'project_id' => env('GOOGLE_APPLICATION_PROJECT'),
        'key_file_path' => env('GOOGLE_APPLICATION_CREDENTIALS'),
        'log_name' => env('GOOGLE_APPLICATION_NAME', 'application'),
        'excluded_routes' => [
            'api/health-check',
        ],
    ],
],
```

Requests hitting the `api` middleware group automatically receive the correlation middleware. Queue jobs and outbound HTTP client calls will include the correlation identifier and emit structured entries in Google Cloud Logging.
