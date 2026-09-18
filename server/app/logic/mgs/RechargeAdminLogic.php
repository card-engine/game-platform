<?php

namespace app\logic\mgs;

use app\model\Recharge;
use app\enum\RedisKey;
use app\service\mgs\TronScanService;
use plugin\saiadmin\app\cache\UserAuthCache;
use support\Redis;
use app\model\Transfer;
use plugin\saiadmin\basic\eloquent\BaseLogic;
use plugin\saiadmin\exception\ApiException;

class RechargeAdminLogic extends BaseLogic
{
    public function __construct() { $this->model = new Recharge(); }

    public function scan(): array
    {
        $status = (new TronScanService())->status();
        $recent = null;
        if ((int) $this->adminInfo['id'] === 1 || in_array('app:mgs:recharge:index', UserAuthCache::getUserAuth($this->adminInfo['id']), true)) {
            $recent = json_decode(Redis::get(RedisKey::TempMgsRecentRecharges->value) ?: 'null', true);
            if ($recent === null) {
                $recent = Recharge::where('status', 'paid')->orderByDesc('credited_time')->orderByDesc('id')->limit(10)
                    ->with(['transfers' => fn ($query) => $query->where('status', 'credited')
                        ->select(['id', 'recharge_id', 'transaction_id', 'block_number'])])
                    ->get(['id', 'recharge_no', 'user_id', 'currency_code', 'recharge_amount', 'pay_currency_code', 'pay_amount', 'credited_time'])->map(fn ($row) => array_merge($row->toArray(), ['recharge_amount' => format_amount((string) $row->recharge_amount), 'pay_amount' => format_amount((string) $row->pay_amount)]))->all();
                Redis::setex(RedisKey::TempMgsRecentRecharges->value, RedisKey::EXPIRE_1_SECOND, json_encode($recent));
            }
        }
        $scanned = ($status['checkpoint']['last_success_time'] ?? 0) ? $status['checkpoint']['next_block_number'] - 1 : null;
        return $status + [
            'scanned_number' => $scanned,
            'lag_blocks' => $scanned !== null && ($status['health']['solid_number'] ?? 0) ? max(0, $status['health']['solid_number'] - $scanned) : null,
            'gap_blocks' => array_sum(array_map(fn ($gap) => max(0, $gap['to'] - $gap['next'] + 1), $status['gaps'])),
            'recent_blocks' => array_map(fn ($block) => json_decode($block, true), Redis::lRange(RedisKey::TempMgsTronBlocks->value, 0, 11)),
            'recent_recharges' => $recent,
            'server_time' => time(),
        ];
    }

    public function recharges(array $filters): array
    {
        $result = $this->getList(Recharge::query()->when($filters['keyword'] ?? '', fn ($q, $v) => $q->where('recharge_no', $v))
            ->when($filters['status'] ?? '', fn ($q, $v) => $q->where('status', $v))
            ->when($filters['currency_code'] ?? '', fn ($q, $v) => $q->where('currency_code', $v)));
        $rows = $result['data'] ?? $result;
        foreach ($rows as &$row) {
            $row['recharge_amount'] = format_amount((string) $row['recharge_amount']);
            $row['pay_amount'] = format_amount((string) $row['pay_amount']);
        }
        unset($row);
        if (isset($result['data'])) $result['data'] = $rows;
        return $result;
    }

    public function transfers(array $filters): array
    {
        $result = $this->getList(Transfer::query()->with('recharge:id,recharge_no')
            ->when($filters['keyword'] ?? '', fn ($q, $v) => $q->where('transaction_id', $v))
            ->when($filters['status'] ?? '', fn ($q, $v) => $q->where('status', $v))
            ->when($filters['currency_code'] ?? '', fn ($q, $v) => $q->where('currency_code', $v)));
        $rows = $result['data'] ?? $result;
        foreach ($rows as &$row) $row['amount'] = format_amount((string) $row['amount']);
        unset($row);
        if (isset($result['data'])) $result['data'] = $rows;
        return $result;
    }

    public function detail(string $type, string $id): array
    {
        $data = $type === 'recharge' ? Recharge::with('transfers')->findOrFail($id)->toArray() : Transfer::with('recharge')->findOrFail($id)->toArray();
        foreach (['recharge_amount', 'pay_amount', 'amount'] as $field) if (isset($data[$field])) $data[$field] = format_amount((string) $data[$field]);
        foreach ($data['transfers'] ?? [] as &$transfer) if (isset($transfer['amount'])) $transfer['amount'] = format_amount((string) $transfer['amount']);
        if (isset($data['recharge']['recharge_amount'])) $data['recharge']['recharge_amount'] = format_amount((string) $data['recharge']['recharge_amount']);
        if (isset($data['recharge']['pay_amount'])) $data['recharge']['pay_amount'] = format_amount((string) $data['recharge']['pay_amount']);
        return $data;
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
