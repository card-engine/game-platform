<?php

namespace app\validate\game;

use plugin\saiadmin\basic\BaseValidate;

class SettingsValidate extends BaseValidate
{
    protected $rule = ['exchange_rate_display_codes' => 'require|array|currencies'];
    protected $scene = ['save' => ['exchange_rate_display_codes']];

    protected function currencies($value): bool
    {
        foreach ($value as $code) if (!is_string($code) || !preg_match('/^[A-Z]{3,16}$/D', $code)) return false;
        return true;
    }
}
