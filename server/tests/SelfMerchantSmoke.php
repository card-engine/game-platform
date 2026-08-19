<?php

use app\command\DbUpgradeCommand;
use app\logic\game\IndexLogic;
use app\model\Game;
use app\model\GameBrand;
use app\model\Merchant;
use app\model\MerchantGame;
use app\model\UniqueBrand;
use app\model\User;
use app\model\mgs\User as MgsUser;
use app\model\mgs\Wallet;
use app\service\game\SecretService;
use app\service\game\report\DailyStatService;
use app\service\game\trade\TradeService;
use app\service\mgs\MgsConfigService;
use support\Db;
use Symfony\Component\Console\Tester\CommandTester;
use Webman\Config;
use Webman\Database\Initializer;

putenv('MGS_GAME_PLATFORM_SECRET=Self-Merchant-Smoke-Secret');
putenv('MGS_DEFAULT_CURRENCY=USD');
putenv('MGS_SYSTEM_BALANCE=1000000');
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';
require __DIR__ . '/fixtures/mock_wallet_server.php';

function checkSelf(bool $result, string $message): void
{
    if (!$result) throw new RuntimeException($message);
}

function switchSelfDatabase(string $database): void
{
    putenv("DB_NAME={$database}");
    $_ENV['DB_NAME'] = $_SERVER['DB_NAME'] = $database;
    Config::clear();
    support\App::loadAllConfig(['route']);
    $property = new ReflectionProperty(Initializer::class, 'initialized');
    $property->setValue(null, false);
    Initializer::init(config('database', []));
}

$original = config('database.connections.mysql');
$database = '__self_merchant_smoke_' . getmypid();
$pdo = new PDO(
    "mysql:host={$original['host']};port={$original['port']};dbname={$original['database']};charset=utf8mb4",
    $original['username'],
    $original['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$walletServer = null;

try {
    $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
    $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    putenv('INITIAL_ADMIN_PASSWORD=Self-Smoke-Admin-123!');
    putenv('INITIAL_GAME_ADMIN_PASSWORD=Self-Smoke-Game-123!');
    switchSelfDatabase($database);
    $upgrade = new CommandTester(new DbUpgradeCommand());
    checkSelf($upgrade->execute([]) === 0, $upgrade->getDisplay());
    (new MgsConfigService())->rebuild();

    $mgsUser = MgsUser::findOrFail(1);
    $merchant = Merchant::findOrFail(1);
    $user = User::findOrFail(1);
    checkSelf((int) $user->merchant_id === (int) $merchant->id && $user->merchant_user_id === $mgsUser->user_no, '双端系统用户未对应');
    checkSelf(Wallet::where(['user_id' => $mgsUser->id, 'currency_code' => 'USD'])->exists(), 'MGS 系统钱包未初始化');

    $walletServer = startMockWallet();
    $merchant->update([
        'callback_url' => "http://127.0.0.1:{$walletServer['port']}/app",
        'secret' => SecretService::encrypt($walletServer['secret']),
    ]);
    $property = new ReflectionProperty(Config::class, 'config');
    $cache = new ReflectionProperty(Config::class, 'flatCache');
    $config = $property->getValue();
    $config['game_platforms']['platforms']['wxgame']['accounts']['sc'] = [
        'url' => "http://127.0.0.1:{$walletServer['port']}", 'app_id' => 'test', 'app_key' => 'test', 'app_secret' => 'test',
    ];
    $property->setValue(null, $config);
    $cache->setValue(null, []);

    $brand = UniqueBrand::create(['code' => 'self_smoke', 'name' => 'Self Smoke', 'status' => 1]);
    $providerBrand = GameBrand::create([
        'platform_code' => 'wxgame', 'provider_brand_code' => 'SELF_SMOKE', 'unique_brand_id' => $brand->id,
        'mapping_status' => 2, 'name' => 'Self Smoke', 'status' => 1,
    ]);
    $game = Game::create([
        'game_code' => 'self_smoke_game', 'brand_id' => $providerBrand->id, 'platform_code' => 'wxgame',
        'provider_game_code' => 'self_smoke_game', 'name' => 'Self Smoke Game', 'currency_codes' => ['USD'], 'status' => 1,
    ]);
    MerchantGame::create(['merchant_id' => $merchant->id, 'game_id' => $game->id, 'status' => 1, 'merchant_status' => 1]);

    $trial = (new IndexLogic())->trial((int) $game->id, 'USD', '127.0.0.1');
    checkSelf($trial['game_url'] === 'https://example.test/demo', '真实自营商户试玩失败');
    $result = (new TradeService())->handle('wxgame', [
        'action' => 'debit', 'player_id' => 'mg_' . id2big((int) $user->id) . '_usd',
        'game_code' => $game->provider_game_code, 'brand_code' => $providerBrand->provider_brand_code,
        'source_no' => 'self_smoke_bet', 'round_id' => 'self_smoke_round', 'amount' => '5', 'finished' => true,
    ]);
    checkSelf($result['status'] === 2, '真实自营商户下注失败');
    $bet = (array) Db::table('mg_bets_' . substr($result['bet_no'], 2, 4))->where('bet_no', $result['bet_no'])->first();
    checkSelf((int) $bet['merchant_id'] === 1 && (int) $bet['settlement_enabled'] === 1
        && $bet['merchant_fee'] === '0.15000000' && (int) $bet['status'] === 2, '自营交易未按普通商户计费');
    (new DailyStatService())->rebuild($bet['business_date']);
    checkSelf(Db::table('mg_daily_stats')->where(['merchant_id' => 1, 'game_id' => $game->id])->exists(), '自营交易未进入游戏平台统计');

    echo "Self merchant smoke test passed\n";
} finally {
    if ($walletServer) stopMockWallet($walletServer);
    Db::statement("USE `{$original['database']}`");
    $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
    putenv('DB_NAME=' . $original['database']);
    $_ENV['DB_NAME'] = $_SERVER['DB_NAME'] = $original['database'];
    Config::clear();
    support\App::loadAllConfig(['route']);
    $property = new ReflectionProperty(Initializer::class, 'initialized');
    $property->setValue(null, false);
    Initializer::init(config('database', []));
    (new MgsConfigService())->rebuild();
}
