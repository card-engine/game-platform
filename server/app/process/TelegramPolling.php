<?php

namespace app\process;

use app\enum\RedisKey;
use app\service\telegram\TelegramService;
use support\Log;
use support\Redis;
use Workerman\Timer;

class TelegramPolling
{
    // 同Token续租或锁过期后接管；绝不覆盖其他实例的Token。
    public const LEASE = <<<'LUA'
local owner = redis.call('get', KEYS[1])
if owner and owner ~= ARGV[1] then return 0 end
redis.call('set', KEYS[1], ARGV[1], 'EX', ARGV[2]); return 1
LUA;
    public const CHECKPOINT = <<<'LUA'
if redis.call('get', KEYS[1]) ~= ARGV[1] then return 0 end
redis.call('set', KEYS[2], ARGV[2]); return 1
LUA;
    private string $token;
    private string $lock;
    private string $checkpoint;
    private bool $started = false;
    private int $errorTime = 0;

    public function __construct(private ?TelegramService $telegram = null)
    {
        $this->token = bin2hex(random_bytes(16));
        $this->lock = RedisKey::LockTelegramPolling->format(config('telegram.bot_id'));
        $this->checkpoint = RedisKey::ForeverTelegramUpdateId->format(config('telegram.bot_id'));
    }

    public function onWorkerStart(): void
    {
        if (!config('telegram.token')) return;
        // 默认事件循环、单进程同步轮询，不影响HTTP和资金队列。
        Timer::add(1, [$this, 'poll']);
    }

    public function poll(): void
    {
        try {
            if (!Redis::eval(self::LEASE, 1, $this->lock, $this->token, RedisKey::EXPIRE_2_MINUTES)) {
                $this->started = false;
                return;
            }
            $this->telegram ??= new TelegramService();
            if (!$this->started) {
                $this->telegram->start();
                $this->started = true;
            }
            $offset = (int) Redis::get($this->checkpoint);
            foreach ($this->telegram->updates($offset + 1) as $update) {
                // 请求返回后校验租约，失锁的旧进程不能派发或推进断点。
                if (!Redis::eval("if redis.call('get',KEYS[1])==ARGV[1] then return redis.call('expire',KEYS[1],ARGV[2]) end return 0",
                    1, $this->lock, $this->token, RedisKey::EXPIRE_2_MINUTES)) {
                    $this->started = false;
                    return;
                }
                $this->telegram->handle($update);
                if (!Redis::eval(self::CHECKPOINT, 2, $this->lock, $this->checkpoint, $this->token, $update->get('update_id'))) {
                    $this->started = false;
                    return;
                }
            }
            $this->errorTime = 0;
        } catch (\Throwable $error) {
            if (time() - $this->errorTime >= RedisKey::EXPIRE_1_MINUTE) {
                // SDK异常可能携带包含Token的URL，只记录类型和错误码。
                Log::warning('Telegram轮询失败，将自动重试', ['error_type' => $error::class, 'code' => $error->getCode()]);
                $this->errorTime = time();
            }
        }
    }

    public function onWorkerStop(): void
    {
        if (!config('telegram.token')) return;
        Redis::eval("if redis.call('get',KEYS[1])==ARGV[1] then return redis.call('del',KEYS[1]) end return 0", 1, $this->lock, $this->token);
    }
}
