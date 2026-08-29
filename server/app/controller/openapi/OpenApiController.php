<?php

namespace app\controller\openapi;

use app\model\Merchant;
use app\service\game\OpenApiService;
use plugin\saiadmin\basic\OpenController;
use support\Request;
use support\Response;

class OpenApiController extends OpenController
{
    protected array $noNeedLogin = ['games', 'launch', 'setRtp', 'bets'];
    private OpenApiService $service;

    public function __construct()
    {
        $this->service = new OpenApiService();
        parent::__construct();
    }

    public function games(Request $request): Response
    {
        $merchant = $this->merchant($request);
        $lang = (string) $request->post('language', $merchant->default_language);
        $currencies = $merchant->credits()->where('status', 1)->pluck('currency_code')->all();
        $list = $this->service->games($merchant, $request->post())->orderBy('sort')->orderByDesc('mg_games.id')->paginate(min(100, max(1, (int) $request->post('limit', 20))), ['*'], 'page', max(1, (int) $request->post('page', 1)));
        $list->getCollection()->transform(function ($game) use ($lang, $currencies) {
            $brand = $game->brand;
            $unique = $brand?->uniqueBrand;
            $platformOpen = (bool) (config('game_platforms.platforms.' . $game->platform_code . '.is_open', true));
            $available = (int) $game->upstream_status === 1 && (int) $game->platform_status === 1 && $platformOpen && (int) ($game->merchant_status ?? 1) === 1;
            $currencyAvailable = (bool) array_intersect((array) $game->currency_codes, $currencies);
            if (!$currencyAvailable) $available = false;
            $merchantStatus = (int) ($game->merchant_status ?? 1);
            $reason = (int) $game->upstream_status !== 1 ? 'upstream_unavailable' : ((int) $game->platform_status !== 1 ? 'platform_disabled' : (!$platformOpen ? 'platform_closed' : ($merchantStatus !== 1 ? 'merchant_disabled' : (!$currencyAvailable ? 'currency_unavailable' : ($brand?->unique_brand_id ? null : 'brand_unmapped')))));
            return [
                'game_id' => (string) id2big((int) $game->id), 'game_code' => $game->game_code, 'platform_code' => $game->platform_code,
                'provider_game_code' => $game->provider_game_code, 'name' => $game->names[$lang] ?? $game->name, 'icon_url' => $game->icon_url,
                'brand_code' => $unique?->code, 'brand_name' => $unique ? ($unique->names[$lang] ?? $unique->name) : $brand?->name,
                'brand_mapping_status' => (int) ($brand?->mapping_status ?? 0), 'game_type' => $game->game_type,
                'currency_codes' => array_values($game->currency_codes ?: []), 'currencies' => array_values(array_intersect($game->currency_codes ?: [], $currencies)),
                'upstream_status' => (int) $game->upstream_status, 'platform_status' => (int) $game->platform_status, 'merchant_status' => (int) ($game->merchant_status ?? 1),
                'status' => $available ? 1 : 0, 'is_available' => $available, 'unavailable_reason' => $reason,
                'support_demo' => (int) $game->support_demo, 'support_rtp' => (int) $game->support_rtp, 'rtp_options' => $game->rtp_options,
            ];
        });
        return $this->success(['list' => $list->items(), 'total' => $list->total()]);
    }

    public function launch(Request $request): Response
    {
        foreach (['user_id', 'game_id'] as $field) if (!$request->post($field)) return $this->fail("{$field} 不能为空");
        return $this->success($this->service->launch($this->merchant($request), $request->post(), $request->getRealIp()));
    }

    public function setRtp(Request $request): Response
    {
        foreach (['game_id', 'rtp'] as $field) if (!$request->post($field)) return $this->fail("{$field} 不能为空");
        return $this->success($this->service->setRtp($this->merchant($request), $request->post()));
    }

    public function bets(Request $request): Response
    {
        return $this->success($this->service->bets($this->merchant($request), $request->post()));
    }

    private function merchant(Request $request): Merchant
    {
        return $request->header('mg_merchant');
    }
}
