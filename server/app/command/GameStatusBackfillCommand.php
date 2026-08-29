<?php

namespace app\command;

use support\Db;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('game:status-backfill', '将旧游戏授权状态迁移到新状态字段')]
class GameStatusBackfillCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $database = config('database.connections.mysql.database');
        $hasOldGameStatus = (bool) Db::table('information_schema.columns')->where(['table_schema' => $database, 'table_name' => 'mg_games', 'column_name' => 'status'])->exists();
        if ($hasOldGameStatus) {
            $count = Db::update("UPDATE mg_games SET upstream_status = IF(status = 1, 1, 0), platform_status = IF(status = 1, 1, 0), platform_status_reason = IF(status = 1, 'upstream_available', 'manual_disabled'), upstream_status_time = COALESCE(upstream_status_time, UTC_TIMESTAMP(3)), platform_status_time = COALESCE(platform_status_time, UTC_TIMESTAMP(3))");
            $output->writeln("MG 游戏状态回填：{$count}");
        }

        $hasOldMerchantStatus = (bool) Db::table('information_schema.columns')->where(['table_schema' => $database, 'table_name' => 'mg_merchant_games', 'column_name' => 'merchant_status'])->exists();
        if ($hasOldMerchantStatus) {
            $count = Db::update("UPDATE mg_merchant_games SET status = IF(status = 1 AND merchant_status = 1, 1, 0), status_reason = IF(status = 1 AND merchant_status = 1, NULL, 'manual_disabled'), status_time = COALESCE(status_time, UTC_TIMESTAMP(3))");
            $output->writeln("商户单款状态回填：{$count}");
        }
        return self::SUCCESS;
    }
}
