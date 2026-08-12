<?php

declare(strict_types=1);

use app\service\ComfyUiClient;
use app\service\MediaWorkflowEngine;
use app\service\MediaWorkflowTemplateService;
use app\service\WorkflowReleaseService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli' || !in_array('--confirm-paid-test', $argv, true)) {
    fwrite(STDERR, "Usage: php scripts/activate-canvas-workflow.php --confirm-paid-test [--admin-id=1]\n");
    fwrite(STDERR, "This performs one real paid image-edit request before publishing the canvas-only ComfyUI workflow.\n");
    exit(2);
}

$adminId = 1;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--admin-id=')) {
        $adminId = max(1, (int) substr($argument, strlen('--admin-id=')));
    }
}

try {
    $app = (new App(dirname(__DIR__)))->initialize();
    $templates = $app->make(MediaWorkflowTemplateService::class);
    $engine = $app->make(MediaWorkflowEngine::class);
    $comfyUi = $app->make(ComfyUiClient::class);
    $releases = $app->make(WorkflowReleaseService::class);

    $expectedModes = [
        'product_report' => 'direct',
        'product_image' => 'direct',
        'listing_bundle' => 'direct',
        'detail_page' => 'direct',
        'free_create' => 'direct',
        'canvas_edit' => 'comfyui',
        'product_video' => 'direct',
    ];
    foreach ($expectedModes as $productCode => $expectedMode) {
        $actualMode = (string) Db::name('creative_products')->where('code', $productCode)->value('execution_mode');
        if ($actualMode !== $expectedMode) {
            throw new RuntimeException(sprintf(
                'Execution boundary mismatch for %s: expected %s, got %s.',
                $productCode,
                $expectedMode,
                $actualMode !== '' ? $actualMode : 'missing'
            ));
        }
    }

    $template = $templates->template(MediaWorkflowTemplateService::CANVAS_EDIT_TEMPLATE, false);
    if ($template === null) {
        throw new RuntimeException('Canvas workflow template is missing; run app:upgrade first.');
    }
    $templateId = (int) $template['id'];

    $objectInfo = $comfyUi->objectInfo();
    if (empty($objectInfo['ok'])) {
        throw new RuntimeException((string) ($objectInfo['message'] ?? 'ComfyUI dependency discovery failed.'));
    }
    $dependencyResult = $templates->testAgainstNodes($templateId, (array) ($objectInfo['nodes'] ?? []));
    if (empty($dependencyResult['ok'])) {
        throw new RuntimeException((string) ($dependencyResult['message'] ?? 'Canvas workflow dependencies are incomplete.'));
    }

    $testResult = $engine->testImageTemplate($templateId);
    if (empty($testResult['ok'])) {
        throw new RuntimeException((string) ($testResult['message'] ?? 'Canvas workflow real-result test failed.'));
    }
    $version = $releases->markTemplateTested($templateId, $testResult, $adminId);
    $published = $releases->publish(
        'canvas_edit',
        (int) $version['id'],
        $adminId,
        '0.58.0：仅为画布 AI 编辑发布经真实结果验证的 ComfyUI 工作流。'
    );

    echo json_encode([
        'ok' => true,
        'product_code' => 'canvas_edit',
        'template_code' => MediaWorkflowTemplateService::CANVAS_EDIT_TEMPLATE,
        'template_id' => $templateId,
        'workflow_version_id' => (int) $published['id'],
        'workflow_version' => (int) $published['version'],
        'workflow_hash' => (string) $published['workflow_hash'],
        'prompt_id' => (string) ($testResult['promptId'] ?? ''),
        'duration_ms' => max(0, (int) ($testResult['durationMs'] ?? 0)),
        'artifact_url' => (string) ($testResult['artifactUrl'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
