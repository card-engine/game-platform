<?php

namespace app\service\mgs;

use app\enum\RedisKey;
use app\model\mgs\Game;
use app\model\mgs\User;
use app\model\mgs\Wallet;
use app\queue\redis\serial\MgsStatsRefresh;
use RuntimeException;
use support\Db;
use support\Redis;

class MgsCallbackService
{
    public function authorized(array $params): bool
    {
        $timestamp = (string) ($params['timestamp'] ?? '');
        $secret = (string) config('mgs.secret');
        $mchId = (new MgsConfigService())->get('game_platform_mch_id', config('mgs.mch_id'));
        return $secret !== '' && (string) ($params['mch_id'] ?? '') === (string) $mchId
            && ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 60
            && hash_equals(game_platform_sign($params, $secret), (string) ($params['sign'] ?? ''));
    }

    public function handle(string $action, array $params): array
    {
        $currency = strtoupper(trim((string) ($params['currency'] ?? '')));
        $userNo = trim((string) ($params['user_id'] ?? ''));
        if ($userNo === '' || $currency === '') throw new RuntimeException('用户或币种不能为空');
        $user = User::firstOrCreate(['user_no' => $userNo], ['language' => config('mgs.default_language', 'en'), 'status' => 1]);
        if ((int) $user->status !== 1) throw new RuntimeException('用户已停用');
        if ($action === 'balance') {
            $wallet = Wallet::firstOrCreate(['user_id' => $user->id, 'currency_code' => $currency], ['balance' => '0.00000000']);
            return ['balance' => (string) $wallet->balance, 'balance_after' => (string) $wallet->balance];
        }
        if (!in_array($action, ['bet', 'win', 'cancel'], true)) throw new RuntimeException('回调动作无效');
        $transactionId = trim((string) ($params['transaction_id'] ?? ''));
        if ($transactionId === '') throw new RuntimeException('交易号不能为空');
        $type = $action === 'cancel' ? 'cancel' : $action;
        $requestData = $params;
        unset($requestData['sign'], $requestData['timestamp']);
        ksort($requestData);
        $requestHash = hash('sha256', json_encode($requestData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $lock = RedisKey::LockMgsUserWallet->format($user->id, $currency);
        $token = bin2hex(random_bytes(12));
        if (!Redis::set($lock, $token, 'EX', 30, 'NX')) throw new RuntimeException('用户钱包处理中');
        try {
            foreach ($this->months() as $month) {
                (new MgsTableService())->table('bets', $month);
                (new MgsTableService())->table('bills', $month);
            }
            $wallet = Wallet::firstOrCreate(['user_id' => $user->id, 'currency_code' => $currency], ['balance' => '0.00000000']);
            $existing = $this->findBill($transactionId, $type, $user->id, $currency);
            if ($existing) {
                if (!hash_equals((string) $existing->request_hash, $requestHash)) throw new RuntimeException('重复交易参数不一致');
                return ['balance' => $existing->after_balance, 'balance_after' => $existing->after_balance];
            }
            $result = Db::transaction(function () use ($action, $params, $requestData, $requestHash, $currency, $user, $wallet, $transactionId, $type) {
                $wallet = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
                $game = null;
                $betTable = null;
                $bet = null;
                if ($action !== 'cancel') {
                    $game = Game::where('platform_game_id', (string) ($params['game_id'] ?? ''))->where('status', 1)->first();
                    if (!$game) throw new RuntimeException('MGS 游戏不存在或已停用');
                    if (!in_array($currency, (array) $game->currency_codes, true)) throw new RuntimeException('MGS 游戏不支持该币种');
                    $betTable = (new MgsTableService())->table('bets');
                    $roundId = trim((string) ($params['parent_round_id'] ?? $params['round_id'] ?? ''));
                    $roundKey = hash('sha256', "{$user->id}|{$currency}|{$game->id}|" . ($roundId ?: "transaction:{$transactionId}"));
                    $bet = $this->findBet($roundKey);
                    if (!$bet) {
                        $betNo = mg_no('MB');
                        $betTable = (new MgsTableService())->table('bets', substr($betNo, 2, 4));
                        Db::table($betTable)->insert([
                            'bet_no' => $betNo, 'user_id' => $user->id, 'game_id' => $game->id, 'currency_code' => $currency,
                            'round_key' => $roundKey, 'platform_round_id' => (string) (($params['round_id'] ?? '') ?: ($roundId ?: $transactionId)), 'rate_value' => $game->rate_value,
                            'business_date' => $this->date(), 'platform_date' => $this->date(), 'create_time' => $this->now(), 'update_time' => $this->now(),
                        ]);
                    $bet = (array) Db::table($betTable)->where('bet_no', $betNo)->first();
                    } else {
                        $betTable = $this->betTable((string) $bet['bet_no']);
                    }
                } else {
                    $originalTransactionId = trim((string) ($params['original_transaction_id'] ?? ''));
                    $originalType = (string) ($params['original_type'] ?? '');
                    $original = in_array($originalType, ['bet', 'win'], true)
                        ? $this->findBill($originalTransactionId, $originalType, $user->id, $currency)
                        : ($this->findBill($originalTransactionId, 'bet', $user->id, $currency) ?: $this->findBill($originalTransactionId, 'win', $user->id, $currency));
                    if (!$original) throw new RuntimeException('原交易不存在');
                    $betNo = (string) ($original->bet_no ?? '');
                    $betTable = $this->betTable($betNo);
                    $bet = (array) Db::table($betTable)->where('bet_no', $betNo)->lockForUpdate()->first();
                    if (!$bet) throw new RuntimeException('原注单不存在');
                    $game = Game::find($bet['game_id']);
                    $params['original_type'] = (string) $original->type;
                    $cancelled = '0';
                    foreach ($this->months() as $month) {
                        $cancelled = bcadd($cancelled, (string) Db::table((new MgsTableService())->table('bills', $month))
                            ->where(['user_id' => $user->id, 'currency_code' => $currency, 'type' => 'cancel', 'status' => 2,
                                'original_transaction_id' => $originalTransactionId, 'direction' => $original->type === 'bet' ? 1 : 2])
                            ->sum('amount'), 8);
                    }
                    $remaining = bcsub((string) $original->amount, $cancelled, 8);
                    if (bccomp($remaining, '0', 8) <= 0) throw new RuntimeException('原交易已全部取消');
                    $cancelAmount = trim((string) ($params['cancel_amount'] ?? ''));
                    if ($cancelAmount !== '' && preg_match('/^\d+(?:\.\d{1,8})?$/', $cancelAmount) !== 1) throw new RuntimeException('金额格式无效');
                    $params['amount'] = $cancelAmount === '' || bccomp($cancelAmount, '0', 8) === 0 ? $remaining : $cancelAmount;
                    if (bccomp($params['amount'], $remaining, 8) > 0) throw new RuntimeException('取消金额超过原交易剩余金额');
                }
                $amount = $action === 'bet' ? (string) ($params['bet_amount'] ?? '0') : ($action === 'win' ? (string) ($params['win_amount'] ?? '0') : (string) ($params['amount'] ?? '0'));
                if (preg_match('/^\d+(?:\.\d{1,8})?$/', $amount) !== 1 || bccomp($amount, '0', 8) < 0) throw new RuntimeException('金额格式无效');
                $increase = $action === 'win' || ($action === 'cancel' && ($params['original_type'] ?? '') === 'bet');
                $before = (string) $wallet->balance;
                $after = $increase ? bcadd($before, $amount, 8) : bcsub($before, $amount, 8);
                if (!$increase && bccomp($after, '0', 8) < 0) throw new RuntimeException('余额不足');
                $wallet->update(['balance' => $after, 'version' => Db::raw('version + 1'), 'update_time' => $this->now()]);
                $billNo = mg_no('ML');
                Db::table((new MgsTableService())->table('bills', substr($billNo, 2, 4)))->insert([
                    'bill_no' => $billNo, 'bet_no' => $bet['bet_no'] ?? null, 'user_id' => $user->id,
                    'game_id' => $game?->id, 'type' => $type, 'direction' => $increase ? 1 : 2, 'transaction_id' => $transactionId,
                    'original_transaction_id' => $params['original_transaction_id'] ?? null, 'amount' => $amount, 'currency_code' => $currency,
                    'before_balance' => $before, 'after_balance' => $after, 'status' => 2,
                    'request_hash' => $requestHash,
                    'data' => json_encode($requestData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'create_time' => $this->now(), 'update_time' => $this->now(),
                ]);
                $this->updateBet($bet, $betTable, $action, $amount, $params);
                return ['balance' => $after, 'balance_after' => $after, 'bet_no' => $bet['bet_no'] ?? null];
            });
            try {
                $local = new \DateTimeImmutable('now', new \DateTimeZone((string) (new MgsConfigService())->get('platform_timezone', config('mgs.timezone', 'UTC'))));
                MgsStatsRefresh::dispatch($local->format('Y-m-d'));
                MgsStatsRefresh::dispatch($local->format('Y-m-d'), (int) $local->format('G'));
            } catch (\Throwable) {
                // 统计任务可重算，不能影响已经提交的资金结果。
            }
            return $result;
        } finally {
            Redis::eval("if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0", 1, $lock, $token);
        }
    }

    private function updateBet(?array $bet, string $table, string $action, string $amount, array $params): void
    {
        if (!$bet) return;
        $bet = (array) $bet;
        $field = ['bet' => 'bet_amount', 'win' => 'win_amount', 'cancel' => (($params['original_type'] ?? '') === 'bet' ? 'bet_rollback_amount' : 'win_rollback_amount')][$action];
        $bet[$field] = bcadd((string) $bet[$field], $amount, 8);
        $bet['ggr_amount'] = bcsub(bcsub((string) $bet['bet_amount'], (string) $bet['bet_rollback_amount'], 8), bcsub((string) $bet['win_amount'], (string) $bet['win_rollback_amount'], 8), 8);
        $billableGgr = bccomp((string) $bet['ggr_amount'], '0', 8) > 0 ? (string) $bet['ggr_amount'] : '0';
        $bet['platform_fee'] = bcmul($billableGgr, (string) $bet['rate_value'], 8);
        $bet['rtp_value'] = bccomp((string) $bet['bet_amount'], '0', 8) > 0 ? bcdiv((string) $bet['win_amount'], (string) $bet['bet_amount'], 10) : null;
        $settledTime = $bet['settled_time'] ?: ($action === 'win' && (int) ($params['is_end'] ?? 0) === 1 ? $this->now() : null);
        $status = bccomp(bcsub((string) $bet['bet_amount'], (string) $bet['bet_rollback_amount'], 8), '0', 8) === 0
            && bccomp(bcsub((string) $bet['win_amount'], (string) $bet['win_rollback_amount'], 8), '0', 8) === 0
            ? 3 : ($settledTime ? 2 : 1);
        $actions = json_decode($bet['actions'] ?: '[]', true) ?: [];
        $actions[] = array_filter([
            'type' => $action, 'transaction_id' => $params['transaction_id'] ?? null,
            'original_transaction_id' => $params['original_transaction_id'] ?? null,
            'amount' => $amount, 'is_end' => $action === 'win' ? (int) ($params['is_end'] ?? 0) : null, 'time' => $this->now(),
        ], fn ($value) => $value !== null);
        Db::table($table)->where('bet_no', $bet['bet_no'])->update([
            $field => $bet[$field], 'ggr_amount' => $bet['ggr_amount'], 'platform_fee' => $bet['platform_fee'], 'rtp_value' => $bet['rtp_value'],
            'actions' => json_encode($actions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => $status, 'settled_time' => $settledTime, 'update_time' => $this->now(),
        ]);
    }

    private function findBill(string $transactionId, string $type, int $userId, string $currency): ?object
    {
        if ($transactionId === '') return null;
        foreach ($this->months() as $month) {
            $row = Db::table((new MgsTableService())->table('bills', $month))->where(['user_id' => $userId, 'currency_code' => $currency, 'transaction_id' => $transactionId, 'type' => $type])->first();
            if ($row) return $row;
        }
        return null;
    }

    private function findBet(string $roundKey): ?array
    {
        foreach ($this->months() as $month) {
            $row = Db::table((new MgsTableService())->table('bets', $month))->where('round_key', $roundKey)->first();
            if ($row) return (array) $row;
        }
        return null;
    }

    private function betTable(string $betNo): string
    {
        if (preg_match('/^MB(\d{4})/', $betNo, $match) !== 1) throw new RuntimeException('MGS 注单号无效');
        return (new MgsTableService())->table('bets', $match[1]);
    }

    private function months(): array
    {
        $month = new \DateTimeImmutable('first day of this month', new \DateTimeZone('UTC'));
        return array_map(fn ($i) => $month->modify("-{$i} month")->format('ym'), range(0, 2));
    }

    private function date(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone((string) (new MgsConfigService())->get('platform_timezone', config('mgs.timezone', 'UTC')))))->format('Y-m-d');
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s.v');
    }
}
