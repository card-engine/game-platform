<?php

/** 隔离MySQL/Redis，使用模拟固化节点；无真实转账，不访问生产资金。 */
use app\enum\RedisKey;
use app\logic\mgs\TransferLogic;
use app\model\Recharge;
use app\model\Transfer;
use app\model\mgs\User;
use app\model\mgs\Wallet;
use app\service\mgs\TronClient;
use app\service\mgs\TronBlockService;
use app\service\mgs\TronScanService;
use app\service\mgs\RechargeMaintenance;
use support\Db;
use support\Redis;
use Webman\Config;
use Webman\Database\Initializer;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

function ensure(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function fails(callable $action, string $expected): void {
    try { $action(); } catch (Throwable $error) { ensure(str_contains($error->getMessage(), $expected), $error->getMessage()); return; }
    throw new RuntimeException('未拒绝：' . $expected);
}

class SolidNode extends TronClient
{
    public int $head = 4;
    public array $blocks = [];
    public array $receipts = [];
    public ?int $missing = null;
    public bool $loseLock = false;
    public function __construct() {}
    public function request(string $method, array $params = []): array {
        $n = $params['num'] ?? $this->head;
        if ($method === 'gettransactioninfobyblocknum') {
            if ($this->loseLock) Redis::del(RedisKey::LockMgsTronScan->value);
            return $this->missing === $n ? [] : ($this->receipts[$n] ?? []);
        }
        return $this->blocks[$n];
    }
}

$port = (int) getenv('MGS_TEST_MYSQL_PORT');
$redisPort = (int) getenv('MGS_TEST_REDIS_PORT');
ensure($port > 0 && $redisPort > 0, '必须明确指定隔离测试端口');
$database = '__mgs_tron_test_' . getmypid();
$prefix = $database . ':';
$pdo = new PDO("mysql:host=127.0.0.1;port={$port};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
$settings = Config::get();
$settings['database']['connections']['mysql'] = array_replace($settings['database']['connections']['mysql'], ['host' => '127.0.0.1', 'port' => $port, 'username' => 'root', 'password' => '', 'database' => $database]);
$settings['redis']['default'] = array_replace($settings['redis']['default'], ['host' => '127.0.0.1', 'port' => $redisPort, 'password' => '', 'prefix' => $prefix]);
$settings['redis_queue'] = ['default' => ['host' => "redis://127.0.0.1:{$redisPort}", 'options' => ['db' => 0, 'prefix' => $prefix]]];
$settings['mgs']['recharge_tron_receive_address'] = TronClient::address('41' . str_repeat('11', 20));
(new ReflectionProperty(Config::class, 'config'))->setValue(null, $settings);
(new ReflectionProperty(Config::class, 'flatCache'))->setValue(null, []);
Webman\Context::destroy();
(new ReflectionProperty(Webman\Database\DatabaseManager::class, 'pools'))->setValue(null, []);
(new ReflectionProperty(Initializer::class, 'initialized'))->setValue(null, false);
Initializer::init(config('database'));

try {
    $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    foreach (['mgs_users', 'mgs_wallets', 'mgs_recharges', 'mgs_transfers', 'mgs_bills_template'] as $table) {
        preg_match('/CREATE TABLE `' . $table . '` \(.*?;(?=\n)/s', $schema, $match);
        Db::statement($match[0]);
    }
    $address = config('mgs.recharge_tron_receive_address');
    $contract = config('mgs.recharge_tron_usdt_contract');
    ensure(TronClient::address('41a614f803b6fd780986a42c78ec9c7f77e6ded13c') === $contract, '主网USDT地址转换错误');
    ensure(TronClient::address($contract) === $contract, 'Base58Check校验错误');
    fails(fn () => TronClient::address(substr($contract, 0, -1) . 'a'), '校验失败');
    $node = new SolidNode();
    $time = time() - 12;
    for ($n = 0; $n <= 8; $n++) {
        $hash = sprintf('%016x', $n) . substr(hash('sha256', (string) $n), 0, 48);
        $parent = $n ? $node->blocks[$n - 1]['blockID'] : str_repeat('0', 64);
        $node->blocks[$n] = ['blockID' => $hash, 'block_header' => ['raw_data' => ['number' => $n, 'timestamp' => ($time + $n) * 1000, 'parentHash' => $parent]], 'transactions' => []];
    }
    $native = ['type' => 'TransferContract', 'parameter' => ['value' => ['owner_address' => '41' . str_repeat('22', 20), 'to_address' => '41' . str_repeat('11', 20), 'amount' => 1234500]]];
    foreach ([1, 4, 5] as $height) {
        $tx = hash('sha256', 'trx' . $height);
        $node->blocks[$height]['transactions'] = [['txID' => $tx, 'ret' => [['contractRet' => 'SUCCESS']], 'raw_data' => ['contract' => [$native]]]];
        $node->receipts[$height] = [['id' => $tx, 'blockNumber' => $height, 'blockTimeStamp' => ($time + $height) * 1000, 'receipt' => []]];
    }
    $tx = hash('sha256', 'usdt');
    $node->blocks[2]['transactions'] = [['txID' => $tx, 'ret' => [['contractRet' => 'SUCCESS']], 'raw_data' => ['contract' => [['type' => 'TriggerSmartContract', 'parameter' => ['value' => ['contract_address' => '41' . str_repeat('33', 20)]]]]]]];
    $log = ['address' => 'a614f803b6fd780986a42c78ec9c7f77e6ded13c', 'topics' => ['ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef', str_repeat('0', 24) . str_repeat('22', 20), str_repeat('0', 24) . str_repeat('11', 20)], 'data' => str_pad(dechex(2345600), 64, '0', STR_PAD_LEFT)];
    $node->receipts[2] = [['id' => $tx, 'blockNumber' => 2, 'blockTimeStamp' => ($time + 2) * 1000, 'receipt' => ['result' => 'SUCCESS'], 'log' => [['topics' => ['approve']], $log, array_replace($log, ['data' => str_pad(dechex(3456700), 64, '0', STR_PAD_LEFT)])]]];
    $processor = new TronBlockService();
    $events = $processor->events($node->blocks[2], $node->receipts[2], [$address]);
    ensure(count($events) === 2 && $events[0]['event_index'] === 1 && $events[1]['amount'] === '3.456700', '合约代付、多事件或原始下标丢失');
    $bad = $node->receipts[2]; $bad[0]['log'][1]['address'] = str_repeat('33', 20); $bad[0]['log'][2]['address'] = str_repeat('33', 20);
    ensure(!$processor->events($node->blocks[2], $bad, [$address]), '假USDT被接受');
    $bad = $node->receipts[2]; $bad[0]['receipt']['result'] = 'REVERT';
    ensure(!$processor->events($node->blocks[2], $bad, [$address]), '失败交易被接受');
    fails(fn () => $processor->events($node->blocks[2], [], [$address]), '不完整');
    $scan = new TronScanService($node);
    Redis::set(RedisKey::ForeverMgsTronCheckpoint->value, json_encode(['start_block_number' => 1, 'next_block_number' => 1,
        'last_block_hash' => $node->blocks[0]['blockID'], 'last_success_time' => 0, 'recovery_required' => true]));
    $scan->start();
    $state = $scan->status();
    ensure($state['checkpoint']['next_block_number'] === 4 && count($state['gaps']) === 1, '重启没有先登记缺口再追最新');
    $gapId = array_key_first($state['gaps']);
    $scan->batch();
    ensure(Transfer::count() === 1 && $scan->status()['ready'] && $scan->status()['gaps'], '实时扫描健康时，旧恢复标记或后台补扫阻断充值');
    $scan->batch($gapId);
    ensure(Transfer::count() === 4 && !$scan->status()['gaps'] && $scan->status()['ready'], '队列补扫未完成或主断点被覆盖');
    $scan->batch($gapId);
    ensure(Transfer::count() === 4, '迟到补扫任务重复落库');

    $node->head = 5; $node->missing = 5;
    fails(fn () => $scan->batch(), '不完整');
    ensure($scan->status()['checkpoint']['next_block_number'] === 5, '缺回执仍推进');
    $node->missing = null; $node->loseLock = true;
    fails(fn () => $scan->batch(), '进度已被');
    ensure(Transfer::count() === 5 && $scan->status()['checkpoint']['next_block_number'] === 5, 'Redis失败顺序错误');
    $node->loseLock = false;
    $scan->batch();
    ensure(Transfer::count() === 5 && $scan->status()['checkpoint']['next_block_number'] === 6, '重放产生重复收款');

    $user = User::create(['status' => 1]);
    $wallet = Wallet::create(['user_id' => $user->id, 'currency_code' => 'INR', 'balance' => '10']);
    $base = ['user_id' => $user->id, 'request_id' => '550e8400-e29b-41d4-a716-446655440001', 'recharge_no' => mg_no('MR'),
        'currency_code' => 'INR', 'recharge_amount' => '100', 'pay_currency_code' => 'USDT', 'pay_method' => 'trc20', 'pay_amount' => '2.3456',
        'receive_address' => $address, 'is_reserved' => 1, 'rate_snapshot' => ['token_address' => $contract], 'status' => 'pending',
        'create_time' => gmdate('Y-m-d H:i:s', $time - 60), 'expire_time' => gmdate('Y-m-d H:i:s', time() + 600)];
    $recharge = Recharge::create($base);
    $transfer = Transfer::where('currency_code', 'USDT')->where('event_index', 1)->firstOrFail();
    $credit = new TransferLogic();
    $credit->credit($transfer->id);
    $credit->credit($transfer->id);
    ensure($wallet->fresh()->balance === '110.00000000' && $recharge->fresh()->status === 'paid', '入账金额或幂等错误');
    ensure(Db::table('mgs_bills_' . gmdate('ym'))->count() === 1, '重复流水');
    $processor->store($events);
    ensure($transfer->fresh()->status === 'credited', '重扫覆盖已入账状态');

    $late = Transfer::where('currency_code', 'USDT')->where('event_index', 2)->firstOrFail();
    $lateOrder = Recharge::create(array_replace($base, ['recharge_no' => mg_no('MR'), 'request_id' => '550e8400-e29b-41d4-a716-446655440002', 'pay_amount' => '3.4567', 'expire_time' => gmdate('Y-m-d H:i:s', $time - 1)]));
    $credit->credit($late->id);
    ensure($late->fresh()->status === 'review' && $wallet->fresh()->balance === '110.00000000', '迟付误加余额');
    $credit->credit($late->id, $lateOrder->id, 1, '测试核验凭证');
    ensure($wallet->fresh()->balance === '210.00000000' && count($late->fresh()->data['reviews']) === 1, '人工入账或留痕错误');

    $trx = Transfer::where('currency_code', 'TRX')->firstOrFail();
    $trxOrder = Recharge::create(array_replace($base, ['recharge_no' => mg_no('MR'), 'request_id' => '550e8400-e29b-41d4-a716-446655440003', 'pay_currency_code' => 'TRX', 'pay_amount' => '1.2345']));
    $oldOrder = Recharge::create(array_replace($base, ['recharge_no' => mg_no('MR'), 'request_id' => '550e8400-e29b-41d4-a716-446655440004', 'pay_currency_code' => 'TRX', 'pay_amount' => '1.2345', 'is_reserved' => null, 'status' => 'expired']));
    $credit->credit($trx->id);
    ensure($trx->fresh()->status === 'review' && $wallet->fresh()->balance === '210.00000000', '复用数量被错误自动认领');

    $pending = Transfer::where('status', 'pending')->firstOrFail();
    $wrong = Recharge::create(array_replace($base, ['recharge_no' => mg_no('MR'), 'request_id' => '550e8400-e29b-41d4-a716-446655440005', 'pay_amount' => '9.9999']));
    $pending->update(['status' => 'review']);
    fails(fn () => $credit->credit($pending->id, $wrong->id, 1, '测试错误金额'), '不匹配');
    ensure($wallet->fresh()->balance === '210.00000000', '人工接口可任意加款');

    $gapId = $scan->backfill(1, 2);
    Webman\RedisQueue\Redis::connection()->del('{redis-queue}-waiting:mgs_tron_backfill');
    (new RechargeMaintenance())->run();
    ensure(Redis::hExists(RedisKey::ForeverMgsTronGaps->value, $gapId), '丢消息导致任务消失');
    $scan->batch($gapId);
    ensure(!Redis::hExists(RedisKey::ForeverMgsTronGaps->value, $gapId) && Transfer::count() === 5, '重复补扫不幂等');
    Redis::del(RedisKey::ForeverMgsTronCheckpoint->value);
    $scan->start();
    ensure(!$scan->status()['ready'], '尚未扫描成功就标记健康');
    $scan->batch();
    ensure($scan->status()['ready'] && !isset($scan->status()['checkpoint']['recovery_required']), '重建断点后没有自动恢复');

    $trxEvent = ['transaction_id' => hash('sha256', 'native-credit'), 'event_index' => 0, 'currency_code' => 'TRX', 'amount' => '7.000100',
        'from_address' => TronClient::address('41' . str_repeat('22', 20)), 'receive_address' => $address, 'block_number' => 7,
        'block_time' => gmdate('Y-m-d H:i:s'), 'data' => ['block_hash' => $node->blocks[7]['blockID']]];
    $nativeOrder = Recharge::create(array_replace($base, ['recharge_no' => mg_no('MR'), 'request_id' => '550e8400-e29b-41d4-a716-446655440006', 'pay_currency_code' => 'TRX', 'pay_amount' => '7.0001']));
    $nativeId = $processor->store([$trxEvent])[0];
    $credit->credit($nativeId);
    ensure($wallet->fresh()->balance === '310.00000000' && $nativeOrder->fresh()->status === 'paid', 'TRX原生收款无法自动入账');
    $rollbackOrder = Recharge::create(array_replace($base, ['recharge_no' => mg_no('MR'), 'request_id' => '550e8400-e29b-41d4-a716-446655440007', 'pay_currency_code' => 'TRX', 'pay_amount' => '8.0001']));
    $rollbackId = $processor->store([array_replace($trxEvent, ['transaction_id' => hash('sha256', 'rollback-credit'), 'amount' => '8.000100'])])[0];
    Db::unprepared("CREATE TRIGGER fail_credit BEFORE INSERT ON mgs_bills_" . gmdate('ym') . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected rollback'");
    fails(fn () => $credit->credit($rollbackId), 'injected rollback');
    ensure($wallet->fresh()->balance === '310.00000000' && $rollbackOrder->fresh()->status === 'pending' && Transfer::find($rollbackId)->status === 'pending', '事务失败留下部分入账');
    Db::unprepared('DROP TRIGGER fail_credit');
    $credit->credit($rollbackId);
    ensure($wallet->fresh()->balance === '410.00000000', '事务回滚后无法重试');
    $previous = (new DateTimeImmutable('first day of this month 00:00:00', new DateTimeZone('UTC')))->modify('-30 seconds');
    $crossMonth = Recharge::create(array_replace($base, ['recharge_no' => mg_no('MR'), 'request_id' => '550e8400-e29b-41d4-a716-446655440008',
        'pay_currency_code' => 'TRX', 'pay_amount' => '9.0001', 'status' => 'expired',
        'create_time' => $previous->modify('-10 seconds')->format('Y-m-d H:i:s.v'), 'expire_time' => $previous->modify('+10 seconds')->format('Y-m-d H:i:s.v')]));
    $crossId = $processor->store([array_replace($trxEvent, ['transaction_id' => hash('sha256', 'cross-month'), 'amount' => '9.000100', 'block_time' => $previous->format('Y-m-d H:i:s.v')])])[0];
    $credit->credit($crossId); $credit->credit($crossId);
    ensure($wallet->fresh()->balance === '510.00000000' && $crossMonth->fresh()->status === 'paid', '迟发现但按时付款的跨月订单未正确入账');
    ensure(Db::table('mgs_bills_' . gmdate('ym'))->where('transaction_id', 'recharge:' . $crossMonth->id)->count() === 1, '跨月流水未记入实际入账月份');
    echo "PASS: 地址/合约、完整回执、重启追最新、Redis缺口补扫、失锁重放、USDT/TRX事件、自动/人工入账、重复消费和自动恢复\n";
} finally {
    // 仅清理本次随机前缀，绝不FLUSHDB。
    $client = new \Redis(); $client->connect('127.0.0.1', $redisPort);
    $keys = $client->keys($prefix . '*'); if ($keys) $client->del($keys);
    $pdo->exec("DROP DATABASE `{$database}`");
}
