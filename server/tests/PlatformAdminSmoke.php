<?php

use app\model\Enterprise;
use app\model\Merchant;
use app\service\game\SecretService;
use plugin\saiadmin\app\cache\UserAuthCache;
use plugin\saiadmin\app\cache\UserMenuCache;
use plugin\saiadmin\app\model\system\SystemUser;
use support\Db;
use Tinywan\Jwt\JwtToken;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

function checkPlatform(bool $result, string $message): void
{
    if (!$result) throw new RuntimeException($message);
}

function requestPlatform(string $token, string $method, string $path, array $data = []): array
{
    $url = 'http://127.0.0.1:8787' . $path;
    if ($method === 'GET' && $data) $url .= '?' . http_build_query($data);
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $method === 'GET' ? null : json_encode($data, JSON_UNESCAPED_UNICODE), CURLOPT_TIMEOUT => 5,
    ]);
    $body = curl_exec($curl);
    if ($body === false) throw new RuntimeException('HTTP 请求失败: ' . curl_error($curl));
    curl_close($curl);
    $response = json_decode($body, true);
    if (!is_array($response)) throw new RuntimeException("返回非 JSON: {$body}");
    return $response;
}

function tokenPlatform(SystemUser $user): string
{
    return JwtToken::generateToken(['access_exp' => 300, 'id' => $user->id, 'username' => $user->username, 'type' => 'web', 'plat' => 'saiadmin'])['access_token'];
}

$suffix = bin2hex(random_bytes(4));
$password = 'MgTest_' . $suffix;
$super = SystemUser::where('username', 'game_admin')->firstOrFail();
UserAuthCache::clearUserAuth($super->id);
UserMenuCache::clearUserMenu($super->id);
$token = tokenPlatform($super);
$enterprise = Enterprise::create(['name' => "__platform_{$suffix}", 'merchant_limit' => 3, 'timezone' => 'UTC', 'default_language' => 'en', 'status' => 1]);
$otherEnterprise = Enterprise::create(['name' => "__platform_other_{$suffix}", 'merchant_limit' => 1, 'timezone' => 'UTC', 'default_language' => 'en', 'status' => 1]);
$merchant = Merchant::create([
    'enterprise_id' => $enterprise->id, 'name' => "Platform A {$suffix}", 'wallet_mode' => 1, 'callback_url' => 'http://127.0.0.1',
    'secret' => SecretService::encrypt($suffix), 'language_codes' => ['en'], 'default_language' => 'en', 'timezone' => 'UTC', 'timeout_ms' => 1000, 'status' => 1,
]);
$merchant->update(['mch_id' => (string) id2big((int) $merchant->id)]);
$merchant2 = Merchant::create([
    'enterprise_id' => $enterprise->id, 'name' => "Platform B {$suffix}", 'wallet_mode' => 1, 'callback_url' => 'http://127.0.0.1',
    'secret' => SecretService::encrypt($suffix), 'language_codes' => ['en'], 'default_language' => 'en', 'timezone' => 'UTC', 'timeout_ms' => 1000, 'status' => 1,
]);
$merchant2->update(['mch_id' => (string) id2big((int) $merchant2->id)]);
$otherMerchant = Merchant::create([
    'enterprise_id' => $otherEnterprise->id, 'name' => "Platform Other {$suffix}", 'wallet_mode' => 1, 'callback_url' => 'http://127.0.0.1',
    'secret' => SecretService::encrypt($suffix), 'language_codes' => ['en'], 'default_language' => 'en', 'timezone' => 'UTC', 'timeout_ms' => 1000, 'status' => 1,
]);
$otherMerchant->update(['mch_id' => (string) id2big((int) $otherMerchant->id)]);
$createdIds = [];

