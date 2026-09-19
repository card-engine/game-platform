<?php

namespace app\validate\game;

use plugin\saiadmin\basic\BaseValidate;

class OpenApiValidate extends BaseValidate
{
    protected $rule = [
        'user_id' => 'require',
        'game_id' => 'require',
        'month' => ['regex:/^\d{4}-(0[1-9]|1[0-2])$/D'],
        'ip' => 'filter:validate_ip',
    ];

    protected $message = ['ip.filter' => 'ip 必须是有效的 IPv4 或 IPv6 地址'];
    protected $scene = ['launch' => ['user_id', 'game_id', 'ip'], 'monthlyBills' => ['month']];
}
