<?php

return [
    'platform_url' => rtrim((string) env('MGS_GAME_PLATFORM_URL', env('APP_URL', 'http://127.0.0.1:8787')), '/'),
    'callback_url' => rtrim((string) env('MGS_GAME_PLATFORM_CALLBACK_URL', 'http://127.0.0.1:8787/api/mgames'), '/'),
    'mch_id' => (string) env('MGS_GAME_PLATFORM_MCH_ID', ''),
    'secret' => (string) env('MGS_GAME_PLATFORM_SECRET', env('GAME_SECRET_KEY', '')),
    'api_secret' => (string) env('MGS_API_SECRET', ''),
    'default_currency' => strtoupper((string) env('MGS_DEFAULT_CURRENCY', 'USD')),
    'default_language' => (string) env('MGS_DEFAULT_LANGUAGE', 'en'),
    'timezone' => (string) env('MGS_TIMEZONE', 'UTC'),
    // 默认禁止新订单；开启后仍须初始化扫描并通过健康检查。
    'recharge_enabled' => filter_var(env('MGS_RECHARGE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'recharge_tron_receive_address' => (string) env('MGS_RECHARGE_TRON_RECEIVE_ADDRESS', ''),
    'recharge_tron_usdt_contract' => (string) env('MGS_RECHARGE_TRON_USDT_CONTRACT', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'),
    'tron' => [
        'tron_url' => rtrim((string) env('TRON_URL', 'https://api.trongrid.io'), '/'),
        'api_keys' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRON_API_KEYS', ''))))),
    ],
    'okx_base_url' => rtrim((string) env('OKX_BASE_URL', 'https://www.okx.com'), '/'),
    'okx_ticker_ttl' => (int) env('OKX_TICKER_TTL', 120),
];
