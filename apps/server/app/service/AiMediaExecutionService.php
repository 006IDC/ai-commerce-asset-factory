<?php

declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Compatibility facade for media callers that still use the historical
 * AiMediaExecutionService contract. Physical account selection belongs to
 * Sub2API; this class never reads or schedules ThinkPHP provider accounts.
 */
final class AiMediaExecutionService
{
    public function __construct(
        private readonly Sub2ApiClient $sub2Api,
        private readonly AiModelCallRecorder $calls
    ) {
    }

    public function configuration(): array
    {
        $configuration = $this->sub2Api->configuration();
        return [
            'configured' => (bool) ($configuration['configured'] ?? false),
            'gateway' => 'sub2api',
        ];
    }

    public function configured(): bool
    {
        return !empty($this->configuration()['configured']);
    }

    public function hasCapabilityRoute(?int $modelId, string $capability): bool
    {
        $model = $this->model($modelId);
        if ($model === null || !$this->supports($model, $capability)) {
            return false;
        }
        return $this->sub2Api->enabledFor(
            null,
            $capability,
            (string) $model['model_id']
        );
    }

    public function testModel(string $capability, array $allowedModels = [], ?int $requestedModelId = null): ?array
    {
        $allowedModels = array_values(array_filter(array_unique(array_map(
            static fn (mixed $model): string => trim((string) $model),
            $allowedModels
        ))));
        $runtime = $this->sub2Api->runtimeStatus();
        if ((string) ($runtime['status'] ?? '') !== 'ready') {
            return null;
        }
        $published = array_fill_keys(array_map('strval', (array) ($runtime['models'] ?? [])), true);
        foreach (Db::name('ai_models')->where('enabled', 1)->order('sort_order', 'asc')->order('id', 'asc')->select()->toArray() as $model) {
            $modelName = trim((string) ($model['model_id'] ?? ''));
            if (($requestedModelId !== null && $requestedModelId > 0 && (int) $model['id'] !== $requestedModelId)
                || (int) ($model['source_visible'] ?? 1) !== 1
                || $modelName === ''
                || !isset($published[$modelName])
                || ($allowedModels !== [] && !in_array($modelName, $allowedModels, true))
                || !$this->supports($model, $capability)
                || !$this->sub2Api->enabledFor(null, $capability, $modelName)) {
                continue;
            }
            return [
                'id' => (int) $model['id'],
                'model_id' => $modelName,
                'display_name' => (string) ($model['display_name'] ?? $modelName),
                'upstream_model_id' => $modelName,
            ];
        }
        return null;
    }

    public function hasRoute(?int $modelId, string $profile, array $allowedAdapters = []): bool
    {
        $capability = match (strtolower(trim($profile))) {
            'image_edits' => AiCapability::IMAGE_EDIT,
            'video_async' => AiCapability::VIDEO_IMAGE_TO_VIDEO,
            default => AiCapability::IMAGE_GENERATION,
        };
        return $this->hasCapabilityRoute($modelId, $capability);
    }

    public function requestJson(
        string $capability,
        ?int $modelId,
        string $method,
        string $path,
        ?array $payload,
        int $timeout,
        array $context = []
    ): array {
        return $this->execute($capability, $modelId, $method, $path, $payload, $timeout, $context, false);
    }

    public function requestMultipart(
        string $capability,
        ?int $modelId,
        string $path,
        array $payload,
        int $timeout,
        array $context = []
    ): array {
        return $this->execute($capability, $modelId, 'POST', $path, $payload, $timeout, $context, true);
    }

    private function execute(
        string $capability,
        ?int $modelId,
        string $method,
        string $path,
        ?array $payload,
        int $timeout,
        array $context,
        bool $multipart
    ): array {
        $model = $this->model($modelId);
        if ($model === null || !$this->supports($model, $capability)) {
            return [
                'handled' => false,
                'ok' => false,
                'message' => '产品模型不存在或不支持当前能力。',
                'status' => 0,
            ];
        }
        $modelName = trim((string) $model['model_id']);
        if (!$this->sub2Api->enabledFor(
            isset($context['merchant_id']) ? (int) $context['merchant_id'] : null,
            $capability,
            $modelName
        )) {
            return [
                'handled' => false,
                'ok' => false,
                'message' => '产品模型尚未发布到 Sub2API。',
                'status' => 0,
            ];
        }
        if ($payload !== null) {
            $payload['model'] = trim((string) ($payload['model'] ?? '')) ?: $modelName;
        }

        $startedAt = date('Y-m-d H:i:s');
        $requestContext = $context + ['model' => $modelName];
        $requestContext['timeout_seconds'] = max(10, min(600, $timeout));
        $result = $multipart
            ? $this->sub2Api->requestMultipart($capability, $path, $payload ?? [], $requestContext)
            : $this->sub2Api->requestJsonMethod($capability, $method, $path, $payload, $requestContext);
        $this->calls->record(
            $capability,
            (int) $model['id'],
            $result,
            (int) ($result['durationMs'] ?? 0),
            !empty($result['ok']) ? 'success' : 'failed',
            $context + [
                'payload' => $payload,
                'attempt_no' => 1,
                'route_role' => 'sub2api_primary',
                'protocol' => $this->profile($capability),
                'started_at' => $startedAt,
                'finished_at' => date('Y-m-d H:i:s'),
            ]
        );
        $result['handled'] = true;
        $result['resolvedUpstreamModel'] = $modelName;
        return $result;
    }

    private function model(?int $modelId): ?array
    {
        if ($modelId === null || $modelId <= 0) {
            return null;
        }
        $model = Db::name('ai_models')
            ->where('id', $modelId)
            ->where('enabled', 1)
            ->where('source_visible', 1)
            ->whereNull('archived_at')
            ->find();
        return $model ?: null;
    }

    private function supports(array $model, string $capability): bool
    {
        $capabilities = json_decode((string) ($model['capabilities_json'] ?? '[]'), true);
        return is_array($capabilities) && in_array($capability, array_map('strval', $capabilities), true);
    }

    private function profile(string $capability): string
    {
        return match ($capability) {
            AiCapability::IMAGE_EDIT => 'image_edits',
            AiCapability::VIDEO_IMAGE_TO_VIDEO,
            AiCapability::VIDEO_TEXT_TO_VIDEO => 'video_async',
            default => 'images',
        };
    }
}
