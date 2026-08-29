<?php

namespace app\controller\game;

use app\logic\game\MerchantLogic;
use app\validate\game\MerchantValidate;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;

class MerchantController extends BaseController
{
    public function __construct()
    {
        $this->logic = new MerchantLogic();
        $this->validate = new MerchantValidate();
        parent::__construct();
    }

    #[Permission('商户参数列表', 'app:game:merchant:index')]
    public function index(Request $request): Response
    {
        return $this->success($this->logic->indexList($request->more([['enterprise_id', ''], ['name', ''], ['status', '']])));
    }

    #[Permission('创建商户参数', 'app:game:merchant:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        $this->validate('save', $data);
        return $this->success(['id' => $this->logic->add($data)]);
    }

    #[Permission('修改商户参数', 'app:game:merchant:update')]
    public function update(Request $request): Response
    {
        $data = $request->post();
        $this->validate('update', $data);
        $this->logic->edit($data['id'], $data);
        return $this->success('修改成功');
    }

    #[Permission('回显商户密钥', 'app:game:merchant:secret')]
    public function secret(Request $request): Response
    {
        return $this->success($this->logic->reveal((int) $request->input('id')));
    }

    #[Permission('重置商户密钥', 'app:game:merchant:secret')]
    public function resetSecret(Request $request): Response
    {
        return $this->success($this->logic->resetSecret((int) $request->post('id')));
    }

    public function options(): Response
    {
        return $this->success($this->logic->options());
    }

    #[Permission('查看游戏授权', 'app:game:merchant:index')]
    public function grantState(Request $request): Response
    {
        return $this->success($this->logic->grantState((int) $request->input('id')));
    }

    #[Permission('保存游戏授权', 'app:game:merchant:grant')]
    public function grants(Request $request): Response
    {
        $this->logic->grants((int) $request->post('id'), $request->post('games', []));
        return $this->success('授权已保存');
    }

    #[Permission('调整商户额度', 'app:game:merchant:credit')]
    public function adjustCredit(Request $request): Response
    {
        $id = $this->logic->adjustCredit((int) $request->post('credit_id'), (string) $request->post('amount'), (int) $request->post('direction'), (string) $request->post('remark', ''));
        return $this->success(['id' => $id]);
    }

    #[Permission('配置商户币种', 'app:game:merchant:credit')]
    public function credits(Request $request): Response
    {
        $this->logic->credits((int) $request->post('id'), (array) $request->post('credits', []));
        return $this->success('币种配置已保存');
    }

    #[Permission('查看商户计费方案', 'app:game:merchant:index')]
    public function billingState(Request $request): Response
    {
        return $this->success($this->logic->billingState((int) $request->input('id')));
    }

    #[Permission('配置商户计费方案', 'app:game:merchant:credit')]
    public function billing(Request $request): Response
    {
        $this->logic->billing((int) $request->post('id'), $request->post());
        return $this->success('计费方案已保存');
    }

    #[Permission('更新月费账单', 'app:game:merchant:credit')]
    public function billStatus(Request $request): Response
    {
        $this->logic->billStatus((int) $request->post('id'), (int) $request->post('status'), (string) $request->post('remark', ''));
        return $this->success('账单状态已更新');
    }
}
