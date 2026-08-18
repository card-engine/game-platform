<?php

namespace app\controller;

use plugin\saiadmin\basic\OpenController;
use support\Request;
use support\Response;
use Tinywan\Jwt\JwtToken;
use Throwable;

class AuthController extends OpenController
{
    protected array $noNeedLogin = ['refresh'];

    public function refresh(Request $request): Response
    {
        if (!str_starts_with(trim((string) $request->header('authorization')), 'Bearer ')) {
            return json(['code' => 401, 'message' => '刷新令牌缺失', 'data' => []]);
        }

        try {
            return $this->success(JwtToken::refreshToken());
        } catch (Throwable $e) {
            return json(['code' => 401, 'message' => $e->getMessage(), 'data' => []]);
        }
    }
}
