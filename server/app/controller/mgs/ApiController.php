<?php

namespace app\controller\mgs;

use app\model\mgs\Game;
use app\service\mgs\MgsAuthService;
use app\service\mgs\MgsConfigService;
use app\service\mgs\MgsGamePlatformClient;
use plugin\saiadmin\basic\OpenController;
use support\Request;
use support\Response;

class ApiController extends OpenController
{
    protected array $noNeedLogin = ['games', 'launch', 'user', 'wallet'];

    public function games(Request $request): Response
    {
        $user = (new MgsAuthService())->user($request);
        $currency = strtoupper((string) $request->input('currency', (new MgsConfigService())->get('default_currency', config('mgs.default_currency', 'USD'))));
        $games = Game::where('status', 1)->whereJsonContains('currency_codes', $currency)->orderBy('sort')->get(['id', 'name', 'names', 'icon_url', 'banner_url', 'game_type', 'currency_codes', 'is_hot', 'is_new']);
        return $this->success(['user_id' => $user->user_no, 'list' => $games]);
    }

    public function launch(Request $request): Response
    {
        $user = (new MgsAuthService())->user($request);
        $game = Game::whereKey((int) $request->input('game_id'))->where('status', 1)->first();
        if (!$game) return $this->fail('游戏不存在或已停用');
        $currency = strtoupper((string) $request->input('currency', (new MgsConfigService())->get('default_currency', config('mgs.default_currency', 'USD'))));
        if (!in_array($currency, (array) $game->currency_codes, true)) return $this->fail('游戏不支持该币种');
        $user->update(['last_launch_time' => gmdate('Y-m-d H:i:s.v'), 'last_ip' => $request->getRealIp()]);
        return $this->success((new MgsGamePlatformClient())->post('/open_api/launch', [
            'user_id' => $user->user_no, 'game_id' => $game->platform_game_id, 'currency' => $currency,
            'language' => $user->language, 'ip' => $request->getRealIp(),
        ]));
    }

    public function user(Request $request): Response
    {
        return $this->success((new MgsAuthService())->user($request)->only(['user_no', 'nickname', 'language']));
    }

    public function wallet(Request $request): Response
    {
        $user = (new MgsAuthService())->user($request);
        return $this->success($user->wallets()->orderBy('currency_code')->get(['currency_code', 'balance'])->all());
    }
}
