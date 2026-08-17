<?php

namespace app\service\game\adapter;

use app\model\Game;

interface AdapterInterface
{
    public function sync(array $config): array;
    public function launch(array $config, string $playerId, Game $game, array $options): array;
    public function getRtp(array $config, array $playerIds, Game $game, string $currency): array;
    public function setRtp(array $config, array $playerIds, Game $game, string $currency, string $rtp): array;
    public function verify(array $config, array $headers, string $body): bool;
    public function parse(array $config, string $action, array $payload): array;
    public function formatResponse(array $result): array;
}
