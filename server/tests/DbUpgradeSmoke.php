<?php

use app\command\DbUpgradeCommand;
use app\service\game\ConfigService;
use Symfony\Component\Console\Tester\CommandTester;
use Webman\Config;
use Webman\Database\Initializer;

putenv('MGS_GAME_PLATFORM_SECRET=Db-Upgrade-Mgs-Secret');
putenv('MGS_GAME_PLATFORM_CALLBACK_URL=http://127.0.0.1:8787/api/mgames');
putenv('MGS_DEFAULT_CURRENCY=USD');
putenv('MGS_SYSTEM_BALANCE=1000000');
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';
$system = require dirname(__DIR__) . '/database/system.php';

function checkUpgrade(bool $result, string $message): void
{
    if (!$result) throw new RuntimeException($message);
}

function useUpgradeDatabase(string $database): void
{
    putenv("DB_NAME={$database}");
    $_ENV['DB_NAME'] = $_SERVER['DB_NAME'] = $database;
    Config::clear();
    support\App::loadAllConfig(['route']);
    $property = new ReflectionProperty(Initializer::class, 'initialized');
    $property->setValue(null, false);
    Initializer::init(config('database', []));
}

$original = config('database.connections.mysql');
$database = '__mg_upgrade_test_' . getmypid();
checkUpgrade((bool) preg_match('/^__mg_upgrade_test_\d+$/', $database), '测试数据库名无效');
$pdo = new PDO(
    "mysql:host={$original['host']};port={$original['port']};dbname={$original['database']};charset=utf8mb4",
    $original['username'],
    $original['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

try {
    $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
    $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    putenv('INITIAL_ADMIN_PASSWORD=Admin-Test-123!');
    putenv('INITIAL_GAME_ADMIN_PASSWORD=Game-Test-123!');
    $_ENV['INITIAL_ADMIN_PASSWORD'] = $_SERVER['INITIAL_ADMIN_PASSWORD'] = 'Admin-Test-123!';
    $_ENV['INITIAL_GAME_ADMIN_PASSWORD'] = $_SERVER['INITIAL_GAME_ADMIN_PASSWORD'] = 'Game-Test-123!';
    useUpgradeDatabase($database);

    $upgrade = new CommandTester(new DbUpgradeCommand());
    checkUpgrade($upgrade->execute([]) === 0, $upgrade->getDisplay());
    $tableCount = support\Db::table('information_schema.tables')->where('table_schema', $database)->count();
    preg_match_all('/^CREATE TABLE `/m', file_get_contents(dirname(__DIR__) . '/database/schema.sql'), $schemaTables);
    checkUpgrade($tableCount === count($schemaTables[0]), "空库结构未完整创建：{$tableCount}");
    checkUpgrade(support\Db::table('sa_system_menu')->whereNull('delete_time')->count() === count($system['menus']), '菜单未完整创建');
    checkUpgrade(support\Db::table('sa_system_role')->whereNull('delete_time')->count() === count($system['roles']), '内置角色未完整创建');
    checkUpgrade(support\Db::table('sa_system_user')->whereIn('username', ['admin', 'game_admin'])->count() === 2, '内置管理员未创建');
    checkUpgrade(password_verify('Admin-Test-123!', support\Db::table('sa_system_user')->where('username', 'admin')->value('password')), '初始密码写入错误');
    $mgsUser = (array) support\Db::table('mgs_users')->where('id', 1)->first();
    checkUpgrade($mgsUser['user_no'] === 'system' && (int) $mgsUser['status'] === 1, 'MGS 系统用户未创建');
    $gameUser = (array) support\Db::table('mg_users')->where('id', 1)->first();
    checkUpgrade((int) $gameUser['merchant_id'] === 1 && $gameUser['merchant_user_id'] === $mgsUser['user_no']
        && $gameUser['nickname'] === '系统玩家' && (int) $gameUser['status'] === 1, '游戏平台系统玩家未与 MGS 系统用户对应');
    checkUpgrade(support\Db::table('mgs_wallets')->where(['user_id' => 1, 'currency_code' => 'USD'])->value('balance') === '1000000.00000000', 'MGS 系统钱包未创建');
    $mgsMchId = json_decode(support\Db::table('mgs_configs')->where('code', 'game_platform_mch_id')->value('value'), true);
    checkUpgrade($mgsMchId !== '' && support\Db::table('mg_merchants')->where(['id' => 1, 'mch_id' => $mgsMchId, 'name' => 'MGS 自营平台'])->exists(), 'MGS 首条真实商户参数未创建');
    checkUpgrade(support\Db::table('mg_merchants')->where('mch_id', $mgsMchId)->value('callback_url') === 'http://127.0.0.1:8787/api/mgames', 'MGS 商户回调地址未初始化');

    support\Db::statement('CREATE TABLE `mg_bets_2701` LIKE `mg_bets_template`');
    support\Db::statement('ALTER TABLE `mg_bets_2701` DROP COLUMN `settled_time`, DROP INDEX `idx_status_time`, ADD INDEX `idx_status_time` (`status`)');
    support\Db::statement('ALTER TABLE `mg_configs` MODIFY COLUMN `name` varchar(101) NOT NULL COMMENT \'错误名称\', ADD COLUMN `manual_note` varchar(20) NULL');
    support\Db::table('mg_configs')->where('code', 'platform_timezone')->update(['value' => json_encode('Pacific/Auckland')]);
    support\Db::table('mgs_configs')->where('code', 'game_platform_mch_id')->update(['value' => json_encode('old-mch')]);
    support\Db::table('sa_tool_crontab')->where('name', 'MG 汇率同步')->update(['status' => 2]);
    support\Db::table('sa_system_menu')->where('code', 'MgDashboard')->update(['name' => '错误菜单名']);
    support\Db::table('mg_users')->where('id', 1)->update(['merchant_id' => 99, 'nickname' => '错误玩家', 'status' => 0]);
    support\Db::table('mgs_wallets')->where(['user_id' => 1, 'currency_code' => 'USD'])->update(['balance' => '123.00000000']);
    $ownerRoleId = support\Db::table('sa_system_role')->where('code', 'enterprise_owner')->value('id');
    support\Db::table('sa_system_role')->where('id', $ownerRoleId)->update(['name' => '错误角色名']);
    support\Db::table('sa_system_role_menu')->where('role_id', $ownerRoleId)->limit(1)->delete();

    $dryRun = new CommandTester(new DbUpgradeCommand());
    checkUpgrade($dryRun->execute(['--dry-run' => true]) === 0, $dryRun->getDisplay());
    checkUpgrade(str_contains($dryRun->getDisplay(), 'MODIFY COLUMN: mg_configs.name'), '预检未发现字段定义差异');
    checkUpgrade(str_contains($dryRun->getDisplay(), 'ADD COLUMN: mg_bets_2701.settled_time'), '预检未发现月表字段差异');
    checkUpgrade(str_contains($dryRun->getDisplay(), 'REPLACE INDEX: mg_bets_2701.idx_status_time'), '预检未发现月表索引差异');

    $upgrade = new CommandTester(new DbUpgradeCommand());
    checkUpgrade($upgrade->execute([]) === 0, $upgrade->getDisplay());
    checkUpgrade(support\Db::connection()->getSchemaBuilder()->hasColumn('mg_bets_2701', 'settled_time'), '月表字段未补齐');
    checkUpgrade(support\Db::table('information_schema.statistics')->where('table_schema', $database)->where('table_name', 'mg_bets_2701')->where('index_name', 'idx_status_time')->exists(), '月表索引未补齐');
    checkUpgrade(support\Db::connection()->getSchemaBuilder()->hasColumn('mg_configs', 'manual_note'), '额外字段被错误删除');
    checkUpgrade(json_decode(support\Db::table('mg_configs')->where('code', 'platform_timezone')->value('value'), true) === 'Pacific/Auckland', '后台配置值被覆盖');
    $currentMgsMchId = json_decode(support\Db::table('mgs_configs')->where('code', 'game_platform_mch_id')->value('value'), true);
    checkUpgrade($currentMgsMchId === $mgsMchId, "自营商户编号未恢复到真实商户：{$mgsMchId} != {$currentMgsMchId}");
    checkUpgrade((int) support\Db::table('sa_tool_crontab')->where('name', 'MG 汇率同步')->value('status') === 2, '定时任务启停状态被覆盖');
    checkUpgrade(support\Db::table('sa_system_menu')->where('code', 'MgDashboard')->value('name') === '运营看板', '菜单定义未更新');
    $gameUser = (array) support\Db::table('mg_users')->where('id', 1)->first();
    checkUpgrade((int) $gameUser['merchant_id'] === 1 && $gameUser['merchant_user_id'] === $mgsUser['user_no']
        && $gameUser['nickname'] === '系统玩家' && (int) $gameUser['status'] === 1, '游戏平台系统玩家对应关系未恢复');
    checkUpgrade(support\Db::table('mgs_wallets')->where(['user_id' => 1, 'currency_code' => 'USD'])->value('balance') === '123.00000000', '重复升级重置了 MGS 钱包余额');
    checkUpgrade(support\Db::table('sa_system_role')->where('id', $ownerRoleId)->value('name') === '企业负责人', '内置角色未更新');
    checkUpgrade(support\Db::table('sa_system_role_menu')->where('role_id', $ownerRoleId)->count() === count($system['roles']['enterprise_owner']['menus']), '内置角色权限未精确同步');

    $final = new CommandTester(new DbUpgradeCommand());
    checkUpgrade($final->execute(['--dry-run' => true]) === 0, $final->getDisplay());
    checkUpgrade(str_contains($final->getDisplay(), '预计变更：0'), '重复执行仍存在结构差异');
    checkUpgrade(!support\Db::table('information_schema.tables')->where('table_schema', $database)->where('table_name', 'like', '\\_\\_mg\\_schema\\_%')->exists(), '临时对比表未清理');
    echo "DB upgrade smoke test passed\n";
} finally {
    support\Db::statement("USE `{$original['database']}`");
    $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
    putenv('DB_NAME=' . $original['database']);
    $_ENV['DB_NAME'] = $_SERVER['DB_NAME'] = $original['database'];
    Config::clear();
    support\App::loadAllConfig(['route']);
    $property = new ReflectionProperty(Initializer::class, 'initialized');
    $property->setValue(null, false);
    Initializer::init(config('database', []));
    (new ConfigService())->rebuild();
}
