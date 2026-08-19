<?php

use app\model\Enterprise;
use app\model\EnterpriseUser;
use app\model\Game;
use app\model\GameBrand;
use app\model\Merchant;
use app\model\MerchantGame;
use app\model\UniqueBrand;
use app\service\game\SecretService;
use plugin\saiadmin\app\cache\UserAuthCache;
use plugin\saiadmin\app\model\system\SystemRole;
use plugin\saiadmin\app\model\system\SystemUser;
use support\Db;
use Tinywan\Jwt\JwtToken;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

function checkAccount(bool $result, string $message): void
{
    if (!$result) throw new RuntimeException($message);
}

function requestAccount(string $token, string $method, string $path, array $data = []): array
{
    $url = 'http://127.0.0.1:8787' . $path;
    if ($method === 'GET' && $data) $url .= '?' . http_build_query($data);
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $method === 'GET' ? null : json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 5,
    ]);
    $body = curl_exec($curl);
    if ($body === false) throw new RuntimeException('HTTP 请求失败: ' . curl_error($curl));
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $json = json_decode($body, true);
    if (!is_array($json)) throw new RuntimeException("HTTP {$status} 返回非 JSON: {$body}");
    return ['status' => $status, 'body' => $json];
}

function tokenAccount(SystemUser $user): string
{
    return JwtToken::generateToken([
        'access_exp' => 300,
        'id' => $user->id,
        'username' => $user->username,
        'type' => 'web',
        'plat' => 'saiadmin',
    ])['access_token'];
}

$suffix = bin2hex(random_bytes(4));
$password = 'MgTest_' . $suffix;
$ownerRole = SystemRole::where('code', 'enterprise_owner')->firstOrFail();
$enterprise = Enterprise::create(['name' => "__mg_account_{$suffix}", 'merchant_limit' => 2, 'timezone' => 'UTC', 'default_language' => 'en', 'status' => 1]);
$otherEnterprise = Enterprise::create(['name' => "__mg_other_{$suffix}", 'merchant_limit' => 1, 'timezone' => 'UTC', 'default_language' => 'en', 'status' => 1]);
$owner = SystemUser::create(['username' => "mgo{$suffix}", 'password' => password_hash($password, PASSWORD_DEFAULT), 'realname' => 'MG Smoke Owner', 'is_super' => 0, 'status' => 1]);
$owner->roles()->sync([$ownerRole->id]);
$ownerRelation = EnterpriseUser::create(['enterprise_id' => $enterprise->id, 'system_user_id' => $owner->id, 'is_owner' => 1, 'status' => 1]);
$merchant = Merchant::create([
    'enterprise_id' => $enterprise->id, 'name' => "Smoke Merchant {$suffix}", 'wallet_mode' => 1, 'callback_url' => 'http://127.0.0.1',
    'secret' => SecretService::encrypt($suffix), 'language_codes' => ['en'], 'default_language' => 'en', 'timezone' => 'UTC', 'timeout_ms' => 1000, 'status' => 1,
]);
    $merchant->update(['mch_id' => (string) id2big((int) $merchant->id)]);
    $trendId = Db::table('mg_hourly_stats')->insertGetId([
        'merchant_id' => $merchant->id, 'timezone' => 'UTC', 'stat_date' => gmdate('Y-m-d'), 'stat_hour' => 0,
        'active_user_count' => 1, 'bet_count' => 1, 'bet_amounts' => json_encode(['USD' => '1.00000000']),
        'converted_currency_code' => 'USD', 'converted_bet_amount' => '1.00000000',
        'create_time' => gmdate('Y-m-d H:i:s'), 'update_time' => gmdate('Y-m-d H:i:s'),
    ]);
