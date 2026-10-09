<?php

namespace Lhduc\LaravelGcpLogging\Support;

/**
 * Shared helpers for the request/HTTP-client loggers.
 */
trait WritesHttpLogs
{
    /** Max bytes of a request/response body kept in a log entry. */
    private const MAX_BODY_BYTES = 50000;

    /**
     * Cut text to MAX_BODY_BYTES on a UTF-8 boundary and mark it as truncated.
     */
    private function limitText(string $text): string
    {
        if (strlen($text) <= self::MAX_BODY_BYTES) {
            return $text;
        }

        return mb_strcut($text, 0, self::MAX_BODY_BYTES, 'UTF-8') . ' [TRUNCATED]';
    }

    /**
     * Keep a decoded payload as-is when small; otherwise fall back to its truncated JSON text.
     */
    private function limitPayload(array $payload): array|string
    {
        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($json === false || strlen($json) <= self::MAX_BODY_BYTES) {
            return $payload;
        }

        return $this->limitText($json);
    }

    /**
     * Log on the `google` channel with a severity derived from the HTTP status.
     * Statuses outside 2xx/4xx/5xx are not logged.
     */
    private function logByStatus(int $status, string $message, array $data): void
    {
        $logger = logger()->channel('google');

        if ($status >= 200 && $status < 300) {
            $logger->info($message, $data);
        } elseif ($status >= 400 && $status < 500) {
            $logger->warning($message, $data);
        } elseif ($status >= 500) {
            $logger->error($message, $data);
        }
    }

    /**
     * Decode a JSON string into an array, or return the original value if it isn't JSON.
     *
     * @return array|string|null
     */
    private function parseJsonBody(?string $body): array|string|null
    {
        if ($body === null || $body === '') {
            return $body;
        }

        $decoded = json_decode($body, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $body;
    }
}
