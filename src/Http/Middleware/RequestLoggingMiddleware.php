<?php

namespace Lhduc\LaravelGcpLogging\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RequestLoggingMiddleware
{
    /**
     * @throws \Throwable
     */
    public function handle(Request $request, Closure $next)
    {
        $start = microtime(true);

        $shouldLog = (bool) config('google-logging.project_id');
        if (!$shouldLog) {
            return $next($request);
        }

        $middlewares = $request->route()?->gatherMiddleware() ?? [];
        if (!in_array('api', $middlewares, true)) {
            return $next($request);
        }

        $routeUri = $request->route()?->uri();
        $excludedRoutes = config('google-logging.excluded_routes', []);
        if (is_string($excludedRoutes)) {
            $excludedRoutes = array_filter(array_map('trim', explode(',', $excludedRoutes)));
        }

        if ($routeUri && in_array($routeUri, $excludedRoutes, true)) {
            return $next($request);
        }

        $correlationId = $request->header('X-Correlation-ID', Str::uuid()->toString());
        $request->attributes->set('correlation_id', $correlationId);

        app()->instance('correlation_id', $correlationId);

        $responseData = null;
        $exceptionMessage = null;
        $response = null;

        try {
            $response = $next($request);
            $status = $response->getStatusCode();
            $responseData = $response->getContent();
            $duration = (microtime(true) - $start) * 1000;

            return $response;
        } catch (\Throwable $e) {
            $status = 500;
            $exceptionMessage = $e->getMessage();
            $duration = (microtime(true) - $start) * 1000;
            throw $e;
        } finally {
            $message = "[$correlationId] $status {$request->method()} {$request->uri()}";
            $data = [
                'correlation_id' => $correlationId,
                'tag' => 'Request',
                'url' => $request->fullUrl(),
                'status' => $status,
                'request_headers' => json_encode($request->headers->all()),
                'request_body' => json_encode($request->all()),
                'response' => $responseData,
                'error' => $exceptionMessage,
                'duration' => $duration,
            ];

            $logger = logger()->channel('google');
            if ($status >= 200 && $status < 300) {
                $logger->info($message, $data);
            } elseif ($status >= 400 && $status < 500) {
                $logger->warning($message, $data);
            } elseif ($status >= 500) {
                $logger->error($message, $data);
            }

            $response->headers->set('X-Correlation-ID', $correlationId);
        }
    }
}
