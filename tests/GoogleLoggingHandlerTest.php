<?php

namespace Lhduc\LaravelGcpLogging\Tests;

use Lhduc\LaravelGcpLogging\Logging\GoogleJsonFormatter;
use Lhduc\LaravelGcpLogging\Logging\GoogleLoggingHandler;
use Lhduc\LaravelGcpLogging\Support\Redactor;
use Monolog\Logger;

class GoogleLoggingHandlerTest extends TestCase
{
    private FakeGcpLogger $gcp;

    private GoogleLoggingHandler $handler;

    private Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gcp = new FakeGcpLogger();
        $this->handler = (new GoogleLoggingHandler($this->gcp, redactor: new Redactor(config('google-logging.redact_keys'))))->setFormatter(new GoogleJsonFormatter());
        $this->logger = new Logger('test', [$this->handler]);
    }

    public function test_entries_are_buffered_until_flush(): void
    {
        $this->logger->info('a');
        $this->assertCount(0, $this->gcp->sent());

        $this->handler->flush();
        $this->assertCount(1, $this->gcp->sent());
        $this->assertSame('INFO', $this->gcp->sent()[0]['options']['severity']);
    }

    public function test_auto_flush_at_one_hundred_entries(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->logger->info("m$i");
        }

        $this->assertCount(1, $this->gcp->batches);
        $this->assertCount(100, $this->gcp->batches[0]);
    }

    public function test_invalid_utf8_and_nan_do_not_break_the_entry(): void
    {
        $this->logger->info("bad \xB1\x31\xFF", ['v' => NAN]);
        $this->handler->flush();

        $this->assertCount(1, $this->gcp->sent());
        $this->assertNotFalse(json_encode($this->gcp->sent()[0]['data']));
    }

    public function test_one_bad_entry_does_not_drop_the_rest_of_the_batch(): void
    {
        $this->logger->info('a');
        $this->logger->info('POISON');
        $this->logger->info('c');

        $previous = ini_set('error_log', '/dev/null');
        $this->handler->flush();
        ini_set('error_log', $previous === false ? '' : $previous);

        $this->assertCount(2, $this->gcp->sent());
    }

    private function utf8Size(array $data): int
    {
        return strlen(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function test_oversized_entry_is_truncated_below_limit_keeping_structure(): void
    {
        $this->logger->info('big', ['tag' => 'Request', 'status' => 200, 'response' => str_repeat('é', 300_000)]);
        $this->handler->flush();

        $data = $this->gcp->sent()[0]['data'];
        $this->assertTrue($data['_truncated']);
        $this->assertLessThanOrEqual(204_800, $this->utf8Size($data));
        $this->assertSame(200, $data['context']['status']);
        $this->assertStringEndsWith(' [TRUNCATED]', $data['context']['response']);
    }

    public function test_large_json_text_with_quotes_is_cut_not_dropped(): void
    {
        $json = json_encode(array_fill(0, 6000, ['id' => 1, 'name' => 'abc "q" def', 'note' => 'lorem ipsum dolor']));

        $this->logger->info('big', ['status' => 200, 'response' => $json]);
        $this->handler->flush();

        $data = $this->gcp->sent()[0]['data'];
        $this->assertLessThanOrEqual(204_800, $this->utf8Size($data));
        $this->assertNotSame('[CONTEXT_EXCEEDS_SIZE_LIMIT]', $data['context']);
        $this->assertStringStartsWith('[{"id":1', $data['context']['response']);
    }

    public function test_cjk_content_is_measured_in_utf8_bytes_and_not_dropped(): void
    {
        $this->logger->info('big', ['response' => str_repeat('漢字テスト', 30_000)]);
        $this->handler->flush();

        $data = $this->gcp->sent()[0]['data'];
        $this->assertLessThanOrEqual(204_800, $this->utf8Size($data));
        $this->assertStringStartsWith('漢字', $data['context']['response']);
        // ~200 KB of UTF-8 is kept (the old escaped-size check kept only ~1/3 of it).
        $this->assertGreaterThan(150_000, strlen($data['context']['response']));
    }

    public function test_huge_array_without_big_strings_falls_back_to_truncated_text(): void
    {
        $this->logger->info('big', ['ids' => range(1, 120_000)]);
        $this->handler->flush();

        $data = $this->gcp->sent()[0]['data'];
        $this->assertLessThanOrEqual(204_800, $this->utf8Size($data));
        $this->assertIsString($data['context']);
        $this->assertStringStartsWith('{"ids":[1,2,3', $data['context']);
    }

    public function test_service_errors_are_not_retried_entry_by_entry(): void
    {
        $gcp = new class extends FakeGcpLogger {
            public int $calls = 0;

            public function writeBatch(array $entries, array $options = [])
            {
                $this->calls++;
                throw new \Google\Cloud\Core\Exception\ServiceException('quota exceeded', 429);
            }
        };
        $handler = new GoogleLoggingHandler($gcp);
        $logger = new Logger('t', [$handler]);
        for ($i = 0; $i < 20; $i++) {
            $logger->info("m$i");
        }

        $previous = ini_set('error_log', '/dev/null');
        $handler->flush();
        ini_set('error_log', $previous === false ? '' : $previous);

        $this->assertSame(1, $gcp->calls);
    }

    public function test_bad_request_is_retried_entry_by_entry(): void
    {
        $gcp = new class extends FakeGcpLogger {
            public function writeBatch(array $entries, array $options = [])
            {
                if (count($entries) > 1) {
                    throw new \Google\Cloud\Core\Exception\BadRequestException('invalid argument', 400);
                }

                return parent::writeBatch($entries, $options);
            }
        };
        $handler = new GoogleLoggingHandler($gcp);
        $logger = new Logger('t', [$handler]);
        $logger->info('a');
        $logger->info('b');

        $previous = ini_set('error_log', '/dev/null');
        $handler->flush();
        ini_set('error_log', $previous === false ? '' : $previous);

        $this->assertCount(2, $gcp->sent());
    }

    public function test_sensitive_data_never_reaches_gcp(): void
    {
        $this->logger->info('[cid] 200 GET http://x.test/cb?token=urltoken', [
            'tag' => 'Request',
            'url' => 'http://x.test/cb?api_key=urlkey&page=2',
            'request_headers' => ['authorization' => ['Bearer headersecret'], 'cookie' => ['s=cookiesecret'], 'accept' => ['*/*']],
            'request_body' => ['email' => 'a@b.c', 'password' => 'pw-secret', 'otp' => '123456'],
            'response' => '{"access_token":"respsecret","ok":true}',
            'payload' => '{"data":{"command":"O:3:\\"Job\\":1:{s:8:\\"password\\";s:6:\\"jobsec\\";}"}}',
        ]);
        $this->handler->flush();

        $sent = json_encode($this->gcp->sent()[0]['data']);
        foreach (['urltoken', 'urlkey', 'headersecret', 'cookiesecret', 'pw-secret', '123456', 'respsecret', 'jobsec'] as $secret) {
            $this->assertStringNotContainsString($secret, $sent, $secret);
        }

        $data = $this->gcp->sent()[0]['data'];
        $this->assertSame('a@b.c', $data['context']['request_body']['email']);
        $this->assertSame(['*/*'], $data['context']['request_headers']['accept']);
        $this->assertStringContainsString('page=2', $data['context']['url']);
    }

    public function test_default_config_redacts_the_common_keys(): void
    {
        $keys = config('google-logging.redact_keys');

        foreach (['authorization', 'cookie', 'password', 'token', 'otp', 'pin', 'secret', 'signature'] as $key) {
            $this->assertContains($key, $keys);
        }
    }
}
