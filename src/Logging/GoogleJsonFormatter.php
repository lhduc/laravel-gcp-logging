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
            'context' => $context,
        ];
    }
}
