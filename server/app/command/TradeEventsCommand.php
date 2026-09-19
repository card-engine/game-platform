<?php

namespace app\command;

use RuntimeException;
use support\Db;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('trade:events', '预建事件月表或将现有交易凭据移交到事件表并删除零资金流水')]
class TradeEventsCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('prepare-only', null, InputOption::VALUE_NONE, '仅预建事件月表，不修改交易数据');
        $this->addOption('apply', null, InputOption::VALUE_NONE, '停止交易服务后执行凭据移交和零流水清理；默认只预览');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply = (bool) $input->getOption('apply');
        $prepare = (bool) $input->getOption('prepare-only');
        $tables = Db::table('information_schema.tables')->where('table_schema', config('database.connections.mysql.database'))->pluck('TABLE_NAME')->all();
        $months = [];
        $now = new \DateTimeImmutable('first day of this month', new \DateTimeZone('UTC'));
        foreach (range(-2, 2) as $offset) $months[] = $now->modify("{$offset} months")->format('ym');
        foreach ($tables as $table) if (preg_match('/^mgs?_(?:bets|bills|trade_events)_(\d{4})$/', $table, $match)) $months[] = $match[1];
        foreach (['mg', 'mgs'] as $prefix) {
            if ($apply || $prepare) foreach (array_unique($months) as $month) {
                Db::statement("CREATE TABLE IF NOT EXISTS `{$prefix}_trade_events_{$month}` LIKE `{$prefix}_trade_events_template`");
            }
            if ($prepare) continue;
            foreach ($tables as $table) {
                if (!preg_match('/^' . $prefix . '_bills_(\d{4})$/', $table, $match)) continue;
                $query = Db::table($table);
                if ($prefix === 'mgs') $query->whereIn('type', ['bet', 'win', 'cancel']);
                $remove = (clone $query)->where(fn ($q) => $q->where('amount', 0)->orWhere('status', '<>', 2))->count();
                $output->writeln("{$table}: 移交事件 {$query->count()}，移出资金流水 {$remove}");
                if (!$apply) continue;
                $eventTable = "{$prefix}_trade_events_{$match[1]}";
                $query->orderBy('id')->chunkById(300, function ($rows) use ($table, $eventTable, $prefix) {
                    Db::transaction(function () use ($rows, $table, $eventTable, $prefix) {
                        foreach ($rows as $row) {
                            $event = (array) $row;
                            unset($event['id']);
                            $event['event_no'] = ($prefix === 'mg' ? 'TE' : 'ME') . substr($row->bill_no, 2);
                            if ($prefix === 'mg') $event['original_event_no'] = $row->original_bill_no ? 'TE' . substr($row->original_bill_no, 2) : null;
                            $remove = bccomp((string) $row->amount, '0', 8) === 0 || (int) $row->status !== 2;
                            if (bccomp((string) $row->amount, '0', 8) === 0) $event['bill_no'] = null;
                            $key = $prefix === 'mg' ? ['source' => $row->source, 'idempotency_key' => $row->idempotency_key]
                                : ['user_id' => $row->user_id, 'currency_code' => $row->currency_code, 'transaction_id' => $row->transaction_id, 'type' => $row->type];
                            $existing = Db::table($eventTable)->where($key)->first();
                            if ($existing) {
                                if ($existing->request_hash !== $row->request_hash || bccomp($existing->amount, $row->amount, 8) !== 0 || (int) $existing->status !== (int) $row->status) throw new RuntimeException('事件与原流水不一致：' . $row->bill_no);
                            } else Db::table($eventTable)->insert($event);
                            if ($remove) Db::table($table)->where('id', $row->id)->delete();
                        }
                    });
                });
            }
        }
        if ($apply) {
            // 交易服务必须停止：只调整操作轨迹与计数，不变更余额、GGR或结算状态。
            foreach ($tables as $table) {
                if (!preg_match('/^(mg|mgs)_bets_\d{4}$/', $table, $match)) continue;
                $prefix = $match[1];
                Db::table($table)->orderBy('id')->chunkById(300, function ($rows) use ($table, $prefix) {
                    foreach ($rows as $row) {
                        $actions = json_decode($row->actions ?: '[]', true);
                        $counts = ['debit_count' => 0, 'credit_count' => 0, 'rollback_count' => 0];
                        foreach ($actions as &$action) {
                            if ($prefix === 'mg') {
                                if (!isset($action['event_no']) && !empty($action['bill_no'])) $action['event_no'] = 'TE' . substr($action['bill_no'], 2);
                                if (bccomp((string) $action['amount'], '0', 8) === 0) {
                                    $action['bill_no'] = null;
                                    if ($action['type'] === 'credit' && !empty($action['event_no'])) {
                                        $eventTable = 'mg_trade_events_' . substr($action['event_no'], 2, 4);
                                        $data = json_decode(Db::table($eventTable)->where('event_no', $action['event_no'])->value('data') ?: '{}', true);
                                        if (!empty($data['request']['finished'])) $action['type'] = 'close';
                                    }
                                } else $counts[match ($action['type']) { 'debit' => 'debit_count', 'credit' => 'credit_count', default => 'rollback_count' }]++;
                            } elseif ($action['type'] === 'win' && bccomp((string) $action['amount'], '0', 8) === 0 && (int) ($action['is_end'] ?? 0) === 1) $action['type'] = 'close';
                        }
                        unset($action);
                        Db::table($table)->where('id', $row->id)->update(['actions' => json_encode($actions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)] + ($prefix === 'mg' ? $counts : []));
                    }
                });
            }
        }
        $output->writeln($prepare ? '事件月表预建完成' : ($apply ? '事件移交及零流水清理完成；请执行统计重算并核对钱包余额' : '只读预览完成，未更改数据'));
        return self::SUCCESS;
    }
}
