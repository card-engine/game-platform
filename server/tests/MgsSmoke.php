<?php

use app\command\DbUpgradeCommand;
use app\logic\mgs\PlayerLogic;
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

putenv('MGS_GAME_PLATFORM_SECRET=Mgs-Smoke-Secret');
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
    (new \app\service\mgs\MgsTableService())->recent();

    $game = Game::create([
        'platform_game_id' => 'smoke-game', 'platform_game_code' => 'smoke-game', 'platform_brand_code' => 'smoke',
        'brand_id' => 1, 'name' => 'Smoke Game', 'currency_codes' => ['USD'], 'status' => 1, 'upstream_status' => 1, 'platform_status' => 1, 'merchant_status' => 1, 'rate_value' => '0.0300000000',
    ]);
    $user = User::findOrFail(1);
    $wallet = Wallet::where(['user_id' => $user->id, 'currency_code' => 'USD'])->firstOrFail();
    $wallet->update(['balance' => '100.00000000']);
    $service = new MgsCallbackService();
    $base = ['user_id' => (string) $user->unique_id, 'currency' => 'USD', 'game_id' => 'smoke-game', 'parent_round_id' => 'round-1', 'round_id' => 'round-1'];
    $service->handle('bet', $base + ['transaction_id' => 'tx-bet-1', 'bet_amount' => '10']);
    $service->handle('bet', $base + ['transaction_id' => 'tx-bet-1', 'bet_amount' => '10']);
    try {
        $service->handle('bet', $base + ['transaction_id' => 'tx-bet-1', 'bet_amount' => '11']);
        throw new RuntimeException('重复交易参数不一致未拒绝');
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== '重复交易参数不一致') throw $e;
    }
    $service->handle('win', $base + ['transaction_id' => 'tx-win-1', 'win_amount' => '4', 'is_end' => 1]);
    $service->handle('win', $base + ['transaction_id' => 'tx-win-1', 'win_amount' => '4', 'is_end' => 1]);
    $wallet->refresh();
    if ((string) $wallet->balance !== '94.00000000') throw new RuntimeException('下注派奖余额不正确');
    (new MgsStatsService())->rebuildDate((new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d'));
    $stat = Db::table('mgs_daily_stats')->where(['game_id' => $game->id, 'currency_code' => 'USD'])->first();
    if (!$stat || (string) $stat->ggr_amount !== '6.00000000' || (string) $stat->rtp_value !== '0.4000000000') throw new RuntimeException('日报 GGR 或 RTP 统计不正确');
    $service->handle('cancel', $base + ['transaction_id' => 'tx-cancel-bet-1', 'original_transaction_id' => 'tx-bet-1', 'original_type' => 'bet', 'cancel_amount' => '4']);
    $service->handle('cancel', $base + ['transaction_id' => 'tx-cancel-bet-1', 'original_transaction_id' => 'tx-bet-1', 'original_type' => 'bet', 'cancel_amount' => '4']);
    $bet = (array) Db::table('mgs_bets_' . gmdate('ym'))->first();
    if ((int) $bet['status'] !== 2 || !$bet['settled_time'] || (string) $wallet->fresh()->balance !== '98.00000000') throw new RuntimeException('部分取消破坏了结单状态');
    try {
        $service->handle('cancel', $base + ['transaction_id' => 'tx-cancel-bet-too-much', 'original_transaction_id' => 'tx-bet-1', 'original_type' => 'bet', 'cancel_amount' => '7']);
        throw new RuntimeException('超额取消未拒绝');
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== '取消金额超过原交易剩余金额') throw $e;
    }
    $service->handle('cancel', $base + ['transaction_id' => 'tx-cancel-bet-2', 'original_transaction_id' => 'tx-bet-1', 'original_type' => 'bet', 'cancel_amount' => '6']);
    $service->handle('cancel', $base + ['transaction_id' => 'tx-cancel-win', 'original_transaction_id' => 'tx-win-1', 'original_type' => 'win']);
    $wallet->refresh();
    if ((string) $wallet->balance !== '100.00000000') throw new RuntimeException('取消回滚后余额不正确');
    $cancelled = (array) Db::table('mgs_bets_' . gmdate('ym'))->first();
    if ((int) $cancelled['status'] !== 3 || $cancelled['settled_time'] !== $bet['settled_time']) throw new RuntimeException('完全取消状态或结单时间错误');
    try {
        $service->handle('cancel', $base + ['transaction_id' => 'tx-cancel-bet-3', 'original_transaction_id' => 'tx-bet-1', 'original_type' => 'bet']);
        throw new RuntimeException('换交易号重复取消未拒绝');
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== '原交易已全部取消') throw $e;
    }
    $service->handle('win', $base + ['transaction_id' => 'tx-win-late', 'win_amount' => '1', 'is_end' => 0]);
    $late = (array) Db::table('mgs_bets_' . gmdate('ym'))->first();
    if ((int) $late['status'] !== 2 || $late['settled_time'] !== $bet['settled_time']) throw new RuntimeException('结单后补发派奖重新打开了注单');
    $service->handle('cancel', $base + ['transaction_id' => 'tx-cancel-win-late', 'original_transaction_id' => 'tx-win-late', 'original_type' => 'win']);
    if ((string) $wallet->fresh()->balance !== '100.00000000') throw new RuntimeException('补发派奖回滚余额不正确');
    $user2 = User::create(['language' => 'en', 'status' => 1]);
    $user2->update(['unique_id' => id2big((int) $user2->id)]);
    $wallet2 = Wallet::create(['user_id' => $user2->id, 'currency_code' => 'USD', 'balance' => '100.00000000']);
    $service->handle('bet', ['user_id' => (string) $user2->unique_id, 'currency' => 'USD', 'game_id' => 'smoke-game', 'transaction_id' => 'tx-bet-1', 'bet_amount' => '2']);
    $service->handle('bet', ['user_id' => (string) $user2->unique_id, 'currency' => 'USD', 'game_id' => 'smoke-game', 'transaction_id' => 'tx-bet-2', 'bet_amount' => '3']);
    if ((string) $wallet2->fresh()->balance !== '95.00000000' || Db::table('mgs_bets_' . gmdate('ym'))->where('user_id', $user2->id)->count() !== 2) throw new RuntimeException('无局号交易被丢弃或错误合并');
    $player = new PlayerLogic();
    if (($player->games($user2, 'USD', 0)['recent'][0]['mgs_game_id'] ?? null) !== $game->id) throw new RuntimeException('玩家最近游戏推荐错误');
    if ($player->update($user2, ['nickname' => 'Smoke Player'])['nickname'] !== 'Smoke Player') throw new RuntimeException('玩家资料更新错误');
    $user2->update(['status' => 0]);
    $service->handle('bet', ['user_id' => (string) $user2->unique_id, 'currency' => 'USD', 'game_id' => 'smoke-game', 'transaction_id' => 'disabled-current-game-bet', 'bet_amount' => '1', 'round_id' => 'disabled-round']);
    $version = $wallet2->fresh()->version;
    $zero = ['user_id' => (string) $user2->unique_id, 'currency' => 'USD', 'game_id' => 'smoke-game', 'transaction_id' => 'disabled-current-game-close', 'win_amount' => '0', 'is_end' => 1, 'round_id' => 'disabled-round'];
    $first = $service->handle('win', $zero);
    $repeat = $service->handle('win', $zero);
    if ($first !== $repeat) throw new RuntimeException('零派奖重试返回不同结果');
    if ($wallet2->fresh()->version !== $version || $wallet2->fresh()->balance !== '94.00000000') throw new RuntimeException('零派奖改动钱包');
    if (Db::table('mgs_bills_' . gmdate('ym'))->where('amount', 0)->exists()) throw new RuntimeException('生成零金额资金流水');
    if (Db::table('mgs_trade_events_' . gmdate('ym'))->where('transaction_id', $zero['transaction_id'])->count() !== 1) throw new RuntimeException('零结单事件不幂等');
    if ((int) Db::table('mgs_bets_' . gmdate('ym'))->where('bet_no', $first['bet_no'])->value('status') !== 2) throw new RuntimeException('停用用户原游戏没有结单');
    $free = array_replace($zero, ['transaction_id' => 'free-bet', 'bet_amount' => '0', 'round_id' => 'free-round']);
    $service->handle('bet', $free);
    $close = $service->handle('win', array_replace($free, ['transaction_id' => 'free-close']));
    if ((int) Db::table('mgs_bets_' . gmdate('ym'))->where('bet_no', $close['bet_no'])->value('status') !== 2) throw new RuntimeException('免费零金额局误判为撤销');
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
