<?php

declare(strict_types=1);

use app\service\AiCapability;
use app\service\ModelCapabilityRouter;
use app\service\SystemSettings;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = (new App(dirname(__DIR__)))->initialize();
$router = $app->make(ModelCapabilityRouter::class);
$settings = $app->make(SystemSettings::class);
$adminId = (int) Db::name('admin_users')->order('id', 'asc')->value('id');
if ($adminId <= 0) {
    throw new RuntimeException('Routing test administrator is missing.');
}

$suffix = strtolower(bin2hex(random_bytes(4)));
$primaryModelName = 'routing-image-primary-' . $suffix;
$secondaryModelName = 'routing-image-secondary-' . $suffix;
$now = date('Y-m-d H:i:s');
$readyTests = static fn (array $capabilities): string => json_encode([
    'capability_tests' => array_fill_keys(
        $capabilities,
        ['status' => 'ready', 'tested_at' => date('Y-m-d H:i:s')]
    ),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$insertModel = static function (string $modelName, array $capabilities) use ($readyTests, $now): int {
    $id = (int) Db::name('ai_models')->insertGetId([
        'gateway' => 'sub2api',
        'model_id' => $modelName,
        'display_name' => $modelName,
        'provider' => 'test',
        'protocol' => 'image',
        'capabilities_json' => json_encode($capabilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'input_modalities_json' => '["text","image"]',
        'output_modalities_json' => '["image"]',
        'limits_json' => '{"release_stage":"active","gray_merchant_ids":[]}',
        'adapter_config_json' => $readyTests($capabilities),
        'catalog_payload_json' => null,
        'user_selectable' => 1,
        'enabled' => 1,
        'sort_order' => 999,
        'health_status' => 'ready',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    foreach ($capabilities as $capability) {
        Db::name('ai_model_capabilities')->insert([
            'ai_model_id' => $id,
            'capability_code' => $capability,
            'confirmation_status' => 'confirmed',
            'test_status' => 'passed',
            'suggested_by' => 'test',
            'confirmed_by_admin_id' => null,
            'last_test_id' => null,
            'notes' => '模型路由测试',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
    return $id;
};
$primaryModelId = $insertModel($primaryModelName, [
    AiCapability::IMAGE_GENERATION,
    AiCapability::PRODUCT_VISION,
]);
$secondaryModelId = $insertModel($secondaryModelName, [
    AiCapability::IMAGE_GENERATION,
    AiCapability::IMAGE_EDIT,
]);
$seedream = Db::name('ai_models')->where('id', $primaryModelId)->find();
$gptImage = Db::name('ai_models')->where('id', $secondaryModelId)->find();
$originalMode = $settings->get('ai_routing_mode');
$originalGrayIds = $settings->get('ai_routing_gray_merchant_ids');
$originalVisionModel = $settings->get('default_vision_model');
$originalGenerationRoute = Db::name('ai_capability_routes')->where('capability_code', AiCapability::IMAGE_GENERATION)->find();
$originalEditRoute = Db::name('ai_capability_routes')->where('capability_code', AiCapability::IMAGE_EDIT)->find();
try {
    $poolRoute = [
        'primary_model_id' => $primaryModelId,
        'fallback_model_id' => null,
        'routing_strategy' => 'selectable_pool',
        'route_config_json' => json_encode([
            'pool_model_ids' => [$primaryModelId, $secondaryModelId],
            'default_model_id' => $primaryModelId,
        ]),
        'enabled' => 1,
        'updated_by_admin_id' => $adminId,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    if ($originalGenerationRoute) {
        Db::name('ai_capability_routes')->where('id', $originalGenerationRoute['id'])->update($poolRoute);
    } else {
        Db::name('ai_capability_routes')->insert($poolRoute + [
            'capability_code' => AiCapability::IMAGE_GENERATION,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
    $editPoolRoute = $poolRoute;
    $editPoolRoute['primary_model_id'] = $secondaryModelId;
    $editPoolRoute['route_config_json'] = json_encode([
        'pool_model_ids' => [$secondaryModelId],
        'default_model_id' => $secondaryModelId,
    ]);
    if ($originalEditRoute) {
        Db::name('ai_capability_routes')->where('id', $originalEditRoute['id'])->update($editPoolRoute);
    } else {
        Db::name('ai_capability_routes')->insert($editPoolRoute + [
            'capability_code' => AiCapability::IMAGE_EDIT,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
    $settings->setMany(['ai_routing_mode' => 'capability'], $adminId);
    $resolved = $router->resolve(AiCapability::IMAGE_GENERATION);
    if ($resolved['resolvedModel'] !== $primaryModelName || $resolved['protocol'] !== 'image_generation'
        || ($resolved['routingStrategy'] ?? '') !== 'selectable_pool') {
        throw new RuntimeException('Default image generation pool model was not resolved correctly.');
    }

    Db::name('ai_models')->where('id', $seedream['id'])->update(['health_status' => 'unavailable']);
    try {
        $router->resolve(AiCapability::IMAGE_GENERATION);
        throw new RuntimeException('Unavailable selected-pool default silently switched to another model.');
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), '不会自动切换其他模型')) {
            throw $exception;
        }
    }
    Db::name('ai_models')->where('id', $seedream['id'])->update(['health_status' => 'ready']);

    $requested = $router->resolve(AiCapability::IMAGE_EDIT, $secondaryModelId);
    if ($requested['resolvedModel'] !== $secondaryModelName
        || $requested['protocol'] !== 'image_edit'
        || !empty($requested['fallbackModel'])
        || !empty($requested['fallbackModelId'])) {
        throw new RuntimeException('Merchant-selectable image edit model was not resolved correctly.');
    }

    $settings->setMany([
        'ai_routing_mode' => 'legacy',
        'default_vision_model' => $primaryModelName,
    ], $adminId);
    $legacy = $router->resolve(AiCapability::PRODUCT_VISION);
    if ($legacy['mode'] !== 'legacy' || $legacy['resolvedModel'] !== $primaryModelName) {
        throw new RuntimeException('Legacy model resolution changed unexpectedly.');
    }
    $settings->setMany(['ai_routing_mode' => $originalMode], $adminId);

} finally {
    if ($originalGenerationRoute) {
        Db::name('ai_capability_routes')->where('id', $originalGenerationRoute['id'])->update([
            'primary_model_id' => $originalGenerationRoute['primary_model_id'],
            'fallback_model_id' => $originalGenerationRoute['fallback_model_id'],
            'routing_strategy' => $originalGenerationRoute['routing_strategy'],
            'route_config_json' => $originalGenerationRoute['route_config_json'],
            'enabled' => $originalGenerationRoute['enabled'],
            'updated_by_admin_id' => $originalGenerationRoute['updated_by_admin_id'],
            'updated_at' => $originalGenerationRoute['updated_at'],
        ]);
    } else {
        Db::name('ai_capability_routes')->where('capability_code', AiCapability::IMAGE_GENERATION)->delete();
    }
    if ($originalEditRoute) {
        Db::name('ai_capability_routes')->where('id', $originalEditRoute['id'])->update([
            'primary_model_id' => $originalEditRoute['primary_model_id'],
            'fallback_model_id' => $originalEditRoute['fallback_model_id'],
            'routing_strategy' => $originalEditRoute['routing_strategy'],
            'route_config_json' => $originalEditRoute['route_config_json'],
            'enabled' => $originalEditRoute['enabled'],
            'updated_by_admin_id' => $originalEditRoute['updated_by_admin_id'],
            'updated_at' => $originalEditRoute['updated_at'],
        ]);
    } else {
        Db::name('ai_capability_routes')->where('capability_code', AiCapability::IMAGE_EDIT)->delete();
    }
    $settings->setMany([
        'ai_routing_mode' => $originalMode,
        'ai_routing_gray_merchant_ids' => $originalGrayIds,
        'default_vision_model' => $originalVisionModel,
    ], $adminId);
    Db::name('ai_model_capabilities')->whereIn('ai_model_id', [$primaryModelId, $secondaryModelId])->delete();
    Db::name('ai_models')->whereIn('id', [$primaryModelId, $secondaryModelId])->delete();
}

echo "Model capability routing tests completed successfully.\n";
