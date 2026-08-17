<?php

namespace app\command;

use app\service\game\report\MonthlyBillingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('game:billing', '生成 MG 阶梯月费账单')]
class GameBillingCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('month', InputArgument::OPTIONAL, '收费月份，默认本月');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new MonthlyBillingService())->generate($input->getArgument('month'));
        $output->writeln("{$result['month']}: {$result['created']} bills");
        return self::SUCCESS;
    }
}
