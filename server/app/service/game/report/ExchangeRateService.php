<?php

namespace app\service\game\report;

use app\enum\RedisKey;
use app\model\ExchangeRate;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use support\Redis;

class ExchangeRateService
{
    public function sync(): ExchangeRate
    {
        $date = gmdate('Y-m-d');
        $key = RedisKey::LockExchangeRateSync->format($date);
        $token = bin2hex(random_bytes(12));
        if (!Redis::set($key, $token, 'EX', RedisKey::EXPIRE_1_MINUTE, 'NX')) throw new RuntimeException('今日汇率正在同步');

        try {
            $response = (new Client([
                'base_uri' => 'https://api.currencyapi.com', 'connect_timeout' => 5, 'timeout' => 30, 'force_ip_resolve' => 'v4',
            ]))->get('/v3/latest', ['query' => ['apikey' => env('CURRENCY_API_KEY'), 'base_currency' => 'USD']]);
            $body = json_decode((string) $response->getBody(), true);
            if (!is_array($body['data'] ?? null) || !$body['data']) throw new RuntimeException('汇率接口返回无效');
            $rates = array_map(fn ($item) => (string) $item['value'], $body['data']);
            $sourceTime = new DateTimeImmutable((string) ($body['meta']['last_updated_at'] ?? 'now'));
            return ExchangeRate::updateOrCreate(
                ['rate_date' => $date, 'base_currency_code' => 'USD', 'source' => 'currencyapi'],
                ['rate_json' => $rates, 'source_update_time' => $sourceTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v')],
            );
        } catch (GuzzleException) {
            throw new RuntimeException('汇率接口请求失败');
        } finally {
            Redis::eval("if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0", 1, $key, $token);
        }
    }

    public function conversion(string $date, string $from, string $to): ?array
    {
        $from = $from === 'SC' ? 'USD' : strtoupper($from);
        $to = $to === 'SC' ? 'USD' : strtoupper($to);
        if ($from === 'GC' || $to === 'GC') return null;
        $rate = ExchangeRate::where('rate_date', '<=', $date)->latest('rate_date')->first();
        if (!$rate) return null;
        $rates = $rate->rate_json;
        $fromValue = $from === 'USD' ? '1' : ($rates[$from] ?? null);
        $toValue = $to === 'USD' ? '1' : ($rates[$to] ?? null);
        if (!$fromValue || !$toValue) return null;
        return ['id' => (int) $rate->id, 'value' => bcdiv((string) $toValue, (string) $fromValue, 18)];
    }
}
