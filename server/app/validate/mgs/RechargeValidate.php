<?php

namespace app\validate\mgs;

use app\logic\mgs\RechargeLogic;
use plugin\saiadmin\basic\BaseValidate;

class RechargeValidate extends BaseValidate
{
    protected $rule = [
        'currency_code' => 'require|regex:/^[A-Z]{3,16}$/D',
        'pay_currency_code' => 'require|in:USDT,TRX',
        'recharge_amount' => 'require|integer|checkAmount',
        'request_id' => 'require|regex:/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
        'quote_key' => 'require|regex:/^[a-f0-9]{64}$/D',
    ];

    protected $scene = [
        'options' => ['currency_code'],
        'save' => ['currency_code', 'pay_currency_code', 'recharge_amount', 'request_id', 'quote_key'],
    ];

    protected function checkAmount($value): bool
    {
        return (is_int($value) || is_string($value)) && in_array((string) $value, array_map('strval', RechargeLogic::AMOUNTS), true);
    }
}
