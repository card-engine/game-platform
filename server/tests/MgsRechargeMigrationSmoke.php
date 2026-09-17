<?php

/** 从Git当前已提交的旧基线构造隔离旧库，验证转换不改ID、资金或历史引用。 */
use app\command\MgsRechargeSchemaCommand;
use Symfony\Component\Console\Tester\CommandTester;
use support\Db;
use Webman\Config;
use Webman\Database\Initializer;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';
$port = (int) getenv('MGS_TEST_MYSQL_PORT');
if (!$port) throw new RuntimeException('必须指定隔离MySQL端口');
$db = '__mgs_migrate_test_' . getmypid();
$pdo = new PDO("mysql:host=127.0.0.1;port={$port};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
$settings = Config::get();
$settings['database']['connections']['mysql'] = array_replace($settings['database']['connections']['mysql'], ['host' => '127.0.0.1','port' => $port,'username' => 'root','password' => '', 'database' => $db]);
(new ReflectionProperty(Config::class, 'config'))->setValue(null, $settings);
(new ReflectionProperty(Config::class, 'flatCache'))->setValue(null, []);
Webman\Context::destroy();
(new ReflectionProperty(Webman\Database\DatabaseManager::class, 'pools'))->setValue(null, []);
(new ReflectionProperty(Initializer::class, 'initialized'))->setValue(null, false);
Initializer::init(config('database'));
function verify(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
try {
    $old = shell_exec('git -C ' . escapeshellarg(dirname(__DIR__, 2)) . ' show b270116:server/database/schema.sql');
    foreach (['mgs_recharge_orders','mgs_recharge_transfers','mgs_chain_scan_states'] as $table) {
        preg_match('/CREATE TABLE `' . $table . '` \(.*?;(?=\n)/s', $old, $match);
        Db::statement($match[0]);
    }
    $now = gmdate('Y-m-d H:i:s');
    Db::table('mgs_recharge_orders')->insert(['id'=>7,'order_no'=>'MR-old','user_id'=>3,'wallet_id'=>9,
        'request_id'=>'550e8400-e29b-41d4-a716-446655440001','currency_code'=>'INR','recharge_amount'=>'100',
        'pay_type'=>'crypto','provider_code'=>'tron','pay_method'=>'trc20','pay_currency_code'=>'TRX','pay_base_amount'=>'5',
        'pay_amount'=>'5.0001','amount_suffix'=>1,'network_code'=>'tron_mainnet','receive_address'=>'test-address',
        'rate_snapshot'=>'{}','status'=>'paid','expire_time'=>$now,'credited_time'=>$now,'bill_no'=>'ML-old','create_time'=>$now]);
    Db::table('mgs_recharge_transfers')->insert(['id'=>11,'recharge_order_id'=>7,'network_code'=>'tron_mainnet','transaction_id'=>'tx-old','event_type'=>'native','event_index'=>0,
        'block_number'=>100,'block_hash'=>str_repeat('a',64),'from_address'=>'payer','receive_address'=>'test-address','currency_code'=>'TRX','amount'=>'5.0001','status'=>'credited',
        'block_time'=>$now,'detected_time'=>$now,'reviewed_by'=>4,'reviewed_time'=>$now,'remark'=>'verified','data'=>'{}']);
    $command = new CommandTester(new MgsRechargeSchemaCommand());
    verify($command->execute([]) === 1, '旧表未阻止普通升级');
    verify(!Db::connection()->getSchemaBuilder()->hasTable('mgs_recharges'), '预览执行了写入');
    verify($command->execute(['--apply'=>true]) === 0, $command->getDisplay());
    $order = Db::table('mgs_recharges')->where('id',7)->first();
    $transfer = Db::table('mgs_transfers')->where('id',11)->first();
    verify($order->recharge_no === 'MR-old' && $order->recharge_amount === '100.00000000' && (int)$transfer->recharge_id === 7, '转换丢失关联或改动资金');
    verify(json_decode($order->rate_snapshot,true)['legacy_bill_no'] === 'ML-old', '旧流水关联丢失');
    verify(json_decode($transfer->data,true)['reviews'][0]['admin_id'] === 4, '核验历史丢失');
    verify(!Db::connection()->getSchemaBuilder()->hasTable('mgs_chain_scan_states'), '旧断点表仍在使用');
    verify(count(Db::connection()->getSchemaBuilder()->getTableListing()) === 5, '未保留三张旧表备份');
    verify($command->execute([]) === 0, '转换后不能继续常规升级');
    echo "PASS: 充值结构转换、原ID/单号/金额、流水关联、核验历史及旧表备份\n";
} finally {
    $pdo->exec("DROP DATABASE `{$db}`");
}
