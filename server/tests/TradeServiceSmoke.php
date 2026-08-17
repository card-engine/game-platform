<?php

use app\model\Enterprise;
use app\model\Game;
use app\model\Merchant;
use app\model\MerchantBrand;
use app\model\MerchantCredit;
use app\model\User;
use app\service\game\SecretService;
use app\service\game\report\DailyStatService;
use app\queue\redis\GameBetClose;
use app\service\game\trade\MonthlyTableService;
use app\service\game\trade\TradeService;
use support\Db;
use Webman\RedisQueue\Redis as QueueRedis;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';
require __DIR__ . '/fixtures/mock_wallet_server.php';

function check(bool $result, string $message): void
{
    if (!$result) throw new RuntimeException($message);
}

$suffix = bin2hex(random_bytes(4));
$wallet = startMockWallet();
$secret = $wallet['secret'];
$game = Game::with('brand')->where('platform_code', 'wxgame')->where('status', 1)->firstOrFail();
$enterprise = Enterprise::create(['name' => "__mg_smoke_{$suffix}", 'merchant_limit' => 1, 'timezone' => 'UTC', 'default_language' => 'en', 'status' => 1]);
$merchant = Merchant::create([
    'enterprise_id' => $enterprise->id, 'name' => "Smoke {$suffix}", 'wallet_mode' => 1, 'callback_url' => "http://127.0.0.1:{$wallet['port']}/app",
    'secret' => SecretService::encrypt($secret), 'language_codes' => ['en'], 'default_language' => 'en', 'timezone' => 'UTC', 'timeout_ms' => 1000, 'status' => 1,
]);
$merchant->update(['mch_id' => (string) id2big($merchant->id)]);
$credit = MerchantCredit::create(['merchant_id' => $merchant->id, 'currency_code' => 'USD', 'rate_value' => '0.1', 'available_amount' => '100', 'status' => 1]);
MerchantBrand::create(['merchant_id' => $merchant->id, 'unique_brand_id' => $game->brand->unique_brand_id, 'status' => 1]);
$user = User::create(['merchant_id' => $merchant->id, 'merchant_user_id' => "player_{$suffix}", 'status' => 1]);
$player = 'mg_' . id2big($user->id) . '_usd';
$trade = new TradeService();
$base = ['player_id' => $player, 'game_code' => $game->provider_game_code, 'brand_code' => $game->brand->provider_brand_code];

