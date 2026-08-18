<?php

namespace app\service\mgs;

use app\queue\redis\MgsStatsRefresh;
use DateTimeImmutable;
use DateTimeZone;
use Webman\RedisQueue\Redis;

class MgsTask
{
    public function run(?string $parameter): string
    {
        $data = json_decode($parameter ?: '{}', true);
        $zone = new DateTimeZone((string) (new MgsConfigService())->get('platform_timezone', config('mgs.timezone', 'UTC')));
        $now = new DateTimeImmutable('now', $zone);
        $result = match ($data['action'] ?? '') {
            'stats' => $this->stats($now),
            'tables' => (new MgsTableService())->recent(),
            'sync' => Redis::send('mgs_game_sync', []),
            'settlement' => (new MgsSettlementService())->generate($data['month'] ?? null),
            default => throw new \InvalidArgumentException('MGS 任务动作无效'),
        };
        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function stats(DateTimeImmutable $now): array
    {
        MgsStatsRefresh::dispatch($now->format('Y-m-d'));
        MgsStatsRefresh::dispatch($now->format('Y-m-d'), (int) $now->format('G'));
        $previous = $now->modify('-1 hour');
        MgsStatsRefresh::dispatch($previous->format('Y-m-d'), (int) $previous->format('G'));
        return ['queued' => true, 'date' => $now->format('Y-m-d')];
    }
}
