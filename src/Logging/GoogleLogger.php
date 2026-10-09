<?php

namespace Lhduc\LaravelGcpLogging\Logging;

use Google\Cloud\Logging\LoggingClient;
use Lhduc\LaravelGcpLogging\Support\Redactor;
use Monolog\Handler\NullHandler;
use Monolog\Level;
use Monolog\Logger;

class GoogleLogger
{
    public function __invoke(array $config): Logger
    {
        $config = array_merge(config('google-logging', []), $config);

        $logName = $config['log_name'] ?? 'application';
        $logger = new Logger($logName);

        if (empty($config['project_id'])) {
            $logger->pushHandler(new NullHandler());

            return $logger;
        }

        $options = ['projectId' => $config['project_id']];

        if (!empty($config['key_file_path'])) {
            $options['keyFilePath'] = $config['key_file_path'];
        }

        $logging = new LoggingClient($options);
        $gcpLogger = $logging->logger($logName);
        $handler = new GoogleLoggingHandler(
            $gcpLogger,
            level: $this->resolveLevel($config['level'] ?? null),
            redactor: new Redactor((array) ($config['redact_keys'] ?? []))
        );
        $handler->setFormatter(new GoogleJsonFormatter());

        $logger->pushHandler($handler);

        return $logger;
    }

    /**
     * Honor the channel's `level` (e.g. LOG_LEVEL=info); fall back to debug when missing/invalid.
     */
    private function resolveLevel(mixed $level): Level
    {
        try {
            return $level === null || $level === '' ? Level::Debug : Logger::toMonologLevel($level);
        } catch (\Throwable $e) {
            return Level::Debug;
        }
    }
}