try {
    $list = requestPlatform($token, 'GET', '/game/platform-admin/index', ['page' => 1, 'limit' => 100]);
    $primary = collect($list['data']['data'] ?? [])->firstWhere('id', $super->id);
    checkPlatform(($list['code'] ?? 0) === 200 && ($primary['protected'] ?? false), '平台主超管保护标识错误');
    $protectedDelete = requestPlatform($token, 'DELETE', '/game/platform-admin/destroy', ['id' => $super->id]);
    checkPlatform(($protectedDelete['code'] ?? 200) !== 200, '平台主超管可以被删除');

    $newSuper = requestPlatform($token, 'POST', '/game/platform-admin/save', [
        'username' => "ps{$suffix}", 'realname' => 'Platform Super', 'password' => $password,
        'role_code' => 'game_super_admin', 'status' => 1,
    ]);
    checkPlatform(($newSuper['code'] ?? 0) === 200, '创建平台超管失败: ' . json_encode($newSuper, JSON_UNESCAPED_UNICODE));
    $createdIds[] = $superId = (int) $newSuper['data']['id'];
    checkPlatform(SystemUser::findOrFail($superId)->roles()->where('code', 'game_super_admin')->exists(), '新超管角色错误');

    $owner = requestPlatform($token, 'POST', '/game/platform-admin/save', [
        'username' => "po{$suffix}", 'realname' => 'Platform Owner', 'password' => $password,
        'role_code' => 'enterprise_owner', 'enterprise_id' => $enterprise->id, 'status' => 1,
    ]);
    checkPlatform(($owner['code'] ?? 0) === 200, '创建企业负责人失败: ' . json_encode($owner, JSON_UNESCAPED_UNICODE));
    $createdIds[] = $ownerId = (int) $owner['data']['id'];
    $duplicateOwner = requestPlatform($token, 'POST', '/game/platform-admin/save', [
        'username' => "px{$suffix}", 'realname' => 'Duplicate Owner', 'password' => $password,
        'role_code' => 'enterprise_owner', 'enterprise_id' => $enterprise->id, 'status' => 1,
    ]);
    checkPlatform(($duplicateOwner['code'] ?? 200) !== 200 && !SystemUser::where('username', "px{$suffix}")->exists(), '企业可以创建多个负责人');

    $staff = requestPlatform($token, 'POST', '/game/platform-admin/save', [
        'username' => "pt{$suffix}", 'realname' => 'Platform Staff', 'password' => $password,
        'role_code' => 'enterprise_staff', 'enterprise_id' => $enterprise->id, 'merchant_ids' => [$merchant->id], 'status' => 1,
    ]);
    checkPlatform(($staff['code'] ?? 0) === 200, '创建企业子账号失败: ' . json_encode($staff, JSON_UNESCAPED_UNICODE));
    $createdIds[] = $staffId = (int) $staff['data']['id'];
    $crossScope = requestPlatform($token, 'PUT', '/game/platform-admin/update', [
        'id' => $staffId, 'username' => "pt{$suffix}", 'realname' => 'Platform Staff', 'role_code' => 'enterprise_staff',
        'enterprise_id' => $enterprise->id, 'merchant_ids' => [$otherMerchant->id], 'status' => 1,
    ]);
    checkPlatform(($crossScope['code'] ?? 200) !== 200, '子账号可以绑定其他企业商户');
    $updated = requestPlatform($token, 'PUT', '/game/platform-admin/update', [
        'id' => $staffId, 'username' => "pt{$suffix}", 'realname' => 'Updated Staff', 'role_code' => 'enterprise_staff',
        'enterprise_id' => $enterprise->id, 'merchant_ids' => [$merchant2->id], 'status' => 1,
    ]);
    checkPlatform(($updated['code'] ?? 0) === 200, '更新子账号失败');
    $relation = Db::table('mg_enterprise_users')->where('system_user_id', $staffId)->whereNull('delete_time')->first();
    checkPlatform(Db::table('mg_enterprise_user_merchants')->where(['enterprise_user_id' => $relation->id, 'merchant_id' => $merchant2->id])->exists(), '子账号商户范围未更新');

    UserMenuCache::clearUserMenu($ownerId);
    checkPlatform(!str_contains(json_encode(UserMenuCache::getUserMenu($ownerId)), 'MgPlatformAdmin'), '企业负责人看到了平台管理菜单');
    $ownerForbidden = requestPlatform(tokenPlatform(SystemUser::findOrFail($ownerId)), 'GET', '/game/platform-admin/index', ['page' => 1]);
    checkPlatform(($ownerForbidden['code'] ?? 200) !== 200, '企业负责人可以访问平台管理');
    $newPassword = 'MgReset_' . $suffix;
    $passwordResult = requestPlatform($token, 'PUT', '/game/platform-admin/password', ['id' => $staffId, 'password' => $newPassword]);
    checkPlatform(($passwordResult['code'] ?? 0) === 200 && password_verify($newPassword, SystemUser::findOrFail($staffId)->password), '平台账号改密失败');

    foreach ([$staffId, $ownerId, $superId] as $id) {
        $deleted = requestPlatform($token, 'DELETE', '/game/platform-admin/destroy', ['id' => $id]);
        checkPlatform(($deleted['code'] ?? 0) === 200 && !SystemUser::find($id), "删除平台账号 {$id} 失败");
    }
    echo "Platform admin smoke test passed\n";
} finally {
    foreach ($createdIds as $userId) {
        UserAuthCache::clearUserAuth($userId);
        UserMenuCache::clearUserMenu($userId);
        Db::table('sa_system_user_role')->where('user_id', $userId)->delete();
        $relationIds = Db::table('mg_enterprise_users')->where('system_user_id', $userId)->pluck('id');
        Db::table('mg_enterprise_user_merchants')->whereIn('enterprise_user_id', $relationIds)->delete();
        Db::table('mg_enterprise_users')->where('system_user_id', $userId)->delete();
        Db::table('sa_system_user')->where('id', $userId)->delete();
    }
    Db::table('mg_merchants')->whereIn('id', [$merchant->id, $merchant2->id, $otherMerchant->id])->delete();
    Db::table('mg_enterprises')->whereIn('id', [$enterprise->id, $otherEnterprise->id])->delete();
}
