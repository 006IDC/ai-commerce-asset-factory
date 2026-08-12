<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

final class CreativeModelService
{
    public function __construct(
        private readonly ModelCapabilityRouter $router,
        private readonly PricingService $pricing,
        private readonly PlanService $plans,
        private readonly MediaWorkflowEngine $workflows,
        private readonly Sub2ApiClient $sub2Api,
        private readonly AiBusinessCompatibilityService $compatibilities
    ) {
    }

    public function options(
        int $merchantId,
        array $capabilities = [AiCapability::IMAGE_GENERATION, AiCapability::IMAGE_EDIT],
        ?string $productCode = null
    ): array
    {
        $capabilities = array_values(array_filter(array_unique($capabilities), [AiCapability::class, 'allowsUserSelection']));
        $tier = $this->merchantTier($merchantId);
        $configuredPools = $this->configuredPools($capabilities);
        $rows = Db::name('ai_models')
            ->where('enabled', 1)
            ->where('user_selectable', 1)
            ->where('source_visible', 1)
            ->whereNull('archived_at')
            ->order('sort_order', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $options = [];
        foreach ($rows as $row) {
            if (!$this->releasedToMerchant($row, $merchantId)) {
                continue;
            }
            $modelCapabilities = $this->decodeList($row['capabilities_json'] ?? '[]');
            $availableCapabilities = [];
            foreach ($capabilities as $capability) {
                $allowedByPool = !array_key_exists($capability, $configuredPools)
                    || in_array((int) $row['id'], $configuredPools[$capability], true);
                if ($allowedByPool
                    && in_array($capability, $modelCapabilities, true)
                    && $this->testStatus($row, $capability) === 'ready'
                    && $this->hasExecutionRoute($row, $capability, $merchantId)
                    && ($productCode === null || $this->compatibilities->isReady(
                        (int) $row['id'],
                        $capability,
                        $productCode,
                        $this->compatibilities->channelForProduct($productCode)
                    ))) {
                    $availableCapabilities[] = $capability;
                }
            }
            $limits = $this->decodeObject($row['limits_json'] ?? '{}');
            $minimumTier = max(0, (int) ($limits['minimum_tier'] ?? 0));
            if ($availableCapabilities === [] || $minimumTier > $tier) {
                continue;
            }
            $prices = [];
            foreach ($availableCapabilities as $capability) {
                $legacyPrice = $this->legacyPrice($capability);
                $price = $this->pricing->priceForModel(
                    $capability,
                    (string) $row['model_id'],
                    $legacyPrice,
                    (int) $row['id'],
                    (string) ($row['gateway'] ?? '')
                );
                $prices[$capability] = in_array($capability, [AiCapability::VIDEO_IMAGE_TO_VIDEO, AiCapability::VIDEO_TEXT_TO_VIDEO], true)
                    ? $this->pricing->creditsForUnits($price, 1)
                    : (int) $this->plans->generationQuote($merchantId, 1, (int) $price['credits'])['credits'];
            }
            $options[] = [
                'id' => (int) $row['id'],
                'modelId' => (string) $row['model_id'],
                'name' => (string) $row['display_name'],
                'provider' => (string) ($row['provider'] ?? ''),
                'description' => trim((string) ($limits['merchant_description'] ?? '')),
                'minimumTier' => $minimumTier,
                'releaseStage' => $this->releaseStage($limits),
                'isGray' => false,
                'capabilities' => $availableCapabilities,
                'prices' => $prices,
                'supportsGeneration' => in_array(AiCapability::IMAGE_GENERATION, $availableCapabilities, true),
                'supportsEdit' => in_array(AiCapability::IMAGE_EDIT, $availableCapabilities, true),
            ];
        }

        return $options;
    }

    public function resolveTask(
        string $capability,
        ?int $requestedModelId,
        int $merchantId,
        int $legacyPrice,
        string $workflowProfile = 'auto'
    ): array
    {
        if (!AiCapability::allowsUserSelection($capability)) {
            throw new RuntimeException('当前能力不允许商户选择模型。');
        }
        $route = $this->router->resolve($capability, $requestedModelId, $merchantId);
        $model = $this->modelForResolution($route);
        if ($model !== null && !$this->releasedToMerchant($model, $merchantId)) {
            if ($requestedModelId !== null && $requestedModelId > 0) {
                throw new RuntimeException('所选创作模型尚未全量发布，请选择其他模型。');
            }
            $eligible = $this->options($merchantId, [$capability]);
            if ($eligible === []) {
                throw new RuntimeException('当前没有对本商户开放的可用创作模型。');
            }
            $route = $this->router->resolve($capability, (int) $eligible[0]['id'], $merchantId);
            $route['requestedModelId'] = null;
            $route['releaseFallback'] = true;
            $model = $this->modelForResolution($route);
        }
        if ($model !== null) {
            $limits = $this->decodeObject($model['limits_json'] ?? '{}');
            $minimumTier = max(0, (int) ($limits['minimum_tier'] ?? 0));
            if ($minimumTier > $this->merchantTier($merchantId)) {
                throw new RuntimeException('当前套餐暂不支持所选创作模型，请升级套餐或选择其他已开放模型。');
            }
            $route['resolvedModelId'] = (int) $model['id'];
            $route['displayName'] = (string) $model['display_name'];
            $route['provider'] = (string) ($model['provider'] ?? '');
            $route['minimumTier'] = $minimumTier;
        } else {
            $route['displayName'] = (string) $route['resolvedModel'];
            $route['provider'] = '';
            $route['minimumTier'] = 0;
        }
        $resolvedModelId = max(0, (int) ($route['resolvedModelId'] ?? 0));
        if ($resolvedModelId <= 0 || !$this->hasExecutionRoute($model ?? [], $capability, $merchantId)) {
            throw new RuntimeException('当前创作模型尚未发布到 Sub2API，请选择其他模型或联系管理员。');
        }
        $route['capturedAt'] = date('Y-m-d H:i:s');
        if (in_array($capability, [AiCapability::IMAGE_GENERATION, AiCapability::IMAGE_EDIT], true)) {
            $route['mediaWorkflow'] = $this->workflows->snapshotForImage(
                $capability,
                $merchantId,
                (string) $route['resolvedModel'],
                $workflowProfile,
                isset($route['resolvedModelId']) ? (int) $route['resolvedModelId'] : null,
                (string) ($route['resolvedGateway'] ?? 'sub2api')
            );
        }
        $productCode = $this->compatibilities->productForProfile($capability, $workflowProfile);
        $channel = $this->compatibilities->channelForProduct($productCode);
        $this->compatibilities->assertReady($resolvedModelId, $capability, $productCode, $channel);
        $route['businessCompatibility'] = [
            'productCode' => $productCode,
            'executionChannel' => $channel,
            'status' => 'passed',
            'capturedAt' => $route['capturedAt'],
        ];
        $price = $this->pricing->priceForModel(
            $capability,
            (string) $route['resolvedModel'],
            $legacyPrice,
            isset($route['resolvedModelId']) ? (int) $route['resolvedModelId'] : null,
            (string) ($route['resolvedGateway'] ?? '')
        );
        $price['captured_at'] = $route['capturedAt'];
        $price['display_name'] = $route['displayName'];

        return [
            'model' => (string) $route['resolvedModel'],
            'requestedModelId' => $requestedModelId !== null && $requestedModelId > 0 ? $requestedModelId : null,
            'resolvedModelId' => isset($route['resolvedModelId']) ? (int) $route['resolvedModelId'] : null,
            'route' => $route,
            'price' => $price,
        ];
    }

    public function canResolve(string $capability, int $merchantId): bool
    {
        try {
            $this->resolveTask($capability, null, $merchantId, $this->legacyPrice($capability));
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function defaultChoice(string $capability, int $merchantId, int $legacyPrice): array
    {
        $selection = $this->resolveTask($capability, null, $merchantId, $legacyPrice);
        return [
            'id' => max(0, (int) ($selection['resolvedModelId'] ?? 0)),
            'modelId' => (string) $selection['model'],
            'name' => (string) ($selection['route']['displayName'] ?? $selection['model']),
            'price' => (int) ($selection['price']['credits'] ?? $legacyPrice),
        ];
    }

    private function hasExecutionRoute(array $model, string $capability, int $merchantId): bool
    {
        if ((int) ($model['source_visible'] ?? 1) !== 1 || !empty($model['archived_at'])) {
            return false;
        }
        $modelName = trim((string) ($model['model_id'] ?? ''));
        return $this->sub2Api->enabledFor($merchantId, $capability, $modelName);
    }

    private function modelForResolution(array $route): ?array
    {
        $id = max(0, (int) ($route['resolvedModelId'] ?? 0));
        if ($id > 0) {
            $model = Db::name('ai_models')->where('id', $id)->find();
            if ($model) {
                return $model;
            }
        }
        $name = trim((string) ($route['resolvedModel'] ?? ''));
        return $name === '' ? null : (Db::name('ai_models')
            ->where('model_id', $name)
            ->where('enabled', 1)
            ->order('id', 'desc')
            ->find() ?: null);
    }

    private function legacyPrice(string $capability): int
    {
        return in_array($capability, [AiCapability::VIDEO_IMAGE_TO_VIDEO, AiCapability::VIDEO_TEXT_TO_VIDEO], true)
            ? $this->pricing->videoGenerationCreditsPerSecond()
            : $this->pricing->imageGenerationCredits();
    }

    private function merchantTier(int $merchantId): int
    {
        $subscription = $this->plans->currentSubscription($merchantId);
        return $subscription ? max(1, (int) ($subscription['tier_level'] ?? 1)) : 0;
    }

    private function testStatus(array $model, string $capability): string
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
        $config = $this->decodeObject($model['adapter_config_json'] ?? '{}');
        return (string) ($config['capability_tests'][$capability]['status'] ?? 'unknown');
    }

    private function configuredPools(array $capabilities): array
    {
        if ($capabilities === []) {
            return [];
        }
        $pools = [];
        foreach (Db::name('ai_capability_routes')->whereIn('capability_code', $capabilities)->select()->toArray() as $route) {
            if ((string) ($route['routing_strategy'] ?? '') !== 'selectable_pool') {
                continue;
            }
            $config = $this->decodeObject($route['route_config_json'] ?? '{}');
            $pools[(string) $route['capability_code']] = array_values(array_unique(array_filter(
                array_map('intval', (array) ($config['pool_model_ids'] ?? [])),
                static fn (int $id): bool => $id > 0
            )));
        }
        return $pools;
    }

    private function releasedToMerchant(array $model, int $merchantId): bool
    {
        $limits = $this->decodeObject($model['limits_json'] ?? '{}');
        return $this->releaseStage($limits) === 'active';
    }

    private function releaseStage(array $limits): string
    {
        $stage = (string) ($limits['release_stage'] ?? 'active');
        return $stage === 'active' ? 'active' : 'candidate';
    }

    private function decodeList(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    private function decodeObject(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
