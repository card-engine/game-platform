<?php

namespace app\service\game\trade;

use app\enum\RedisKey;
use app\model\Game;
use app\model\Merchant;
use app\model\MerchantCredit;
use app\model\MerchantGame;
use app\model\User;
use app\queue\redis\parallel\GameBetClose;
use app\queue\redis\serial\GameStatsRefresh;
use app\service\game\ConfigService;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use support\Db;
use support\Redis;

class TradeService
{
    private const TYPE = ['debit' => 1, 'credit' => 2, 'rollback_debit' => 3, 'rollback_credit' => 4];

    public function balance(string $playerId): array
    {
        [$user, $merchant, $currency] = $this->player($playerId);
        $result = (new MerchantCallbackClient())->request($merchant, 'balance', [
            'user_id' => $user->merchant_user_id,
            'currency' => $currency,
        ]);
        return $result + ['operation' => 'balance', 'player_id' => $playerId, 'currency_code' => $currency];
    }

    public function handle(string $platform, array $operation): array
    {
        $action = (string) ($operation['action'] ?? '');
        if (!isset(self::TYPE[$action])) throw new RuntimeException('资金动作无效');
        [$user, $merchant, $currency] = $this->player((string) ($operation['player_id'] ?? ''));
        $amount = (string) ($operation['amount'] ?? '0');
        if (preg_match('/^\d+(?:\.\d{1,8})?$/', $amount) !== 1) throw new RuntimeException('金额格式无效');
        $operation['amount'] = bcadd($amount, '0', 8);
        $operation['source_no'] = (string) ($operation['source_no'] ?? '');
        $operation['round_id'] = (string) ($operation['round_id'] ?? '');
        if ($operation['source_no'] === '') throw new RuntimeException('交易号不能为空');

        $lock = RedisKey::LockUserWallet->format($merchant->id, $user->id, $currency);
        $lockToken = bin2hex(random_bytes(12));
        if (!Redis::set($lock, $lockToken, 'EX', max(30, (int) ceil($merchant->timeout_ms / 1000) + 10), 'NX')) {
            return ['status' => 3, 'code' => 1005, 'message' => '玩家钱包处理中', 'data' => [], 'operation' => $action];
        }

        try {
            $prepared = $this->prepare(strtolower($platform), $operation, $user, $merchant, $currency);
            if (isset($prepared['result'])) {
                if (($prepared['result']['status'] ?? 0) === 2 && $prepared['result']['bet_no']) {
                    $this->syncBetClose($prepared['bet_table'] ?? $this->betTable($prepared['result']['bet_no']), $prepared['result']['bet_no']);
                }
                return $prepared['result'];
            }

            $callbackAction = ['debit' => 'bet', 'credit' => 'win', 'rollback_debit' => 'cancel', 'rollback_credit' => 'cancel'][$action];
            $payload = [
                'user_id' => $user->merchant_user_id,
                'game_id' => (string) id2big((int) $prepared['bill']['game_id']),
                'currency' => $currency,
                'transaction_id' => $operation['source_no'],
                'parent_round_id' => (string) ($operation['parent_round_id'] ?? $operation['round_id']),
                'round_id' => $operation['round_id'],
            ];
            if ($action === 'debit') $payload['bet_amount'] = $prepared['bill']['amount'];
            elseif ($action === 'credit') {
                $payload['win_amount'] = $prepared['bill']['amount'];
                $payload['is_end'] = empty($operation['finished']) ? 0 : 1;
            } else {
                $payload['original_transaction_id'] = (string) ($operation['original_source_no'] ?? '');
                $payload['original_type'] = $action === 'rollback_debit' ? 'bet' : 'win';
                $payload['cancel_amount'] = $prepared['bill']['amount'];
            }
            $wallet = (new MerchantCallbackClient())->request($merchant, $callbackAction, $payload);
            $result = $this->complete($prepared, $wallet, $operation, $merchant, $currency);
            if (($result['status'] ?? 0) === 2) {
                $this->syncBetClose($prepared['bet_table'], $prepared['bill']['bet_no']);
                GameStatsRefresh::dispatch($prepared['bet_table'], $prepared['bill']['bet_no']);
            }
            return $result;
        } finally {
            Redis::eval("if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0", 1, $lock, $lockToken);
        }
    }

