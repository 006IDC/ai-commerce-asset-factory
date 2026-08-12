<?php

declare(strict_types=1);

namespace app\service;

use Throwable;
use think\facade\Db;

final class NewApiClient
{
    private const SAFETY_VISION_TIMEOUT_SECONDS = 45;
    private const PRODUCT_VISION_TIMEOUT_SECONDS = 120;

    public function __construct(
        private readonly SystemSettings $settings,
        private readonly PromptTemplateService $prompts,
        private readonly ModelCapabilityRouter $router,
        private readonly AiModelCallRecorder $callRecorder,
        private readonly AiExecutionService $execution,
        private readonly AiMediaExecutionService $mediaExecution,
        private readonly Sub2ApiClient $sub2Api,
        private readonly AssetStorage $storage,
        private readonly StructuredOutputParser $structuredOutput
    )
    {
    }

    public function configuration(): array
    {
        $sub2ApiConfiguration = $this->mediaExecution->configuration();
        return [
            'configured' => !empty($sub2ApiConfiguration['configured']),
            'sub2ApiConfigured' => !empty($sub2ApiConfiguration['configured']),
            'sub2Api' => $sub2ApiConfiguration,
            'defaultModel' => $this->settings->get('default_image_model'),
            'visionModel' => $this->visionModel(),
            'imageEditMode' => $this->imageEditMode(),
            'imageEditEnabled' => $this->supportsImageEdit($this->settings->get('default_image_model')),
            'models' => $this->modelCatalog(),
        ];
    }

    public function defaultModel(): string
    {
        return $this->settings->get('default_image_model');
    }

    public function capabilityRoutingEnabledFor(?int $merchantId = null): bool
    {
        return $this->router->usesCapabilityRouting($merchantId);
    }

    public function visionModel(?int $merchantId = null): string
    {
        if ($this->router->usesCapabilityRouting($merchantId)) {
            try {
                return (string) $this->router->resolve(AiCapability::PRODUCT_VISION, null, $merchantId)['resolvedModel'];
            } catch (Throwable) {
                return '';
            }
        }
        return trim($this->settings->get('default_vision_model'));
    }

    public function contentPlannerModel(?int $merchantId = null): string
    {
        return $this->modelForCapability(AiCapability::CONTENT_PLANNER, $merchantId);
    }

    private function requestCapability(string $capability, array $payload, int $timeout = 120, ?string $administrativeModelOverride = null, array $context = []): array
    {
        $resolvedModelOverride = trim((string) ($context['resolved_model_override'] ?? ''));
        if ($resolvedModelOverride !== '') {
            $resolvedModelId = max(0, (int) ($context['resolved_model_id_override'] ?? 0)) ?: null;
            $fallbackModelId = max(0, (int) ($context['fallback_model_id_override'] ?? 0)) ?: null;
            $currentRoute = null;
            if ($resolvedModelId === null) {
                try {
                    $candidateRoute = $this->router->resolve(
                        $capability,
                        null,
                        isset($context['merchant_id']) ? (int) $context['merchant_id'] : null
                    );
                    if ((string) ($candidateRoute['resolvedModel'] ?? '') === $resolvedModelOverride) {
                        $currentRoute = $candidateRoute;
                        $resolvedModelId = max(0, (int) ($candidateRoute['resolvedModelId'] ?? 0)) ?: null;
                        if ($fallbackModelId === null) {
                            $fallbackModelId = max(0, (int) ($candidateRoute['fallbackModelId'] ?? 0)) ?: null;
                        }
                    }
                } catch (Throwable) {
                    $currentRoute = null;
                }
            }
            $resolvedGatewayOverride = trim((string) ($context['resolved_gateway_override'] ?? ''));
            $fallbackModelOverride = trim((string) ($context['fallback_model_override'] ?? ''));
            $fallbackGatewayOverride = trim((string) ($context['fallback_gateway_override'] ?? ''));
            $resolution = [
                'mode' => 'capability',
                'resolvedModel' => $resolvedModelOverride,
                'resolvedModelId' => $resolvedModelId,
                'resolvedGateway' => $resolvedGatewayOverride
                    ?: trim((string) ($currentRoute['resolvedGateway'] ?? ''))
                    ?: $this->gatewayForModel($resolvedModelId, $resolvedModelOverride),
                'fallbackModel' => $fallbackModelOverride
                    ?: trim((string) ($currentRoute['fallbackModel'] ?? '')),
                'fallbackModelId' => $fallbackModelId,
                'fallbackGateway' => $fallbackGatewayOverride
                    ?: trim((string) ($currentRoute['fallbackGateway'] ?? ''))
                    ?: $this->gatewayForModel(
                        $fallbackModelId,
                        $fallbackModelOverride ?: trim((string) ($currentRoute['fallbackModel'] ?? ''))
                    ),
            ];
        } elseif (trim((string) $administrativeModelOverride) !== '') {
            try {
                $resolution = $this->router->resolveAdministrativeModel(
                    $capability,
                    trim((string) $administrativeModelOverride)
                );
            } catch (Throwable $exception) {
                return ['ok' => false, 'message' => $exception->getMessage(), 'status' => 0];
            }
        } else {
            try {
                $resolution = $this->router->resolve(
                    $capability,
                    null,
                    isset($context['merchant_id']) ? (int) $context['merchant_id'] : null
                );
            } catch (Throwable $exception) {
                return ['ok' => false, 'message' => $exception->getMessage(), 'status' => 0];
            }
        }
        if (AiCapability::allowsUserSelection($capability)) {
            $resolution['fallbackModel'] = '';
            $resolution['fallbackModelId'] = null;
            $resolution['fallbackGateway'] = '';
        }
        $payload['model'] = $resolution['resolvedModel'];
        $successStatus = in_array((string) ($context['success_status'] ?? ''), ['success', 'fallback', 'review'], true)
            ? (string) $context['success_status']
            : 'success';
        $routeStartedAt = microtime(true);
        $result = $this->executeCapabilityTransport(
            $capability,
            $this->resolvedModelId($resolution, (string) $payload['model']),
            (string) ($resolution['resolvedGateway'] ?? 'sub2api'),
            $payload,
            $timeout,
            $successStatus,
            $context,
            'primary'
        );
        if (!$result['ok']
            && empty($context['disable_fallback'])
            && !AiCapability::allowsUserSelection($capability)
            && trim((string) ($resolution['fallbackModel'] ?? '')) !== '') {
            $fallbackTimeout = $timeout - (int) ceil(microtime(true) - $routeStartedAt);
            if ($fallbackTimeout < 10) {
                $result['message'] = (string) ($result['message'] ?? '主模型调用失败')
                    . '；备用模型未调用：本次能力调用已达总等待上限。';
                $result['routingRouteBudgetExhausted'] = true;
                return $result;
            }
            $payload['model'] = $resolution['fallbackModel'];
            $fallbackResult = $this->executeCapabilityTransport(
                $capability,
                isset($resolution['fallbackModelId']) ? (int) $resolution['fallbackModelId'] : null,
                (string) ($resolution['fallbackGateway'] ?? 'sub2api'),
                $payload,
                $fallbackTimeout,
                'fallback',
                $context,
                'fallback'
            );
            if ($fallbackResult['ok']) {
                $fallbackResult['model'] = $payload['model'];
                $fallbackResult['routingFallbackUsed'] = true;
                $fallbackResult['routingPrimaryError'] = $result['message'] ?? '';
                return $fallbackResult;
            }
            $result['message'] = (string) ($result['message'] ?? '主模型调用失败') . '；备用模型调用失败：' . (string) ($fallbackResult['message'] ?? '未知错误');
            $result['routingFallbackAttempted'] = true;
            $result['routingFallbackStatus'] = max(0, (int) ($fallbackResult['status'] ?? 0));
            $result['routingFallbackDispatchAmbiguous'] = !empty($fallbackResult['dispatchAmbiguous']);
        }
        if ($result['ok']) {
            $result['model'] = $payload['model'];
        }
        return $result;
    }

    private function executeCapabilityTransport(
        string $capability,
        ?int $modelId,
        string $gateway,
        array $payload,
        int $timeout,
        string $successStatus,
        array $context,
        string $routeRole,
        bool $deferSuccessfulRecord = false
    ): array {
        $native = $this->execution->request(
            $capability,
            $modelId,
            'POST',
            '/v1/chat/completions',
            $payload,
            $timeout,
            [],
            $context
        );
        if (!empty($native['handled'])) {
            $deferred = null;
            foreach ((array) ($native['nativeAttempts'] ?? []) as $attempt) {
                $attemptResult = is_array($attempt['result'] ?? null) ? $attempt['result'] : [];
                $attemptOk = !empty($attemptResult['ok']);
                $recordContext = array_merge($context, [
                    'payload' => $payload,
                    'attempt_no' => (int) ($attempt['attemptNo'] ?? 1),
                    'route_role' => (string) ($attempt['routeRole'] ?? $this->nativeRouteRole($routeRole)),
                    'protocol' => (string) ($attempt['protocol'] ?? 'chat'),
                    'started_at' => (string) ($attempt['startedAt'] ?? ''),
                    'finished_at' => (string) ($attempt['finishedAt'] ?? ''),
                ]);
                if ($attemptOk && $deferSuccessfulRecord) {
                    $deferred = [
                        'capability' => $capability,
                        'modelId' => $modelId,
                        'durationMs' => max(0, (int) ($attempt['durationMs'] ?? 0)),
                        'status' => $successStatus,
                        'context' => $recordContext,
                    ];
                    continue;
                }
                $this->callRecorder->record(
                    $capability,
                    $modelId,
                    $attemptResult,
                    max(0, (int) ($attempt['durationMs'] ?? 0)),
                    $attemptOk ? $successStatus : 'failed',
                    $recordContext
                );
            }
            unset($native['nativeAttempts']);
            if (!empty($native['ok'])) {
                if ($deferred !== null) {
                    $native['_deferred_call'] = $deferred;
                }
                return $native;
            }
            return $native;
        }

        return [
            'ok' => false,
            'message' => '当前能力未进入 Sub2API 共享网关，任务未发送。',
            'status' => 503,
            'retryAfter' => 5,
        ];
    }

    private function recordDeferredTransport(array $result, bool $valid, string $validationError = ''): void
    {
        $deferred = is_array($result['_deferred_call'] ?? null) ? $result['_deferred_call'] : null;
        if ($deferred === null) {
            return;
        }
        unset($result['_deferred_call']);
        if (!$valid) {
            $result['ok'] = false;
            $result['message'] = $validationError !== '' ? $validationError : '模型返回结果无法识别。';
        }
        $this->callRecorder->record(
            (string) ($deferred['capability'] ?? ''),
            isset($deferred['modelId']) ? (int) $deferred['modelId'] : null,
            $result,
            max(0, (int) ($deferred['durationMs'] ?? 0)),
            $valid ? (string) ($deferred['status'] ?? 'success') : 'failed',
            is_array($deferred['context'] ?? null) ? $deferred['context'] : []
        );
    }

    private function nativeRouteRole(string $routeRole): string
    {
        return $routeRole === 'fallback' ? 'native_model_fallback' : 'native_primary';
    }

    private function resolvedModelId(array $resolution, string $model): ?int
    {
        if ($model === (string) ($resolution['resolvedModel'] ?? '') && (int) ($resolution['resolvedModelId'] ?? 0) > 0) {
            return (int) $resolution['resolvedModelId'];
        }
        if ($model === (string) ($resolution['fallbackModel'] ?? '') && (int) ($resolution['fallbackModelId'] ?? 0) > 0) {
            return (int) $resolution['fallbackModelId'];
        }
        return null;
    }

    private function gatewayForModel(?int $modelId, string $model): string
    {
        if ($modelId !== null && $modelId > 0) {
            $gateway = trim((string) Db::name('ai_models')->where('id', $modelId)->value('gateway'));
            if ($gateway !== '') {
                return $gateway;
            }
        }

        return 'sub2api';
    }

    private function modelResolution(int $modelId, string $model): array
    {
        return [
            'resolvedModelId' => $modelId > 0 ? $modelId : null,
            'resolvedModel' => trim($model),
            'resolvedGateway' => $this->gatewayForModel($modelId > 0 ? $modelId : null, $model),
        ];
    }

    private function modelForCapability(string $capability, ?int $merchantId = null): string
    {
        try {
            return (string) $this->router->resolve($capability, null, $merchantId)['resolvedModel'];
        } catch (Throwable) {
            return '';
        }
    }

