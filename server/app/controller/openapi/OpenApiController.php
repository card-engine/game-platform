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
        $list = $this->service->games($merchant, $request->post())->orderBy('sort')->paginate(min(100, max(1, (int) $request->post('limit', 20))));
        $list->getCollection()->transform(fn ($game) => [
            'game_id' => (string) id2big((int) $game->id), 'name' => $game->names[$lang] ?? $game->name, 'icon_url' => $game->icon_url,
            'brand_code' => $game->brand->uniqueBrand->code, 'brand_name' => $game->brand->uniqueBrand->names[$lang] ?? $game->brand->uniqueBrand->name,
            'game_type' => $game->game_type, 'currencies' => array_values(array_intersect($game->currency_codes, $currencies)), 'support_demo' => (int) $game->support_demo,
            'support_rtp' => (int) $game->support_rtp, 'rtp_options' => $game->rtp_options,
        ]);
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