$otherMerchant = Merchant::create([
    'enterprise_id' => $otherEnterprise->id, 'name' => "Hidden Merchant {$suffix}", 'wallet_mode' => 1, 'callback_url' => 'http://127.0.0.1',
    'secret' => SecretService::encrypt($suffix), 'language_codes' => ['en'], 'default_language' => 'en', 'timezone' => 'UTC', 'timeout_ms' => 1000, 'status' => 1,
]);
$otherMerchant->update(['mch_id' => (string) id2big((int) $otherMerchant->id)]);
$uniqueBrand = UniqueBrand::create(['code' => "scope_{$suffix}", 'name' => 'Scope Smoke', 'status' => 1]);
$gameBrand = GameBrand::create([
    'platform_code' => "scope_{$suffix}", 'provider_brand_code' => "scope_{$suffix}", 'unique_brand_id' => $uniqueBrand->id,
    'mapping_status' => 2, 'name' => 'Scope Smoke', 'status' => 1,
]);
$game = Game::create([
    'game_code' => "scope_{$suffix}_1", 'brand_id' => $gameBrand->id, 'platform_code' => $gameBrand->platform_code,
    'provider_game_code' => '1', 'name' => 'Visible Game', 'currency_codes' => ['USD'], 'status' => 1,
]);
$hiddenGame = Game::create([
    'game_code' => "scope_{$suffix}_2", 'brand_id' => $gameBrand->id, 'platform_code' => $gameBrand->platform_code,
    'provider_game_code' => '2', 'name' => 'Hidden Game', 'currency_codes' => ['USD'], 'status' => 1,
]);
MerchantGame::create(['merchant_id' => $merchant->id, 'game_id' => $game->id, 'status' => 1, 'merchant_status' => 1]);
MerchantGame::create(['merchant_id' => $otherMerchant->id, 'game_id' => $hiddenGame->id, 'status' => 1, 'merchant_status' => 1]);
$staff = null;

