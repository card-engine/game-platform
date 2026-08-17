<?php

use app\enum\RedisKey;
use app\service\game\ConfigService;
use app\service\game\report\ExchangeRateService;
use support\Db;
use support\Redis;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

$tables = Db::table('information_schema.tables')->selectRaw('TABLE_NAME AS name')->where('table_schema', config('database.connections.mysql.database'))->pluck('name')->all();
if (in_array('mg_trade_routes', $tables, true) || in_array('mg_trade_indexes', $tables, true)) throw new RuntimeException('仍存在全局交易路由或索引表');

$configs = (new ConfigService())->rebuild();
$timezone = $configs['platform_timezone'] ?? '';
$currency = $configs['platform_currency_code'] ?? '';
if (!in_array($timezone, DateTimeZone::listIdentifiers(), true) || !preg_match('/^[A-Z]{3,16}$/', $currency)) throw new RuntimeException('全局配置值错误');
if (!Redis::get(RedisKey::ForeverConfigs->value)) throw new RuntimeException('全局配置未写入永久缓存');

$conversion = (new ExchangeRateService())->conversion(gmdate('Y-m-d'), 'USD', 'USDT');
if (!$conversion || !$conversion['id'] || bccomp($conversion['value'], '0', 18) <= 0) throw new RuntimeException('汇率快照换算错误');

$missingComments = Db::table('information_schema.columns')
    ->where('table_schema', config('database.connections.mysql.database'))
    ->where('table_name', 'like', 'mg\_%')
    ->whereNotIn('column_name', ['id', 'create_time', 'update_time', 'delete_time', 'created_by', 'updated_by'])
    ->where(fn ($query) => $query->whereNull('column_comment')->orWhere('column_comment', ''))
    ->count();
if ($missingComments) throw new RuntimeException("存在 {$missingComments} 个未注释业务字段");
if (!Db::table('mg_daily_stats')->exists() || !Db::table('mg_hourly_stats')->exists() || !Db::table('mg_monthly_stats')->exists()) throw new RuntimeException('统计测试数据不完整');

echo "Architecture smoke test passed\n";
