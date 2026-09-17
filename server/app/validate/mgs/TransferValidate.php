<?php

namespace app\validate\mgs;

use plugin\saiadmin\basic\BaseValidate;

class TransferValidate extends BaseValidate
{
    protected $rule = ['mgs_recharge_id' => 'require|integer|gt:0', 'remark' => 'require|max:500', 'status' => 'require|in:review,ignored'];
    protected $scene = ['credit' => ['mgs_recharge_id', 'remark'], 'review' => ['status', 'remark']];
}
