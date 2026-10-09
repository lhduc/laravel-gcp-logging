<?php

namespace Lhduc\LaravelGcpLogging\Tests;

use Illuminate\Support\Facades\Route;

class RequestLoggingMiddlewareTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::middleware('api')->group(function () {
            Route::get('api/ping', fn () => ['ok' => true]);
            Route::get('api/health', fn () => 'ok');
            Route::get('api/health/deep', fn () => 'ok');
            Route::get('api/fail', fn () => response(['error' => 'x'], 422));
        });

        Route::get('web/page', fn () => 'ok');
    }

    public function test_api_request_is_logged_and_response_gets_correlation_header(): void
    {
        $response = $this->getJson('api/ping', ['X-Correlation-ID' => 'cid-1']);

        $response->assertOk()->assertHeader('X-Correlation-ID', 'cid-1');

        $records = $this->googleRecords();
        $this->assertCount(1, $records);
        $this->assertSame('Request', $records[0]->context['tag']);
        $this->assertSame('cid-1', $records[0]->context['correlation_id']);
        $this->assertSame(['ok' => true], $records[0]->context['response']);
    }

    public function test_correlation_id_is_generated_when_missing(): void
    {
        $this->getJson('api/ping')->assertHeader('X-Correlation-ID');
    }

    public function test_4xx_is_logged_as_warning(): void
    {
        $this->getJson('api/fail');

        $this->assertSame('WARNING', $this->googleRecords()[0]->level->getName());
    }

    public function test_excluded_routes_support_wildcards(): void
    {
        $this->getJson('api/health');
        $this->getJson('api/health/deep');

        $this->assertCount(0, $this->googleRecords());
    }

    public function test_non_api_routes_are_not_logged(): void
    {
        $this->get('web/page')->assertOk();

        $this->assertCount(0, $this->googleRecords());
    }

    public function test_logging_failure_does_not_break_the_response(): void
    {
        // Swap in a handler that throws on every write.
        app('log')->forgetChannel('google');
        config(['logging.channels.google.handler' => \Lhduc\LaravelGcpLogging\Tests\ThrowingHandler::class]);

        $this->getJson('api/ping')->assertOk();
    }
}
