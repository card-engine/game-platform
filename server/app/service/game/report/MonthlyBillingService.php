<?php

namespace app\service\game\report;

use app\model\Merchant;
use app\model\MerchantCredit;
use app\model\MerchantMonthlyBill;
use app\model\MonthlyStat;
use DateTimeImmutable;
use DateTimeZone;
use support\Db;

class MonthlyBillingService
{
    public function stats(Merchant $merchant, ?string $month = null): MonthlyStat
    {
        $day = new DateTimeImmutable(($month ?: 'first day of this month'), new DateTimeZone($merchant->timezone));
        $month = $day->format('Y-m');
        (new TrendStatService())->rebuildMonth((int) $merchant->id, $merchant->timezone, $month);
        return MonthlyStat::where(['merchant_id' => $merchant->id, 'stat_month' => $month])->firstOrFail();
    }

    public function preview(Merchant $merchant, ?string $month = null): array
    {
        $stat = $this->stats($merchant, $month);
        $value = (int) $merchant->monthly_metric === 1 ? (string) $stat->converted_bet_amount : (string) $stat->bet_count;
        return ['stats' => $stat, 'next_fee' => $this->fee($merchant, $value)];
    }

    public function generate(?string $month = null): array
    {
        $created = 0;
        $months = [];
        foreach (Merchant::where(['billing_mode' => 2, 'status' => 1])->get() as $merchant) {
            $billingMonth = new DateTimeImmutable(($month ?: 'first day of this month'), new DateTimeZone($merchant->timezone ?: 'UTC'));
            $billingMonth = $billingMonth->modify('first day of this month');
            $monthDate = $billingMonth->format('Y-m-d');
            $months[] = $billingMonth->format('Y-m');
            if (MerchantMonthlyBill::withTrashed()->where(['merchant_id' => $merchant->id, 'billing_month' => $monthDate, 'billing_mode' => 2])->exists()) continue;
            $first = !MerchantMonthlyBill::withTrashed()->where(['merchant_id' => $merchant->id, 'billing_mode' => 2])->exists();
            $sourceMonth = $billingMonth->modify('-1 month')->format('Y-m-d');
            $stat = $first ? null : $this->stats($merchant, $sourceMonth);
            $value = $stat ? ((int) $merchant->monthly_metric === 1 ? (string) $stat->converted_bet_amount : (string) $stat->bet_count) : '0';
            MerchantMonthlyBill::create([
                'bill_no' => mg_no('MF'), 'merchant_id' => $merchant->id, 'billing_month' => $monthDate,
                'source_month' => $first ? null : $sourceMonth, 'metric_type' => $merchant->monthly_metric,
                'metric_value' => $value, 'amount' => $first ? (string) $merchant->monthly_min_fee : $this->fee($merchant, $value),
                'status' => 0, 'rules_snapshot' => $merchant->monthly_tiers ?: [['min' => '0', 'fee' => (string) $merchant->monthly_min_fee]],
                'remark' => $first ? '首月固定最低月费' : null,
            ]);
            $created++;
        }
        // 按注单快照出账；停用或切换计费模式不能抹掉历史费用，任务停机后补齐遗漏月份。
        $tables = array_filter(Db::table('information_schema.tables')->where('table_schema', config('database.connections.mysql.database'))->pluck('TABLE_NAME')->all(), fn ($table) => preg_match('/^mg_bets_\d{4}$/', $table));
        foreach (Merchant::get() as $merchant) {
            $source = (new DateTimeImmutable($month ?: 'first day of this month', new DateTimeZone($merchant->timezone)))->modify('first day of last month')->format('Y-m');
            $periods = collect([$source]);
            foreach ($tables as $table) {
                $first = Db::table($table)->where('merchant_id', $merchant->id)->where('billing_mode', 1)->where('settlement_enabled', 1)->whereNull('delete_time')->min('settled_time');
                if (!$first) continue;
                $first = (new DateTimeImmutable($first, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($merchant->timezone))->modify('first day of this month');
                while ($first->format('Y-m') <= $source) {
                    $periods->push($first->format('Y-m'));
                    $first = $first->modify('+1 month');
                }
            }
            $periods = $periods->unique()->sort();
            foreach ($periods as $period) $created += $this->generateGgr($merchant, $period);
            $months[] = $source;
        }
        return ['months' => array_values(array_unique($months)), 'created' => $created];
    }

    /** 汇总已结束自然月，正负 GGR 及各注单费率均参与抵扣。 */
    public function generateGgr(Merchant $merchant, string $month): int
    {
        $previous = MerchantMonthlyBill::where(['merchant_id' => $merchant->id, 'billing_mode' => 1, 'source_month' => $month . '-01'])->value('rules_snapshot');
        $zone = new DateTimeZone($previous['timezone'] ?? $merchant->timezone);
        $start = new DateTimeImmutable($month . '-01 00:00:00', $zone);
        $end = $start->modify('+1 month');
        if ($end > new DateTimeImmutable('now', $zone)) return 0;
        $tables = array_values(array_filter(Db::table('information_schema.tables')->where('table_schema', config('database.connections.mysql.database'))->pluck('TABLE_NAME')->all(), fn ($table) => preg_match('/^mg_bets_\d{4}$/', $table)));
        if (!$tables) return 0;
        $utc = new DateTimeZone('UTC');
        $union = implode(' UNION ALL ', array_map(fn ($table) => "SELECT currency_code, merchant_rate_value, bet_amount, win_amount, ggr_amount, merchant_fee FROM `{$table}` WHERE merchant_id = ? AND settled_time >= ? AND settled_time < ? AND status = 2 AND billing_mode = 1 AND settlement_enabled = 1 AND currency_code <> 'GC' AND delete_time IS NULL", $tables));
        $bindings = array_merge(...array_fill(0, count($tables), [$merchant->id, $start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s')]));
        $rows = collect(Db::select("SELECT currency_code, merchant_rate_value, COUNT(*) bet_count, SUM(bet_amount) bet_amount, SUM(win_amount) win_amount, SUM(ggr_amount) ggr_amount, SUM(merchant_fee) legacy_fee FROM ({$union}) bets GROUP BY currency_code, merchant_rate_value", $bindings))->groupBy('currency_code');
        $created = 0;
        foreach ($rows as $currency => $rates) {
            $created += Db::transaction(function () use ($merchant, $currency, $rates, $start, $end) {
                $credit = MerchantCredit::where(['merchant_id' => $merchant->id, 'currency_code' => $currency])->lockForUpdate()->firstOrFail();
                $bill = MerchantMonthlyBill::withTrashed()->firstOrNew(['merchant_id' => $merchant->id, 'currency_code' => $currency, 'billing_month' => $end->format('Y-m-d'), 'billing_mode' => 1]);
                $ggr = $weighted = $legacy = '0.00000000';
                foreach ($rates as $rate) {
                    $ggr = bcadd($ggr, (string) $rate->ggr_amount, 8);
                    $weighted = bcadd($weighted, bcmul((string) $rate->ggr_amount, (string) $rate->merchant_rate_value, 8), 8);
                    $legacy = bcadd($legacy, (string) $rate->legacy_fee, 8);
                }
                $billable = bccomp($ggr, '0', 8) > 0 ? $ggr : '0.00000000';
                $fee = bccomp($ggr, '0', 8) > 0 && bccomp($weighted, '0', 8) > 0 ? $weighted : '0.00000000';
                if ($bill->exists && in_array((int) $bill->status, [1, 3], true)) {
                    if (bccomp((string) $bill->amount, $fee, 8) !== 0 || bccomp((string) $bill->ggr_amount, $ggr, 8) !== 0) throw new \RuntimeException("月结账单 {$bill->bill_no} 已支付或减免，迟到交易需对账调整");
                    return 0;
                }
                // 旧版已按单笔收费的部分抵扣，避免升级后重复收费。
                $difference = bcsub($fee, $bill->exists ? (string) $bill->amount : $legacy, 8);
                $created = (int) !$bill->exists;
                $snapshot = ['timezone' => $start->getTimezone()->getName(), 'rates' => $rates->values()->all(), 'legacy_fee' => $legacy, 'weighted_fee' => $weighted];
                $bill->fill([
                    'bill_no' => $bill->bill_no ?: mg_no('MF'), 'source_month' => $start->format('Y-m-d'),
                    'metric_type' => 0, 'metric_value' => $ggr, 'ggr_amount' => $ggr, 'billable_ggr_amount' => $billable,
                    'amount' => $fee, 'rules_snapshot' => $snapshot, 'delete_time' => null,
                ])->save();
                $before = (string) $credit->payable_amount;
                $credit->update(['payable_amount' => bcadd($before, $difference, 8), 'available_amount' => bcsub((string) $credit->available_amount, $difference, 8)]);
                Db::table('mg_merchant_monthly_usages')->updateOrInsert(
                    ['credit_id' => $credit->id, 'billing_month' => $start->format('Y-m-d')],
                    ['bet_count' => $rates->sum('bet_count'), 'ggr_amount' => $ggr, 'billable_ggr_amount' => $billable, 'billed_amount' => $fee, 'rules_snapshot' => json_encode($snapshot), 'update_time' => gmdate('Y-m-d H:i:s'), 'delete_time' => null],
                );
                if (bccomp($difference, '0', 8) !== 0) Db::table('mg_merchant_bills')->insert([
                    'bill_no' => mg_no('MC'), 'credit_id' => $credit->id, 'type' => 2, 'direction' => bccomp($difference, '0', 8) > 0 ? 2 : 1,
                    'amount' => ltrim($difference, '-'), 'before_amount' => $before, 'after_amount' => $credit->payable_amount,
                    'source' => 'monthly_ggr', 'source_no' => $bill->bill_no, 'data' => json_encode(['ggr_amount' => $ggr, 'billable_ggr_amount' => $billable]), 'create_time' => gmdate('Y-m-d H:i:s'),
                ]);
                return $created;
            });
        }
        return $created;
    }

    /** 对外只读月账单快照；版本仅涵盖账务口径，不包含支付状态。 */
    public function snapshot(MerchantMonthlyBill $bill): array
    {
        $data = $bill->only(['id', 'bill_no', 'merchant_id', 'source_month', 'currency_code', 'ggr_amount', 'amount', 'rules_snapshot']);
        $data['snapshot_hash'] = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $data + $bill->only(['status', 'paid_time', 'remark']);
    }

    private function fee(Merchant $merchant, string $value): string
    {
        $fee = (string) $merchant->monthly_min_fee;
        $tiers = $merchant->monthly_tiers ?: [['min' => '0', 'fee' => $fee]];
        usort($tiers, fn ($a, $b) => bccomp((string) $a['min'], (string) $b['min'], 8));
        foreach ($tiers as $tier) {
            if (bccomp($value, (string) $tier['min'], 8) < 0) break;
            if (bccomp((string) $tier['fee'], $fee, 8) > 0) $fee = (string) $tier['fee'];
        }
        return $fee;
    }
}
