<?php

declare(strict_types=1);

use app\service\AdminPromptManagementService;
use app\service\MediaWorkflowTemplateService;
use app\service\PromptTemplateService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/common.php';

function assertDirectArchitecture(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$app = (new App($root))->initialize();
$prompts = $app->make(PromptTemplateService::class);
$management = $app->make(AdminPromptManagementService::class);
$workflows = $app->make(MediaWorkflowTemplateService::class);
$prompts->ensureBuiltIns();

$definitionCodes = array_column($prompts->builtInDefinitions(), 'code');
foreach (['builtin.image.main', 'builtin.detail.detail_01_hero', 'builtin.system.free_runtime'] as $code) {
    assertDirectArchitecture(in_array($code, $definitionCodes, true), "Missing complete prompt template {$code}.");
}
foreach ($definitionCodes as $code) {
    assertDirectArchitecture(
        !str_starts_with((string) $code, 'builtin.system.platform_')
            && !in_array($code, ['builtin.system.detail_offering_physical', 'builtin.system.detail_offering_service'], true),
        "Retired prompt fragment is still seeded: {$code}."
    );
}

$imageTemplate = (string) ($prompts->builtInDefinition('builtin.image.main')['content'] ?? '');
foreach (['{product_report}', '{creative_brief}', '{asset_type}', '{output_width}', '{output_height}', '{language_requirement}', '{reference_requirement}'] as $variable) {
    assertDirectArchitecture(str_contains($imageTemplate, $variable), "Image template is missing {$variable}.");
}
assertDirectArchitecture(
    str_contains($imageTemplate, '不得虚构商品品牌、参数、认证、功效、价格、赠品或不存在的配件'),
    'Image template lost the hard-fact boundary.'
);
assertDirectArchitecture(
    str_contains($imageTemplate, '四边至少保留画面宽高各 8% 的内边距')
        && str_contains($imageTemplate, '不得贴边、越界、被截断、只显示半个字'),
    'Image template lost the 0.21.1 safe-area boundary.'
);
$featureTemplate = (string) ($prompts->builtInDefinition('builtin.image.feature')['content'] ?? '');
assertDirectArchitecture(
    str_contains($featureTemplate, '避开商品主体和安全裁切区'),
    'Feature template lost the 0.21.1 title safe-area instruction.'
);
foreach (['营销角色合同', '文案合同', '证据密度合同', '套图风格锁', 'AI 视觉质量门', '自动质量重生'] as $retiredConstraint) {
    assertDirectArchitecture(
        !str_contains($featureTemplate, $retiredConstraint),
        "Safe-area repair restored retired architecture: {$retiredConstraint}."
    );
}
$detailTemplate = (string) ($prompts->builtInDefinition('builtin.detail.detail_01_hero')['content'] ?? '');
foreach (['{offering_requirement}', '{reference_requirement}', '{language_requirement}'] as $variable) {
    assertDirectArchitecture(str_contains($detailTemplate, $variable), "Detail template is missing {$variable}.");
}

$freeBusiness = $management->business('free_create');
assertDirectArchitecture(
    count((array) ($freeBusiness['visible_prompts'] ?? [])) === 1
        && (string) ($freeBusiness['visible_prompts'][0]['code'] ?? '') === 'builtin.system.free_runtime',
    'Free creation does not expose its one complete runtime template.'
);

Db::startTrans();
try {
    Db::name('prompt_templates')->where('code', 'builtin.image.main')->update([
        'content' => 'IMAGE_ONLY::{product_name}::{language_requirement}::{reference_requirement}',
        'is_customized' => 1,
    ]);
    Db::name('prompt_templates')->where('code', 'builtin.image.feature')->update([
        'content' => 'FEATURE_ONLY::{product_name}',
        'is_customized' => 1,
    ]);
    $imagePrompt = (new PromptTemplateService())->buildImagePrompt([
        'name' => '测试商品',
        'category' => '测试类目',
        'selling_points' => '["真实卖点"]',
        'preferred_image_ratio' => 'square_1_1',
        'reference_images_json' => '[]',
    ], 'taobao', 'main', true, 1, 1, 'zh-TW', '用户要求');
    assertDirectArchitecture(str_starts_with($imagePrompt, 'IMAGE_ONLY::测试商品::'), 'Image prompt did not render the selected template directly.');
    assertDirectArchitecture(str_contains($imagePrompt, '繁体字形') && !str_contains($imagePrompt, '已确认商品资料：'), 'Image prompt still appends a hidden segment or lost its language variable.');
    $restored = (new PromptTemplateService())->restoreLegacyImageSafeAreaBoundary();
    $legacyTemplate = (string) Db::name('prompt_templates')->where('code', 'builtin.image.main')->value('content');
    assertDirectArchitecture($restored >= 1, 'Legacy customized image template was not repaired.');
    assertDirectArchitecture(
        str_starts_with($legacyTemplate, 'IMAGE_ONLY::{product_name}')
            && str_contains($legacyTemplate, '四边至少保留画面宽高各 8% 的内边距'),
        'Legacy safe-area repair overwrote the customized template body or missed the crop boundary.'
    );
    $legacyFeature = (string) Db::name('prompt_templates')->where('code', 'builtin.image.feature')->value('content');
    assertDirectArchitecture(
        str_starts_with($legacyFeature, 'FEATURE_ONLY::{product_name}')
            && str_contains($legacyFeature, '四边至少保留画面宽高各 8% 的内边距')
            && str_contains($legacyFeature, '避开商品主体和安全裁切区'),
        'Legacy feature template lost its body or its title-specific crop boundary.'
    );
    assertDirectArchitecture(
        (new PromptTemplateService())->restoreLegacyImageSafeAreaBoundary() === 0,
        'Legacy safe-area repair is not idempotent.'
    );

    Db::name('prompt_templates')->where('code', 'builtin.system.free_runtime')->update([
        'content' => 'FREE_ONLY::{user_prompt}::{language_requirement}::{reference_requirement}',
        'is_customized' => 1,
    ]);
    $freePrompt = (new PromptTemplateService())->buildFreeGenerationPrompt('透明玻璃杯棚拍', 1024, 1024, 1, 1, true, 1, 'zh-TW');
    assertDirectArchitecture(
        str_starts_with($freePrompt, 'FREE_ONLY::透明玻璃杯棚拍::')
            && !str_contains($freePrompt, '不得虚构品牌'),
        'Free creation still appends code-owned prompt text outside its complete template.'
    );
} finally {
    Db::rollBack();
}

$engineSource = (string) file_get_contents($root . '/app/service/MediaWorkflowEngine.php');
$workerSource = (string) file_get_contents($root . '/app/command/WorkGenerationJobs.php');
$routeSource = (string) file_get_contents($root . '/route/app.php');
$visionQueueSource = (string) file_get_contents($root . '/app/service/VisionTaskQueue.php');
$newApiSource = (string) file_get_contents($root . '/app/service/NewApiClient.php');
$aiExecutionSource = (string) file_get_contents($root . '/app/service/AiExecutionService.php');
$aiMediaExecutionSource = (string) file_get_contents($root . '/app/service/AiMediaExecutionService.php');
$sub2ApiSource = (string) file_get_contents($root . '/app/service/Sub2ApiClient.php');
$taskCenterSource = (string) file_get_contents($root . '/public/static/merchant-studio.js');
$schemaUpgradeSource = (string) file_get_contents($root . '/app/service/SchemaUpgrade.php');
assertDirectArchitecture(str_contains($engineSource, "\$engine = \$canvas ? 'comfyui' : 'direct';"), 'Image execution is not canvas-only for ComfyUI.');
assertDirectArchitecture(str_contains($engineSource, "'workflow_engine' => 'direct'") && str_contains($engineSource, 'createVideo('), 'Video execution is not direct.');
assertDirectArchitecture(str_contains($workerSource, "(string) (\$job['platform'] ?? '') === 'canvas'"), 'Worker does not restrict ComfyUI dispatch to canvas jobs.');
assertDirectArchitecture(!str_contains($routeSource, 'internal/comfyui/videos'), 'Retired ComfyUI video endpoint is still registered.');
assertDirectArchitecture(
    str_contains($visionQueueSource, 'private const MAX_AUTOMATIC_ATTEMPTS = 2;')
        && str_contains($visionQueueSource, '$attempts < self::MAX_AUTOMATIC_ATTEMPTS')
        && preg_match("/content_safety_unavailable'[,]?[^]]*'retryable' => true/s", $visionQueueSource) === 1
        && !str_contains($visionQueueSource, '[15, 45]'),
    'Product recognition or a cached safety outage is not capped at one additional automatic attempt.'
);
assertDirectArchitecture(
    str_contains($visionQueueSource, "'vision_task_stale', 'retryable' => false")
        && !str_contains($visionQueueSource, '商品识别暂时中断，任务已重新排队。'),
    'A stale product-recognition request can still be blindly resent.'
);
assertDirectArchitecture(
    str_contains($newApiSource, "'product_vision_json_invalid'")
        && str_contains($newApiSource, "'retryable' => false"),
    'Unparseable product reports can still enter the technical retry loop.'
);
assertDirectArchitecture(
    str_contains($newApiSource, 'private const SAFETY_VISION_TIMEOUT_SECONDS = 45;')
        && str_contains($newApiSource, 'private const PRODUCT_VISION_TIMEOUT_SECONDS = 120;')
        && str_contains($newApiSource, 'self::SAFETY_VISION_TIMEOUT_SECONDS')
        && str_contains($newApiSource, 'self::PRODUCT_VISION_TIMEOUT_SECONDS')
        && str_contains($newApiSource, '$fallbackTimeout = $timeout - (int) ceil(microtime(true) - $routeStartedAt);')
        && str_contains($newApiSource, "\$result['routingFallbackAttempted'] = true;")
        && str_contains($newApiSource, "empty(\$result['routingRouteBudgetExhausted'])")
        && str_contains($newApiSource, "in_array(\$effectiveStatus, [425, 429, 503], true)"),
    'Vision safety or product reporting can still wait on the retired long timeout.'
);
assertDirectArchitecture(
    str_contains($aiExecutionSource, "['timeout_seconds'] = max(10, min(600, \$timeout))")
        && str_contains($aiMediaExecutionSource, "['timeout_seconds'] = max(10, min(600, \$timeout))")
        && str_contains($sub2ApiSource, 'CURLOPT_TIMEOUT => $requestTimeout'),
    'Declared model-call timeouts do not reach the Sub2API transport.'
);
assertDirectArchitecture(
    str_contains($schemaUpgradeSource, "['needs_review', 'manual_review']")
        && str_contains($schemaUpgradeSource, "['processing', 'running', 'retrying']")
        && str_contains($schemaUpgradeSource, 'legacy_vision_pipeline_retired'),
    'Legacy staged vision states are not terminalized during retirement.'
);
assertDirectArchitecture(
    !str_contains($taskCenterSource, '重试失败阶段')
        && str_contains($taskCenterSource, '重新处理'),
    'Task-center copy still exposes the retired staged pipeline.'
);

Db::startTrans();
try {
    $workflows->ensureBuiltIns();
    foreach ($workflows->templates() as $workflow) {
        assertDirectArchitecture(str_starts_with((string) $workflow['code'], 'canvas_edit_'), 'Non-canvas ComfyUI template remains active.');
    }
    $canvas = $workflows->template(MediaWorkflowTemplateService::CANVAS_EDIT_TEMPLATE, false);
    assertDirectArchitecture($canvas !== null, 'Canvas ComfyUI template is missing.');
    assertDirectArchitecture(
        (string) ($canvas['outputMap']['prompt_template'] ?? '') === '{prompt}'
            && (array) ($canvas['outputMap']['required_nodes'] ?? []) === ['AssetFactorySub2ApiImage', 'AssetFactorySaveImage'],
        'Canvas workflow still wraps prompts or carries retired layout nodes.'
    );
    $rejected = false;
    try {
        $workflows->compileCaptured(['workflow_template_code' => 'listing_bundle_v1'], []);
    } catch (RuntimeException $exception) {
        $rejected = str_contains($exception->getMessage(), 'ComfyUI 仅用于画布编辑');
    }
    assertDirectArchitecture($rejected, 'A non-canvas ComfyUI snapshot can still compile.');
} finally {
    Db::rollBack();
}

$retiredFiles = [
    'app/contract/CreativeOutputMode.php',
    'app/contract/GenerationPlan.php',
    'app/contract/PromptSnapshot.php',
    'app/service/GenerationPlanCompiler.php',
    'app/service/GenerationPlanPreflightService.php',
    'app/service/GenerationQualityGateService.php',
    'app/service/GenerationQualityInsightsService.php',
    'app/service/GenerationSnapshotService.php',
    'app/service/MarketingTextStrategyService.php',
    'app/service/PromptAssemblySchema.php',
    'app/service/PromptAssemblyService.php',
    'app/service/PromptCompiler.php',
    'app/service/PromptReleaseService.php',
    'app/service/PromptRevisionValidator.php',
    'app/service/WorkflowRoutingPolicy.php',
    'scripts/restore-gpt-image-production-baseline.php',
    'scripts/restore-pre-comfy-recognition.php',
];
foreach ($retiredFiles as $file) {
    assertDirectArchitecture(!is_file($root . '/' . $file), "Retired architecture file remains: {$file}.");
}

$retiredTables = ['prompt_quality_runs', 'prompt_quality_cases', 'prompt_release_sets', 'prompt_template_revisions', 'prompt_assembly_bindings', 'detail_design_system_versions'];
$driver = (string) Db::getConfig('type');
if ($driver === 'sqlite') {
    foreach ($retiredTables as $table) {
        $exists = Db::query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
        assertDirectArchitecture($exists === [], "Retired table remains after upgrade: {$table}.");
    }

    $retiredColumns = [
        'generation_jobs' => ['foundation_snapshot_json', 'creative_brief_json', 'generation_plan_json', 'creative_output_mode', 'visual_profile_ready', 'visual_profile_readiness_json'],
        'generation_job_items' => ['auto_retry_count', 'prompt_snapshot_json', 'workflow_snapshot_json', 'routing_policy_json', 'quality_status', 'quality_reason_codes_json', 'quality_evidence_json'],
        'detail_page_projects' => ['design_system_json', 'design_system_version_id'],
        'creative_products' => ['fidelity_policy', 'current_prompt_release_id', 'previous_prompt_release_id'],
        'workflow_template_versions' => ['prompt_wrapper_json', 'validation_result_json'],
    ];
    foreach ($retiredColumns as $table => $columns) {
        $currentColumns = array_column(Db::query("PRAGMA table_info({$table})"), 'name');
        foreach ($columns as $column) {
            assertDirectArchitecture(
                !in_array($column, $currentColumns, true),
                "Retired column remains after upgrade: {$table}.{$column}."
            );
        }
    }
}

echo "Direct generation architecture contracts completed successfully.\n";
