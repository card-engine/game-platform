<?php

namespace app\service\game\report;

use app\model\Merchant;
use app\model\MerchantMonthlyBill;
use app\model\MonthlyStat;
use DateTimeImmutable;
use DateTimeZone;
use app\model\MerchantCredit;
use app\service\game\trade\MonthlyTableService;
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
            if (MerchantMonthlyBill::where(['merchant_id' => $merchant->id, 'billing_month' => $monthDate])->exists()) continue;
            $first = !MerchantMonthlyBill::where('merchant_id', $merchant->id)->exists();
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
        foreach (Merchant::where(['billing_mode' => 1, 'status' => 1])->get() as $merchant) {
            $billingMonth = new DateTimeImmutable(($month ?: 'first day of this month'), new DateTimeZone($merchant->timezone ?: 'UTC'));
            $billingMonth = $billingMonth->modify('first day of this month');
            $source = $billingMonth->modify('-1 month');
            $start = $source->format('Y-m-d');
            $end = $billingMonth->format('Y-m-d');
            $tables = [];
            foreach ([-1, 0, 1] as $offset) $tables[] = (new MonthlyTableService())->table('bets', $source->modify("{$offset} month")->format('ym'));
            $union = implode(' UNION ALL ', array_map(fn ($table) => "SELECT currency_code, SUM(ggr_amount) ggr_amount FROM `{$table}` WHERE merchant_id = ? AND business_date >= ? AND business_date < ? AND status = 2 AND delete_time IS NULL GROUP BY currency_code", $tables));
            $bindings = array_merge(...array_fill(0, count($tables), [$merchant->id, $start, $end]));
            foreach (Db::select("SELECT currency_code, SUM(ggr_amount) ggr_amount FROM ({$union}) bets GROUP BY currency_code", $bindings) as $row) {
                $ggr = (string) $row->ggr_amount;
                $billable = bccomp($ggr, '0', 8) > 0 ? $ggr : '0.00000000';
                $credit = MerchantCredit::where(['merchant_id' => $merchant->id, 'currency_code' => $row->currency_code])->first();
                if (!$credit || MerchantMonthlyBill::where(['merchant_id' => $merchant->id, 'currency_code' => $row->currency_code, 'billing_month' => $billingMonth->format('Y-m-d')])->exists()) continue;
                $fee = bcmul($billable, (string) $credit->rate_value, 8);
                MerchantMonthlyBill::create(['bill_no' => mg_no('MF'), 'merchant_id' => $merchant->id, 'currency_code' => $row->currency_code, 'billing_month' => $billingMonth->format('Y-m-d'), 'source_month' => $source->format('Y-m-d'), 'metric_type' => 0, 'metric_value' => $ggr, 'ggr_amount' => $ggr, 'billable_ggr_amount' => $billable, 'amount' => $fee, 'status' => 0, 'rules_snapshot' => ['billing_mode' => 1, 'rate_value' => $credit->rate_value]]);
                $before = (string) $credit->payable_amount;
                $credit->update(['payable_amount' => bcadd($before, $fee, 8)]);
                if (bccomp($fee, '0', 8) > 0) Db::table('mg_merchant_bills')->insert(['bill_no' => mg_no('MC'), 'credit_id' => $credit->id, 'type' => 2, 'direction' => 2, 'amount' => $fee, 'before_amount' => $before, 'after_amount' => $credit->payable_amount, 'source' => 'monthly_ggr', 'source_no' => $merchant->id . ':' . $billingMonth->format('Ym'), 'data' => json_encode(['ggr_amount' => $ggr, 'billable_ggr_amount' => $billable]), 'create_time' => gmdate('Y-m-d H:i:s')]);
                $created++;
            }
        }
        return ['months' => array_values(array_unique($months)), 'created' => $created];
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
