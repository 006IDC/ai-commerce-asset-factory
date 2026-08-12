<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

final class ModelCapabilityRouter
{
    public function __construct(private readonly SystemSettings $settings)
    {
    }

    public function resolve(string $capability, ?int $requestedModelId = null, ?int $merchantId = null): array
    {
        if (!AiCapability::exists($capability)) {
            throw new RuntimeException('未知 AI 能力：' . $capability);
        }
        if ($requestedModelId !== null && $requestedModelId > 0) {
            if (!AiCapability::allowsUserSelection($capability)) {
                $requestedModelId = null;
            }
        }
        if ($requestedModelId !== null && $requestedModelId > 0) {
            $requested = $this->model($requestedModelId);
            if (!$requested
                || (int) $requested['enabled'] !== 1
                || (int) $requested['user_selectable'] !== 1
                || (int) ($requested['source_visible'] ?? 1) !== 1
                || !empty($requested['archived_at'])) {
                throw new RuntimeException('所选创作模型当前不可用。');
            }
            if (!$this->releasedToMerchant($requested, $merchantId)) {
                throw new RuntimeException('所选创作模型当前未向此商户开放。');
            }
            if (!AiCapability::allowsUserSelection($capability) || !in_array($capability, $requested['capabilities'], true)) {
                throw new RuntimeException('所选模型不支持当前创作任务。');
            }
            if ($this->capabilityTestStatus($requested, $capability) !== 'ready') {
                throw new RuntimeException('所选创作模型尚未通过当前能力测试。');
            }
            $poolRoute = $this->creativePoolRoute($capability);
            if ($poolRoute !== null && !in_array((int) $requested['id'], $poolRoute['poolModelIds'], true)) {
                throw new RuntimeException('所选创作模型未加入管理员开放的模型池。');
            }
            $resolution = $this->resolution($capability, $requested, null, true);
            if ($poolRoute !== null) {
                $resolution['routingStrategy'] = 'selectable_pool';
                $resolution['poolModelIds'] = $poolRoute['poolModelIds'];
                $resolution['routeVersion'] = 2;
            }
            return $resolution;
        }
        $poolRoute = $this->creativePoolRoute($capability);
        if ($poolRoute !== null) {
            return $this->resolveCreativePool($capability, $poolRoute, $merchantId);
        }
        if (!$this->usesCapabilityRouting($merchantId)) {
            return $this->legacyResolution($capability);
        }

        $route = Db::name('ai_capability_routes')->where('capability_code', $capability)->where('enabled', 1)->find();
        if (!$route) {
            throw new RuntimeException('管理员尚未配置“' . AiCapability::label($capability) . '”模型路由。');
        }
        $primaryModelId = (int) ($route['primary_model_id'] ?? 0);
        $primary = $this->usableModel($primaryModelId, $capability, true, $merchantId);
        $fallback = AiCapability::allowsUserSelection($capability)
            ? null
            : $this->usableModel((int) ($route['fallback_model_id'] ?? 0), $capability, false, $merchantId);
        if (!$primary && !$fallback) {
            throw new RuntimeException(
                AiCapability::allowsUserSelection($capability)
                    ? '“' . AiCapability::label($capability) . '”默认模型当前不可用，请稍后重试或由用户重新选择模型。'
                    : '“' . AiCapability::label($capability) . '”主模型和备用模型均不可用。'
            );
        }
        $routeConfig = json_decode((string) ($route['route_config_json'] ?? '{}'), true);
        $routeConfig = is_array($routeConfig) ? $routeConfig : [];
        $fallbackUsed = $primary === null;
        $resolution = $this->resolution(
            $capability,
            $primary ?? $fallback,
            $fallbackUsed ? null : $fallback,
            false,
            $fallbackUsed,
            $routeConfig
        );
        return $resolution;
    }

    public function usesCapabilityRouting(?int $merchantId = null): bool
    {
        return $this->settings->get('ai_routing_mode') === 'capability';
    }

    public function resolveAdministrativeModel(string $capability, string $modelName): array
    {
        if (!AiCapability::exists($capability)) {
            throw new RuntimeException('未知 AI 能力：' . $capability);
        }
        $model = $this->legacyModelByName(trim($modelName), $capability);
        if ($model === null) {
            throw new RuntimeException('所选模型未启用、未发布或尚未通过“' . AiCapability::label($capability) . '”能力测试。');
        }
        $resolution = $this->resolution($capability, $model, null, false);
        $resolution['routingStrategy'] = 'administrator_selection';
        $resolution['routeVersion'] = 2;
        return $resolution;
    }

