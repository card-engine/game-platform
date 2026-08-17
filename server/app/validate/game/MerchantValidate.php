<?php

namespace app\validate\game;

use plugin\saiadmin\basic\BaseValidate;

class MerchantValidate extends BaseValidate
{
    protected $rule = [
        'enterprise_id' => 'require|integer', 'name' => 'require|max:100', 'wallet_mode' => 'require|in:1',
        'callback_url' => 'url|max:255', 'language_codes' => 'require|array', 'default_language' => 'require|max:16',
        'gc_exchange_rate' => 'float|gt:0',
        'timezone' => 'require|max:64', 'timeout_ms' => 'integer|between:500,30000',
    ];

    protected $scene = [
        'save' => ['enterprise_id', 'name', 'wallet_mode', 'callback_url', 'language_codes', 'default_language', 'timezone', 'timeout_ms'],
        'update' => ['name', 'wallet_mode', 'callback_url', 'language_codes', 'default_language', 'timezone', 'timeout_ms'],
    ];
}
