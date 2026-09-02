<?php

namespace app\queue\redis\serial;

use app\enum\RedisKey;
use app\service\mgs\MgsStatsService;
use support\Redis as Cache;
use Webman\RedisQueue\Consumer;
use Webman\RedisQueue\Redis;

class MgsStatsRefresh implements Consumer
{
    public string $queue = 'mgs_stats_refresh';
    public string $connection = 'default';

    public static function dispatch(string $date, ?int $hour = null): void
    {
        $bucket = $hour === null ? $date : sprintf('%s:%02d', $date, $hour);
        $key = RedisKey::TempMgsStatsRefresh->format($bucket);
        if (!Cache::set($key, '1', 'EX', RedisKey::EXPIRE_5_SECONDS, 'NX')) return;
        Redis::send('mgs_stats_refresh', ['date' => $date, 'hour' => $hour, 'key' => $key]);
    }

    public function consume($data): void
    {
        try {
            $service = new MgsStatsService();
            $date = (string) $data['date'];
            $hour = $data['hour'] === null ? null : (int) $data['hour'];
            if ($hour === null) {
                $service->rebuildDate($date);
                $service->rebuildMonth(substr($date, 0, 7));
            } else {
                $service->rebuildHour($date, $hour);
            }
        } finally {
            Cache::del((string) $data['key']);
        }
    }
}
