<?php

namespace app\command;

use app\service\game\trade\TradeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('game:retry', '重试 MG 结果未知的玩家 Bill')]
class GameRetryCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('limit', InputArgument::OPTIONAL, '单次处理数量', 100);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new TradeService())->retryUnknown((int) $input->getArgument('limit'));
        $output->writeln("retried={$result['retried']} success={$result['success']}");
        return self::SUCCESS;
    }
}
