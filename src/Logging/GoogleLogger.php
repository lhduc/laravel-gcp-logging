<?php

namespace Lhduc\LaravelGcpLogging\Logging;

use Google\Cloud\Logging\LoggingClient;
use Monolog\Logger;

class GoogleLogger
{
    public function __invoke(array $config): ?Logger
    {
        $logName = $config['log_name'] ?? 'application';

        if (empty($config['project_id'])) {
            return null;
        }

        $logging = new LoggingClient([
            'projectId' => $config['project_id'],
            'keyFilePath' => $config['key_file_path'],
        ]);

        $gcpLogger = $logging->logger($logName);
        $handler = new GoogleLoggingHandler($gcpLogger);
        $handler->setFormatter(new GoogleJsonFormatter());

        $logger = new Logger($logName);
        $logger->pushHandler($handler);

        return $logger;
    }
}
