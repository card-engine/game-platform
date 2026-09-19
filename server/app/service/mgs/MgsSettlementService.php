<?php

namespace app\service\mgs;

use app\model\mgs\Settlement;
use plugin\saiadmin\exception\ApiException;
use support\Db;

class MgsSettlementService
{
    public function generate(?string $month = null): int
    {
        if ($month !== null && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) throw new ApiException('结算月份无效');
        $source = (new MgsGamePlatformClient())->post('/open_api/monthly-bills/generate', $month ? ['month' => $month] : []);
        if (!$source['bills']) throw new ApiException('该月份暂无正式月账单，请先在游戏平台生成月账单');
        return Db::transaction(function () use ($source) {
            $count = 0;
            foreach ($source['bills'] as $bill) {
                $row = Settlement::withTrashed()->firstOrCreate(['settlement_month' => $source['month'], 'currency_code' => $bill['currency_code']], ['settlement_no' => mg_no('MS')]);
                $row = Settlement::withTrashed()->whereKey($row->id)->lockForUpdate()->firstOrFail();
                if ((int) $row->status !== 0) continue;
                $bet = $win = '0';
                foreach ($bill['rules_snapshot']['rates'] as $rate) {
                    $bet = bcadd($bet, (string) ($rate['bet_amount'] ?? '0'), 8);
                    $win = bcadd($win, (string) ($rate['win_amount'] ?? '0'), 8);
                }
                $rates = array_unique(array_column($bill['rules_snapshot']['rates'], 'merchant_rate_value'));
                $row->fill(['bet_amount' => $bet, 'win_amount' => $win, 'ggr_amount' => $bill['ggr_amount'],
                    'rate_value' => count($rates) === 1 ? reset($rates) : '0', 'platform_fee' => $bill['amount'],
                    'mgs_net_amount' => bcsub($bill['ggr_amount'], $bill['amount'], 8), 'delete_time' => null,
                    'data' => ['source' => $bill, 'timezone' => $bill['rules_snapshot']['timezone'], 'generated_time' => gmdate('Y-m-d H:i:s.v')]])->save();
                $count++;
            }
            return $count;
        });
    }

    public function source(Settlement $row): array
    {
        $response = (new MgsGamePlatformClient())->post('/open_api/monthly-bills', ['month' => $row->settlement_month]);
        foreach ($response['bills'] as $bill) if ($bill['bill_no'] === ($row->data['source']['bill_no'] ?? null)) return $bill;
        throw new ApiException('来源月账单不存在，请重新生成结算');
    }
}
