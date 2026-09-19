<?php

namespace app\controller\mgs;

use app\logic\mgs\SettlementLogic;
use app\validate\mgs\SettlementValidate;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;

class SettlementController extends BaseController
{
    public function __construct()
    {
        $this->logic = new SettlementLogic();
        $this->validate = new SettlementValidate();
        parent::__construct();
    }

    #[Permission('结算详情', 'app:mgs:settlement:index')]
    public function read(Request $request, string $id): Response { return $this->success($this->logic->detail((int) $id)); }

    #[Permission('确认结算', 'app:mgs:settlement:confirm')]
    public function confirm(Request $request, string $id): Response
    {
        $data = $request->only(['remark']);
        $this->validate('confirm', $data);
        $this->logic->confirm((int) $id, $data['remark']);
        return $this->success();
    }

    #[Permission('撤回结算确认', 'app:mgs:settlement:confirm')]
    public function reopen(Request $request, string $id): Response
    {
        $data = $request->only(['remark']);
        $this->validate('confirm', $data);
        $this->logic->reopen((int) $id, $data['remark']);
        return $this->success();
    }

    #[Permission('登记结算付款', 'app:mgs:settlement:pay')]
    public function pay(Request $request, string $id): Response
    {
        $data = $request->only(['remark', 'payment_reference', 'paid_time']);
        $this->validate('pay', $data);
        $this->logic->pay((int) $id, $data['payment_reference'], $data['paid_time'], $data['remark']);
        return $this->success();
    }
}
