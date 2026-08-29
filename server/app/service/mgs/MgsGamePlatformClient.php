<?php

namespace app\service\mgs;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\StreamHandler;
use RuntimeException;

class MgsGamePlatformClient
{
    public function post(string $path, array $params = []): array
    {
        $config = config('mgs');
        $params['mch_id'] = (new MgsConfigService())->get('game_platform_mch_id', $config['mch_id']);
        $params['timestamp'] = time();
        $params['sign'] = game_platform_sign($params, $config['secret']);
        try {
            $response = (new Client([
                'handler' => new StreamHandler(), 'base_uri' => rtrim($config['platform_url'], '/') . '/',
                'timeout' => 15, 'connect_timeout' => 3, 'http_errors' => false,
            ]))->post(ltrim($path, '/'), ['json' => $params]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('游戏平台请求失败：' . $e->getMessage());
        }
        $result = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() >= 500 || !is_array($result) || (int) ($result['code'] ?? 0) !== 200) {
            throw new RuntimeException((string) ($result['message'] ?? '游戏平台返回错误'));
        }
        return is_array($result['data'] ?? null) ? $result['data'] : [];
    }

    public function games(): array
    {
        $items = [];
        $total = null;
        for ($page = 1; ; $page++) {
            $result = $this->post('/open_api/games', ['page' => $page, 'limit' => 100]);
            $rows = (array) ($result['list'] ?? []);
            $total ??= (int) ($result['total'] ?? 0);
            foreach ($rows as $row) if (($id = (string) ($row['game_id'] ?? '')) !== '') $items[$id] = $row;
            if (!$rows || count($items) >= $total) break;
        }
        if ($total === null || count($items) !== $total) throw new RuntimeException('游戏平台游戏目录分页不完整');
        return array_values($items);
    }
}
