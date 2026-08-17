<?php

namespace app\queue\redis;

use app\enum\RedisKey;
use app\model\Game;
use app\model\GameBrand;
use support\Redis;
use Webman\RedisQueue\Consumer;

class GameBrandCodeRebuild implements Consumer
{
    public string $queue = 'game_brand_code_rebuild';
    public string $connection = 'default';

    public function consume($data): void
    {
        $brandId = (int) ($data['brand_id'] ?? 0);
        $lock = RedisKey::LockGameBrandCode->format($brandId);
        $token = bin2hex(random_bytes(12));
        if (!Redis::set($lock, $token, 'EX', RedisKey::EXPIRE_1_HOUR, 'NX')) throw new \RuntimeException('品牌游戏编码正在生成');

        try {
            $brand = GameBrand::with('uniqueBrand:id,code')->find($brandId);
            $code = strtolower(trim((string) ($brand?->uniqueBrand?->code ?? '')));
            if (!$brand || !$code) return;
            Game::where('brand_id', $brandId)->select('id')->chunkById(500, function ($games) use ($code) {
                foreach ($games as $game) Game::whereKey($game->id)->update(['game_code' => Game::makeCode($code, (int) $game->id)]);
            });
        } finally {
            Redis::eval("if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0", 1, $lock, $token);
        }
    }
}
