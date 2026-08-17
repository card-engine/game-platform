<?php

namespace app\service\game\adapter\platforms;

use app\model\Game;
use app\service\game\adapter\AgentAdapter;

class AceWinAdapter extends AgentAdapter
{
    public function parse(array $config, string $action, array $payload): array
    {
        $player = $this->expiringPlayer((string) ($payload['token'] ?? ''));
        if ($action === 'auth') return ['operation' => 'balance', 'player_id' => $player, 'token' => (string) ($payload['token'] ?? '')];
        if ($action === 'bet') {
            $round = (string) ($payload['round'] ?? '');
            $common = [
                'player_id' => $player, 'game_code' => (string) ($payload['game'] ?? ''), 'round_id' => $round,
                'parent_round_id' => (string) ($payload['gameNo'] ?? $round), 'source_no' => $round,
            ];
            return ['operations' => [
                $common + ['action' => 'debit', 'amount' => (string) ($payload['betAmount'] ?? '0')],
                $common + ['action' => 'credit', 'amount' => (string) ($payload['winloseAmount'] ?? '0'), 'finished' => true],
            ], 'token' => (string) ($payload['token'] ?? '')];
        }
        $player = $player ?: (string) ($payload['userId'] ?? '');
        $round = (string) ($payload['round'] ?? '');
        $source = (string) ($payload['reqId'] ?? "cancel:{$round}");
        return ['operations' => [
            ['action' => 'rollback_credit', 'original_action' => 'credit', 'player_id' => $player, 'game_code' => (string) ($payload['game'] ?? ''), 'round_id' => $round, 'source_no' => $source, 'original_source_no' => $round, 'amount' => '0'],
            ['action' => 'rollback_debit', 'original_action' => 'debit', 'player_id' => $player, 'game_code' => (string) ($payload['game'] ?? ''), 'round_id' => $round, 'source_no' => $source, 'original_source_no' => $round, 'amount' => '0', 'finished' => true],
        ]];
    }

    public function sync(array $config): array
    {
        $list = $this->request($config, 'GET', '/api3/GetGameList', ['IconSize' => '400x400'], ['AgentId']);
        $games = [];
        foreach ($list as $item) {
            $names = $item['name'] ?? [];
            $games[] = [
                'brand_code' => 'acewin',
                'provider_game_code' => (string) $item['GameId'],
                'name' => (string) ($names['en-US'] ?? $item['GameId']),
                'names' => $names,
                'icon_url' => (string) ($item['Icon']['en-US'] ?? ''),
                'currency_codes' => ['SC'],
                'support_demo' => 0,
                'support_rtp' => 0,
                'rtp_options' => null,
                'extra' => ['category_id' => $item['GameCategoryId'] ?? null, 'jp' => $item['JP'] ?? false],
            ];
        }
        return ['brands' => [['provider_brand_code' => 'acewin', 'name' => 'AceWin']], 'games' => $games];
    }

    public function launch(array $config, string $playerId, Game $game, array $options): array
    {
        $lang = match (strtolower($options['lang'])) {
            'es', 'es-es' => 'es-es', 'zh', 'zh-cn', 'zh-tw' => 'zh-cn', 'th', 'th-th' => 'th-th',
            'vi', 'vi-vn' => 'vi-vn', 'ms', 'ms-my' => 'ms-my', default => 'en-us',
        };
        $url = $this->request($config, 'GET', '/singleWallet/LoginWithoutRedirect', [
            'Token' => $this->playerToken($playerId), 'GameId' => $game->provider_game_code, 'Lang' => $lang,
        ], ['Token', 'GameId', 'Lang', 'AgentId']);
        return ['game_url' => (string) $url];
    }
}
