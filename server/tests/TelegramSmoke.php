<?php

/** 模拟Telegram HTTP响应、独立Redis前缀；不读取.env，不发送真实消息。 */
use app\enum\RedisKey;
use app\process\TelegramPolling;
use app\queue\redis\telegram\SendMessage;
use app\service\telegram\TelegramService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use support\Redis;
use Telegram\Bot\Api;
use Telegram\Bot\HttpClients\GuzzleHttpClient;
use Telegram\Bot\Objects\Update;
use Webman\Config;
use Webman\Event\Event;

require dirname(__DIR__) . '/vendor/autoload.php';

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function settings(array $settings): void {
    (new ReflectionProperty(Config::class, 'config'))->setValue(null, $settings);
    (new ReflectionProperty(Config::class, 'flatCache'))->setValue(null, []);
}
function response(mixed $result): Response { return new Response(200, [], json_encode(['ok' => true, 'result' => $result])); }
function queued(): array {
    $raw = Webman\RedisQueue\Redis::connection()->rPop('{redis-queue}-waitingtelegram_message');
    return $raw ? json_decode($raw, true)['data'] : [];
}
function message(string $text, int $chat = -1001): array {
    return ['message_id' => 8, 'date' => time(), 'chat' => ['id' => $chat, 'type' => 'supergroup'],
        'from' => ['id' => 7, 'is_bot' => false], 'text' => $text];
}

$port = (int) getenv('MGS_TEST_REDIS_PORT');
check($port > 0, '必须指定隔离Redis端口');
$prefix = '__telegram_smoke_' . getmypid() . ':';
$config = ['telegram' => require dirname(__DIR__) . '/config/telegram.php',
    'redis' => ['default' => ['host' => '127.0.0.1', 'port' => $port, 'password' => '', 'database' => 0, 'prefix' => $prefix]],
    'redis_queue' => ['default' => ['host' => 'redis://127.0.0.1:' . $port, 'options' => ['db' => 0, 'prefix' => $prefix]]],
    'log' => ['default' => ['handlers' => [['class' => Monolog\Handler\NullHandler::class, 'constructor' => []]]]]];
$config['telegram'] = array_replace($config['telegram'], ['token' => '123:TEST_ONLY', 'bot_id' => 123,
    'chat_ids' => ['ops' => '-1001', 'business' => '-1002', 'customer' => '']]);
settings($config);
$redis = new \Redis();
$redis->connect('127.0.0.1', $port);
$history = [];
$mock = new MockHandler();
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$api = new Api('123:TEST_ONLY', false, new GuzzleHttpClient(new Client(['handler' => $stack])));
$service = new TelegramService($api);
$bot = ['id' => 123, 'is_bot' => true, 'first_name' => 'test', 'username' => 'mgames_test_bot'];
$lock = RedisKey::LockTelegramPolling->format(123);
$checkpoint = RedisKey::ForeverTelegramUpdateId->format(123);

