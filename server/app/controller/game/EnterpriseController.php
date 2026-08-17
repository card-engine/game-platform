<?php

namespace app\controller\game;

use app\logic\game\EnterpriseLogic;
use app\validate\game\EnterpriseValidate;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;

class EnterpriseController extends BaseController
{
    public function __construct()
    {
        $this->logic = new EnterpriseLogic();
        $this->validate = new EnterpriseValidate();
        parent::__construct();
    }

    #[Permission('企业列表', 'app:game:enterprise:index')]
    public function index(Request $request): Response
    {
        return $this->success($this->logic->indexList($request->more([['name', ''], ['status', '']])));
    }

    #[Permission('创建企业', 'app:game:enterprise:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        $this->validate('save', $data);
        return $this->success(['id' => $this->logic->addWithOwner($data)]);
    }

    #[Permission('修改企业', 'app:game:enterprise:update')]
    public function update(Request $request): Response
    {
        $data = $request->post();
        $this->validate('update', $data);
        $this->logic->edit($data['id'], $data);
        return $this->success('修改成功');
    }

    #[Permission('企业账号列表', 'app:game:enterprise:user:index')]
    public function users(Request $request): Response
    {
        return $this->success($this->logic->users((int) $request->input('enterprise_id')));
    }

    #[Permission('创建企业子账号', 'app:game:enterprise:user:save')]
    public function saveUser(Request $request): Response
    {
        $data = $request->post();
        $this->validate('user', $data);
        return $this->success(['id' => $this->logic->addUser($data)]);
    }

    #[Permission('启停企业子账号', 'app:game:enterprise:user:update')]
    public function userStatus(Request $request): Response
    {
        $this->logic->setUserStatus((int) $request->post('id'), (int) $request->post('status'));
        return $this->success('操作成功');
    }

    #[Permission('设置企业子账号商户范围', 'app:game:enterprise:user:update')]
    public function userMerchants(Request $request): Response
    {
        $this->logic->setUserMerchants((int) $request->post('id'), (array) $request->post('merchant_ids', []));
        return $this->success('商户范围已更新');
    }

    #[Permission('修改企业子账号密码', 'app:game:enterprise:user:update')]
    public function userPassword(Request $request): Response
    {
        $data = $request->post();
        $this->validate('password', $data);
        $this->logic->setUserPassword((int) $data['id'], (string) $data['password']);
        return $this->success('密码已修改');
    }

    public function roles(): Response
    {
        return $this->success($this->logic->enterpriseRoles());
    }
}