    private function legacyResolution(string $capability): array
    {
        [$model, $fallback, $protocol] = match ($capability) {
            AiCapability::SAFETY_TEXT => [$this->settings->get('content_safety_model'), $this->settings->get('content_safety_fallback_model'), 'moderations'],
            AiCapability::SAFETY_VISION => [$this->settings->get('content_safety_fallback_model'), $this->settings->get('default_vision_model'), 'chat'],
            AiCapability::PRODUCT_VISION => [$this->settings->get('default_vision_model'), '', 'chat'],
            AiCapability::CONTENT_PLANNER => [$this->settings->get('deepseek_text_model'), '', 'chat'],
            AiCapability::IMAGE_GENERATION => [$this->settings->get('default_image_model'), '', 'image_generation'],
            AiCapability::IMAGE_EDIT => [$this->settings->get('default_image_model'), '', 'image_edit'],
            default => ['', '', 'video_async'],
        };
        if (trim($model) === '') {
            throw new RuntimeException('旧配置中尚未设置“' . AiCapability::label($capability) . '”模型。');
        }
        $primaryModel = $this->legacyModelByName(trim($model), $capability);
        $fallbackModel = trim($fallback) !== ''
            ? $this->legacyModelByName(trim($fallback), $capability)
            : null;
        $fallbackUsed = false;
        if ($primaryModel === null && $fallbackModel !== null) {
            $primaryModel = $fallbackModel;
            $fallbackModel = null;
            $fallbackUsed = true;
        }
        if ($primaryModel === null) {
            throw new RuntimeException(
                '“' . AiCapability::label($capability) . '”默认模型尚未发布到 Sub2API，请在 AI Console 配置并测试。'
            );
        }
        return [
            'mode' => 'legacy', 'capability' => $capability, 'requestedModelId' => null,
            'resolvedModelId' => (int) $primaryModel['id'],
            'resolvedModel' => (string) $primaryModel['model_id'],
            'fallbackModelId' => $fallbackModel ? (int) $fallbackModel['id'] : null,
            'resolvedGateway' => (string) ($primaryModel['gateway'] ?? 'sub2api'),
            'resolvedProvider' => (string) ($primaryModel['provider'] ?? ''),
            'fallbackModel' => $fallbackModel ? (string) $fallbackModel['model_id'] : '',
            'fallbackGateway' => $fallbackModel ? (string) ($fallbackModel['gateway'] ?? 'sub2api') : '',
            'fallbackProvider' => $fallbackModel ? (string) ($fallbackModel['provider'] ?? '') : '',
            'protocol' => $protocol, 'limits' => [],
            'fallbackUsed' => $fallbackUsed, 'routeVersion' => 2, 'routeConfig' => [],
        ];
    }

