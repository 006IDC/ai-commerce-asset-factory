<?php

declare(strict_types=1);

use app\service\AiCapability;
use app\service\ModelCapabilityRouter;
use app\service\ModelCatalogService;
use app\service\SystemSettings;
use app\support\Web;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = (new App(dirname(__DIR__)))->initialize();
$catalog = $app->make(ModelCatalogService::class);
$router = $app->make(ModelCapabilityRouter::class);
$settings = $app->make(SystemSettings::class);
$adminId = (int) Db::name('admin_users')->order('id', 'asc')->value('id');
if ($adminId <= 0) {
    throw new RuntimeException('Model release lifecycle test requires an administrator.');
}

$suffix = strtolower(bin2hex(random_bytes(4)));
$capability = AiCapability::CONTENT_PLANNER;
$readyConfig = json_encode(['capability_tests' => [
    $capability => ['status' => 'ready', 'tested_at' => Web::now()],
]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$insertModel = static function (string $name, string $stage) use ($capability, $readyConfig): int {
    $now = Web::now();
    $id = (int) Db::name('ai_models')->insertGetId([
        'gateway' => 'sub2api',
        'model_id' => $name,
        'display_name' => $name,
        'provider' => 'test',
        'protocol' => 'chat',
        'capabilities_json' => json_encode([$capability]),
        'input_modalities_json' => json_encode(['text']),
        'output_modalities_json' => json_encode(['text']),
        'limits_json' => json_encode([
            'release_stage' => $stage,
            'gray_merchant_ids' => [1],
        ]),
        'adapter_config_json' => $readyConfig,
        'catalog_payload_json' => null,
        'user_selectable' => 0,
        'enabled' => 1,
        'sort_order' => 99999,
        'health_status' => 'ready',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    Db::name('ai_model_capabilities')->insert([
        'ai_model_id' => $id,
        'capability_code' => $capability,
        'confirmation_status' => 'confirmed',
        'test_status' => 'passed',
        'suggested_by' => 'test',
        'confirmed_by_admin_id' => null,
        'last_test_id' => null,
        'notes' => '模型发布生命周期测试',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    return $id;
};

Db::startTrans();
try {
    $candidateModelId = $insertModel('release-candidate-' . $suffix, 'gray');
    $activeModelId = $insertModel('release-active-' . $suffix, 'active');
    $deletableModelId = $insertModel('release-delete-' . $suffix, 'candidate');
    $route = Db::name('ai_capability_routes')->where('capability_code', $capability)->find();
    $routeData = [
        'primary_model_id' => $candidateModelId,
        'fallback_model_id' => null,
        'routing_strategy' => 'single',
        'route_config_json' => '{}',
        'enabled' => 1,
        'updated_by_admin_id' => $adminId,
        'updated_at' => Web::now(),
    ];
    if ($route) {
        Db::name('ai_capability_routes')->where('id', (int) $route['id'])->update($routeData);
    } else {
        Db::name('ai_capability_routes')->insert($routeData + [
            'capability_code' => $capability,
            'created_at' => Web::now(),
        ]);
    }
    $settings->setMany(['ai_routing_mode' => 'capability'], $adminId);

    $unreleasedRejected = false;
    try {
        $router->resolve($capability, null, 999999);
    } catch (RuntimeException $exception) {
        $unreleasedRejected = str_contains($exception->getMessage(), '不可用');
    }
    if (!$unreleasedRejected) {
        throw new RuntimeException('Historical gray configuration still affected runtime routing.');
    }
    echo "[ok] historical gray merchant lists are ignored by runtime routing\n";

    Db::name('ai_capability_routes')->where('capability_code', $capability)->update([
        'primary_model_id' => $activeModelId,
        'updated_at' => Web::now(),
    ]);
    $resolution = $router->resolve($capability, null, 999999);
    if ((int) $resolution['resolvedModelId'] !== $activeModelId
        || !empty($resolution['fallbackUsed'])
        || (int) ($resolution['fallbackModelId'] ?? 0) > 0) {
        throw new RuntimeException('Active model was not resolved as one full-release route.');
    }
    echo "[ok] active model applies atomically without merchant or percentage routing\n";

    $archived = $catalog->deleteModel($deletableModelId);
    $archivedRow = Db::name('ai_models')->where('id', $deletableModelId)->find();
    if ((int) $archived['id'] !== $deletableModelId
        || !$archivedRow
        || (string) ($archivedRow['lifecycle_status'] ?? '') !== 'archived'
        || (int) ($archivedRow['enabled'] ?? 1) !== 0) {
        throw new RuntimeException('Unreferenced old model was not archived safely.');
    }
    echo "[ok] unreferenced old models are archived without deleting history\n";
} finally {
    Db::rollBack();
}

echo "Model release lifecycle tests completed successfully.\n";
