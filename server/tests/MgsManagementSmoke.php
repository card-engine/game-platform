<?php

use app\logic\game\SettingsLogic;
use app\logic\mgs\MgsLogic;
use app\model\mgs\User;
use app\service\game\ConfigService;
use app\service\mgs\MgsAuthService;
use app\validate\mgs\SettlementValidate;
use support\Db;
use support\Request;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';
if (config('database.connections.mysql.database') !== '__mgs_ui_test') throw new RuntimeException('仅运行于隔离测试库');
function assertManagement(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function deniedManagement(callable $fn, string $message): void {
    try { $fn(); } catch (plugin\saiadmin\exception\ApiException $e) { assertManagement(str_contains($e->getMessage(), $message), $e->getMessage()); return; }
    throw new RuntimeException('操作没有被拒绝：' . $message);
}
Db::beginTransaction();
try {
    $settings = new SettingsLogic();$settings->init(['id' => 1]);
    $before = (new ConfigService())->rebuild();
    deniedManagement(fn () => $settings->save(['platform_timezone' => 'UTC']), '只读');
    deniedManagement(fn () => $settings->save(['platform_currency_code' => 'EUR']), '只读');
    deniedManagement(fn () => $settings->save(['other_config' => 'x']), '只读');
    $settings->save(['exchange_rate_display_codes' => ['USD', 'INR', 'USD']]);
    $after = (new ConfigService())->all();
    assertManagement($after['platform_timezone'] === $before['platform_timezone'] && $after['platform_currency_code'] === $before['platform_currency_code'], '保存改变了安装口径');
    assertManagement($after['exchange_rate_display_codes'] === ['USD', 'INR'], '汇率展示币种保存错误');
    $token = bin2hex(random_bytes(32));
    $user = User::create(['browser_token_hash' => hash('sha256', $token), 'status' => 1]);
    $user->update(['unique_id' => id2big($user->id)]);
    $logic = new MgsLogic();$logic->userStatus($user->id, 0);
    deniedManagement(fn () => $logic->userStatus(1, 0), '系统玩家');
    $req = new Request("POST /api/session HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer {$token}\r\n\r\n");
    $auth = new MgsAuthService();
    assertManagement((int) $auth->session($req)['user']->status === 0, '停用用户不能登录');
    assertManagement($auth->browserUser($req)->id === $user->id, '停用用户不能读取');
    deniedManagement(fn () => $auth->browserUser($req, true), '停用');
    $api = new app\controller\mgs\ApiController();
    foreach (['user', 'wallet', 'recharges'] as $method) assertManagement(json_decode($api->$method($req)->rawBody(), true)['code'] === 200, '停用读取接口被拒：' . $method);
    foreach (['launch', 'updateUser', 'createRecharge'] as $method) deniedManagement(fn () => $api->$method($req), '停用');
    $logic->userStatus($user->id, 1);
    assertManagement((int) $auth->browserUser($req, true)->status === 1, '重新启用不生效');
    $validator = new SettlementValidate();
    assertManagement($validator->scene('pay')->check(['remark'=>'test','payment_reference'=>'test','paid_time'=>gmdate('Y-m-d H:i:s',time()-60)]), '合法付款时间被拒');
    assertManagement(!$validator->scene('pay')->check(['remark'=>'test','payment_reference'=>'test','paid_time'=>gmdate('Y-m-d H:i:s',time()+86400)]), '未来付款时间被接受');
    assertManagement(format_amount('-0.00010000') === '-0.0001' && format_amount('-0.0000') === '0.00', '金额负号展示错误');
    echo "PASS: 安装口径只读、保存字段白名单、停用用户登录读取/写限制、重新启用、付款校验\n";
} finally { Db::rollBack(); (new ConfigService())->rebuild(); }
