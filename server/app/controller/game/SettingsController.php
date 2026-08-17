<?php

namespace app\controller\game;

use app\logic\game\SettingsLogic;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;

class SettingsController extends BaseController
{
    public function __construct()
    {
        $this->logic = new SettingsLogic();
        parent::__construct();
    }

    #[Permission('查看全局设置', 'app:game:settings:index')]
    public function configs(): Response
    {
        return $this->success($this->logic->configs());
    }

    #[Permission('修改全局设置', 'app:game:settings:update')]
    public function save(Request $request): Response
    {
        $this->logic->save((array) $request->post('values', []));
        return $this->success('全局设置已保存');
    }

    #[Permission('查看平台统计重建状态', 'app:game:settings:index')]
    public function rebuildStatus(): Response
    {
        return $this->success($this->logic->rebuildStatus());
    }

    #[Permission('查看汇率快照', 'app:game:exchange-rate:index')]
    public function exchangeRates(Request $request): Response
    {
        return $this->success($this->logic->exchangeRates($request->more([['date_start', ''], ['date_end', '']])));
    }

    #[Permission('更新今日汇率', 'app:game:exchange-rate:update')]
    public function syncExchangeRate(): Response
    {
        return $this->success($this->logic->syncExchangeRate());
    }
}
