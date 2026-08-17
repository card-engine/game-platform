<?php

use app\logic\game\IndexLogic;
use app\model\Game;
use app\model\GameBrand;
use app\model\Merchant;
use app\model\User;
use app\service\game\report\DailyStatService;
use app\service\game\trade\TradeService;
use support\Db;
use Webman\Config;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';
require __DIR__ . '/fixtures/mock_wallet_server.php';

function checkSelf(bool $result, string $message): void
{
    if (!$result) throw new RuntimeException($message);
}

$suffix = bin2hex(random_bytes(4));
$wallet = startMockWallet();
$property = new ReflectionProperty(Config::class, 'config');
$cache = new ReflectionProperty(Config::class, 'flatCache');
$originalConfig = $property->getValue();
$config = $originalConfig;
$config['game_platforms']['self_merchant'] = [
    'callback_url' => "http://127.0.0.1:{$wallet['port']}/app", 'secret' => $wallet['secret'],
    'currencies' => ['USD'], 'user_id' => "demo_{$suffix}", 'language' => 'en',
    'timezone' => 'UTC', 'timeout_ms' => 1000, 'back_url' => '',
];
$config['game_platforms']['platforms']['wxgame']['accounts']['sc'] = [
    'url' => "http://127.0.0.1:{$wallet['port']}", 'app_id' => 'test', 'app_key' => 'test', 'app_secret' => 'test',
];
$property->setValue(null, $config);
$cache->setValue(null, []);
$brand = GameBrand::create([
    'platform_code' => 'wxgame', 'provider_brand_code' => "SELF_{$suffix}", 'mapping_status' => 0,
    'name' => "Self {$suffix}", 'status' => 1,
]);
$game = Game::create([
    'brand_id' => $brand->id, 'platform_code' => 'wxgame', 'provider_game_code' => "self_{$suffix}",
    'name' => "Self {$suffix}", 'currency_codes' => ['USD'], 'status' => 1,
]);

try {
    $merchant = Merchant::system();
    checkSelf((int) $merchant->id === 0 && !$merchant->exists && !Merchant::whereKey(0)->exists(), '虚拟商户被持久化或参数错误');
    $trial = (new IndexLogic())->trial((int) $game->id, 'USD', '127.0.0.1');
    checkSelf($trial['game_url'] === 'https://example.test/demo', '未归并游戏试玩失败');
    $user = User::where(['merchant_id' => 0, 'merchant_user_id' => "demo_{$suffix}"])->firstOrFail();
    $playerId = 'mg_' . id2big((int) $user->id) . '_usd';
    checkSelf((new TradeService())->balance($playerId)['status'] === 2, '试玩余额回调失败');
    $result = (new TradeService())->handle('wxgame', [
        'action' => 'debit', 'player_id' => $playerId, 'game_code' => $game->provider_game_code,
        'brand_code' => $brand->provider_brand_code, 'source_no' => "self_{$suffix}",
        'round_id' => "self_{$suffix}", 'amount' => '5', 'finished' => true,
    ]);
    checkSelf($result['status'] === 2, '试玩下注回调失败');
    $betTable = 'mg_bets_' . gmdate('ym');
    $bet = (array) Db::table($betTable)->where(['merchant_id' => 0, 'user_id' => $user->id])->first();
    checkSelf((int) $bet['settlement_enabled'] === 0 && $bet['merchant_fee'] === '0.00000000' && (int) $bet['status'] === 2, '试玩注单进入了额度或计费');
    (new DailyStatService())->rebuild($bet['business_date']);
    checkSelf(!Db::table('mg_daily_stats')->where(['merchant_id' => 0, 'game_id' => $game->id])->exists(), '试玩注单进入了日报');

    $system = require dirname(__DIR__) . '/database/system.php';
    checkSelf(in_array('app:game:list:trial', $system['roles']['game_super_admin']['menus'], true), '游戏超管缺少试玩权限');
    checkSelf(!in_array('app:game:list:trial', $system['roles']['enterprise_owner']['menus'], true)
        && !in_array('app:game:list:trial', $system['roles']['enterprise_staff']['menus'], true), '企业角色获得了试玩权限');

    echo "Self merchant smoke test passed\n";
} finally {
    foreach (['mg_bets_' . gmdate('ym') => 'user_id', 'mg_bills_' . gmdate('ym') => 'user_id'] as $table => $field) {
        Db::table($table)->where($field, $user->id ?? 0)->delete();
    }
    User::where(['merchant_id' => 0, 'merchant_user_id' => "demo_{$suffix}"])->forceDelete();
    $game->forceDelete();
    $brand->forceDelete();
    $property->setValue(null, $originalConfig);
    $cache->setValue(null, []);
    stopMockWallet($wallet);
}
