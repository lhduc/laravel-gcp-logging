<?php

namespace Lhduc\LaravelGcpLogging\Providers;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Lhduc\LaravelGcpLogging\Http\Middleware\RequestLoggingMiddleware;
use Lhduc\LaravelGcpLogging\Logging\GoogleLogger;
use Lhduc\LaravelGcpLogging\Logging\GoogleLoggingHandler;
use Lhduc\LaravelGcpLogging\Services\HttpClientLogger;

class GoogleLoggingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/google-logging.php', 'google-logging');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/google-logging.php' => config_path('google-logging.php'),
        ], 'config');

        $this->extendLoggingChannel();
        $this->registerApiMiddleware();

        if (!$this->shouldLog()) {
            return;
        }

        $this->registerHttpClientLogging();
        $this->registerQueueCorrelationId();
        $this->registerQueueEventLogging();
        $this->registerLongRunningFlush();
    }

    private function extendLoggingChannel(): void
    {
        $this->app['log']->extend('google', function ($app, array $config) {
            return (new GoogleLogger())($config);
        });
    }

    private function registerApiMiddleware(): void
    {
        $this->app->booted(function () {
            $router = $this->app['router'];
            $apiGroup = $router->getMiddlewareGroups()['api'] ?? [];
            if (!in_array(RequestLoggingMiddleware::class, $apiGroup, true)) {
                $router->pushMiddlewareToGroup('api', RequestLoggingMiddleware::class);
            }
        });
    }

    private function shouldLog(): bool
    {
        return (bool) config('google-logging.project_id');
    }

    private function registerHttpClientLogging(): void
    {
        $logger = new HttpClientLogger();

        // Middleware instead of Http::globalOptions(): globalOptions replaces the whole array,
        // so it would drop the app's own global options (or be dropped by them).
        Http::globalMiddleware(fn (callable $handler) => function ($request, array $options) use ($handler, $logger) {
            $original = $options['on_stats'] ?? null;

            $options['on_stats'] = function ($stats) use ($original, $logger) {
                if (is_callable($original)) {
                    $original($stats);
                }

                $logger->logRequest($stats);
            };

            return $handler($request, $options);
        });

        Http::globalRequestMiddleware(function ($request) {
            $correlationId = app()->bound('correlation_id') ? app('correlation_id') : null;

            return $correlationId ? $request->withHeader('X-Correlation-ID', $correlationId) : $request;
        });
    }

    private function registerQueueCorrelationId(): void
    {
        Queue::createPayloadUsing(function () {
            return [
                'correlation_id' => app()->bound('correlation_id') ? app('correlation_id') : null,
            ];
        });

        Queue::before(function (JobProcessing $event) {
            // Always reset so a job without a correlation id never inherits the previous job's id.
            $cid = $event->job->payload()['correlation_id'] ?? null;
            if ($cid) {
                app()->instance('correlation_id', $cid);
            } else {
                app()->forgetInstance('correlation_id');
            }
        });
    }

    private function registerQueueEventLogging(): void
    {
        Event::listen(JobProcessed::class, function (JobProcessed $event) {
            try {
                $payload = $event->job->payload();
                $cid = $payload['correlation_id'] ?? ($payload['uuid'] ?? '');
                $message = "[$cid] SUCCESS {$event->job->resolveName()}";

                logger()->channel('google')->info($message, [
                    'correlation_id' => $cid,
                    'tag' => 'Job',
                    'status' => 'success',
                    'job' => $event->job->resolveName(),
                    'queue' => $event->job->getQueue(),
                    'connection' => $event->connectionName,
                    'payload' => $event->job->getRawBody(),
                ]);
            } catch (\Throwable $e) {
                // Logging must never break job processing.
            }

            $this->flushLogs();
        });

        Event::listen(JobFailed::class, function (JobFailed $event) {
            try {
                $payload = $event->job->payload();
                $cid = $payload['correlation_id'] ?? ($payload['uuid'] ?? '');
                $message = "[$cid] FAILED {$event->job->resolveName()}";

                logger()->channel('google')->error($message, [
                    'correlation_id' => $cid,
                    'tag' => 'Job',
                    'status' => 'failed',
                    'job' => $event->job->resolveName(),
                    'queue' => $event->job->getQueue(),
                    'connection' => $event->connectionName,
                    'payload' => $event->job->getRawBody(),
                    'error' => $event->exception->getMessage(),
                    'exception' => $event->exception,
                ]);
            } catch (\Throwable $e) {
                // Logging must never break job processing.
            }

            $this->flushLogs();
        });
    }

    /**
     * Long-running processes (queue workers, Octane) never reach PHP shutdown between
     * units of work, so push buffered entries to GCP at the end of each job/request.
     */
    private function registerLongRunningFlush(): void
    {
        Event::listen(JobExceptionOccurred::class, fn () => $this->flushLogs());

        // String class name: Octane is optional, listening on a missing class is harmless.
        Event::listen('Laravel\\Octane\\Events\\RequestTerminated', fn () => $this->flushLogs());
    }

    private function flushLogs(): void
    {
        try {
            foreach (logger()->channel('google')->getLogger()->getHandlers() as $handler) {
                if ($handler instanceof GoogleLoggingHandler) {
                    $handler->flush();
                }
            }
        } catch (\Throwable $e) {
            // Flushing must never break the application.
        }
    }
}
