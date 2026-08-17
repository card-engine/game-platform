<?php

namespace app\service\game;

use app\enum\RedisKey;
use app\model\Config;
use RuntimeException;
use support\Redis;

class ConfigService
{
    public function all(): array
    {
        $value = Redis::get(RedisKey::ForeverConfigs->value);
        return $value === false || $value === null ? $this->rebuild() : json_decode($value, true);
    }

    public function get(string $code, mixed $default = null): mixed
    {
        return $this->all()[$code] ?? $default;
    }

    public function rebuild(): array
    {
        $key = RedisKey::LockConfigsRebuild->value;
        $token = bin2hex(random_bytes(12));
        if (!Redis::set($key, $token, 'EX', RedisKey::EXPIRE_1_MINUTE, 'NX')) {
            usleep(50000);
            $cached = Redis::get(RedisKey::ForeverConfigs->value);
            if ($cached !== false && $cached !== null) return json_decode($cached, true);
            throw new RuntimeException('全局配置缓存重建中');
        }

        try {
            $configs = Config::where('status', 1)->get(['code', 'value'])->mapWithKeys(fn ($config) => [$config->code => $config->value])->all();
            Redis::set(RedisKey::ForeverConfigs->value, json_encode($configs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $configs;
        } finally {
            Redis::eval("if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0", 1, $key, $token);
        }
    }
}
