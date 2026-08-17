<?php

namespace app\queue\redis;

use app\service\game\SyncService;
use Webman\RedisQueue\Consumer;

class GameSync implements Consumer
{
    public string $queue = 'game_sync';
    public string $connection = 'default';

    public function consume($data): void
    {
        (new SyncService())->sync((string) $data['platform_code']);
    }
}
