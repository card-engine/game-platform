<?php

namespace app\service\game;

use RuntimeException;

class SecretService
{
    public static function encrypt(string $value): string
    {
        $key = hash('sha256', self::key(), true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new RuntimeException('密钥加密失败');
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $value): string
    {
        $data = base64_decode($value, true);
        if ($data === false || strlen($data) < 29) throw new RuntimeException('密钥数据无效');
        $plain = openssl_decrypt(substr($data, 28), 'aes-256-gcm', hash('sha256', self::key(), true), OPENSSL_RAW_DATA, substr($data, 0, 12), substr($data, 12, 16));
        if ($plain === false) throw new RuntimeException('密钥解密失败');
        return $plain;
    }

    private static function key(): string
    {
        $key = config('game_platforms.secret_key');
        if (!$key) throw new RuntimeException('GAME_SECRET_KEY 未配置');
        return $key;
    }
}
