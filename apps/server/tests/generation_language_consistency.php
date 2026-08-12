<?php

declare(strict_types=1);

use app\service\PromptTemplateService;
use app\support\LocalizedCopy;
use think\App;

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/common.php';

function assertLanguageConsistency(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
(new App($root))->initialize();

assertLanguageConsistency(
    LocalizedCopy::promptRule('zh-TW') === '所有消费者可见中文必须逐字使用繁体字形，禁止混入任何简体字；语义相同但字形为简体也属于失败。',
    'Traditional Chinese prompt rule changed or disappeared.'
);
assertLanguageConsistency(str_contains(LocalizedCopy::promptRule('zh-CN'), '禁止混入任何繁体字'), 'Simplified Chinese prompt rule is missing.');
assertLanguageConsistency(LocalizedCopy::promptRule('en-US') === '', 'Non-Chinese generation received an unrelated script rule.');

if (class_exists(Transliterator::class)) {
    assertLanguageConsistency(
        LocalizedCopy::normalize('实木三层可翻转收纳梯凳', 'zh-TW') === '實木三層可翻轉收納梯凳',
        'Simplified product copy was not normalized to Traditional Chinese.'
    );
    assertLanguageConsistency(
        LocalizedCopy::normalize('實木三層可翻轉收納梯凳', 'zh-CN') === '实木三层可翻转收纳梯凳',
        'Traditional product copy was not normalized to Simplified Chinese.'
    );
}

$prompts = new PromptTemplateService();
$freeTraditional = $prompts->buildFreeGenerationPrompt('製作商品宣傳圖', 1024, 1024, 1, 1, false, 0, 'zh-TW');
assertLanguageConsistency(
    str_contains($freeTraditional, '繁体中文')
        && str_contains($freeTraditional, '禁止混入任何简体字'),
    'Traditional Chinese free-creation request does not carry an explicit script rule.'
);
$freeSimplified = (new PromptTemplateService())->buildFreeGenerationPrompt('制作商品宣传图', 1024, 1024, 1, 1, false, 0, 'zh-CN');
assertLanguageConsistency(
    str_contains($freeSimplified, '简体中文')
        && str_contains($freeSimplified, '禁止混入任何繁体字'),
    'Simplified Chinese free-creation request does not carry an explicit script rule.'
);

$source = (string) file_get_contents($root . '/app/support/LocalizedCopy.php');
assertLanguageConsistency(!str_contains($source, 'copyPlanIssues'), 'Deleted copy_plan language contract remains in runtime code.');

echo "Generation language consistency contracts completed successfully.\n";
