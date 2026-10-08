<?php

namespace Lhduc\LaravelGcpLogging\Logging;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

class GoogleJsonFormatter extends NormalizerFormatter
{
    private const MAX_TRACE_FRAMES = 3;

    private const MAX_MESSAGE_CHARS = 1000;

    public function format(LogRecord $record): array
    {
        $context = $record['context'] ?? [];
        $user = auth()->user() ?? null;
        $userId = $user?->id;

        if (empty($userId)) {
            $userId = $context['userId'] ?? ($context['user_id'] ?? null);
        }

        $cid = app()->bound('correlation_id') ? app('correlation_id') : null;
        if (empty($cid)) {
            $cid = $context['correlation_id'] ?? null;
        }

        return [
            'correlation_id' => $cid,
            'tag' => $context['tag'] ?? 'Other',
            'user_id' => $userId,
            'user_email' => $user?->email,
            'message' => $record['message'],
            'context' => $this->expandThrowables($context),
        ];
    }

    /**
     * Throwables json_encode to `{}` (protected props), so expand them into arrays.
     * Other values are left untouched to keep the existing payload shape.
     */
    private function expandThrowables(mixed $value, int $depth = 0): mixed
    {
        if ($value instanceof \Throwable) {
            return $this->throwableToArray($value);
        }

        if (is_array($value) && $depth < 10) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->expandThrowables($item, $depth + 1);
            }
        }

        return $value;
    }

    /**
     * Compact exception: location + top frames only (previous exceptions are dropped), to keep entries far below GCP's size limit.
     */
    private function throwableToArray(\Throwable $e): array
    {
        $data = [
            'class' => get_class($e),
            'message' => mb_strimwidth($e->getMessage(), 0, self::MAX_MESSAGE_CHARS, '...'),
            'code' => $e->getCode(),
            'file' => $this->relativePath($e->getFile()) . ':' . $e->getLine(),
        ];

        $data['trace'] = $this->topFrames($e);

        return $data;
    }

    /**
     * @return string[] e.g. "App\\Foo->bar (app/Foo.php:12)"
     */
    private function topFrames(\Throwable $e): array
    {
        $frames = [];

        foreach (array_slice($e->getTrace(), 0, self::MAX_TRACE_FRAMES) as $frame) {
            $call = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
            $frames[] = isset($frame['file'])
                ? "$call ({$this->relativePath($frame['file'])}:" . ($frame['line'] ?? 0) . ')'
                : $call;
        }

        return $frames;
    }

    private function relativePath(string $path): string
    {
        $base = base_path() . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
