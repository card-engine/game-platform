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
];
