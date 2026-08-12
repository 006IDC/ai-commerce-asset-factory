<?php

declare(strict_types=1);

use app\service\AiCapability;
use app\service\ModelCatalogService;
use app\service\PricingService;
use app\support\Web;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = (new App(dirname(__DIR__)))->initialize();
$model = Db::name('ai_models')->where('model_id', '<>', '')->order('id', 'asc')->find();
if (!$model) {
    throw new RuntimeException('No logical model is available for pricing regression test.');
}

$modelId = (int) $model['id'];
$originalRows = Db::name('ai_model_prices')->where('ai_model_id', $modelId)->select()->toArray();
$originalCapabilities = (string) ($model['capabilities_json'] ?? '[]');

try {
    $testCapabilities = array_values(array_unique(array_merge(
        json_decode($originalCapabilities, true) ?: [],
        [AiCapability::IMAGE_GENERATION, AiCapability::PRODUCT_VISION, AiCapability::SAFETY_TEXT]
    )));
    Db::name('ai_models')->where('id', $modelId)->update([
        'capabilities_json' => json_encode($testCapabilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'updated_at' => Web::now(),
    ]);
    Db::name('ai_model_prices')->where('ai_model_id', $modelId)->delete();
    $pricing = $app->make(PricingService::class);
    $fallback = $pricing->priceForModel(AiCapability::IMAGE_GENERATION, (string) $model['model_id'], 8);
    if ($fallback['source'] !== 'legacy' || (int) $fallback['credits'] !== 8) {
        throw new RuntimeException('Unconfigured model pricing did not fall back to the global price.');
    }
    echo "[ok] unconfigured model falls back to global price\n";

    Db::name('ai_model_prices')->insert([
        'ai_model_id' => $modelId,
        'capability_code' => AiCapability::IMAGE_GENERATION,
        'unit_type' => 'image',
        'unit_value' => 1,
        'credits' => 13,
        'config_json' => '{}',
        'enabled' => 1,
        'created_at' => Web::now(),
        'updated_at' => Web::now(),
    ]);
    $modelPrice = $pricing->priceForModel(AiCapability::IMAGE_GENERATION, (string) $model['model_id'], 8);
    if ($modelPrice['source'] !== 'model' || (int) $modelPrice['credits'] !== 13 || $modelPrice['unit_type'] !== 'image') {
        throw new RuntimeException('Enabled model-specific price was not selected.');
    }
    echo "[ok] model-specific capability price selected\n";

    $visionFallback = $pricing->priceForModel(AiCapability::PRODUCT_VISION, (string) $model['model_id'], 1);
    if ($visionFallback['source'] !== 'platform' || (int) $visionFallback['credits'] !== 0) {
        throw new RuntimeException('Internal product vision pricing was not forced to platform-funded.');
    }
    echo "[ok] internal product vision pricing is platform-funded\n";

    Db::name('ai_model_prices')->where('ai_model_id', $modelId)->update(['enabled' => 0, 'updated_at' => Web::now()]);
    $disabled = $pricing->priceForModel(AiCapability::IMAGE_GENERATION, (string) $model['model_id'], 8);
    if ($disabled['source'] !== 'legacy' || (int) $disabled['credits'] !== 8) {
        throw new RuntimeException('Disabled model-specific price did not fall back to the global price.');
    }
    echo "[ok] disabled model price falls back to global price\n";

    $catalog = $app->make(ModelCatalogService::class);
    $updated = $catalog->saveCapabilityPrices([
        $modelId => [
            AiCapability::PRODUCT_VISION => [
                'unit_type' => 'request',
                'unit_value' => '1',
                'credits' => '5',
                'strategy' => 'custom',
            ],
            AiCapability::SAFETY_TEXT => [
                'unit_type' => 'request',
                'unit_value' => '1',
                'strategy' => 'platform',
            ],
        ],
    ]);
    $visionPrice = $pricing->priceForModel(AiCapability::PRODUCT_VISION, (string) $model['model_id'], 1);
    $safetyPrice = $pricing->priceForModel(AiCapability::SAFETY_TEXT, (string) $model['model_id'], 9);
    if ($updated !== 2 || $visionPrice['source'] !== 'platform' || (int) $visionPrice['credits'] !== 0
        || $safetyPrice['source'] !== 'platform' || (int) $safetyPrice['credits'] !== 0) {
        throw new RuntimeException('Central capability pricing did not lock internal capabilities to platform-funded.');
    }
    echo "[ok] central pricing locks vision and text capabilities to platform-funded\n";

    $catalog->saveCapabilityPrices([
        $modelId => [
            AiCapability::PRODUCT_VISION => [
                'strategy' => 'inherit',
            ],
        ],
    ]);
    $visionInherited = $pricing->priceForModel(AiCapability::PRODUCT_VISION, (string) $model['model_id'], 4);
    $storedVisionPrices = Db::name('ai_model_prices')
        ->where('ai_model_id', $modelId)
        ->where('capability_code', AiCapability::PRODUCT_VISION)
        ->count();
    if ($visionInherited['source'] !== 'platform' || (int) $visionInherited['credits'] !== 0 || (int) $storedVisionPrices !== 1) {
        throw new RuntimeException('Internal capability pricing was not normalized back to platform-funded.');
    }
    echo "[ok] internal capability pricing ignores attempts to restore merchant billing\n";

    try {
        $catalog->saveCapabilityPrices([
            $modelId => [
                AiCapability::IMAGE_GENERATION => [
                    'strategy' => 'custom',
                    'credits' => '0',
                ],
            ],
        ]);
        throw new RuntimeException('A zero-credit custom strategy was accepted instead of using platform-funded pricing.');
    } catch (InvalidArgumentException $exception) {
        if (!str_contains($exception->getMessage(), '平台承担')) {
            throw $exception;
        }
    }
    echo "[ok] billable media capability requires an explicit positive custom price\n";
} finally {
    Db::name('ai_model_prices')->where('ai_model_id', $modelId)->delete();
    foreach ($originalRows as $row) {
        Db::name('ai_model_prices')->insert($row);
    }
    Db::name('ai_models')->where('id', $modelId)->update([
        'capabilities_json' => $originalCapabilities,
        'updated_at' => Web::now(),
    ]);
}

echo "Model pricing tests completed successfully.\n";
