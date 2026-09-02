<?php

namespace app\queue\redis\parallel;

use app\service\game\trade\MonthlyTableService;
use app\service\game\trade\TradeService;
use Webman\RedisQueue\Consumer;
use Webman\RedisQueue\Redis;

class GameBetClose implements Consumer
{
    public string $queue = 'game_bet_close';
    public string $connection = 'default';

    public static function dispatch(string $betNo, string $month, int $delay = 600): void
    {
        Redis::connection()->zAdd('{redis-queue}-delayed', time() + $delay, self::package($betNo, $month));
    }

    public static function cancel(string $betNo, string $month): void
    {
        Redis::connection()->zRem('{redis-queue}-delayed', self::package($betNo, $month));
    }

    public function consume($data): void
    {
        $table = (new MonthlyTableService())->table('bets', (string) $data['month']);
        $bet = (array) \support\Db::table($table)->where('bet_no', $data['bet_no'])->first();
        if (!$bet || (int) $bet['status'] !== 1) return;

        $remaining = 600 - (time() - strtotime((string) $bet['update_time']));
        if ($remaining > 0) {
            self::dispatch($bet['bet_no'], $data['month'], $remaining);
            return;
        }

        $result = (new TradeService())->handle($bet['platform_code'], [
            'action' => 'credit',
            'player_id' => 'mg_' . id2big((int) $bet['user_id']) . '_' . strtolower($bet['currency_code']),
            'source_no' => 'auto_close_' . $bet['bet_no'],
            'round_id' => $bet['provider_round_id'],
            'parent_round_id' => $bet['provider_parent_round_id'] ?: $bet['provider_round_id'],
            'amount' => '0',
            'finished' => true,
            'auto_close_bet_no' => $bet['bet_no'],
            'auto_close_month' => $data['month'],
        ]);
        if (($result['code'] ?? 0) === 1005) self::dispatch($bet['bet_no'], $data['month'], 5);
    }

    private static function package(string $betNo, string $month): string
    {
        return json_encode([
            'id' => 'game_bet_close:' . $betNo,
            'time' => 0,
            'delay' => 600,
            'attempts' => 0,
            'queue' => 'game_bet_close',
            'data' => ['bet_no' => $betNo, 'month' => $month],
        ], JSON_UNESCAPED_SLASHES);
    }
}