    public function testModelCapability(int $modelId, string $model, string $capability): array
    {
        if (!AiCapability::exists($capability)) {
            return ['ok' => false, 'message' => '未知的模型能力。'];
        }
        $model = trim($model);
        if ($model === '') {
            return ['ok' => false, 'message' => '模型 ID 不能为空。'];
        }

        $resolution = $this->modelResolution($modelId, $model);
        $startedAt = microtime(true);
        $result = match ($capability) {
            AiCapability::SAFETY_TEXT => $this->testSafetyCapability($modelId, $model, false, $resolution),
            AiCapability::SAFETY_VISION => $this->testSafetyCapability($modelId, $model, true, $resolution),
            AiCapability::PRODUCT_VISION => $this->testProductVisionCapability($modelId, $model, $resolution),
            AiCapability::CONTENT_PLANNER => $this->testContentPlannerCapability($modelId, $model, $resolution),
            AiCapability::IMAGE_GENERATION => $this->testImageGenerationCapability($modelId, $model, $resolution),
            AiCapability::IMAGE_EDIT => $this->testImageEditCapability($modelId, $model, $resolution),
            AiCapability::VIDEO_IMAGE_TO_VIDEO => $this->testVideoCapability($modelId, $model, true, $resolution),
            AiCapability::VIDEO_TEXT_TO_VIDEO => $this->testVideoCapability($modelId, $model, false, $resolution),
            default => ['ok' => false, 'message' => '该能力暂未提供自动测试，请在接入对应协议适配器后测试。'],
        };
        $result['durationMs'] = (int) round((microtime(true) - $startedAt) * 1000);
        return $result;
    }

    public function refreshVideoCapabilityTest(
        int $modelId,
        string $model,
        string $capability,
        string $taskId
    ): array {
        if (!in_array($capability, [AiCapability::VIDEO_IMAGE_TO_VIDEO, AiCapability::VIDEO_TEXT_TO_VIDEO], true)) {
            return ['ok' => false, 'pending' => false, 'message' => '该测试不是视频输出测试。'];
        }
        $resolution = $this->modelResolution($modelId, $model);
        $startedAt = microtime(true);
        $result = $this->videoStatus(
            $taskId,
            (string) ($resolution['resolvedGateway'] ?? ''),
            $modelId,
            $model,
            $capability,
            [
                'call_key' => 'capability-test-video-status:' . $modelId . ':' . $taskId,
                'require_sub2api' => true,
            ]
        );
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        if (empty($result['ok'])) {
            return [
                'ok' => false,
                'pending' => true,
                'message' => '暂时无法读取同一视频测试任务，请稍后刷新；不要重新提交付费测试。',
                'requestId' => $taskId,
                'durationMs' => $durationMs,
                'outputSummary' => [
                    'type' => 'video_task',
                    'task_id' => $taskId,
                    'status' => '状态读取暂时失败',
                ],
            ];
        }
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $status = strtolower(trim((string) ($data['status'] ?? '')));
        if (in_array($status, ['failed', 'error', 'cancelled', 'canceled', 'expired'], true)) {
            return [
                'ok' => false,
                'pending' => false,
                'message' => trim((string) ($data['error']['message'] ?? $data['message'] ?? '视频真实输出测试未通过。')),
                'requestId' => $taskId,
                'durationMs' => $durationMs,
            ];
        }
        if (!in_array($status, ['succeeded', 'completed', 'success'], true)) {
            return [
                'ok' => false,
                'pending' => true,
                'message' => '视频仍在同一上游任务中生成，请稍后刷新；不会创建新的付费请求。',
                'requestId' => $taskId,
                'durationMs' => $durationMs,
                'outputSummary' => [
                    'type' => 'video_task',
                    'task_id' => $taskId,
                    'status' => '生成中' . (isset($data['progress']) ? ' · ' . max(0, min(100, (int) $data['progress'])) . '%' : ''),
                ],
            ];
        }
        $videoUrl = trim((string) (
            $data['video']['url']
            ?? $data['url']
            ?? $data['video_url']
            ?? $data['output']['video_url']
            ?? $data['content']['video_url']
            ?? ''
        ));
        $artifact = $this->storeModelTestVideo($videoUrl, $model);
        if ($artifact === null) {
            return [
                'ok' => false,
                'pending' => false,
                'message' => '视频已生成，但测试产物无法安全保存和播放。',
                'requestId' => $taskId,
                'durationMs' => $durationMs,
            ];
        }
        return [
            'ok' => true,
            'pending' => false,
            'message' => '视频真实输出测试通过，已保存可播放样例。',
            'requestId' => $taskId,
            'durationMs' => $durationMs,
            'artifactUrl' => $artifact['url'],
            'outputSummary' => $artifact,
        ];
    }

    private function testSafetyCapability(int $modelId, string $model, bool $withImage, array $resolution): array
    {
        $capability = $withImage ? AiCapability::SAFETY_VISION : AiCapability::SAFETY_TEXT;
        $image = $withImage ? $this->capabilityTestImage() : null;
        $safe = $this->classifyContentSafety(
            '普通家居水杯，简洁实用的电商商品介绍。',
            $image,
            $model,
            '测试调用失败。',
            $capability,
            $modelId,
            'success',
            [],
            (string) ($resolution['resolvedGateway'] ?? 'sub2api')
        );
        if (!$safe['ok'] || !empty($safe['flagged'])) {
            return ['ok' => false, 'message' => !$safe['ok'] ? (string) $safe['reason'] : '安全模型把正常电商内容误判为违规。', 'requestId' => $safe['requestId'] ?? null];
        }

        $unsafe = $this->classifyContentSafety(
            '固定违规测试样本：提供血腥暴力内容和武器交易教程。',
            $image,
            $model,
            '测试调用失败。',
            $capability,
            $modelId,
            'success',
            [],
            (string) ($resolution['resolvedGateway'] ?? 'sub2api')
        );
        if (!$unsafe['ok'] || empty($unsafe['flagged'])) {
            return ['ok' => false, 'message' => !$unsafe['ok'] ? (string) $unsafe['reason'] : '安全模型没有拦截固定违规样本。', 'requestId' => $unsafe['requestId'] ?? null];
        }

        return ['ok' => true, 'message' => '正常样本和违规样本均通过校验。', 'requestId' => $unsafe['requestId'] ?? null];
    }

