<?php

namespace Lhduc\LaravelGcpLogging\Tests;

use Google\Cloud\Logging\Logger;

/** Google logger double: records batches, rejects entries containing "POISON". */
class FakeGcpLogger extends Logger
{
    public array $batches = [];

    public function __construct()
    {
    }

    public function entry($data, array $options = [])
    {
        return ['data' => $data, 'options' => $options];
    }

    public function writeBatch(array $entries, array $options = [])
    {
        foreach ($entries as $entry) {
            if (str_contains((string) json_encode($entry['data']), 'POISON')) {
                throw new \RuntimeException('bad entry');
            }
        }

        $this->batches[] = $entries;

        return true;
    }

    /** @return array<int, array> every entry that reached GCP */
    public function sent(): array
    {
        return array_merge([], ...$this->batches);
    }
}
