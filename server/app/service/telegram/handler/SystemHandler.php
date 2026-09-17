<?php

namespace app\service\telegram\handler;

use app\service\mgs\TronScanService;
use app\service\telegram\TelegramService;

class SystemHandler
{
    public static function help(array $message): void
    {
        $params = ['chat_id' => $message['chat']['id'], 'text' => "MGames\n/help 帮助\n/status 扫描状态（仅运维群）"];
        if ((string) $message['chat']['id'] === config('telegram.chat_ids.ops')) {
            $params['reply_markup'] = ['inline_keyboard' => [[['text' => '扫描状态', 'callback_data' => 'system:status']]]];
        }
        TelegramService::enqueue('sendMessage', $params);
    }

    public static function status(array $message): void
    {
        if ((string) $message['chat']['id'] !== config('telegram.chat_ids.ops')) return;
        $status = (new TronScanService())->status();
        TelegramService::enqueue('sendMessage', ['chat_id' => $message['chat']['id'],
            'text' => 'TRON扫描：' . ($status['ready'] ? '正常' : '未就绪')
                . "\n下一高度：" . ($status['checkpoint']['next_block_number'] ?? '未启动')
                . "\n待补扫任务：" . count($status['gaps'])]);
    }

    public static function scanChanged(array $data): void
    {
        TelegramService::enqueue('sendMessage', ['chat_id' => config('telegram.chat_ids.ops'),
            'text' => $data['ready'] ? 'TRON实时扫描已恢复。' : "TRON实时扫描异常，正在自动重试。\n错误类型：" . $data['error_type']]);
    }
}
