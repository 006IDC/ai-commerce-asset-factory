<?php

declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class AiExecutionService
{
    private const GATEWAY_CAPABILITIES = [
        AiCapability::SAFETY_TEXT,
        AiCapability::CONTENT_PLANNER,
        AiCapability::SAFETY_VISION,
        AiCapability::PRODUCT_VISION,
    ];

    public function __construct(private readonly Sub2ApiClient $sub2Api)
    {
    }

    public function configuration(): array
    {
        $runtime = $this->sub2Api->runtimeStatus();
        return [
            'mode' => 'sub2api',
            'modeLabel' => 'Sub2API 共享网关',
            'nativeCapabilities' => self::GATEWAY_CAPABILITIES,
            'availableCapabilities' => array_combine(
                self::GATEWAY_CAPABILITIES,
                array_map(static fn (string $code): string => AiCapability::label($code), self::GATEWAY_CAPABILITIES)
            ),
            'stats' => [
                'status' => (string) ($runtime['status'] ?? 'offline'),
                'modelCount' => (int) ($runtime['modelCount'] ?? 0),
                'latencyMs' => (int) ($runtime['latencyMs'] ?? 0),
            ],
        ];
    }

    public function nativeCapabilities(): array
    {
        return self::GATEWAY_CAPABILITIES;
    }

    public function normalizeConfiguration(string $mode, array $capabilities): array
    {
        return ['mode' => 'sub2api', 'capabilities' => self::GATEWAY_CAPABILITIES];
    }

    public function assertRoutesReady(array $routes, string $mode, array $capabilities): array
    {
        $configuration = $this->normalizeConfiguration($mode, $capabilities);
        $runtime = $this->sub2Api->runtimeStatus();
        if ((string) ($runtime['status'] ?? '') !== 'ready') {
            throw new \InvalidArgumentException('Sub2API 当前不可用：' . (string) ($runtime['message'] ?? '模型目录无法读取。'));
        }
        $gatewayModels = array_values(array_map('strval', (array) ($runtime['models'] ?? [])));

        foreach ($configuration['capabilities'] as $capability) {
            $route = is_array($routes[$capability] ?? null) ? $routes[$capability] : [];
            $modelId = max(0, (int) ($route['primary'] ?? 0));
            if ($modelId <= 0) {
                throw new \InvalidArgumentException('请先为“' . AiCapability::label($capability) . '”配置主模型。');
            }
            $model = Db::name('ai_models')->where('id', $modelId)->where('enabled', 1)->find();
            if (!$model) {
                throw new \InvalidArgumentException('“' . AiCapability::label($capability) . '”主模型不存在或已停用。');
            }
            $modelCapabilities = json_decode((string) ($model['capabilities_json'] ?? '[]'), true);
            if (!is_array($modelCapabilities) || !in_array($capability, array_map('strval', $modelCapabilities), true)) {
                throw new \InvalidArgumentException(
                    '主模型 ' . (string) $model['model_id'] . ' 不支持“' . AiCapability::label($capability) . '”。'
                );
            }
            if (!in_array((string) $model['model_id'], $gatewayModels, true)) {
                throw new \InvalidArgumentException(
                    '“' . AiCapability::label($capability) . '”主模型 '
                    . (string) $model['model_id']
                    . ' 尚未发布到 Sub2API 服务密钥目录。'
                );
            }
        }
        return $configuration;
    }

    public function usesNative(string $capability): bool
    {
        return in_array($capability, self::GATEWAY_CAPABILITIES, true);
    }

    public function allowsCompatibilityFallback(string $capability): bool
    {
        return false;
    }

    public function request(
        string $capability,
        ?int $modelId,
        string $method,
        string $path,
        ?array $payload,
        int $timeout,
        array $extraHeaders = [],
        array $context = []
    ): array {
        if (!$this->usesNative($capability)) {
            return ['handled' => false, 'ok' => false, 'nativeAttempts' => []];
        }
        if ($modelId === null || $modelId <= 0 || $payload === null) {
            return [
                'handled' => true,
                'ok' => false,
                'message' => '当前任务没有冻结可供 Sub2API 执行的产品模型。',
                'status' => 0,
                'retryAfter' => 0,
                'nativeAttempts' => [],
                'nativeUnavailable' => true,
            ];
        }

        $model = Db::name('ai_models')->where('id', $modelId)->where('enabled', 1)->find();
        if (!$model) {
            return [
                'handled' => true,
                'ok' => false,
                'message' => '产品模型不存在或已停用，任务未发送。',
                'status' => 422,
                'retryAfter' => 0,
                'nativeAttempts' => [],
                'nativeUnavailable' => true,
            ];
        }
        $modelCapabilities = json_decode((string) ($model['capabilities_json'] ?? '[]'), true);
        if (!is_array($modelCapabilities) || !in_array($capability, array_map('strval', $modelCapabilities), true)) {
            return [
                'handled' => true,
                'ok' => false,
                'message' => '产品模型不支持当前能力，任务未发送。',
                'status' => 422,
                'retryAfter' => 0,
                'nativeAttempts' => [],
                'nativeUnavailable' => true,
            ];
        }

        $payload['model'] = (string) $model['model_id'];
        $startedAt = date('Y-m-d H:i:s');
        $requestContext = $context;
        $requestContext['timeout_seconds'] = max(10, min(600, $timeout));
        $result = $this->sub2Api->requestJson($capability, $path, $payload, $requestContext);
        $durationMs = max(0, (int) ($result['durationMs'] ?? 0));
        $result['nativeExecution'] = false;
        $result['sub2apiExecution'] = true;
        $result['upstreamModel'] = (string) $model['model_id'];
        $result['handled'] = true;
        $result['nativeAttempts'] = [[
            'attemptNo' => 1,
            'protocol' => 'sub2api_chat',
            'routeRole' => 'sub2api_primary',
            'startedAt' => $startedAt,
            'finishedAt' => date('Y-m-d H:i:s'),
            'durationMs' => $durationMs,
            'result' => $result,
        ]];
        return $result;
    }
}
