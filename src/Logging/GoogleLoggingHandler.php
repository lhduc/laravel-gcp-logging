<?php

namespace Lhduc\LaravelGcpLogging\Logging;

use Google\Cloud\Logging\Logger as GcpLogger;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class GoogleLoggingHandler extends AbstractProcessingHandler
{
    protected GcpLogger $gcpLogger;

    protected ?FormatterInterface $formatter;

    public function __construct(GcpLogger $gcpLogger, $level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->gcpLogger = $gcpLogger;
        $this->formatter = new NormalizerFormatter();
    }

    public function setFormatter(FormatterInterface $formatter): static
    {
        $this->formatter = $formatter;

        return $this;
    }

    public function getFormatter(): FormatterInterface
    {
        return $this->formatter;
    }

    protected function write(LogRecord $record): void
    {
        $formatted = $this->formatter->format($record);
        $data = is_string($formatted) ? ['message' => $formatted] : $formatted;

        $entry = $this->gcpLogger->entry($data, [
            'timestamp' => $record['datetime'],
            'severity' => $record['level_name'],
            'resource' => ['type' => 'global'],
        ]);

        $this->gcpLogger->write($entry);
    }
}
