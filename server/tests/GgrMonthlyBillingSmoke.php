<?php

use app\logic\game\MerchantLogic;
use app\model\Enterprise;
use app\model\Merchant;
use app\model\MerchantCredit;
use app\model\MerchantMonthlyBill;
use app\service\game\report\MonthlyBillingService;
use app\service\game\SecretService;
use app\service\game\trade\MonthlyTableService;
use support\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

function checkGgr(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException($message);
}

$month = new DateTimeImmutable('first day of last month 00:00:00', new DateTimeZone('UTC'));
// 注单创建月不等于结单月，必须能读取更早的分表。
$table = (new MonthlyTableService())->table('bets', $month->modify('-4 months')->format('ym'));
$period = $month->format('Y-m');
$now = gmdate('Y-m-d H:i:s');
Db::beginTransaction();
try {
    $suffix = bin2hex(random_bytes(4));
    $enterprise = Enterprise::create(['name' => "GGR {$suffix}", 'merchant_limit' => 1, 'timezone' => 'UTC', 'default_language' => 'en', 'status' => 1]);
    $merchant = Merchant::create(['enterprise_id' => $enterprise->id, 'name' => "GGR {$suffix}", 'secret' => SecretService::encrypt($suffix), 'language_codes' => ['en'], 'timezone' => 'UTC', 'billing_mode' => 1, 'status' => 0]);
    $credits = [];
    foreach (['USD', 'INR', 'EUR', 'GC'] as $currency) $credits[$currency] = MerchantCredit::create(['merchant_id' => $merchant->id, 'currency_code' => $currency, 'rate_value' => '0.99', 'settlement_enabled' => $currency !== 'GC', 'available_amount' => '1000', 'status' => 1]);
    $ids = [];
    foreach ([['USD', '100', '0.1'], ['USD', '-80', '0.1'], ['INR', '100', '0.1'], ['INR', '-130', '0.1'], ['EUR', '100', '0.2'], ['EUR', '-80', '0.1'], ['GC', '10000', '0.1']] as $i => [$currency, $ggr, $rate]) {
        $ids[] = Db::table($table)->insertGetId([
            'bet_no' => "GGR{$suffix}{$i}", 'merchant_id' => $merchant->id, 'user_id' => 1, 'platform_code' => 'test', 'brand_id' => 1, 'game_id' => 1,
            'currency_code' => $currency, 'round_key' => hash('sha256', "{$suffix}{$i}"), 'provider_round_id' => "{$suffix}{$i}",
            'ggr_amount' => $ggr, 'merchant_rate_value' => $rate, 'billing_mode' => 1, 'settlement_enabled' => $currency !== 'GC', 'status' => 2,
            'business_date' => $month->modify('-4 months')->format('Y-m-d'), 'platform_date' => $month->modify('-4 months')->format('Y-m-d'),
            'settled_time' => $month->format('Y-m-d 12:00:00'), 'create_time' => $month->modify('-4 months')->format('Y-m-d H:i:s'), 'update_time' => $now,
        ]);
    }
    Db::table($table)->where('id', $ids[0])->update(['merchant_fee' => '0.4']);
    $credits['USD']->update(['available_amount' => '999.6', 'payable_amount' => '0.4']);
    $service = new MonthlyBillingService();
    checkGgr($service->generateGgr($merchant, gmdate('Y-m')) === 0, '未结束月份被提前出账');
    checkGgr($service->generateGgr($merchant, $period) === 3, '多币种出账或 GC 排除错误');
    $bills = MerchantMonthlyBill::where('merchant_id', $merchant->id)->get()->keyBy('currency_code');
    checkGgr($bills['USD']->amount === '2.00000000' && $bills['USD']->ggr_amount === '20.00000000', '月内输赢未相抵');
    checkGgr($bills['INR']->amount === '0.00000000' && $bills['INR']->ggr_amount === '-30.00000000', '负 GGR 月被收费');
    checkGgr($bills['EUR']->amount === '12.00000000', '独立费率快照被当前费率覆盖');
    checkGgr($credits['USD']->fresh()->payable_amount === '2.00000000' && $credits['USD']->fresh()->available_amount === '998.00000000', '旧费用重复扣除或额度不平');
    checkGgr(Db::table('mg_merchant_monthly_usages')->where('credit_id', $credits['USD']->id)->value('billed_amount') === '2.00000000', '月度用量未同步');
    $ledgerCount = Db::table('mg_merchant_bills')->whereIn('credit_id', array_map(fn ($credit) => $credit->id, $credits))->count();
    checkGgr($service->generateGgr($merchant, $period) === 0, '重复生成账单');
    checkGgr(Db::table('mg_merchant_bills')->whereIn('credit_id', array_map(fn ($credit) => $credit->id, $credits))->count() === $ledgerCount, '重复出账重复记账');

    Db::table($table)->where('id', $ids[1])->update(['ggr_amount' => '-120']);
    $service->generateGgr($merchant, $period);
    checkGgr($bills['USD']->fresh()->amount === '0.00000000' && $credits['USD']->fresh()->payable_amount === '0.00000000' && $credits['USD']->fresh()->available_amount === '1000.00000000', '未支付账单迟到派奖未冲减费用');

    // 账单更新后模拟流水写入失败，三个表必须共同回滚。
    $fail = true;
    Db::listen(function ($query) use (&$fail) {
        if ($fail && str_starts_with($query->sql, 'insert into `mg_merchant_bills`')) throw new RuntimeException('模拟流水故障');
    });
    Db::table($table)->where('id', $ids[1])->update(['ggr_amount' => '-80']);
    try {
        $service->generateGgr($merchant, $period);
        throw new RuntimeException('未触发故障');
    } catch (RuntimeException $error) {
        checkGgr($error->getMessage() === '模拟流水故障', $error->getMessage());
    } finally {
        $fail = false;
    }
    checkGgr($bills['USD']->fresh()->amount === '0.00000000' && $credits['USD']->fresh()->payable_amount === '0.00000000', '故障留下部分账务结果');
    $service->generateGgr($merchant, $period);
    $logic = new MerchantLogic();
    $logic->init(['id' => 1]);
    $logic->billStatus((int) $bills['USD']->id, 1, 'test');
    $logic->billStatus((int) $bills['USD']->id, 1, 'test');
    checkGgr($credits['USD']->fresh()->payable_amount === '0.00000000', '支付未核销应付或重复核销');
    Db::table($table)->where('id', $ids[1])->update(['ggr_amount' => '-90']);
    try {
        $service->generateGgr($merchant, $period);
        throw new RuntimeException('已支付账单被静默改写');
    } catch (RuntimeException $error) {
        checkGgr(str_contains($error->getMessage(), '迟到交易需对账调整'), $error->getMessage());
    }
    echo "GGR monthly billing smoke passed: netting, rates, currencies, closed month, old shards, legacy offset, retry, adjustment, rollback, payment\n";
} finally {
    Db::rollBack();
}
