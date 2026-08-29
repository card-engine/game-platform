<?php

use app\model\Enterprise;
use app\model\Game;
use app\model\Merchant;
use app\model\MerchantCredit;
use app\model\User;
use app\service\game\SecretService;
use app\service\game\trade\TradeService;
use support\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';
require __DIR__ . '/fixtures/mock_wallet_server.php';

function checkGc(bool $result, string $message): void
{
    if (!$result) throw new RuntimeException($message);
}

$suffix = bin2hex(random_bytes(4));
$wallet = startMockWallet(1);
$secret = $wallet['secret'];
$game = Game::with('brand')->where('platform_code', 'tada')->where('upstream_status', 1)->where('platform_status', 1)->get()->first(fn ($game) => in_array('GC', $game->currency_codes, true));
if (!$game || !$game->brand->unique_brand_id) throw new RuntimeException('没有可测试的 GC 游戏');

$enterprise = Enterprise::create(['name' => "__mg_gc_{$suffix}", 'merchant_limit' => 1, 'timezone' => 'UTC', 'default_language' => 'en', 'status' => 1]);
$merchant = Merchant::create([
    'enterprise_id' => $enterprise->id, 'name' => "GC {$suffix}", 'wallet_mode' => 1, 'callback_url' => "http://127.0.0.1:{$wallet['port']}/app",
    'secret' => SecretService::encrypt($secret), 'language_codes' => ['en'], 'default_language' => 'en',
    'gc_exchange_rate' => '10000', 'timezone' => 'UTC', 'timeout_ms' => 1000, 'status' => 1,
]);
$merchant->update(['mch_id' => (string) id2big((int) $merchant->id)]);
$sc = MerchantCredit::create(['merchant_id' => $merchant->id, 'currency_code' => 'SC', 'rate_value' => '0.1', 'settlement_enabled' => 1, 'available_amount' => '100', 'status' => 1]);
$gc = MerchantCredit::create(['merchant_id' => $merchant->id, 'currency_code' => 'GC', 'rate_value' => '0.1', 'settlement_enabled' => 0, 'available_amount' => '100', 'status' => 1]);
$user = User::create(['merchant_id' => $merchant->id, 'merchant_user_id' => "gc_{$suffix}", 'status' => 1]);
$player = 'mg_' . id2big((int) $user->id) . '_gc';
$base = ['player_id' => $player, 'game_code' => $game->provider_game_code, 'brand_code' => $game->brand->provider_brand_code, 'round_id' => "gc_round_{$suffix}"];

try {
    $trade = new TradeService();
    checkGc($trade->handle('tada', $base + ['action' => 'debit', 'source_no' => "gc_debit_{$suffix}", 'amount' => '10'])['status'] === 2, 'GC 扣款失败');
    checkGc($trade->handle('tada', $base + ['action' => 'credit', 'source_no' => "gc_credit_{$suffix}", 'amount' => '6', 'finished' => true])['status'] === 2, 'GC 派奖失败');

    $betTable = 'mg_bets_' . gmdate('ym');
    $bet = (array) Db::table($betTable)->where('merchant_id', $merchant->id)->first();
    $gc->refresh();
    checkGc($bet['ggr_amount'] === '4.00000000', 'GC 原始 GGR 未记录');
    checkGc($bet['billable_ggr_amount'] === '0.00000000' && $bet['merchant_fee'] === '0.00000000', 'GC 产生了结算费用');
    checkGc($gc->available_amount === '100.00000000' && $gc->reserved_amount === '0.00000000' && $gc->payable_amount === '0.00000000', 'GC 改变了商户费用额度');
    checkGc(!Db::table('mg_merchant_bills')->where('credit_id', $gc->id)->exists(), 'GC 生成了商户费用流水');
    echo "GC settlement smoke test passed\n";
} finally {
    $businessNos = [];
    foreach (['mg_bets_' . gmdate('ym') => 'bet_no', 'mg_bills_' . gmdate('ym') => 'bill_no'] as $table => $column) {
        array_push($businessNos, ...Db::table($table)->where('merchant_id', $merchant->id)->pluck($column)->all());
        Db::table($table)->where('merchant_id', $merchant->id)->delete();
    }
    Db::table('mg_merchant_bills')->whereIn('credit_id', [$sc->id, $gc->id])->delete();
    Db::table('mg_merchant_monthly_usages')->whereIn('credit_id', [$sc->id, $gc->id])->delete();
    Db::table('mg_users')->where('merchant_id', $merchant->id)->delete();
    Db::table('mg_merchant_credits')->where('merchant_id', $merchant->id)->delete();
    Db::table('mg_merchants')->where('id', $merchant->id)->delete();
    Db::table('mg_enterprises')->where('id', $enterprise->id)->delete();
    stopMockWallet($wallet);
}
