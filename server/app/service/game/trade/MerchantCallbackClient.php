<?php

namespace app\service\game\trade;

use app\model\Merchant;
use app\service\game\SecretService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\StreamHandler;

class MerchantCallbackClient
{
    public function request(Merchant $merchant, string $action, array $data): array
    {
        if ((int) $merchant->wallet_mode !== 1) return ['status' => 3, 'code' => 1003, 'message' => '无缝钱包未启用', 'data' => []];

        $data['mch_id'] = $merchant->mch_id;
        $data['timestamp'] = time();
        $secret = (int) $merchant->id === 0
            ? (string) config('game_platforms.self_merchant.secret')
            : SecretService::decrypt($merchant->getRawOriginal('secret'));
        $data['sign'] = game_platform_sign($data, $secret);
        try {
            $response = (new Client([
                'handler' => new StreamHandler(),
                'base_uri' => rtrim((string) $merchant->callback_url, '/') . '/',
                'timeout' => max(1, (int) $merchant->timeout_ms / 1000),
                'connect_timeout' => 3,
                'http_errors' => false,
            ]))->post($action, ['json' => $data]);
            $status = $response->getStatusCode();
            $result = json_decode((string) $response->getBody(), true);
            if ($status >= 500 || !is_array($result) || !array_key_exists('code', $result)) {
                return ['status' => 4, 'code' => 1006, 'message' => '商户通知结果未知', 'data' => []];
            }
            return [
                'status' => (int) $result['code'] === 0 ? 2 : 3,
                'code' => (int) $result['code'],
                'message' => (string) ($result['message'] ?? ''),
                'data' => is_array($result['data'] ?? null) ? $result['data'] : [],
            ];
        } catch (GuzzleException) {
            return ['status' => 4, 'code' => 1006, 'message' => '商户通知结果未知', 'data' => []];
        }
    }
}
