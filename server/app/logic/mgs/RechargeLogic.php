<?php

namespace app\logic\mgs;

use app\enum\RedisKey;
use app\model\ExchangeRate;
use app\model\Recharge;
use app\model\mgs\User;
use app\model\mgs\Wallet;
use app\service\mgs\TronScanService;
use app\service\mgs\TronClient;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use plugin\saiadmin\basic\eloquent\BaseLogic;
use plugin\saiadmin\exception\ApiException;
use support\Db;
use support\Redis;

class RechargeLogic extends BaseLogic
{
    public const AMOUNTS = [10, 20, 50, 100, 200, 300, 500, 1000, 2000, 5000, 10000, 50000, 100000];
    public const SUFFIX_SCRIPT = "local n = (tonumber(redis.call('get', KEYS[1])) or 0) % 99 + 1; redis.call('set', KEYS[1], n); return n";

    public function options(string $currency): array
    {
        $reason = $this->unavailableReason();
        $payments = [];
        if (!$reason) {
            foreach (['USDT', 'TRX'] as $payCurrency) {
                if ($quote = $this->quote($currency, $payCurrency)) $payments[] = $quote;
            }
            if (!$payments) $reason = '暂无有效充值报价';
        }
        return ['currency_code' => $currency, 'amounts' => self::AMOUNTS, 'default_amount' => 100,
            'available' => !$reason, 'unavailable_reason' => $reason, 'payments' => $payments];
    }

    public function create(User $user, array $data): array
    {
        return $this->transaction(function () use ($user, $data) {
            // 串行化同一玩家的新建和重试；唯一索引负责跨玩家的金额占用。
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ((int) $user->status !== 1) throw new ApiException('MGS 用户已停用', 403);
            $existing = Recharge::where(['user_id' => $user->id, 'request_id' => $data['request_id']])->first();
            if ($existing) {
                if ($existing->currency_code !== $data['currency_code'] || $existing->pay_currency_code !== $data['pay_currency_code']
                    || bccomp((string) $existing->recharge_amount, (string) $data['recharge_amount'], 8) !== 0) {
                    throw new ApiException('请求编号已用于其他充值参数');
                }
                return $this->output($existing);
            }
            $active = Recharge::where(['user_id' => $user->id, 'currency_code' => $data['currency_code']])
                ->where('status', 'pending')->where('expire_time', '>', gmdate('Y-m-d H:i:s'))->first();
            if ($active) throw new ApiException('请先处理当前充值订单');

            if ($reason = $this->unavailableReason()) throw new ApiException($reason);
            $quote = $this->quote($data['currency_code'], $data['pay_currency_code']);
            if (!$quote || !hash_equals($quote['quote_key'], $data['quote_key'])) throw new ApiException('报价已过期，请刷新后重试');
            $base = $quote['amounts'][(string) $data['recharge_amount']];
            if (bccomp($base, '0', 2) <= 0) throw new ApiException('该档位换算后金额过小，请选择更高档位');
            Wallet::firstOrCreate(['user_id' => $user->id, 'currency_code' => $data['currency_code']], ['balance' => '0.00000000']);
            $address = (string) config('mgs.recharge_tron_receive_address');
            Redis::sAdd(RedisKey::ForeverMgsTronAddresses->value, TronClient::address($address));
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            for ($attempt = 0; $attempt < 99; $attempt++) {
                $suffix = (int) Redis::eval(self::SUFFIX_SCRIPT, 1, RedisKey::ForeverMgsRechargeSuffix->value);
                $amount = bcadd($base, bcdiv((string) $suffix, '10000', 4), 4);
                try {
                    $order = Recharge::create([
                        'recharge_no' => mg_no('MR'), 'user_id' => $user->id,
                        'request_id' => $data['request_id'], 'currency_code' => $data['currency_code'], 'recharge_amount' => $data['recharge_amount'],
                        'pay_method' => 'trc20', 'pay_currency_code' => $data['pay_currency_code'],
                        'pay_amount' => $amount, 'is_reserved' => 1,
                        'rate_snapshot' => array_diff_key($quote, array_flip(['amounts', 'quote_key'])),
                        'receive_address' => $address,
                        'status' => 'pending', 'expire_time' => $now->modify('+15 minutes')->format('Y-m-d H:i:s.v'),
                        'create_time' => $now->format('Y-m-d H:i:s.v'),
                    ]);
                    return $this->output($order);
                } catch (UniqueConstraintViolationException $e) {
                    if (!str_contains($e->getMessage(), 'uk_recharge_reserved')) throw $e;
                }
            }
            throw new ApiException('充值名额暂满，请稍后再试');
        });
    }

