<?php

$file = getenv('MOCK_WALLET_STATE');
$secret = getenv('MOCK_WALLET_SECRET');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/v1/api/get_game_url') {
    header('content-type: application/json');
    echo json_encode(['code' => 0, 'msg' => 'success', 'data' => ['url' => 'https://example.test/demo']]);
    return;
}
$action = '/' . basename($path);
if (!str_starts_with($path, '/app/') || !in_array($action, ['/balance', '/bet', '/win', '/cancel'], true)) {
    http_response_code(404);
    echo json_encode(['code' => 404, 'message' => 'bad callback path']);
    return;
}
$request = json_decode(file_get_contents('php://input'), true);
$sign = (string) ($request['sign'] ?? '');
unset($request['sign']);
$params = array_map(static fn ($value) => $value ?? '', $request);
ksort($params);
$expected = md5(http_build_query($params, '', '&', PHP_QUERY_RFC3986) . '&secret=' . $secret);
if (!hash_equals($expected, $sign)) {
    http_response_code(401);
    echo json_encode(['code' => 401, 'message' => 'bad signature']);
    return;
}

$handle = fopen($file, 'c+');
flock($handle, LOCK_EX);
$state = json_decode(stream_get_contents($handle) ?: '{}', true) + ['balance' => '100.00000000', 'transactions' => [], 'attempts' => [], 'events' => [], 'rounds' => []];
$transaction = (string) ($request['transaction_id'] ?? 'balance');
$key = $path . ':' . $transaction;
$state['attempts'][$transaction] = ($state['attempts'][$transaction] ?? 0) + 1;

if (str_starts_with($transaction, 'unknown_') && $state['attempts'][$transaction] === 1) {
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($state));
    flock($handle, LOCK_UN);
    fclose($handle);
    http_response_code(500);
    echo 'unknown';
    return;
}

if ($action === '/win' && ((str_starts_with($transaction, 'credit_1_') && (int) ($request['is_end'] ?? -1) !== 0)
    || (str_starts_with($transaction, 'credit_2_') && (int) ($request['is_end'] ?? -1) !== 1)
    || (str_starts_with($transaction, 'auto_close_') && ((string) ($request['win_amount'] ?? '') !== '0.00000000' || (int) ($request['is_end'] ?? -1) !== 1)))) {
    $response = ['code' => 1001, 'message' => 'invalid is_end', 'data' => []];
} elseif (str_starts_with($transaction, 'fail_')) {
    $response = ['code' => 2001, 'message' => 'insufficient balance', 'data' => ['balance' => $state['balance']]];
} elseif ($action === '/balance') {
    $response = ['code' => 0, 'message' => 'success', 'data' => ['balance' => $state['balance']]];
} elseif (isset($state['transactions'][$key])) {
    $response = $state['transactions'][$key];
} else {
    $round = (string) ($request['round_id'] ?? '');
    $state['rounds'][$round] ??= ['bet' => '0', 'win' => '0', 'cancelled' => false];
    if ($action === '/bet') {
        $state['balance'] = bcsub($state['balance'], (string) $request['bet_amount'], 8);
        $state['rounds'][$round]['bet'] = bcadd($state['rounds'][$round]['bet'], (string) $request['bet_amount'], 8);
    } elseif ($action === '/win') {
        $state['balance'] = bcadd($state['balance'], (string) $request['win_amount'], 8);
        $state['rounds'][$round]['win'] = bcadd($state['rounds'][$round]['win'], (string) $request['win_amount'], 8);
    } elseif ($action === '/cancel' && !$state['rounds'][$round]['cancelled']) {
        $state['balance'] = bcadd($state['balance'], bcsub($state['rounds'][$round]['bet'], $state['rounds'][$round]['win'], 8), 8);
        $state['rounds'][$round]['cancelled'] = true;
    }
    $response = ['code' => 0, 'message' => 'success', 'data' => ['transaction_id' => 'merchant_' . count($state['transactions']), 'balance' => $state['balance']]];
    $state['transactions'][$key] = $response;
    $state['events'][] = ['path' => $path, 'request' => $request];
}

ftruncate($handle, 0);
rewind($handle);
fwrite($handle, json_encode($state));
flock($handle, LOCK_UN);
fclose($handle);
header('content-type: application/json');
echo json_encode($response);
