<?php

namespace app\middleware;

use app\model\Merchant;
use app\service\game\SecretService;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class MerchantAuth implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $params = (array) $request->post();
        $mchId = (string) ($params['mch_id'] ?? '');
        $timestamp = (string) ($params['timestamp'] ?? '');
        $merchant = Merchant::where('mch_id', $mchId)->where('status', 1)->first();
        if (!$merchant || abs(time() - (int) $timestamp) > 60) return $this->error('商户认证失败', 401);
        if ($merchant->ip_whitelist && !in_array($request->getRealIp(), $merchant->ip_whitelist, true)) return $this->error('IP 不在白名单', 403);
        $sign = game_platform_sign($params, SecretService::decrypt($merchant->getRawOriginal('secret')));
        if (!hash_equals($sign, (string) ($params['sign'] ?? ''))) return $this->error('签名错误', 401);
        $request->setHeader('mg_merchant', $merchant);
        return $handler($request);
    }

    private function error(string $message, int $code): Response
    {
        return json(['code' => $code, 'message' => $message]);
    }
}
