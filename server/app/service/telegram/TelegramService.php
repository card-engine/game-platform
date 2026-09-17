<?php

namespace app\service\telegram;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\StreamHandler;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramResponseException;
use Telegram\Bot\HttpClients\GuzzleHttpClient;
use Telegram\Bot\Objects\Update;
use support\Log;
use Webman\RedisQueue\Redis as Queue;

class TelegramService
{
    private Api $api;
    private string $username = '';

    public function __construct(?Api $api = null)
    {
        $this->api = $api ?? new Api(config('telegram.token'), false,
            new GuzzleHttpClient(new Client(['handler' => new StreamHandler()])));
        $this->api->setConnectTimeOut(5)->setTimeOut(35);
    }

    public function start(): void
    {
        $bot = $this->api->getMe();
        if ((int) $bot->id !== (int) config('telegram.bot_id')) throw new \RuntimeException('TELEGRAM_BOT_ID与Token不匹配');
        $this->username = (string) $bot->username;
        $this->api->deleteWebhook();
    }

    public function updates(int $offset): array
    {
        return $this->api->getUpdates([
            'offset' => $offset, 'timeout' => 25, 'limit' => 20,
            'allowed_updates' => json_encode(['message', 'callback_query']),
        ], false);
    }

    public function handle(Update $update): void
    {
        $data = $update->toArray();
        $callback = $data['callback_query'] ?? null;
        $message = $callback['message'] ?? $data['message'] ?? null;
        if ($callback) {
            // 必须立即应答；过期按钮不应阻断后续更新。
            try {
                $this->api->answerCallbackQuery(['callback_query_id' => $callback['id']]);
            } catch (TelegramResponseException $error) {
                if ($error->getCode() !== 400) throw $error;
                Log::notice('Telegram按钮应答已失效', ['update_id' => $data['update_id']]);
            }
        }
        if (!$message || ($data['message']['from']['is_bot'] ?? false)) return;
        if ($callback) {
            $handler = config('telegram.callbacks')[$callback['data'] ?? ''] ?? null;
        } else {
            if (!preg_match('/^\/([a-z0-9_]+)(?:@([a-z0-9_]+))?(?:\s|$)/i', $message['text'] ?? '', $matches)) return;
            if (!empty($matches[2]) && strcasecmp($matches[2], $this->username) !== 0) return;
            $handler = config('telegram.commands')[strtolower($matches[1])] ?? null;
        }
        if ($handler) $handler($message, $data);
    }

    /** 业务只投递任务，不创建SDK实例、不访问Telegram。参数沿用Bot API。 */
    public static function enqueue(string $method, array $params): bool
    {
        if (!in_array($method, ['sendMessage', 'editMessageText', 'deleteMessage'], true)) {
            throw new \InvalidArgumentException('Telegram消息方法不支持进入队列');
        }
        if (!config('telegram.token') || empty($params['chat_id'])) return false;
        if (!Queue::send('telegram_message', ['method' => $method, 'params' => $params])) {
            throw new \RuntimeException('Telegram消息入队失败');
        }
        return true;
    }

    /** 仅由队列消费者调用；getUpdates、answerCallbackQuery不能经此入口执行。 */
    public function deliver(array $data): void
    {
        $params = $data['params'];
        if (isset($params['reply_markup']) && is_array($params['reply_markup'])) {
            $params['reply_markup'] = json_encode($params['reply_markup'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        match ($data['method']) {
            'sendMessage' => $this->api->sendMessage($params),
            'editMessageText' => $this->api->editMessageText($params),
            'deleteMessage' => $this->api->deleteMessage($params),
            default => throw new \InvalidArgumentException('Telegram消息方法无效'),
        };
    }
}
