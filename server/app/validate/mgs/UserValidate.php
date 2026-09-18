<?php

namespace app\validate\mgs;

use plugin\saiadmin\basic\BaseValidate;

class UserValidate extends BaseValidate
{
    protected $rule = ['nickname' => 'require|max:20'];
    protected $scene = ['update' => ['nickname']];
}
