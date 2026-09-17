<?php

namespace app\queue\redis\tron;

use app\service\mgs\TronScanService;
use Webman\RedisQueue\Consumer;

class TronBackfill implements Consumer
{
    public string $queue = 'mgs_tron_backfill';
    public string $connection = 'default';

    public function consume($data): void
    {
        (new TronScanService())->batch((string) $data['gap_id']);
    }
}
