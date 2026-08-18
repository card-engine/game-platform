<?php

namespace app\service\mgs;

use app\model\mgs\User;
use plugin\saiadmin\exception\ApiException;
use support\Request;

class MgsAuthService
{
    public function user(Request $request): User
    {
        $userNo = trim((string) ($request->header('X-Mgs-User') ?: $request->input('user_no')));
        $timestamp = (string) ($request->header('X-Mgs-Timestamp') ?: $request->input('timestamp'));
        $sign = (string) ($request->header('X-Mgs-Sign') ?: $request->input('sign'));
        $secret = (string) (config('mgs.api_secret') ?: config('game_platforms.secret_key'));
        if ($userNo === '' || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300 || $secret === '' || !hash_equals(hash_hmac('sha256', $userNo . '|' . $timestamp, $secret), $sign)) {
            throw new ApiException('MGS 用户认证失败', 401);
        }
        $user = User::firstOrCreate(['user_no' => $userNo], [
            'nickname' => $request->input('nickname'), 'language' => (string) ($request->input('language') ?: config('mgs.default_language', 'en')), 'status' => 1,
        ]);
        if ((int) $user->status !== 1) throw new ApiException('MGS 用户已停用');
        $user->update(['last_login_time' => gmdate('Y-m-d H:i:s.v'), 'last_ip' => $request->getRealIp()]);
        return $user;
    }
}
