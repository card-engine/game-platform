<?php

namespace app\service\mgs;

use app\model\mgs\MonthlyStat;
use app\model\mgs\Settlement;
use DateTimeImmutable;
use DateTimeZone;

class MgsSettlementService
{
    public function generate(?string $month = null): int
    {
        if ($month === null) {
            $zone = new DateTimeZone((string) (new MgsConfigService())->get('platform_timezone', config('mgs.timezone', 'UTC')));
            $month = (new DateTimeImmutable('first day of last month', $zone))->format('Y-m');
        }
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) throw new \InvalidArgumentException('结算月份无效');
        (new MgsStatsService())->rebuildMonth($month);
        $count = 0;
        foreach (MonthlyStat::where('stat_month', $month)->get() as $stat) {
            $settlement = Settlement::withTrashed()->firstOrNew(['settlement_month' => $month, 'currency_code' => $stat->currency_code]);
            if ($settlement->exists && (int) $settlement->status !== 0) continue;
            $billableGgr = bccomp((string) $stat->ggr_amount, '0', 8) > 0 ? (string) $stat->ggr_amount : '0';
            $settlement->fill([
                'settlement_no' => $settlement->settlement_no ?: mg_no('MS'),
                'bet_amount' => $stat->bet_amount, 'win_amount' => $stat->win_amount, 'ggr_amount' => $stat->ggr_amount,
                'rate_value' => bccomp($billableGgr, '0', 8) > 0 ? bcdiv((string) $stat->platform_fee, $billableGgr, 10) : '0',
                'platform_fee' => $stat->platform_fee, 'mgs_net_amount' => bcsub((string) $stat->ggr_amount, (string) $stat->platform_fee, 8),
                'status' => 0, 'data' => ['monthly_stat_id' => $stat->id, 'generated_time' => gmdate('Y-m-d H:i:s.v')],
                'delete_time' => null,
            ])->save();
            $count++;
        }
        return $count;
    }
}
