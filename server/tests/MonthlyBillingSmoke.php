<?php

use app\model\Enterprise;
use app\model\Merchant;
use app\model\MerchantCredit;
use app\model\MerchantMonthlyBill;
use app\logic\game\MerchantLogic;
use app\service\game\SecretService;
use app\service\game\report\MonthlyBillingService;
use app\service\game\trade\MonthlyTableService;
use support\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

function checkMonthlyBilling(bool $result, string $message): void
{
    if (!$result) throw new RuntimeException($message);
}

$month = new DateTimeImmutable('first day of this month', new DateTimeZone('UTC'));
$table = (new MonthlyTableService())->table('bets', $month->format('ym'));
$now = date('Y-m-d H:i:s.v');
Db::beginTransaction();

try {
    $suffix = bin2hex(random_bytes(4));
    $enterprise = Enterprise::create(['name' => "Billing {$suffix}", 'merchant_limit' => 1, 'timezone' => 'UTC', 'default_language' => 'en', 'status' => 1]);
    $merchant = Merchant::create([
        'enterprise_id' => $enterprise->id, 'name' => "Billing {$suffix}", 'wallet_mode' => 1,
        'callback_url' => 'http://127.0.0.1', 'secret' => SecretService::encrypt($suffix),
        'language_codes' => ['en'], 'default_language' => 'en', 'timezone' => 'UTC', 'timeout_ms' => 1000,
        'billing_mode' => 2, 'monthly_metric' => 1, 'monthly_min_fee' => '1000',
        'monthly_tiers' => [['min' => '0', 'fee' => '1000'], ['min' => '10000', 'fee' => '1500']], 'status' => 1,
    ]);
    $merchant->update(['mch_id' => (string) id2big((int) $merchant->id)]);
    $credit = MerchantCredit::create([
        'merchant_id' => $merchant->id, 'currency_code' => 'USD',
        'rate_value' => '0.1', 'settlement_enabled' => 0, 'available_amount' => '1000', 'status' => 1,
    ]);

    foreach ([['8000', 101], ['2000', 101], ['1000', 102]] as $index => [$amount, $userId]) {
        Db::table($table)->insert([
            'bet_no' => "TEST{$suffix}{$index}", 'merchant_id' => $merchant->id, 'user_id' => $userId,
            'platform_code' => 'test', 'brand_id' => 1, 'game_id' => 1, 'currency_code' => 'USD',
            'round_key' => hash('sha256', "ROUND{$suffix}{$index}"), 'provider_round_id' => "ROUND{$suffix}{$index}",
            'bet_amount' => $amount, 'business_date' => $month->format('Y-m-d'), 'platform_date' => $month->format('Y-m-d'),
            'create_time' => $now, 'update_time' => $now,
        ]);
    }

    $service = new MonthlyBillingService();
    $preview = $service->preview($merchant, $month->format('Y-m-d'));
    checkMonthlyBilling((string) $preview['stats']->converted_bet_amount === '11000.00000000', '月投注额统计错误');
    checkMonthlyBilling((int) $preview['stats']->bet_count === 3, '月注单量统计错误');
    checkMonthlyBilling((int) $preview['stats']->active_user_count === 2, '月活跃玩家统计错误');
    checkMonthlyBilling((string) $preview['next_fee'] === '1500', '次月阶梯预估错误');

    $service->generate($month->format('Y-m-d'));
    $first = MerchantMonthlyBill::where(['merchant_id' => $merchant->id, 'billing_month' => $month->format('Y-m-d')])->firstOrFail();
    checkMonthlyBilling((string) $first->amount === '1000.00000000', '首月未使用最低月费');

    $nextMonth = $month->modify('+1 month')->format('Y-m-d');
    $service->generate($nextMonth);
    $second = MerchantMonthlyBill::where(['merchant_id' => $merchant->id, 'billing_month' => $nextMonth])->firstOrFail();
    checkMonthlyBilling((string) $second->amount === '1500.00000000', '次月未按上月阶梯出账');
    checkMonthlyBilling((int) $second->metric_type === 1, '月活跃玩家被错误用作计费指标');

    $logic = new MerchantLogic();
    $logic->init(['id' => 1]);
    $logic->billing((int) $merchant->id, ['billing_mode' => 1]);
    checkMonthlyBilling((int) $credit->fresh()->settlement_enabled === 1, '切换正 GGR 后币种未参与结算');
    $logic->billing((int) $merchant->id, [
        'billing_mode' => 2, 'monthly_metric' => 2, 'monthly_min_fee' => '750',
        'monthly_tiers' => [['min' => '0', 'fee' => '750'], ['min' => '1,000', 'fee' => '900']],
    ]);
    checkMonthlyBilling((int) $credit->fresh()->settlement_enabled === 0, '切换月费后仍占用服务费额度');
    $merchant->refresh();
    checkMonthlyBilling((string) $merchant->monthly_min_fee === '750.00000000', '最低月费仍被写死为 1000');
    checkMonthlyBilling((string) $merchant->monthly_tiers[1]['min'] === '1000', '千分位阶梯值未正确保存');

    echo "Monthly billing smoke test passed\n";
} finally {
    Db::rollBack();
}
