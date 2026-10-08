<?php

namespace Lhduc\LaravelGcpLogging\Logging;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

class GoogleJsonFormatter extends NormalizerFormatter
{
    private const MAX_TRACE_FRAMES = 3;

    private const MAX_PREVIOUS_TRACE_FRAMES = 1;

    private const MAX_PREVIOUS_DEPTH = 2;

    private const MAX_MESSAGE_CHARS = 1000;

    public function format(LogRecord $record): array
    {
        $context = $record->context;
        $user = $this->resolvedUser();
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
            'message' => $record->message,
            'context' => $this->expandThrowables($context),
        ];
    }

    /**
     * Only read a user that is already resolved. Never trigger a session/token/DB lookup
     * from inside the logger (it would add queries and throw when the DB is down).
     */
    private function resolvedUser(): ?object
    {
        try {
            $guard = auth();

            return $guard->hasUser() ? $guard->user() : null;
        } catch (\Throwable $e) {
            return null;
        }
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
     * Compact exception: location + top frames only (previous exceptions get a shorter trace), to keep entries far below GCP's size limit.
     */
    private function throwableToArray(\Throwable $e, int $depth = 0): array
    {
        $data = [
            'class' => get_class($e),
            'message' => mb_strimwidth($e->getMessage(), 0, self::MAX_MESSAGE_CHARS, '...'),
            'code' => $e->getCode(),
            'file' => $this->relativePath($e->getFile()) . ':' . $e->getLine(),
        ];

        $data['trace'] = $this->topFrames(
            $e,
            $depth === 0 ? self::MAX_TRACE_FRAMES : self::MAX_PREVIOUS_TRACE_FRAMES
        );

        if ($e->getPrevious() && $depth < self::MAX_PREVIOUS_DEPTH) {
            $data['previous'] = $this->throwableToArray($e->getPrevious(), $depth + 1);
        }

        return $data;
    }

    /**
     * @return string[] e.g. "App\\Foo->bar (app/Foo.php:12)"
     */
    private function topFrames(\Throwable $e, int $limit): array
    {
        $frames = [];

        foreach (array_slice($e->getTrace(), 0, $limit) as $frame) {
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
