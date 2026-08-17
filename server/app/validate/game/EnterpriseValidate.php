<?php

namespace app\validate\game;

use plugin\saiadmin\basic\BaseValidate;
use plugin\saiadmin\app\model\system\SystemUser;

class EnterpriseValidate extends BaseValidate
{
    protected $rule = [
        'name' => 'require|max:100', 'merchant_limit' => 'require|integer|egt:1',
        'timezone' => 'require|max:64', 'default_language' => 'require|max:16',
        'status' => 'in:0,1',
        'username' => 'require|max:16|unique:' . SystemUser::class, 'password' => 'require|min:6|max:32',
    ];

    protected $scene = [
        'save' => ['name', 'merchant_limit', 'timezone', 'default_language', 'username', 'password'],
        'update' => ['name', 'merchant_limit', 'timezone', 'default_language', 'status'],
        'user' => ['username', 'password'],
        'password' => ['password'],
    ];
}
