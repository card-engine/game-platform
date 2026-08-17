<?php

namespace app\service\game\adapter\platforms;

use app\model\Game;
use app\service\game\adapter\AbstractAdapter;
use GuzzleHttp\Client;
use RuntimeException;

class WxGameAdapter extends AbstractAdapter
{
    private const RTP = ['50', '65', '75', '85', '90', '95', '97', '100', '150'];

    public function sync(array $config): array
    {
        $list = $this->request($config, (string) array_key_first($config['accounts']), '/v1/api/get_game_list', []);
        $list = $list['gameList'] ?? $list;
        $brands = [];
        $games = [];
        foreach ($list as $item) {
            $brand = trim((string) ($item['gameBrand'] ?? ''));
            $gameId = trim((string) ($item['gameId'] ?? ''));
            if ($brand === '' || $gameId === '') continue;
            $brands[$brand] = ['provider_brand_code' => $brand, 'name' => $brand];
            $games[] = [
                'brand_code' => $brand,
                'provider_game_code' => $gameId,
                'name' => (string) ($item['gameFullName'] ?? $gameId),
                'names' => null,
                'icon_url' => null,
                'currency_codes' => array_map('strtoupper', array_keys($config['accounts'])),
                'support_demo' => 0,
                'support_rtp' => 1,
                'rtp_options' => self::RTP,
                'extra' => ['game_type' => $item['gameType'] ?? null],
            ];
        }
        return ['brands' => array_values($brands), 'games' => $games];
    }

    public function launch(array $config, string $playerId, Game $game, array $options): array
    {
        $currency = strtolower((string) $options['currency_code']);
        $account = $config['accounts'][$currency === 'gc' ? 'gc' : 'sc'];
        $token = $playerId . '|' . password_hash($playerId . $account['app_secret'], PASSWORD_DEFAULT) . '|' . ($options['rtp'] ?? '');
        $data = $this->request($config, $currency, '/v1/api/get_game_url', [
            'token' => $token,
            'gameId' => $game->provider_game_code,
            'gameBrand' => $game->brand->provider_brand_code,
            'language' => $options['lang'],
        ]);
        return ['game_url' => is_array($data) ? (string) $data['url'] : (string) $data];
    }

    public function getRtp(array $config, array $playerIds, Game $game, string $currency): array
    {
        return $this->request($config, strtolower($currency), '/v1/api/get_player_rtp', ['playerIds' => $playerIds]);
    }

    public function setRtp(array $config, array $playerIds, Game $game, string $currency, string $rtp): array
    {
        if (!in_array($rtp, self::RTP, true)) throw new RuntimeException('RTP 档位无效');
        return $this->request($config, strtolower($currency), '/v1/api/set_player_rtp', ['playerIds' => $playerIds, 'rtp' => $rtp]);
    }

    public function parse(array $config, string $action, array $payload): array
    {
        if ($action === 'verify') {
            $token = (string) ($payload['token'] ?? '');
            if (!str_contains($token, '|')) return ['operation' => 'balance', 'player_id' => ''];
            [$player, $hash, $rtp] = array_pad(explode('|', $token, 3), 3, null);
            $account = $config['accounts'][strtolower($this->currency($player)) === 'gc' ? 'gc' : 'sc'];
            return ['operation' => 'balance', 'player_id' => $account && password_verify($player . $account['app_secret'], $hash) ? $player : '', 'token' => $token, 'rtp' => $rtp];
        }
        if ($action === 'balance') return ['operation' => 'balance', 'player_id' => (string) ($payload['playerId'] ?? '')];
        $common = [
            'player_id' => (string) ($payload['playerId'] ?? ''), 'game_code' => (string) ($payload['gameId'] ?? ''),
            'brand_code' => (string) ($payload['gameBrand'] ?? ''), 'round_id' => (string) ($payload['roundId'] ?? ''),
            'parent_round_id' => (string) ($payload['roundId'] ?? ''), 'source_no' => (string) ($payload['transactionId'] ?? ''),
        ];
        return ['operations' => [match ($action) {
            'bet' => $common + ['action' => 'debit', 'amount' => (string) ($payload['bet'] ?? '0')],
            'win' => $common + ['action' => 'credit', 'amount' => (string) ($payload['win'] ?? '0'), 'finished' => true],
            'refund' => $common + ['action' => 'rollback_debit', 'original_action' => 'debit', 'original_source_no' => (string) ($payload['betTransactionId'] ?? ''), 'amount' => '0', 'finished' => true],
            default => throw new RuntimeException('WXGAME 回调动作无效'),
        }]];
    }

    public function formatResponse(array $result): array
    {
        if (($result['status'] ?? 0) === 2) {
            $data = ['balance' => (float) $this->balance($result), 'currency' => $result['currency_code'] ?? $this->currency((string) ($result['player_id'] ?? ''))];
            if (($result['operation'] ?? '') === 'balance' && isset($result['player_id'])) {
                $data['playerId'] = $result['player_id'];
                if (($result['rtp'] ?? '') !== '') $data['rtp'] = (int) $result['rtp'];
            }
            return ['code' => 0, 'data' => $data, 'msg' => 'success'];
        }
        $code = match ((int) ($result['code'] ?? 0)) { 1001 => 1011, 1004 => 1014, default => 1016 };
        return ['code' => $code, 'data' => null, 'msg' => (string) ($result['message'] ?? 'Internal error')];
    }

    private function request(array $config, string $currency, string $path, array $params): mixed
    {
        $account = $config['accounts'][strtolower($currency) === 'gc' ? 'gc' : 'sc'];
        $nonce = bin2hex(random_bytes(8));
        $timestamp = (string) time();
        $options = [
            'headers' => [
                'AccessKeyId' => $account['app_key'],
                'Sign' => hash('sha256', $account['app_secret'] . $nonce . $timestamp),
                'Nonce' => $nonce,
                'Timestamp' => $timestamp,
                'Content-Type' => 'application/json',
            ],
            'http_errors' => false,
        ];
        $options[$params === [] ? 'body' : 'json'] = $params === [] ? '{}' : $params;
        $response = (new Client(['base_uri' => rtrim($account['url'], '/'), 'timeout' => 30]))->post($path, $options);
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if ((int) ($body['code'] ?? -1) !== 0) throw new RuntimeException((string) ($body['msg'] ?? 'WXGAME 请求失败'));
        return $body['data'] ?? null;
    }
}
