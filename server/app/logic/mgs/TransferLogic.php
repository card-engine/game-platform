<?php

namespace app\logic\mgs;

use app\enum\RedisKey;
use app\model\Recharge;
use app\model\Transfer;
use app\model\mgs\User;
use app\model\mgs\Wallet;
use app\service\mgs\MgsTableService;
use app\service\mgs\TronScanService;
use DateTimeImmutable;
use DateTimeZone;
use plugin\saiadmin\basic\eloquent\BaseLogic;
use RuntimeException;
use support\Db;
use support\Redis;
use Webman\Event\Event;

class TransferLogic extends BaseLogic
{
    /** 自动确认与人工核验共用同一钱包事务；人工只解除时间/归属歧义，不允许改金额。 */
    public function credit(int $transferId, ?int $rechargeId = null, ?int $adminId = null, string $remark = ''): void
    {
        $transfer = Transfer::findOrFail($transferId);
        if ($transfer->status === 'credited') {
            if ($rechargeId !== null && (int) $transfer->recharge_id !== $rechargeId) throw new RuntimeException('收款已入账到另一订单');
            return;
        }
        if ($adminId === null && $transfer->status !== 'pending') return;
        if ($adminId !== null && ($remark === '' || $transfer->status !== 'review')) throw new RuntimeException('仅待核验收款可人工确认，必须填写依据');
        $matches = Recharge::withTrashed()->where(['pay_method' => 'trc20', 'pay_currency_code' => $transfer->currency_code,
            'receive_address' => $transfer->receive_address, 'pay_amount' => $transfer->amount])->where('create_time', '<=', $transfer->block_time);
        if ($rechargeId === null) {
            $candidates = $matches->limit(2)->get();
            if ($candidates->count() !== 1) {
                Transfer::whereKey($transferId)->where('status', 'pending')->update(['status' => 'review', 'remark' => '无精确订单或支付数量曾复用，需核实归属']);
                return;
            }
            $rechargeId = (int) $candidates[0]->id;
        }
        $recharge = Recharge::findOrFail($rechargeId);
        $lock = RedisKey::LockMgsUserWallet->format($recharge->user_id, $recharge->currency_code);
        $token = bin2hex(random_bytes(16));
        if (!Redis::set($lock, $token, 'EX', RedisKey::EXPIRE_1_MINUTE, 'NX')) throw new RuntimeException('用户钱包处理中');
        try {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $table = (new MgsTableService())->table('bills', $now->format('ym'));
            $paid = $this->transaction(function () use ($transferId, $recharge, $adminId, $remark, $now, $table) {
                $wallet = Wallet::where(['user_id' => $recharge->user_id, 'currency_code' => $recharge->currency_code])->lockForUpdate()->firstOrFail();
                $order = Recharge::whereKey($recharge->id)->lockForUpdate()->firstOrFail();
                $event = Transfer::whereKey($transferId)->lockForUpdate()->firstOrFail();
                if ($event->status === 'credited') {
                    if ((int) $event->recharge_id !== (int) $order->id) throw new RuntimeException('收款已入账到另一订单');
                    return;
                }
                if ($adminId === null && $event->status !== 'pending') return;
                if ($adminId !== null && $event->status !== 'review') throw new RuntimeException('收款状态已改变');
                $reason = null;
                if (!in_array($order->status, ['pending', 'expired', 'review'], true)) $reason = '充值订单已到账或关闭';
                elseif ((int) User::whereKey($order->user_id)->value('status') !== 1) $reason = '用户已停用';
                elseif ($order->pay_method !== 'trc20' || $order->pay_currency_code !== $event->currency_code
                    || $order->receive_address !== $event->receive_address || bccomp((string) $order->pay_amount, (string) $event->amount, 8) !== 0) $reason = '支付币种、地址或数量不匹配';
                elseif ($event->currency_code === 'USDT' && ($order->rate_snapshot['token_address'] ?? '') !== ($event->data['token_address'] ?? '')) $reason = 'USDT合约与订单快照不一致';
                elseif ($adminId === null && ($event->block_time < $order->getRawOriginal('create_time') || $event->block_time > $order->expire_time || $order->status === 'review')) $reason = '超时付款或订单需人工核验';
                if ($reason) {
                    if ($adminId !== null) throw new RuntimeException($reason);
                    $event->update(['status' => 'review', 'recharge_id' => $order->id, 'remark' => $reason]);
                    if (in_array($order->status, ['pending', 'expired'], true)) $order->update(['status' => 'review']);
                    return;
                }
                $time = $now->format('Y-m-d H:i:s.v');
                $before = (string) $wallet->balance;
                $after = bcadd($before, (string) $order->recharge_amount, 8);
                $wallet->update(['balance' => $after, 'version' => Db::raw('version + 1'), 'update_time' => $time]);
                Db::table($table)->insert([
                    'bill_no' => mg_no('ML', $now->format('ym')), 'user_id' => $order->user_id, 'type' => 'recharge', 'direction' => 1,
                    'transaction_id' => 'recharge:' . $order->id, 'amount' => $order->recharge_amount, 'currency_code' => $order->currency_code,
                    'before_balance' => $before, 'after_balance' => $after, 'status' => 2,
                    'request_hash' => hash('sha256', 'recharge:' . $order->id . '|transfer:' . $event->id),
                    'data' => json_encode(['recharge_id' => $order->id, 'recharge_no' => $order->recharge_no, 'transfer_id' => $event->id]),
                    'create_time' => $time, 'update_time' => $time,
                ]);
                $evidence = $event->data;
                if ($adminId !== null) $evidence['reviews'][] = ['admin_id' => $adminId, 'time' => $time, 'action' => 'credit', 'recharge_id' => $order->id, 'remark' => $remark];
                $order->update(['status' => 'paid', 'credited_time' => $time]);
                $event->update(['status' => 'credited', 'recharge_id' => $order->id, 'remark' => $adminId === null ? null : $remark, 'data' => $evidence]);
                return $order->only(['recharge_no', 'user_id', 'pay_amount', 'pay_currency_code', 'recharge_amount', 'currency_code', 'credited_time']);
            });
        } finally {
            Redis::eval(TronScanService::RELEASE, 1, $lock, $token);
        }
        // 提交并释放资金锁后发布业务事件；通知失败不回滚或重做入账。
        if ($paid) Event::emit('mgs.recharge.paid', $paid);
    }
}
