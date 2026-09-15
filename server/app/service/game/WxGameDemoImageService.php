<?php

namespace app\service\game;

use app\model\Game;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\StreamHandler;

class WxGameDemoImageService
{
    public function sync(array $config): array
    {
        $demo = $config['demo'] ?? [];
        if (!($demo['enabled'] ?? false)) return ['matched' => 0, 'downloaded' => 0, 'skipped' => 0, 'failed' => 0];
        $games = Game::with('brand')->where('platform_code', 'wxgame')->get();
        $client = new Client(['handler' => new StreamHandler(), 'base_uri' => $demo['url'], 'connect_timeout' => 5, 'timeout' => 20]);
        $matched = $downloaded = $skipped = $failed = 0;
        $lists = [];
        $types = ['slot', 'table', 'fish', 'evo', 'poker'];
        foreach ($games as $game) {
            $brand = $game->brand->provider_brand_code;
            foreach ($types as $type) {
                $key = $brand . '|' . $type;
                if (array_key_exists($key, $lists)) continue;
                try {
                    $response = $client->post('/api/game_list', ['json' => ['appId' => $demo['app_id'], 'password' => $demo['password'], 'gameBrand' => $brand, 'gameType' => $type]]);
                    $lists[$key] = collect(json_decode((string) $response->getBody(), true)['data'] ?? [])->keyBy(fn ($item) => (string) ($item['gameId'] ?? ''));
                } catch (\Throwable) {
                    $lists[$key] = collect();
                }
            }
            $item = collect($types)->map(fn ($type) => $lists[$brand . '|' . $type]->get((string) $game->provider_game_code))->filter()->first();
            if (!$item) continue;
            $matched++;
            $extra = $game->extra ?: [];
            $extra['game_type'] = strtolower((string) ($item['gameType'] ?? data_get($extra, 'game_type')));
            if ($extra !== ($game->extra ?: [])) $game->update(['extra' => $extra]);
            if (empty($item['gameIcon'])) continue;
            $origin = (string) $item['gameIcon'];
            if ($game->origin_icon_url === $origin && $game->icon_url) { $skipped++; continue; }
            try {
                $image = $client->get($origin, ['http_errors' => false]);
                if ($image->getStatusCode() !== 200) throw new \RuntimeException('图片下载失败');
                $extension = strtolower(pathinfo(parse_url($origin, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION)) ?: 'png';
                $path = "games/wxgame/{$game->brand->provider_brand_code}/{$game->provider_game_code}.{$extension}";
                $file = public_path('storage/' . $path);
                is_dir(dirname($file)) || mkdir(dirname($file), 0755, true);
                file_put_contents($file, (string) $image->getBody());
                $game->update(['origin_icon_url' => $origin, 'icon_url' => '/storage/' . $path]);
                $downloaded++;
            } catch (\Throwable) {
                $failed++;
            }
        }
        return compact('matched', 'downloaded', 'skipped', 'failed');
    }
}
