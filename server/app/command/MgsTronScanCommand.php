<?php

namespace app\command;

use app\service\mgs\TronScanService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('mgs:tron-scan', '查看TRON进度或提交队列补扫')]
class MgsTronScanCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, '补扫起始高度');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, '补扫结束高度');
        $this->addOption('once', null, InputOption::VALUE_NONE, '执行一个实时扫描批次');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scan = new TronScanService();
        if ($input->getOption('from') !== null || $input->getOption('to') !== null) {
            $from = (string) $input->getOption('from');
            $to = (string) $input->getOption('to');
            if (!ctype_digit($from) || !ctype_digit($to)) throw new \RuntimeException('请同时指定有效的from和to');
            $output->writeln('补扫任务：' . $scan->backfill((int) $from, (int) $to));
        } elseif ($input->getOption('once')) {
            $scan->batch();
        }
        $output->writeln(json_encode($scan->status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}
