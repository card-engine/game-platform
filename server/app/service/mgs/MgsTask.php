<?php

namespace app\service\mgs;

use app\enum\RedisKey;
use app\queue\redis\serial\MgsStatsRefresh;
use DateTimeImmutable;
use DateTimeZone;
use support\Redis;
use Webman\RedisQueue\Redis as RedisQueue;

class MgsTask
{
    public function run(?string $parameter): string
    {
        $data = json_decode($parameter ?: '{}', true);
        $action = (string) ($data['action'] ?? '');
        $key = RedisKey::LockMgsTask->format($action);
        $token = bin2hex(random_bytes(12));
        if (!Redis::set($key, $token, 'EX', RedisKey::EXPIRE_1_HOUR, 'NX')) return '{"skipped":"running"}';
        try {
            $zone = new DateTimeZone((string) (new MgsConfigService())->get('platform_timezone', config('mgs.timezone', 'UTC')));
            $now = new DateTimeImmutable('now', $zone);
            $result = match ($action) {
                'stats' => $this->stats($now),
                'tables' => (new MgsTableService())->recent(),
                'sync' => RedisQueue::send('mgs_game_sync', []),
                'settlement' => (new MgsSettlementService())->generate($data['month'] ?? null),
                default => throw new \InvalidArgumentException('MGS 任务动作无效'),
            };
            return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } finally {
            Redis::eval("if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0", 1, $key, $token);
        }
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
