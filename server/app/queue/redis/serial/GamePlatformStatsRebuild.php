<?php

namespace app\queue\redis\serial;

use app\service\game\report\PlatformStatsRebuildService;
use Webman\RedisQueue\Consumer;

class GamePlatformStatsRebuild implements Consumer
{
    public string $queue = 'game_platform_stats_rebuild';
    public string $connection = 'default';

    public function consume($data): void
    {
        (new PlatformStatsRebuildService())->rebuild((string) $data['id']);
    }
}
