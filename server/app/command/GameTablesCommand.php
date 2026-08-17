<?php

namespace app\command;

use app\service\game\trade\MonthlyTableService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('game:tables', '预建 MG 当前及未来两个月交易表')]
class GameTablesCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ((new MonthlyTableService())->precreate() as $table) $output->writeln($table);
        return self::SUCCESS;
    }
}
