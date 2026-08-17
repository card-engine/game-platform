<?php

declare(strict_types=1);

namespace app\enum;

enum RedisKey: string
{
    public const EXPIRE_5_SECONDS = 5;
    public const EXPIRE_1_MINUTE = 60;
    public const EXPIRE_10_MINUTES = 600;
    public const EXPIRE_1_HOUR = 3600;
    public const EXPIRE_1_DAY = 86400;
    public const EXPIRE_2_DAYS = 172800;

    /** Locks. */
    case LockConfigsRebuild = 'lock:mg:configs:rebuild'; // string 全局配置缓存重建锁
    case LockUserWallet = 'lock:mg:user_wallet:%d:%d:%s'; // string 玩家资金锁，格式：merchant_id:user_id:currency_code
    case LockExchangeRateSync = 'lock:mg:exchange_rate:sync:%s'; // string 汇率同步锁，格式：rate_date
    case LockStatsRefresh = 'lock:mg:stats:refresh:%s'; // string 统计时间桶刷新锁，格式：bucket_hash

    /** Permanent caches. */
    case ForeverConfigs = 'forever:mg:configs'; // string 启用中的全局配置 JSON

    /** Temporary caches. */
    case TempGoldenGateXToken = 'temp:mg:goldengatex:token'; // string GoldenGateX Bearer Token
    case TempStatsRefresh = 'temp:mg:stats:refresh:%s'; // string 统计时间桶待刷新标记，格式：bucket_hash
    case TempPlatformStatsRebuild = 'temp:mg:platform_stats:rebuild'; // JSON 平台统计重建状态

    public function format(mixed ...$args): string
    {
        return sprintf($this->value, ...$args);
    }
}
