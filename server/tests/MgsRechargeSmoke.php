<?php

/** 隔离 MySQL 库和 Redis 前缀，验证充值下单；不请求链、不改余额、不触碰现有业务数据。 */
use app\enum\RedisKey;
use app\logic\mgs\RechargeLogic;
use app\model\ExchangeRate;
use app\model\RechargeOrder;
use app\model\mgs\User;
use app\model\mgs\Wallet;
use app\validate\mgs\RechargeValidate;
use app\service\mgs\TrxPriceService;
use plugin\saiadmin\exception\ApiException;
use support\Db;
use support\Redis;
use Webman\Config;
use Webman\Database\Initializer;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function rejects(callable $action, string $message): void
{
    try {
        $action();
    } catch (ApiException $exception) {
        check(str_contains($exception->getMessage(), $message), $exception->getMessage());
        return;
    }
    throw new RuntimeException('未拒绝：' . $message);
}

$original = config('database.connections.mysql');
$testPort = getenv('MGS_TEST_MYSQL_PORT');
if ($testPort) $original = array_replace($original, ['host' => '127.0.0.1', 'port' => (int) $testPort, 'username' => 'root', 'password' => '']);
check(in_array($original['host'], ['127.0.0.1', 'localhost'], true), '只允许本机测试库');
check(in_array(config('redis.default.host'), ['127.0.0.1', 'localhost'], true), '只允许本机 Redis');
$worker = ($argv[1] ?? '') === '--worker';
$database = $worker ? $argv[2] : '__mgs_recharge_test_' . getmypid();
check((bool) preg_match('/^__mgs_recharge_test_\d+$/', $database), '测试库名称无效');
$prefix = $database . ':';
$pdo = new PDO("mysql:host={$original['host']};port={$original['port']};charset=utf8mb4", $original['username'], $original['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (!$worker) $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
$settings = Config::get();
$settings['database']['connections']['mysql'] = array_replace($original, ['database' => $database]);
if ($testPort) $settings['redis']['default'] = array_replace($settings['redis']['default'], ['host' => '127.0.0.1', 'port' => (int) getenv('MGS_TEST_REDIS_PORT'), 'password' => '']);
$settings['redis']['default']['prefix'] = $prefix;
$settings['mgs']['recharge_enabled'] = true;
$settings['mgs']['recharge_currencies'] = ['USD', 'INR'];
// 仅作测试标识，绝不向该地址转账。
$settings['mgs']['recharge_tron_receive_address'] = 'T' . str_repeat('A', 33);
$configProperty = new ReflectionProperty(Config::class, 'config');
$configProperty->setValue(null, $settings);
(new ReflectionProperty(Config::class, 'flatCache'))->setValue(null, []);
(new ReflectionProperty(Initializer::class, 'initialized'))->setValue(null, false);
Initializer::init(config('database'));

if ($worker) {
    $logic = new RechargeLogic();
    $quote = $logic->options('INR')['payments'][0];
    $order = $logic->create(User::findOrFail((int) $argv[3]), ['currency_code' => 'INR', 'recharge_amount' => 100,
        'pay_currency_code' => 'USDT', 'request_id' => 'concurrent-request', 'quote_key' => $quote['quote_key']]);
    echo $order['order_no'];
    exit;
}

try {
    $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    foreach (['mgs_users', 'mgs_wallets', 'mg_exchange_rates', 'mgs_recharge_orders', 'mgs_chain_scan_states'] as $table) {
        preg_match('/CREATE TABLE `' . $table . '` \(.*?;(?=\n)/s', $schema, $match);
        Db::statement($match[0]);
    }
    $scan = ['network_code' => 'tron_mainnet', 'start_block_number' => 1, 'next_block_number' => 2,
        'heartbeat_time' => gmdate('Y-m-d H:i:s'), 'solid_block_time' => gmdate('Y-m-d H:i:s'), 'last_block_time' => gmdate('Y-m-d H:i:s')];
    Db::table('mgs_chain_scan_states')->insert($scan);
    ExchangeRate::create(['rate_date' => gmdate('Y-m-d'), 'base_currency_code' => 'USD', 'source' => 'currencyapi',
        'rate_json' => ['INR' => '80'], 'source_update_time' => gmdate('Y-m-d H:i:s')]);
    $user = User::create(['status' => 1]);
    $logic = new RechargeLogic();
    $options = $logic->options('INR');
    check(count($options['amounts']) === 13 && count($options['payments']) === 1, '档位或无行情降级错误');
    check($options['payments'][0]['amounts'][100] === '1.25', '服务端报价错误');
    $data = ['currency_code' => 'INR', 'pay_currency_code' => 'USDT', 'recharge_amount' => 100,
        'request_id' => 'smoke-request-0001', 'quote_key' => $options['payments'][0]['quote_key']];
    foreach ([100, '100'] as $amount) check((new RechargeValidate())->scene('save')->check(array_replace($data, ['recharge_amount' => $amount])), '合法档位未通过');
    foreach ([100.5, '100.5', '100x', 0, -100, true, [], '1e2', '100.000000001'] as $amount) {
        check(!(new RechargeValidate())->scene('save')->check(array_replace($data, ['recharge_amount' => $amount])), '非法金额通过校验');
    }
    Redis::set(RedisKey::ForeverMgsRechargeSuffix->value, 98);
    check((int) Redis::eval(RechargeLogic::SUFFIX_SCRIPT, 1, RedisKey::ForeverMgsRechargeSuffix->value) === 99, '尾号99错误');
    check((int) Redis::eval(RechargeLogic::SUFFIX_SCRIPT, 1, RedisKey::ForeverMgsRechargeSuffix->value) === 1, '尾号循环错误');
    Redis::set(RedisKey::ForeverMgsRechargeSuffix->value, 0);
    $order = $logic->create($user, $data);
    check($order['pay_amount'] === '1.2501', '支付数量错误');
    check(Wallet::where('user_id', $user->id)->value('balance') === '0.00000000', '下单误加余额');
    $stored = RechargeOrder::first();
    check($stored->exchange_rate_id !== null && $stored->amount_match_key !== null, '报价或金额占用未保存');
    check($logic->create($user, $data)['order_no'] === $order['order_no'], '重试产生新单');
    check(Redis::get(RedisKey::ForeverMgsRechargeSuffix->value) === '1', '重试占用新尾号');
    rejects(fn () => $logic->create($user, array_replace($data, ['recharge_amount' => 200])), '其他充值参数');
    rejects(fn () => $logic->create($user, array_replace($data, ['request_id' => 'smoke-request-0002'])), '当前充值订单');
    rejects(fn () => $logic->order(User::create(['status' => 1]), $order['order_no']), '不存在');

    $stored->update(['expire_time' => gmdate('Y-m-d H:i:s', time() - 1)]);
    check($logic->current($user, 'INR') === null && $logic->order($user, $order['order_no'])['status'] === 'expired', '过期订单仍可付款');
    // Redis 重建后仍以数据库唯一索引跳过已占用数量。
    Redis::set(RedisKey::ForeverMgsRechargeSuffix->value, 0);
    $next = $logic->create($user, array_replace($data, ['request_id' => 'smoke-request-0002']));
    check($next['pay_amount'] === '1.2502', '金额冲突未避让');
    check($logic->current($user, 'USD') === null, '跨钱包恢复错误');

    $concurrentUser = User::create(['status' => 1]);
    $workers = [];
    for ($i = 0; $i < 2; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $database, (string) $concurrentUser->id],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $numbers = [];
    foreach ($workers as [$process, $pipes]) {
        $numbers[] = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        check(proc_close($process) === 0, '并发请求失败：' . $error);
    }
    check(count(array_unique($numbers)) === 1 && RechargeOrder::where('user_id', $concurrentUser->id)->count() === 1, '并发重试生成多单');

    $other = User::create(['status' => 1]);
    rejects(fn () => $logic->create($other, array_replace($data, ['quote_key' => str_repeat('0', 64)])), '报价已过期');
    Redis::setex(RedisKey::TempMgsTrxTicker->value, 60, json_encode(['price' => '0.25', 'source_time' => time(), 'fetch_time' => time()]));
    $options = $logic->options('INR');
    check($options['payments'][1]['amounts'][100] === '5.00', 'TRX换算错误');
    Redis::setex(RedisKey::TempMgsTrxTicker->value, 60, json_encode(['price' => '0.25', 'source_time' => time() - 300]));
    check(count($logic->options('INR')['payments']) === 1, '过期行情未禁用TRX');
    // 同一支付数量最多99个槽位，全部用满后必须失败，不能丢掉唯一约束。
    for ($i = RechargeOrder::count(); $i < 99; $i++) $logic->create(User::create(['status' => 1]), $data);
    $count = RechargeOrder::count();
    rejects(fn () => $logic->create($other, $data), '暂满');
    check(RechargeOrder::count() === $count, '满额失败留下了订单');
    check(!$logic->options('EUR')['available'], '未开放币种可充值');
    Db::table('mgs_chain_scan_states')->update(['heartbeat_time' => gmdate('Y-m-d H:i:s', time() - 300)]);
    check(!$logic->options('INR')['available'], '扫块不健康仍开放充值');
    rejects(fn () => $logic->create($other, $data), '未就绪');
    check($logic->create($user, $data)['order_no'] === $order['order_no'], '原单重试依赖扫描健康');
    $settings['mgs']['recharge_enabled'] = false;
    $configProperty->setValue(null, $settings);
    (new ReflectionProperty(Config::class, 'flatCache'))->setValue(null, []);
    check(!$logic->options('INR')['available'], '充值关闭失效');
    // 真实 HTTP 客户端配合本机响应夹具验证行情解析，测试不请求 OKX。
    foreach ([['last' => '0.25', 'ts' => (string) (time() * 1000)], ['last' => '0.25', 'ts' => (string) ((time() - 600) * 1000)], ['last' => '0', 'ts' => (string) (time() * 1000)]] as $index => $fixture) {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $settings['mgs']['okx_base_url'] = 'http://' . stream_socket_get_name($socket, false);
        $configProperty->setValue(null, $settings);
        (new ReflectionProperty(Config::class, 'flatCache'))->setValue(null, []);
        $pid = pcntl_fork();
        if ($pid === 0) {
            $connection = stream_socket_accept($socket, 5);
            while (($line = fgets($connection)) !== false && trim($line) !== '') {}
            $body = json_encode(['code' => '0', 'data' => [['instId' => 'TRX-USDT'] + $fixture]]);
            fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
            fclose($connection);
            fclose($socket);
            exit;
        }
        try {
            $ticker = (new TrxPriceService())->sync();
            check($index === 0 && $ticker['price'] === '0.25', '错误行情被接受');
            check(Redis::ttl(RedisKey::TempMgsTrxTicker->value) > 0, '行情缓存没有TTL');
        } catch (RuntimeException $exception) {
            check($index > 0 && $exception->getMessage() === 'OKX TRX 行情无效或已过期', $exception->getMessage());
        } finally {
            fclose($socket);
            pcntl_waitpid($pid, $status);
        }
    }
    echo "PASS: 充值校验、报价、尾号循环、并发重试、金额冲突、钱包隔离、越权及未就绪拦截\n";
} finally {
    Redis::del(RedisKey::ForeverMgsRechargeSuffix->value, RedisKey::TempMgsTrxTicker->value);
    // HTTP夹具子进程退出会关闭继承的PDO连接；清理时重新建立测试库连接。
    $pdo = new PDO("mysql:host={$original['host']};port={$original['port']};charset=utf8mb4", $original['username'], $original['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("DROP DATABASE `{$database}`");
}
