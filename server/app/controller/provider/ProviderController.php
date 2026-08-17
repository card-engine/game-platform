<?php

namespace app\controller\provider;

use app\service\game\adapter\AdapterRegistry;
use app\service\game\trade\TradeService;
use plugin\saiadmin\basic\OpenController;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

class ProviderController extends OpenController
{
    protected array $noNeedLogin = ['callback'];

    public function callback(Request $request, string $platform, string $action): Response
    {
        $adapter = AdapterRegistry::get($platform);
        try {
            $config = AdapterRegistry::config($platform);
            if (!$adapter->verify($config, $request->header(), $request->rawBody())) {
                return json($adapter->formatResponse(['status' => 3, 'code' => 1003, 'message' => 'unauthorized']));
            }
            $parsed = $adapter->parse($config, $action, $request->post());
            $trade = new TradeService();
            if (($parsed['operation'] ?? '') === 'balance') {
                $result = $trade->balance((string) $parsed['player_id']);
            } else {
                $operations = $parsed['operations'] ?? [];
                if ($platform === 'goldengatex' && $action === 'batch-transactions') {
                    $operations = [];
                    foreach ((array) $request->post('transactions', []) as $item) {
                        $item['userCode'] = $request->post('userCode');
                        array_push($operations, ...($adapter->parse($config, 'transaction', $item)['operations'] ?? []));
                    }
                }
                foreach ($operations as $operation) {
                    $result = $trade->handle($platform, $operation);
                    $result += [
                        'player_id' => $operation['player_id'],
                        'currency_code' => strtoupper(ltrim((string) strrchr($operation['player_id'], '_'), '_')),
                        'source_no' => $operation['source_no'],
                    ];
                    if (($result['status'] ?? 0) !== 2) break;
                }
            }
            $result += array_filter(['player_id' => $parsed['player_id'] ?? null, 'token' => $parsed['token'] ?? null, 'rtp' => $parsed['rtp'] ?? null], fn ($value) => $value !== null);
            if (!isset($result['currency_code']) && isset($result['player_id'])) $result['currency_code'] = strtoupper(ltrim((string) strrchr($result['player_id'], '_'), '_'));
            return json($adapter->formatResponse($result));
        } catch (Throwable $e) {
            Log::error('游戏平台回调失败', ['platform' => $platform, 'action' => $action, 'message' => $e->getMessage()]);
            return json($adapter->formatResponse(['status' => 3, 'code' => 1003, 'message' => $e->getMessage()]));
        }
    }
}
