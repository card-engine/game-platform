<?php

namespace app\service\mgs;

use app\enum\RedisKey;
use app\model\Recharge;
use app\model\Transfer;
use support\Redis;
use Webman\RedisQueue\Redis as Queue;

class RechargeMaintenance
{
    public function run(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        Recharge::where('status', 'pending')->where('expire_time', '<=', $now)->update(['status' => 'expired']);
        Recharge::where('is_reserved', 1)->whereIn('status', ['paid', 'expired', 'closed'])
            ->whereRaw('GREATEST(expire_time, COALESCE(credited_time, expire_time)) <= ?', [gmdate('Y-m-d H:i:s', time() - 86400)])
            ->update(['is_reserved' => null]);
        // 历史地址仅补充，不自动删除；扫描进程本身不需要查询订单表。
        foreach (Recharge::withTrashed()->where('pay_method', 'trc20')->whereNotNull('receive_address')->distinct()->pluck('receive_address') as $address) {
            Redis::sAdd(RedisKey::ForeverMgsTronAddresses->value, TronClient::address($address));
        }
        foreach (Redis::hGetAll(RedisKey::ForeverMgsTronGaps->value) as $id => $value) {
            $gap = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            if ($gap['retry_time'] <= time()) Queue::send('mgs_tron_backfill', ['gap_id' => $id]);
        }
        // 以更新时间轮转，某个坏事件不会一直占着前100个名额。
        foreach (Transfer::where('status', 'pending')->orderBy('update_time')->orderBy('id')->limit(100)->pluck('id') as $id) {
            Queue::send('mgs_recharge_credit', ['transfer_id' => $id]);
            Transfer::whereKey($id)->where('status', 'pending')->update(['update_time' => $now]);
        }
    }
}
