<?php

use app\model\Enterprise;
use app\model\Game;
use app\model\Merchant;
use app\model\User;
use app\service\game\SecretService;
use app\service\game\trade\MonthlyTableService;
use app\service\game\trade\TradeService;
use support\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

$suffix = bin2hex(random_bytes(4));
$month = (new DateTimeImmutable('first day of last month', new DateTimeZone('UTC')))->format('ym');
$table = (new MonthlyTableService())->table('bills', $month);
$enterprise = Enterprise::create(['name' => "Cross Month {$suffix}", 'merchant_limit' => 1, 'timezone' => 'UTC', 'default_language' => 'en', 'status' => 1]);
$merchant = Merchant::create([
    'enterprise_id' => $enterprise->id, 'name' => "Cross Month {$suffix}", 'wallet_mode' => 1, 'callback_url' => 'http://127.0.0.1:1',
    'secret' => SecretService::encrypt($suffix), 'language_codes' => ['en'], 'default_language' => 'en', 'timezone' => 'UTC', 'timeout_ms' => 1000, 'status' => 1,
]);
$merchant->update(['mch_id' => (string) id2big((int) $merchant->id)]);
$user = User::create(['merchant_id' => $merchant->id, 'merchant_user_id' => "cross_{$suffix}", 'status' => 1]);
$player = 'mg_' . id2big((int) $user->id) . '_usd';
$operation = ['action' => 'debit', 'player_id' => $player, 'source_no' => "cross_{$suffix}", 'round_id' => "round_{$suffix}", 'amount' => '3.00000000'];
$key = hash('sha256', "debit|{$user->id}|USD|{$operation['source_no']}");
$requestHash = hash('sha256', json_encode($operation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$billNo = 'BL' . $month . '01000000' . $suffix;

try {
    Db::table($table)->insert([
        'bill_no' => $billNo, 'merchant_id' => $merchant->id, 'user_id' => $user->id, 'game_id' => Game::value('id'),
        'type' => 1, 'source' => 'wxgame', 'source_no' => $operation['source_no'], 'amount' => $operation['amount'], 'currency_code' => 'USD',
        'idempotency_key' => $key, 'request_hash' => $requestHash, 'status' => 2,
        'data' => json_encode(['wallet_response' => ['code' => 0, 'message' => 'success', 'data' => ['balance' => '97.00000000']]]),
        'business_date' => gmdate('Y-m-d'), 'platform_date' => gmdate('Y-m-d'), 'received_time' => gmdate('Y-m-d H:i:s'),
        'completed_time' => gmdate('Y-m-d H:i:s'), 'create_time' => gmdate('Y-m-d H:i:s'), 'update_time' => gmdate('Y-m-d H:i:s'),
    ]);
    $result = (new TradeService())->handle('wxgame', $operation);
    if (($result['status'] ?? 0) !== 2 || ($result['bill_no'] ?? '') !== $billNo) throw new RuntimeException('未复用上月幂等结果');
    if (Db::table('mg_bills_' . gmdate('ym'))->where(['source' => 'wxgame', 'idempotency_key' => $key])->exists()) throw new RuntimeException('跨月重试生成了新流水');
    echo "Cross-month idempotency smoke test passed\n";
} finally {
    Db::table($table)->where('bill_no', $billNo)->delete();
    Db::table('mg_users')->where('id', $user->id)->delete();
    Db::table('mg_merchants')->where('id', $merchant->id)->delete();
    Db::table('mg_enterprises')->where('id', $enterprise->id)->delete();
}
