<?php

use app\service\game\adapter\platforms\WxGameAdapter;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

$player = 'mg_60972_sc';
$secret = 'test-secret';
$token = $player . '|' . password_hash($player . $secret, PASSWORD_DEFAULT) . '|95';
$adapter = new WxGameAdapter();
$parsed = $adapter->parse(['accounts' => ['sc' => ['app_secret' => $secret]]], 'verify', ['token' => $token]);
$response = $adapter->formatResponse([
    'status' => 2, 'operation' => 'balance', 'player_id' => $parsed['player_id'], 'currency_code' => 'SC',
    'rtp' => $parsed['rtp'], 'data' => ['balance' => '100.00'],
]);
if ($parsed['player_id'] !== $player || ($response['data']['rtp'] ?? null) !== 95) throw new RuntimeException('WxGame 首次进游 RTP 下发失败');

$usdPlayer = 'mg_123456_usd';
$usdToken = $usdPlayer . '|' . password_hash($usdPlayer . $secret, PASSWORD_DEFAULT) . '|';
if (($adapter->parse(['accounts' => ['sc' => ['app_secret' => $secret]]], 'verify', ['token' => $usdToken])['player_id'] ?? '') !== $usdPlayer) {
    throw new RuntimeException('WxGame USD 未映射普通账号');
}

echo "WxGame RTP smoke test passed\n";
