<?php

namespace Lhduc\LaravelGcpLogging\Services;

use GuzzleHttp\TransferStats;
use Lhduc\LaravelGcpLogging\Support\WritesHttpLogs;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

class HttpClientLogger
{
    use WritesHttpLogs;

    public function logRequest(TransferStats $stats): void
    {
        try {
            $correlationId = app()->bound('correlation_id') ? app('correlation_id') : null;
            if (!$correlationId) {
                return;
            }

            $request = $stats->getRequest();
            $response = $stats->getResponse();

            if (!$response) {
                return;
            }

            $url = (string) $request->getUri();
            $method = $request->getMethod();
            $status = $response->getStatusCode();
            $message = "[$correlationId] $status $method $url";

            $data = [
                'correlation_id' => $correlationId,
                'tag' => 'HttpClient',
                'method' => $method,
                'url' => $url,
                'status' => $status,
                'request_headers' => $this->formatHeaders($request),
                'request_body' => $this->parseJsonBody($this->readBody($request->getBody())),
                'response_body' => $this->parseJsonBody($this->readBody($response->getBody())),
                'transfer_time' => $stats->getTransferTime(),
            ];

            $this->logByStatus($status, $message, $data);
        } catch (\Throwable $e) {
            // swallow exceptions; logging should not break requests
        }
    }

    private function formatHeaders(RequestInterface $request): array
    {
        return array_map(function ($values) {
            return implode(', ', $values);
        }, $request->getHeaders());
    }

    /**
     * Read at most MAX_BODY_BYTES bytes (without loading huge bodies into memory)
     * and leave the stream rewound for the caller.
     */
    private function readBody(StreamInterface $body): string
    {
        if ($body->isSeekable()) {
            $body->rewind();
            $contents = $body->read(self::MAX_BODY_BYTES + 1);
            $body->rewind();
        } else {
            $contents = (string) $body;
        }

        return $this->limitText($contents);
    }
}
