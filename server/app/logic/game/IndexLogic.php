<?php

namespace app\logic\game;

use app\model\Game;
use app\model\GameBrand;
use app\model\Merchant;
use app\model\UniqueBrand;
use app\model\User;
use app\enum\RedisKey;
use app\service\game\OpenApiService;
use app\service\mgs\MgsConfigService;
use plugin\saiadmin\basic\eloquent\BaseLogic;
use plugin\saiadmin\exception\ApiException;
use support\Redis as Cache;
use Webman\RedisQueue\Redis;

class IndexLogic extends BaseLogic
{
    public function __construct()
    {
        $this->model = new Game();
    }

    public function brands(array $where): array
    {
        $query = GameBrand::with('uniqueBrand:id,code,name')->withCount('games')
            ->when($where['platform_code'] ?? null, fn ($q, $value) => $q->where('platform_code', $value))
            ->when(($where['mapping_status'] ?? '') !== '', fn ($q) => $q->where('mapping_status', $where['mapping_status']))
            ->when($where['keyword'] ?? null, fn ($q, $value) => $q->whereAny(['provider_brand_code', 'name'], 'like', "%{$value}%"));
        return $this->getList($query);
    }

    public function setBrandMode(int $id, int $isGc): bool
    {
        $brand = GameBrand::findOrFail($id);
        $brand->update(['is_gc' => $isGc ? 1 : 0]);
        foreach ($brand->games()->get() as $game) {
            $codes = $isGc ? ['SC', 'GC'] : array_values(array_diff($game->currency_codes ?: [], ['SC', 'GC']));
            $game->update(['currency_codes' => $codes ?: ['USD']]);
        }
        return true;
    }

    public function uniqueBrands(array $where): array
    {
        $query = UniqueBrand::withCount('providerBrands')
            ->when($where['keyword'] ?? null, fn ($q, $value) => $q->whereAny(['code', 'name'], 'like', "%{$value}%"))
            ->where('status', 1)->orderBy('sort')->orderBy('name');
        return $this->getList($query);
    }

    public function lists(array $where): array
    {
        $query = Game::with('brand:id,name,provider_brand_code,unique_brand_id', 'brand.uniqueBrand:id,code,name')
            ->select('mg_games.*')
            ->selectRaw('CASE WHEN upstream_status = 0 THEN 3 ELSE platform_status END AS status')
            ->when($where['platform_code'] ?? null, fn ($q, $value) => $q->where('platform_code', $value))
            ->when($where['brand_id'] ?? null, fn ($q, $value) => $q->where('brand_id', $value))
            ->when($where['unique_brand_id'] ?? null, fn ($q, $value) => $q->whereHas('brand', fn ($brand) => $brand->where('unique_brand_id', $value)))
            ->when(($where['status'] ?? '') !== '', fn ($q) => (string) $where['status'] === '3' ? $q->where('upstream_status', 0) : $q->where('platform_status', $where['status']))
            ->when($where['keyword'] ?? null, fn ($q, $value) => $q->whereAny(['game_code', 'provider_game_code', 'name'], 'like', "%{$value}%"));
        if ($merchantId = (int) ($where['merchant_id'] ?? 0)) {
            $query->orderByRaw('EXISTS (SELECT 1 FROM mg_merchant_games WHERE merchant_id = ? AND game_id = mg_games.id AND status = 1 AND delete_time IS NULL) DESC', [$merchantId]);
        }
        return $this->getList($query);
    }

    public function trial(int $gameId, string $currency, string $ip): array
    {
        $mchId = (new MgsConfigService())->get('game_platform_mch_id', config('mgs.mch_id'));
        $merchant = Merchant::where(['mch_id' => $mchId, 'status' => 1])->first()
            ?: throw new ApiException('自营商户未初始化，请执行数据库升级');
        $user = User::where(['id' => 1, 'merchant_id' => $merchant->id, 'status' => 1])->first()
            ?: throw new ApiException('系统玩家未初始化，请执行数据库升级');
        return (new OpenApiService())->launch($merchant, [
            'user_id' => $user->merchant_user_id, 'game_id' => (string) id2big($gameId),
            'currency' => $currency, 'language' => $merchant->default_language,
        ], $ip);
    }

    public function mappingImpact(int $brandId, int $uniqueBrandId): array
    {
        return [
            'merchant_count' => Merchant::where('status', 1)->count(),
            'game_count' => Game::where(['brand_id' => $brandId, 'platform_status' => 1])->count(),
        ];
    }

    public function mapBrand(int $brandId, array $data): array
    {
        $lock = RedisKey::LockGameBrandCode->format($brandId);
        $token = bin2hex(random_bytes(12));
        if (!Cache::set($lock, $token, 'EX', RedisKey::EXPIRE_1_MINUTE, 'NX')) throw new ApiException('该品牌正在生成游戏编码，请稍后重试');

        try {
            $result = $this->transaction(function () use ($brandId, $data) {
                $brand = GameBrand::findOrFail($brandId);
                if ($data['unique_brand_id'] ?? null) {
                    $unique = UniqueBrand::where('status', 1)->findOrFail($data['unique_brand_id']);
                } else {
                    $code = strtolower(trim((string) ($data['code'] ?? '')));
                    $name = trim((string) ($data['name'] ?? ''));
                    if ($code === '' || $name === '') throw new ApiException('请填写统一品牌名称和 Code');
                    $unique = UniqueBrand::firstOrCreate(['code' => $code], ['name' => $name, 'status' => 1]);
                }
                $brand->update(['unique_brand_id' => $unique->id, 'mapping_status' => 2, 'mapped_by' => $this->adminInfo['id'], 'mapped_time' => date('Y-m-d H:i:s')]);
                Game::where('brand_id', $brand->id)->update(['game_code' => null]);
                return $unique->only(['id', 'code', 'name']);
            });
        } finally {
            Cache::eval("if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0", 1, $lock, $token);
        }

        Redis::send('game_brand_code_rebuild', ['brand_id' => $brandId]);
        return $result + ['queued' => true];
    }

    public function status(array $ids, int $status): int
    {
        return Game::whereIn('id', $ids)->where('upstream_status', 1)->update(['platform_status' => $status, 'platform_status_reason' => $status ? 'manual_enabled' : 'manual_disabled', 'platform_status_time' => gmdate('Y-m-d H:i:s.v')]);
    }
}
