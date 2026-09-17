<?php

namespace app\service\mgs;

use app\enum\RedisKey;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\StreamHandler;
use RuntimeException;
use support\Redis;

class TrxPriceService
{
    public function sync(): array
    {
        $ttl = (int) config('mgs.okx_ticker_ttl');
        $response = (new Client(['handler' => new StreamHandler(), 'base_uri' => config('mgs.okx_base_url'), 'timeout' => 5]))
            ->get('/api/v5/market/ticker', ['query' => ['instId' => 'TRX-USDT']]);
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $row = $body['data'][0] ?? [];
        $price = (string) ($row['last'] ?? '');
        $time = intdiv((int) ($row['ts'] ?? 0), 1000);
        if (($body['code'] ?? '') !== '0' || ($row['instId'] ?? '') !== 'TRX-USDT'
            || !preg_match('/^\d+(\.\d+)?$/D', $price) || bccomp($price, '0', 18) <= 0
            || $ttl < 1 || $time < time() - $ttl || $time > time() + 5) throw new RuntimeException('OKX TRX 行情无效或已过期');
        $ticker = ['price' => $price, 'source_time' => $time, 'fetch_time' => time(), 'inst_id' => 'TRX-USDT', 'source' => 'okx'];
        Redis::setex(RedisKey::TempMgsTrxTicker->value, min($ttl, RedisKey::EXPIRE_10_MINUTES), json_encode($ticker));
        return $ticker;
    }
}
