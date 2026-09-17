<?php

namespace app\service\mgs;

use app\enum\RedisKey;
use RuntimeException;
use support\Redis;
use Webman\RedisQueue\Redis as Queue;

class TronScanService
{
    public const RELEASE = "if redis.call('get',KEYS[1])==ARGV[1] then return redis.call('del',KEYS[1]) end return 0";
    private TronClient $client;

    public function __construct(?TronClient $client = null)
    {
        $this->client = $client ?? new TronClient();
    }

    public function status(): array
    {
        $state = json_decode(Redis::get(RedisKey::ForeverMgsTronCheckpoint->value) ?: 'null', true);
        $health = json_decode(Redis::get(RedisKey::TempMgsTronHealth->value) ?: 'null', true);
        $gaps = array_map(fn ($value) => json_decode($value, true), Redis::hGetAll(RedisKey::ForeverMgsTronGaps->value));
        $ready = $state && !$state['recovery_required'] && !$gaps && $health && !$health['error']
            && $health['heartbeat_time'] >= time() - 120 && $health['solid_time'] >= time() - 180
            && $state['last_success_time'] >= time() - 120 && $state['next_block_number'] >= $health['solid_number'] - 40;
        return ['ready' => (bool) $ready, 'checkpoint' => $state, 'health' => $health, 'gaps' => $gaps];
    }

    /** 每次进程启动均切到最新固化块，旧范围先在同一Lua内登记，不能先跳后登记。 */
    public function start(bool $firstUse = false): void
    {
        $lock = RedisKey::LockMgsTronScan->value;
        $token = bin2hex(random_bytes(16));
        if (!Redis::set($lock, $token, 'EX', RedisKey::EXPIRE_1_MINUTE, 'NX')) throw new RuntimeException('实时扫描正在运行');
        try {
            Redis::del(RedisKey::TempMgsTronHealth->value);
            $head = $this->client->request('getnowblock');
            $h = $head['block_header']['raw_data'];
            $number = (int) $h['number'];
            $old = Redis::get(RedisKey::ForeverMgsTronCheckpoint->value) ?: '';
            if ($firstUse && $old !== '') throw new RuntimeException('扫描断点已存在，不允许重新初始化');
            $state = json_decode($old ?: 'null', true);
            if (!is_array($state) || !isset($state['next_block_number'], $state['last_block_hash'], $state['recovery_required'])) $state = null;
            if ($state && $state['next_block_number'] > $number + 1) throw new RuntimeException('TRON节点固化头落后于扫描断点');
            if ($state && $state['next_block_number'] === $number + 1 && $state['last_block_hash'] !== $head['blockID']) throw new RuntimeException('已扫描固化块哈希发生变化');
            $gap = null;
            if ($state && $state['next_block_number'] < $number) {
                $gap = ['from' => $state['next_block_number'], 'to' => $number - 1, 'next' => $state['next_block_number'],
                    'last_hash' => $state['last_block_hash'], 'end_hash' => $h['parentHash'], 'attempts' => 0, 'retry_time' => 0, 'error' => null];
            }
            $next = ['start_block_number' => $state['start_block_number'] ?? $number, 'next_block_number' => $number,
                'last_block_hash' => $h['parentHash'], 'last_success_time' => 0,
                'recovery_required' => $state['recovery_required'] ?? !$firstUse];
            $gapId = bin2hex(random_bytes(12));
            $changed = Redis::eval(<<<'LUA'
if redis.call('get',KEYS[1])~=ARGV[1] or (redis.call('get',KEYS[2]) or '')~=ARGV[2] then return 0 end
local t=redis.call('type',KEYS[3]).ok
if t~='none' and t~='hash' then return redis.error_reply('invalid gaps type') end
if ARGV[4]~='' then redis.call('hset',KEYS[3],ARGV[5],ARGV[4]) end
redis.call('set',KEYS[2],ARGV[3]); return 1
LUA, 3, $lock, RedisKey::ForeverMgsTronCheckpoint->value, RedisKey::ForeverMgsTronGaps->value,
                $token, $old, json_encode($next), $gap ? json_encode($gap) : '', $gapId);
            if (!$changed) throw new RuntimeException('扫描锁或断点已改变');
        } finally {
            Redis::eval(self::RELEASE, 1, $lock, $token);
        }
        // 持久任务已登记，队列失败由下轮maintenance补派发。
        if ($gap) Queue::send('mgs_tron_backfill', ['gap_id' => $gapId]);
    }

