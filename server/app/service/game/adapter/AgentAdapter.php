<?php

namespace app\service\game\adapter;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\StreamHandler;
use RuntimeException;

abstract class AgentAdapter extends AbstractAdapter
{
    public function verify(array $config, array $headers, string $body): bool
    {
        $username = (string) ($config['basic_auth_username'] ?? '');
        $password = (string) ($config['basic_auth_password'] ?? '');
        return ($username === '' && $password === '') || hash_equals('Basic ' . base64_encode("{$username}:{$password}"), (string) ($headers['authorization'] ?? ''));
    }

    public function formatResponse(array $result): array
    {
        if (($result['status'] ?? 0) === 2) {
            return array_filter([
                'errorCode' => 0, 'message' => 'success', 'username' => $result['player_id'] ?? null,
                'currency' => $result['currency_code'] ?? null, 'balance' => (float) $this->balance($result),
                'token' => $result['token'] ?? null, 'txId' => $result['source_no'] ?? null,
            ], fn ($value) => $value !== null);
        }
        $code = ($result['code'] ?? 0) === 1001 ? 2 : (($result['code'] ?? 0) === 1004 ? 2 : 5);
        return ['errorCode' => $code, 'message' => (string) ($result['message'] ?? 'Internal error')];
    }

    protected function request(array $config, string $method, string $path, array $params, array $signFields): mixed
    {
        $params['AgentId'] = $config['agent_id'];
        $pairs = array_map(fn ($field) => $field . '=' . ($params[$field] ?? ''), $signFields);
        $date = new DateTimeImmutable('now', new DateTimeZone('America/Puerto_Rico'));
        $keyG = md5($date->format('ym') . (int) $date->format('j') . $config['agent_id'] . $config['agent_key']);
        $params['Key'] = '000000' . md5(implode('&', $pairs) . $keyG) . '000000';
        $options = $method === 'GET' ? ['query' => $params] : ['form_params' => $params];
        if (($config['basic_auth_username'] ?? '') !== '' || ($config['basic_auth_password'] ?? '') !== '') {
            $options['auth'] = [$config['basic_auth_username'], $config['basic_auth_password']];
        }
        $response = (new Client(['handler' => new StreamHandler(), 'base_uri' => rtrim($config['url'], '/') . '/', 'timeout' => 30]))->request($method, ltrim($path, '/'), $options);
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if ((int) ($body['ErrorCode'] ?? -1) !== 0) throw new RuntimeException((string) ($body['Message'] ?? '游戏平台请求失败'));
        $data = $body['Data'] ?? null;
        if (is_string($data) && (($decoded = json_decode($data, true)) !== null)) return $decoded;
        return $data;
    }
}
