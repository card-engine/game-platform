<?php

namespace app\service\game\report;

use app\model\Merchant;
use app\model\MerchantMonthlyBill;
use app\model\MonthlyStat;
use DateTimeImmutable;
use DateTimeZone;

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
