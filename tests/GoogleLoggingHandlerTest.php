<?php

namespace Lhduc\LaravelGcpLogging\Tests;

use Lhduc\LaravelGcpLogging\Logging\GoogleJsonFormatter;
use Lhduc\LaravelGcpLogging\Logging\GoogleLoggingHandler;
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
        $this->handler = (new GoogleLoggingHandler($this->gcp))->setFormatter(new GoogleJsonFormatter());
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

    public function test_oversized_entry_is_truncated_below_limit(): void
    {
        $this->logger->info('big', ['blob' => str_repeat('é', 300_000)]);
        $this->handler->flush();

        $data = $this->gcp->sent()[0]['data'];
        $this->assertTrue($data['_truncated']);
        $this->assertLessThanOrEqual(204_800, strlen(json_encode($data)));
    }
}
