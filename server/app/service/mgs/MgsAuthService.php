<?php

namespace app\service\mgs;

use app\model\mgs\User;
use plugin\saiadmin\exception\ApiException;
use support\Db;
use support\Request;

class MgsAuthService
{
    public function session(Request $request): array
    {
        $token = $this->token($request) ?: bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $user = User::where('browser_token_hash', $hash)->first();
        if (!$user) {
            $user = Db::transaction(function () use ($hash, $request) {
                $user = User::create(['browser_token_hash' => $hash, 'nickname' => $request->input('nickname'), 'language' => (string) ($request->input('language') ?: config('mgs.default_language', 'en')), 'status' => 1]);
                $user->update(['unique_id' => id2big((int) $user->id)]);
                return $user;
            });
        }
        if (!$user->unique_id) $user->update(['unique_id' => id2big((int) $user->id)]);
        if ((int) $user->status !== 1) throw new ApiException('MGS 用户已停用', 403);
        $user->update(['last_login_time' => gmdate('Y-m-d H:i:s.v'), 'last_ip' => $request->getRealIp()]);
        return ['token' => $token, 'user' => $user, 'wallets' => $user->wallets()->get(['id', 'currency_code', 'balance'])];
    }

    public function browserUser(Request $request): User
    {
        $token = $this->token($request);
        $user = $token ? User::where('browser_token_hash', hash('sha256', $token))->first() : null;
        if (!$user || (int) $user->status !== 1) throw new ApiException('MGS 用户认证失败', 401);
        return $user;
    }

    public function user(Request $request): User
    {
        $uniqueId = trim((string) ($request->header('X-Mgs-User') ?: $request->input('unique_id')));
        $timestamp = (string) ($request->header('X-Mgs-Timestamp') ?: $request->input('timestamp'));
        $sign = (string) ($request->header('X-Mgs-Sign') ?: $request->input('sign'));
        $secret = (string) (config('mgs.api_secret') ?: config('game_platforms.secret_key'));
        if ($uniqueId === '' || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300 || $secret === '' || !hash_equals(hash_hmac('sha256', $uniqueId . '|' . $timestamp, $secret), $sign)) {
            throw new ApiException('MGS 用户认证失败', 401);
        }
        $id = big2id((int) $uniqueId);
        $user = $id === false ? null : User::find($id);
        if (!$user) $user = User::create(['unique_id' => $id ?: null, 'nickname' => $request->input('nickname'), 'language' => (string) ($request->input('language') ?: config('mgs.default_language', 'en')), 'status' => 1]);
        if ((int) $user->status !== 1) throw new ApiException('MGS 用户已停用');
        $user->update(['last_login_time' => gmdate('Y-m-d H:i:s.v'), 'last_ip' => $request->getRealIp()]);
        return $user;
    }

    private function token(Request $request): string
    {
        return preg_replace('/^Bearer\s+/i', '', trim((string) $request->header('Authorization'))) ?: '';
    }
}
