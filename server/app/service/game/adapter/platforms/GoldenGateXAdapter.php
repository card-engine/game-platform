<?php

namespace app\service\game\adapter\platforms;

use app\enum\RedisKey;
use app\model\Game;
use app\service\game\adapter\AbstractAdapter;
use GuzzleHttp\Client;
use RuntimeException;
use support\Redis;

class GoldenGateXAdapter extends AbstractAdapter
{
    public function verify(array $config, array $headers, string $body): bool
    {
        return hash_equals('Basic ' . base64_encode($config['client_id'] . ':' . $config['client_secret']), (string) ($headers['authorization'] ?? ''));
    }

    public function parse(array $config, string $action, array $payload): array
    {
        if ($action === 'balance') return ['operation' => 'balance', 'player_id' => (string) ($payload['userCode'] ?? '')];
        $amount = (string) ($payload['amount'] ?? '0');
        $common = [
            'player_id' => (string) ($payload['userCode'] ?? ''), 'game_code' => (string) ($payload['gameCode'] ?? ''),
            'brand_code' => (string) ($payload['vendorCode'] ?? ''), 'parent_round_id' => (string) ($payload['historyId'] ?? ''),
            'round_id' => (string) ($payload['roundId'] ?? ''), 'source_no' => (string) ($payload['transactionCode'] ?? ''),
        ];
        if (!empty($payload['isCanceled'])) {
            $originalAction = str_starts_with($amount, '-') ? 'debit' : 'credit';
            return ['operations' => [$common + ['action' => "rollback_{$originalAction}", 'original_action' => $originalAction, 'original_source_no' => $common['source_no'], 'amount' => '0', 'finished' => (bool) ($payload['isFinished'] ?? false)]]];
        }
        if (str_starts_with($amount, '-')) return ['operations' => [$common + ['action' => 'debit', 'amount' => substr($amount, 1), 'finished' => (bool) ($payload['isFinished'] ?? false)]]];
        return ['operations' => [$common + ['action' => 'credit', 'amount' => $amount, 'finished' => (bool) ($payload['isFinished'] ?? false)]]];
    }

    public function formatResponse(array $result): array
    {
        if (($result['status'] ?? 0) === 2) return ['success' => true, 'message' => (float) $this->balance($result), 'errorCode' => 0];
        $code = match ((int) ($result['code'] ?? 0)) { 1001 => 4, 1004 => 5, 1003 => 400, default => 500 };
        return ['success' => false, 'message' => (string) ($result['message'] ?? 'internal error'), 'errorCode' => $code];
    }

    public function sync(array $config): array
    {
        $vendors = $this->request($config, 'GET', '/vendors/list');
        $brands = [];
        $games = [];
        $errors = [];
        foreach ($vendors as $vendor) {
            $code = (string) $vendor['vendorCode'];
            $brands[$code] = ['provider_brand_code' => $code, 'name' => (string) $vendor['name'], 'extra' => ['type' => $vendor['type'] ?? null]];
            try {
                $list = $this->request($config, 'POST', '/games/list', ['vendorCode' => $code, 'language' => 'en']);
            } catch (RuntimeException $e) {
                $errors[$code] = $e->getMessage();
                continue;
            }
            foreach ($list as $game) {
                $games[$code . ':' . $game['gameCode']] = $this->normalize($config, $code, $game);
            }
        }
        foreach ($this->request($config, 'GET', '/games/mini/list') as $game) {
            $code = (string) $game['vendorCode'];
            $brands[$code] ??= ['provider_brand_code' => $code, 'name' => (string) $game['gameName']];
            $games[$code . ':' . $game['gameCode']] = $this->normalize($config, $code, $game);
        }
        return ['brands' => array_values($brands), 'games' => array_values($games), 'complete' => $errors === [], 'errors' => $errors];
    }

    public function launch(array $config, string $playerId, Game $game, array $options): array
    {
        $language = explode('-', strtolower(str_replace('_', '-', $options['lang'])))[0];
        $params = [
            'vendorCode' => $game->brand->provider_brand_code,
            'gameCode' => $game->provider_game_code,
            'userCode' => $playerId,
            'language' => $language,
        ];
        if (!empty($options['back_url'])) $params['lobbyUrl'] = $options['back_url'];
        return ['game_url' => (string) $this->request($config, 'POST', '/game/launch-url', $params)];
    }

    public function getRtp(array $config, array $playerIds, Game $game, string $currency): array
    {
        $result = [];
        foreach ($playerIds as $playerId) {
            $result[] = ['player_id' => $playerId, 'rtp' => (string) $this->request($config, 'POST', '/game/user/get-rtp', [
                'vendorCode' => $game->brand->provider_brand_code, 'userCode' => $playerId,
            ])];
        }
        return $result;
    }

    public function setRtp(array $config, array $playerIds, Game $game, string $currency, string $rtp): array
    {
        if (!ctype_digit($rtp) || (int) $rtp < 30 || (int) $rtp > 99) throw new RuntimeException('RTP 档位无效');
        foreach ($playerIds as $playerId) {
            $this->request($config, 'POST', '/game/user/set-rtp', [
                'vendorCode' => $game->brand->provider_brand_code, 'userCode' => $playerId, 'rtp' => (int) $rtp,
            ]);
        }
        return ['player_ids' => $playerIds, 'rtp' => $rtp];
    }

    private function normalize(array $config, string $brand, array $game): array
    {
        return [
            'brand_code' => $brand,
            'provider_game_code' => (string) $game['gameCode'],
            'name' => (string) $game['gameName'],
            'names' => null,
            'icon_url' => (string) ($game['thumbnail'] ?? ''),
            'currency_codes' => [strtoupper($config['currency'])],
            'support_demo' => 0,
            'support_rtp' => 1,
            'rtp_options' => null,
            'extra' => ['provider' => $game['provider'] ?? null, 'under_maintenance' => $game['underMaintenance'] ?? false],
        ];
    }

    private function request(array $config, string $method, string $path, array $params = []): mixed
    {
        $client = new Client(['base_uri' => rtrim($config['url'], '/') . '/', 'timeout' => 30]);
        $response = $client->request($method, ltrim($path, '/'), [
            'headers' => ['Authorization' => 'Bearer ' . $this->token($config), 'Accept' => 'application/json'],
            $method === 'GET' ? 'query' : 'json' => $params,
        ]);
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!($body['success'] ?? false) || (int) ($body['errorCode'] ?? -1) !== 0) {
            throw new RuntimeException("GoldenGateX {$path} 请求失败: " . (string) ($body['message'] ?? json_encode($body, JSON_UNESCAPED_UNICODE)));
        }
        return $body['message'] ?? null;
    }

    private function token(array $config): string
    {
        $key = RedisKey::TempGoldenGateXToken->value;
        if ($token = Redis::get($key)) return $token;
        $response = (new Client(['base_uri' => rtrim($config['url'], '/') . '/', 'timeout' => 30]))->post('auth/createtoken', [
            'json' => ['clientId' => $config['client_id'], 'clientSecret' => $config['client_secret']],
        ]);
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (empty($data['token'])) throw new RuntimeException('GoldenGateX Token 获取失败');
        Redis::setex($key, max(60, (int) $data['expiration'] - time() - 60), $data['token']);
        return $data['token'];
    }
}
