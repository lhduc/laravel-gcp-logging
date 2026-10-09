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
            Route::get('api/big', fn () => response(str_repeat('é', 40_000)));
            Route::post('api/upload', fn () => ['ok' => true]);
            Route::get('api/boom', fn () => throw new \RuntimeException('kaboom'));
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

    public function test_large_response_is_truncated_on_a_utf8_boundary(): void
    {
        $this->get('api/big')->assertOk();

        $response = $this->googleRecords()[0]->context['response'];
        $this->assertStringEndsWith(' [TRUNCATED]', $response);
        $this->assertTrue(mb_check_encoding($response, 'UTF-8'));
        $this->assertLessThanOrEqual(50_000 + strlen(' [TRUNCATED]'), strlen($response));
    }

    public function test_large_request_body_is_truncated_but_small_stays_structured(): void
    {
        $this->postJson('api/upload', ['note' => str_repeat('x', 60_000)])->assertOk();
        $this->postJson('api/upload', ['note' => 'short'])->assertOk();

        $records = $this->googleRecords();
        $this->assertIsString($records[0]->context['request_body']);
        $this->assertStringEndsWith(' [TRUNCATED]', $records[0]->context['request_body']);
        $this->assertSame(['note' => 'short'], $records[1]->context['request_body']);
    }

    public function test_unhandled_exception_is_recorded_on_the_request_log(): void
    {
        $this->getJson('api/boom')->assertStatus(500);

        $record = $this->googleRecords()[0];
        $this->assertSame('ERROR', $record->level->getName());
        $this->assertSame('kaboom', $record->context['error']);
        $this->assertInstanceOf(\RuntimeException::class, $record->context['exception']);
    }
}