    public function retryUnknown(int $limit = 100): array
    {
        $retried = $success = 0;
        $month = new DateTimeImmutable('first day of this month', new DateTimeZone('UTC'));
        for ($i = 0; $i < 3 && $retried < $limit; $i++) {
            $suffix = $month->modify("-{$i} month")->format('ym');
            $table = (new MonthlyTableService())->table('bills', $suffix);
            Db::table($table)->where('status', 1)->where('update_time', '<', date('Y-m-d H:i:s', time() - 60))->update(['status' => 4]);
            $rows = Db::table($table)->where('status', 4)->orderBy('id')->limit($limit - $retried)->get();
            foreach ($rows as $row) {
                $data = json_decode($row->data ?: '{}', true);
                if (!is_array($data['request'] ?? null)) continue;
                $retried++;
                if (($this->handle((string) $row->source, $data['request'])['status'] ?? 0) === 2) $success++;
            }
        }
        return compact('retried', 'success');
    }

    private function prepare(string $platform, array $operation, User $user, Merchant $merchant, string $currency): array
    {
        $month = gmdate('ym');
        $billTable = (new MonthlyTableService())->table('bills', $month);
        $betTable = (new MonthlyTableService())->table('bets', $month);
        $searchMonth = new DateTimeImmutable('first day of this month', new DateTimeZone('UTC'));
        $billTables = $betTables = [];
        for ($i = 0; $i < 3; $i++) {
            $suffix = $searchMonth->modify("-{$i} month")->format('ym');
            $billTables[] = (new MonthlyTableService())->table('bills', $suffix);
            $betTables[] = (new MonthlyTableService())->table('bets', $suffix);
        }
        $autoCloseBetTable = isset($operation['auto_close_month'])
            ? (new MonthlyTableService())->table('bets', (string) $operation['auto_close_month'])
            : null;
        $action = $operation['action'];
        $idempotencyKey = hash('sha256', "{$action}|{$user->id}|{$currency}|{$operation['source_no']}");
        $requestHash = hash('sha256', json_encode($operation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $request = $operation;

        return Db::transaction(function () use ($platform, $operation, $request, $user, $merchant, $currency, $month, $billTable, $betTable, $billTables, $betTables, $autoCloseBetTable, $idempotencyKey, $requestHash, $action) {
            $bill = null;
            foreach ($billTables as $candidate) {
                $bill = (array) Db::table($candidate)->where(['source' => $platform, 'idempotency_key' => $idempotencyKey])->whereNull('delete_time')->lockForUpdate()->first();
                if ($bill) {
                    $billTable = $candidate;
                    break;
                }
            }
            if ($bill) {
                if (($bill['request_hash'] ?? '') !== $requestHash) return ['result' => $this->result(3, 1005, '重复交易参数不一致', [], $action)];
                $data = json_decode($bill['data'] ?: '{}', true) ?: [];
                if (in_array((int) $bill['status'], [2, 3], true)) {
                    $wallet = $data['wallet_response'] ?? [];
                    return ['result' => $this->result((int) $bill['status'], (int) ($wallet['code'] ?? 0), (string) ($wallet['message'] ?? ''), $wallet['data'] ?? [], $action, $bill)];
                }
                if ((int) $bill['status'] === 1) return ['result' => $this->result(3, 1005, '交易处理中', [], $action, $bill)];
                Db::table($billTable)->where('id', $bill['id'])->update(['status' => 1, 'update_time' => $this->now()]);
                return ['bill' => $bill, 'bill_table' => $billTable, 'bet_table' => $this->betTable($bill['bet_no'])];
            }

            $original = null;
            $originalData = [];
            $bet = null;
            if ($operation['auto_close_bet_no'] ?? null) {
                $betNo = $operation['auto_close_bet_no'];
                $betTable = $autoCloseBetTable;
                $bet = (array) Db::table($betTable)->where('bet_no', $betNo)->lockForUpdate()->first();
                if (!$bet || (int) $bet['user_id'] !== (int) $user->id || $bet['platform_code'] !== $platform || $bet['currency_code'] !== $currency) {
                    return ['result' => $this->result(2, 0, '注单不存在', [], $action)];
                }
                if ((int) $bet['status'] !== 1 || time() - strtotime((string) $bet['update_time']) < 600) {
                    return ['result' => $this->result(2, 0, '注单无需结单', [], $action)];
                }
            } elseif (str_starts_with($action, 'rollback')) {
                $originalAction = str_replace('rollback_', '', $action);
                $originalKey = hash('sha256', "{$originalAction}|{$user->id}|{$currency}|" . ($operation['original_source_no'] ?? $operation['source_no']));
                foreach ($billTables as $originalTable) {
                    $original = (array) Db::table($originalTable)->where(['source' => $platform, 'idempotency_key' => $originalKey])->whereNull('delete_time')->lockForUpdate()->first();
                    if ($original) break;
                }
                if ((int) ($original['status'] ?? 0) !== 2 || (int) ($original['type'] ?? 0) !== self::TYPE[$originalAction]) return ['result' => $this->result(3, 1004, '原交易不存在或未成功', [], $action)];
                $originalData = json_decode($original['data'] ?: '{}', true) ?: [];
                $used = bcadd((string) ($originalData['rollback_amount'] ?? '0'), (string) ($originalData['rollback_pending_amount'] ?? '0'), 8);
                $remaining = bcsub((string) $original['amount'], $used, 8);
                if (bccomp($remaining, '0', 8) <= 0) return ['result' => $this->result(3, 1004, '原交易已全部回滚', [], $action)];
                if (bccomp($operation['amount'], '0', 8) === 0) $operation['amount'] = $remaining;
                if (bccomp($operation['amount'], $remaining, 8) > 0) return ['result' => $this->result(3, 1004, '回滚金额超过原交易剩余金额', [], $action)];
                $betTable = $this->betTable($original['bet_no']);
                $betNo = $original['bet_no'];
            } else {
                if ($action === 'debit' && !(bool) config("game_platforms.platforms.{$platform}.is_open", true)) {
                    return ['result' => $this->result(3, 1004, '游戏平台已关闭', [], $action)];
                }
                $gameQuery = Game::where('platform_code', $platform)->where('provider_game_code', $operation['game_code'] ?? '')
                    ->when($operation['brand_code'] ?? '', fn ($query, $value) => $query->whereHas('brand', fn ($q) => $q->where('provider_brand_code', $value)))
                    ->whereNull('delete_time');
                if (($operation['action'] ?? '') === 'debit') $gameQuery->where('upstream_status', 1)->where('platform_status', 1)
                    ->whereNotIn('id', MerchantGame::where(['merchant_id' => $merchant->id, 'status' => 0])->whereNull('delete_time')->select('game_id'));
                $game = $gameQuery->first();
                if (!$game) return ['result' => $this->result(3, 1004, '游戏不存在或已停用', [], $action)];
                $roundId = trim((string) ($operation['parent_round_id'] ?? $operation['round_id'] ?? ''));
                $roundKey = hash('sha256', "{$user->id}|{$currency}|{$game->id}|" . ($roundId ?: "transaction:{$operation['source_no']}"));
                foreach ($betTables as $candidate) {
                    $existing = Db::table($candidate)->where(['platform_code' => $platform, 'round_key' => $roundKey])->whereNull('delete_time')->first();
                    if ($existing) {
                        $betTable = $candidate;
                        $betNo = $existing->bet_no;
                        break;
                    }
                }
                if (!isset($betNo)) {
                    $credit = MerchantCredit::where(['merchant_id' => $merchant->id, 'currency_code' => $currency, 'status' => 1])->first();
                    if (!$credit) return ['result' => $this->result(3, 1003, '商户币种未开通', [], $action)];
                    $rate = $credit && (int) $merchant->billing_mode === 1
                        ? (MerchantGame::where(['merchant_id' => $merchant->id, 'game_id' => $game->id, 'status' => 1])->value('rate_value') ?? $credit->rate_value ?? '0')
                        : '0';
                    $betNo = mg_no('BT', $month);
                    Db::table($betTable)->insert([
                        'bet_no' => $betNo, 'merchant_id' => $merchant->id, 'user_id' => $user->id, 'platform_code' => $platform,
                        'brand_id' => $game->brand_id, 'game_id' => $game->id, 'currency_code' => $currency,
                        'round_key' => $roundKey, 'provider_parent_round_id' => $operation['parent_round_id'] ?? null,
                        'provider_round_id' => (string) (($operation['round_id'] ?? '') ?: ($roundId ?: $operation['source_no'])),
                        'billing_mode' => $merchant->billing_mode, 'settlement_enabled' => $credit && (int) $merchant->billing_mode === 1 ? $credit->settlement_enabled : 0,
                        'merchant_rate_value' => $rate, 'business_date' => $this->businessDate($merchant), 'platform_date' => $this->platformDate(),
                        'create_time' => $this->now(), 'update_time' => $this->now(),
                    ]);
                }
            }

            $bet ??= (array) Db::table($betTable)->where('bet_no', $betNo)->lockForUpdate()->first();
            $reserved = $rollbackReserved = '0.00000000';
            if ((int) $bet['settlement_enabled'] === 1 && (int) $bet['status'] !== 2 && $action === 'debit') {
                $reserved = bcmul($operation['amount'], (string) $bet['merchant_rate_value'], 8);
            } elseif ((int) $bet['status'] === 2) {
                $projected = $bet;
                $field = ['debit' => 'bet_amount', 'credit' => 'win_amount', 'rollback_debit' => 'bet_rollback_amount', 'rollback_credit' => 'win_rollback_amount'][$action];
                $projected[$field] = bcadd((string) $projected[$field], $operation['amount'], 8);
                $projected['ggr_amount'] = bcsub(bcsub($projected['bet_amount'], $projected['bet_rollback_amount'], 8), bcsub($projected['win_amount'], $projected['win_rollback_amount'], 8), 8);
                $reserved = bcsub($this->fee($projected), (string) $bet['merchant_fee'], 8);
                if (bccomp($reserved, '0', 8) < 0) $reserved = '0.00000000';
            } elseif ($action === 'rollback_debit') {
                $rollbackReserved = bcmul($operation['amount'], (string) $bet['merchant_rate_value'], 8);
                if (bccomp($rollbackReserved, (string) $bet['reserved_fee'], 8) > 0) $rollbackReserved = (string) $bet['reserved_fee'];
            }
            if (bccomp($reserved, '0', 8) > 0) {
                $credit = MerchantCredit::where(['merchant_id' => $merchant->id, 'currency_code' => $currency, 'status' => 1])->lockForUpdate()->first();
                if (!$credit || bccomp((string) $credit->available_amount, $reserved, 8) < 0) return ['result' => $this->result(3, 1002, '商户额度不足', [], $action)];
                $credit->update(['available_amount' => bcsub((string) $credit->available_amount, $reserved, 8), 'reserved_amount' => bcadd((string) $credit->reserved_amount, $reserved, 8)]);
                Db::table($betTable)->where('bet_no', $betNo)->increment('reserved_fee', $reserved);
                Db::table('mg_merchant_monthly_usages')->updateOrInsert(
                    ['credit_id' => $credit->id, 'billing_month' => substr($bet['business_date'], 0, 7) . '-01'],
                    ['rules_snapshot' => json_encode(['billing_mode' => $bet['billing_mode'], 'rate_value' => $bet['merchant_rate_value']]), 'update_time' => $this->now(), 'delete_time' => null],
                );
                Db::table('mg_merchant_monthly_usages')->where(['credit_id' => $credit->id, 'billing_month' => substr($bet['business_date'], 0, 7) . '-01'])->increment('reserved_amount', $reserved);
            }

            if ($original) {
                $originalData['rollback_pending_amount'] = bcadd((string) ($originalData['rollback_pending_amount'] ?? '0'), $operation['amount'], 8);
                Db::table($originalTable)->where('id', $original['id'])->update(['data' => json_encode($originalData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'update_time' => $this->now()]);
            }

            $bill = [
                'bill_no' => mg_no('BL', $month), 'bet_no' => $betNo, 'merchant_id' => $merchant->id, 'user_id' => $user->id,
                'game_id' => $original['game_id'] ?? $bet['game_id'], 'type' => self::TYPE[$action], 'source' => $platform,
                'source_no' => $operation['source_no'], 'amount' => $operation['amount'], 'currency_code' => $currency,
                'original_bill_no' => $original['bill_no'] ?? null, 'idempotency_key' => $idempotencyKey, 'request_hash' => $requestHash,
                'status' => 1, 'data' => json_encode(['request' => $request, 'reserved_fee' => $reserved, 'rollback_reserved_fee' => $rollbackReserved], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'business_date' => $this->businessDate($merchant), 'platform_date' => $this->platformDate(),
                'received_time' => $this->now(), 'create_time' => $this->now(), 'update_time' => $this->now(),
            ];
            Db::table($billTable)->insert($bill);
            return ['bill' => $bill, 'bill_table' => $billTable, 'bet_table' => $betTable];
        });
    }

    private function complete(array $prepared, array $wallet, array $operation, Merchant $merchant, string $currency): array
    {
        return Db::transaction(function () use ($prepared, $wallet, $operation, $merchant, $currency) {
            $bill = (array) Db::table($prepared['bill_table'])->where('bill_no', $prepared['bill']['bill_no'])->lockForUpdate()->first();
            $data = json_decode($bill['data'] ?: '{}', true) ?: [];
            $data['wallet_response'] = ['code' => $wallet['code'], 'message' => $wallet['message'], 'data' => $wallet['data']];
            Db::table($prepared['bill_table'])->where('id', $bill['id'])->update([
                'status' => $wallet['status'], 'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'completed_time' => $wallet['status'] === 4 ? null : $this->now(), 'update_time' => $this->now(),
            ]);
            if ($wallet['status'] === 4) return $this->result(4, 1006, $wallet['message'], [], $operation['action'], $bill);
            if (str_starts_with($operation['action'], 'rollback')) {
                if (preg_match('/^BL(\d{4})/', (string) $bill['original_bill_no'], $match) !== 1) throw new RuntimeException('Bill 编号无效');
                $originalTable = (new MonthlyTableService())->table('bills', $match[1]);
                $original = (array) Db::table($originalTable)->where('bill_no', $bill['original_bill_no'])->lockForUpdate()->first();
                $originalData = json_decode($original['data'] ?: '{}', true) ?: [];
                $originalData['rollback_pending_amount'] = bcsub((string) ($originalData['rollback_pending_amount'] ?? '0'), $bill['amount'], 8);
                if ($wallet['status'] === 2) $originalData['rollback_amount'] = bcadd((string) ($originalData['rollback_amount'] ?? '0'), $bill['amount'], 8);
                Db::table($originalTable)->where('id', $original['id'])->update(['data' => json_encode($originalData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'update_time' => $this->now()]);
            }
            if ($wallet['status'] === 3) {
                $this->release($prepared['bet_table'], $bill, $merchant, $currency, (string) ($data['reserved_fee'] ?? '0'));
                return $this->result(3, $wallet['code'], $wallet['message'], $wallet['data'], $operation['action'], $bill);
            }

            $bet = (array) Db::table($prepared['bet_table'])->where('bet_no', $bill['bet_no'])->lockForUpdate()->first();
            $action = $operation['action'];
            $map = [
                'debit' => ['bet_amount', 'debit_count'], 'credit' => ['win_amount', 'credit_count'],
                'rollback_debit' => ['bet_rollback_amount', 'rollback_count'], 'rollback_credit' => ['win_rollback_amount', 'rollback_count'],
            ];
            [$amountField, $countField] = $map[$action];
            $bet[$amountField] = bcadd((string) $bet[$amountField], $bill['amount'], 8);
            $bet[$countField]++;
            $actions = json_decode($bet['actions'] ?: '[]', true) ?: [];
            $actions[] = ['bill_no' => $bill['bill_no'], 'type' => $action, 'amount' => $bill['amount'], 'source_no' => $bill['source_no'], 'time' => $this->now()];
            $bet['ggr_amount'] = bcsub(bcsub($bet['bet_amount'], $bet['bet_rollback_amount'], 8), bcsub($bet['win_amount'], $bet['win_rollback_amount'], 8), 8);
            $bet['billable_ggr_amount'] = (int) $bet['settlement_enabled'] === 1 && bccomp($bet['ggr_amount'], '0', 8) > 0 ? $bet['ggr_amount'] : '0.00000000';
            $updates = [
                $amountField => $bet[$amountField], $countField => $bet[$countField], 'actions' => json_encode($actions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ggr_amount' => $bet['ggr_amount'], 'billable_ggr_amount' => $bet['billable_ggr_amount'], 'status' => max(1, (int) $bet['status']), 'update_time' => $this->now(),
            ];
            if (str_starts_with($action, 'rollback') && (int) $bet['status'] !== 2) {
                if ($action === 'rollback_debit') {
                    $released = (string) ($data['rollback_reserved_fee'] ?? '0');
                    $this->release($prepared['bet_table'], $bill, $merchant, $currency, $released);
                    $bet['reserved_fee'] = bcsub((string) $bet['reserved_fee'], $released, 8);
                    if (bccomp($bet['reserved_fee'], '0', 8) < 0) $bet['reserved_fee'] = '0.00000000';
                    $updates['reserved_fee'] = $bet['reserved_fee'];
                }
            }
            if ((int) $bet['status'] === 2) {
                $updates = array_merge($updates, $this->settle($bet, $merchant, $currency, true, (string) ($data['reserved_fee'] ?? '0')));
            } elseif (!empty($operation['finished'])) {
                $updates = array_merge($updates, $this->settle($bet, $merchant, $currency));
            }
            Db::table($prepared['bet_table'])->where('id', $bet['id'])->update($updates);
            return $this->result(2, 0, 'success', $wallet['data'], $action, $bill);
        });
    }

    private function settle(array $bet, Merchant $merchant, string $currency, bool $late = false, string $reserved = '0.00000000'): array
    {
        $fee = $this->fee($bet);
        if ($late) {
            $difference = bcsub($fee, (string) $bet['merchant_fee'], 8);
            if (bccomp($difference, '0', 8) < 0) $difference = '0.00000000';
            if (bccomp($difference, '0', 8) === 0 && bccomp($reserved, '0', 8) === 0) return [];
            $credit = MerchantCredit::where(['merchant_id' => $merchant->id, 'currency_code' => $currency])->lockForUpdate()->firstOrFail();
            $before = (string) $credit->payable_amount;
            $credit->update([
                'available_amount' => bcadd((string) $credit->available_amount, bcsub($reserved, $difference, 8), 8),
                'reserved_amount' => bcsub((string) $credit->reserved_amount, $reserved, 8),
                'payable_amount' => bcadd($before, $difference, 8),
            ]);
            $month = substr($bet['business_date'], 0, 7) . '-01';
            Db::table('mg_merchant_monthly_usages')->updateOrInsert(
                ['credit_id' => $credit->id, 'billing_month' => $month],
                ['rules_snapshot' => json_encode(['billing_mode' => $bet['billing_mode'], 'rate_value' => $bet['merchant_rate_value']]), 'update_time' => $this->now(), 'delete_time' => null],
            );
            Db::table('mg_merchant_monthly_usages')->where(['credit_id' => $credit->id, 'billing_month' => $month])->update([
                'billed_amount' => Db::raw("billed_amount + {$difference}"), 'reserved_amount' => Db::raw("GREATEST(reserved_amount - {$reserved}, 0)"), 'update_time' => $this->now(),
            ]);
            if (bccomp($difference, '0', 8) > 0) Db::table('mg_merchant_bills')->insert([
                'bill_no' => mg_no('MC'), 'credit_id' => $credit->id, 'type' => 2, 'direction' => 2, 'amount' => $difference,
                'before_amount' => $before, 'after_amount' => $credit->payable_amount, 'source' => 'bet_adjustment',
                'source_no' => $bet['bet_no'], 'data' => json_encode(['ggr_amount' => $bet['ggr_amount'], 'billable_ggr_amount' => $bet['billable_ggr_amount']]),
                'create_time' => $this->now(),
            ]);
            return ['merchant_fee' => bccomp($difference, '0', 8) > 0 ? $fee : $bet['merchant_fee'], 'reserved_fee' => bcsub((string) $bet['reserved_fee'], $reserved, 8)];
        }
        $reserved = (string) $bet['reserved_fee'];
        $credit = MerchantCredit::where(['merchant_id' => $merchant->id, 'currency_code' => $currency])->lockForUpdate()->firstOrFail();
        $month = substr($bet['business_date'], 0, 7) . '-01';
        Db::table('mg_merchant_monthly_usages')->updateOrInsert(
            ['credit_id' => $credit->id, 'billing_month' => $month],
            ['rules_snapshot' => json_encode(['billing_mode' => $bet['billing_mode'], 'rate_value' => $bet['merchant_rate_value']]), 'update_time' => $this->now(), 'delete_time' => null],
        );
        $difference = bcsub($reserved, $fee, 8);
        $credit->update([
            'available_amount' => bcadd((string) $credit->available_amount, $difference, 8),
            'reserved_amount' => bcsub((string) $credit->reserved_amount, $reserved, 8),
            'payable_amount' => bcadd((string) $credit->payable_amount, $fee, 8),
        ]);
        Db::table('mg_merchant_monthly_usages')->where(['credit_id' => $credit->id, 'billing_month' => $month])->update([
            'bet_count' => Db::raw('bet_count + 1'), 'billed_amount' => Db::raw("billed_amount + {$fee}"),
            'reserved_amount' => Db::raw("GREATEST(reserved_amount - {$reserved}, 0)"), 'update_time' => $this->now(),
        ]);
        if (bccomp($fee, '0', 8) > 0) {
            Db::table('mg_merchant_bills')->insert([
                'bill_no' => mg_no('MC'), 'credit_id' => $credit->id, 'type' => 2, 'direction' => 2, 'amount' => $fee,
                'before_amount' => $credit->getOriginal('payable_amount'), 'after_amount' => $credit->payable_amount,
                'source' => 'bet', 'source_no' => $bet['bet_no'], 'data' => json_encode(['ggr_amount' => $bet['ggr_amount'], 'billable_ggr_amount' => $bet['billable_ggr_amount']]),
                'create_time' => $this->now(),
            ]);
        }
        return ['merchant_fee' => $fee, 'reserved_fee' => '0.00000000', 'status' => 2, 'settled_time' => $this->now()];
    }

    private function fee(array $bet): string
    {
        if ((int) $bet['settlement_enabled'] !== 1) return '0.00000000';
        if ((int) $bet['billing_mode'] !== 1) return '0.00000000';
        $base = (string) $bet['ggr_amount'];
        if (bccomp($base, '0', 8) < 0) $base = '0.00000000';
        return bcmul($base, (string) $bet['merchant_rate_value'], 8);
    }

    private function release(string $betTable, array $bill, Merchant $merchant, string $currency, string $amount): void
    {
        if (bccomp($amount, '0', 8) <= 0) return;
        $credit = MerchantCredit::where(['merchant_id' => $merchant->id, 'currency_code' => $currency])->lockForUpdate()->first();
        if (!$credit) return;
        $credit->update([
            'available_amount' => bcadd((string) $credit->available_amount, $amount, 8),
            'reserved_amount' => bcsub((string) $credit->reserved_amount, $amount, 8),
        ]);
        Db::table($betTable)->where('bet_no', $bill['bet_no'])->update(['reserved_fee' => Db::raw("GREATEST(reserved_fee - {$amount}, 0)")]);
        Db::table('mg_merchant_monthly_usages')->where(['credit_id' => $credit->id, 'billing_month' => substr($bill['business_date'], 0, 7) . '-01'])->update([
            'reserved_amount' => Db::raw("GREATEST(reserved_amount - {$amount}, 0)"), 'update_time' => $this->now(),
        ]);
    }

    private function player(string $playerId): array
    {
        if (preg_match('/^mg_(\d+)_([a-z0-9]{2,16})$/i', $playerId, $match) !== 1 || ($id = big2id((int) $match[1])) === false) throw new RuntimeException('玩家账号无效');
        $user = User::find($id);
        if (!$user || (int) $user->status !== 1) throw new RuntimeException('玩家不存在或已停用');
        $merchant = Merchant::find($user->merchant_id);
        if (!$merchant || (int) $merchant->status !== 1) throw new RuntimeException('商户未启用');
        $currency = strtoupper($match[2]);
        return [$user, $merchant, $currency];
    }

    private function result(int $status, int $code, string $message, array $data, string $operation, array $bill = []): array
    {
        return compact('status', 'code', 'message', 'data', 'operation') + ['bill_no' => $bill['bill_no'] ?? null, 'bet_no' => $bill['bet_no'] ?? null];
    }

    private function businessDate(Merchant $merchant): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone($merchant->timezone ?: 'UTC')))->format('Y-m-d');
    }

    private function platformDate(): string
    {
        $timezone = (new ConfigService())->get('platform_timezone', 'UTC');
        return (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d');
    }

    private function betTable(?string $number): string
    {
        if (preg_match('/^BT(\d{4})/', (string) $number, $match) !== 1) throw new RuntimeException('注单编号无效');
        return (new MonthlyTableService())->table('bets', $match[1]);
    }

    private function syncBetClose(string $table, string $betNo): void
    {
        $status = Db::table($table)->where('bet_no', $betNo)->value('status');
        $month = substr($table, -4);
        (int) $status === 1 ? GameBetClose::dispatch($betNo, $month) : GameBetClose::cancel($betNo, $month);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s.v');
    }
}
