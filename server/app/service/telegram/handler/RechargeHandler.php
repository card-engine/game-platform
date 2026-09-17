<?php

namespace app\service\telegram\handler;

use app\service\telegram\TelegramService;

class RechargeHandler
{
    public static function paid(array $order): void
    {
        TelegramService::enqueue('sendMessage', ['chat_id' => config('telegram.chat_ids.business'),
            'text' => "充值到账\n订单：{$order['recharge_no']}\n用户ID：{$order['user_id']}"
                . "\n付款：{$order['pay_amount']} {$order['pay_currency_code']}"
                . "\n到账：{$order['recharge_amount']} {$order['currency_code']}"
                . "\n时间（UTC）：{$order['credited_time']}"]);
    }
}
