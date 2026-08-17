<?php

namespace app\service\game\adapter;

use app\model\Game;
use RuntimeException;

abstract class AbstractAdapter implements AdapterInterface
{
    public function getRtp(array $config, array $playerIds, Game $game, string $currency): array
    {
        throw new RuntimeException('该游戏平台不支持 RTP 调整');
    }

    public function setRtp(array $config, array $playerIds, Game $game, string $currency, string $rtp): array
    {
        throw new RuntimeException('该游戏平台不支持 RTP 调整');
    }

    public function verify(array $config, array $headers, string $body): bool
    {
        return true;
    }

    public function parse(array $config, string $action, array $payload): array
    {
        throw new RuntimeException('该回调协议尚未支持');
    }

    public function formatResponse(array $result): array
    {
        return $result;
    }

    protected function expiringPlayer(string $token): string
    {
        if (strlen($token) <= 64 || !ctype_xdigit($token)) return '';
        $payload = hex2bin(substr($token, 0, -64));
        if ($payload === false || !hash_equals(hash_hmac('sha256', $payload, config('game_platforms.secret_key')), substr($token, -64))) return '';
        [$player, $expires] = array_pad(explode('|', $payload, 2), 2, '');
        return (int) $expires >= time() ? $player : '';
    }

    protected function currency(string $playerId): string
    {
        return strtoupper(ltrim((string) strrchr($playerId, '_'), '_'));
    }

    protected function balance(array $result): string
    {
        return (string) ($result['data']['balance_after'] ?? $result['data']['balance'] ?? '0');
    }

    protected function playerToken(string $playerId): string
    {
        $payload = $playerId . '|' . (time() + 86400);
        return bin2hex($payload) . hash_hmac('sha256', $payload, config('game_platforms.secret_key'));
    }
}
