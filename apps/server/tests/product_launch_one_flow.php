<?php

declare(strict_types=1);

use app\service\ProductLaunchTargetService;
use app\service\SkuWorkbenchService;

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/common.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$sku = [
    'id' => 901,
    'name' => '双核抽水泵',
    'category' => '家用抽水泵',
    'sku_code' => 'ONE-FLOW-901',
    'source_image_path' => '/storage/one-flow.png',
    'reference_images_json' => json_encode(['/storage/one-flow-detail.png']),
    'selling_points' => json_encode(['双核泵体', '便携提手'], JSON_UNESCAPED_UNICODE),
    'product_description' => '用于庭院浇灌与车辆冲洗的便携式家用抽水泵。',
    'report_appearance' => '黑色与橙色泵体，带便携提手。',
    'report_audience' => '家庭庭院与车辆清洁用户。',
    'report_scenarios' => '庭院浇灌、车辆冲洗。',
    'report_risks' => '具体扬程和流量需以商品参数为准。',
    'launch_targets_json' => json_encode(['targets' => [
        ['platform' => 'taobao', 'language' => 'zh-CN', 'primary' => true],
        ['platform' => 'amazon', 'language' => 'en-US'],
    ]]),
    'launch_direction' => '突出家庭多场景使用',
];
$projects = [[
    'id' => 31,
    'screen_plan' => json_encode([['title' => '核心卖点'], ['title' => '使用场景']], JSON_UNESCAPED_UNICODE),
]];
$state = (new ProductLaunchTargetService())->forSku($sku);
$workbench = (new SkuWorkbenchService())->build($sku, $projects, $state);

$assert($workbench['ready'] === true && $workbench['visual_ready'] === true, 'Name, category and a reference image must be enough for product-image creation.');
$assert(count($state['targets']) === 2 && $state['launch_direction'] === '突出家庭多场景使用', 'Reusable channel targets and launch direction must remain on the SKU.');
$assert(count($workbench['readiness']) === 4, 'The compact workbench must expose only product, report, detail and delivery readiness.');
$assert($workbench['uses'][0]['enabled'] === true && $workbench['uses'][1]['enabled'] === true && $workbench['uses'][2]['enabled'] === true, 'A SKU with a detail plan must enable all three creation entries.');

$withoutPlan = (new SkuWorkbenchService())->build($sku, [], $state);
$assert($withoutPlan['ready'] === true, 'A missing detail plan must not block product-image creation.');
$assert($withoutPlan['uses'][1]['enabled'] === true, 'Listing images must stay enabled without a detail-page plan.');
$assert($withoutPlan['uses'][0]['enabled'] === false && $withoutPlan['uses'][2]['enabled'] === false, 'Only detail-page uses may require a detail plan.');

$missingReference = $sku;
$missingReference['source_image_path'] = '';
$missing = (new SkuWorkbenchService())->build($missingReference, $projects, $state);
$assert($missing['ready'] === false, 'A product reference image remains a required direct-generation input.');
$assert(in_array('商品参考图', $missing['readiness'][0]['missing'], true), 'The compact readiness message must identify the missing reference image.');

$root = dirname(__DIR__);
$route = (string) file_get_contents($root . '/route/app.php');
$controller = (string) file_get_contents($root . '/app/controller/MerchantProductWorkspace.php');
$view = (string) file_get_contents($root . '/view/merchant/sku_workbench.php');
$generationView = (string) file_get_contents($root . '/view/merchant/generate.php');
$merchantController = (string) file_get_contents($root . '/app/controller/Merchant.php');
$console = (string) file_get_contents($root . '/public/static/console.js');
$promptView = (string) file_get_contents($root . '/view/admin/prompt_business.php');
$visionQueue = (string) file_get_contents($root . '/app/service/VisionTaskQueue.php');

$assert(str_contains($route, "Route::get('skus/:id', 'MerchantProductWorkspace/detail')"), 'SKU route must point to the focused workbench controller.');
foreach (['overview', 'profile', 'foundation', 'channels'] as $legacySection) {
    $assert(str_contains($controller, "'{$legacySection}' =>"), "Legacy section {$legacySection} must map to a current workbench anchor.");
}
foreach (['product-identity', 'product-report', 'delivery-targets', 'creation-use'] as $anchor) {
    $assert(str_contains($view, 'id="' . $anchor . '"'), "Compact workbench anchor {$anchor} is missing.");
}
foreach (['fact-task', 'reference-role', 'fact_response', 'product_profile_json', '证据密度', '事实确认'] as $retired) {
    $assert(!str_contains($view . $controller, $retired), "Retired product-contract UI remains: {$retired}.");
}
$assert(!str_contains($view, 'sku-section-tabs'), 'The compact workbench must not render the historical four-page tab shell.');
$assert(str_contains($view, '这些字段不是出图审批门槛'), 'The report must be presented as an editable commerce record rather than a generation gate.');
$assert(substr_count($generationView, '<strong>先预览 1 张</strong>') === 2, 'Bundle and listing-image entry points must recommend a one-image preview.');
$assert(str_contains($generationView, 'data-launch-action-value="preview"') && str_contains($generationView, 'data-launch-action-value="full"'), 'Preview and full actions must remain machine-distinguishable.');
$assert(str_contains($console, "form.getAttribute('data-creative-ready') === '0'") && str_contains($console, 'event.stopImmediatePropagation()'), 'Client submission must keep the minimal readiness guard.');
$assert(str_contains($merchantController, "'mode' => \$launchAction === 'preview' ? 'first_image_preview' : 'normal'"), 'Listing preview must persist an explicit first-image task mode.');
$assert(str_contains($promptView, '查看完整模板') && str_contains($promptView, '编辑模板'), 'Prompt administration must focus on a complete task-specific prompt body.');
$assert(!str_contains($promptView, '当前生产配置编译') && !str_contains($promptView, '前置约束最终内容') && !str_contains($promptView, '尾部限制最终内容'), 'Prompt administration must not expose the retired three-segment compiler.');
$assert(str_contains($visionQueue, 'analyzeDetailPageProduct(')
    && !str_contains($visionQueue, 'buildDetailPagePlan(')
    && !str_contains($visionQueue, 'product_launch_stages'),
    'Product onboarding must use one rich direct vision report without a second planner or staged join.');
$assert(
    str_contains($visionQueue, "(string) (\$task['task_type'] ?? '') === 'product_launch'")
        && str_contains($visionQueue, 'must remain available for a merchant-initiated retry'),
    'A failed product-launch task must keep its uploaded source material available for manual retry.'
);

echo "Product launch one-flow contract passed.\n";
