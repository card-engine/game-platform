<?php

function startMockWallet(int $offset = 0): array
{
    $port = 20000 + getmypid() % 20000 + $offset;
    $state = tempnam(sys_get_temp_dir(), 'mg_wallet_');
    $secret = bin2hex(random_bytes(16));
    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/mock_wallet.php'],
        [STDIN, ['file', '/dev/null', 'a'], ['file', '/dev/null', 'a']],
        $pipes,
        dirname(__DIR__, 2),
        array_replace(getenv(), ['MOCK_WALLET_SECRET' => $secret, 'MOCK_WALLET_STATE' => $state]),
    );
    if (!is_resource($process)) throw new RuntimeException('模拟钱包启动失败');
    for ($i = 0; $i < 50; $i++) {
        if ($socket = @fsockopen('127.0.0.1', $port)) {
            fclose($socket);
            return compact('process', 'port', 'secret', 'state');
        }
        usleep(20000);
    }
    proc_terminate($process);
    proc_close($process);
    unlink($state);
    throw new RuntimeException('模拟钱包启动超时');
}

function stopMockWallet(array $server): void
{
    proc_terminate($server['process']);
    proc_close($server['process']);
    unlink($server['state']);
}