    private function testProductVisionCapability(int $modelId, string $model, array $resolution): array
    {
        $image = $this->capabilityTestImage();
        $instruction = $this->prompts->buildDetailReportPrompt([
            '用户初步想法' => '固定商品图完整报告能力校验。',
            'SKU 已有商品资料' => [
                'product_name' => '蓝色便携杯',
                'category' => '杯具',
                'selling_points' => ['便携', '简约外观'],
                'uncertain_points' => ['容量与材质待确认'],
            ],
            '参考图片数量' => 1,
            '十屏键名' => array_keys(detail_page_screen_options()),
        ], 'taobao', 'zh-CN');
        $payload = [
            'model' => $model,
            'temperature' => 0.25,
            'max_tokens' => 5000,
            'response_format' => ['type' => 'json_object'],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $instruction],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $image['mime'] . ';base64,' . base64_encode($image['content'])]],
                ],
            ]],
        ];
        $result = $this->executeCapabilityTransport(
            AiCapability::PRODUCT_VISION,
            $modelId,
            (string) ($resolution['resolvedGateway'] ?? 'sub2api'),
            $payload,
            180,
            'success',
            [],
            'primary',
            true
        );
        $report = $result['ok']
            ? $this->decodeDetailReport($this->chatResponseText((array) ($result['data'] ?? [])))
            : null;
        $valid = is_array($report)
            && trim((string) ($report['product_name'] ?? '')) !== ''
            && trim((string) ($report['category'] ?? '')) !== ''
            && (array) ($report['selling_points'] ?? []) !== []
            && count((array) ($report['screen_plan'] ?? [])) === count(detail_page_screen_options());
        if ($result['ok'] && !$valid) {
            $result['ok'] = false;
            $result['message'] = '商品识别模型没有返回生产链路可解析的完整商品报告和十屏方案。';
        }
        $this->recordDeferredTransport(
            $result,
            (bool) $result['ok'],
            '商品识别模型没有返回生产链路可解析的完整商品报告和十屏方案。'
        );
        return [
            'ok' => (bool) $result['ok'],
            'message' => (string) ($result['message'] ?? ''),
            'requestId' => $result['requestId'] ?? null,
            'outputSummary' => $valid ? [
                'type' => 'text',
                'text' => mb_strimwidth(
                    (string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                    0,
                    4000,
                    ''
                ),
            ] : [],
        ];
    }

    private function testContentPlannerCapability(int $modelId, string $model, array $resolution): array
    {
        $instruction = $this->prompts->buildDetailReportPrompt([
            'SKU 通用商品报告' => [
                'product_name' => '简约测试水杯',
                'category' => '杯具',
                'materials' => '不锈钢杯盖，杯身材质待确认',
                'selling_points' => ['便携', '简约外观'],
                'uncertain_points' => ['容量待确认'],
            ],
            '用户本次创作想法' => '面向通勤人群，保持事实谨慎。',
            '任务' => '沿用 SKU 商品资料，只针对目标平台和语言生成十张详情图的标题、正文和自然创作方向。',
            '十屏键名' => array_keys(detail_page_screen_options()),
        ], 'taobao', 'zh-CN');
        $payload = [
            'model' => $model,
            'temperature' => 0,
            'max_tokens' => 5000,
            'messages' => [['role' => 'user', 'content' => $instruction]],
        ];
        $result = $this->executeCapabilityTransport(
            AiCapability::CONTENT_PLANNER,
            $modelId,
            (string) ($resolution['resolvedGateway'] ?? 'sub2api'),
            $payload,
            180,
            'success',
            [],
            'primary',
            true
        );
        $report = $result['ok'] ? $this->decodeDetailReport($this->chatResponseText((array) ($result['data'] ?? []))) : null;
        if ($result['ok'] && $report === null) {
            $result['ok'] = false;
            $result['message'] = '策划模型没有返回符合详情方案结构的 JSON。';
        }
        $this->recordDeferredTransport(
            $result,
            (bool) $result['ok'],
            '策划模型没有返回符合详情方案结构的 JSON。'
        );
        return [
            'ok' => (bool) $result['ok'],
            'message' => (string) ($result['message'] ?? ''),
            'requestId' => $result['requestId'] ?? null,
            'outputSummary' => $report !== null ? [
                'type' => 'text',
                'text' => mb_strimwidth(
                    (string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                    0,
                    4000,
                    ''
                ),
            ] : [],
        ];
    }

    private function testImageGenerationCapability(int $modelId, string $model, array $resolution): array
    {
        $payload = [
            'model' => $model,
            'prompt' => '能力测试：生成一张无文字、无品牌的简洁蓝色几何静物图。',
            'n' => 1,
            'size' => '1024x1024',
            'response_format' => 'b64_json',
        ];
        $startedAt = microtime(true);
        $result = $this->generateImage(
            $model,
            (string) $payload['prompt'],
            (string) $payload['size'],
            $modelId,
            (string) ($resolution['resolvedGateway'] ?? ''),
            [
                'call_key' => 'capability-test:image-generation:' . $modelId,
                'require_sub2api' => true,
            ]
        );
        if ($result['ok'] && !$this->hasImageResult((array) ($result['data'] ?? []))) {
            $result['ok'] = false;
            $result['message'] = '图片模型未返回可识别的图片结果。';
        }
        $this->callRecorder->record(AiCapability::IMAGE_GENERATION, $modelId, $result, (int) round((microtime(true) - $startedAt) * 1000), $result['ok'] ? 'success' : 'failed', ['payload' => $payload]);
        $artifact = $result['ok'] ? $this->storeModelTestImage((array) ($result['data'] ?? []), $model, 'generate') : null;
        return [
            'ok' => (bool) $result['ok'],
            'message' => (string) ($result['message'] ?? ''),
            'requestId' => $result['requestId'] ?? null,
            'artifactUrl' => $artifact['url'] ?? null,
            'outputSummary' => $artifact ?? [],
        ];
    }

    private function testImageEditCapability(int $modelId, string $model, array $resolution): array
    {
        $image = $this->capabilityTestImage();
        $startedAt = microtime(true);
        $result = $this->editImage(
            $model,
            '能力测试：保持杯子轮廓不变，仅将背景改为浅灰色，不添加文字。',
            $image,
            null,
            '1024x1024',
            $modelId,
            (string) ($resolution['resolvedGateway'] ?? ''),
            [
                'call_key' => 'capability-test:image-edit:' . $modelId,
                'require_sub2api' => true,
            ]
        );
        if ($result['ok'] && !$this->hasImageResult((array) ($result['data'] ?? []))) {
            $result['ok'] = false;
            $result['message'] = '图片编辑模型未返回可识别的图片结果。';
        }
        $this->callRecorder->record(AiCapability::IMAGE_EDIT, $modelId, $result, (int) round((microtime(true) - $startedAt) * 1000), $result['ok'] ? 'success' : 'failed', ['payload' => ['prompt' => 'capability-test-image-edit']]);
        $artifact = $result['ok'] ? $this->storeModelTestImage((array) ($result['data'] ?? []), $model, 'edit') : null;
        return [
            'ok' => (bool) $result['ok'],
            'message' => (string) ($result['message'] ?? ''),
            'requestId' => $result['requestId'] ?? null,
            'artifactUrl' => $artifact['url'] ?? null,
            'outputSummary' => $artifact ?? [],
        ];
    }

    private function testVideoCapability(int $modelId, string $model, bool $withImage, array $resolution): array
    {
        $capability = $withImage ? AiCapability::VIDEO_IMAGE_TO_VIDEO : AiCapability::VIDEO_TEXT_TO_VIDEO;
        $images = [];
        if ($withImage) {
            $image = $this->capabilityTestImage();
            $images[] = 'data:' . $image['mime'] . ';base64,' . base64_encode($image['content']);
        }
        $startedAt = microtime(true);
        $result = $this->createVideo(
            $model,
            '能力测试：商品保持外观不变，镜头缓慢推进，不添加文字。',
            $images,
            '1:1',
            '720p',
            5,
            false,
            'capability-test-' . $modelId . '-' . date('YmdHis'),
            $modelId,
            (string) ($resolution['resolvedGateway'] ?? ''),
            [
                'call_key' => 'capability-test:' . $capability . ':' . $modelId,
                'require_sub2api' => true,
            ]
        );
        $taskId = trim((string) ($result['data']['id'] ?? $result['data']['request_id'] ?? ''));
        if ($result['ok'] && $taskId === '') {
            $result['ok'] = false;
            $result['message'] = '视频模型已响应，但没有返回可轮询的任务 ID。';
        }
        $this->callRecorder->record(
            $capability,
            $modelId,
            $result,
            (int) round((microtime(true) - $startedAt) * 1000),
            $result['ok'] ? 'success' : 'failed',
            ['payload' => ['prompt' => 'capability-test-video', 'reference_count' => count($images)]]
        );
        return [
            'ok' => (bool) $result['ok'],
            'pending' => (bool) $result['ok'],
            'message' => $result['ok']
                ? '视频真实输出测试已提交。请稍后刷新同一测试任务；不要重复提交付费测试。'
                : (string) ($result['message'] ?? ''),
            'requestId' => $taskId !== '' ? $taskId : ($result['requestId'] ?? null),
            'outputSummary' => $taskId !== '' ? [
                'type' => 'video_task',
                'task_id' => $taskId,
                'status' => '已提交，等待上游生成；请刷新同一任务',
            ] : [],
        ];
    }

    private function storeModelTestImage(array $data, string $model, string $purpose): ?array
    {
        $encoded = trim((string) ($data['data'][0]['b64_json'] ?? ''));
        if ($encoded === '') {
            return null;
        }
        $content = base64_decode($encoded, true);
        if (!is_string($content) || $content === '' || strlen($content) > 30 * 1024 * 1024) {
            return null;
        }
        $imageInfo = getimagesizefromstring($content);
        $mime = strtolower((string) ($imageInfo['mime'] ?? ''));
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/png' => 'png',
            default => null,
        };
        if ($extension === null) {
            return null;
        }
        $directory = dirname(__DIR__, 2) . '/public/storage/model-tests';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return null;
        }
        $safeModel = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $model), '-');
        $filename = ($safeModel !== '' ? $safeModel : 'model')
            . '-' . $purpose
            . '-' . date('Ymd-His')
            . '-' . bin2hex(random_bytes(4))
            . '.' . $extension;
        $path = $directory . '/' . $filename;
        if (file_put_contents($path, $content, LOCK_EX) !== strlen($content)) {
            return null;
        }
        @chmod($path, 0644);
        return [
            'type' => 'image',
            'url' => '/storage/model-tests/' . $filename,
            'mime' => $mime,
            'width' => max(0, (int) ($imageInfo[0] ?? 0)),
            'height' => max(0, (int) ($imageInfo[1] ?? 0)),
            'bytes' => strlen($content),
            'sha256' => hash('sha256', $content),
        ];
    }

    private function storeModelTestVideo(string $url, string $model): ?array
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return null;
        }
        try {
            $download = $this->storage->downloadVideo($url);
        } catch (Throwable) {
            return null;
        }
        $content = (string) ($download['content'] ?? '');
        $contentType = strtolower(trim((string) ($download['mime'] ?? '')));
        $extension = match ($contentType) {
            'video/mp4', 'application/mp4' => 'mp4',
            'video/webm' => 'webm',
            default => null,
        };
        if ($extension === null || $content === '') {
            return null;
        }
        $directory = dirname(__DIR__, 2) . '/public/storage/model-tests';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return null;
        }
        $safeModel = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $model), '-');
        $filename = ($safeModel !== '' ? $safeModel : 'model')
            . '-video-' . date('Ymd-His')
            . '-' . bin2hex(random_bytes(4))
            . '.' . $extension;
        $path = $directory . '/' . $filename;
        if (file_put_contents($path, $content, LOCK_EX) !== strlen($content)) {
            return null;
        }
        @chmod($path, 0644);
        return [
            'type' => 'video',
            'url' => '/storage/model-tests/' . $filename,
            'mime' => $contentType,
            'bytes' => strlen($content),
            'sha256' => hash('sha256', $content),
        ];
    }

    private function decodeJsonObject(string $content): ?array
    {
        $parsed = $this->structuredOutput->parse($content);
        return !empty($parsed['ok']) ? (array) $parsed['data'] : null;
    }

    private function normalizeProductVisionResult(array $product): array
    {
        $aliases = [
            'name' => ['product_name', 'productName', 'title'],
            'category' => ['product_category', 'productCategory'],
            'search_terms' => ['searchTerms', 'keywords', 'search_keywords'],
            'selling_points' => ['sellingPoints', 'key_selling_points', 'highlights'],
            'product_description' => ['productDescription', 'description', 'summary'],
            'foundation' => ['product_foundation', 'productFoundation'],
        ];
        foreach ($aliases as $canonical => $candidates) {
            if (array_key_exists($canonical, $product)) {
                continue;
            }
            foreach ($candidates as $candidate) {
                if (array_key_exists($candidate, $product)) {
                    $product[$canonical] = $product[$candidate];
                    break;
                }
            }
        }
        foreach (['search_terms', 'selling_points'] as $field) {
            $value = $product[$field] ?? [];
            if (is_string($value)) {
                $value = preg_split('/[\r\n,，;；]+/u', $value) ?: [];
            } elseif (!is_array($value)) {
                $value = [];
            }
            $product[$field] = array_values(array_filter(
                array_map(static fn (mixed $item): string => trim((string) $item), $value),
                static fn (string $item): bool => $item !== ''
            ));
        }

        $foundation = is_array($product['foundation'] ?? null) ? $product['foundation'] : [];
        foreach (['brand_notes'] as $field) {
            if (!array_key_exists($field, $foundation) && array_key_exists($field, $product)) {
                $foundation[$field] = $product[$field];
            }
        }
        foreach (['primary_colors', 'visible_components', 'use_scenarios', 'core_functions', 'uncertain_points', 'prohibited_claims'] as $field) {
            $value = $foundation[$field] ?? [];
            if (is_string($value)) {
                $value = preg_split('/[\r\n,，、；;]+/u', $value) ?: [];
            }
            $foundation[$field] = is_array($value)
                ? array_values(array_filter(array_map(static fn (mixed $item): string => trim((string) $item), $value)))
                : [];
        }
        $product['foundation'] = $foundation;

        return $product;
    }

    private function decodeProductVisionResponse(array $result): ?array
    {
        $parsed = $this->parseProductVisionResponse($result);
        return !empty($parsed['ok']) ? (array) $parsed['data'] : null;
    }

    private function parseProductVisionResponse(array $result): array
    {
        $content = $result['data']['choices'][0]['message']['content']
            ?? $this->chatResponseText((array) ($result['data'] ?? []));
        $parsed = $this->structuredOutput->parse($content, [
            'name' => ['product_name', 'productName', 'title'],
            'category' => ['product_category', 'productCategory'],
            'search_terms' => ['searchTerms', 'keywords', 'search_keywords'],
            'selling_points' => ['sellingPoints', 'key_selling_points', 'highlights'],
            'product_description' => ['productDescription', 'description', 'summary'],
            'foundation' => ['product_foundation', 'productFoundation'],
        ]);
        if (!empty($parsed['ok'])) {
            $parsed['data'] = $this->normalizeProductVisionResult((array) $parsed['data']);
        }
        return $parsed;
    }

    private function productVisionPayload(array $product): ?array
    {
        $name = trim((string) ($product['name'] ?? ''));
        $category = trim((string) ($product['category'] ?? ''));
        $foundation = is_array($product['foundation'] ?? null) ? $product['foundation'] : [];
        if ($name === '' || $category === '') {
            return null;
        }
        $sellingPoints = array_values(array_filter((array) ($product['selling_points'] ?? $product['sellingPoints'] ?? [])));
        $productDescription = trim((string) ($product['product_description'] ?? $product['productDescription'] ?? ''));
        if ($productDescription === '') {
            $parts = array_values(array_filter([
                trim((string) ($foundation['appearance'] ?? '')),
                implode('；', array_slice($sellingPoints, 0, 5)),
            ]));
            $productDescription = $name . '，类目为' . $category . ($parts === [] ? '。' : '。' . implode('；', $parts) . '。');
        }
        $searchTerms = array_values(array_unique(array_filter(array_merge([$name, $category], array_slice($sellingPoints, 0, 3)))));

        return [
            'name' => mb_strimwidth($name, 0, 160, ''),
            'category' => mb_strimwidth($category, 0, 100, ''),
            'searchTerms' => array_slice($searchTerms !== [] ? $searchTerms : [$name, $category], 0, 5),
            'sellingPoints' => array_slice($sellingPoints, 0, 8),
            'productDescription' => mb_strimwidth($productDescription, 0, 4000, ''),
            'foundation' => $foundation,
        ];
    }

    /** Merchant-entered product identity is authoritative over model embellishment. */
    private function applyMerchantVisionIdentity(array $product, array $context): array
    {
        $merchantName = trim((string) ($context['name'] ?? $context['product_name'] ?? ''));
        $merchantCategory = trim((string) ($context['category'] ?? ''));
        if ($merchantName !== '') {
            $product['name'] = mb_strimwidth($merchantName, 0, 160, '');
            $product['search_terms'] = [];
        }
        if ($merchantCategory !== '') {
            $product['category'] = mb_strimwidth($merchantCategory, 0, 100, '');
        }
        return $product;
    }

    private function hasImageResult(array $data): bool
    {
        $item = $data['data'][0] ?? null;
        return is_array($item) && (trim((string) ($item['b64_json'] ?? '')) !== '' || trim((string) ($item['url'] ?? '')) !== '');
    }

    private function capabilityTestImage(): array
    {
        $content = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAIAAAAmkwkpAAAAFElEQVR4nGP4z8DAwMDAxAADCBYAOvoCBYw9H9kAAAAASUVORK5CYII=', true);
        if (function_exists('imagecreatetruecolor')) {
            $canvas = imagecreatetruecolor(320, 320);
            $background = imagecolorallocate($canvas, 238, 243, 247);
            $blue = imagecolorallocate($canvas, 28, 118, 164);
            $dark = imagecolorallocate($canvas, 35, 48, 62);
            imagefill($canvas, 0, 0, $background);
            imagefilledrectangle($canvas, 105, 72, 215, 256, $blue);
            imagefilledrectangle($canvas, 118, 55, 202, 78, $dark);
            imagefilledellipse($canvas, 160, 72, 110, 34, $blue);
            imagefilledellipse($canvas, 160, 256, 110, 30, $blue);
            ob_start();
            imagepng($canvas, null, 6);
            $generated = ob_get_clean();
            if (is_string($generated) && $generated !== '') {
                $content = $generated;
            }
        }
        return ['mime' => 'image/png', 'filename' => 'capability-test-product.png', 'content' => (string) $content];
    }

    public function analyzeProductImage(array $image, array $context = []): array
    {
        $merchantId = isset($context['merchantId']) ? (int) $context['merchantId'] : null;
        $model = trim((string) ($context['resolvedModel'] ?? ''));
        if ($model === '') {
            $model = $this->modelForCapability(AiCapability::PRODUCT_VISION, $merchantId);
        }
        if ($model === '') {
            return ['ok' => false, 'message' => '管理员尚未配置商品识别模型。'];
        }
        $dataUrl = 'data:' . $image['mime'] . ';base64,' . base64_encode($image['content']);
        $additionalImages = array_values(array_filter(
            (array) ($context['additionalImages'] ?? []),
            static fn (mixed $candidate): bool => is_array($candidate)
                && !empty($candidate['mime']) && !empty($candidate['content'])
        ));
        $reference = [
            '已有商品名称' => mb_strimwidth(trim((string) ($context['name'] ?? '')), 0, 160, ''),
            '已有商品类目' => mb_strimwidth(trim((string) ($context['category'] ?? '')), 0, 100, ''),
            '已有商品卖点' => mb_strimwidth(trim((string) ($context['sellingPoints'] ?? '')), 0, 2000, ''),
            '已有商品详情' => mb_strimwidth(trim((string) ($context['productDescription'] ?? '')), 0, 4000, ''),
        ];
        $reference = array_filter($reference, static fn (mixed $value): bool => $value !== '' && $value !== []);
        $instruction = $this->prompts->buildAnalysisPrompt($reference);
        $content = [
            ['type' => 'text', 'text' => $instruction],
            ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
        ];
        foreach (array_slice($additionalImages, 0, 5) as $additionalImage) {
            $content[] = ['type' => 'image_url', 'image_url' => [
                'url' => 'data:' . $additionalImage['mime'] . ';base64,' . base64_encode($additionalImage['content']),
            ]];
        }
        $result = $this->requestCapability(AiCapability::PRODUCT_VISION, [
            'temperature' => 0.35,
            'max_tokens' => 1600,
            'response_format' => ['type' => 'json_object'],
            'messages' => [[
                'role' => 'user',
                'content' => $content,
            ]],
        ], 90, null, [
            'merchant_id' => $merchantId,
            'resolved_model_override' => $model,
            'resolved_model_id_override' => max(0, (int) ($context['resolvedModelId'] ?? 0)),
            'resolved_gateway_override' => trim((string) ($context['resolvedGateway'] ?? '')),
            'fallback_model_override' => trim((string) ($context['fallbackModel'] ?? '')),
            'fallback_model_id_override' => max(0, (int) ($context['fallbackModelId'] ?? 0)),
            'fallback_gateway_override' => trim((string) ($context['fallbackGateway'] ?? '')),
            'call_key' => trim((string) ($context['callKey'] ?? '')),
            'disable_fallback' => true,
        ], ['data', 'result']);
        if (!$result['ok']) {
            $diagnostic = $this->transportDiagnostic($result, $model, 'product_vision');
            return array_merge($result, [
                'errorCode' => 'product_vision_transport_failed',
                'stage' => 'product_vision',
                'retryable' => (bool) $diagnostic['retryable'],
                'diagnostic' => $diagnostic,
            ]);
        }
        $parseStarted = microtime(true);
        $parsed = $this->parseProductVisionResponse($result);
        $parseMs = (int) round((microtime(true) - $parseStarted) * 1000);
        $repairAttempted = false;
        $repairModel = null;
        $repairMs = 0;
        if (empty($parsed['ok'])) {
            $repairAttempted = true;
            $repairStarted = microtime(true);
            $repair = $this->repairStructuredOutput(
                AiCapability::PRODUCT_VISION,
                (string) ($parsed['rawText'] ?? ''),
                'product_vision',
                $model,
                $context,
                '{"name":"","category":"","selling_points":[],"search_terms":[],"product_description":"","foundation":{"materials":"","appearance":"","primary_colors":[],"visible_components":[],"target_audience":"","use_scenarios":[],"core_functions":[],"uncertain_points":[],"prohibited_claims":[],"brand_notes":""}}'
            );
            $repairMs = (int) round((microtime(true) - $repairStarted) * 1000);
            $repairModel = $repair['model'] ?? null;
            if (!empty($repair['ok'])) {
                $repairParseStarted = microtime(true);
                $parsed = $this->parseProductVisionResponse($repair);
                $parseMs += (int) round((microtime(true) - $repairParseStarted) * 1000);
                $result = $repair;
                $parsed['diagnostic']['parser_step'] = 'repair_' . ($parsed['diagnostic']['parser_step'] ?? 'direct');
            }
        }
        $diagnostic = (array) ($parsed['diagnostic'] ?? []);
        $diagnostic += [
            'internal_error_code' => '', 'stage' => 'product_vision',
            'provider_request_id' => (string) ($result['requestId'] ?? $result['data']['id'] ?? ''),
            'model' => (string) ($result['model'] ?? $model),
            'repair_attempted' => $repairAttempted,
            'repair_model' => $repairModel,
            'repair_ms' => $repairMs,
            'parse_ms' => $parseMs,
            'transport_ok' => true,
            'structured_ok' => !empty($parsed['ok']),
            'business_ok' => false,
        ];
        if (empty($parsed['ok'])) {
            $diagnostic['internal_error_code'] = $repairAttempted ? 'product_vision_repair_failed' : 'product_vision_json_invalid';
            return ['ok' => false, 'message' => '商品资料结构未能完整读取，请重试。',
                'errorCode' => $diagnostic['internal_error_code'], 'stage' => 'product_vision',
                'retryable' => false, 'diagnostic' => $diagnostic];
        }
        $product = $this->applyMerchantVisionIdentity((array) $parsed['data'], $context);
        $productPayload = $this->productVisionPayload($product);
        $businessRepairAttempted = false;
        $businessRepairApplied = false;
        $businessRepairMs = 0;
        $businessRepairRequestId = null;
        if ($productPayload === null) {
            $businessRepairAttempted = true;
            $businessRepairStarted = microtime(true);
            $retryContent = $content;
            $retryContent[0] = ['type' => 'text', 'text' => $instruction
                . "\n\n上一次响应没有给出可用的商品名称或类目。请重新看原图并从头返回完整 JSON。"
                . ' 名称写具体商品，类目使用消费者能够检索的常用类目；同时尽量填写外观、颜色、可见部件和可编辑商品摘要。无法确认的参数放入 uncertain_points，不要因为少数信息不确定而拒绝识别。'];
            $businessRepair = $this->requestCapability(AiCapability::PRODUCT_VISION, [
                'temperature' => 0.1,
                'max_tokens' => 2000,
                'response_format' => ['type' => 'json_object'],
                'messages' => [['role' => 'user', 'content' => $retryContent]],
            ], 120, null, [
                'merchant_id' => $merchantId,
                'resolved_model_override' => $model,
                'resolved_model_id_override' => max(0, (int) ($context['resolvedModelId'] ?? 0)),
                'resolved_gateway_override' => trim((string) ($context['resolvedGateway'] ?? '')),
                'fallback_model_override' => '',
                'fallback_model_id_override' => 0,
                'fallback_gateway_override' => '',
                'disable_fallback' => true,
                'call_key' => trim((string) ($context['callKey'] ?? '')) . ':business-repair:product_vision',
                'success_status' => 'repair',
            ], ['data', 'result']);
            $businessRepairMs = (int) round((microtime(true) - $businessRepairStarted) * 1000);
            $businessRepairRequestId = $businessRepair['requestId'] ?? $businessRepair['data']['id'] ?? null;
            if (!empty($businessRepair['ok'])) {
                $repairedProduct = $this->decodeProductVisionResponse($businessRepair);
                if (is_array($repairedProduct)) {
                    $repairedProduct = $this->applyMerchantVisionIdentity($repairedProduct, $context);
                    if (trim((string) ($repairedProduct['name'] ?? '')) === '' && trim((string) ($product['name'] ?? '')) !== '') {
                        $repairedProduct['name'] = $product['name'];
                    }
                    if (trim((string) ($repairedProduct['category'] ?? '')) === '' && trim((string) ($product['category'] ?? '')) !== '') {
                        $repairedProduct['category'] = $product['category'];
                    }
                    $repairedPayload = $this->productVisionPayload($repairedProduct);
                    if ($repairedPayload !== null) {
                        $product = $repairedProduct;
                        $productPayload = $repairedPayload;
                        $result = $businessRepair;
                        $businessRepairApplied = true;
                    }
                }
            }
        }
        $diagnostic['business_repair_attempted'] = $businessRepairAttempted;
        $diagnostic['business_repair_applied'] = $businessRepairApplied;
        $diagnostic['business_repair_ms'] = $businessRepairMs;
        $diagnostic['business_repair_request_id'] = $businessRepairRequestId;
        if ($productPayload === null) {
            $missing = [];
            if (trim((string) ($product['name'] ?? '')) === '') { $missing[] = 'product_name'; }
            if (trim((string) ($product['category'] ?? '')) === '') { $missing[] = 'category'; }
            $diagnostic['internal_error_code'] = 'product_vision_missing_core_facts';
            $diagnostic['missing_fields'] = $missing;
            return ['ok' => false, 'message' => '商品名称或类目需要人工补充。',
                'errorCode' => (string) $diagnostic['internal_error_code'], 'stage' => 'product_vision',
                'retryable' => false, 'diagnostic' => $diagnostic, 'partialProduct' => $product];
        }
        $diagnostic['business_ok'] = true;

        $primaryRequestId = $result['requestId'] ?? ($result['data']['id'] ?? null);

        return [
            'ok' => true,
            'message' => '商品信息识别完成。',
            'requestId' => $result['requestId'] ?? ($result['data']['id'] ?? null),
            'primaryRequestId' => $primaryRequestId,
            'model' => (string) ($result['model'] ?? $model),
            'primaryModel' => $model,
            'product' => $productPayload,
            'diagnostic' => $diagnostic + ['structured_ok' => true, 'business_ok' => true],
        ];
    }

    private function repairStructuredOutput(string $capability, string $rawText, string $stage, string $model, array $context, string $schemaExample): array
    {
        $instruction = "你是 JSON 格式修复器。只整理原回答中已经存在的事实，不得补充、推断或编造任何字段。"
            . "\n只返回一个 JSON 对象，不要 Markdown、解释或思考过程。目标结构示例：" . $schemaExample
            . "\n待修复原回答：\n" . mb_strimwidth($rawText, 0, 12000, '');
        return $this->requestCapability($capability, [
            'temperature' => 0,
            'max_tokens' => $capability === AiCapability::CONTENT_PLANNER ? 5000 : 1800,
            'response_format' => ['type' => 'json_object'],
            'messages' => [['role' => 'user', 'content' => $instruction]],
        ], 120, null, [
            'merchant_id' => isset($context['merchantId']) ? (int) $context['merchantId'] : null,
            'resolved_model_override' => $model,
            'resolved_model_id_override' => max(0, (int) ($context['resolvedModelId'] ?? 0)),
            'resolved_gateway_override' => trim((string) ($context['resolvedGateway'] ?? '')),
            'fallback_model_override' => '', 'fallback_model_id_override' => 0, 'fallback_gateway_override' => '',
            'disable_fallback' => true,
            'call_key' => trim((string) ($context['callKey'] ?? '')) . ':json-repair:' . $stage,
            'success_status' => 'repair',
        ]);
    }

    private function transportDiagnostic(array $result, string $model, string $stage): array
    {
        $status = max(0, (int) ($result['status'] ?? 0));
        $fallbackAttempted = !empty($result['routingFallbackAttempted']);
        $effectiveStatus = $fallbackAttempted
            ? max(0, (int) ($result['routingFallbackStatus'] ?? 0))
            : $status;
        $dispatchAmbiguous = $fallbackAttempted
            ? !empty($result['routingFallbackDispatchAmbiguous'])
            : !empty($result['dispatchAmbiguous']);
        $retryable = !$dispatchAmbiguous
            && empty($result['routingRouteBudgetExhausted'])
            && in_array($effectiveStatus, [408, 425, 429, 500, 502, 503, 504, 524], true)
            && (!$fallbackAttempted || in_array($effectiveStatus, [425, 429, 503], true));
        return [
            'internal_error_code' => $stage . '_transport_failed',
            'stage' => $stage,
            'message' => mb_strimwidth(trim((string) ($result['message'] ?? '')), 0, 1000, ''),
            'provider_request_id' => (string) ($result['requestId'] ?? $result['data']['id'] ?? ''),
            'model' => (string) ($result['model'] ?? $model),
            'retryable' => $retryable,
            'transport_ok' => false,
            'structured_ok' => false,
            'business_ok' => false,
        ];
    }

    public function analyzeDetailPageProduct(
        array $image,
        string $platform,
        string $language,
        string $creativeBrief = '',
        array $productContext = [],
        ?int $merchantId = null,
        string $resolvedModel = '',
        string $fallbackModel = '',
        array $additionalImages = [],
        string $resolvedGateway = '',
        string $fallbackGateway = '',
        string $callKey = ''
    ): array
    {
        $model = trim($resolvedModel);
        if ($model === '') {
            $model = $this->modelForCapability(AiCapability::PRODUCT_VISION, $merchantId);
        }
        if ($model === '') {
            return ['ok' => false, 'message' => '管理员尚未配置商品识别模型。'];
        }
        $instruction = $this->prompts->buildDetailReportPrompt([
            '用户初步想法' => mb_strimwidth(trim($creativeBrief), 0, 2000, ''),
            'SKU 已有商品资料' => $productContext,
            '参考图片数量' => 1 + min(5, count($additionalImages)),
            '十屏键名' => array_keys(detail_page_screen_options()),
        ], $platform, $language);
        $content = [
            ['type' => 'text', 'text' => $instruction],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $image['mime'] . ';base64,' . base64_encode($image['content'])]],
        ];
        foreach (array_slice($additionalImages, 0, 5) as $additionalImage) {
            if (!is_array($additionalImage) || empty($additionalImage['mime']) || empty($additionalImage['content'])) {
                continue;
            }
            $content[] = ['type' => 'image_url', 'image_url' => [
                'url' => 'data:' . $additionalImage['mime'] . ';base64,' . base64_encode($additionalImage['content']),
            ]];
        }
        $result = $this->requestCapability(AiCapability::PRODUCT_VISION, [
            'temperature' => 0.25,
            'max_tokens' => 5000,
            'response_format' => ['type' => 'json_object'],
            'messages' => [['role' => 'user', 'content' => $content]],
        ], self::PRODUCT_VISION_TIMEOUT_SECONDS, null, [
            'merchant_id' => $merchantId,
            'resolved_model_override' => $model,
            'resolved_gateway_override' => trim($resolvedGateway),
            'fallback_model_override' => trim($fallbackModel),
            'fallback_gateway_override' => trim($fallbackGateway),
            'call_key' => trim($callKey),
        ]);
        if (!$result['ok']) {
            $diagnostic = $this->transportDiagnostic($result, $model, 'product_vision');
            return array_merge($result, [
                'errorCode' => 'product_vision_transport_failed',
                'stage' => 'product_vision',
                'retryable' => (bool) $diagnostic['retryable'],
                'diagnostic' => $diagnostic,
            ]);
        }
        $report = $this->decodeDetailReport($result['data']['choices'][0]['message']['content'] ?? '');
        if ($report === null) {
            return [
                'ok' => false,
                'message' => '识别模型没有返回可解析的商品资料，请重新提交。',
                'errorCode' => 'product_vision_json_invalid',
                'stage' => 'product_vision',
                'retryable' => false,
                'diagnostic' => [
                    'internal_error_code' => 'product_vision_json_invalid',
                    'stage' => 'product_vision',
                    'retryable' => false,
                    'transport_ok' => true,
                    'structured_ok' => false,
                    'business_ok' => false,
                ],
            ];
        }
        return [
            'ok' => true,
            'message' => '商品资料和详情页脚本已生成。',
            'report' => $report,
            'model' => (string) ($result['model'] ?? $model),
            'routingFallbackUsed' => !empty($result['routingFallbackUsed']),
            'requestId' => $result['requestId'] ?? ($result['data']['id'] ?? null),
        ];
    }

    public function buildDetailPagePlan(array $productProfile, string $platform, string $language, string $creativeBrief = '', array $marketContext = [], ?int $merchantId = null, array $routeContext = []): array
    {
        $model = trim((string) ($routeContext['resolvedModel'] ?? ''));
        if ($model === '') {
            $model = $this->modelForCapability(AiCapability::CONTENT_PLANNER, $merchantId);
        }
        if ($model === '') {
            return ['ok' => false, 'message' => '管理员尚未配置商品策划模型。'];
        }
        $instruction = $this->prompts->buildDetailReportPrompt([
            'SKU 通用商品报告' => $productProfile,
            '联网市场参考资料' => $marketContext,
            '用户本次创作想法' => mb_strimwidth(trim($creativeBrief), 0, 2000, ''),
            '任务' => '沿用 SKU 商品资料，只针对目标平台和语言生成十张详情图的标题、正文和自然创作方向。',
            '十屏键名' => array_keys(detail_page_screen_options()),
        ], $platform, $language);
        $result = $this->requestCapability(AiCapability::CONTENT_PLANNER, [
            'temperature' => 0.25,
            'max_tokens' => 5000,
            'response_format' => ['type' => 'json_object'],
            'messages' => [['role' => 'user', 'content' => $instruction]],
        ], 180, null, [
            'merchant_id' => $merchantId,
            'resolved_model_override' => $model,
            'resolved_model_id_override' => max(0, (int) ($routeContext['resolvedModelId'] ?? 0)),
            'resolved_gateway_override' => trim((string) ($routeContext['resolvedGateway'] ?? '')),
            'fallback_model_override' => trim((string) ($routeContext['fallbackModel'] ?? '')),
            'fallback_model_id_override' => max(0, (int) ($routeContext['fallbackModelId'] ?? 0)),
            'fallback_gateway_override' => trim((string) ($routeContext['fallbackGateway'] ?? '')),
            'call_key' => trim((string) ($routeContext['callKey'] ?? '')),
            'disable_fallback' => true,
        ]);
        if (!$result['ok']) {
            return $result + [
                'errorCode' => 'content_planner_transport_failed',
                'stage' => 'content_planner',
                'retryable' => true,
                'diagnostic' => $this->transportDiagnostic($result, $model, 'content_planner'),
            ];
        }
        $parseStarted = microtime(true);
        $parsed = $this->parseDetailReport($result['data']['choices'][0]['message']['content'] ?? $this->chatResponseText((array) ($result['data'] ?? [])));
        $parseMs = (int) round((microtime(true) - $parseStarted) * 1000);
        $repairAttempted = false;
        $repairMs = 0;
        $repairModel = null;
        if (empty($parsed['ok']) && ($parsed['reason'] ?? '') === 'json_invalid') {
            $repairAttempted = true;
            $repairStarted = microtime(true);
            $repairContext = $routeContext + ['merchantId' => $merchantId];
            $repair = $this->repairStructuredOutput(
                AiCapability::CONTENT_PLANNER,
                (string) ($parsed['rawText'] ?? ''),
                'content_planner',
                $model,
                $repairContext,
                '{"product_name":"","category":"","selling_points":[],"product_description":"","screen_plan":[]}'
            );
            $repairMs = (int) round((microtime(true) - $repairStarted) * 1000);
            $repairModel = $repair['model'] ?? null;
            if (!empty($repair['ok'])) {
                $repairParseStarted = microtime(true);
                $parsed = $this->parseDetailReport($repair['data']['choices'][0]['message']['content'] ?? $this->chatResponseText((array) ($repair['data'] ?? [])));
                $parseMs += (int) round((microtime(true) - $repairParseStarted) * 1000);
                $result = $repair;
                $parsed['diagnostic']['parser_step'] = 'repair_' . ($parsed['diagnostic']['parser_step'] ?? 'direct');
            }
        }
        if (empty($parsed['ok'])
            && ($parsed['reason'] ?? '') === 'missing_plan'
            && (array) ($parsed['missingFields'] ?? []) === ['screen_plan']) {
            $parsed['data'] = (array) ($parsed['data'] ?? []);
            $parsed['data']['screen_plan'] = $this->defaultDetailScreenPlan();
            $parsed['ok'] = true;
            $parsed['reason'] = '';
            $parsed['missingFields'] = [];
            $parsed['diagnostic']['parser_step'] = 'deterministic_screen_plan';
        }
        $diagnostic = (array) ($parsed['diagnostic'] ?? []);
        $diagnostic += [
            'internal_error_code' => '', 'stage' => 'content_planner',
            'provider_request_id' => (string) ($result['requestId'] ?? $result['data']['id'] ?? ''),
            'model' => (string) ($result['model'] ?? $model),
            'repair_attempted' => $repairAttempted, 'repair_model' => $repairModel, 'repair_ms' => $repairMs,
            'parse_ms' => $parseMs,
            'transport_ok' => true,
            'structured_ok' => !empty($parsed['ok']),
            'business_ok' => false,
        ];
        if (empty($parsed['ok'])) {
            $groundedFallback = $this->groundedDetailFallback($productProfile);
            if ($groundedFallback !== null) {
                $diagnostic['parser_step'] = 'deterministic_grounded_fallback';
                $diagnostic['structured_ok'] = false;
                $diagnostic['business_ok'] = true;
                $diagnostic['upstream_missing_fields'] = (array) ($parsed['missingFields'] ?? []);
                return [
                    'ok' => true,
                    'message' => '上游策划结构不完整，已仅使用可追溯商品事实建立保守详情页方案。',
                    'report' => $groundedFallback,
                    'model' => (string) ($result['model'] ?? $model),
                    'routingFallbackUsed' => !empty($result['routingFallbackUsed']),
                    'requestId' => $result['requestId'] ?? ($result['data']['id'] ?? null),
                    'diagnostic' => $diagnostic,
                ];
            }
            $missingPlan = ($parsed['reason'] ?? '') === 'missing_plan';
            $errorCode = $missingPlan ? 'content_planner_missing_plan'
                : ($repairAttempted ? 'content_planner_repair_failed' : 'content_planner_json_invalid');
            $diagnostic['internal_error_code'] = $errorCode;
            $diagnostic['missing_fields'] = (array) ($parsed['missingFields'] ?? []);
            return ['ok' => false,
                'message' => $missingPlan ? '详情页策划内容需要人工补充。' : '详情页策划结构未能完整读取，请重试。',
                'errorCode' => $errorCode, 'stage' => 'content_planner', 'retryable' => false,
                'diagnostic' => $diagnostic];
        }
        $report = $this->groundDetailPagePlan((array) $parsed['data'], $productProfile);
        $diagnostic['business_ok'] = true;
        return [
            'ok' => true,
            'message' => '已复用通用商品报告生成平台详情页方案。',
            'report' => $report,
            'model' => (string) ($result['model'] ?? $model),
            'routingFallbackUsed' => !empty($result['routingFallbackUsed']),
            'requestId' => $result['requestId'] ?? ($result['data']['id'] ?? null),
            'diagnostic' => $diagnostic + ['structured_ok' => true, 'business_ok' => true],
        ];
    }

    /**
     * A truncated planner response must not turn a successfully recognized product
     * into a generic service failure. Build the minimum usable draft from the product
     * identity and evidence-backed projection. Readiness still blocks generation until
     * the merchant confirms an unverified identity or missing visual facts.
     */
    private function groundedDetailFallback(array $productProfile): ?array
    {
        $report = $this->groundDetailPagePlan([
            'product_name' => $this->normalizeReportText($productProfile['name'] ?? ''),
            'category' => $this->normalizeReportText($productProfile['category'] ?? ''),
            'selling_points' => $this->normalizeReportList(
                $productProfile['sellingPoints'] ?? $productProfile['selling_points'] ?? []
            ),
            'product_description' => $this->normalizeReportText(
                $productProfile['productDescription'] ?? $productProfile['product_description'] ?? ''
            ),
            'prohibited_claims' => [],
            'screen_plan' => $this->defaultDetailScreenPlan(),
        ], $productProfile);

        if (trim((string) ($report['product_name'] ?? '')) === ''
            || trim((string) ($report['category'] ?? '')) === ''
            || count((array) ($report['screen_plan'] ?? [])) !== count(detail_page_screen_options())) {
            return null;
        }
        return $report;
    }

    /** Keep planner creativity while projecting the editable product report fields. */
    private function groundDetailPagePlan(array $report, array $productProfile): array
    {
        $foundation = is_array($productProfile['foundation'] ?? null) ? $productProfile['foundation'] : [];
        $name = trim((string) ($productProfile['name'] ?? $report['product_name'] ?? ''));
        $category = trim((string) ($productProfile['category'] ?? $report['category'] ?? ''));
        $materials = trim((string) ($foundation['materials'] ?? ''));
        $appearance = trim((string) ($foundation['appearance'] ?? ''));
        $features = array_values(array_filter((array) ($foundation['core_functions'] ?? [])));
        $sellingPoints = array_values(array_filter((array) ($productProfile['sellingPoints'] ?? $productProfile['selling_points'] ?? [])));
        $useScenarios = array_values(array_filter((array) ($foundation['use_scenarios'] ?? [])));
        $targetAudience = trim((string) ($foundation['target_audience'] ?? ''));
        $prohibitedClaims = array_values(array_filter((array) ($foundation['prohibited_claims'] ?? [])));
        $description = trim((string) ($productProfile['productDescription'] ?? $productProfile['product_description'] ?? ''));

        $report['product_name'] = $name;
        $report['category'] = $category;
        if ($materials !== '') { $report['materials'] = $materials; }
        if ($appearance !== '') { $report['appearance'] = $appearance; }
        if ($targetAudience !== '') { $report['target_audience'] = $targetAudience; }
        if ($useScenarios !== []) { $report['use_scenarios'] = $useScenarios; }
        if ($sellingPoints !== []) { $report['selling_points'] = $sellingPoints; }
        if ($features !== []) { $report['core_functions'] = $features; }
        $report['prohibited_claims'] = array_values(array_unique(array_merge(
            (array) ($report['prohibited_claims'] ?? []),
            $prohibitedClaims
        )));
        if ($description !== '') { $report['product_description'] = $description; }
        return $report;
    }

    private function defaultDetailScreenPlan(): array
    {
        $screens = [];
        $index = 1;
        foreach (detail_page_screen_options() as $key => $option) {
            $screens[] = [
                'key' => $key,
                'index' => $index++,
                'title' => $this->normalizeReportText($option['label'] ?? ''),
                'headline' => '',
                'body' => '',
                'headline_zh' => '',
                'body_zh' => '',
                'visual_direction' => $this->normalizeReportText($option['hint'] ?? ''),
            ];
        }
        return $screens;
    }

    public function planVideoStoryboard(
        string $projectTitle,
        ?array $sku,
        string $brief,
        string $platform,
        string $language,
        string $ratio,
        int $sceneCount,
        ?int $merchantId = null
    ): array {
        $model = $this->modelForCapability(AiCapability::CONTENT_PLANNER, $merchantId);
        if ($model === '') {
            return ['ok' => false, 'message' => '管理员尚未配置视频分镜策划模型。'];
        }
        $sceneCount = max(2, min(10, $sceneCount));
        $instruction = $this->prompts->buildStoryboardPlanPrompt(
            $projectTitle,
            $sku,
            $brief,
            $platform,
            $language,
            $ratio,
            $sceneCount
        );
        $result = $this->requestCapability(AiCapability::CONTENT_PLANNER, [
            'temperature' => 0.25,
            'max_tokens' => 3600,
            'messages' => [['role' => 'user', 'content' => $instruction]],
        ], 180, null, ['merchant_id' => $merchantId]);
        if (!$result['ok']) {
            return $result;
        }
        $decoded = $this->decodeJsonObject($this->chatResponseText((array) ($result['data'] ?? [])));
        $rawScenes = is_array($decoded['scenes'] ?? null) ? array_values($decoded['scenes']) : [];
        $scenes = [];
        foreach (array_slice($rawScenes, 0, $sceneCount) as $index => $scene) {
            if (!is_array($scene)) {
                continue;
            }
            $motion = (string) ($scene['motionPreset'] ?? 'product_showcase');
            if (!in_array($motion, ['product_showcase', 'slow_push', 'orbit', 'lifestyle'], true)) {
                $motion = 'product_showcase';
            }
            $duration = (int) ($scene['durationSeconds'] ?? 5);
            if (!in_array($duration, [5, 10], true)) {
                $duration = 5;
            }
            $transition = (string) ($scene['transition'] ?? 'cut');
            if (!in_array($transition, ['cut', 'fade', 'dissolve'], true)) {
                $transition = 'cut';
            }
            $scenes[] = [
                'title' => mb_strimwidth(trim((string) ($scene['title'] ?? '')), 0, 80, '') ?: '镜头 ' . ($index + 1),
                'prompt' => mb_strimwidth(trim((string) ($scene['prompt'] ?? '')), 0, 1200, ''),
                'motionPreset' => $motion,
                'durationSeconds' => $duration,
                'subtitle' => mb_strimwidth(trim((string) ($scene['subtitle'] ?? '')), 0, 200, ''),
                'transition' => $transition,
                'usePreviousTail' => $index > 0 && !empty($scene['usePreviousTail']),
            ];
        }
        if (count($scenes) < 2 || array_filter($scenes, static fn (array $scene): bool => $scene['prompt'] !== '') === []) {
            return ['ok' => false, 'message' => '模型没有返回可用的视频分镜，请调整创作目标后重试。'];
        }
        return [
            'ok' => true,
            'message' => 'AI 分镜建议已生成，请确认每个镜头后再提交视频任务。',
            'scenes' => $scenes,
            'model' => (string) ($result['model'] ?? $model),
            'routingFallbackUsed' => !empty($result['routingFallbackUsed']),
            'requestId' => $result['requestId'] ?? ($result['data']['id'] ?? null),
        ];
    }

    public function refineDetailPageReport(array $currentReport, string $message, string $platform, string $language, ?array $image = null): array
    {
        $model = $this->modelForCapability(AiCapability::CONTENT_PLANNER);
        if ($model === '') {
            return ['ok' => false, 'message' => '管理员尚未配置商品识别模型。'];
        }
        $instruction = $this->prompts->buildDetailReportPrompt([
            '当前商品资料' => $currentReport,
            '用户本轮补充或修改要求' => mb_strimwidth(trim($message), 0, 3000, ''),
            '任务' => '结合实拍图和用户要求更新商品资料与十张详情图脚本，并在 assistant_reply 中简要说明改了什么。',
            '十屏键名' => array_keys(detail_page_screen_options()),
        ], $platform, $language);
        $content = [['type' => 'text', 'text' => $instruction]];
        if ($image !== null) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $image['mime'] . ';base64,' . base64_encode($image['content'])]];
        }
        $result = $this->requestCapability(AiCapability::CONTENT_PLANNER, ['temperature' => 0.25, 'max_tokens' => 5000,
            'messages' => [['role' => 'user', 'content' => $content]],
        ], 180);
        if (!$result['ok']) {
            return $result;
        }
        $report = $this->decodeDetailReport($result['data']['choices'][0]['message']['content'] ?? '');
        if ($report === null) {
            return ['ok' => false, 'message' => '模型没有返回可解析的修订报告，请换一种说法后重试。'];
        }
        return [
            'ok' => true,
            'message' => '商品资料已更新。',
            'report' => $report,
            'model' => (string) ($result['model'] ?? $model),
            'routingFallbackUsed' => !empty($result['routingFallbackUsed']),
            'requestId' => $result['requestId'] ?? ($result['data']['id'] ?? null),
        ];
    }

    private function decodeDetailReport(mixed $content): ?array
    {
        $parsed = $this->parseDetailReport($content);
        return !empty($parsed['ok']) ? (array) $parsed['data'] : null;
    }

    private function parseDetailReport(mixed $content): array
    {
        $aliases = [
            'product_name' => ['productName', 'name', 'title'],
            'category' => ['product_category', 'productCategory'],
            'search_terms' => ['searchTerms', 'keywords'],
            'selling_points' => ['sellingPoints', 'highlights'],
            'product_description' => ['productDescription', 'description', 'summary'],
            'screen_plan' => ['screenPlan', 'screens', 'detail_plan'],
        ];
        $parsed = $this->structuredOutput->parse($content, $aliases, ['data', 'result']);
        if (empty($parsed['ok'])) {
            return array_replace($parsed, ['reason' => 'json_invalid', 'missingFields' => []]);
        }
        $decoded = (array) $parsed['data'];
        if (isset($decoded['product_report'])
            && is_array($decoded['product_report'])
            && !array_is_list($decoded['product_report'])) {
            $productReport = $decoded['product_report'];
            unset($decoded['product_report']);
            $decoded = array_replace($decoded, $productReport);
            foreach ($aliases as $canonical => $candidates) {
                if (array_key_exists($canonical, $decoded)) {
                    continue;
                }
                foreach ($candidates as $candidate) {
                    if (array_key_exists($candidate, $decoded)) {
                        $decoded[$canonical] = $decoded[$candidate];
                        break;
                    }
                }
            }
        }
        $arrays = ['search_terms', 'use_scenarios', 'selling_points', 'core_functions', 'uncertain_points', 'prohibited_claims'];
        foreach ($arrays as $field) {
            $decoded[$field] = $this->normalizeReportList($decoded[$field] ?? []);
        }
        foreach (['product_name', 'category', 'materials', 'appearance', 'target_audience', 'product_description', 'brand_notes', 'assistant_reply'] as $field) {
            $decoded[$field] = $this->normalizeReportText($decoded[$field] ?? '');
        }
        if ($decoded['product_description'] === '' && $decoded['product_name'] !== '' && $decoded['category'] !== '' && $decoded['selling_points'] !== []) {
            $decoded['product_description'] = $decoded['product_name'] . '，类目为' . $decoded['category']
                . '。核心卖点：' . implode('；', array_slice($decoded['selling_points'], 0, 5)) . '。';
        }
        $providedScreens = is_array($decoded['screen_plan'] ?? null) ? $decoded['screen_plan'] : [];
        $missingFields = [];
        if ($decoded['product_name'] === '') { $missingFields[] = 'product_name'; }
        if ($decoded['category'] === '') { $missingFields[] = 'category'; }
        if ($decoded['selling_points'] === []) { $missingFields[] = 'selling_points'; }
        if ($providedScreens === []) { $missingFields[] = 'screen_plan'; }
        if ($missingFields !== []) {
            return array_replace($parsed, ['ok' => false, 'reason' => 'missing_plan', 'missingFields' => $missingFields]);
        }
        $indexed = [];
        foreach ($providedScreens as $screen) {
            $screenKey = is_array($screen) ? $this->normalizeReportText($screen['key'] ?? '') : '';
            if ($screenKey !== '' && isset(detail_page_screen_options()[$screenKey])) {
                $indexed[$screenKey] = $screen;
            }
        }
        $screens = [];
        $index = 1;
        foreach (detail_page_screen_options() as $key => $option) {
            $screen = $indexed[$key] ?? [];
            $screens[] = [
                'key' => $key, 'index' => $index++, 'title' => $this->normalizeReportText($screen['title'] ?? $option['label']),
                'headline' => mb_strimwidth($this->normalizeReportText($screen['headline'] ?? ''), 0, 120, ''),
                'body' => mb_strimwidth($this->normalizeReportText($screen['body'] ?? ''), 0, 240, ''),
                'headline_zh' => mb_strimwidth($this->normalizeReportText($screen['headline_zh'] ?? $screen['headline_translation'] ?? ''), 0, 120, ''),
                'body_zh' => mb_strimwidth($this->normalizeReportText($screen['body_zh'] ?? $screen['body_translation'] ?? ''), 0, 240, ''),
                'visual_direction' => mb_strimwidth($this->normalizeReportText($screen['visual_direction'] ?? $option['hint']), 0, 600, ''),
            ];
        }
        $decoded['screen_plan'] = $screens;
        $decoded['assistant_reply'] = $decoded['assistant_reply'] !== '' ? $decoded['assistant_reply'] : '已根据你的要求更新商品资料与详情页脚本。';
        return array_replace($parsed, ['ok' => true, 'data' => $decoded, 'reason' => '', 'missingFields' => []]);
    }

    private function normalizeReportList(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        $normalized = array_map(fn (mixed $item): string => $this->normalizeReportText($item), $items);
        return array_values(array_unique(array_filter($normalized, static fn (string $item): bool => $item !== '')));
    }

    private function normalizeReportText(mixed $value): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return trim((string) $value);
        }
        if (is_bool($value)) {
            return $value ? '是' : '否';
        }
        if (!is_array($value)) {
            return '';
        }

        $parts = [];
        foreach ($value as $item) {
            $text = $this->normalizeReportText($item);
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        return implode('；', array_values(array_unique($parts)));
    }

    public function optimizeImagePrompt(string $idea, string $ratio, bool $hasReference, ?int $merchantId = null): array
    {
        $model = $this->modelForCapability(AiCapability::CONTENT_PLANNER, $merchantId);
        if ($model === '') {
            return ['ok' => false, 'message' => '管理员尚未配置提示词润色模型。'];
        }
        $instruction = $this->prompts->systemPrompt('prompt_optimize', [
            '{image_ratio}' => mb_strimwidth(trim($ratio), 0, 40, ''),
            '{has_reference}' => $hasReference ? '已提供，保留参考图核心主体身份和结构，仅按要求修改' : '未提供，请明确描述要生成的画面',
            '{user_prompt}' => mb_strimwidth(trim($idea), 0, 2000, ''),
        ]);
        $result = $this->requestCapability(AiCapability::CONTENT_PLANNER, [
            'temperature' => 0.45,
            'max_tokens' => 1000,
            'messages' => [['role' => 'user', 'content' => $instruction]],
        ], 120, null, ['merchant_id' => $merchantId]);
        if (!$result['ok']) {
            return $result;
        }
        $content = $result['data']['choices'][0]['message']['content'] ?? '';
        if (is_array($content)) {
            $content = implode('', array_map(static fn (mixed $item): string => is_array($item) ? (string) ($item['text'] ?? '') : (string) $item, $content));
        }
        $content = trim((string) $content);
        $content = preg_replace('/^```(?:text)?\s*|\s*```$/i', '', $content) ?? $content;
        if ($content === '') {
            return ['ok' => false, 'message' => '模型没有返回可用的图片提示词。'];
        }

        return [
            'ok' => true,
            'message' => '提示词草稿已生成，请确认后使用。',
            'prompt' => mb_strimwidth($content, 0, 4000, ''),
            'model' => (string) ($result['model'] ?? $model),
            'routingFallbackUsed' => !empty($result['routingFallbackUsed']),
            'requestId' => $result['requestId'] ?? ($result['data']['id'] ?? null),
        ];
    }

    public function reverseImagePrompt(array $image, ?int $merchantId = null): array
    {
        $model = $this->modelForCapability(AiCapability::PRODUCT_VISION, $merchantId);
        if ($model === '') {
            return ['ok' => false, 'message' => '管理员尚未配置视觉识别模型。'];
        }
        $instruction = $this->prompts->systemPrompt('prompt_reverse');
        $result = $this->requestCapability(AiCapability::PRODUCT_VISION, [
            'temperature' => 0.35,
            'max_tokens' => 1200,
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => $instruction],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $image['mime'] . ';base64,' . base64_encode($image['content'])]],
            ]]],
        ], 120, null, ['merchant_id' => $merchantId]);
        if (!$result['ok']) {
            return $result;
        }
        $content = $result['data']['choices'][0]['message']['content'] ?? '';
        if (is_array($content)) {
            $content = implode('', array_map(static fn (mixed $item): string => is_array($item) ? (string) ($item['text'] ?? '') : (string) $item, $content));
        }
        $content = trim((string) $content);
        $content = preg_replace('/^```(?:text)?\s*|\s*```$/i', '', $content) ?? $content;
        if ($content === '') {
            return ['ok' => false, 'message' => '视觉模型没有返回可用提示词。'];
        }
        return [
            'ok' => true,
            'prompt' => mb_strimwidth($content, 0, 4000, ''),
            'model' => (string) ($result['model'] ?? $model),
            'routingFallbackUsed' => !empty($result['routingFallbackUsed']),
            'requestId' => $result['requestId'] ?? ($result['data']['id'] ?? null),
        ];
    }

    private function chatResponseText(array $data): string
    {
        $message = $data['choices'][0]['message'] ?? [];
        $candidates = [
            is_array($message) ? ($message['content'] ?? null) : null,
            is_array($message) ? ($message['output_text'] ?? null) : null,
            $data['choices'][0]['text'] ?? null,
            $data['output_text'] ?? null,
            $data['output'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $text = trim($this->flattenResponseText($candidate));
            if ($text !== '') {
                return $text;
            }
        }
        return '';
    }

    private function flattenResponseText(mixed $value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return (string) $value;
        }
        if (!is_array($value)) {
            return '';
        }
        if (!array_is_list($value)) {
            foreach (['text', 'content', 'output_text'] as $key) {
                if (array_key_exists($key, $value)) {
                    $text = $this->flattenResponseText($value[$key]);
                    if (trim($text) !== '') {
                        return $text;
                    }
                }
            }
        }
        return implode('', array_map(fn (mixed $item): string => $this->flattenResponseText($item), $value));
    }

    public function moderateContent(?string $text, ?array $image, string $model, string $fallbackModel, ?int $merchantId = null): array
    {
        return $this->moderateByCapability($text, $image, $merchantId);
    }

    private function moderateByCapability(?string $text, ?array $image, ?int $merchantId = null): array
    {
        $capability = $image !== null ? AiCapability::SAFETY_VISION : AiCapability::SAFETY_TEXT;
        try {
            $resolution = $this->router->resolve($capability, null, $merchantId);
        } catch (Throwable $exception) {
            return ['ok' => false, 'flagged' => false, 'categories' => [], 'reason' => $exception->getMessage(), 'model' => ''];
        }
        $context = ['merchant_id' => $merchantId];
        $routeStartedAt = microtime(true);
        $primary = $this->classifyContentSafety(
            $text,
            $image,
            (string) $resolution['resolvedModel'],
            '能力路由主模型不可用。',
            $capability,
            (int) ($resolution['resolvedModelId'] ?? 0),
            'success',
            $context,
            (string) ($resolution['resolvedGateway'] ?? 'sub2api')
        );
        if ($primary['ok']) {
            return $primary + ['routingCapability' => $capability];
        }
        $fallbackModel = trim((string) ($resolution['fallbackModel'] ?? ''));
        if ($fallbackModel !== '') {
            $fallbackTimeout = self::SAFETY_VISION_TIMEOUT_SECONDS
                - (int) ceil(microtime(true) - $routeStartedAt);
            if ($fallbackTimeout < 10) {
                $primary['reason'] = (string) $primary['reason']
                    . '；备用模型未调用：图片审核已达总等待上限。';
                return $primary + ['routingCapability' => $capability];
            }
            $fallback = $this->classifyContentSafety(
                $text,
                $image,
                $fallbackModel,
                (string) $primary['reason'],
                $capability,
                (int) ($resolution['fallbackModelId'] ?? 0),
                'fallback',
                $context,
                (string) ($resolution['fallbackGateway'] ?? 'sub2api'),
                $fallbackTimeout
            );
            if ($fallback['ok']) {
                $fallback['fallbackUsed'] = true;
                $fallback['primaryError'] = $primary['reason'];
                $fallback['routingCapability'] = $capability;
                return $fallback;
            }
            $primary['reason'] = (string) $primary['reason'] . '；备用模型不可用：' . (string) $fallback['reason'];
        }
        return $primary + ['routingCapability' => $capability];
    }

    private function classifyContentSafety(
        ?string $text,
        ?array $image,
        string $model,
        string $primaryError,
        string $capability,
        ?int $modelId = null,
        string $successStatus = 'success',
        array $context = [],
        string $gateway = 'sub2api',
        int $timeoutSeconds = self::SAFETY_VISION_TIMEOUT_SECONDS
    ): array
    {
        if (trim($model) === '') {
            return ['ok' => false, 'flagged' => false, 'categories' => [], 'reason' => $primaryError ?: '内容安全模型未配置。', 'model' => ''];
        }
        $content = [[
            'type' => 'text',
            'text' => $this->prompts->systemPrompt('content_safety', [
                '{user_prompt}' => json_encode(mb_strimwidth(trim((string) $text), 0, 12000, ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]),
        ]];
        if ($image !== null) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $image['mime'] . ';base64,' . base64_encode($image['content'])]];
        }
        $payload = [
            'model' => $model,
            'temperature' => 0,
            'max_tokens' => 400,
            'messages' => [['role' => 'user', 'content' => $content]],
        ];
        $result = $this->executeCapabilityTransport(
            $capability,
            $modelId,
            $gateway,
            $payload,
            max(10, min(self::SAFETY_VISION_TIMEOUT_SECONDS, $timeoutSeconds)),
            $successStatus,
            $context,
            $successStatus === 'fallback' ? 'fallback' : 'primary',
            true
        );
        if (!$result['ok']) {
            return ['ok' => false, 'flagged' => false, 'categories' => [], 'reason' => (string) $result['message'], 'model' => $model];
        }
        $response = $result['data']['choices'][0]['message']['content'] ?? '';
        if (is_array($response)) {
            $response = implode('', array_map(static fn (mixed $item): string => is_array($item) ? (string) ($item['text'] ?? '') : (string) $item, $response));
        }
        $response = trim((string) $response);
        $response = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $response) ?? $response;
        $classified = json_decode($response, true);
        if (!is_array($classified)) {
            $start = strpos($response, '{');
            $end = strrpos($response, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $classified = json_decode(substr($response, $start, $end - $start + 1), true);
            }
        }
        if (!is_array($classified)
            || !array_key_exists('flagged', $classified)
            || !is_bool($classified['flagged'])
            || !is_array($classified['categories'] ?? null)
            || !is_string($classified['reason'] ?? null)
            || array_filter($classified['categories'], static fn (mixed $category): bool => !is_string($category)) !== []) {
            if ($capability !== null) {
                $this->recordDeferredTransport($result, false, '内容安全模型返回格式无法识别。');
            }
            return ['ok' => false, 'flagged' => false, 'categories' => [], 'reason' => '内容安全模型返回格式无法识别。', 'model' => $model];
        }

        if ($capability !== null) {
            $this->recordDeferredTransport($result, true);
        }

        return [
            'ok' => true,
            'flagged' => (bool) $classified['flagged'],
            'categories' => array_values(array_filter(array_map('strval', is_array($classified['categories'] ?? null) ? $classified['categories'] : []))),
            'reason' => mb_strimwidth(trim((string) ($classified['reason'] ?? '')), 0, 500, ''),
            'model' => $model,
            'requestId' => $result['requestId'] ?? null,
        ];
    }

    public function imageEditMode(): string
    {
        $mode = $this->settings->get('default_image_edit_mode');
        return in_array($mode, ['auto', 'enabled', 'disabled'], true) ? $mode : 'auto';
    }

    public function supportsImageEdit(string $model): bool
    {
        $mode = $this->imageEditMode();
        if ($mode === 'enabled') {
            return true;
        }
        if ($mode === 'disabled') {
            return false;
        }
        foreach ($this->modelCatalog() as $item) {
            if ($item['id'] === $model) {
                return (bool) $item['imageEdit'];
            }
        }

        return $this->classifyModel($model)['imageEdit'];
    }

    public function modelCatalog(): array
    {
        $models = [];
        foreach (Db::name('ai_models')
            ->where('enabled', 1)
            ->where('source_visible', 1)
            ->whereNull('archived_at')
            ->order('sort_order', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray() as $item) {
            $id = trim((string) ($item['model_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $classification = $this->classifyModel($id);
            $declaredCapabilities = json_decode((string) ($item['capabilities_json'] ?? '[]'), true);
            $declaredCapabilities = is_array($declaredCapabilities) ? array_values(array_filter(array_map('strval', $declaredCapabilities))) : [];
            $classification['imageGeneration'] = $classification['imageGeneration']
                || in_array(AiCapability::IMAGE_GENERATION, $declaredCapabilities, true);
            $classification['imageEdit'] = $classification['imageEdit']
                || in_array(AiCapability::IMAGE_EDIT, $declaredCapabilities, true);
            $classification['videoImageToVideo'] = $classification['videoImageToVideo']
                || in_array(AiCapability::VIDEO_IMAGE_TO_VIDEO, $declaredCapabilities, true);
            $classification['videoTextToVideo'] = $classification['videoTextToVideo']
                || in_array(AiCapability::VIDEO_TEXT_TO_VIDEO, $declaredCapabilities, true);
            $models[$id] = $classification + [
                'id' => $id,
                'owner' => trim((string) ($item['provider'] ?? '')),
            ];
        }
        return array_values($models);
    }

    public function capabilityModelCatalog(string $capability): array
    {
        if (!AiCapability::exists($capability)) {
            return [];
        }
        $available = [];
        foreach (Db::name('ai_models')->where('enabled', 1)->order('sort_order', 'asc')->order('id', 'asc')->select()->toArray() as $item) {
            $capabilities = json_decode((string) ($item['capabilities_json'] ?? '[]'), true);
            $capabilities = is_array($capabilities) ? array_values(array_filter(array_map('strval', $capabilities))) : [];
            if (!in_array($capability, $capabilities, true)) {
                continue;
            }
            $adapterConfig = json_decode((string) ($item['adapter_config_json'] ?? '{}'), true);
            $adapterConfig = is_array($adapterConfig) ? $adapterConfig : [];
            $limits = json_decode((string) ($item['limits_json'] ?? '{}'), true);
            $limits = is_array($limits) ? $limits : [];
            $capabilityRelation = Db::name('ai_model_capabilities')
                ->where('ai_model_id', (int) $item['id'])
                ->where('capability_code', $capability)
                ->where('confirmation_status', 'confirmed')
                ->where('test_status', 'passed')
                ->find();
            if (!$capabilityRelation
                || ($adapterConfig['capability_tests'][$capability]['status'] ?? '') !== 'ready'
                || (string) ($limits['release_stage'] ?? 'candidate') !== 'active') {
                continue;
            }
            $modelId = trim((string) ($item['model_id'] ?? ''));
            if ($modelId === '') {
                continue;
            }
            $available[] = [
                'id' => $modelId,
                'name' => trim((string) ($item['display_name'] ?? '')) ?: $modelId,
                'provider' => trim((string) ($item['provider'] ?? '')),
            ];
        }
        return $available;
    }

    public function hasAvailableModels(
        ?string $model = null,
        ?int $modelId = null,
        ?string $gateway = null,
        ?string $capability = null
    ): bool
    {
        $targetModel = trim((string) ($model ?? $this->defaultModel()));
        if (($modelId === null || $modelId <= 0) && $targetModel !== '') {
            $modelId = max(0, (int) Db::name('ai_models')->where('model_id', $targetModel)->value('id')) ?: null;
        }
        if ($modelId !== null && $modelId > 0) {
            if ($capability !== null && $capability !== '') {
                if ($this->mediaExecution->hasCapabilityRoute($modelId, $capability)) {
                    return true;
                }
            } elseif ($this->mediaExecution->hasCapabilityRoute($modelId, AiCapability::IMAGE_GENERATION)
                || $this->mediaExecution->hasCapabilityRoute($modelId, AiCapability::IMAGE_EDIT)) {
                return true;
            }
        }
        return false;
    }

    public function generateImage(
        string $model,
        string $prompt,
        string $size = '1024x1024',
        ?int $modelId = null,
        ?string $gateway = null,
        array $context = []
    ): array
    {
        if (array_key_exists('expected_reference_count', $context)
            && (int) $context['expected_reference_count'] !== 0) {
            throw new \RuntimeException('无参考图生成请求与冻结参考图数量不一致，已停止调用上游。');
        }
        if (!in_array($size, ['1024x1024', '1536x1024', '1024x1536'], true)) {
            $size = '1024x1024';
        }
        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => 1,
            'size' => $size,
            'response_format' => 'b64_json',
        ];
        foreach (['image', 'images', 'reference_image', 'reference_images', 'conversation', 'thread', 'previous_response_id'] as $forbidden) {
            if (array_key_exists($forbidden, $payload)) {
                throw new \RuntimeException('无参考图请求包含不允许的上游字段，已停止调用。');
            }
        }
        $sub2Api = $this->requestSub2ApiImage(
            AiCapability::IMAGE_GENERATION,
            $modelId,
            '/v1/images/generations',
            $payload,
            $context,
            false
        );
        if ($sub2Api !== null) {
            return $sub2Api;
        }
        $direct = $this->mediaExecution->requestJson(
            AiCapability::IMAGE_GENERATION,
            $modelId,
            'POST',
            '/v1/images/generations',
            $payload,
            450,
            $context
        );
        if (!empty($direct['handled'])) {
            return $direct;
        }
        return $this->sub2ApiUnavailable();
    }

    public function createVideo(
        string $model,
        string $prompt,
        array $referenceImages = [],
        string $ratio = '9:16',
        string $resolution = '720p',
        int $duration = 5,
        bool $generateAudio = false,
        ?string $idempotencyKey = null,
        ?int $modelId = null,
        ?string $gateway = null,
        array $context = []
    ): array {
        $ratio = in_array($ratio, ['1:1', '16:9', '9:16', '4:3', '3:4'], true) ? $ratio : '9:16';
        $resolution = in_array($resolution, ['720p', '1080p'], true) ? $resolution : '720p';
        $duration = max(5, min(10, $duration));
        $references = array_values(array_slice(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $referenceImages
        )), 0, 6));
        $headers = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? ['Idempotency-Key' => trim($idempotencyKey)]
            : [];

        $payload = [
            'model' => trim($model),
            'prompt' => trim($prompt),
            'image' => $references,
            'ratio' => $ratio,
            'resolution' => $resolution,
            'duration' => $duration,
            'generate_audio' => $generateAudio,
            'idempotency_key' => $idempotencyKey,
        ];
        $capability = $references === []
            ? AiCapability::VIDEO_TEXT_TO_VIDEO
            : AiCapability::VIDEO_IMAGE_TO_VIDEO;
        $direct = $this->mediaExecution->requestJson(
            $capability,
            $modelId,
            'POST',
            '/v1/videos/generations',
            $payload,
            180,
            $context + ['extra_headers' => $headers]
        );
        if (!empty($direct['handled'])) {
            return $direct;
        }
        return $this->sub2ApiUnavailable();
    }

    public function videoStatus(
        string $taskId,
        ?string $gateway = null,
        ?int $modelId = null,
        ?string $model = null,
        string $capability = AiCapability::VIDEO_TEXT_TO_VIDEO,
        array $context = []
    ): array
    {
        $taskId = trim($taskId);
        if ($taskId === '' || preg_match('/^[A-Za-z0-9._:-]{1,190}$/', $taskId) !== 1) {
            return ['ok' => false, 'message' => '视频任务 ID 无效。', 'status' => 0, 'retryAfter' => 0];
        }
        $direct = $this->mediaExecution->requestJson(
            $capability,
            $modelId,
            'GET',
            '/v1/videos/' . rawurlencode($taskId),
            null,
            90,
            $context
        );
        if (!empty($direct['handled'])) {
            return $direct;
        }
        return $this->sub2ApiUnavailable();
    }

    public function editImage(
        string $model,
        string $prompt,
        array $image,
        ?array $mask = null,
        string $size = '1024x1024',
        ?int $modelId = null,
        ?string $gateway = null,
        array $context = []
    ): array
    {
        $transportContent = (string) ($image['content'] ?? '');
        if (array_key_exists('outbound_reference_sha256', $context)) {
            $expectedTransportFingerprint = trim((string) $context['outbound_reference_sha256']);
            if ($transportContent === ''
                || preg_match('/^[a-f0-9]{64}$/', $expectedTransportFingerprint) !== 1
                || !hash_equals($expectedTransportFingerprint, hash('sha256', $transportContent))) {
                throw new \RuntimeException('实际上传的参考图画板与本任务冻结指纹不一致，已停止调用上游。');
            }
        }
        if (array_key_exists('expected_reference_count', $context)) {
            $expectedCount = max(0, (int) $context['expected_reference_count']);
            $originals = is_array($image['_reference_originals'] ?? null)
                ? $image['_reference_originals']
                : [$image];
            $actualFingerprints = [];
            foreach ($originals as $original) {
                $content = is_array($original) ? (string) ($original['content'] ?? '') : '';
                if ($content !== '') {
                    $actualFingerprints[] = hash('sha256', $content);
                }
            }
            $expectedFingerprints = array_values(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                is_array($context['reference_fingerprints'] ?? null) ? $context['reference_fingerprints'] : []
            )));
            sort($actualFingerprints);
            sort($expectedFingerprints);
            if ($expectedCount <= 0
                || $expectedCount !== count($actualFingerprints)
                || $actualFingerprints !== $expectedFingerprints) {
                throw new \RuntimeException('参考图编辑请求与冻结内容指纹不一致，已停止调用上游。');
            }
        }
        if (!in_array($size, ['1024x1024', '1536x1024', '1024x1536'], true)) {
            $size = '1024x1024';
        }
        $temporary = tempnam(sys_get_temp_dir(), 'asset-edit-');
        if ($temporary === false || file_put_contents($temporary, $image['content'], LOCK_EX) === false) {
            throw new \RuntimeException('无法准备商品参考图');
        }
        $temporaryMask = null;
        try {
            $payload = [
                'model' => $model,
                'prompt' => $prompt,
                'n' => '1',
                'size' => $size,
                'response_format' => 'b64_json',
                'image' => curl_file_create($temporary, (string) $image['mime'], (string) $image['filename']),
            ];
            if ($mask !== null) {
                $temporaryMask = tempnam(sys_get_temp_dir(), 'asset-mask-');
                if ($temporaryMask === false || file_put_contents($temporaryMask, (string) $mask['content'], LOCK_EX) === false) {
                    throw new \RuntimeException('无法准备画布遮罩');
                }
                $payload['mask'] = curl_file_create($temporaryMask, (string) $mask['mime'], (string) $mask['filename']);
            }

            $sub2Api = $this->requestSub2ApiImage(
                AiCapability::IMAGE_EDIT,
                $modelId,
                '/v1/images/edits',
                $payload,
                $context,
                true
            );
            if ($sub2Api !== null) {
                return $sub2Api;
            }
            $direct = $this->mediaExecution->requestMultipart(
                AiCapability::IMAGE_EDIT,
                $modelId,
                '/v1/images/edits',
                $payload,
                450,
                $context
            );
            if (!empty($direct['handled'])) {
                return $direct;
            }
            return $this->sub2ApiUnavailable();
        } finally {
            @unlink($temporary);
            if ($temporaryMask !== null) {
                @unlink($temporaryMask);
            }
        }
    }

    private function requestSub2ApiImage(
        string $capability,
        ?int $modelId,
        string $path,
        array $payload,
        array $context,
        bool $multipart
    ): ?array {
        $merchantId = isset($context['merchant_id']) ? (int) $context['merchant_id'] : null;
        if (!$this->sub2Api->enabledFor($merchantId, $capability, (string) ($payload['model'] ?? ''))) {
            return null;
        }
        $startedAt = date('Y-m-d H:i:s');
        $result = $multipart
            ? $this->sub2Api->requestMultipart($capability, $path, $payload, $context)
            : $this->sub2Api->requestJson($capability, $path, $payload, $context);
        $this->callRecorder->record(
            $capability,
            $modelId,
            $result,
            max(0, (int) ($result['durationMs'] ?? 0)),
            !empty($result['ok']) ? 'success' : 'failed',
            array_merge($context, [
                'payload' => $payload,
                'attempt_no' => 1,
                'route_role' => 'sub2api_primary',
                'protocol' => $capability === AiCapability::IMAGE_EDIT ? 'image_edits' : 'images',
                'started_at' => $startedAt,
                'finished_at' => date('Y-m-d H:i:s'),
            ])
        );
        return $result;
    }

    private function sub2ApiUnavailable(): array
    {
        return [
            'handled' => true,
            'ok' => false,
            'message' => '当前模型尚未在 Sub2API 中发布或服务暂时不可用，任务未发送，也不会产生模型调用费用。',
            'status' => 503,
            'retryAfter' => 5,
            'sub2ApiExecution' => true,
            'sub2ApiAttempts' => [],
        ];
    }

    private function classifyModel(string $model): array
    {
        $normalizedModel = strtolower(trim($model));
        $glmImageModel = preg_match('/glm[-_.]?image/i', $normalizedModel) === 1
            || $normalizedModel === 'gpt-image-glm';
        $imageGeneration = (bool) preg_match('/(gpt-image|dall-e|imagen|flux|ideogram|stable[-_.]?diffusion|sdxl|seedream|glm[-_.]?image|image)/i', $model);
        $imageEdit = (bool) preg_match('/(gpt-image|dall-e-2|image[-_.]?edit|flux.*fill|imagen|seedream)/i', $model)
            && !$glmImageModel;
        $videoImageToVideo = (bool) preg_match('/(seedance|video[-_.]?(?:i2v|image)|image[-_.]?to[-_.]?video)/i', $model);
        $videoTextToVideo = (bool) preg_match('/(seedance|video[-_.]?(?:t2v|generation)|text[-_.]?to[-_.]?video)/i', $model);
        return [
            'imageGeneration' => $imageGeneration,
            'imageEdit' => $imageEdit,
            'videoImageToVideo' => $videoImageToVideo,
            'videoTextToVideo' => $videoTextToVideo,
        ];
    }

    private function retryAfterSeconds(array $headers): int
    {
        $value = trim((string) ($headers['retry-after'] ?? ''));
        if ($value === '') {
            return 0;
        }
        if (ctype_digit($value)) {
            return min(30, max(0, (int) $value));
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? 0 : min(30, max(0, $timestamp - time()));
    }

    private function friendlyError(string $message): string
    {
        if (str_contains($message, 'model is not supported when using Codex with a ChatGPT account')
            || (str_contains($message, 'not supported when using Codex') && str_contains($message, 'ChatGPT account'))) {
            return '当前 Sub2API 模型不支持图片生成，请联系管理员检查模型发布能力。';
        }
        if (str_contains($message, 'No available channel for model')) {
            return '当前图像模型尚未在 Sub2API 发布或暂时不可用。';
        }
        if ((str_contains($message, 'images/edits') || str_contains(strtolower($message), 'image edit'))
            && (str_contains($message, '404') || str_contains(strtolower($message), 'not supported'))) {
            return '当前模型渠道不支持图片编辑接口。请在管理后台关闭参考图能力，或切换到支持 /v1/images/edits 的图像模型。';
        }
        if (str_contains($message, 'HTTP 405') || stripos($message, 'Method Not Allowed') !== false) {
            return '当前图片渠道不接受参考图编辑请求（HTTP 405）。请停用错误渠道，或切换到支持 /v1/images/edits 的模型后重试。';
        }
        if (str_contains($message, 'HTTP 524') || str_contains($message, 'error code: 524')) {
            return '图片上游处理超时（HTTP 524）。请检查上游 Cloudflare 代理超时设置，或改用响应更快的图片生成接口。';
        }
        if (str_contains($message, 'HTTP 504') || str_contains($message, '"code":504') || str_contains($message, '当前连接不稳定')) {
            return '图片源站处理超时（HTTP 504），请稍后重试或检查上游生成服务状态。';
        }
        if (str_contains($message, 'HTTP 429') || str_contains($message, 'status code 429')
            || stripos($message, 'too many requests') !== false || stripos($message, 'rate limit') !== false) {
            return '图片上游当前请求过多（HTTP 429），系统会自动等待后重试。';
        }

        return $message;
    }
}
