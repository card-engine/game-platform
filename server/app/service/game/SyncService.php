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
        $data = AdapterRegistry::get($platform)->sync($config);
        $defaultCurrency = strtoupper((string) ($config['default_currency'] ?? $config['currency'] ?? 'USD'));
        $now = date('Y-m-d H:i:s');

        return Db::transaction(function () use ($platform, $data, $defaultCurrency, $now) {
            $brands = [];
            foreach ($data['brands'] as $item) {
                $brand = GameBrand::withTrashed()->updateOrCreate(
                    ['platform_code' => $platform, 'provider_brand_code' => $item['provider_brand_code']],
                    ['name' => $item['name'], 'names' => $item['names'] ?? null, 'logo_url' => $item['logo_url'] ?? null, 'extra' => $item['extra'] ?? null, 'status' => 1, 'last_sync_time' => $now, 'delete_time' => null],
                );
                if ($brand->wasRecentlyCreated && isset($item['is_gc'])) $brand->update(['is_gc' => (int) $item['is_gc']]);
                if (!$brand->unique_brand_id) {
                    $unique = UniqueBrand::firstOrCreate(
                        ['code' => strtolower(trim($item['provider_brand_code']))],
                        ['name' => $item['name'], 'names' => $item['names'] ?? null, 'logo_url' => $item['logo_url'] ?? null, 'status' => 1],
                    );
                    $brand->update(['unique_brand_id' => $unique->id, 'mapping_status' => 1, 'mapped_by' => null, 'mapped_time' => $now]);
                }
                $brands[$item['provider_brand_code']] = $brand;
            }

            $seen = [];
            foreach ($data['games'] as $item) {
                $brand = $brands[$item['brand_code']];
                unset($item['brand_code']);
                $codes = (bool) $brand->is_gc ? ['SC', 'GC'] : array_values(array_diff((array) ($item['currency_codes'] ?? []), ['SC', 'GC']));
                $item['currency_codes'] = $codes ?: [$defaultCurrency === 'SC' || $defaultCurrency === 'GC' ? 'USD' : $defaultCurrency];
                $game = Game::withTrashed()->updateOrCreate(
                    ['platform_code' => $platform, 'brand_id' => $brand->id, 'provider_game_code' => $item['provider_game_code']],
                    $item + ['status' => 1, 'last_sync_time' => $now, 'delete_time' => null],
                );
                if (!$game->game_code) {
                    $game->game_code = $platform . '_' . id2big((int) $game->id);
                    $game->save();
                }
                $seen[] = $game->id;
            }
            if ($data['complete'] ?? true) {
                Game::where('platform_code', $platform)->when($seen, fn ($query) => $query->whereNotIn('id', $seen))->update(['status' => 3]);
                GameBrand::where('platform_code', $platform)->where('last_sync_time', '<', $now)->update(['status' => 2]);
            }

            return ['platform' => $platform, 'brands' => count($brands), 'games' => count($seen), 'errors' => $data['errors'] ?? []];
        });
    }
}
