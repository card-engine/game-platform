<?php

namespace app\service\game;

use app\model\Game;
use app\model\GameBrand;
use app\model\UniqueBrand;
use app\service\game\adapter\AdapterRegistry;
use support\Db;

class SyncService
{
    public function sync(string $platform): array
    {
        $platform = strtolower($platform);
        $config = AdapterRegistry::config($platform);
        if (!($config['is_open'] ?? true)) return ['platform' => $platform, 'brands' => 0, 'games' => 0, 'errors' => ['_' => '游戏平台已关闭']];
        $data = AdapterRegistry::get($platform)->sync($config);
        $defaultCurrency = strtoupper((string) ($config['default_currency'] ?? $config['currency'] ?? 'USD'));
        $now = gmdate('Y-m-d H:i:s.v');

        return Db::transaction(function () use ($platform, $data, $defaultCurrency, $now) {
            $brands = [];
            foreach ($data['brands'] as $item) {
                $brand = GameBrand::withTrashed()->updateOrCreate(
                    ['platform_code' => $platform, 'provider_brand_code' => $item['provider_brand_code']],
                    ['name' => $item['name'], 'names' => $item['names'] ?? null, 'logo_url' => $item['logo_url'] ?? null, 'extra' => $item['extra'] ?? null, 'status' => 1, 'last_sync_time' => $now, 'delete_time' => null],
                );
                if ($brand->wasRecentlyCreated && isset($item['is_gc'])) $brand->update(['is_gc' => (int) $item['is_gc']]);
                if (!$brand->unique_brand_id) {
                    $unique = UniqueBrand::where('status', 1)->where('code', strtolower(trim($item['provider_brand_code'])))->first();
                    $brand->update($unique
                        ? ['unique_brand_id' => $unique->id, 'mapping_status' => 1, 'mapped_by' => null, 'mapped_time' => $now]
                        : ['mapping_status' => 0, 'mapped_by' => null, 'mapped_time' => null]);
                }
                $brands[$item['provider_brand_code']] = $brand;
            }
            $uniqueCodes = UniqueBrand::whereKey(array_filter(array_map(fn ($brand) => $brand->unique_brand_id, $brands)))->pluck('code', 'id');

            $seen = [];
            foreach ($data['games'] as $item) {
                $brand = $brands[$item['brand_code']];
                unset($item['brand_code']);
                $codes = (bool) $brand->is_gc ? ['SC', 'GC'] : array_values(array_diff((array) ($item['currency_codes'] ?? []), ['SC', 'GC']));
                $item['currency_codes'] = $codes ?: [$defaultCurrency === 'SC' || $defaultCurrency === 'GC' ? 'USD' : $defaultCurrency];
                $keys = ['platform_code' => $platform, 'brand_id' => $brand->id, 'provider_game_code' => $item['provider_game_code']];
                $game = Game::withTrashed()->where($keys)->first();
                $values = $item + ['upstream_status' => 1, 'upstream_status_time' => $now, 'last_sync_time' => $now, 'delete_time' => null];
                if ($game) {
                    $game->update($values);
                } else {
                    $game = Game::create($keys + $values + ['platform_status' => 1, 'platform_status_reason' => 'upstream_available', 'platform_status_time' => $now]);
                }
                $brandCode = $uniqueCodes->get($brand->unique_brand_id);
                $gameCode = $brandCode ? Game::makeCode($brandCode, (int) $game->id) : null;
                if ($game->game_code !== $gameCode) $game->update(['game_code' => $gameCode]);
                $seen[] = $game->id;
            }
            if ($data['complete'] ?? true) {
                $stale = Game::where('platform_code', $platform)->when($seen, fn ($query) => $query->whereNotIn('id', $seen))->get(['id', 'platform_status']);
                foreach ($stale as $game) {
                    $fields = ['upstream_status' => 0, 'upstream_status_time' => $now];
                    if ((int) $game->platform_status === 1) $fields += ['platform_status' => 0, 'platform_status_reason' => 'upstream_unavailable', 'platform_status_time' => $now];
                    $game->update($fields);
                    \app\model\MerchantGame::where('game_id', $game->id)->where('status', 1)->update(['status' => 0, 'status_reason' => 'upstream_unavailable', 'status_time' => $now]);
                }
                GameBrand::where('platform_code', $platform)->where('last_sync_time', '<', $now)->update(['status' => 2]);
            }

            return ['platform' => $platform, 'brands' => count($brands), 'games' => count($seen), 'errors' => $data['errors'] ?? []];
        });
    }
}
