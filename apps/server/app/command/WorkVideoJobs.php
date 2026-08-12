<?php

declare(strict_types=1);

namespace app\command;

use app\service\VideoGenerationQueue;
use Throwable;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

final class WorkVideoJobs extends Command
{
    protected function configure(): void
    {
        $this->setName('jobs:work-video')
            ->addOption('once', null, Option::VALUE_NONE, 'Process one video submission or poll')
            ->addOption('drain', null, Option::VALUE_NONE, 'Process due video work until the queue is empty')
            ->setDescription('Submit and poll asynchronous video generation jobs');
    }

    protected function execute(Input $input, Output $output): int
    {
        $once = (bool) $input->getOption('once');
        $drain = (bool) $input->getOption('drain');
        if ($once && $drain) {
            $output->writeln('<error>--once and --drain cannot be used together.</error>');
            return 2;
        }
        $queue = $this->app->make(VideoGenerationQueue::class);
        do {
            try {
                $result = $queue->processNext();
            } catch (Throwable $exception) {
                $output->writeln('<error>Video worker error: ' . $exception->getMessage() . '</error>');
                if ($once || $drain) {
                    return 1;
                }
                sleep(5);
                continue;
            }
            if ($result !== null) {
                $job = $result['job'] ?? [];
                $output->writeln('<info>Video ' . ($result['status'] ?? 'updated') . ': ' . ($job['job_no'] ?? $job['id'] ?? '-') . '</info>');
            }
            if ($once) {
                return 0;
            }
            if ($drain && $result === null) {
                return 0;
            }
            if ($result === null) {
                sleep(3);
            }
        } while (true);
    }
}