    private function legacyModelByName(string $modelName, string $capability): ?array
    {
        $rows = Db::name('ai_models')
            ->where('model_id', $modelName)
            ->where('enabled', 1)
            ->order('id', 'desc')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $model = $this->model((int) $row['id']);
            if ($model === null
                || (int) ($model['source_visible'] ?? 1) !== 1
                || !empty($model['archived_at'])
                || (string) ($model['health_status'] ?? '') === 'unavailable'
                || !in_array($capability, $model['capabilities'], true)
                || $this->capabilityTestStatus($model, $capability) !== 'ready'
                || $this->releaseStage($model) !== 'active') {
                continue;
            }
            return $model;
        }
        return null;
    }

    private function resolution(
        string $capability,
        array $model,
        ?array $fallback,
        bool $requested,
        bool $fallbackUsed = false,
        array $routeConfig = []
    ): array
    {
        $limits = json_decode((string) ($model['limits_json'] ?? '{}'), true);
        return [
            'mode' => 'capability', 'capability' => $capability,
            'requestedModelId' => $requested ? (int) $model['id'] : null,
            'resolvedModelId' => (int) $model['id'], 'resolvedModel' => (string) $model['model_id'],
            'resolvedGateway' => (string) ($model['gateway'] ?? 'sub2api'),
            'resolvedProvider' => (string) ($model['provider'] ?? ''),
            'fallbackModelId' => $fallback ? (int) $fallback['id'] : null,
            'fallbackModel' => $fallback ? (string) $fallback['model_id'] : '',
            'fallbackGateway' => $fallback ? (string) ($fallback['gateway'] ?? 'sub2api') : '',
            'fallbackProvider' => $fallback ? (string) ($fallback['provider'] ?? '') : '',
            'protocol' => $this->protocolForCapability($capability, (string) $model['protocol']),
            'limits' => is_array($limits) ? $limits : [],
            'fallbackUsed' => $fallbackUsed,
            'routeVersion' => $routeConfig === [] ? 1 : 2,
            'routeConfig' => $routeConfig,
        ];
    }

    private function creativePoolRoute(string $capability): ?array
    {
        if (!AiCapability::allowsUserSelection($capability)) {
            return null;
        }
        $route = Db::name('ai_capability_routes')->where('capability_code', $capability)->find();
        if (!$route || (string) ($route['routing_strategy'] ?? '') !== 'selectable_pool') {
            return null;
        }
        $config = json_decode((string) ($route['route_config_json'] ?? '{}'), true);
        $config = is_array($config) ? $config : [];
        $poolIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($config['pool_model_ids'] ?? [])),
            static fn (int $id): bool => $id > 0
        )));
        return [
            'poolModelIds' => $poolIds,
            'defaultModelId' => max(0, (int) ($config['default_model_id'] ?? $route['primary_model_id'] ?? 0)),
        ];
    }

    private function resolveCreativePool(string $capability, array $poolRoute, ?int $merchantId): array
    {
        $poolIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($poolRoute['poolModelIds'] ?? [])),
            static fn (int $id): bool => $id > 0
        )));
        $defaultId = max(0, (int) ($poolRoute['defaultModelId'] ?? 0));
        if ($defaultId <= 0) {
            throw new RuntimeException('“' . AiCapability::label($capability) . '”尚未配置默认模型。');
        }
        $model = $this->usableModel($defaultId, $capability, true, $merchantId);
        if ($model === null) {
            throw new RuntimeException(
                '“' . AiCapability::label($capability) . '”默认模型当前不可用，不会自动切换其他模型。'
            );
        }
        $resolution = $this->resolution($capability, $model, null, false);
        $resolution['routingStrategy'] = 'selectable_pool';
        $resolution['poolModelIds'] = $poolIds;
        $resolution['routeVersion'] = 3;
        return $resolution;
    }

    private function usableModel(int $id, string $capability, bool $requireReady, ?int $merchantId): ?array
    {
        $model = $this->model($id);
        if (!$model
            || (int) $model['enabled'] !== 1
            || (int) ($model['source_visible'] ?? 1) !== 1
            || !empty($model['archived_at'])
            || $model['health_status'] === 'unavailable') {
            return null;
        }
        if (!$this->releasedToMerchant($model, $merchantId)) {
            return null;
        }
        if (!in_array($capability, $model['capabilities'], true)) {
            return null;
        }
        $testStatus = $this->capabilityTestStatus($model, $capability);
        if (($requireReady && $testStatus !== 'ready') || (!$requireReady && $testStatus === 'unavailable')) {
            return null;
        }
        return $model;
    }

    private function releasedToMerchant(array $model, ?int $merchantId): bool
    {
        return $this->releaseStage($model) === 'active';
    }

    private function releaseStage(array $model): string
    {
        $limits = json_decode((string) ($model['limits_json'] ?? '{}'), true);
        $limits = is_array($limits) ? $limits : [];
        $stage = (string) ($limits['release_stage'] ?? 'active');
        return $stage === 'active' ? 'active' : 'candidate';
    }

    private function model(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $model = Db::name('ai_models')->where('id', $id)->find();
        if (!$model) {
            return null;
        }
        $capabilities = json_decode((string) ($model['capabilities_json'] ?? '[]'), true);
        $model['capabilities'] = is_array($capabilities) ? array_values(array_map('strval', $capabilities)) : [];
        return $model;
    }

    private function capabilityTestStatus(array $model, string $capability): string
    {
        try {
            $relation = Db::name('ai_model_capabilities')
                ->where('ai_model_id', (int) ($model['id'] ?? 0))
                ->where('capability_code', $capability)
                ->where('confirmation_status', 'confirmed')
                ->find();
            if ($relation) {
                return match ((string) ($relation['test_status'] ?? 'pending')) {
                    'passed' => 'ready',
                    'failed', 'unavailable' => 'unavailable',
                    default => 'unknown',
                };
            }
            return 'unknown';
        } catch (\Throwable) {
            // Rolling upgrades may briefly use the legacy embedded test state.
        }
        $config = json_decode((string) ($model['adapter_config_json'] ?? '{}'), true);
        return is_array($config) ? (string) ($config['capability_tests'][$capability]['status'] ?? 'unknown') : 'unknown';
    }

    private function protocolForCapability(string $capability, string $modelDefault): string
    {
        return match ($capability) {
            AiCapability::IMAGE_GENERATION => 'image_generation',
            AiCapability::IMAGE_EDIT => 'image_edit',
            AiCapability::VIDEO_IMAGE_TO_VIDEO, AiCapability::VIDEO_TEXT_TO_VIDEO => 'video_async',
            AiCapability::SAFETY_TEXT => $modelDefault === 'moderations' ? 'moderations' : 'chat',
            default => 'chat',
        };
    }
}
