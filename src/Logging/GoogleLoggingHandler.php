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

    /**
     * Auto-flush when buffer reaches this size.
     * Prevents unbounded memory growth in long-running processes (queue workers, Octane).
     */
    protected const MAX_BUFFER_SIZE = 100;

    protected GcpLogger $gcpLogger;

    /** @var \Google\Cloud\Logging\Entry[] */
    protected array $buffer = [];

    protected bool $shutdownRegistered = false;

    public function __construct(GcpLogger $gcpLogger, $level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->gcpLogger = $gcpLogger;
    }

    protected function getDefaultFormatter(): FormatterInterface
    {
        return new NormalizerFormatter();
    }

    protected function write(LogRecord $record): void
    {
        $formatted = $this->getFormatter()->format($record);
        $data = is_string($formatted) ? ['message' => $formatted] : $formatted;
        $data = $this->truncateIfNeeded($this->sanitize($data));

        $this->buffer[] = $this->gcpLogger->entry($data, [
            'timestamp' => $record->datetime,
            'severity' => $record->level->getName(),
            'resource' => ['type' => 'global'],
        ]);

        if (count($this->buffer) >= self::MAX_BUFFER_SIZE) {
            $this->flush();
        }

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
            error_log('[laravel-gcp-logging] Failed to flush log batch: ' . $e->getMessage());

            // Retry one by one so a single bad entry doesn't drop the valid ones.
            foreach ($entries as $entry) {
                try {
                    $this->gcpLogger->writeBatch([$entry]);
                } catch (\Throwable $e) {
                    error_log('[laravel-gcp-logging] Dropped log entry: ' . $e->getMessage());
                }
            }
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
     * Make the payload always JSON-encodable: invalid UTF-8 is substituted,
     * NAN/INF and circular references are replaced instead of failing the encode.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function sanitize(array $data): array
    {
        $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $decoded = $json === false ? null : json_decode($json, true);

        return is_array($decoded) ? $decoded : ['message' => '[UNENCODABLE_LOG_ENTRY]'];
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
        $encoded = json_encode($data);
        if (strlen($encoded) <= self::MAX_LOG_BYTES) {
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
                $data['context'] = mb_strcut($contextJson, 0, $allowedLen, 'UTF-8') . ' [TRUNCATED]';
            } else {
                $data['context'] = '[CONTEXT_EXCEEDS_SIZE_LIMIT]';
            }

            if (strlen(json_encode($data)) <= self::MAX_LOG_BYTES) {
                return $data;
            }
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
            $data['message'] = mb_strcut($data['message'], 0, max(0, $allowedLen), 'UTF-8') . ' [TRUNCATED]';
        }

        return $data;
    }
}
