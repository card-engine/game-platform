<?php

namespace app\controller\openapi;

use plugin\saiadmin\basic\OpenController;
use support\Request;
use support\Response;

class SelfWalletController extends OpenController
{
    protected array $noNeedLogin = ['callback'];

    public function callback(Request $request, string $action): Response
    {
        $params = $request->post();
        $config = config('game_platforms.self_merchant');
        $timestamp = (string) ($params['timestamp'] ?? '');
        $authorized = $config['secret'] !== ''
            && (string) ($params['mch_id'] ?? '') === '0'
            && (string) ($params['user_id'] ?? '') === $config['user_id']
            && in_array(strtoupper((string) ($params['currency'] ?? '')), $config['currencies'], true)
            && ctype_digit($timestamp)
            && abs(time() - (int) $timestamp) <= 60
            && hash_equals(game_platform_sign($params, $config['secret']), (string) ($params['sign'] ?? ''));
        if (!$authorized) return json(['code' => 1003, 'message' => 'unauthorized', 'data' => []]);

        return json(['code' => 0, 'message' => 'success', 'data' => [
            'balance' => $config['balance'],
            'balance_after' => $config['balance'],
        ]]);
    }
}
