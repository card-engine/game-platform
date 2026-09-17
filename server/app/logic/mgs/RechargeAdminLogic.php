<?php

namespace app\logic\mgs;

use app\model\Recharge;
use app\model\Transfer;
use plugin\saiadmin\basic\eloquent\BaseLogic;
use plugin\saiadmin\exception\ApiException;

class RechargeAdminLogic extends BaseLogic
{
    public function __construct() { $this->model = new Recharge(); }

    public function recharges(array $filters): array
    {
        return $this->getList(Recharge::query()->when($filters['keyword'] ?? '', fn ($q, $v) => $q->where('recharge_no', $v))
            ->when($filters['status'] ?? '', fn ($q, $v) => $q->where('status', $v))
            ->when($filters['currency_code'] ?? '', fn ($q, $v) => $q->where('currency_code', $v)));
    }

    public function transfers(array $filters): array
    {
        return $this->getList(Transfer::query()->with('recharge:id,recharge_no')
            ->when($filters['keyword'] ?? '', fn ($q, $v) => $q->where('transaction_id', $v))
            ->when($filters['status'] ?? '', fn ($q, $v) => $q->where('status', $v))
            ->when($filters['currency_code'] ?? '', fn ($q, $v) => $q->where('currency_code', $v)));
    }

    public function detail(string $type, string $id): array
    {
        return $type === 'recharge' ? Recharge::with('transfers')->findOrFail($id)->toArray() : Transfer::with('recharge')->findOrFail($id)->toArray();
    }

    public function review(int $id, string $status, string $remark, int $adminId): void
    {
        $rechargeId = Transfer::findOrFail($id)->recharge_id;
        $this->transaction(function () use ($id, $status, $remark, $adminId, $rechargeId) {
            // 与入账保持订单在前、收款在后的锁顺序。
            $order = $rechargeId ? Recharge::whereKey($rechargeId)->lockForUpdate()->first() : null;
            $transfer = Transfer::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($transfer->recharge_id !== $rechargeId) throw new ApiException('收款关联已改变，请刷新');
            if (!in_array($transfer->status, ['review', 'ignored'], true)) throw new ApiException('只能核验待核验或已忽略的收款');
            $data = $transfer->data;
            $data['reviews'][] = ['admin_id' => $adminId, 'time' => gmdate('c'), 'from' => $transfer->status, 'to' => $status, 'remark' => $remark];
            $transfer->update(['status' => $status, 'remark' => $remark, 'data' => $data]);
            if ($order && $status === 'ignored' && $order->status === 'review'
                && !Transfer::where('recharge_id', $order->id)->where('status', 'review')->exists()) {
                $order->update(['status' => $order->expire_time > gmdate('Y-m-d H:i:s') ? 'pending' : 'expired']);
            }
        });
    }
}
