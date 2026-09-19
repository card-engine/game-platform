<?php

namespace app\validate\mgs;

use plugin\saiadmin\basic\BaseValidate;

class UserValidate extends BaseValidate
{
    protected $rule = ['nickname' => 'require|max:20', 'id' => 'require|integer|gt:0', 'status' => 'require|in:0,1', 'remark' => 'require|max:500'];
    protected $scene = ['update' => ['nickname'], 'status' => ['id', 'status', 'remark']];
}
