<?php

namespace app\command;

use RuntimeException;
use support\Db;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('mgs:recharge-schema', '检查旧充值表；显式apply转换并保留旧表备份')]
class MgsRechargeSchemaCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, '先停止服务和资金写入，再执行结构转换；不删除旧数据');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $schema = Db::connection()->getSchemaBuilder();
        $mapping = ['mgs_recharge_orders' => 'mgs_recharges', 'mgs_recharge_transfers' => 'mgs_transfers'];
        $legacy = array_filter(array_keys($mapping), fn ($name) => $schema->hasTable($name));
        $hasState = $schema->hasTable('mgs_chain_scan_states');
        if (!$legacy && !$hasState) { $output->writeln('充值表已使用新结构'); return self::SUCCESS; }
        foreach ($legacy as $name) $output->writeln($name . '：' . Db::table($name)->count() . ' 条');
        if (!$input->getOption('apply')) {
            $output->writeln('请先停服备份，执行 php webman mgs:recharge-schema --apply，再运行 db:upgrade。');
            return self::FAILURE;
        }
        if ((int) Db::selectOne('SELECT GET_LOCK(?, 5) AS locked', ['mgs:recharge:schema'])->locked !== 1) throw new RuntimeException('结构转换正在执行');
        $suffix = '_legacy_' . gmdate('ymdHis') . '_' . getmypid();
        $staging = [];
        $renames = [];
        try {
            foreach ($legacy as $old) {
                $target = $mapping[$old];
                if ($schema->hasTable($target) && Db::table($target)->exists()) throw new RuntimeException('新旧表均有数据，禁止自动合并：' . $target);
                $temp = '__mgs_convert_' . getmypid() . '_' . $target;
                if ($schema->hasTable($temp)) throw new RuntimeException('转换临时表已存在，请先人工检查');
                preg_match('/CREATE TABLE `' . $target . '` \(.*?;(?=\n)/s', file_get_contents(base_path('database/schema.sql')), $match);
                Db::statement(str_replace('CREATE TABLE `' . $target . '`', 'CREATE TABLE `' . $temp . '`', $match[0]));
                $staging[] = $temp;
                $snapshotCount = Db::table($old)->count();
                foreach (Db::table($old)->orderBy('id')->cursor() as $record) {
                    $row = (array) $record;
                    if ($target === 'mgs_recharges') {
                        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $row['request_id'])) throw new RuntimeException('旧请求编号不是UUID，需人工处理，充值ID=' . $row['id']);
                        $rate = json_decode($row['rate_snapshot'] ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                        unset($rate['amounts'], $rate['quote_key']);
                        $rate['token_address'] = $row['token_address'];
                        // 保留旧单的实际流水定位，不修改历史余额和流水交易号。
                        $rate['legacy_bill_no'] = $row['bill_no'];
                        $rate['legacy_transaction_id'] = 'recharge:' . $row['order_no'];
                        $data = array_intersect_key($row, array_flip(['id','user_id','request_id','currency_code','recharge_amount','pay_method','pay_currency_code','pay_amount','receive_address','status','expire_time','credited_time','create_time','update_time','delete_time']));
                        $data += ['recharge_no' => $row['order_no'], 'is_reserved' => $row['amount_match_key'] !== null || in_array($row['status'], ['pending','review'], true) ? 1 : null, 'rate_snapshot' => json_encode($rate)];
                    } else {
                        $proof = json_decode($row['data'] ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                        $proof['block_hash'] = $row['block_hash'];
                        $proof['token_address'] = $row['token_address'];
                        if ($row['reviewed_by']) $proof['reviews'][] = ['admin_id' => $row['reviewed_by'], 'time' => $row['reviewed_time'], 'remark' => $row['remark']];
                        $data = array_intersect_key($row, array_flip(['id','transaction_id','event_index','currency_code','amount','from_address','receive_address','block_number','block_time','status','create_time','update_time','delete_time']));
                        $data += ['recharge_id' => $row['recharge_order_id'], 'remark' => $row['remark'] ?: $row['reason'], 'data' => json_encode($proof)];
                    }
                    Db::table($temp)->insert($data);
                }
                if (Db::table($temp)->count() !== $snapshotCount || Db::table($old)->count() !== $snapshotCount) throw new RuntimeException('转换期间数据发生变化，请停服后重试');
                $renames[] = "`{$old}` TO `{$old}{$suffix}`";
                if ($schema->hasTable($target)) $renames[] = "`{$target}` TO `{$target}{$suffix}`";
                $renames[] = "`{$temp}` TO `{$target}`";
            }
            $transferTable = in_array('mgs_recharge_transfers', $legacy, true) ? '__mgs_convert_' . getmypid() . '_mgs_transfers' : 'mgs_transfers';
            $rechargeTable = in_array('mgs_recharge_orders', $legacy, true) ? '__mgs_convert_' . getmypid() . '_mgs_recharges' : 'mgs_recharges';
            if ($schema->hasTable($transferTable) && Db::table($transferTable . ' as t')->leftJoin($rechargeTable . ' as r', 'r.id', '=', 't.recharge_id')->whereNotNull('t.recharge_id')->whereNull('r.id')->exists()) throw new RuntimeException('存在无对应充值的收款关联，禁止切换');
            if ($hasState) $renames[] = '`mgs_chain_scan_states` TO `mgs_chain_scan_states' . $suffix . '`';
            Db::statement('RENAME TABLE ' . implode(', ', $renames));
            $staging = [];
            $output->writeln('转换完成；旧表备份后缀：' . $suffix . '。旧扫描进度需人工核对并初始化Redis。');
            return self::SUCCESS;
        } finally {
            // 仅清理本次新建、未切换成功的临时副本；旧表永不删除。
            foreach ($staging as $table) Db::statement("DROP TABLE `{$table}`");
            Db::selectOne('SELECT RELEASE_LOCK(?)', ['mgs:recharge:schema']);
        }
    }
}
