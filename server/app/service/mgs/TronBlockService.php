<?php

namespace app\service\mgs;

use app\model\Transfer;
use Brick\Math\BigInteger;
use RuntimeException;
use support\Db;

class TronBlockService
{
    /** 实时和补扫共用；所有回执齐全才返回，任何解析异常都不允许推进。 */
    public function events(array $block, array $receipts, array $addresses): array
    {
        $header = $block['block_header']['raw_data'];
        $height = (int) $header['number'];
        $timestamp = (int) $header['timestamp'];
        $transactions = $block['transactions'] ?? [];
        $infos = array_column($receipts, null, 'id');
        if (count($infos) !== count($transactions) || count($infos) !== count($receipts)) throw new RuntimeException('TRON区块回执不完整');
        $watched = array_fill_keys($addresses, true);
        $contract = TronClient::address((string) config('mgs.recharge_tron_usdt_contract'));
        $events = [];
        foreach ($transactions as $transaction) {
            $info = $infos[$transaction['txID']] ?? null;
            if (!$info || (int) ($info['blockNumber'] ?? -1) !== $height || (int) ($info['blockTimeStamp'] ?? 0) !== $timestamp) throw new RuntimeException('TRON回执区块归属不符');
            $success = $transaction['ret'][0]['contractRet'] ?? null;
            if ($success === null) throw new RuntimeException('TRON交易缺少执行结果');
            if ($success !== 'SUCCESS' || ($info['result'] ?? '') === 'FAILED' || ($info['receipt']['result'] ?? 'SUCCESS') !== 'SUCCESS') continue;
            $smart = array_intersect(array_column($transaction['raw_data']['contract'], 'type'), ['TriggerSmartContract', 'CreateSmartContract']);
            if ($smart && !isset($info['receipt']['result'])) throw new RuntimeException('合约回执缺少执行结果');
            $base = ['transaction_id' => $transaction['txID'], 'block_number' => $height, 'block_time' => TronClient::time($timestamp)];
            foreach ($transaction['raw_data']['contract'] as $index => $item) {
                if ($item['type'] !== 'TransferContract') continue;
                $value = $item['parameter']['value'];
                $to = TronClient::address($value['to_address']);
                if (!isset($watched[$to])) continue;
                $amount = (string) $value['amount'];
                if (!ctype_digit($amount)) throw new RuntimeException('TRX金额格式无效');
                if (bccomp($amount, '0') <= 0) continue;
                $events[] = $base + ['event_index' => $index, 'currency_code' => 'TRX', 'amount' => bcdiv($amount, '1000000', 6),
                    'from_address' => TronClient::address($value['owner_address']), 'receive_address' => $to,
                    'data' => ['block_hash' => $block['blockID'], 'event' => $item]];
            }
            foreach ($info['log'] ?? [] as $index => $log) {
                if (($log['topics'][0] ?? '') !== 'ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef') continue;
                if (TronClient::address($log['address']) !== $contract) continue;
                if (count($log['topics']) !== 3 || !preg_match('/^[0-9a-fA-F]{64}$/D', $log['data'])) throw new RuntimeException('USDT事件格式无效');
                foreach (array_slice($log['topics'], 1) as $topic) {
                    if (!preg_match('/^0{24}[0-9a-fA-F]{40}$/D', $topic)) throw new RuntimeException('USDT事件地址无效');
                }
                $to = TronClient::address(substr($log['topics'][2], -40));
                if (!isset($watched[$to])) continue;
                $integer = (string) BigInteger::fromBase($log['data'], 16);
                if ($integer === '0') continue;
                $events[] = $base + ['event_index' => $index, 'currency_code' => 'USDT', 'amount' => bcdiv($integer, '1000000', 6),
                    'from_address' => TronClient::address(substr($log['topics'][1], -40)), 'receive_address' => $to,
                    'data' => ['block_hash' => $block['blockID'], 'token_address' => $contract, 'event' => $log]];
            }
        }
        return $events;
    }

    public function store(array $events): array
    {
        if (!$events) return [];
        return Db::transaction(function () use ($events) {
            $pending = [];
            foreach ($events as $event) {
                $key = array_intersect_key($event, array_flip(['transaction_id', 'currency_code', 'event_index']));
                $transfer = Transfer::withTrashed()->firstOrCreate($key, $event);
                if ($transfer->receive_address !== $event['receive_address'] || bccomp((string) $transfer->amount, $event['amount'], 8) !== 0
                    || (int) $transfer->block_number !== $event['block_number'] || $transfer->from_address !== $event['from_address']
                    || ($transfer->data['block_hash'] ?? '') !== $event['data']['block_hash']) throw new RuntimeException('重复链上事件内容不一致');
                if ($transfer->wasRecentlyCreated || $transfer->status === 'pending') $pending[] = $transfer->id;
            }
            return $pending;
        });
    }
}