    public function backfill(int $from, int $to): string
    {
        if ($from < 1 || $to < $from) throw new RuntimeException('补扫范围无效');
        $head = $this->client->request('getnowblock');
        if ($to > (int) $head['block_header']['raw_data']['number']) throw new RuntimeException('补扫范围尚未固化');
        $previous = $this->client->request('getblockbynum', ['num' => $from - 1]);
        $end = $this->client->request('getblockbynum', ['num' => $to]);
        if ((int) $previous['block_header']['raw_data']['number'] !== $from - 1 || (int) $end['block_header']['raw_data']['number'] !== $to) throw new RuntimeException('补扫边界区块不符');
        $id = bin2hex(random_bytes(12));
        Redis::hSet(RedisKey::ForeverMgsTronGaps->value, $id, json_encode(['from' => $from, 'to' => $to, 'next' => $from,
            'last_hash' => $previous['blockID'], 'end_hash' => $end['blockID'], 'attempts' => 0, 'retry_time' => 0, 'error' => null]));
        Queue::send('mgs_tron_backfill', ['gap_id' => $id]);
        return $id;
    }

    /** 主扫描与补扫共用推进逻辑。先提交数据库，再CAS更新Redis；失败可重放，不会漏记。 */
    public function batch(?string $gapId = null): void
    {
        $lock = $gapId === null ? RedisKey::LockMgsTronScan->value : RedisKey::LockMgsTronGap->format($gapId);
        $token = bin2hex(random_bytes(16));
        if (!Redis::set($lock, $token, 'EX', RedisKey::EXPIRE_1_MINUTE, 'NX')) return;
        $key = $gapId === null ? RedisKey::ForeverMgsTronCheckpoint->value : RedisKey::ForeverMgsTronGaps->value;
        $raw = $gapId === null ? Redis::get($key) : Redis::hGet($key, $gapId);
        if (!$raw) { Redis::eval(self::RELEASE, 1, $lock, $token); return; }
        $state = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $done = false;
        try {
            if ($gapId !== null && $state['retry_time'] > time()) return;
            $head = $this->client->request('getnowblock');
            $header = $head['block_header']['raw_data'];
            $solid = (int) $header['number'];
            $health = ['solid_number' => $solid, 'solid_time' => intdiv((int) $header['timestamp'], 1000), 'heartbeat_time' => time(), 'error' => null];
            $started = microtime(true);
            $addresses = Redis::sMembers(RedisKey::ForeverMgsTronAddresses->value);
            $addresses[] = TronClient::address((string) config('mgs.recharge_tron_receive_address'));
            for ($count = 0; $count < 20 && microtime(true) - $started < 20; $count++) {
                $number = $gapId === null ? $state['next_block_number'] : $state['next'];
                if ($number > $solid || ($gapId !== null && $number > $state['to'])) break;
                if (!Redis::eval("if redis.call('get',KEYS[1])==ARGV[1] then return redis.call('expire',KEYS[1],ARGV[2]) end return 0", 1, $lock, $token, RedisKey::EXPIRE_1_MINUTE)) throw new RuntimeException('扫描锁已失效');
                $block = $number === $solid ? $head : $this->client->request('getblockbynum', ['num' => $number]);
                $h = $block['block_header']['raw_data'];
                $lastHash = $gapId === null ? $state['last_block_hash'] : $state['last_hash'];
                if ((int) $h['number'] !== $number || !hash_equals($lastHash, $h['parentHash'])
                    || !preg_match('/^[a-f0-9]{64}$/D', $block['blockID'])) throw new RuntimeException('区块高度或父哈希不连续');
                if ($gapId !== null && $number === $state['to'] && !hash_equals($state['end_hash'], $block['blockID'])) throw new RuntimeException('补扫终点哈希不一致');
                $receipts = empty($block['transactions']) ? [] : $this->client->request('gettransactioninfobyblocknum', ['num' => $number]);
                $processor = new TronBlockService();
                $pending = $processor->store($processor->events($block, $receipts, $addresses));
                $next = $state;
                if ($gapId === null) {
                    $next['next_block_number'] = $number + 1;
                    $next['last_block_hash'] = $block['blockID'];
                    $next['last_success_time'] = time();
                } else {
                    $next['next'] = $number + 1;
                    $next['last_hash'] = $block['blockID'];
                    $next['attempts'] = 0;
                    $next['retry_time'] = 0;
                    $next['error'] = null;
                }
                $encoded = json_encode($next);
                $done = $gapId !== null && $next['next'] > $next['to'];
                $updated = Redis::eval(<<<'LUA'
if redis.call('get',KEYS[1])~=ARGV[1] then return 0 end
local old=ARGV[2]=='' and redis.call('get',KEYS[2]) or redis.call('hget',KEYS[2],ARGV[2])
if old~=ARGV[3] then return 0 end
if ARGV[2]=='' then redis.call('set',KEYS[2],ARGV[4])
elseif ARGV[5]=='1' then redis.call('hdel',KEYS[2],ARGV[2])
else redis.call('hset',KEYS[2],ARGV[2],ARGV[4]) end
return 1
LUA, 2, $lock, $key, $token, $gapId ?? '', $raw, $encoded, $done ? '1' : '0');
                if (!$updated) throw new RuntimeException('扫描进度已被其他进程修改');
                $state = $next;
                $raw = $encoded;
                foreach ($pending as $id) Queue::send('mgs_recharge_credit', ['transfer_id' => $id]);
                if ($done) break;
            }
            if ($gapId === null) {
                $health['heartbeat_time'] = time();
                Redis::eval("if redis.call('get',KEYS[1])==ARGV[1] then redis.call('setex',KEYS[2],ARGV[2],ARGV[3]); return 1 end return 0", 2,
                    $lock, RedisKey::TempMgsTronHealth->value, $token, RedisKey::EXPIRE_2_MINUTES, json_encode($health));
            }
        } catch (\Throwable $error) {
            $message = $error instanceof RuntimeException ? $error->getMessage() : '扫描处理失败：' . $error::class;
            if ($gapId !== null) {
                $state['attempts']++;
                $state['retry_time'] = time() + min(300, 10 * $state['attempts']);
                $state['error'] = mb_substr($message, 0, 200);
                Redis::eval("if redis.call('get',KEYS[1])==ARGV[1] and redis.call('hget',KEYS[2],ARGV[2])==ARGV[3] then return redis.call('hset',KEYS[2],ARGV[2],ARGV[4]) end return 0", 2, $lock, $key, $token, $gapId, $raw, json_encode($state));
            } else {
                Redis::setex(RedisKey::TempMgsTronHealth->value, RedisKey::EXPIRE_2_MINUTES,
                    json_encode(['solid_number' => $solid ?? 0, 'solid_time' => 0, 'heartbeat_time' => time(), 'error' => mb_substr($message, 0, 200)]));
            }
            throw new RuntimeException($message);
        } finally {
            Redis::eval(self::RELEASE, 1, $lock, $token);
        }
        if ($gapId !== null && !$done) Queue::send('mgs_tron_backfill', ['gap_id' => $gapId], 3);
    }

    public function confirmRecovery(): void
    {
        // 仅管理命令显式调用。补扫清空并不自动解除未知历史范围的恢复标记。
        $key = RedisKey::ForeverMgsTronCheckpoint->value;
        $raw = Redis::get($key);
        if (!$raw) throw new RuntimeException('未初始化扫描');
        $state = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $health = $this->status()['health'];
        if (!$health || $health['error'] || $state['last_success_time'] < time() - 120) throw new RuntimeException('实时扫描尚未就绪');
        $state['recovery_required'] = false;
        if (!Redis::eval("if redis.call('get',KEYS[1])~=ARGV[1] or redis.call('hlen',KEYS[2])>0 then return 0 end redis.call('set',KEYS[1],ARGV[2]); return 1", 2, $key, RedisKey::ForeverMgsTronGaps->value, $raw, json_encode($state))) throw new RuntimeException('断点改变或补扫未完成');
    }
}
