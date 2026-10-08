<?php

namespace Lhduc\LaravelGcpLogging\Support;

/**
 * Shared helpers for the request/HTTP-client loggers.
 */
trait WritesHttpLogs
{
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
