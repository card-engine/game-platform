<?php

// 本机模拟上游，不能连接真实供应商。
if (PHP_SAPI === 'cli-server') {
    header('Content-Type: application/json');
    echo json_encode(['code' => 0, 'data' => ['url' => 'https://game.example/test']]);
    return;
}

use app\controller\openapi\OpenApiController;
use app\middleware\MerchantAuth;
use app\model\Game;
use app\model\GameBrand;
use app\model\Merchant;
use app\model\MerchantCredit;
use app\model\User;
use app\service\game\OpenApiService;
use app\service\game\SecretService;
use app\validate\game\OpenApiValidate;
use support\Db;
use support\Request;
use Webman\Config;
use Webman\Database\Initializer;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

function checkIp(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

$port = (int) getenv('MGS_TEST_MYSQL_PORT');
checkIp($port > 0, '必须指定隔离测试库端口');
$database = '__mg_ip_test_' . getmypid();
$pdo = new PDO("mysql:host=127.0.0.1;port={$port};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
$settings = Config::get();
$settings['database']['connections']['mysql'] = array_replace($settings['database']['connections']['mysql'], ['host' => '127.0.0.1', 'port' => $port, 'username' => 'root', 'password' => '', 'database' => $database]);
$upstreamPort = 20000 + getmypid() % 20000;
$settings['game_platforms'] = ['secret_key' => 'ip-test-only', 'platforms' => ['wxgame' => ['is_open' => true, 'accounts' => ['sc' => ['url' => "http://127.0.0.1:{$upstreamPort}", 'app_key' => 'test', 'app_secret' => 'test']]]]];
(new ReflectionProperty(Config::class, 'config'))->setValue(null, $settings);
(new ReflectionProperty(Config::class, 'flatCache'))->setValue(null, []);
Webman\Context::destroy();
(new ReflectionProperty(Webman\Database\DatabaseManager::class, 'pools'))->setValue(null, []);
(new ReflectionProperty(Initializer::class, 'initialized'))->setValue(null, false);
Initializer::init(config('database'));
$process = null;

try {
    $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    foreach (['mg_merchants', 'mg_merchant_credits', 'mg_merchant_games', 'mg_game_brands', 'mg_unique_brands', 'mg_games', 'mg_users', 'mg_user_game_rtps'] as $table) {
        preg_match('/CREATE TABLE `' . $table . '` \(.*?;(?=\n)/s', $schema, $match);
        Db::statement($match[0]);
    }
    $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$upstreamPort}", __FILE__], [STDIN, ['file', '/dev/null', 'a'], ['file', '/dev/null', 'a']], $pipes);
    checkIp(is_resource($process), '模拟上游未启动');
    for ($i = 0; $i < 50; $i++) {
        if ($socket = @fsockopen('127.0.0.1', $upstreamPort)) { fclose($socket); break; }
        usleep(20000);
    }
    checkIp($i < 50, '模拟上游启动超时');
    $merchant = Merchant::create(['enterprise_id' => 1, 'mch_id' => 'test', 'name' => 'IP test', 'secret' => SecretService::encrypt('test-secret'), 'language_codes' => ['en'], 'status' => 1]);
    MerchantCredit::create(['merchant_id' => $merchant->id, 'currency_code' => 'USD', 'settlement_enabled' => 0, 'status' => 1]);
    $brand = GameBrand::create(['platform_code' => 'wxgame', 'provider_brand_code' => 'fixture', 'name' => 'Fixture']);
    $game = Game::create(['brand_id' => $brand->id, 'platform_code' => 'wxgame', 'provider_game_code' => 'fixture', 'name' => 'Fixture', 'currency_codes' => ['USD'], 'upstream_status' => 1, 'platform_status' => 1]);
    $params = ['mch_id' => 'test', 'timestamp' => time(), 'user_id' => 'player', 'game_id' => (string) id2big($game->id), 'currency' => 'USD'];
    $controller = new OpenApiController();
    $auth = new MerchantAuth();
    foreach (['198.51.100.7', '2001:db8::7', null, ''] as $ip) {
        $body = $params;
        if ($ip !== null) $body['ip'] = $ip;
        $body['sign'] = game_platform_sign($body, 'test-secret');
        $json = json_encode($body);
        $request = new class("POST /open_api/launch HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\n\r\n" . $json) extends Request {
            public function getRealIp(bool $safeMode = true): string { return '127.0.0.1'; }
        };
        $response = json_decode($auth->process($request, fn ($request) => $controller->launch($request))->rawBody(), true);
        checkIp($response['code'] === 200, '进游失败');
        $expected = $ip ?: '2001:db8::7';
        checkIp(User::where('merchant_user_id', 'player')->value('last_ip') === $expected, '玩家IP被调用方IP覆盖，或未传IP时清空原值');
        $merchant->update(['ip_whitelist' => [$ip ?: '198.51.100.7']]);
        $denied = json_decode($auth->process($request, fn () => throw new RuntimeException('不应通过白名单'))->rawBody(), true);
        checkIp($denied['code'] === 403, '玩家IP被错误用于商户白名单');
        $merchant->update(['ip_whitelist' => ['127.0.0.1']]);
    }
    $body['ip'] = '203.0.113.8'; // 保留旧签名，篡改玩家IP必须被拒绝。
    $json = json_encode($body);
    $requestClass = $request::class;
    $tampered = new $requestClass("POST /open_api/launch HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\n\r\n" . $json);
    $denied = json_decode($auth->process($tampered, fn () => throw new RuntimeException('篡改请求不应进入进游'))->rawBody(), true);
    checkIp($denied['code'] === 401, '玩家IP未参与签名验证');
    foreach (['bad-ip', '198.51.100.1, 127.0.0.1', '198.51.100.1:80', [], 123] as $badIp) {
        checkIp(!(new OpenApiValidate())->scene('launch')->check($params + ['ip' => $badIp]), '非法IP通过验证');
    }
    $service = new OpenApiService();
    $service->launch($merchant, array_replace($params, ['user_id' => 'no-ip']), null);
    checkIp(User::where('merchant_user_id', 'no-ip')->value('last_ip') === null, '新玩家未提供IP却保存调用方IP');
    $service->launch($merchant, $params, '203.0.113.9');
    checkIp(User::where('merchant_user_id', 'player')->value('last_ip') === '203.0.113.9', '后台直接试玩IP失效');
    echo "PASS: 验签进游IPv4/IPv6落库、未传IP保留原值、调用方白名单独立、非法IP拒绝、后台试玩IP\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
}
