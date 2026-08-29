<?php

namespace app\service\mgs;

use app\enum\RedisKey;
use app\model\mgs\Brand;
use app\model\mgs\Game;
use RuntimeException;
use support\Db;
use support\Redis;

class MgsSyncService
{
    public function sync(): array
    {
        $token = bin2hex(random_bytes(12));
        if (!Redis::set(RedisKey::LockMgsSync->value, $token, 'EX', RedisKey::EXPIRE_10_MINUTES, 'NX')) throw new RuntimeException('MGS 游戏同步正在执行');
        try {
            $items = (new MgsGamePlatformClient())->games();
            $seen = [];
            $now = gmdate('Y-m-d H:i:s.v');
            Db::transaction(function () use ($items, &$seen, $now) {
                foreach ($items as $item) {
                    $platformGameId = (string) ($item['game_id'] ?? '');
                    $brandCode = strtolower(trim((string) ($item['brand_code'] ?? '')));
                    if ($platformGameId === '' || $brandCode === '') continue;
                    $seen[] = $platformGameId;
                    $brand = Brand::withTrashed()->updateOrCreate(
                        ['platform' => 'mgames', 'platform_brand_code' => $brandCode],
                        ['name' => (string) ($item['brand_name'] ?? $brandCode), 'last_sync_time' => $now, 'delete_time' => null],
                    );
                    $game = Game::withTrashed()->updateOrCreate(
                        ['platform' => 'mgames', 'platform_game_id' => $platformGameId],
                        [
                            'brand_id' => $brand->id, 'platform_brand_code' => $brandCode,
                            'platform_game_code' => (string) ($item['game_code'] ?? $platformGameId),
                            'real_game_code' => (string) ($item['game_code'] ?? $platformGameId),
                            'name' => (string) ($item['name'] ?? $platformGameId), 'icon_url' => $item['icon_url'] ?? null,
                            'game_type' => $item['game_type'] ?? null, 'currency_codes' => $item['currencies'] ?? [],
                            'support_rtp' => (int) ($item['support_rtp'] ?? 0), 'rtp_options' => $item['rtp_options'] ?? null,
                            'upstream_status' => (int) ($item['upstream_status'] ?? 0), 'platform_status' => (int) ($item['platform_status'] ?? 0),
                            'merchant_status' => (int) ($item['merchant_status'] ?? 1), 'unavailable_reason' => $item['unavailable_reason'] ?? null,
                            'platform_status_time' => $item['platform_status_time'] ?? null, 'upstream_status_time' => $item['upstream_status_time'] ?? null,
                            'last_sync_time' => $now, 'delete_time' => null,
                        ],
                    );
                    if ($game->wasRecentlyCreated) $game->update(['rate_value' => (string) (new MgsConfigService())->get('platform_fee_rate', '0.0300000000')]);
                }
                Game::where('platform', 'mgames')->when($seen, fn ($query) => $query->whereNotIn('platform_game_id', $seen))
                    ->update(['upstream_status' => 0, 'platform_status' => 0, 'merchant_status' => 0, 'unavailable_reason' => 'upstream_unavailable', 'upstream_status_time' => $now, 'platform_status_time' => $now, 'update_time' => $now]);
            });
            return ['count' => count($seen), 'synced_time' => gmdate('Y-m-d H:i:s.v')];
        } finally {
            Redis::eval("if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0", 1, RedisKey::LockMgsSync->value, $token);
        }
    }
}