try {
    checkAccount(password_verify($password, $owner->password), '负责人密码未使用 SaiAdmin 兼容哈希保存');
    $token = tokenAccount($owner);

    $enterprises = requestAccount($token, 'GET', '/game/enterprise/index', ['page' => 1, 'limit' => 20]);
    checkAccount($enterprises['status'] === 200 && ($enterprises['body']['code'] ?? 0) === 200, '负责人无法访问企业列表: ' . json_encode($enterprises, JSON_UNESCAPED_UNICODE));
    checkAccount(($enterprises['body']['data']['total'] ?? 0) === 1 && (int) $enterprises['body']['data']['data'][0]['id'] === (int) $enterprise->id, '负责人企业范围错误');

    $merchants = requestAccount($token, 'GET', '/game/merchant/index', ['page' => 1, 'limit' => 20]);
    checkAccount(($merchants['body']['data']['total'] ?? 0) === 1 && (int) $merchants['body']['data']['data'][0]['id'] === (int) $merchant->id, '负责人看到了其他企业商户');
    $ownerContext = requestAccount($token, 'GET', '/game/context');
    checkAccount(($ownerContext['body']['data']['role'] ?? '') === 'enterprise_owner' && count($ownerContext['body']['data']['merchants'] ?? []) === 1, '负责人顶部商户范围错误');
    $overview = requestAccount($token, 'GET', '/game/operations/overview');
    checkAccount(collect($overview['body']['data']['hourly'] ?? [])->contains(fn ($row) => (int) $row['merchant_id'] === (int) $merchant->id), '负责人趋势错误使用平台口径');
    checkAccount(($overview['body']['data']['platform_count'] ?? 0) === 1 && ($overview['body']['data']['game_count'] ?? 0) === 1
        && ($overview['body']['data']['total_game_count'] ?? 0) === 1, '负责人看到了权限外游戏平台或游戏');
    checkAccount(collect($overview['body']['data']['platforms'] ?? [])->every(fn ($row) => $row['platform_code'] === $gameBrand->platform_code), '负责人平台摘要越权');
    $ownerTrial = requestAccount($token, 'POST', '/game/trial', ['game_id' => 0, 'currency' => 'USD']);
    checkAccount(($ownerTrial['body']['code'] ?? 200) !== 200, '企业负责人获得了自营试玩权限');

    $created = requestAccount($token, 'POST', '/game/enterprise/user', [
        'username' => "mgs{$suffix}", 'password' => $password, 'realname' => 'MG Smoke Staff', 'merchant_ids' => [$merchant->id],
    ]);
    checkAccount(($created['body']['code'] ?? 0) === 200, '负责人创建子账号失败: ' . json_encode($created, JSON_UNESCAPED_UNICODE));
    $staff = SystemUser::findOrFail($created['body']['data']['id']);
    checkAccount(password_verify($password, $staff->password), '子账号密码保存错误');
    $staffRelation = EnterpriseUser::where(['enterprise_id' => $enterprise->id, 'system_user_id' => $staff->id, 'is_owner' => 0])->firstOrFail();
    checkAccount($staffRelation->merchants()->whereKey($merchant->id)->exists(), '子账号未绑定指定商户');
    $newPassword = 'MgReset_' . $suffix;
    $passwordUpdate = requestAccount($token, 'PUT', '/game/enterprise/user/password', ['id' => $staffRelation->id, 'password' => $newPassword]);
    checkAccount(($passwordUpdate['body']['code'] ?? 0) === 200 && password_verify($newPassword, $staff->fresh()->password), '负责人修改子账号密码失败');

    $staffToken = tokenAccount($staff);
    $staffMerchants = requestAccount($staffToken, 'GET', '/game/merchant/index', ['page' => 1, 'limit' => 20]);
    checkAccount(($staffMerchants['body']['data']['total'] ?? 0) === 1 && (int) $staffMerchants['body']['data']['data'][0]['id'] === (int) $merchant->id, '子账号商户范围错误');
    $staffContext = requestAccount($staffToken, 'GET', '/game/context');
    checkAccount(($staffContext['body']['data']['role'] ?? '') === 'enterprise_staff' && count($staffContext['body']['data']['merchants'] ?? []) === 1, '子账号顶部商户范围错误');
    $staffOverview = requestAccount($staffToken, 'GET', '/game/operations/overview');
    checkAccount(($staffOverview['body']['data']['game_count'] ?? 0) === 1
        && collect($staffOverview['body']['data']['platforms'] ?? [])->every(fn ($row) => $row['platform_code'] === $gameBrand->platform_code), '子账号看到了权限外游戏数据');
    $billing = requestAccount($staffToken, 'GET', '/game/merchant/billing', ['id' => $merchant->id]);
    checkAccount(($billing['body']['code'] ?? 0) === 200, '子账号不能查看计费方案');
    $billingWrite = requestAccount($staffToken, 'PUT', '/game/merchant/billing', ['id' => $merchant->id, 'billing_mode' => 1]);
    checkAccount(($billingWrite['body']['code'] ?? 200) !== 200, '子账号可以修改计费方案');
    $enterpriseList = requestAccount($staffToken, 'GET', '/game/enterprise/index', ['page' => 1, 'limit' => 20]);
    checkAccount(($enterpriseList['body']['code'] ?? 200) !== 200, '子账号可以访问企业账号管理');
    $forbidden = requestAccount($staffToken, 'GET', '/game/merchant/secret', ['id' => $otherMerchant->id]);
    checkAccount(($forbidden['body']['code'] ?? 200) !== 200, '子账号可读取其他企业密钥');
    $staffTrial = requestAccount($staffToken, 'POST', '/game/trial', ['game_id' => 0, 'currency' => 'USD']);
    checkAccount(($staffTrial['body']['code'] ?? 200) !== 200, '企业子账号获得了自营试玩权限');

    $enterprise->update(['status' => 0]);
    $disabled = requestAccount($token, 'GET', '/game/brands', ['page' => 1, 'limit' => 1]);
    checkAccount(($disabled['body']['code'] ?? 200) !== 200 && str_contains((string) ($disabled['body']['message'] ?? ''), '停用'), '停用企业仍可访问管理端');

    echo "Enterprise account smoke test passed\n";
} finally {
    foreach (array_filter([$owner->id, $staff?->id]) as $userId) {
        UserAuthCache::clearUserAuth($userId);
        Db::table('sa_system_user_role')->where('user_id', $userId)->delete();
        $relationIds = Db::table('mg_enterprise_users')->where('system_user_id', $userId)->pluck('id');
        Db::table('mg_enterprise_user_merchants')->whereIn('enterprise_user_id', $relationIds)->delete();
        Db::table('mg_enterprise_users')->where('system_user_id', $userId)->delete();
        Db::table('sa_system_user')->where('id', $userId)->delete();
    }
    Db::table('mg_merchants')->whereIn('id', [$merchant->id, $otherMerchant->id])->delete();
    Db::table('mg_hourly_stats')->where('id', $trendId)->delete();
    Db::table('mg_merchant_games')->whereIn('game_id', [$game->id, $hiddenGame->id])->delete();
    $game->forceDelete();
    $hiddenGame->forceDelete();
    $gameBrand->forceDelete();
    $uniqueBrand->forceDelete();
    Db::table('mg_enterprises')->whereIn('id', [$enterprise->id, $otherEnterprise->id])->delete();
}
