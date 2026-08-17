<?php

namespace app\command;

use app\service\game\SyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('game:sync', '同步 MG 上游游戏列表')]
class GameSyncCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('platform', InputArgument::OPTIONAL, 'wxgame、acewin、tada 或 goldengatex');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $platforms = $input->getArgument('platform') ? [$input->getArgument('platform')] : array_keys(config('game_platforms.platforms'));
        foreach ($platforms as $platform) {
            $result = (new SyncService())->sync($platform);
            $output->writeln("{$result['platform']}: {$result['brands']} brands, {$result['games']} games");
            foreach ($result['errors'] as $brand => $error) $output->writeln("  {$brand}: {$error}");
        }
        return self::SUCCESS;
    }
}
