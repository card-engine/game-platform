<?php

namespace app\service\game\adapter;

use app\service\game\adapter\platforms\AceWinAdapter;
use app\service\game\adapter\platforms\GoldenGateXAdapter;
use app\service\game\adapter\platforms\TadaAdapter;
use app\service\game\adapter\platforms\WxGameAdapter;
use InvalidArgumentException;

class AdapterRegistry
{
    public static function get(string $platform): AdapterInterface
    {
        return match (strtolower($platform)) {
            'wxgame' => new WxGameAdapter(),
            'acewin' => new AceWinAdapter(),
            'tada' => new TadaAdapter(),
            'goldengatex' => new GoldenGateXAdapter(),
            default => throw new InvalidArgumentException("不支持的游戏平台: {$platform}"),
        };
    }

    public static function config(string $platform): array
    {
        $config = config('game_platforms.platforms.' . strtolower($platform));
        if (!$config) throw new InvalidArgumentException("游戏平台未配置: {$platform}");
        return $config;
    }
}
