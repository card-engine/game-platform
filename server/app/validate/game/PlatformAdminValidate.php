<?php

namespace app\validate\game;

use plugin\saiadmin\app\model\system\SystemUser;
use plugin\saiadmin\basic\BaseValidate;

class PlatformAdminValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer', 'username' => 'require|max:16', 'password' => 'require|min:6|max:32',
        'realname' => 'max:64', 'role_code' => 'require|in:game_super_admin,enterprise_owner,enterprise_staff',
        'enterprise_id' => 'integer', 'status' => 'require|in:1,2',
    ];

    protected $scene = [
        'save' => ['username' => 'require|max:16|unique:' . SystemUser::class, 'password', 'realname', 'role_code', 'enterprise_id', 'status'],
        'update' => ['id', 'username', 'realname', 'role_code', 'enterprise_id', 'status'],
        'password' => ['id', 'password'],
    ];
}
