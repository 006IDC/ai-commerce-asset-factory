<?php

declare(strict_types=1);

use app\service\GenerationItemQueue;
use app\service\AiCapability;
use app\service\AiExecutionService;
use app\service\NewApiClient;
use app\service\SchemaUpgrade;
use app\service\SystemSettings;
use app\support\Web;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = (new App(dirname(__DIR__)))->initialize();
$app->make(SchemaUpgrade::class)->run();
$port = 26500 + random_int(0, 900);
$log = tempnam(sys_get_temp_dir(), 'sub2api-mock-');
$pipes = [];
$process = proc_open([
    PHP_BINARY,
    '-S',
    '127.0.0.1:' . $port,
    __DIR__ . '/mock_sub2api.php',
], [
    0 => ['pipe', 'r'],
    1 => ['file', $log, 'a'],
    2 => ['file', $log, 'a'],
], $pipes, dirname(__DIR__));
if (!is_resource($process)) {
    throw new RuntimeException('Unable to start Sub2API mock server.');
}

$ready = false;
$deadline = microtime(true) + 5;
do {
    $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.2);
    if (is_resource($socket)) {
        fclose($socket);
        $ready = true;
        break;
    }
    usleep(50000);
} while (microtime(true) < $deadline);
if (!$ready) {
    $detail = is_file($log) ? trim((string) file_get_contents($log)) : '';
    proc_terminate($process);
    proc_close($process);
    @unlink($log);
    throw new RuntimeException('Sub2API mock did not start' . ($detail !== '' ? ': ' . $detail : '.'));
}

$merchantId = (int) Db::name('merchants')->order('id', 'asc')->value('id');
if ($merchantId <= 0) {
    throw new RuntimeException('Sub2API test requires a merchant.');
}

