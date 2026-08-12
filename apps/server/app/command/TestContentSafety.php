<?php

declare(strict_types=1);

namespace app\command;

use app\service\ContentSafetyService;
use app\service\NewApiClient;
use think\console\Command;
use think\console\Input;
use think\console\Output;

final class TestContentSafety extends Command
{
    protected function configure(): void
    {
        $this->setName('security:test')->setDescription('Test text and image moderation through the native AI account pool');
    }

    protected function execute(Input $input, Output $output): int
    {
        $safety = $this->app->make(ContentSafetyService::class);
        $newApi = $this->app->make(NewApiClient::class);
        $configuration = $safety->configuration();
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $textResult = $newApi->moderateContent(
            '普通家居用品，简洁实用的电商商品介绍。',
            null,
            $configuration['model'],
            $configuration['fallbackModel']
        );
        $imageResult = $newApi->moderateContent(null, [
            'content' => $image === false ? '' : $image,
            'mime' => 'image/png',
            'filename' => 'safety-test.png',
        ], $configuration['model'], $configuration['fallbackModel']);
        foreach (['Text' => $textResult, 'Image' => $imageResult] as $type => $result) {
            if (!$result['ok']) {
                $output->writeln('<error>' . $type . ' content safety test failed: ' . (string) $result['reason'] . '</error>');
                return 1;
            }
            if ($result['flagged']) {
                $output->writeln('<error>' . $type . ' content safety test returned an unexpected violation.</error>');
                return 1;
            }
        }
        $output->writeln('<info>Content safety test passed.</info>');
        $output->writeln('<comment>Text model: ' . (string) $textResult['model'] . '</comment>');
        $output->writeln('<comment>Image model: ' . (string) $imageResult['model'] . '</comment>');
        return 0;
    }
}
