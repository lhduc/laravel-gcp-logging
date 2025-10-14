<?php

namespace Lhduc\LaravelGcpLogging\Services;

use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;

class HttpClientLogger
{
    private const MAX_BODY_SIZE = 50000; // keep payloads manageable

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
                'request_body' => $this->truncateBody((string) $request->getBody()),
                'response_body' => $this->truncateBody((string) $response->getBody()),
                'transfer_time' => $stats->getTransferTime(),
            ];

            $this->log($status, $message, $data);
        } catch (\Throwable $e) {
            // swallow exceptions; logging should not break requests
        }
    }

    public function addCorrelationIdHeader($request): void
    {
        $correlationId = app()->bound('correlation_id') ? app('correlation_id') : null;
        if ($correlationId) {
            $request->withHeaders(['X-Correlation-ID' => $correlationId]);
        }
    }

    private function formatHeaders(RequestInterface $request): string
    {
        $headers = array_map(function ($values) {
            return implode(', ', $values);
        }, $request->getHeaders());

        return json_encode($headers);
    }

    private function truncateBody(string $body): string
    {
        if (strlen($body) > self::MAX_BODY_SIZE) {
            return substr($body, 0, self::MAX_BODY_SIZE) . ' [TRUNCATED]';
        }

        return $body;
    }

    private function log(int $status, string $message, array $data): void
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
}