Db::startTrans();
$jobIds = [];
try {
    $settings = $app->make(SystemSettings::class);
    $settings->setMany([
        'sub2api_enabled' => '1',
        'sub2api_base_url' => 'http://127.0.0.1:' . $port,
        'sub2api_service_key' => 'sub2api-test-service-key',
        'sub2api_timeout_seconds' => '30',
        'sub2api_image_async_enabled' => '0',
        'sub2api_gray_merchant_ids' => json_encode([$merchantId]),
    ], 1);
    $client = $app->make(NewApiClient::class);
    $execution = $app->make(AiExecutionService::class);
    $queue = $app->make(GenerationItemQueue::class);
    $now = Web::now();
    $suffix = strtoupper(bin2hex(random_bytes(4)));

    $createProcessingItem = static function (string $name) use ($merchantId, $now, $suffix, &$jobIds): array {
        $jobId = (int) Db::name('generation_jobs')->insertGetId([
            'merchant_id' => $merchantId,
            'sku_id' => null,
            'job_no' => 'SUB2API-' . $name . '-' . $suffix,
            'platform' => 'free',
            'language' => 'zh-CN',
            'asset_types' => '["free_creation"]',
            'safety_status' => 'passed',
            'status' => 'running',
            'model' => 'gpt-image-2',
            'estimated_credits' => 1,
            'actual_credits' => 0,
            'progress_current' => 0,
            'progress_total' => 1,
            'progress_stage' => 'generating',
            'progress_message' => 'Sub2API 测试',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $jobIds[] = $jobId;
        $itemId = (int) Db::name('generation_job_items')->insertGetId([
            'job_id' => $jobId,
            'merchant_id' => $merchantId,
            'position' => 1,
            'asset_type' => 'free_creation',
            'occurrence' => 1,
            'type_total' => 1,
            'estimated_credits' => 1,
            'status' => 'processing',
            'attempts' => 1,
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return [$jobId, $itemId];
    };

    [$generationJobId, $generationItemId] = $createProcessingItem('GEN');
    $generated = $client->generateImage(
        'gpt-image-2',
        '生成一张简单商品图',
        '1024x1024',
        null,
        'sub2api',
        [
            'merchant_id' => $merchantId,
            'job_id' => $generationJobId,
            'generation_item_id' => $generationItemId,
            'call_key' => 'sub2api-test-generation:' . $generationItemId,
            'expected_reference_count' => 0,
            'reference_fingerprints' => [],
        ]
    );
    $generationMarker = (string) Db::name('generation_job_items')->where('id', $generationItemId)->value('request_id');
    if (empty($generated['ok'])
        || empty($generated['sub2apiExecution'])
        || empty($generated['sub2apiAccepted'])
        || !str_starts_with($generationMarker, 'sub2api:af-')
        || (string) ($generated['data']['mock']['request_id'] ?? '') !== (string) ($generated['clientRequestId'] ?? '')
        || (string) ($generated['data']['mock']['idempotency_key'] ?? '') !== (string) ($generated['clientRequestId'] ?? '')) {
        throw new RuntimeException(
            'Image generation did not use Sub2API with deterministic dispatch protection: '
            . json_encode($generated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
    if ($queue->canCancel($generationJobId, $merchantId)) {
        throw new RuntimeException('A dispatched Sub2API image request remained cancellable.');
    }
    $queue->fail($generationItemId, '测试失败后保留派发标记');
    if ((string) Db::name('generation_job_items')->where('id', $generationItemId)->value('request_id') !== $generationMarker
        || $queue->retryItems($generationJobId, 'queued') !== 0) {
        throw new RuntimeException('A dispatched paid request could be cleared and automatically retried.');
    }
    echo "[ok] Sub2API generation uses an isolated service key and becomes non-cancellable before network dispatch\n";

    try {
        $client->generateImage('gpt-image-2', '不应发出的污染请求', '1024x1024', null, 'sub2api', [
            'merchant_id' => $merchantId,
            'job_id' => $generationJobId,
            'generation_item_id' => $generationItemId,
            'call_key' => 'sub2api-test-invalid-generation:' . $generationItemId,
            'expected_reference_count' => 1,
            'reference_fingerprints' => ['invalid'],
        ]);
        throw new RuntimeException('Text-to-image accepted a frozen reference count greater than zero.');
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), '冻结参考图数量')) {
            throw $exception;
        }
    }

    [$editJobId, $editItemId] = $createProcessingItem('EDIT');
    $reference = [
        'mime' => 'image/png',
        'filename' => 'reference.png',
        'content' => (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAIAAAAmkwkpAAAAFElEQVR4nGP4z8DAwMDAxAADCBYAOvoCBYw9H9kAAAAASUVORK5CYII=',
            true
        ),
    ];
    $referenceFingerprint = hash('sha256', (string) $reference['content']);
    $reference['_reference_originals'] = [[
        'content' => (string) $reference['content'],
        'mime' => 'image/png',
        'filename' => 'reference.png',
    ]];
    $reference['_reference_count'] = 1;
    $edited = $client->editImage(
        'gpt-image-2',
        '保持主体并修改背景',
        $reference,
        null,
        '1024x1024',
        null,
        'sub2api',
        [
            'merchant_id' => $merchantId,
            'job_id' => $editJobId,
            'generation_item_id' => $editItemId,
            'call_key' => 'sub2api-test-edit:' . $editItemId,
            'expected_reference_count' => 1,
            'reference_fingerprints' => [$referenceFingerprint],
            'outbound_reference_sha256' => hash('sha256', (string) $reference['content']),
        ]
    );
    if (empty($edited['ok'])
        || empty($edited['sub2apiExecution'])
        || empty($edited['data']['mock']['received_image'])
        || (string) ($edited['data']['mock']['path'] ?? '') !== '/v1/images/edits'
        || (string) ($edited['data']['mock']['image_sha256'] ?? '') !== $referenceFingerprint) {
        throw new RuntimeException(
            'Image edit did not use the Sub2API multipart endpoint: '
            . json_encode($edited, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
    echo "[ok] Sub2API image editing preserves the OpenAI-compatible multipart contract\n";

    try {
        $client->editImage('gpt-image-2', '不应发出的错误画板请求', $reference, null, '1024x1024', null, 'sub2api', [
            'merchant_id' => $merchantId,
            'job_id' => $editJobId,
            'generation_item_id' => $editItemId,
            'call_key' => 'sub2api-test-invalid-edit:' . $editItemId,
            'expected_reference_count' => 1,
            'reference_fingerprints' => [$referenceFingerprint],
            'outbound_reference_sha256' => str_repeat('0', 64),
        ]);
        throw new RuntimeException('Image edit accepted an outbound board with the wrong fingerprint.');
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), '画板')) {
            throw $exception;
        }
    }
    echo "[ok] generation/edit capability, original fingerprints, and outbound board hash fail closed before dispatch\n";

    [$womanJobId, $womanItemId] = $createProcessingItem('WOMAN');
    $woman = $client->generateImage(
        'gpt-image-2',
        '一位穿深蓝西装的成年职业女性站在明亮办公室内',
        '1024x1024',
        null,
        'sub2api',
        [
            'merchant_id' => $merchantId,
            'job_id' => $womanJobId,
            'generation_item_id' => $womanItemId,
            'call_key' => 'sub2api-test-woman:' . $womanItemId,
            'expected_reference_count' => 0,
            'reference_fingerprints' => [],
        ]
    );
    [$coffeeJobId, $coffeeItemId] = $createProcessingItem('COFFEE');
    $coffee = $client->generateImage(
        'gpt-image-2',
        '透明玻璃咖啡杯置于浅色木桌，自然晨光',
        '1024x1024',
        null,
        'sub2api',
        [
            'merchant_id' => $merchantId,
            'job_id' => $coffeeJobId,
            'generation_item_id' => $coffeeItemId,
            'call_key' => 'sub2api-test-coffee:' . $coffeeItemId,
            'expected_reference_count' => 0,
            'reference_fingerprints' => [],
        ]
    );
    $womanMock = (array) ($woman['data']['mock'] ?? []);
    $coffeeMock = (array) ($coffee['data']['mock'] ?? []);
    $requestIds = [
        (string) ($edited['clientRequestId'] ?? ''),
        (string) ($woman['clientRequestId'] ?? ''),
        (string) ($coffee['clientRequestId'] ?? ''),
    ];
    if (empty($woman['ok']) || empty($coffee['ok'])
        || (string) ($womanMock['path'] ?? '') !== '/v1/images/generations'
        || (string) ($coffeeMock['path'] ?? '') !== '/v1/images/generations'
        || !str_contains((string) ($womanMock['prompt'] ?? ''), '职业女性')
        || str_contains((string) ($womanMock['prompt'] ?? ''), '自行车')
        || !str_contains((string) ($coffeeMock['prompt'] ?? ''), '玻璃咖啡杯')
        || str_contains((string) ($coffeeMock['prompt'] ?? ''), '职业女性')
        || in_array('image', (array) ($womanMock['payload_keys'] ?? []), true)
        || in_array('image', (array) ($coffeeMock['payload_keys'] ?? []), true)
        || count(array_unique(array_filter($requestIds))) !== 3) {
        throw new RuntimeException('Sequential free-creation payloads reused a prior subject, reference, or request identity.');
    }
    echo "[ok] sequential bicycle-reference, professional-woman, and coffee-cup requests keep isolated prompts, paths, and identities\n";

    $internalModelId = (int) Db::name('ai_models')->insertGetId([
        'gateway' => 'sub2api',
        'model_id' => 'deepseek-v4-flash',
        'display_name' => 'DeepSeek V4 Flash',
        'provider' => 'deepseek',
        'protocol' => 'chat',
        'capabilities_json' => json_encode([
            AiCapability::SAFETY_TEXT,
            AiCapability::SAFETY_VISION,
            AiCapability::PRODUCT_VISION,
            AiCapability::CONTENT_PLANNER,
        ]),
        'input_modalities_json' => '["text"]',
        'output_modalities_json' => '["text"]',
        'limits_json' => '{"release_stage":"active"}',
        'adapter_config_json' => '{"capability_tests":{"safety_text":{"status":"ready"}}}',
        'catalog_payload_json' => null,
        'user_selectable' => 0,
        'enabled' => 1,
        'sort_order' => 1,
        'health_status' => 'ready',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $validated = $execution->assertRoutesReady([
        AiCapability::SAFETY_TEXT => ['primary' => $internalModelId],
        AiCapability::SAFETY_VISION => ['primary' => $internalModelId],
        AiCapability::PRODUCT_VISION => ['primary' => $internalModelId],
        AiCapability::CONTENT_PLANNER => ['primary' => $internalModelId],
    ], 'sub2api', []);
    if (($validated['mode'] ?? '') !== 'sub2api') {
        throw new RuntimeException('Internal capability routing was not validated against the Sub2API catalog.');
    }
    $internal = $execution->request(
        AiCapability::SAFETY_TEXT,
        $internalModelId,
        'POST',
        '/v1/chat/completions',
        ['messages' => [['role' => 'user', 'content' => '测试']]],
        30,
        [],
        ['call_key' => 'sub2api-test-safety']
    );
    $attempt = (array) (($internal['nativeAttempts'] ?? [])[0] ?? []);
    if (empty($internal['ok'])
        || empty($internal['sub2apiExecution'])
        || (string) ($attempt['routeRole'] ?? '') !== 'sub2api_primary'
        || array_key_exists('providerAccountId', $attempt)
        || array_key_exists('providerChannelId', $attempt)) {
        throw new RuntimeException(
            'Internal text and vision capabilities did not use the Sub2API shared gateway: '
            . json_encode($internal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
    echo "[ok] internal text and vision capabilities use the Sub2API shared gateway without ThinkPHP accounts\n";
} finally {
    Db::rollback();
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_terminate($process);
    proc_close($process);
    @unlink($log);
}

echo "Sub2API media execution tests completed successfully.\n";
