<?php

namespace Lhduc\LaravelGcpLogging\Logging;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

class GoogleJsonFormatter extends NormalizerFormatter
{
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

    private function throwableToArray(\Throwable $e, int $depth = 0): array
    {
        $data = [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];

        if ($e->getPrevious() && $depth < 5) {
            $data['previous'] = $this->throwableToArray($e->getPrevious(), $depth + 1);
        }

        return $data;
    }
}
