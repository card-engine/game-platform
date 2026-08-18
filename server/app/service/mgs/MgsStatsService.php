<?php

namespace app\service\mgs;

use app\model\mgs\DailyStat;
use app\model\mgs\HourlyStat;
use app\model\mgs\MonthlyStat;
use DateTimeImmutable;
use DateTimeZone;
use support\Db;

class MgsStatsService
{
    public function rebuildDate(string $date): void
    {
        $zone = new DateTimeZone((string) (new MgsConfigService())->get('platform_timezone', config('mgs.timezone', 'UTC')));
        $start = new DateTimeImmutable($date . ' 00:00:00', $zone);
        $rows = $this->aggregate($start->setTimezone(new DateTimeZone('UTC')), $start->modify('+1 day')->setTimezone(new DateTimeZone('UTC')), true);
        $this->replace(DailyStat::class, ['stat_date' => $date], ['stat_date' => $date], $rows);
    }

    public function rebuildHour(string $date, int $hour): void
    {
        $zone = new DateTimeZone((string) (new MgsConfigService())->get('platform_timezone', config('mgs.timezone', 'UTC')));
        $start = new DateTimeImmutable(sprintf('%s %02d:00:00', $date, $hour), $zone);
        $rows = $this->aggregate($start->setTimezone(new DateTimeZone('UTC')), $start->modify('+1 hour')->setTimezone(new DateTimeZone('UTC')));
        $this->replace(HourlyStat::class, ['stat_date' => $date, 'stat_hour' => $hour], ['stat_date' => $date, 'stat_hour' => $hour], $rows);
    }

    public function rebuildMonth(string $month): void
    {
        $zone = new DateTimeZone((string) (new MgsConfigService())->get('platform_timezone', config('mgs.timezone', 'UTC')));
        $start = new DateTimeImmutable($month . '-01 00:00:00', $zone);
        $rows = $this->aggregate($start->setTimezone(new DateTimeZone('UTC')), $start->modify('+1 month')->setTimezone(new DateTimeZone('UTC')));
        $this->replace(MonthlyStat::class, ['stat_month' => $month], ['stat_month' => $month], $rows);
    }

    private function aggregate(DateTimeImmutable $start, DateTimeImmutable $end, bool $byGame = false): array
    {
        $tables = [];
        $month = new DateTimeImmutable($start->format('Y-m-01'), new DateTimeZone('UTC'));
        $last = new DateTimeImmutable($end->format('Y-m-01'), new DateTimeZone('UTC'));
        while ($month <= $last) {
            $tables[] = (new MgsTableService())->table('bets', $month->format('ym'));
            $month = $month->modify('+1 month');
        }
        $parts = [];
        $bindings = [];
        foreach ($tables as $table) {
            $parts[] = "SELECT user_id, game_id, currency_code, bet_amount, bet_rollback_amount, win_amount, win_rollback_amount, ggr_amount, platform_fee FROM `{$table}` WHERE delete_time IS NULL AND create_time >= ? AND create_time < ?";
            $bindings[] = $start->format('Y-m-d H:i:s.v');
            $bindings[] = $end->format('Y-m-d H:i:s.v');
        }
        if (!$parts) return [];
        $group = $byGame ? 'game_id, currency_code' : 'currency_code';
        $select = $byGame ? 'game_id,' : '';
        $rows = Db::select("SELECT {$select} currency_code, COUNT(DISTINCT CASE WHEN bet_amount > 0 THEN user_id END) active_user_count, SUM(bet_amount > 0) bet_count, COALESCE(SUM(bet_amount), 0) bet_amount, COALESCE(SUM(win_amount), 0) win_amount, COALESCE(SUM(bet_rollback_amount + win_rollback_amount), 0) rollback_amount, COALESCE(SUM(ggr_amount), 0) ggr_amount, COALESCE(SUM(platform_fee), 0) platform_fee FROM (" . implode(' UNION ALL ', $parts) . ") bets GROUP BY {$group}", $bindings);
        return array_map(fn ($row) => (array) $row, $rows);
    }

    private function replace(string $model, array $where, array $scope, array $rows): void
    {
        Db::transaction(function () use ($model, $where, $scope, $rows) {
            Db::table((new $model())->getTable())->where($where)->delete();
            $now = $this->now();
            foreach ($rows as $row) {
                $row['rtp_value'] = bccomp((string) $row['bet_amount'], '0', 8) > 0 ? bcdiv((string) $row['win_amount'], (string) $row['bet_amount'], 10) : null;
                $model::create($row + $scope + ['create_time' => $now, 'update_time' => $now]);
            }
        });
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s.v');
    }
}
