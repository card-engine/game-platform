<?php

return [
    'secret_key' => (string) env('GAME_SECRET_KEY', ''),
    'self_merchant' => [
        'callback_url' => rtrim((string) env('GAME_SELF_MERCHANT_CALLBACK_URL', ''), '/'),
        'secret' => (string) env('GAME_SELF_MERCHANT_SECRET', ''),
        'currencies' => array_values(array_filter(array_map('trim', explode(',', strtoupper((string) env('GAME_SELF_MERCHANT_CURRENCIES', 'INR')))))),
        'user_id' => (string) env('GAME_SELF_MERCHANT_USER_ID', 'demo'),
        'language' => (string) env('GAME_SELF_MERCHANT_LANGUAGE', 'en'),
        'timezone' => (string) env('GAME_SELF_MERCHANT_TIMEZONE', 'UTC'),
        'timeout_ms' => (int) env('GAME_SELF_MERCHANT_TIMEOUT_MS', 5000),
        'balance' => (string) env('GAME_SELF_MERCHANT_BALANCE', '1000000'),
        'back_url' => (string) env('GAME_SELF_MERCHANT_BACK_URL', ''),
    ],
    'platforms' => [
        'wxgame' => [
            'name' => 'WXGAME',
            'is_gc' => false,
            'default_currency' => (string) env('GAME_WXGAME_DEFAULT_CURRENCY', 'USD'),
            'accounts' => [
                'sc' => [
                    'url' => rtrim((string) env('GAME_WXGAME_URL', ''), '/'),
                    'app_id' => (string) env('GAME_WXGAME_APP_ID', ''),
                    'app_key' => (string) env('GAME_WXGAME_APP_KEY', ''),
                    'app_secret' => (string) env('GAME_WXGAME_APP_SECRET', ''),
                ],
                'gc' => [
                    'url' => rtrim((string) env('GAME_WXGAME_GC_URL', ''), '/'),
                    'app_id' => (string) env('GAME_WXGAME_GC_APP_ID', ''),
                    'app_key' => (string) env('GAME_WXGAME_GC_APP_KEY', ''),
                    'app_secret' => (string) env('GAME_WXGAME_GC_APP_SECRET', ''),
                    'currency' => (string) env('GAME_WXGAME_GC_CURRENCY', 'WST'),
                ],
            ],
        ],
        'acewin' => [
            'name' => 'AceWin',
            'is_gc' => true,
            'url' => rtrim((string) env('GAME_ACEWIN_URL', ''), '/'),
            'agent_id' => (string) env('GAME_ACEWIN_AGENT_ID', ''),
            'agent_key' => (string) env('GAME_ACEWIN_AGENT_KEY', ''),
            'basic_auth_username' => (string) env('GAME_ACEWIN_BASIC_AUTH_USERNAME', ''),
            'basic_auth_password' => (string) env('GAME_ACEWIN_BASIC_AUTH_PASSWORD', ''),
        ],
        'tada' => [
            'name' => 'TADA',
            'is_gc' => true,
            'url' => rtrim((string) env('GAME_TADA_URL', ''), '/') . '/',
            'agent_id' => (string) env('GAME_TADA_AGENT_ID', ''),
            'agent_key' => (string) env('GAME_TADA_AGENT_KEY', ''),
            'currency' => (string) env('GAME_TADA_CURRENCY', 'SC'),
            'basic_auth_username' => (string) env('GAME_TADA_BASIC_AUTH_USERNAME', ''),
            'basic_auth_password' => (string) env('GAME_TADA_BASIC_AUTH_PASSWORD', ''),
        ],
        'goldengatex' => [
            'name' => 'GoldenGateX',
            'url' => rtrim((string) env('GAME_GOLDENGATEX_URL', ''), '/'),
            'client_id' => (string) env('GAME_GOLDENGATEX_CLIENT_ID', ''),
            'client_secret' => (string) env('GAME_GOLDENGATEX_CLIENT_SECRET', ''),
            'currency' => (string) env('GAME_GOLDENGATEX_CURRENCY', 'USD'),
        ],
    ],
];
