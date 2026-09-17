<?php

namespace app\command;

use app\service\mgs\TrxPriceService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('mgs:recharge-price', '刷新 MGS TRX-USDT 充值报价')]
class MgsRechargePriceCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ticker = (new TrxPriceService())->sync();
        $output->writeln('TRX-USDT: ' . $ticker['price']);
        return self::SUCCESS;
    }
}
