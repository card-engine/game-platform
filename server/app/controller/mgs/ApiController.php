<?php

namespace app\controller\mgs;

use app\model\mgs\Game;
use app\service\mgs\MgsAuthService;
use app\service\mgs\MgsConfigService;
use app\service\mgs\MgsGamePlatformClient;
use app\model\mgs\Wallet;
use plugin\saiadmin\basic\OpenController;
use support\Request;
use support\Response;

class ApiController extends OpenController
{
    protected array $noNeedLogin = ['session', 'brands', 'games', 'launch', 'user', 'wallet'];

    public function session(Request $request): Response
    {
        $data = (new MgsAuthService())->session($request);
        $user = $data['user'];
        $currency = strtoupper((string) (new MgsConfigService())->get('default_currency', config('mgs.default_currency', 'USD')));
        Wallet::firstOrCreate(['user_id' => $user->id, 'currency_code' => $currency], ['balance' => '0.00000000']);
        return $this->success(['token' => $data['token'], 'default_currency_code' => $currency, 'user' => ['mgs_user_id' => $user->id, 'unique_id' => $user->unique_id, 'nickname' => $user->nickname, 'language' => $user->language], 'wallets' => $user->wallets()->get(['id', 'currency_code', 'balance'])]);
    }

    public function brands(Request $request): Response
    {
        (new MgsAuthService())->browserUser($request);
        $currency = strtoupper((string) $request->input('currency_code', (new MgsConfigService())->get('default_currency', config('mgs.default_currency', 'USD'))));
        $games = Game::with('brand:id,platform_brand_code,name,names')->whereJsonContains('currency_codes', $currency)->get(['id', 'brand_id', 'game_type', 'status', 'upstream_status', 'platform_status', 'merchant_status', 'unavailable_reason']);
        $categories = $games->groupBy(fn ($game) => in_array($game->game_type, ['slot', 'fish', 'table', 'poker', 'sport'], true) ? $game->game_type : 'other')->map(fn ($rows, $code) => ['code' => $code, 'count' => $rows->count(), 'available_count' => $rows->filter(fn ($game) => $this->available($game))->count(), 'brands' => $rows->groupBy('brand_id')->map(fn ($brandRows) => ['mgs_brand_id' => $brandRows->first()->brand_id, 'code' => $brandRows->first()->brand?->platform_brand_code, 'name' => $brandRows->first()->brand?->name, 'count' => $brandRows->count(), 'available_count' => $brandRows->filter(fn ($game) => $this->available($game))->count()])->values()])->values();
        return $this->success(['default_currency_code' => $currency, 'categories' => $categories]);
    }

    public function games(Request $request): Response
    {
        (new MgsAuthService())->browserUser($request);
        $currency = strtoupper((string) $request->input('currency_code', (new MgsConfigService())->get('default_currency', config('mgs.default_currency', 'USD'))));
        $games = Game::with('brand:id,platform_brand_code,name,names')->when($request->input('game_type'), fn ($q, $v) => $q->where('game_type', $v))->when($request->input('brand_code'), fn ($q, $v) => $q->whereHas('brand', fn ($b) => $b->where('platform_brand_code', $v)))->when($request->input('keyword'), fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))->whereJsonContains('currency_codes', $currency)->orderBy('sort')->orderByDesc('id')->paginate(min(500, max(1, (int) $request->input('limit', 50))), ['*'], 'page', max(1, (int) $request->input('page', 1)));
        return $this->success(['list' => collect($games->items())->map(fn ($game) => ['mgs_game_id' => $game->id, 'name' => $game->name, 'names' => $game->names, 'icon_url' => $game->icon_url, 'mgs_brand_id' => $game->brand_id, 'brand_code' => $game->brand?->platform_brand_code, 'brand_name' => $game->brand?->name, 'game_type' => $game->game_type, 'currency_codes' => $game->currency_codes, 'is_hot' => $game->is_hot, 'is_new' => $game->is_new, 'status' => $this->available($game) ? 1 : 0, 'unavailable_reason' => $game->unavailable_reason])->all(), 'total' => $games->total(), 'page' => $games->currentPage(), 'limit' => $games->perPage()]);
    }

    public function launch(Request $request): Response
    {
        $user = (new MgsAuthService())->browserUser($request);
        $game = Game::find((int) $request->input('mgs_game_id'));
        if (!$game || !$this->available($game)) return $this->fail('游戏不存在或已停用');
        $currency = strtoupper((string) $request->input('currency_code', (new MgsConfigService())->get('default_currency', config('mgs.default_currency', 'USD'))));
        if (!in_array($currency, (array) $game->currency_codes, true)) return $this->fail('游戏不支持该币种');
        $user->update(['last_launch_time' => gmdate('Y-m-d H:i:s.v'), 'last_ip' => $request->getRealIp()]);
        return $this->success((new MgsGamePlatformClient())->post('/open_api/launch', [
            'user_id' => (string) $user->unique_id, 'game_id' => $game->platform_game_id, 'currency' => $currency,
            'language' => $user->language, 'ip' => $request->getRealIp(),
        ]));
    }

    public function user(Request $request): Response
    {
        $user = (new MgsAuthService())->browserUser($request);
        return $this->success(['mgs_user_id' => $user->id, 'unique_id' => $user->unique_id, 'nickname' => $user->nickname, 'language' => $user->language]);
    }

    public function wallet(Request $request): Response
    {
        $user = (new MgsAuthService())->browserUser($request);
        return $this->success($user->wallets()->orderBy('currency_code')->get(['id', 'currency_code', 'balance'])->map(fn ($wallet) => ['mgs_wallet_id' => $wallet->id, 'currency_code' => $wallet->currency_code, 'balance' => (string) $wallet->balance])->all());
    }

    private function available(Game $game): bool
    {
        return (int) $game->status === 1 && (int) $game->upstream_status === 1 && (int) $game->platform_status === 1 && (int) $game->merchant_status === 1 && !$game->unavailable_reason;
    }
}