try {
    $mock->append(response($bot), response(true), response([['update_id' => 10, 'message' => message('/start')]]));
    $poll = new TelegramPolling($service);
    $poll->poll();
    check((int) Redis::get($checkpoint) === 10, '处理成功后未推进断点');
    check(count($history) === 3, '命令回复不应直接请求Telegram');
    parse_str($history[2]['request']->getUri()->getQuery(), $query);
    check($query['offset'] === '1' && $query['timeout'] === '25'
        && json_decode($query['allowed_updates'], true) === ['message', 'callback_query'], '轮询参数编码错误');
    $task = queued();
    check($task['method'] === 'sendMessage' && $task['params']['chat_id'] === -1001, '帮助没有入队');
    $mock->append(response(message('reply')));
    (new SendMessage($service))->consume($task);
    parse_str((string) $history[3]['request']->getBody(), $body);
    check(json_decode($body['reply_markup'], true)['inline_keyboard'][0][0]['callback_data'] === 'system:status', '按钮JSON错误');

    $service->handle(new Update(['update_id' => 11, 'message' => message('/help@other_bot')]));
    $service->handle(new Update(['update_id' => 12, 'message' => message('/status', -1002)]));
    check(queued() === [], '其他机器人命令或非运维群越权');
    $service->handle(new Update(['update_id' => 13, 'message' => message("/help@MGAMES_TEST_BOT\nargs")]));
    check(queued()['method'] === 'sendMessage', '指定本机器人命令未识别');

    $mock->append(new Response(400, [], json_encode(['ok' => false, 'error_code' => 400, 'description' => 'query is too old'])));
    $service->handle(new Update(['update_id' => 14, 'callback_query' => ['id' => 'expired', 'from' => ['id' => 7],
        'data' => 'system:status', 'message' => message('button')]]));
    check(str_ends_with(end($history)['request']->getUri()->getPath(), '/answerCallbackQuery'), '按钮未直接应答');
    check(str_contains(queued()['params']['text'], '未就绪'), '过期按钮阻断Handler');
    foreach (['getUpdates', 'answerCallbackQuery'] as $method) {
        try { TelegramService::enqueue($method, ['chat_id' => -1001]); throw new RuntimeException('直接调用误入队'); }
        catch (InvalidArgumentException) {}
    }

    foreach (['editMessageText' => ['text' => 'changed'], 'deleteMessage' => []] as $method => $params) {
        TelegramService::enqueue($method, ['chat_id' => -1001, 'message_id' => 8] + $params);
        $mock->append(response(true));
        (new SendMessage($service))->consume(queued());
        check(str_ends_with(end($history)['request']->getUri()->getPath(), '/' . $method), '编辑/删除没有经SDK发送');
    }

    $other = new TelegramPolling($service);
    $before = count($history);
    $other->poll();
    $other->onWorkerStop();
    check(count($history) === $before && Redis::exists($lock), '第二实例抢锁或误删锁');
    $poll->onWorkerStop();
    $mock->append(response($bot), response(true), response([]));
    $other->poll();
    parse_str(end($history)['request']->getUri()->getQuery(), $query);
    check($query['offset'] === '11', '重启后未从断点继续');
    $other->onWorkerStop();

    $poll = new TelegramPolling($service);
    $mock->append(response($bot), response(true), function () use ($lock) {
        Redis::set($lock, 'new-owner', 'EX', 120);
        return response([['update_id' => 20, 'message' => message('/help')]]);
    });
    $poll->poll();
    check((int) Redis::get($checkpoint) === 10 && queued() === [], '请求期间失锁仍派发消息');
    $poll->onWorkerStop();
    check(Redis::get($lock) === 'new-owner', '旧实例释放新实例锁');
    Redis::del($lock);

    $config['telegram']['commands']['fail'] = static function () { throw new RuntimeException('business error'); };
    settings($config);
    $mock->append(response($bot), response(true), response([['update_id' => 21, 'message' => message('/fail')]]));
    $poll->poll();
    check((int) Redis::get($checkpoint) === 10, '处理失败仍推进断点');
    $mock->append(response([['update_id' => 21, 'message' => message('/help')]]));
    $poll->poll();
    check((int) Redis::get($checkpoint) === 21 && queued()['method'] === 'sendMessage', '失败后不能恢复');
    $poll->onWorkerStop();

    foreach (require dirname(__DIR__) . '/config/event.php' as $name => $listener) Event::on($name, $listener);
    Event::emit('mgs.recharge.paid', ['recharge_no' => 'TEST', 'user_id' => '1', 'pay_amount' => '1.2301',
        'pay_currency_code' => 'TRX', 'recharge_amount' => '100', 'currency_code' => 'INR', 'credited_time' => '2026-01-01 00:00:00.000']);
    $notice = queued();
    check($notice['params']['chat_id'] === '-1002' && str_contains($notice['params']['text'], '1.2301 TRX'), '充值通知群或币种错误');
    Event::emit('mgs.tron.status', ['ready' => false, 'error_type' => 'TestFailure']);
    check(queued()['params']['chat_id'] === '-1001', '扫描异常未发运维群');
    Event::emit('mgs.tron.status', ['ready' => true]);
    check(str_contains(queued()['params']['text'], '已恢复'), '扫描恢复通知缺失');

    $mock->append(new Response(429, [], json_encode(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 60]])));
    (new SendMessage($service))->consume($notice);
    $delayed = $redis->zRange($prefix . '{redis-queue}-delayed', 0, -1, true);
    check(count($delayed) === 1 && array_values($delayed)[0] >= time() + 59, '未遵循429 retry_after');
    check(json_decode(array_key_first($delayed), true)['data']['rate_retries'] === 1, '限流次数未累计');
    $mock->append(new RuntimeException('https://api.telegram.org/bot123:TEST_ONLY/request'));
    try { (new SendMessage($service))->consume($notice); throw new LogicException('发送异常未抛出'); }
    catch (RuntimeException $error) {
        check(!str_contains((string) $error, 'TEST_ONLY') && $error->getPrevious() === null, '异常泄露Token');
    }

    $config['telegram']['token'] = '';
    settings($config);
    check(!TelegramService::enqueue('sendMessage', ['chat_id' => -1001, 'text' => 'disabled']), '未配置Token仍发送');
    (new TelegramPolling())->onWorkerStart();
    if (in_array('--process', $argv, true)) {
        $config['telegram']['token'] = '123:TEST_ONLY';
        settings($config);
        $mock->append(response($bot), response(true), response([['update_id' => 30, 'message' => message('/help')]]));
        $process = new TelegramPolling($service);
        $worker = new Workerman\Worker();
        $worker->name = 'telegram-smoke';
        $worker->onWorkerStart = function () use ($process, $checkpoint) {
            $process->onWorkerStart();
            Workerman\Timer::add(1.5, function () use ($checkpoint) {
                check((int) Redis::get($checkpoint) === 30 && queued()['method'] === 'sendMessage', '真实Worker未执行轮询');
                posix_kill(posix_getppid(), SIGINT);
            }, [], false);
        };
        $worker->onWorkerStop = [$process, 'onWorkerStop'];
        Workerman\Worker::$pidFile = sys_get_temp_dir() . '/' . $prefix . '.pid';
        Workerman\Worker::$logFile = sys_get_temp_dir() . '/' . $prefix . '.log';
        Workerman\Worker::$onWorkerExit = static function ($worker, int $status) {
            if ($status !== 0) Workerman\Worker::stopAll(1);
        };
        Workerman\Worker::$onMasterStop = static function () use ($port, $prefix, $lock) {
            $connection = new \Redis();
            $connection->connect('127.0.0.1', $port);
            check(!$connection->exists($prefix . $lock), 'Worker停止后锁未释放');
            $keys = $connection->keys($prefix . '*');
            if ($keys) $connection->del($keys);
            echo "TelegramSmoke Worker OK: 真实进程启动、定时轮询、停止释放锁\n";
        };
        $argv = [__FILE__, 'start'];
        Workerman\Worker::runAll();
    }
    echo "TelegramSmoke OK: SDK收发、同步应答、群权限、队列、事件、锁接管、断点、失败重试、429和Token脱敏\n";
} finally {
    $keys = $redis->keys($prefix . '*');
    if ($keys) $redis->del($keys);
}
