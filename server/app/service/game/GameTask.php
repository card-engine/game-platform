<?php

namespace app\service\game;

use app\service\game\report\DailyStatService;
use app\service\game\report\ExchangeRateService;
use app\service\game\report\MonthlyBillingService;
use app\service\game\report\TrendStatService;
use app\service\game\trade\MonthlyTableService;
use app\service\game\trade\TradeService;

class GameTask
{
    public function run(?string $parameter): string
    {
        $data = json_decode($parameter ?: '{}', true);
        $result = match ($data['action'] ?? '') {
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
    }
}
