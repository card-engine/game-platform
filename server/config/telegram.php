<?php

$token = (string) env('TELEGRAM_BOT_TOKEN', '');

return [
    'token' => $token,
    'bot_id' => (int) (env('TELEGRAM_BOT_ID') ?: explode(':', $token)[0]),
    'chat_ids' => [
        'ops' => (string) env('TELEGRAM_OPS_CHAT_ID', ''),
        'business' => (string) env('TELEGRAM_BUSINESS_CHAT_ID', ''),
        'customer' => (string) env('TELEGRAM_CUSTOMER_CHAT_ID', ''),
    ],
    // 命令和 callback_data 精确映射，业务实现放在 Handler。
    'commands' => [
        'start' => [app\service\telegram\handler\SystemHandler::class, 'help'],
        'help' => [app\service\telegram\handler\SystemHandler::class, 'help'],
        'status' => [app\service\telegram\handler\SystemHandler::class, 'status'],
    ],
    'callbacks' => [
        'system:status' => [app\service\telegram\handler\SystemHandler::class, 'status'],
    ],
];
