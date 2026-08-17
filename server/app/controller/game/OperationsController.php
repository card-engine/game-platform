<?php

namespace app\controller\game;

use app\logic\game\OperationsLogic;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;

class OperationsController extends BaseController
{
    public function __construct()
    {
        $this->logic = new OperationsLogic();
        parent::__construct();
    }

    #[Permission('运营看板', 'app:game:dashboard:index')]
    public function overview(Request $request): Response
    {
        return $this->success($this->logic->overview((int) $this->adminInfo['id'], $this->merchantIds($request)));
    }

    #[Permission('玩家列表', 'app:game:user:index')]
    public function users(Request $request): Response
    {
        return $this->success($this->logic->users((int) $this->adminInfo['id'], $request->more([['merchant_ids', []], ['keyword', ''], ['status', '']]), $this->merchantIds($request)));
    }

    #[Permission('注单列表', 'app:game:bet:index')]
    public function bets(Request $request): Response
    {
        return $this->success($this->logic->monthly((int) $this->adminInfo['id'], 'bets', $request->more([['date_start', gmdate('Y-m-01')], ['date_end', gmdate('Y-m-d')], ['merchant_ids', []], ['keyword', ''], ['status', '']]), $this->merchantIds($request)));
    }

    #[Permission('玩家流水列表', 'app:game:bill:index')]
    public function bills(Request $request): Response
    {
        return $this->success($this->logic->monthly((int) $this->adminInfo['id'], 'bills', $request->more([['date_start', gmdate('Y-m-01')], ['date_end', gmdate('Y-m-d')], ['merchant_ids', []], ['keyword', ''], ['status', '']]), $this->merchantIds($request)));
    }

    #[Permission('商户流水列表', 'app:game:merchant-bill:index')]
    public function merchantBills(Request $request): Response
    {
        return $this->success($this->logic->merchantBills((int) $this->adminInfo['id'], $request->more([['merchant_ids', []], ['date_start', ''], ['date_end', ''], ['keyword', '']]), $this->merchantIds($request)));
    }

    #[Permission('游戏日报', 'app:game:report:index')]
    public function reports(Request $request): Response
    {
        return $this->success($this->logic->reports((int) $this->adminInfo['id'], $request->more([['merchant_ids', []], ['date_start', ''], ['date_end', '']]), $this->merchantIds($request)));
    }

    private function merchantIds(Request $request): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) $request->input('merchant_ids', []))));
        if (!$ids && ($id = (int) $request->header('X-Merchant-Id'))) $ids[] = $id;
        return $ids;
    }
}
