<?php

declare(strict_types=1);

use app\service\NewApiClient;
use think\App;

require dirname(__DIR__) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$app = (new App(dirname(__DIR__)))->initialize();
$client = $app->make(NewApiClient::class);
$reflection = new ReflectionClass($client);

$normalize = $reflection->getMethod('normalizeProductVisionResult');
$normalized = $normalize->invoke($client, [
    'productName' => '蓝色便携水泵', 'productCategory' => '家用水泵',
    'searchTerms' => "便携水泵，家用抽水泵", 'sellingPoints' => "双泵体\n便携提手",
    'description' => '蓝色便携式家用水泵。', 'brand_notes' => '图中未见明确品牌',
    'productFoundation' => [
        'primary_colors' => '蓝色、黑色', 'visible_components' => '泵体；提手',
        'use_scenarios' => '庭院浇灌；车辆冲洗', 'core_functions' => ['抽水', '冲洗'],
        'uncertain_points' => '具体扬程待核对', 'prohibited_claims' => ['不要声称商用认证'],
        'appearance' => '蓝色圆柱泵体，顶部带黑色提手', 'materials' => '外壳材质待核对',
    ],
]);
$assert(($normalized['name'] ?? '') === '蓝色便携水泵' && ($normalized['category'] ?? '') === '家用水泵',
    'Vision aliases must normalize into product name and category.');
$assert(($normalized['search_terms'] ?? []) === ['便携水泵', '家用抽水泵']
    && ($normalized['selling_points'] ?? []) === ['双泵体', '便携提手'],
    'Vision list fields must accept natural Chinese separators.');
$assert(($normalized['foundation']['primary_colors'] ?? []) === ['蓝色', '黑色']
    && ($normalized['foundation']['brand_notes'] ?? '') === '图中未见明确品牌',
    'The rich editable product report must remain intact without a fact ledger.');

$payloadMethod = $reflection->getMethod('productVisionPayload');
$payload = $payloadMethod->invoke($client, $normalized);
$assert(($payload['sellingPoints'] ?? []) === ['双泵体', '便携提手']
    && ($payload['foundation']['appearance'] ?? '') === '蓝色圆柱泵体，顶部带黑色提手',
    'The direct payload must preserve useful model prose instead of filtering it through evidence contracts.');

$identityMethod = $reflection->getMethod('applyMerchantVisionIdentity');
$merchantIdentity = $identityMethod->invoke($client, $normalized, [
    'product_name' => '用户确认名称', 'category' => '用户确认类目',
]);
$assert(($merchantIdentity['name'] ?? '') === '用户确认名称'
    && ($merchantIdentity['category'] ?? '') === '用户确认类目'
    && ($merchantIdentity['search_terms'] ?? null) === [],
    'User-entered identity must remain authoritative over recognition wording.');

$groundMethod = $reflection->getMethod('groundDetailPagePlan');
$plan = [
    'product_name' => '模型名称', 'category' => '模型类目',
    'selling_points' => ['模型卖点'],
    'screen_plan' => [
        ['key' => 'hero', 'headline' => '强视觉首屏', 'visual_direction' => '高对比营销场景'],
        ['key' => 'scenario', 'headline' => '多场景使用', 'visual_direction' => '庭院与车辆双场景'],
    ],
];
$grounded = $groundMethod->invoke($client, $plan, $normalized);
$assert(($grounded['product_name'] ?? '') === '蓝色便携水泵'
    && count((array) ($grounded['screen_plan'] ?? [])) === 2
    && ($grounded['screen_plan'][0]['visual_direction'] ?? '') === '高对比营销场景',
    'Grounding may project editable product data but must not replace the planner creative structure.');

$screens = [];
$screenIndex = 1;
foreach (detail_page_screen_options() as $key => $option) {
    $screens[] = [
        'key' => $key,
        'index' => $screenIndex++,
        'title' => (string) $option['label'],
        'headline' => '测试标题',
        'body' => '测试正文',
        'headline_zh' => '测试标题',
        'body_zh' => '测试正文',
        'visual_direction' => (string) $option['hint'],
    ];
}
$parseDetailReport = $reflection->getMethod('parseDetailReport');
$flatReport = $parseDetailReport->invoke($client, json_encode([
    'product_name' => '扁平结构水杯',
    'category' => '杯具',
    'selling_points' => ['便携'],
    'screen_plan' => $screens,
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$assert(!empty($flatReport['ok'])
    && ($flatReport['data']['product_name'] ?? '') === '扁平结构水杯'
    && count((array) ($flatReport['data']['screen_plan'] ?? [])) === count($screens),
    'The 0.21.1-style flat detail report must remain compatible.');

$wrappedReport = $parseDetailReport->invoke($client, json_encode([
    'product_report' => [
        'productName' => '嵌套结构水杯',
        'productCategory' => '杯具',
        'sellingPoints' => ['便携', '简约外观'],
    ],
    'screen_plan' => $screens,
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$assert(!empty($wrappedReport['ok'])
    && ($wrappedReport['data']['product_name'] ?? '') === '嵌套结构水杯'
    && ($wrappedReport['data']['selling_points'] ?? []) === ['便携', '简约外观']
    && count((array) ($wrappedReport['data']['screen_plan'] ?? [])) === count($screens),
    'A product_report envelope must preserve its sibling ten-screen plan.');

$source = (string) file_get_contents(dirname(__DIR__) . '/app/service/NewApiClient.php');
foreach (['fact_ledger', 'reference_classifications', 'consistency_constraints', 'evidence_density'] as $retiredContract) {
    $assert(!str_contains($source, $retiredContract), "Retired vision contract remains: {$retiredContract}.");
}
$assert(str_contains($source, "buildDetailReportPrompt([")
    && str_contains($source, '商品识别模型没有返回生产链路可解析的完整商品报告和十屏方案。'),
    'Product-vision capability tests must exercise the production detail-report contract.');

echo "Direct product vision normalization contracts completed successfully.\n";
