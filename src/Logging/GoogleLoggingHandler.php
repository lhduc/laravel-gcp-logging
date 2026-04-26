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
    /**
     * GCP Cloud Logging hard limit is 256 KB per log entry.
     * We truncate any entry exceeding 200 KB to stay safely under that limit.
     */
    protected const MAX_LOG_BYTES = 204_800; // 200 KB

    protected GcpLogger $gcpLogger;

    protected ?FormatterInterface $formatter;

    /** @var \Google\Cloud\Logging\Entry[] */
    protected array $buffer = [];

    protected bool $shutdownRegistered = false;

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
        $data = $this->truncateIfNeeded($data);

        $this->buffer[] = $this->gcpLogger->entry($data, [
            'timestamp' => $record['datetime'],
            'severity' => $record['level_name'],
            'resource' => ['type' => 'global'],
        ]);

        $this->registerShutdown();
    }

    /**
     * Flush all buffered entries to GCP in a single batch call.
     *
     * Wrapped in try/catch so GCP failures never break the application.
     */
    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $entries = $this->buffer;
        $this->buffer = [];

        try {
            $this->gcpLogger->writeBatch($entries);
        } catch (\Throwable $e) {
            // Swallow – GCP logging must never break the application.
            // Optionally log to stderr so ops can still spot issues:
            error_log('[laravel-gcp-logging] Failed to flush log batch: ' . $e->getMessage());
        }
    }

    /**
     * Called by Monolog when the handler is closed (e.g. on app termination).
     */
    public function close(): void
    {
        $this->flush();
        parent::close();
    }

    /**
     * Register a shutdown function to flush remaining entries.
     *
     * In PHP-FPM, shutdown functions run *after* the response has been sent,
     * so this effectively makes GCP writes non-blocking for the client.
     */
    protected function registerShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;

        register_shutdown_function([$this, 'flush']);
    }

    /**
     * Ensure the log payload stays within GCP's size limit.
     *
     * Strategy (applied in order until the entry is small enough):
     *   1. Truncate the `context` field as a JSON string.
     *   2. Replace `context` with a size-exceeded placeholder.
     *   3. Truncate the `message` field.
     *
     * A `_truncated` flag is added whenever any truncation occurs.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function truncateIfNeeded(array $data): array
    {
        if (strlen(json_encode($data)) <= self::MAX_LOG_BYTES) {
            return $data;
        }

        $data['_truncated'] = true;

        // Step 1 – shrink the context field if present.
        if (isset($data['context'])) {
            $contextJson = is_string($data['context'])
                ? $data['context']
                : (string) json_encode($data['context']);

            $overhead   = strlen(json_encode(array_merge($data, ['context' => ''])));
            $allowedLen = self::MAX_LOG_BYTES - $overhead - strlen(' [TRUNCATED]');

            if ($allowedLen > 0) {
                $data['context'] = substr($contextJson, 0, $allowedLen) . ' [TRUNCATED]';
            } else {
                $data['context'] = '[CONTEXT_EXCEEDS_SIZE_LIMIT]';
            }
        }

        if (strlen(json_encode($data)) <= self::MAX_LOG_BYTES) {
            return $data;
        }

        // Step 2 – context alone wasn't enough; drop it entirely.
        $data['context'] = '[CONTEXT_EXCEEDS_SIZE_LIMIT]';

        if (strlen(json_encode($data)) <= self::MAX_LOG_BYTES) {
            return $data;
        }

        // Step 3 – truncate the message as a last resort.
        if (isset($data['message']) && is_string($data['message'])) {
            $overhead   = strlen(json_encode(array_merge($data, ['message' => ''])));
            $allowedLen = self::MAX_LOG_BYTES - $overhead - strlen(' [TRUNCATED]');
            $data['message'] = substr($data['message'], 0, max(0, $allowedLen)) . ' [TRUNCATED]';
        }

        return $data;
    }
}
