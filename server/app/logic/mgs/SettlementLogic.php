<?php

namespace app\logic\mgs;

use app\logic\game\MerchantLogic;
use app\model\mgs\Settlement;
use app\service\game\EnterpriseScope;
use app\service\mgs\MgsConfigService;
use app\service\mgs\MgsSettlementService;
use plugin\saiadmin\basic\eloquent\BaseLogic;
use plugin\saiadmin\exception\ApiException;

class SettlementLogic extends BaseLogic
{
    public function __construct() { $this->model = new Settlement(); }

    public function detail(int $id): array
    {
        return Settlement::findOrFail($id)->toArray();
    }

    public function confirm(int $id, string $remark): void
    {
        if (!EnterpriseScope::isGameSuperAdmin((int) $this->adminInfo['id'])) throw new ApiException('仅游戏超管可确认结算');
        $row = Settlement::findOrFail($id);
        $source = (new MgsSettlementService())->source($row);
        $this->transaction(function () use ($id, $source, $remark) {
            $row = Settlement::whereKey($id)->lockForUpdate()->firstOrFail();
            if ((int) $row->status !== 0) return;
            if (!hash_equals($source['snapshot_hash'], $row->data['source']['snapshot_hash'])) throw new ApiException('来源月账单已变化，请重新生成');
            $row->update(['status' => 1, 'confirmed_by' => $this->adminInfo['id'], 'confirmed_time' => gmdate('Y-m-d H:i:s.v'), 'remark' => $remark]);
            if (bccomp((string) $row->platform_fee, '0', 8) === 0) $this->pay($id, '无需付款', gmdate('Y-m-d H:i:s.v'), $remark);
        });
    }

    public function reopen(int $id, string $remark): void
    {
        if (!EnterpriseScope::isGameSuperAdmin((int) $this->adminInfo['id'])) throw new ApiException('仅游戏超管可撤回确认');
        $row = Settlement::findOrFail($id);
        $source = (new MgsSettlementService())->source($row);
        if (in_array((int) $source['status'], [1, 3], true)) throw new ApiException('来源账单已结清，不可撤回');
        $this->transaction(function () use ($id, $remark) {
            $row = Settlement::whereKey($id)->lockForUpdate()->firstOrFail();
            if ((int) $row->status !== 1) throw new ApiException('仅已确认未结清单可撤回');
            $row->update(['status' => 0, 'confirmed_by' => null, 'confirmed_time' => null, 'remark' => $remark]);
        });
    }

    public function pay(int $id, string $reference, string $paidTime, string $remark): void
    {
        if (!EnterpriseScope::isGameSuperAdmin((int) $this->adminInfo['id'])) throw new ApiException('仅游戏超管可登记结清');
        $mchId = (string) (new MgsConfigService())->get('game_platform_mch_id', config('mgs.mch_id'));
        $this->transaction(function () use ($id, $reference, $paidTime, $remark, $mchId) {
            $row = Settlement::whereKey($id)->lockForUpdate()->firstOrFail();
            if ((int) $row->status === 2) return;
            if ((int) $row->status !== 1) throw new ApiException('请先确认结算');
            // 同库管理端复用 MG 的授权及核销事务；不开放商户自行标记付款的接口。
            $billing = new MerchantLogic();
            $billing->init($this->adminInfo);
            $source = $billing->billStatus((int) $row->data['source']['id'], 1, $reference . ' ' . $remark,
                ['mch_id' => $mchId, 'snapshot_hash' => $row->data['source']['snapshot_hash']], $paidTime);
            $row->update(['status' => 2, 'paid_by' => $this->adminInfo['id'], 'paid_time' => $source['paid_time'], 'data' => array_replace($row->data, ['source' => $source]), 'payment_reference' => $reference, 'remark' => $remark]);
        });
    }
}
