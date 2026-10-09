<?php

namespace Lhduc\LaravelGcpLogging\Logging;

use Google\Cloud\Core\Exception\BadRequestException;
use Google\Cloud\Core\Exception\ServiceException;
use Google\Cloud\Logging\Logger as GcpLogger;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Lhduc\LaravelGcpLogging\Support\Redactor;
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

    protected ?Redactor $redactor;

    public function __construct(GcpLogger $gcpLogger, $level = Level::Debug, bool $bubble = true, ?Redactor $redactor = null)
    {
        parent::__construct($level, $bubble);
        $this->gcpLogger = $gcpLogger;
        $this->redactor = $redactor;
    }

    protected function getDefaultFormatter(): FormatterInterface
    {
        return new NormalizerFormatter();
    }

    protected function write(LogRecord $record): void
    {
        $formatted = $this->getFormatter()->format($record);
        $data = is_string($formatted) ? ['message' => $formatted] : $formatted;
        $data = $this->sanitize($data);
        $data = $this->redactor?->redact($data) ?? $data;
        $data = $this->truncateIfNeeded($data);

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

            if (! $this->isEntryRejection($e)) {
                // Auth/quota/network/server errors hit every entry the same way, and the batch may
                // even have been written: retrying 100 times would block the worker and duplicate logs.
                return;
            }

            // The request was rejected as a whole (nothing written): retry one by one so a single
            // bad entry doesn't drop the valid ones.
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
     * True when the failure can come from a single bad entry: a client-side error before
     * anything was sent, or a 400 from the API. Other service errors are not entry-specific.
     */
    private function isEntryRejection(\Throwable $e): bool
    {
        return $e instanceof BadRequestException || ! $e instanceof ServiceException;
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
     *   1. Shorten the largest string values in place (keeps the structure and the other fields).
     *   2. Replace `context` with its truncated JSON text.
     *   3. Replace `context` with a placeholder.
     *   4. Truncate the `message` field.
     *
     * A `_truncated` flag is added whenever any truncation occurs.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function truncateIfNeeded(array $data): array
    {
        if ($this->encodedSize($data) <= self::MAX_LOG_BYTES) {
            return $data;
        }

        $data['_truncated'] = true;

        // Step 1 – cut the biggest strings; each cut removes at least the excess bytes.
        for ($i = 0; $i < 10; $i++) {
            $over = $this->encodedSize($data) - self::MAX_LOG_BYTES;
            if ($over <= 0) {
                return $data;
            }

            if (! $this->shortenLargestString($data, $over)) {
                break;
            }
        }

        // Step 2 – no big string to cut (e.g. a huge array): keep a truncated JSON text of the context.
        if (isset($data['context'])) {
            $contextJson = is_string($data['context']) ? $data['context'] : (string) $this->encode($data['context']);

            $fitted = $this->fitString($data, 'context', $contextJson);
            if ($fitted !== null) {
                $data['context'] = $fitted;

                return $data;
            }
        }

        // Step 3 – context alone can't fit: drop it.
        $data['context'] = '[CONTEXT_EXCEEDS_SIZE_LIMIT]';
        if ($this->encodedSize($data) <= self::MAX_LOG_BYTES) {
            return $data;
        }

        // Step 4 – truncate the message as a last resort.
        if (isset($data['message']) && is_string($data['message'])) {
            $data['message'] = $this->fitString($data, 'message', $data['message']) ?? '[TRUNCATED]';
        }

        return $data;
    }

    /**
     * Size as GCP sees it: UTF-8 bytes, so unicode and slashes are not counted in their escaped form.
     */
    private function encodedSize(array $data): int
    {
        return strlen((string) $this->encode($data));
    }

    private function encode(mixed $value): string|false
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * Cut the largest string value so that at least $over bytes are removed.
     * Removing N raw bytes removes at least N encoded bytes, so no re-measuring loop is needed.
     */
    private function shortenLargestString(array &$data, int $over): bool
    {
        $marker = ' [TRUNCATED]';
        $largest = null;
        $this->findLargestString($data, [], $largest);

        if ($largest === null || $largest[1] <= strlen($marker)) {
            return false;
        }

        [$path, $length] = $largest;
        $target = max(0, $length - $over - strlen($marker) - 64);

        $ref = &$data;
        foreach ($path as $key) {
            $ref = &$ref[$key];
        }
        $ref = mb_strcut($ref, 0, $target, 'UTF-8') . $marker;

        return true;
    }

    /**
     * @param  array<int, string|int>  $path
     * @param  array{0: array<int, string|int>, 1: int}|null  $largest
     */
    private function findLargestString(array $node, array $path, ?array &$largest, int $depth = 0): void
    {
        foreach ($node as $key => $value) {
            if (is_string($value)) {
                if ($largest === null || strlen($value) > $largest[1]) {
                    $largest = [[...$path, $key], strlen($value)];
                }
            } elseif (is_array($value) && $depth < 12) {
                $this->findLargestString($value, [...$path, $key], $largest, $depth + 1);
            }
        }
    }

    /**
     * Truncate $value so that the whole entry fits, re-measuring because the text is re-escaped
     * when embedded (quotes, newlines). Returns null when it cannot fit.
     */
    private function fitString(array $data, string $key, string $value): ?string
    {
        $marker = ' [TRUNCATED]';
        $data[$key] = '';
        $allowed = self::MAX_LOG_BYTES - $this->encodedSize($data) - strlen($marker);

        for ($i = 0; $i < 8 && $allowed > 0; $i++) {
            $data[$key] = mb_strcut($value, 0, $allowed, 'UTF-8') . $marker;
            $over = $this->encodedSize($data) - self::MAX_LOG_BYTES;

            if ($over <= 0) {
                return $data[$key];
            }

            $allowed -= $over + 16;
        }

        return null;
    }
}
