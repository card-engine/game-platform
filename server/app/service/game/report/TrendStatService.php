<?php

namespace app\service\game\report;

use app\model\Merchant;
use app\service\game\ConfigService;
use app\service\game\trade\MonthlyTableService;
use DateTimeImmutable;
use DateTimeZone;
use support\Db;

class TrendStatService
{
    public function rebuildCurrent(): array
    {
        $scopes = [[0, (new ConfigService())->get('platform_timezone', 'UTC')]];
        foreach (Merchant::where('status', 1)->get(['id', 'timezone']) as $merchant) $scopes[] = [(int) $merchant->id, $merchant->timezone];
        $hours = $months = 0;
        foreach ($scopes as [$merchantId, $timezone]) {
            $now = new DateTimeImmutable('now', new DateTimeZone($timezone));
            foreach ([$now, $now->modify('-1 hour')] as $hour) {
                $this->rebuildHour($merchantId, $timezone, $hour->format('Y-m-d'), (int) $hour->format('G'));
                $hours++;
            }
            $this->rebuildMonth($merchantId, $timezone, $now->format('Y-m'));
            $months++;
            if ((int) $now->format('j') === 1) {
                $this->rebuildMonth($merchantId, $timezone, $now->modify('-1 month')->format('Y-m'));
                $months++;
            }
        }
        return compact('hours', 'months');
    }

    public function rebuildHour(int $merchantId, string $timezone, string $date, int $hour): void
    {
        $start = new DateTimeImmutable(sprintf('%s %02d:00:00', $date, $hour), new DateTimeZone($timezone));
        $end = $start->modify('+1 hour');
        $rows = $this->aggregate($merchantId, $start, $end);
        $target = $this->targetCurrency($merchantId);
        $converted = '0.00000000';
        $rateId = null;
        $rates = [];
        foreach ($rows['amounts'] as $currency => $amount) {
            if ($merchantId === 0) {
                $conversion = $currency === $target || ($currency === 'SC' && $target === 'USD')
                    ? ['id' => null, 'value' => '1']
                    : (new ExchangeRateService())->conversion($date, $currency, $target);
                if (!$conversion) continue;
                $rateId ??= $conversion['id'];
                $rates[$currency] = $conversion['value'];
                $converted = bcadd($converted, bcmul($amount, $conversion['value'], 8), 8);
            } elseif ($currency === $target || ($target === 'USD' && in_array($currency, ['USD', 'SC'], true))) {
                $converted = bcadd($converted, $amount, 8);
            }
        }
        Db::table('mg_hourly_stats')->updateOrInsert(
            ['merchant_id' => $merchantId, 'stat_date' => $date, 'stat_hour' => $hour],
            ['timezone' => $timezone, 'active_user_count' => $rows['users'], 'bet_count' => $rows['bets'],
                'bet_amounts' => json_encode($rows['amounts']), 'converted_currency_code' => $target,
                'converted_bet_amount' => $converted, 'exchange_rate_id' => $rateId,
                'exchange_rate_values' => $rates ? json_encode($rates) : null, 'update_time' => gmdate('Y-m-d H:i:s'), 'delete_time' => null],
        );
    }

    public function rebuildMonth(int $merchantId, string $timezone, string $month): void
    {
        $start = new DateTimeImmutable($month . '-01 00:00:00', new DateTimeZone($timezone));
        $end = $start->modify('+1 month');
        $rows = $this->aggregate($merchantId, $start, $end);
        $target = $this->targetCurrency($merchantId);
        $converted = '0.00000000';
        if ($merchantId === 0) {
            $converted = (string) Db::table('mg_daily_stats')->where('platform_date', '>=', $start->format('Y-m-d'))
                ->where('platform_date', '<', $end->format('Y-m-d'))->sum('platform_bet_amount');
        } else {
            foreach ($rows['amounts'] as $currency => $amount) {
                if ($currency === $target || ($target === 'USD' && in_array($currency, ['USD', 'SC'], true))) $converted = bcadd($converted, $amount, 8);
            }
        }
        Db::table('mg_monthly_stats')->updateOrInsert(
            ['merchant_id' => $merchantId, 'stat_month' => $month],
            ['timezone' => $timezone, 'active_user_count' => $rows['users'], 'bet_count' => $rows['bets'],
                'bet_amounts' => json_encode($rows['amounts']), 'converted_currency_code' => $target,
                'converted_bet_amount' => $converted, 'update_time' => gmdate('Y-m-d H:i:s'), 'delete_time' => null],
        );
    }

    private function aggregate(int $merchantId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $utc = new DateTimeZone('UTC');
        $startUtc = $start->setTimezone($utc);
        $endUtc = $end->setTimezone($utc);
        $tables = [];
        $month = $startUtc->modify('first day of this month');
        while ($month <= $endUtc) {
            $tables[] = (new MonthlyTableService())->table('bets', $month->format('ym'));
            $month = $month->modify('+1 month');
        }
        $where = $merchantId ? ' AND merchant_id = ?' : ' AND merchant_id > 0';
        $union = implode(' UNION ALL ', array_map(fn ($table) => "SELECT user_id, currency_code, bet_amount, bet_rollback_amount FROM `{$table}` WHERE create_time >= ? AND create_time < ?{$where} AND delete_time IS NULL", $tables));
        $bindings = [];
        foreach ($tables as $_) array_push($bindings, $startUtc->format('Y-m-d H:i:s'), $endUtc->format('Y-m-d H:i:s'), ...($merchantId ? [$merchantId] : []));
        $summary = (array) Db::selectOne("SELECT COUNT(DISTINCT CASE WHEN bet_amount > bet_rollback_amount THEN user_id END) users, SUM(bet_amount > bet_rollback_amount) bets FROM ({$union}) bets", $bindings);
        $amounts = [];
        foreach (Db::select("SELECT currency_code, SUM(GREATEST(bet_amount - bet_rollback_amount, 0)) amount FROM ({$union}) bets GROUP BY currency_code", $bindings) as $row) $amounts[$row->currency_code] = (string) $row->amount;
        return ['users' => (int) ($summary['users'] ?? 0), 'bets' => (int) ($summary['bets'] ?? 0), 'amounts' => $amounts];
    }

    private function targetCurrency(int $merchantId): string
    {
        if ($merchantId === 0) return strtoupper((string) (new ConfigService())->get('platform_currency_code', 'USD'));
        $currencies = Db::table('mg_merchant_credits')->where(['merchant_id' => $merchantId, 'status' => 1])->whereNull('delete_time')->pluck('currency_code')->all();
        return in_array('SC', $currencies, true) ? 'USD' : (string) (array_values(array_diff($currencies, ['GC']))[0] ?? 'USD');
    }
}
