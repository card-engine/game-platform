<?php

namespace app\command;

use app\service\game\report\DailyStatService;
use app\service\game\report\TrendStatService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('game:stats', '重建 MG 每日和趋势统计')]
class GameStatsCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('date', InputArgument::OPTIONAL, '指定营业日或平台统计日期');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $date = $input->getArgument('date');
        $result = [
            'daily' => $date ? (new DailyStatService())->rebuild($date) : (new DailyStatService())->rebuildCurrent(),
            'trend' => (new TrendStatService())->rebuildCurrent(),
        ];
        $output->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
