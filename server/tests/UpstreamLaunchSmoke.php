<?php

use app\model\Enterprise;
use app\model\Game;
use app\model\Merchant;
use app\model\MerchantBrand;
use app\model\MerchantCredit;
use app\service\game\OpenApiService;
use app\service\game\SecretService;
use support\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

$suffix = bin2hex(random_bytes(4));
$enterprise = Enterprise::create(['name' => "__mg_launch_{$suffix}", 'merchant_limit' => 1, 'timezone' => 'UTC', 'default_language' => 'en', 'status' => 1]);
$merchant = Merchant::create([
    'enterprise_id' => $enterprise->id, 'name' => "Launch {$suffix}", 'wallet_mode' => 1, 'callback_url' => 'http://127.0.0.1',
    'secret' => SecretService::encrypt($suffix), 'language_codes' => ['en'], 'default_language' => 'en', 'timezone' => 'UTC', 'timeout_ms' => 30000, 'status' => 1,
]);
$merchant->update(['mch_id' => (string) id2big((int) $merchant->id)]);
$games = Game::with('brand')->whereIn('id', Game::whereIn('platform_code', ['wxgame', 'tada', 'goldengatex'])->where('status', 1)->whereHas('brand', fn ($brand) => $brand->whereNotNull('unique_brand_id'))->groupBy('platform_code')->selectRaw('MIN(id)')->pluck('MIN(id)'))->get()->keyBy('platform_code');

try {
    foreach ($games as $game) MerchantBrand::create(['merchant_id' => $merchant->id, 'unique_brand_id' => $game->brand->unique_brand_id, 'status' => 1]);
    foreach ($games->map(fn ($game) => $game->currency_codes[0])->unique() as $currency) MerchantCredit::create(['merchant_id' => $merchant->id, 'currency_code' => $currency, 'rate_value' => 0, 'settlement_enabled' => 1, 'status' => 1]);
    $service = new OpenApiService();
    foreach (['wxgame', 'tada', 'goldengatex'] as $platform) {
        $game = $games->get($platform);
        if (!$game) throw new RuntimeException("{$platform} 没有已同步游戏");
        try {
            $result = $service->launch($merchant, [
                'user_id' => "launch_{$platform}_{$suffix}",
                'game_id' => (string) id2big((int) $game->id),
                'currency' => $game->currency_codes[0],
                'language' => 'en',
            ], '127.0.0.1');
            $url = (string) ($result['game_url'] ?? '');
            if (!filter_var($url, FILTER_VALIDATE_URL)) throw new RuntimeException('未返回有效进游地址');
            echo "{$platform} launch passed: " . parse_url($url, PHP_URL_HOST) . PHP_EOL;
        } catch (Throwable $e) {
            echo "{$platform} launch blocked: {$e->getMessage()}" . PHP_EOL;
        }
    }
    echo "acewin launch skipped: game list unavailable from current upstream IP" . PHP_EOL;
} finally {
    Db::table('mg_users')->where('merchant_id', $merchant->id)->delete();
    Db::table('mg_merchant_brands')->where('merchant_id', $merchant->id)->delete();
    Db::table('mg_merchant_credits')->where('merchant_id', $merchant->id)->delete();
    Db::table('mg_merchants')->where('id', $merchant->id)->delete();
    Db::table('mg_enterprises')->where('id', $enterprise->id)->delete();
}
