<?php

namespace Lhduc\LaravelGcpLogging\Tests;

use Lhduc\LaravelGcpLogging\Logging\GoogleLogger;
use Lhduc\LaravelGcpLogging\Logging\GoogleLoggingHandler;
use Monolog\Level;

class GoogleLoggerTest extends TestCase
{
    private function handlerLevel(array $config): Level
    {
        $logger = (new GoogleLogger())(['project_id' => 'test-project'] + $config);

        $handler = $logger->getHandlers()[0];
        $this->assertInstanceOf(GoogleLoggingHandler::class, $handler);

        return $handler->getLevel();
    }

    public function test_channel_level_is_honored(): void
    {
        $this->assertSame(Level::Info, $this->handlerLevel(['level' => 'info']));
        $this->assertSame(Level::Error, $this->handlerLevel(['level' => 'error']));
    }

    public function test_missing_or_invalid_level_falls_back_to_debug(): void
    {
        $this->assertSame(Level::Debug, $this->handlerLevel([]));
        $this->assertSame(Level::Debug, $this->handlerLevel(['level' => 'nonsense']));
    }

    public function test_handler_ignores_records_below_the_level(): void
    {
        $logger = (new GoogleLogger())(['project_id' => 'test-project', 'level' => 'warning']);

        $this->assertFalse($logger->getHandlers()[0]->isHandling(new \Monolog\LogRecord(new \DateTimeImmutable(), 'x', Level::Info, 'm')));
        $this->assertTrue($logger->getHandlers()[0]->isHandling(new \Monolog\LogRecord(new \DateTimeImmutable(), 'x', Level::Error, 'm')));
    }
}
