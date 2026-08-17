<?php

namespace app\controller\game;

use app\logic\game\PlatformAdminLogic;
use app\validate\game\PlatformAdminValidate;
use plugin\saiadmin\basic\BaseController;
use plugin\saiadmin\service\Permission;
use support\Request;
use support\Response;

class PlatformAdminController extends BaseController
{
    public function __construct()
    {
        $this->logic = new PlatformAdminLogic();
        $this->validate = new PlatformAdminValidate();
        parent::__construct();
    }

    #[Permission('平台账号列表', 'app:game:platform-admin:index')]
    public function index(Request $request): Response
    {
        return $this->success($this->logic->indexList($request->more([['keyword', ''], ['role_code', ''], ['enterprise_id', ''], ['status', '']])));
    }

    #[Permission('创建平台账号', 'app:game:platform-admin:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        $this->validate('save', $data);
        return $this->success(['id' => $this->logic->add($data)]);
    }

    #[Permission('修改平台账号', 'app:game:platform-admin:update')]
    public function update(Request $request): Response
    {
        $data = $request->post();
        $this->validate('update', $data);
        $this->logic->edit((int) $data['id'], $data);
        return $this->success('修改成功');
    }

    #[Permission('删除平台账号', 'app:game:platform-admin:delete')]
    public function destroy(Request $request): Response
    {
        $this->logic->destroy((int) $request->post('id'));
        return $this->success('删除成功');
    }

    #[Permission('修改平台账号', 'app:game:platform-admin:update')]
    public function password(Request $request): Response
    {
        $data = $request->post();
        $this->validate('password', $data);
        $this->logic->password((int) $data['id'], (string) $data['password']);
        return $this->success('密码已修改');
    }

    #[Permission('平台账号列表', 'app:game:platform-admin:index')]
    public function options(): Response
    {
        return $this->success($this->logic->options());
    }
}
