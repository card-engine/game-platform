<?php

namespace app\queue\redis\telegram;

use app\service\telegram\TelegramService;
use Telegram\Bot\Exceptions\TelegramResponseException;
use Webman\RedisQueue\Consumer;
use Webman\RedisQueue\Redis as Queue;

class SendMessage implements Consumer
{
    public string $queue = 'telegram_message';
    public string $connection = 'default';

    public function __construct(private ?TelegramService $telegram = null) {}

    public function consume($data): void
    {
        try {
            ($this->telegram ??= new TelegramService())->deliver($data);
        } catch (\Throwable $error) {
            if ($error instanceof TelegramResponseException && $error->getCode() === 429 && ($data['rate_retries'] ?? 0) < 5) {
                $data['rate_retries'] = ($data['rate_retries'] ?? 0) + 1;
                $delay = max(1, (int) ($error->get('parameters')['retry_after'] ?? 5));
                if (!Queue::send($this->queue, $data, $delay)) throw new \RuntimeException('Telegram限流重试入队失败');
                return;
            }
            // 不保留原异常链，避免队列日志暴露SDK请求URL中的Bot Token。
            throw new \RuntimeException('Telegram发送失败：' . $data['method'] . '，类型=' . $error::class . '，码=' . $error->getCode());
        }
    }
}
