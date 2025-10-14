<?php

namespace Lhduc\LaravelGcpLogging\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Lhduc\LaravelGcpLogging\Http\Middleware\RequestLoggingMiddleware;
use Lhduc\LaravelGcpLogging\Logging\GoogleLogger;
use Lhduc\LaravelGcpLogging\Services\HttpClientLogger;

class GoogleLoggingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/google-logging.php', 'google-logging');

        $this->app->singleton(GoogleLogger::class, function (Container $app) {
            return new GoogleLogger();
        });
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
    }

    private function extendLoggingChannel(): void
    {
        $this->app['log']->extend('google', function ($app, array $config) {
            $provider = $app->make(GoogleLogger::class);

            return $provider(array_merge(config('google-logging'), $config));
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

        Http::globalOptions([
            'on_stats' => fn ($stats) => $logger->logRequest($stats),
        ]);

        Http::beforeSending(fn ($request) => $logger->addCorrelationIdHeader($request));
    }

    private function registerQueueCorrelationId(): void
    {
        Queue::createPayloadUsing(function () {
            return [
                'correlation_id' => app()->bound('correlation_id') ? app('correlation_id') : null,
            ];
        });

        Queue::before(function (JobProcessing $event) {
            $cid = $event->job->payload()['correlation_id'] ?? null;
            if ($cid) {
                app()->instance('correlation_id', $cid);
            }
        });
    }

    private function registerQueueEventLogging(): void
    {
        Event::listen(JobProcessed::class, function (JobProcessed $event) {
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
        });

        Event::listen(JobFailed::class, function (JobFailed $event) {
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
                'trace' => $event->exception->getTraceAsString(),
            ]);
        });
    }
}
