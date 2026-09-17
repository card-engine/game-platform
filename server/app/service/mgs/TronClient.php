<?php

namespace app\service\mgs;

use Brick\Math\BigInteger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use app\enum\RedisKey;
use support\Redis;

/** 仅开放固化区块只读接口，不包含签名或转账功能。 */
class TronClient
{
    private const BASE58 = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client(['base_uri' => config('mgs.tron.tron_url'), 'connect_timeout' => 3, 'timeout' => 8]);
    }

    public function request(string $method, array $params = []): array
    {
        if (!in_array($method, ['getnowblock', 'getblockbynum', 'gettransactioninfobyblocknum'], true)) throw new RuntimeException('不支持的TRON读取接口');
        $keys = config('mgs.tron.api_keys', []);
        $key = $keys ? $keys[array_rand($keys)] : '';
        $rate = RedisKey::TempMgsTronRate->format(hash('sha256', $key));
        $count = Redis::eval("local n=redis.call('incr',KEYS[1]); if n==1 then redis.call('expire',KEYS[1],ARGV[1]) end; return n", 1, $rate, RedisKey::EXPIRE_1_SECOND);
        if ($count > 5) throw new RuntimeException('TRON节点请求限速，请稍后重试');
        try {
            $response = $this->http->post('/walletsolidity/' . $method, [
                'headers' => $key === '' ? [] : ['TRON-PRO-API-KEY' => $key],
                'json' => (object) $params, 'http_errors' => false,
            ]);
        } catch (GuzzleException) {
            // 不向队列日志暴露请求头、密钥或含凭证的节点URL。
            throw new RuntimeException('TRON节点请求失败');
        }
        if ($response->getStatusCode() !== 200) throw new RuntimeException('TRON节点HTTP状态：' . $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (!is_array($data) || isset($data['Error']) || isset($data['error'])) throw new RuntimeException('TRON节点返回错误');
        return $data;
    }

    /** 用现有大整数库换进制，校验和使用PHP内置SHA256，不实现密码学算法。 */
    public static function address(string $value): string
    {
        if (str_starts_with($value, 'T')) {
            $hex = BigInteger::fromArbitraryBase($value, self::BASE58)->toBase(16);
            if (!preg_match('/^41[0-9a-f]{48}$/D', $hex)) throw new RuntimeException('TRON地址长度无效');
            $payload = hex2bin(substr($hex, 0, 42));
            if (!hash_equals(substr(hash('sha256', hash('sha256', $payload, true)), 0, 8), substr($hex, 42))) throw new RuntimeException('TRON地址校验失败');
            return $value;
        }
        $hex = strtolower($value);
        if (strlen($hex) === 40) $hex = '41' . $hex;
        if (!preg_match('/^41[0-9a-f]{40}$/D', $hex)) throw new RuntimeException('TRON十六进制地址无效');
        $payload = hex2bin($hex);
        return BigInteger::fromBase($hex . substr(hash('sha256', hash('sha256', $payload, true)), 0, 8), 16)->toArbitraryBase(self::BASE58);
    }

    public static function time(int $milliseconds): string
    {
        return gmdate('Y-m-d H:i:s', intdiv($milliseconds, 1000)) . sprintf('.%03d', $milliseconds % 1000);
    }
}
