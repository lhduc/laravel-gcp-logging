<?php

namespace Lhduc\LaravelGcpLogging\Tests;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

/** Handler that always fails, to prove logging errors never reach the app. */
class ThrowingHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        throw new \RuntimeException('log backend down');
    }
}
