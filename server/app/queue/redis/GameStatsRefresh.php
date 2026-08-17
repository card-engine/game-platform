<?php

namespace app\queue\redis;

use app\enum\RedisKey;
use app\model\Merchant;
use app\service\game\ConfigService;
use app\service\game\report\DailyStatService;
use app\service\game\report\TrendStatService;
use app\service\game\trade\MonthlyTableService;
use DateTimeImmutable;
use DateTimeZone;
use support\Db;
use support\Redis as Cache;
use Webman\RedisQueue\Consumer;
use Webman\RedisQueue\Redis;

class GameStatsRefresh implements Consumer
{
    public string $queue = 'game_stats_refresh';
    public string $connection = 'default';

    public static function dispatch(string $table, string $betNo): void
    {
        $bet = Db::table($table)->where('bet_no', $betNo)->first();
        if (!$bet || (int) $bet->merchant_id === 0) return;
        $hash = hash('sha256', "{$bet->merchant_id}|{$bet->business_date}|{$bet->platform_date}|" . substr($bet->create_time, 0, 13));
        $key = RedisKey::TempStatsRefresh->format($hash);
        if (!Cache::set($key, '1', 'EX', RedisKey::EXPIRE_5_SECONDS, 'NX')) return;
        Redis::send('game_stats_refresh', ['table' => $table, 'bet_no' => $betNo, 'key' => $key]);
    }

    public function consume($data): void
    {
        try {
            $bet = Db::table((string) $data['table'])->where('bet_no', $data['bet_no'])->first();
            if (!$bet || (int) $bet->merchant_id === 0) return;
            $daily = new DailyStatService();
            $daily->rebuild($bet->business_date);
            if ($bet->platform_date !== $bet->business_date) $daily->rebuild($bet->platform_date);

            $trend = new TrendStatService();
            $merchant = Merchant::findOrFail($bet->merchant_id);
            $platformTimezone = (new ConfigService())->get('platform_timezone', 'UTC');
            $created = new DateTimeImmutable($bet->create_time, new DateTimeZone('UTC'));
            foreach ([[0, $platformTimezone], [(int) $merchant->id, $merchant->timezone]] as [$merchantId, $timezone]) {
                $local = $created->setTimezone(new DateTimeZone($timezone));
                $trend->rebuildHour($merchantId, $timezone, $local->format('Y-m-d'), (int) $local->format('G'));
                $trend->rebuildMonth($merchantId, $timezone, $local->format('Y-m'));
            }
        } finally {
            Cache::del((string) $data['key']);
        }
    }
}
