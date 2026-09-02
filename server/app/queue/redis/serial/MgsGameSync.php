<?php

namespace app\queue\redis\serial;

use app\service\mgs\MgsSyncService;
use Webman\RedisQueue\Consumer;

class MgsGameSync implements Consumer
{
    public string $queue = 'mgs_game_sync';
    public string $connection = 'default';

    public function consume($data): void
    {
        (new MgsSyncService())->sync();
    }
}
