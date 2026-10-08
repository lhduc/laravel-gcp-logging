<?php

namespace Lhduc\LaravelGcpLogging\Tests;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Http;
use Lhduc\LaravelGcpLogging\Services\HttpClientLogger;

class HttpClientLoggerTest extends TestCase
{
    private function stats(int $status, string $body, string $requestBody = '{"a":1}'): TransferStats
    {
        return new TransferStats(
            new Request('POST', 'http://example.test/x', ['Content-Type' => 'application/json'], $requestBody),
            new Response($status, [], $body),
            0.25
        );
    }

    public function test_nothing_is_logged_without_a_correlation_id(): void
    {
        (new HttpClientLogger())->logRequest($this->stats(200, '{}'));

        $this->assertCount(0, $this->googleRecords());
    }

    public function test_json_bodies_are_decoded_and_severity_follows_status(): void
    {
        app()->instance('correlation_id', 'cid-2');
        $logger = new HttpClientLogger();

        $logger->logRequest($this->stats(200, '{"ok":true}'));
        $logger->logRequest($this->stats(500, 'oops'));
        $logger->logRequest($this->stats(302, ''));

        $records = $this->googleRecords();
        $this->assertCount(2, $records);
        $this->assertSame('INFO', $records[0]->level->getName());
        $this->assertSame(['ok' => true], $records[0]->context['response_body']);
        $this->assertSame(['a' => 1], $records[0]->context['request_body']);
        $this->assertSame('ERROR', $records[1]->level->getName());
        $this->assertSame('oops', $records[1]->context['response_body']);
    }

    public function test_large_body_is_truncated_on_a_utf8_boundary_and_stream_is_rewound(): void
    {
        app()->instance('correlation_id', 'cid-3');
        $stats = $this->stats(200, str_repeat('é', 40_000));

        (new HttpClientLogger())->logRequest($stats);

        $body = $this->googleRecords()[0]->context['response_body'];
        $this->assertStringEndsWith(' [TRUNCATED]', $body);
        $this->assertTrue(mb_check_encoding($body, 'UTF-8'));
        $this->assertSame(0, $stats->getResponse()->getBody()->tell());
    }

    public function test_outbound_requests_carry_the_correlation_header(): void
    {
        app()->instance('correlation_id', 'cid-4');
        Http::fake();

        Http::get('http://example.test/x');

        Http::assertSent(fn ($request) => $request->header('X-Correlation-ID') === ['cid-4']);
    }

    public function test_outbound_requests_have_no_header_without_a_correlation_id(): void
    {
        Http::fake();

        Http::get('http://example.test/x');

        Http::assertSent(fn ($request) => ! $request->hasHeader('X-Correlation-ID'));
    }
}
