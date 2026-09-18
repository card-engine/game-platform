<?php

namespace app\controller\mgs;

use app\logic\mgs\RechargeAdminLogic;
use app\logic\mgs\TransferLogic;
use app\validate\mgs\TransferValidate;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;

class RechargeController extends BaseController
{
    public function __construct()
    {
        $this->logic = new RechargeAdminLogic();
        $this->validate = new TransferValidate();
        parent::__construct();
    }

    #[Permission('充值列表', 'app:mgs:recharge:index')]
    public function index(Request $request): Response
    {
        return $this->success($this->logic->recharges($request->only(['keyword', 'status', 'currency_code'])));
    }

    #[Permission('充值详情', 'app:mgs:recharge:index')]
    public function read(Request $request, string $id): Response
    {
        return $this->success($this->logic->detail('recharge', $id));
    }

    #[Permission('链上收款列表', 'app:mgs:transfer:index')]
    public function transfers(Request $request): Response
    {
        return $this->success($this->logic->transfers($request->only(['keyword', 'status', 'currency_code'])));
    }

    #[Permission('链上收款详情', 'app:mgs:transfer:index')]
    public function transfer(Request $request, string $id): Response
    {
        return $this->success($this->logic->detail('transfer', $id));
    }

    #[Permission('核验收款入账', 'app:mgs:recharge:credit')]
    public function credit(Request $request, string $id): Response
    {
        $data = $request->only(['mgs_recharge_id', 'remark']);
        $this->validate('credit', $data);
        (new TransferLogic())->credit((int) $id, (int) $data['mgs_recharge_id'], $this->adminId, $data['remark']);
        return $this->success();
    }

    #[Permission('核验收款备注', 'app:mgs:recharge:review')]
    public function review(Request $request, string $id): Response
    {
        $data = $request->only(['status', 'remark']);
        $this->validate('review', $data);
        $this->logic->review((int) $id, $data['status'], $data['remark'], $this->adminId);
        return $this->success();
    }

    #[Permission('区块状态', 'app:mgs:scan:index')]
    public function scan(): Response
    {
        return $this->success($this->logic->scan());
    }
}
