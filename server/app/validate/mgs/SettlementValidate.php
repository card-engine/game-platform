<?php

namespace app\validate\mgs;

use plugin\saiadmin\basic\BaseValidate;

class SettlementValidate extends BaseValidate
{
    protected $rule = [
        'remark' => 'require|max:300',
        'payment_reference' => 'require|max:180',
        'paid_time' => 'require|dateFormat:Y-m-d H:i:s|before:now',
    ];
    protected $scene = ['confirm' => ['remark'], 'pay' => ['remark', 'payment_reference', 'paid_time']];
}
