<?php

namespace app\service\game\adapter\platforms;

use app\model\Game;
use app\service\game\adapter\AgentAdapter;

class TadaAdapter extends AgentAdapter
{
    public function parse(array $config, string $action, array $payload): array
    {
        $player = $this->expiringPlayer((string) ($payload['token'] ?? ''));
        if ($action === 'auth') return ['operation' => 'balance', 'player_id' => $player, 'token' => (string) ($payload['token'] ?? '')];
        if ($action === 'bet') {
            $round = (string) ($payload['round'] ?? '');
            $source = (string) ($payload['transactionId'] ?? $round);
            $common = [
                'player_id' => $player, 'game_code' => (string) ($payload['game'] ?? ''), 'round_id' => $source,
                'parent_round_id' => $round, 'source_no' => $source,
            ];
            return ['operations' => [
                $common + ['action' => 'debit', 'amount' => (string) ($payload['betAmount'] ?? '0')],
                $common + ['action' => 'credit', 'amount' => (string) ($payload['winloseAmount'] ?? '0'), 'finished' => true],
            ], 'token' => (string) ($payload['token'] ?? '')];
        }
        $player = $player ?: (string) ($payload['userId'] ?? '');
        $round = (string) ($payload['round'] ?? '');
        $original = (string) ($payload['transactionId'] ?? $round);
        $source = (string) ($payload['reqId'] ?? "cancel:{$original}");
        return ['operations' => [
            ['action' => 'rollback_credit', 'original_action' => 'credit', 'player_id' => $player, 'game_code' => (string) ($payload['game'] ?? ''), 'round_id' => $original, 'source_no' => $source, 'original_source_no' => $original, 'amount' => '0'],
            ['action' => 'rollback_debit', 'original_action' => 'debit', 'player_id' => $player, 'game_code' => (string) ($payload['game'] ?? ''), 'round_id' => $original, 'source_no' => $source, 'original_source_no' => $original, 'amount' => '0', 'finished' => true],
        ]];
    }

    public function sync(array $config): array
    {
        $list = $this->request($config, 'POST', '/sss/GetGameList', ['Currency' => strtoupper($config['currency'])], ['Currency', 'AgentId']);
        $games = [];
        foreach ($list as $item) {
            $id = trim((string) ($item['GameId'] ?? ''));
            if ($id === '') continue;
            $names = is_array($item['name'] ?? null) ? $item['name'] : [];
            $games[] = [
                'brand_code' => 'tada',
                'provider_game_code' => $id,
                'name' => (string) ($names['en-US'] ?? $id),
                'names' => $names,
                'icon_url' => "https://wbgame.rsne4d5q.com/partner-gaming-assets/{$id}/icon/900x600.png",
                'currency_codes' => ($config['is_gc'] ?? false) ? ['SC', 'GC'] : [strtoupper($config['currency'])],
                'support_demo' => 0,
                'support_rtp' => 0,
                'rtp_options' => null,
                'extra' => ['category_id' => $item['GameCategoryId'] ?? null, 'jp' => $item['JP'] ?? false],
            ];
        }
        return ['brands' => [['provider_brand_code' => 'tada', 'name' => 'TADA', 'is_gc' => 1]], 'games' => $games];
    }

    public function launch(array $config, string $playerId, Game $game, array $options): array
    {
        $params = ['Token' => $this->playerToken($playerId), 'GameId' => $game->provider_game_code, 'Lang' => $options['lang']];
        if (!empty($options['back_url'])) $params['HomeUrl'] = $options['back_url'];
        $url = $this->request($config, 'POST', '/singleWallet/LoginWithoutRedirect', $params, ['Token', 'GameId', 'Lang', 'AgentId']);
        return ['game_url' => (string) $url];
    }
}
