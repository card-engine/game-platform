<?php

namespace app\controller\game;

use app\logic\game\IndexLogic;
use app\service\game\adapter\AdapterRegistry;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;
use Webman\RedisQueue\Redis;

class IndexController extends BaseController
{
    public function __construct()
    {
        $this->logic = new IndexLogic();
        parent::__construct();
    }

    #[Permission('游戏品牌列表', 'app:game:list:index')]
    public function brands(Request $request): Response
    {
        return $this->success($this->logic->brands($request->more([['platform_code', ''], ['mapping_status', ''], ['keyword', '']])));
    }

    #[Permission('统一品牌列表', 'app:game:list:index')]
    public function uniqueBrands(Request $request): Response
    {
        return $this->success($this->logic->uniqueBrands($request->more([['keyword', '']])));
    }

    #[Permission('游戏列表', 'app:game:list:index')]
    public function lists(Request $request): Response
    {
        return $this->success($this->logic->lists($request->more([['platform_code', ''], ['brand_id', ''], ['unique_brand_id', ''], ['merchant_id', ''], ['status', ''], ['keyword', '']])));
    }

    #[Permission('试玩游戏', 'app:game:list:trial')]
    public function trial(Request $request): Response
    {
        return $this->success($this->logic->trial(
            (int) $request->post('game_id'),
            strtoupper((string) $request->post('currency')),
            $request->getRealIp(),
        ));
    }

    #[Permission('同步游戏列表', 'app:game:list:sync')]
    public function sync(Request $request): Response
    {
        $platform = strtolower((string) $request->post('platform_code'));
        AdapterRegistry::config($platform);
        Redis::send('game_sync', ['platform_code' => $platform]);
        return $this->success(['platform' => $platform, 'queued' => true]);
    }

    #[Permission('启停游戏', 'app:game:list:update')]
    public function status(Request $request): Response
    {
        $this->logic->status((array) $request->post('ids'), (int) $request->post('status'));
        return $this->success('操作成功');
    }

    #[Permission('归并品牌资源', 'app:game:list:update')]
    public function mappingImpact(Request $request): Response
    {
        return $this->success($this->logic->mappingImpact((int) $request->input('brand_id'), (int) $request->input('unique_brand_id')));
    }

    #[Permission('归并品牌资源', 'app:game:list:update')]
    public function mapBrand(Request $request): Response
    {
        return $this->success($this->logic->mapBrand((int) $request->post('brand_id'), $request->post()));
    }

    #[Permission('设置品牌币种', 'app:game:list:update')]
    public function brandMode(Request $request): Response
    {
        $this->logic->setBrandMode((int) $request->post('id'), (int) $request->post('is_gc'));
        return $this->success('品牌币种已更新');
    }
}
