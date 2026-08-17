<?php

use plugin\saiadmin\app\cache\UserAuthCache;
use plugin\saiadmin\app\cache\UserMenuCache;
use plugin\saiadmin\app\model\system\SystemUser;
use Tinywan\Jwt\JwtToken;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

function checkSuper(bool $result, string $message): void
{
    if (!$result) throw new RuntimeException($message);
}

function requestSuper(string $token, string $path, array $data = [], string $method = 'GET'): array
{
    $curl = curl_init('http://127.0.0.1:8787' . $path . ($method === 'GET' && $data ? '?' . http_build_query($data) : ''));
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $method === 'GET' ? null : json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 5,
    ]);
    $body = curl_exec($curl);
    if ($body === false) throw new RuntimeException('HTTP 请求失败: ' . curl_error($curl));
    $response = json_decode($body, true);
    curl_close($curl);
    if (!is_array($response)) throw new RuntimeException("返回非 JSON: {$body}");
    return $response;
}

$user = SystemUser::where('username', 'game_admin')->firstOrFail();
checkSuper((int) $user->is_super === 0 && $user->roles()->where('code', 'game_super_admin')->exists(), '游戏超管账号或角色错误');

UserAuthCache::clearUserAuth($user->id);
UserMenuCache::clearUserMenu($user->id);
$menus = UserMenuCache::getUserMenu($user->id);
checkSuper(count($menus) === 1 && ($menus[0]['name'] ?? '') === 'MgGame', '游戏超管包含框架系统菜单');
$children = collect($menus[0]['children'] ?? []);
checkSuper($children->contains('name', 'MgSettings') && $children->contains('name', 'MgExchangeRates') && $children->contains('name', 'MgPlatformAdmin'), '游戏超管缺少专属菜单');
checkSuper($children->whereIn('name', ['MgSettings', 'MgExchangeRates', 'MgPlatformAdmin'])->every(fn ($menu) => ($menu['meta']['showTextBadge'] ?? '') === '超管'), '超管菜单提示缺失');

$token = JwtToken::generateToken([
    'access_exp' => 300,
    'id' => $user->id,
    'username' => $user->username,
    'type' => 'web',
    'plat' => 'saiadmin',
])['access_token'];
$merchantCount = app\model\Merchant::count();
$context = requestSuper($token, '/game/context');
checkSuper(($context['code'] ?? 0) === 200 && ($context['data']['role'] ?? '') === 'super_admin', '游戏超管上下文错误');
checkSuper(count($context['data']['merchants'] ?? []) === $merchantCount, '游戏超管未看到全部商户参数');

foreach ([
    ['/game/settings', []],
    ['/game/settings/rebuild-status', []],
    ['/game/exchange-rates', ['page' => 1, 'limit' => 20]],
    ['/game/platform-admin/index', ['page' => 1, 'limit' => 20]],
    ['/game/platform-admin/options', []],
    ['/game/operations/overview', []],
    ['/game/operations/bets', ['page' => 1, 'limit' => 20, 'date_start' => gmdate('Y-m-01'), 'date_end' => gmdate('Y-m-d')]],
    ['/game/operations/bills', ['page' => 1, 'limit' => 20, 'date_start' => gmdate('Y-m-01'), 'date_end' => gmdate('Y-m-d')]],
    ['/game/operations/reports', ['page' => 1, 'limit' => 20, 'date_start' => gmdate('Y-m-01'), 'date_end' => gmdate('Y-m-d')]],
] as [$path, $query]) {
    $response = requestSuper($token, $path, $query);
    checkSuper(($response['code'] ?? 0) === 200, "游戏超管无法访问 {$path}: " . json_encode($response, JSON_UNESCAPED_UNICODE));
}
$report = requestSuper($token, '/game/operations/reports', ['page' => 1, 'limit' => 100]);
$reportRow = collect($report['data']['data'] ?? [])->first(fn ($row) => bccomp((string) $row['bet_amount'], '0', 8) > 0);
$expectedRtp = $reportRow ? bcadd(bcmul(bcdiv((string) $reportRow['win_amount'], (string) $reportRow['bet_amount'], 6), '100', 4), '0.005', 2) : null;
checkSuper($reportRow && bccomp((string) $reportRow['rtp'], $expectedRtp, 2) === 0, '游戏日报 RTP 计算错误');
$configs = collect(requestSuper($token, '/game/settings')['data'] ?? [])->pluck('value', 'code');
$saved = requestSuper($token, '/game/settings', ['values' => [
    'platform_timezone' => $configs['platform_timezone'],
    'platform_currency_code' => $configs['platform_currency_code'],
    'exchange_rate_display_codes' => $configs['exchange_rate_display_codes'],
]], 'PUT');
checkSuper(($saved['code'] ?? 0) === 200, '游戏超管保存全局设置失败: ' . json_encode($saved, JSON_UNESCAPED_UNICODE));

echo "Game super admin smoke test passed\n";
