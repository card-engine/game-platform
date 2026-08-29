<?php

use app\logic\game\MerchantLogic;
use app\model\Enterprise;
use app\model\Game;
use app\model\Merchant;
use app\model\MerchantCredit;
use app\model\MerchantGame;
use app\service\game\SecretService;
use plugin\saiadmin\exception\ApiException;
use support\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

function checkMerchantMode(bool $result, string $message): void
{
    if (!$result) throw new RuntimeException($message);
}

$suffix = bin2hex(random_bytes(4));
$game = Game::with('brand')->where('upstream_status', 1)->where('platform_status', 1)->whereHas('brand', fn ($brand) => $brand->whereNotNull('unique_brand_id'))->firstOrFail();
$enterprise = Enterprise::create(['name' => "__mg_mode_{$suffix}", 'merchant_limit' => 4, 'timezone' => 'UTC', 'default_language' => 'en', 'status' => 1]);
$source = Merchant::create([
    'enterprise_id' => $enterprise->id, 'name' => "Source {$suffix}", 'wallet_mode' => 1, 'callback_url' => 'http://127.0.0.1',
    'secret' => SecretService::encrypt($suffix), 'language_codes' => ['en'], 'default_language' => 'en',
    'gc_exchange_rate' => '10000', 'timezone' => 'UTC', 'timeout_ms' => 1000, 'status' => 1,
]);
$source->update(['mch_id' => (string) id2big((int) $source->id)]);
MerchantGame::create(['merchant_id' => $source->id, 'game_id' => $game->id, 'status' => 0, 'rate_value' => '0.02']);
$logic = new MerchantLogic();
$logic->init(['id' => 1]);
$targetId = null;
$singleId = null;

try {
    $targetId = $logic->add([
        'enterprise_id' => $enterprise->id, 'name' => "Dual {$suffix}", 'wallet_mode' => 1, 'callback_url' => 'http://127.0.0.1',
        'secret' => '', 'ip_whitelist' => [], 'language_codes' => ['en'], 'default_language' => 'en',
        'gc_exchange_rate' => '10000', 'timezone' => 'UTC', 'timeout_ms' => 1000, 'status' => 1, 'remark' => '',
        'copy_from_merchant_id' => $source->id,
        'credits' => [
            ['currency_code' => 'SC', 'rate_value' => '0.1', 'available_amount' => '10', 'status' => 1],
            ['currency_code' => 'GC', 'rate_value' => '0.1', 'available_amount' => '0', 'status' => 1],
        ],
    ]);
    $credits = MerchantCredit::where('merchant_id', $targetId)->pluck('settlement_enabled', 'currency_code')->map(fn ($value) => (int) $value)->all();
    $target = Merchant::find($targetId);
    checkMerchantMode($credits === ['GC' => 0, 'SC' => 1] || $credits === ['SC' => 1, 'GC' => 0], 'SC/GC 结算开关错误: ' . json_encode($credits) . ', merchant mode=' . $target?->billing_mode);
    checkMerchantMode(MerchantGame::where(['merchant_id' => $targetId, 'game_id' => $game->id, 'status' => 0])->exists(), '游戏例外未复制');

    $singleId = $logic->add([
        'enterprise_id' => $enterprise->id, 'name' => "Single {$suffix}", 'wallet_mode' => 1, 'callback_url' => 'http://127.0.0.1',
        'secret' => '', 'language_codes' => ['en'], 'default_language' => 'en',
        'timezone' => 'UTC', 'timeout_ms' => 1000, 'status' => 1,
        'credits' => [['currency_code' => 'USD', 'rate_value' => 0, 'available_amount' => 0, 'status' => 1]],
    ]);
    checkMerchantMode(MerchantCredit::where(['merchant_id' => $singleId, 'currency_code' => 'USD', 'status' => 1])->exists(), '普通模式未保存单币种');

    $scId = $logic->add([
        'enterprise_id' => $enterprise->id, 'name' => "SC {$suffix}", 'wallet_mode' => 1, 'callback_url' => 'http://127.0.0.1',
        'secret' => '', 'language_codes' => ['en'], 'default_language' => 'en', 'timezone' => 'UTC', 'timeout_ms' => 1000, 'status' => 1,
        'credits' => [['currency_code' => 'SC', 'rate_value' => 0, 'available_amount' => 0, 'status' => 1]],
    ]);
    checkMerchantMode(MerchantCredit::where(['merchant_id' => $scId, 'currency_code' => 'SC', 'status' => 1])->exists(), 'SC 未能单独启用');

    try {
        $logic->credits($scId, [['currency_code' => 'GC', 'rate_value' => 0, 'status' => 1]]);
        throw new RuntimeException('GC 仍能脱离 SC 单独启用');
    } catch (ApiException $e) {
        checkMerchantMode(str_contains($e->getMessage(), '同时启用 SC'), 'GC 依赖 SC 的错误提示不明确');
    }
    echo "Merchant mode smoke test passed\n";
} finally {
    $ids = array_filter([$source->id, $targetId, $singleId, $scId ?? null]);
    Db::table('mg_merchant_games')->whereIn('merchant_id', $ids)->delete();
    Db::table('mg_merchant_credits')->whereIn('merchant_id', $ids)->delete();
    Db::table('mg_merchants')->whereIn('id', $ids)->delete();
    Db::table('mg_enterprises')->where('id', $enterprise->id)->delete();
}