try {
    $debit = $base + ['action' => 'debit', 'source_no' => "debit_{$suffix}", 'round_id' => "round_{$suffix}", 'amount' => '10', 'finished' => false];
    check($trade->handle('wxgame', $debit)['status'] === 2, '扣款失败');
    $billTable = 'mg_bills_' . gmdate('ym');
    check(Db::table($billTable)->where('merchant_id', $merchant->id)->count() === 1, '扣款 Bill 未写入');
    check($trade->handle('wxgame', $debit)['status'] === 2, '重复扣款未返回原结果');
    check(Db::table($billTable)->where('merchant_id', $merchant->id)->count() === 1, '重复扣款生成了新 Bill');

    check($trade->handle('wxgame', $base + ['action' => 'credit', 'source_no' => "credit_1_{$suffix}", 'round_id' => "round_{$suffix}", 'amount' => '2', 'finished' => false])['status'] === 2, '第一次派奖失败');
    check($trade->handle('wxgame', $base + ['action' => 'credit', 'source_no' => "credit_2_{$suffix}", 'round_id' => "round_{$suffix}", 'amount' => '4', 'finished' => true])['status'] === 2, '最终派奖失败');
    $betTable = 'mg_bets_' . gmdate('ym');
    $bet = (array) Db::table($betTable)->where('merchant_id', $merchant->id)->where('provider_round_id', "round_{$suffix}")->first();
    check($bet['ggr_amount'] === '4.00000000' && $bet['merchant_fee'] === '0.40000000', 'GGR 或费用错误');

    $rollback = $trade->handle('wxgame', $base + ['action' => 'rollback_debit', 'source_no' => "rollback_{$suffix}", 'original_source_no' => "debit_{$suffix}", 'round_id' => "round_{$suffix}", 'amount' => '2', 'finished' => true]);
    check($rollback['status'] === 2, '退款失败');
    $bet = (array) Db::table($betTable)->where('id', $bet['id'])->first();
    $credit->refresh();
    check($bet['ggr_amount'] === '2.00000000' && $bet['bet_rollback_amount'] === '2.00000000', '部分退款汇总错误');

    $secondRollback = $trade->handle('wxgame', $base + ['action' => 'rollback_debit', 'source_no' => "rollback_2_{$suffix}", 'original_source_no' => "debit_{$suffix}", 'round_id' => "round_{$suffix}", 'amount' => '4', 'finished' => true]);
    check($secondRollback['status'] === 2, '第二次部分退款失败');
    $bet = (array) Db::table($betTable)->where('id', $bet['id'])->first();
    $credit->refresh();
    check($bet['ggr_amount'] === '-2.00000000' && $bet['merchant_fee'] === '0.40000000', '负 GGR 被退费或归零');
    check($credit->payable_amount === '0.40000000', '负 GGR 产生了退费');

    $available = $credit->available_amount;
    $credit->update(['available_amount' => '0.05000000']);
    check($trade->handle('wxgame', $base + ['action' => 'debit', 'source_no' => "late_limit_{$suffix}", 'round_id' => "round_{$suffix}", 'amount' => '7', 'finished' => true])['status'] === 3, '结算后补单未校验额度');
    $credit->update(['available_amount' => $available]);

    check($trade->handle('wxgame', $base + ['action' => 'debit', 'source_no' => "late_{$suffix}", 'round_id' => "round_{$suffix}", 'amount' => '15', 'finished' => true])['status'] === 2, '结算后补扣失败');
    $bet = (array) Db::table($betTable)->where('id', $bet['id'])->first();
    $credit->refresh();
    check($bet['merchant_fee'] === '1.30000000' && $credit->payable_amount === '1.30000000' && $credit->reserved_amount === '0.00000000', '正差额补收错误');

    $before = $credit->available_amount;
    check($trade->handle('wxgame', $base + ['action' => 'debit', 'source_no' => "fail_{$suffix}", 'round_id' => "failed_round_{$suffix}", 'amount' => '5'])['status'] === 3, '确定失败状态错误');
    $credit->refresh();
    check($credit->available_amount === $before && $credit->reserved_amount === '0.00000000', '失败交易未释放额度');

    $unknown = $base + ['action' => 'debit', 'source_no' => "unknown_{$suffix}", 'round_id' => "unknown_round_{$suffix}", 'amount' => '2', 'finished' => true];
    check($trade->handle('wxgame', $unknown)['status'] === 4, '超时未进入结果未知');
    check($trade->handle('wxgame', $unknown)['status'] === 2, '结果未知未使用原 Bill 重试成功');
    check(Db::table($billTable)->where('merchant_id', $merchant->id)->where('source_no', "unknown_{$suffix}")->count() === 1, '结果未知重试生成了新 Bill');

    $autoRound = "auto_round_{$suffix}";
    check($trade->handle('wxgame', $base + ['action' => 'debit', 'source_no' => "auto_debit_{$suffix}", 'round_id' => $autoRound, 'amount' => '3'])['status'] === 2, '自动结单下注失败');
    $autoBet = (array) Db::table($betTable)->where('merchant_id', $merchant->id)->where('provider_round_id', $autoRound)->first();
    check($trade->handle('wxgame', $base + ['action' => 'credit', 'source_no' => "auto_credit_{$suffix}", 'round_id' => $autoRound, 'amount' => '1'])['status'] === 2, '自动结单中间派奖失败');
    $queued = array_filter(QueueRedis::connection()->zRange('{redis-queue}-delayed', 0, -1), fn ($item) => str_contains($item, $autoBet['bet_no']));
    check(count($queued) === 1, '同一注单重复投递了延迟任务');
    (new GameBetClose())->consume(['bet_no' => $autoBet['bet_no'], 'month' => gmdate('ym')]);
    check((int) Db::table($betTable)->where('id', $autoBet['id'])->value('status') === 1, '600 秒内错误结单');
    Db::table($betTable)->where('id', $autoBet['id'])->update(['update_time' => date('Y-m-d H:i:s.v', time() - 601)]);
    (new GameBetClose())->consume(['bet_no' => $autoBet['bet_no'], 'month' => gmdate('ym')]);
    $autoBet = (array) Db::table($betTable)->where('id', $autoBet['id'])->first();
    check((int) $autoBet['status'] === 2 && $autoBet['win_amount'] === '1.00000000' && (int) $autoBet['credit_count'] === 2, '零派奖自动结单失败');
    (new GameBetClose())->consume(['bet_no' => $autoBet['bet_no'], 'month' => gmdate('ym')]);
    check(Db::table($billTable)->where('source_no', 'auto_close_' . $autoBet['bet_no'])->count() === 1, '自动结单重复通知');
    $walletState = json_decode(file_get_contents($wallet['state']), true);
    $autoEvent = current(array_filter($walletState['events'], fn ($event) => ($event['request']['transaction_id'] ?? '') === 'auto_close_' . $autoBet['bet_no']));
    check(($autoEvent['request']['win_amount'] ?? '') === '0.00000000' && (int) ($autoEvent['request']['is_end'] ?? 0) === 1, '自动结单通知参数错误');

    Db::statement('DROP TABLE IF EXISTS `mg_bets_9912`');
    check((new MonthlyTableService())->table('bets', '9912') === 'mg_bets_9912', '缺表请求未继续完成');
    check(Db::connection()->getSchemaBuilder()->hasTable('mg_bets_9912'), '缺失月表未动态创建');
    (new DailyStatService())->rebuild($bet['business_date']);
    check(Db::table('mg_daily_stats')->where('merchant_id', $merchant->id)->exists(), '每日统计未生成');

    echo "TradeService smoke test passed\n";
} finally {
    Db::statement('DROP TABLE IF EXISTS `mg_bets_9912`');
    $businessNos = [];
    foreach (Db::table('information_schema.tables')->selectRaw('TABLE_NAME as name')->where('table_schema', config('database.connections.mysql.database'))->where('table_name', 'like', 'mg_b%_' . gmdate('ym'))->pluck('name') as $table) {
        $number = str_starts_with($table, 'mg_bets_') ? 'bet_no' : 'bill_no';
        array_push($businessNos, ...Db::table($table)->where('merchant_id', $merchant->id)->pluck($number)->all());
        Db::table($table)->where('merchant_id', $merchant->id)->delete();
    }
    Db::table('mg_daily_stats')->where('merchant_id', $merchant->id)->delete();
    Db::table('mg_merchant_bills')->where('credit_id', $credit->id)->delete();
    Db::table('mg_merchant_monthly_usages')->where('credit_id', $credit->id)->delete();
    Db::table('mg_merchant_games')->where('merchant_id', $merchant->id)->delete();
    Db::table('mg_merchant_brands')->where('merchant_id', $merchant->id)->delete();
    Db::table('mg_users')->where('merchant_id', $merchant->id)->delete();
    Db::table('mg_merchant_credits')->where('merchant_id', $merchant->id)->delete();
    Db::table('mg_merchants')->where('id', $merchant->id)->delete();
    Db::table('mg_enterprises')->where('id', $enterprise->id)->delete();
    stopMockWallet($wallet);
}
