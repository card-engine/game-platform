<?php

namespace app\command;

use app\service\game\report\ExchangeRateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('game:exchange-rate', '同步 MG 今日汇率快照')]
class GameExchangeRateCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rate = (new ExchangeRateService())->sync();
        $output->writeln("{$rate->rate_date} #{$rate->id}");
        return self::SUCCESS;
    }
}
