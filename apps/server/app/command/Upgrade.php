<?php

declare(strict_types=1);

namespace app\command;

use app\support\Installer;
use think\console\Command;
use think\console\Input;
use think\console\Output;

final class Upgrade extends Command
{
    protected function configure(): void
    {
        $this->setName('app:upgrade')->setDescription('Apply backward-compatible database schema upgrades');
    }

    protected function execute(Input $input, Output $output): int
    {
        $changes = $this->app->make(Installer::class)->initializeApplication();
        $output->writeln('<info>Database upgrade completed.</info>');
        foreach ($changes as $change) {
            $output->writeln('<comment>' . $change . '</comment>');
        }
        return 0;
    }
}