    public function current(User $user, string $currency): ?array
    {
        $order = Recharge::where(['user_id' => $user->id, 'currency_code' => $currency])
            ->where(fn ($query) => $query->where('status', 'review')->orWhere(fn ($query) => $query
                ->where('status', 'pending')->where('expire_time', '>', gmdate('Y-m-d H:i:s'))))->latest('id')->first();
        return $order ? $this->output($order) : null;
    }

    public function history(User $user, int $page): array
    {
        $orders = Recharge::where('user_id', $user->id)->orderByDesc('id')->simplePaginate(10, ['*'], 'page', $page);
        return ['list' => array_map(fn ($order) => $this->output($order), $orders->items()),
            'page' => $orders->currentPage(), 'has_more' => $orders->hasMorePages()];
    }

    public function order(User $user, string $id): array
    {
        $order = Recharge::where(['user_id' => $user->id, 'id' => $id])->first();
        if (!$order) throw new ApiException('充值订单不存在', 404);
        return $this->output($order) + ['transfers' => $order->transfers()->where('status', 'credited')
            ->get(['transaction_id', 'block_number', 'block_time'])->toArray()];
    }

    private function unavailableReason(): ?string
    {
        if (!config('mgs.recharge_enabled')) return '充值暂未开放';
        if (!config('mgs.recharge_tron_receive_address')) return '充值收款配置未完成';
        TronClient::address((string) config('mgs.recharge_tron_receive_address'));
        if (!(new TronScanService())->status()['ready']) return '充值确认服务未就绪';
        return null;
    }

    private function quote(string $currency, string $payCurrency): ?array
    {
        $snapshot = ExchangeRate::where(['rate_date' => gmdate('Y-m-d'), 'base_currency_code' => 'USD', 'source' => 'currencyapi'])->first();
        $rate = $currency === 'USD' ? '1' : (string) ($snapshot?->rate_json[$currency] ?? '0');
        if (!$snapshot || !preg_match('/^\d+(\.\d+)?$/D', $rate) || bccomp($rate, '0', 18) <= 0) return null;
        $ticker = null;
        if ($payCurrency === 'TRX') {
            $ticker = json_decode(Redis::get(RedisKey::TempMgsTrxTicker->value) ?: 'null', true);
            if (!$ticker || $ticker['source_time'] < time() - config('mgs.okx_ticker_ttl') || $ticker['source_time'] > time() + 5) return null;
        }
        $payUsd = $ticker['price'] ?? '1';
        if (!preg_match('/^\d+(\.\d+)?$/D', $payUsd) || bccomp($payUsd, '0', 18) <= 0) return null;
        $denominator = bcmul($rate, $payUsd, 18);
        if (bccomp($denominator, '0', 18) <= 0) return null;
        $value = bcdiv('1', $denominator, 18);
        $quote = ['currency_code' => $currency, 'pay_currency_code' => $payCurrency, 'pay_per_unit' => $value,
            'exchange_rate_id' => $snapshot->id, 'rate_date' => $snapshot->rate_date,
            'source_update_time' => $snapshot->source_update_time, 'currency_per_usd' => $rate,
            'pay_usd' => $payUsd, 'token_address' => config('mgs.recharge_tron_usdt_contract'), 'pricing_policy' => 'usd_parity', 'ticker' => $ticker];
        $quote['quote_key'] = hash('sha256', json_encode([$quote, config('mgs.recharge_tron_receive_address'), config('mgs.recharge_tron_usdt_contract')]));
        $quote['amounts'] = [];
        foreach (self::AMOUNTS as $amount) $quote['amounts'][(string) $amount] = bcround(bcmul((string) $amount, $value, 18), 2, \RoundingMode::HalfAwayFromZero);
        return $quote;
    }

    private function output(Recharge $order): array
    {
        $expire = new DateTimeImmutable($order->getRawOriginal('expire_time'), new DateTimeZone('UTC'));
        $status = $order->status === 'pending' && $expire->getTimestamp() <= time() ? 'expired' : $order->status;
        return ['mgs_recharge_id' => (string) $order->id, 'recharge_no' => $order->recharge_no,
            'currency_code' => $order->currency_code, 'recharge_amount' => (string) $order->recharge_amount,
            'pay_currency_code' => $order->pay_currency_code, 'pay_amount' => bcadd((string) $order->pay_amount, '0', 4),
            'pay_method' => $order->pay_method, 'receive_address' => $order->receive_address, 'status' => $status,
            'create_time' => str_replace(' ', 'T', $order->getRawOriginal('create_time')) . 'Z',
            'credited_time' => $order->credited_time ? str_replace(' ', 'T', $order->credited_time) . 'Z' : null,
            'expire_time' => $expire->format('Y-m-d\TH:i:s.v\Z'), 'server_time' => gmdate('Y-m-d\TH:i:s\Z')];
    }
}
