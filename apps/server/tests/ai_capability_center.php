<?php

declare(strict_types=1);

use app\service\AiBusinessCompatibilityService;
use app\service\AiCapability;
use app\service\AiCapabilityAssignmentService;
use app\service\AiModelCatalogService;
use app\service\AiModelTestService;
use app\service\ModelCapabilityRouter;
use app\support\Web;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = (new App(dirname(__DIR__)))->initialize();
$router = $app->make(ModelCapabilityRouter::class);
$compatibilities = $app->make(AiBusinessCompatibilityService::class);
$catalog = $app->make(AiModelCatalogService::class);
$assignments = $app->make(AiCapabilityAssignmentService::class);
$tests = $app->make(AiModelTestService::class);
$adminId = (int) Db::name('admin_users')->order('id', 'asc')->value('id');
$suffix = strtolower(bin2hex(random_bytes(4)));
$now = Web::now();

$insertModel = static function (string $alias, array $capabilities, string $relationStatus) use ($now): int {
    $id = (int) Db::name('ai_models')->insertGetId([
        'gateway' => 'sub2api',
        'model_id' => $alias,
        'display_name' => $alias,
        'provider' => 'test',
        'protocol' => in_array(AiCapability::IMAGE_EDIT, $capabilities, true) ? 'image_edit' : 'image_generation',
        'capabilities_json' => json_encode($capabilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'input_modalities_json' => json_encode(['text', 'image']),
        'output_modalities_json' => json_encode(['image']),
        'limits_json' => json_encode(['release_stage' => 'active']),
        'adapter_config_json' => json_encode(['capability_tests' => array_fill_keys(
            $capabilities,
            ['status' => 'ready', 'tested_at' => $now]
        )], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'catalog_payload_json' => json_encode(['source' => 'sub2api', 'alias' => $alias]),
        'user_selectable' => 1,
        'enabled' => 1,
        'sort_order' => 99999,
        'health_status' => 'ready',
        'source_visible' => 1,
        'source_last_seen_at' => $now,
        'lifecycle_status' => $relationStatus === 'passed' ? 'available' : 'configured',
        'archived_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    foreach ($capabilities as $capability) {
        Db::name('ai_model_capabilities')->insert([
            'ai_model_id' => $id,
            'capability_code' => $capability,
            'confirmation_status' => $relationStatus === 'passed' ? 'confirmed' : 'suggested',
            'test_status' => $relationStatus,
            'suggested_by' => 'name_heuristic',
            'confirmed_by_admin_id' => null,
            'last_test_id' => null,
            'notes' => $relationStatus === 'passed' ? '自动化测试已验证' : '名称推断建议',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
    return $id;
};

$upsertPool = static function (string $capability, int $modelId) use ($now, $adminId): void {
    $data = [
        'primary_model_id' => $modelId,
        'fallback_model_id' => null,
        'routing_strategy' => 'selectable_pool',
        'route_config_json' => json_encode([
            'pool_model_ids' => [$modelId],
            'default_model_id' => $modelId,
        ]),
        'enabled' => 1,
        'updated_by_admin_id' => $adminId > 0 ? $adminId : null,
        'updated_at' => $now,
    ];
    $existing = Db::name('ai_capability_routes')->where('capability_code', $capability)->find();
    if ($existing) {
        Db::name('ai_capability_routes')->where('id', (int) $existing['id'])->update($data);
        return;
    }
    Db::name('ai_capability_routes')->insert($data + [
        'capability_code' => $capability,
        'created_at' => $now,
    ]);
};

Db::startTrans();
try {
    $suggestedId = $insertModel(
        'capability-suggested-' . $suffix,
        [AiCapability::IMAGE_GENERATION],
        'pending'
    );
    $upsertPool(AiCapability::IMAGE_GENERATION, $suggestedId);
    foreach ([
        'current model catalog' => array_column((array) $catalog->dashboard()['models'], 'id'),
        'function assignment options' => array_column((array) $assignments->dashboard()['models'], 'id'),
        'compatibility dashboard' => array_column((array) $compatibilities->dashboard()['models'], 'id'),
    ] as $surface => $ids) {
        if (!in_array($suggestedId, array_map('intval', $ids), true)) {
            throw new RuntimeException("A current visible model is missing from {$surface}.");
        }
    }
    $archivedFixtureId = $insertModel(
        'capability-archived-' . $suffix,
        [AiCapability::IMAGE_GENERATION],
        'passed'
    );
    Db::name('ai_models')->where('id', $archivedFixtureId)->update([
        'archived_at' => $now,
        'updated_at' => $now,
    ]);
    foreach ([
        'current model catalog' => array_column((array) $catalog->dashboard()['models'], 'id'),
        'function assignment options' => array_column((array) $assignments->dashboard()['models'], 'id'),
        'compatibility dashboard' => array_column((array) $compatibilities->dashboard()['models'], 'id'),
    ] as $surface => $ids) {
        if (in_array($archivedFixtureId, array_map('intval', $ids), true)) {
            throw new RuntimeException("An archived model leaked into {$surface}.");
        }
    }
    echo "[ok] ordinary model surfaces only include current visible unarchived models\n";
    $suggestionRejected = false;
    try {
        $router->resolve(AiCapability::IMAGE_GENERATION, $suggestedId, 1);
    } catch (RuntimeException $exception) {
        $suggestionRejected = str_contains($exception->getMessage(), '尚未通过当前能力测试');
    }
    if (!$suggestionRejected) {
        throw new RuntimeException('A name-based capability suggestion was treated as a passed real test.');
    }
    echo "[ok] inferred capability suggestions never open a model\n";

    $unassignedId = $insertModel(
        'capability-unassigned-' . $suffix,
        [AiCapability::IMAGE_GENERATION],
        'pending'
    );
    $catalogDashboard = $catalog->dashboard();
    $assignmentDashboard = $assignments->dashboard();
    if (in_array($unassignedId, array_map('intval', array_column((array) $catalogDashboard['models'], 'id')), true)
        || !in_array($unassignedId, array_map('intval', array_column((array) $catalogDashboard['pool'], 'id')), true)
        || in_array($unassignedId, array_map('intval', array_column((array) $assignmentDashboard['models'], 'id')), true)) {
        throw new RuntimeException('Unassigned model did not stay exclusively in the model pool.');
    }
    echo "[ok] unassigned and unverified models stay out of the in-use model homepage\n";

    $adminRoutes = (string) file_get_contents(dirname(__DIR__) . '/route/app.php');
    $testServiceSource = (string) file_get_contents(dirname(__DIR__) . '/app/service/AiModelTestService.php');
    if (str_contains($adminRoutes, "ai-models/:id/capabilities")
        || !str_contains($testServiceSource, "'confirmation_status' => \$testStatus === 'passed' ? 'confirmed' : 'suggested'")) {
        throw new RuntimeException('Model capability still depends on an ordinary administrator confirmation write.');
    }
    echo "[ok] real test results automatically establish verified capability without subjective confirmation\n";

    Db::name('ai_model_capabilities')
        ->where('ai_model_id', $suggestedId)
        ->where('capability_code', AiCapability::IMAGE_GENERATION)
        ->update([
            'confirmation_status' => 'confirmed',
            'test_status' => 'passed',
            'updated_at' => $now,
        ]);
    $resolved = $router->resolve(AiCapability::IMAGE_GENERATION, $suggestedId, 1);
    if ((int) ($resolved['resolvedModelId'] ?? 0) !== $suggestedId
        || !empty($resolved['fallbackModelId'])
        || !empty($resolved['fallbackModel'])) {
        throw new RuntimeException('A selected paid model did not resolve exactly without fallback.');
    }
    echo "[ok] a merchant-selected paid model resolves exactly without fallback\n";

    if ($compatibilities->isReady(
        $suggestedId,
        AiCapability::IMAGE_GENERATION,
        'free_create',
        'direct'
    )) {
        throw new RuntimeException('Capability test alone incorrectly unlocked a business scene.');
    }
    $compatibilities->record(
        $suggestedId,
        AiCapability::IMAGE_GENERATION,
        'free_create',
        'direct',
        'passed',
        null,
        '自动化测试业务兼容',
        $adminId
    );
    $compatibilityRow = Db::name('ai_business_compatibilities')
        ->where('ai_model_id', $suggestedId)
        ->where('capability_code', AiCapability::IMAGE_GENERATION)
        ->where('product_code', 'free_create')
        ->where('execution_channel', 'direct')
        ->find();
    if (!$compatibilityRow
        || !array_key_exists('workflow_version_id', $compatibilityRow)
        || !array_key_exists('restriction_json', $compatibilityRow)
        || json_decode((string) $compatibilityRow['restriction_json'], true) !== []) {
        throw new RuntimeException('Business compatibility did not preserve structured workflow/restriction fields.');
    }
    $testRecordId = (int) Db::name('ai_capability_tests')->insertGetId([
        'ai_model_id' => $suggestedId,
        'capability_code' => AiCapability::IMAGE_GENERATION,
        'test_scope' => 'business',
        'product_code' => 'free_create',
        'execution_channel' => 'direct',
        'workflow_version_id' => null,
        'status' => 'passed',
        'request_id' => null,
        'duration_ms' => 1,
        'paid_confirmed' => 0,
        'result_json' => json_encode([
            'ok' => true,
            'input_summary' => [],
            'output_summary' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'tested_by_admin_id' => $adminId > 0 ? $adminId : null,
        'created_at' => $now,
    ]);
    if ($testRecordId <= 0
        || !array_key_exists(
            'workflow_version_id',
            (array) Db::name('ai_capability_tests')->where('id', $testRecordId)->find()
        )) {
        throw new RuntimeException('Capability tests did not preserve a structured workflow release reference.');
    }
    echo "[ok] compatibility and test evidence preserve workflow release metadata\n";
    if (!$compatibilities->isReady(
        $suggestedId,
        AiCapability::IMAGE_GENERATION,
        'free_create',
        'direct'
    ) || $compatibilities->isReady(
        $suggestedId,
        AiCapability::IMAGE_GENERATION,
        'listing_bundle',
        'comfyui'
    )) {
        throw new RuntimeException('Direct and ComfyUI business compatibility were not isolated.');
    }
    echo "[ok] model capability and business-channel compatibility are separate gates\n";

    $paidConfirmationRequired = false;
    try {
        $tests->runCapabilityTest($suggestedId, AiCapability::IMAGE_GENERATION, false, $adminId);
    } catch (RuntimeException $exception) {
        $paidConfirmationRequired = str_contains($exception->getMessage(), '费用确认');
    }
    if (!$paidConfirmationRequired) {
        throw new RuntimeException('Paid model test ran without explicit cost confirmation.');
    }
    echo "[ok] real model tests require explicit cost confirmation\n";

    $modelTestSource = (string) file_get_contents(dirname(__DIR__) . '/app/service/AiModelTestService.php');
    $newApiSource = (string) file_get_contents(dirname(__DIR__) . '/app/service/NewApiClient.php');
    $controllerSource = (string) file_get_contents(dirname(__DIR__) . '/app/controller/AdminAiCapabilities.php');
    $routesSource = (string) file_get_contents(dirname(__DIR__) . '/route/app.php');
    $detailViewSource = (string) file_get_contents(dirname(__DIR__) . '/view/admin/ai_model_detail.php');
    foreach ([
        'service same-task refresh' => str_contains($modelTestSource, 'refreshVideoTest')
            && str_contains($modelTestSource, "if ((string) (\$test['status'] ?? '') !== 'pending')"),
        'provider task status refresh' => str_contains($newApiSource, 'refreshVideoCapabilityTest')
            && str_contains($newApiSource, '$this->videoStatus(')
            && str_contains($newApiSource, '已保存可播放样例'),
        'refresh route' => str_contains($routesSource, 'video-test-refresh'),
        'no-new-charge audit' => str_contains($controllerSource, "'new_paid_request_started' => false"),
        'pending task action' => str_contains($detailViewSource, '刷新同一测试任务')
            && str_contains($detailViewSource, '不会重新创建付费生成'),
    ] as $contract => $passed) {
        if (!$passed) {
            throw new RuntimeException("Video capability test contract failed: {$contract}.");
        }
    }
    echo "[ok] video output test refreshes one paid task until a playable local sample exists\n";

    Db::name('ai_models')->where('id', $suggestedId)->update([
        'source_visible' => 0,
        'lifecycle_status' => 'unavailable',
        'health_status' => 'unavailable',
        'updated_at' => $now,
    ]);
    $missingSourceRejected = false;
    try {
        $router->resolve(AiCapability::IMAGE_GENERATION, $suggestedId, 1);
    } catch (RuntimeException $exception) {
        $missingSourceRejected = str_contains($exception->getMessage(), '不可用');
    }
    if (!$missingSourceRejected) {
        throw new RuntimeException('A model missing from Sub2API remained usable for new tasks.');
    }
    foreach ([
        array_column((array) $catalog->dashboard()['models'], 'id'),
        array_column((array) $assignments->dashboard()['models'], 'id'),
        array_column((array) $compatibilities->dashboard()['models'], 'id'),
    ] as $ids) {
        if (in_array($suggestedId, array_map('intval', $ids), true)) {
            throw new RuntimeException('A source-invisible model leaked into an ordinary model surface.');
        }
    }
    echo "[ok] a model missing from Sub2API is blocked from new tasks\n";

    Db::name('ai_capability_routes')->where('capability_code', AiCapability::IMAGE_GENERATION)->update([
        'primary_model_id' => null,
        'fallback_model_id' => null,
        'route_config_json' => '{"pool_model_ids":[],"default_model_id":null}',
        'enabled' => 0,
        'updated_at' => $now,
    ]);
    Db::name('ai_model_prices')->insert([
        'ai_model_id' => $suggestedId,
        'capability_code' => AiCapability::IMAGE_GENERATION,
        'unit_type' => 'image',
        'unit_value' => 1,
        'credits' => 8,
        'config_json' => '{"strategy":"custom"}',
        'enabled' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    Db::name('ai_model_calls')->insert([
        'merchant_id' => null,
        'job_id' => null,
        'capability_code' => AiCapability::IMAGE_GENERATION,
        'ai_model_id' => $suggestedId,
        'request_id' => 'capability-center-' . $suffix,
        'status' => 'success',
        'created_at' => $now,
    ]);
    $priceCount = (int) Db::name('ai_model_prices')->where('ai_model_id', $suggestedId)->count();
    $callCount = (int) Db::name('ai_model_calls')->where('ai_model_id', $suggestedId)->count();
    $archived = $catalog->archive($suggestedId, $adminId);
    if (empty($archived['archived_at'])
        || (int) Db::name('ai_model_prices')->where('ai_model_id', $suggestedId)->count() !== $priceCount
        || (int) Db::name('ai_model_calls')->where('ai_model_id', $suggestedId)->count() !== $callCount) {
        throw new RuntimeException('Model archive deleted historical pricing or call records.');
    }
    echo "[ok] model archive preserves pricing and call history\n";

    $videoId = $insertModel(
        'seedance-unverified-' . $suffix,
        [AiCapability::VIDEO_IMAGE_TO_VIDEO, AiCapability::VIDEO_TEXT_TO_VIDEO],
        'pending'
    );
    if ((int) Db::name('ai_models')->where('id', $videoId)->value('enabled') !== 1) {
        throw new RuntimeException('Video test fixture was not created.');
    }
    foreach (Db::name('ai_model_capabilities')->where('ai_model_id', $videoId)->select()->toArray() as $relation) {
        if ((string) $relation['confirmation_status'] !== 'suggested'
            || (string) $relation['test_status'] !== 'pending') {
            throw new RuntimeException('Unverified video capability was promoted automatically.');
        }
    }
    if ($compatibilities->isReady(
        $videoId,
        AiCapability::VIDEO_IMAGE_TO_VIDEO,
        'product_video',
        'direct'
    )) {
        throw new RuntimeException('Unverified video model was opened for product video.');
    }
    echo "[ok] unverified image/video models remain unopened\n";
} finally {
    Db::rollBack();
}

echo "AI capability center contract tests completed successfully.\n";
