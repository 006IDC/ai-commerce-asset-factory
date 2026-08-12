<?php

declare(strict_types=1);

use app\service\ProductCreativeReadinessService;
use app\support\Web;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$app = (new App(dirname(__DIR__)))->initialize();
$readiness = $app->make(ProductCreativeReadinessService::class);
$merchantId = (int) Db::name('merchants')->order('id', 'asc')->value('id');
$userId = (int) Db::name('merchant_users')->where('merchant_id', $merchantId)->order('id', 'asc')->value('id');
$assert($merchantId > 0 && $userId > 0, 'Readiness test requires a local merchant fixture.');

$skuId = 0;
$projectId = 0;
$now = Web::now();
try {
    $skuId = (int) Db::name('skus')->insertGetId([
        'merchant_id' => $merchantId,
        'sku_code' => 'DIRECT-READY-' . strtoupper(bin2hex(random_bytes(4))),
        'name' => '直通就绪测试商品', 'category' => '测试类目',
        'selling_points' => '[]', 'creative_brief' => null, 'product_description' => null,
        'preferred_image_ratio' => '1:1', 'preferred_image_model' => null, 'search_terms' => '[]',
        'source_image_path' => '/storage/direct-ready.png', 'source_image_backup_path' => null,
        'reference_images_json' => '[]',
        'report_materials' => null, 'report_appearance' => null, 'report_target_audience' => null,
        'report_use_scenarios' => '[]', 'report_core_functions' => '[]',
        'report_uncertain_points' => '[]', 'report_prohibited_claims' => '[]',
        'report_brand_notes' => null, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
    ]);

    $visual = $readiness->forVisualSku($skuId, $merchantId);
    $assert($visual['ready'] && $visual['state'] === ProductCreativeReadinessService::READY,
        'Name, category and a reference image must be enough for product-image generation.');
    $assert(($visual['snapshot']['contract'] ?? '') === 'direct_product_image_readiness_v1',
        'Product image readiness must use the compact direct contract.');

    $withoutProject = $readiness->forSku($skuId, $merchantId);
    $assert(!$withoutProject['ready'] && in_array('详情页方案', array_column($withoutProject['missing'], 'label'), true),
        'A detail-page request may require a saved plan without blocking listing images.');

    $projectId = (int) Db::name('detail_page_projects')->insertGetId([
        'merchant_id' => $merchantId, 'created_by_user_id' => $userId, 'sku_id' => $skuId,
        'product_name' => '直通就绪测试商品', 'category' => '测试类目',
        'materials' => null, 'appearance' => null, 'target_audience' => null,
        'use_scenarios' => '[]', 'selling_points' => '[]', 'core_functions' => '[]',
        'uncertain_points' => '[]', 'prohibited_claims' => '[]', 'product_description' => null,
        'brand_notes' => null, 'creative_brief' => null,
        'screen_plan' => json_encode([['key' => 'hero', 'headline' => '核心卖点']], JSON_UNESCAPED_UNICODE),
        'source_image_url' => '/storage/direct-ready.png', 'source_image_backup_url' => null,
        'platform' => 'taobao', 'language' => 'zh-CN', 'status' => 'draft',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $detail = $readiness->forSku($skuId, $merchantId, $projectId);
    $assert($detail['ready'] && $detail['state'] === ProductCreativeReadinessService::READY,
        'A saved channel, language and screen plan must enable detail-page generation.');

    Db::name('skus')->where('id', $skuId)->update(['report_appearance' => '', 'report_materials' => '', 'updated_at' => Web::now()]);
    $assert($readiness->forVisualSku($skuId, $merchantId)['ready'],
        'Optional product-report fields must never become a product-image approval gate.');

    Db::name('skus')->where('id', $skuId)->update(['source_image_path' => '', 'updated_at' => Web::now()]);
    $missingReference = $readiness->forVisualSku($skuId, $merchantId);
    $assert(!$missingReference['ready'] && in_array('商品参考图', array_column($missingReference['missing'], 'label'), true),
        'The reference image remains a real generation input.');
    $payload = $readiness->responsePayload($missingReference);
    $assert(empty($payload['taskCreated']) && empty($payload['creditsReserved']),
        'Readiness rejection must prove no task and no credit reservation.');
} finally {
    if ($projectId > 0) { Db::name('detail_page_projects')->where('id', $projectId)->delete(); }
    if ($skuId > 0) { Db::name('skus')->where('id', $skuId)->delete(); }
}

echo "Direct product readiness contracts completed successfully.\n";
