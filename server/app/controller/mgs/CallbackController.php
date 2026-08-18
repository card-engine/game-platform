<?php

namespace app\controller\mgs;

use app\service\mgs\MgsCallbackService;
use plugin\saiadmin\basic\OpenController;
use RuntimeException;
use support\Request;
use Throwable;

class CallbackController extends OpenController
{
    protected array $noNeedLogin = ['callback'];

    public function callback(Request $request, string $action)
    {
        $params = $request->post();
        $service = new MgsCallbackService();
        if (!$service->authorized($params)) return json(['code' => 1003, 'message' => 'unauthorized', 'data' => []]);
        try {
            return json(['code' => 0, 'message' => 'success', 'data' => $service->handle($action, $params)]);
        } catch (RuntimeException $e) {
            $code = match ($e->getMessage()) {
                '余额不足' => 1001,
                '原交易不存在', '原注单不存在', 'MGS 游戏不存在或已停用', 'MGS 游戏不支持该币种' => 1004,
                default => 1005,
            };
            return json(['code' => $code, 'message' => $e->getMessage(), 'data' => []]);
        } catch (Throwable) {
            return json(['code' => 1006, 'message' => 'internal error', 'data' => []])->withStatus(500);
        }
    }
}
