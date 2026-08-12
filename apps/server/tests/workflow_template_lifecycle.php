<?php

declare(strict_types=1);

use app\service\MediaWorkflowTemplateService;
use app\service\WorkflowReleaseService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertWorkflowLifecycle(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function workflowValueAtPath(array $workflow, string $path): mixed
{
    $value = $workflow;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return null;
        }
        $value = $value[$segment];
    }
    return $value;
}

$app = (new App(dirname(__DIR__)))->initialize();
$templates = $app->make(MediaWorkflowTemplateService::class);
$releases = $app->make(WorkflowReleaseService::class);

Db::startTrans();
try {
    $templates->ensureBuiltIns();
    assertWorkflowLifecycle(
        (int) Db::name('media_workflow_templates')->where('code', 'not like', 'canvas_edit_%')->count() === 0,
        'A retired non-canvas ComfyUI template remains.'
    );
    assertWorkflowLifecycle(
        (int) Db::name('workflow_template_versions')->where('template_code', 'not like', 'canvas_edit_%')->count() === 0,
        'A retired non-canvas ComfyUI version remains.'
    );

    $template = $templates->template(MediaWorkflowTemplateService::CANVAS_EDIT_TEMPLATE, false);
    assertWorkflowLifecycle($template !== null, 'Canvas ComfyUI template is missing.');
    assertWorkflowLifecycle(
        (array) ($template['outputMap']['required_nodes'] ?? []) === ['AssetFactorySub2ApiImage', 'AssetFactorySaveImage'],
        'Canvas template retained a deterministic text/layout node.'
    );

    $compiled = $templates->compileForTest((int) $template['id'], [
        'prompt' => '保持商品一致，更换为干净摄影棚背景。',
        'negative_prompt' => '',
        'reference_images_json' => '["asset-factory/test/reference.png"]',
        'mask_image' => '',
        'model' => 'gpt-image-2',
        'size' => '1024x1024',
        'seed' => 20260808,
        'job_no' => 'CANVAS-WORKFLOW-TEST',
        'generation_item_id' => 9001,
        'workflow_code' => MediaWorkflowTemplateService::CANVAS_EDIT_TEMPLATE,
        'workflow_version' => (int) $template['version'],
        'idempotency_key' => 'canvas-workflow-test',
        'output_prefix' => 'asset-factory/system-tests/canvas',
    ]);
    $promptPath = (string) ($template['inputMap']['prompt'][0] ?? '');
    assertWorkflowLifecycle(
        workflowValueAtPath((array) $compiled['workflow'], $promptPath) === '保持商品一致，更换为干净摄影棚背景。',
        'Canvas compile changed the complete edit prompt.'
    );

    $dependencies = $templates->testAgainstNodes((int) $template['id'], [
        'AssetFactorySub2ApiImage' => [],
        'AssetFactorySaveImage' => [],
    ]);
    assertWorkflowLifecycle(!empty($dependencies['ok']), 'Canvas dependency check failed with both required nodes present.');

    $tested = $releases->markTemplateTested((int) $template['id'], ['ok' => true, 'message' => 'contract'], 1);
    $published = $releases->publish('canvas_edit', (int) $tested['id'], 1, '合同测试发布');
    assertWorkflowLifecycle((string) $published['status'] === 'published', 'Tested canvas workflow could not be published.');
    assertWorkflowLifecycle($releases->snapshotForProduct('product_image') === null, 'Direct product exposes a ComfyUI workflow snapshot.');

    $publishRejected = false;
    try {
        $releases->publish('listing_bundle', (int) $tested['id'], 1, '非法发布');
    } catch (RuntimeException $exception) {
        $publishRejected = str_contains($exception->getMessage(), '只能发布到画布编辑');
    }
    assertWorkflowLifecycle($publishRejected, 'Non-canvas product accepted a ComfyUI release.');
} finally {
    Db::rollBack();
}

echo "Canvas workflow lifecycle contracts completed successfully.\n";
