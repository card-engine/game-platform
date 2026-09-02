<?php

namespace app\service\game;

use app\enum\RedisKey;
use app\service\game\report\DailyStatService;
use app\service\game\report\ExchangeRateService;
use app\service\game\report\MonthlyBillingService;
use app\service\game\report\TrendStatService;
use app\service\game\trade\MonthlyTableService;
use app\service\game\trade\TradeService;
use support\Redis;

class GameTask
{
    public function run(?string $parameter): string
    {
        $data = json_decode($parameter ?: '{}', true);
        $action = (string) ($data['action'] ?? '');
        $key = RedisKey::LockGameTask->format($action);
        $token = bin2hex(random_bytes(12));
        if (!Redis::set($key, $token, 'EX', RedisKey::EXPIRE_1_HOUR, 'NX')) return '{"skipped":"running"}';
        try {
            $result = match ($action) {
                'stats' => [
                    'daily' => isset($data['date']) ? (new DailyStatService())->rebuild($data['date']) : (new DailyStatService())->rebuildCurrent(),
                    'trend' => (new TrendStatService())->rebuildCurrent(),
                ],
                'exchange_rate' => (new ExchangeRateService())->sync()->only(['id', 'rate_date', 'source_update_time']),
                'billing' => (new MonthlyBillingService())->generate($data['month'] ?? null),
                'retry' => (new TradeService())->retryUnknown((int) ($data['limit'] ?? 100)),
                'tables' => (new MonthlyTableService())->precreate(),
                'sync' => (new SyncService())->sync((string) ($data['platform'] ?? '')),
                default => throw new \InvalidArgumentException('MG 任务动作无效'),
            };
            return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } finally {
            Redis::eval("if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0", 1, $key, $token);
        }
    }
}
