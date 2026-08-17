<?php

namespace app\service\game\report;

use app\model\Merchant;
use app\service\game\ConfigService;
use app\service\game\trade\MonthlyTableService;
use DateTimeImmutable;
use DateTimeZone;
use support\Db;

class DailyStatService
{
    public function rebuildCurrent(): array
    {
        $dates = [];
        foreach (Merchant::where('status', 1)->pluck('timezone') as $timezone) {
            $today = new DateTimeImmutable('now', new DateTimeZone($timezone));
            $dates[] = $today->format('Y-m-d');
            $dates[] = $today->modify('-1 day')->format('Y-m-d');
        }
        $platformToday = new DateTimeImmutable('now', new DateTimeZone((new ConfigService())->get('platform_timezone', 'UTC')));
        $dates[] = $platformToday->format('Y-m-d');
        $dates[] = $platformToday->modify('-1 day')->format('Y-m-d');
        $rows = 0;
        foreach (array_values(array_unique($dates)) as $date) $rows += $this->rebuild($date)['rows'];
        return ['dates' => array_values(array_unique($dates)), 'rows' => $rows];
    }

    public function rebuild(string $date): array
    {
        $day = new DateTimeImmutable($date, new DateTimeZone('UTC'));
        $tables = [];
        foreach ([-1, 0, 1] as $offset) $tables[] = (new MonthlyTableService())->table('bets', $day->modify("{$offset} month")->format('ym'));
        $fields = 'business_date, platform_date, merchant_id, platform_code, brand_id, game_id, currency_code, user_id, debit_count, credit_count, rollback_count, bet_amount, win_amount, bet_rollback_amount, win_rollback_amount, ggr_amount, billable_ggr_amount, upstream_fee, merchant_fee, status';
        $union = implode(' UNION ALL ', array_map(fn ($table) => "SELECT {$fields} FROM `{$table}` WHERE (business_date = ? OR platform_date = ?) AND delete_time IS NULL", $tables));
        $rows = Db::select("SELECT business_date, platform_date, merchant_id, platform_code, brand_id, game_id, currency_code, COUNT(DISTINCT user_id) user_count, COUNT(*) bet_count, SUM(debit_count + credit_count + rollback_count) bill_count, SUM(bet_amount) bet_amount, SUM(GREATEST(bet_amount - bet_rollback_amount, 0)) valid_bet_amount, SUM(win_amount) win_amount, SUM(bet_rollback_amount + win_rollback_amount) rollback_amount, SUM(ggr_amount) ggr_amount, SUM(billable_ggr_amount) billable_ggr_amount, SUM(upstream_fee) upstream_fee, SUM(merchant_fee) merchant_fee, SUM(status = 4) exception_count FROM ({$union}) bets GROUP BY business_date, platform_date, merchant_id, platform_code, brand_id, game_id, currency_code", array_merge(...array_fill(0, 3, [$date, $date])));
        Db::table('mg_daily_stats')->where('business_date', $date)->orWhere('platform_date', $date)->delete();
        $target = strtoupper((string) (new ConfigService())->get('platform_currency_code', 'USD'));
        foreach ($rows as $item) {
            $row = (array) $item;
            $conversion = strtoupper($row['currency_code']) === $target || ($row['currency_code'] === 'SC' && $target === 'USD')
                ? ['id' => null, 'value' => '1']
                : (new ExchangeRateService())->conversion($row['platform_date'], $row['currency_code'], $target);
            $row += ['platform_currency_code' => $conversion ? $target : null, 'exchange_rate_id' => $conversion['id'] ?? null, 'exchange_rate_value' => $conversion['value'] ?? null];
            foreach (['bet_amount', 'valid_bet_amount', 'win_amount', 'ggr_amount', 'upstream_fee', 'merchant_fee'] as $field) {
                $row['platform_' . $field] = $conversion ? bcmul((string) $row[$field], $conversion['value'], 8) : '0.00000000';
            }
            Db::table('mg_daily_stats')->insert($row + ['create_time' => gmdate('Y-m-d H:i:s'), 'update_time' => gmdate('Y-m-d H:i:s')]);
        }
        return ['date' => $date, 'rows' => count($rows)];
    }
}
