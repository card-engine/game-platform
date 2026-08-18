<?php

use app\command\DbUpgradeCommand;
use app\model\mgs\Game;
use app\model\mgs\User;
use app\model\mgs\Wallet;
use app\service\mgs\MgsCallbackService;
use app\service\mgs\MgsConfigService;
use app\service\mgs\MgsStatsService;
use app\service\mgs\MgsSettlementService;
use Symfony\Component\Console\Tester\CommandTester;
use Webman\Config;
use Webman\Database\Initializer;
use support\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

function switchMgsSmokeDatabase(string $database): void
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
$database = '__mgs_smoke_' . getmypid();
$pdo = new PDO("mysql:host={$original['host']};port={$original['port']};dbname={$original['database']};charset=utf8mb4", $original['username'], $original['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

try {
    $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
    $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    putenv('INITIAL_ADMIN_PASSWORD=Mgs-Smoke-Admin-123!');
    putenv('INITIAL_GAME_ADMIN_PASSWORD=Mgs-Smoke-Game-123!');
    $_ENV['INITIAL_ADMIN_PASSWORD'] = $_SERVER['INITIAL_ADMIN_PASSWORD'] = 'Mgs-Smoke-Admin-123!';
    $_ENV['INITIAL_GAME_ADMIN_PASSWORD'] = $_SERVER['INITIAL_GAME_ADMIN_PASSWORD'] = 'Mgs-Smoke-Game-123!';
    switchMgsSmokeDatabase($database);
    $upgrade = new CommandTester(new DbUpgradeCommand());
    if ($upgrade->execute([]) !== 0) throw new RuntimeException($upgrade->getDisplay());
    (new MgsConfigService())->rebuild();

    $game = Game::create([
        'platform_game_id' => 'smoke-game', 'platform_game_code' => 'smoke-game', 'platform_brand_code' => 'smoke',
        'brand_id' => 1, 'name' => 'Smoke Game', 'currency_codes' => ['USD'], 'status' => 1, 'rate_value' => '0.0300000000',
    ]);
    $user = User::findOrFail(1);
    $wallet = Wallet::create(['user_id' => $user->id, 'currency_code' => 'USD', 'balance' => '100.00000000']);
    $service = new MgsCallbackService();
    $base = ['user_id' => $user->user_no, 'currency' => 'USD', 'game_id' => 'smoke-game', 'parent_round_id' => 'round-1', 'round_id' => 'round-1'];
    $service->handle('bet', $base + ['transaction_id' => 'tx-bet-1', 'bet_amount' => '10']);
    $service->handle('bet', $base + ['transaction_id' => 'tx-bet-1', 'bet_amount' => '10']);
    try {
        $service->handle('bet', $base + ['transaction_id' => 'tx-bet-1', 'bet_amount' => '11']);
        throw new RuntimeException('重复交易参数不一致未拒绝');
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== '重复交易参数不一致') throw $e;
    }
    $service->handle('win', $base + ['transaction_id' => 'tx-win-1', 'win_amount' => '4', 'is_end' => 1]);
    $wallet->refresh();
    if ((string) $wallet->balance !== '94.00000000') throw new RuntimeException('下注派奖余额不正确');
    (new MgsStatsService())->rebuildDate((new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d'));
    $stat = Db::table('mgs_daily_stats')->where(['game_id' => $game->id, 'currency_code' => 'USD'])->first();
    if (!$stat || (string) $stat->ggr_amount !== '6.00000000' || (string) $stat->rtp_value !== '0.4000000000') throw new RuntimeException('日报 GGR 或 RTP 统计不正确');
    $month = gmdate('Y-m');
    (new MgsSettlementService())->generate($month);
    $settlement = Db::table('mgs_settlements')->where(['settlement_month' => $month, 'currency_code' => 'USD'])->first();
    if (!$settlement || (string) $settlement->platform_fee !== '0.18000000' || (string) $settlement->mgs_net_amount !== '5.82000000') throw new RuntimeException('部门结算金额不正确');
    $service->handle('cancel', $base + ['transaction_id' => 'tx-cancel-bet', 'original_transaction_id' => 'tx-bet-1']);
    $service->handle('cancel', $base + ['transaction_id' => 'tx-cancel-win', 'original_transaction_id' => 'tx-win-1']);
    $wallet->refresh();
    if ((string) $wallet->balance !== '100.00000000') throw new RuntimeException('取消回滚后余额不正确');
    $user2 = User::create(['user_no' => 'system-2', 'language' => 'en', 'status' => 1]);
    $wallet2 = Wallet::create(['user_id' => $user2->id, 'currency_code' => 'USD', 'balance' => '100.00000000']);
    $service->handle('bet', ['user_id' => $user2->user_no, 'currency' => 'USD', 'game_id' => 'smoke-game', 'parent_round_id' => 'round-2', 'round_id' => 'round-2', 'transaction_id' => 'tx-bet-1', 'bet_amount' => '2']);
    if ((string) $wallet2->fresh()->balance !== '98.00000000') throw new RuntimeException('不同用户相同交易号被错误判为重复');
    echo "MGS smoke test passed\n";
} finally {
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
