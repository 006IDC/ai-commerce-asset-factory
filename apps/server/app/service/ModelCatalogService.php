<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use InvalidArgumentException;
use think\facade\Db;

final class ModelCatalogService
{
    private const PROTOCOLS = ['chat', 'moderations', 'image_generation', 'image_edit', 'video_async'];
    private const RELEASE_STAGES = ['candidate', 'active', 'archived'];
    private const CREATIVE_POOL_CAPABILITIES = [
        AiCapability::IMAGE_GENERATION,
        AiCapability::IMAGE_EDIT,
        AiCapability::VIDEO_IMAGE_TO_VIDEO,
        AiCapability::VIDEO_TEXT_TO_VIDEO,
    ];

    public function __construct(private readonly SystemSettings $settings)
    {
    }

    public function enforcePlatformFundedInternalPrices(): int
    {
        $capabilities = array_values(array_filter(
            AiCapability::codes(),
            static fn (string $capability): bool => !AiCapability::isMerchantBillable($capability)
        ));
        if ($capabilities === []) {
            return 0;
        }
        return Db::name('ai_model_prices')->whereIn('capability_code', $capabilities)->update([
            'unit_type' => 'request',
            'unit_value' => 1,
            'credits' => 0,
            'config_json' => json_encode(['strategy' => 'platform'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'enabled' => 1,
            'updated_at' => Web::now(),
        ]);
    }

    public function ensureChannelModelCandidate(string $modelId, string $provider, string $endpointProfile): int
    {
        $modelId = trim($modelId);
        if ($modelId === '' || mb_strlen($modelId) > 190) {
            throw new InvalidArgumentException('请填写有效的上游 Model ID。');
        }
        $provider = strtolower(trim($provider));
        $gateway = in_array($provider, ['deepseek', 'zhipu', 'volcengine', 'openai'], true)
            ? $provider
            : 'openai';
        [$protocol, $capabilities] = match (strtolower(trim($endpointProfile))) {
            'images' => ['image_generation', [AiCapability::IMAGE_GENERATION]],
            'image_edits' => ['image_edit', [AiCapability::IMAGE_EDIT]],
            'video_async' => ['video_async', [AiCapability::VIDEO_IMAGE_TO_VIDEO, AiCapability::VIDEO_TEXT_TO_VIDEO]],
            default => ['chat', []],
        };
        return $this->ensureModel(
            $modelId,
            $provider,
            $protocol,
            $capabilities,
            ['source' => 'sub2api', 'endpoint_profile' => $endpointProfile],
            true,
            $gateway
        );
    }

    public function dashboard(): array
    {
        $models = Db::name('ai_models')->order('sort_order', 'asc')->order('id', 'asc')->select()->toArray();
        foreach ($models as &$model) {
            $model['capabilities'] = $this->decodeList($model['capabilities_json'] ?? '[]');
            $model['input_modalities'] = $this->decodeList($model['input_modalities_json'] ?? '[]');
            $model['output_modalities'] = $this->decodeList($model['output_modalities_json'] ?? '[]');
            $model['capability_tests'] = $this->capabilityTests($model['adapter_config_json'] ?? '{}');
            $model['prices'] = $this->prices((int) $model['id']);
            $model += $this->releasePolicy($model['limits_json'] ?? '{}');
        }
        unset($model);

        $routes = [];
        foreach (Db::name('ai_capability_routes')->select()->toArray() as $route) {
            $config = json_decode((string) ($route['route_config_json'] ?? '{}'), true);
            $config = is_array($config) ? $config : [];
            $poolIds = array_values(array_unique(array_filter(
                array_map('intval', (array) ($config['pool_model_ids'] ?? [])),
                static fn (int $id): bool => $id > 0
            )));
            if (in_array((string) $route['capability_code'], self::CREATIVE_POOL_CAPABILITIES, true) && $poolIds === []) {
                $poolIds = array_values(array_unique(array_filter([
                    (int) ($route['primary_model_id'] ?? 0),
                    (int) ($route['fallback_model_id'] ?? 0),
                    ...array_map(
                        static fn (array $model): int => (int) $model['id'],
                        array_filter($models, static fn (array $model): bool =>
                            (int) $model['user_selectable'] === 1
                            && in_array((string) $route['capability_code'], $model['capabilities'], true)
                        )
                    ),
                ], static fn (int $id): bool => $id > 0)));
            }
            $route['route_config'] = $config;
            $route['pool_model_ids'] = $poolIds;
            $route['default_model_id'] = max(0, (int) ($config['default_model_id'] ?? $route['primary_model_id'] ?? 0));
            $routes[(string) $route['capability_code']] = $route;
        }
        $options = [];
        foreach (AiCapability::codes() as $capability) {
            $options[$capability] = array_values(array_filter($models, static fn (array $model): bool =>
                (int) $model['enabled'] === 1 && in_array($capability, $model['capabilities'], true)
            ));
        }

        return [
            'models' => $models,
            'routes' => $routes,
            'options' => $options,
            'definitions' => AiCapability::definitions(),
            'routingMode' => $this->routingMode(),
            'stats' => [
                'models' => count($models),
                'enabled' => count(array_filter($models, static fn (array $model): bool => (int) $model['enabled'] === 1)),
                'userSelectable' => count(array_filter($models, static fn (array $model): bool => (int) $model['user_selectable'] === 1)),
                'ready' => count(array_filter($models, static fn (array $model): bool => $model['health_status'] === 'ready')),
            ],
        ];
    }

    public function model(int $id): ?array
    {
        $model = Db::name('ai_models')->where('id', $id)->find();
        if (!$model) {
            return null;
        }
        $model['capabilities'] = $this->decodeList($model['capabilities_json'] ?? '[]');
        $model['input_modalities'] = $this->decodeList($model['input_modalities_json'] ?? '[]');
        $model['output_modalities'] = $this->decodeList($model['output_modalities_json'] ?? '[]');
        $model['capability_tests'] = $this->capabilityTests($model['adapter_config_json'] ?? '{}');
        $model['prices'] = $this->prices($id);
        $model['limits'] = json_decode((string) ($model['limits_json'] ?? '{}'), true) ?: [];
        $model += $this->releasePolicy($model['limits']);
        $finishedJobs = Db::name('generation_jobs')->where('model', (string) $model['model_id'])->whereIn('status', ['completed', 'partial', 'failed', 'cancelled'])->count();
        $successfulJobs = Db::name('generation_jobs')->where('model', (string) $model['model_id'])->whereIn('status', ['completed', 'partial'])->count();
        $model['generation_stats'] = [
            'jobs' => (int) Db::name('generation_jobs')->where('model', (string) $model['model_id'])->count(),
            'successful_jobs' => $successfulJobs,
            'success_rate' => $finishedJobs > 0 ? round($successfulJobs * 100 / $finishedJobs, 1) : null,
            'actual_credits' => (int) Db::name('generation_jobs')->where('model', (string) $model['model_id'])->sum('actual_credits'),
        ];
        $model['recent_calls'] = Db::name('ai_model_calls')->where('ai_model_id', $id)->order('id', 'desc')->limit(12)->select()->toArray();
        return $model;
    }

    public function updateModel(int $id, array $input): array
    {
        $model = $this->model($id);
        if (!$model) {
            throw new InvalidArgumentException('模型不存在。');
        }
        $displayName = trim((string) ($input['display_name'] ?? ''));
        $provider = trim((string) ($input['provider'] ?? ''));
        $protocol = trim((string) ($input['protocol'] ?? 'chat'));
        $capabilities = array_values(array_unique(array_filter(array_map('strval', (array) ($input['capabilities'] ?? [])), [AiCapability::class, 'exists'])));
        if ($displayName === '' || mb_strlen($displayName) > 160) {
            throw new InvalidArgumentException('前台名称不能为空且不能超过 160 个字符。');
        }
        if (mb_strlen($provider) > 80 || !in_array($protocol, self::PROTOCOLS, true)) {
            throw new InvalidArgumentException('模型提供方或调用协议不正确。');
        }
        $userSelectable = $this->modelInCreativePool($id)
            && array_filter($capabilities, [AiCapability::class, 'allowsUserSelection']) !== [];
        [$inputModalities, $outputModalities] = $this->modalities($capabilities);
        $adapterConfig = json_decode((string) ($model['adapter_config_json'] ?? '{}'), true);
        $adapterConfig = is_array($adapterConfig) ? $adapterConfig : [];
        $tests = array_intersect_key($this->capabilityTests($adapterConfig), array_flip($capabilities));
        $adapterConfig['capability_tests'] = $tests;
        $health = $this->aggregateHealth($capabilities, $tests);
        $currentLimits = json_decode((string) ($model['limits_json'] ?? '{}'), true);
        $limits = is_array($currentLimits) ? $currentLimits : [];
        $limits['minimum_tier'] = max(0, min(999, (int) ($input['minimum_tier'] ?? 0)));
        $limits['merchant_description'] = mb_strimwidth(trim((string) ($input['merchant_description'] ?? '')), 0, 180, '');
        if (array_key_exists('upstream_cost_mode', $input)) {
            $costMode = trim((string) $input['upstream_cost_mode']);
            if (!in_array($costMode, ['unconfigured', 'free', 'resource_pack', 'paid'], true)) {
                throw new InvalidArgumentException('上游成本模式不正确。');
            }
            $costValues = [];
            foreach (['input_cost_per_million', 'output_cost_per_million', 'fixed_cost_per_request'] as $field) {
                $raw = trim((string) ($input[$field] ?? ''));
                if ($raw !== '' && !is_numeric($raw)) {
                    throw new InvalidArgumentException('上游成本只能填写数字。');
                }
                $costValues[$field] = max(0.0, min(1000000.0, (float) ($raw === '' ? 0 : $raw)));
            }
            $limits['upstream_cost'] = [
                'mode' => $costMode,
                'currency' => 'CNY',
                'input_per_million' => $costValues['input_cost_per_million'],
                'output_per_million' => $costValues['output_cost_per_million'],
                'fixed_per_request' => $costValues['fixed_cost_per_request'],
                'resource_label' => mb_strimwidth(trim((string) ($input['upstream_resource_label'] ?? '')), 0, 120, ''),
            ];
        }
        Db::transaction(function () use ($id, $displayName, $provider, $protocol, $capabilities, $inputModalities, $outputModalities, $userSelectable, $input, $adapterConfig, $health, $limits): void {
            Db::name('ai_models')->where('id', $id)->update([
                'display_name' => $displayName,
                'provider' => $provider !== '' ? $provider : null,
                'protocol' => $protocol,
                'capabilities_json' => json_encode($capabilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'input_modalities_json' => json_encode($inputModalities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'output_modalities_json' => json_encode($outputModalities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'adapter_config_json' => json_encode($adapterConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'limits_json' => json_encode($limits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'health_status' => $health,
                'user_selectable' => $userSelectable ? 1 : 0,
                'enabled' => !empty($input['enabled']) ? 1 : 0,
                'sort_order' => max(0, min(100000, (int) ($input['sort_order'] ?? 0))),
                'updated_at' => Web::now(),
            ]);
            if (array_key_exists('prices', $input)) {
                $this->savePrices($id, (array) $input['prices'], $capabilities);
            }
        });

        return $this->model($id) ?? $model;
    }

    public function updateReleasePolicy(int $id, string $stage, array $grayMerchantIds, int $adminId): array
    {
        $model = $this->model($id);
        if (!$model) {
            throw new InvalidArgumentException('模型不存在。');
        }
        if (!in_array($stage, self::RELEASE_STAGES, true)) {
            throw new InvalidArgumentException('模型上线阶段不正确。');
        }
        if ((int) $model['enabled'] !== 1 && $stage === 'active') {
            throw new InvalidArgumentException('请先启用模型，再进入生产阶段。');
        }

        $readyCapabilities = array_keys(array_filter(
            (array) $model['capability_tests'],
            static fn (array $test): bool => (string) ($test['status'] ?? '') === 'ready'
        ));
        if ($stage !== 'candidate' && $readyCapabilities === []) {
            throw new InvalidArgumentException('模型至少需要一项能力实测通过，才能进入生产阶段。');
        }

        $references = $this->routeReferences($id);
        if ($stage === 'candidate' && ($references['primary'] !== [] || $references['default'] !== [])) {
            throw new InvalidArgumentException(
                '该模型仍是“' . implode('、', array_values(array_unique(array_merge($references['primary'], $references['default']))))
                . '”的当前模型，请先完成路由替换再降为候选。'
            );
        }
        $limits = is_array($model['limits']) ? $model['limits'] : [];
        $limits['release_stage'] = $stage;
        unset($limits['gray_merchant_ids']);
        $limits['release_updated_at'] = Web::now();
        $limits['release_updated_by_admin_id'] = $adminId;
        Db::name('ai_models')->where('id', $id)->update([
            'limits_json' => json_encode($limits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => Web::now(),
        ]);

        return $this->model($id) ?? $model;
    }

    public function deleteModel(int $id): array
    {
        $model = $this->model($id);
        if (!$model) {
            throw new InvalidArgumentException('模型不存在或已经删除。');
        }
        $references = $this->routeReferences($id);
        $blockingReferences = array_values(array_unique(array_merge(
            $references['primary'],
            $references['fallback'],
            $references['default'],
            $references['pool']
        )));
        if ($blockingReferences !== []) {
            throw new InvalidArgumentException(
                '该模型仍参与“' . implode('、', $blockingReferences) . '”路由，请先替换路由后再删除。'
            );
        }

        Db::name('ai_models')->where('id', $id)->update([
            'enabled' => 0,
            'user_selectable' => 0,
            'lifecycle_status' => 'archived',
            'archived_at' => Web::now(),
            'updated_at' => Web::now(),
        ]);

        return $this->model($id) ?? $model;
    }

    private function prices(int $modelId): array
    {
        $prices = [];
        foreach (Db::name('ai_model_prices')->where('ai_model_id', $modelId)->order('id', 'asc')->select()->toArray() as $price) {
            $prices[(string) $price['capability_code']] = [
                'unit_type' => in_array((string) ($price['unit_type'] ?? ''), ['request', 'image', 'second'], true) ? (string) $price['unit_type'] : 'request',
                'unit_value' => (float) ($price['unit_value'] ?? 1),
                'credits' => max(0, (int) ($price['credits'] ?? 0)),
                'enabled' => (int) ($price['enabled'] ?? 0) === 1,
                'id' => (int) $price['id'],
            ];
        }

        return $prices;
    }

    private function savePrices(int $modelId, array $input, array $capabilities): void
    {
        $now = Web::now();
        foreach (AiCapability::codes() as $capability) {
            $row = is_array($input[$capability] ?? null) ? $input[$capability] : [];
            $existing = Db::name('ai_model_prices')->where('ai_model_id', $modelId)->where('capability_code', $capability)->order('id', 'desc')->find();
            $unitType = (string) ($row['unit_type'] ?? 'request');
            $unitValue = (float) ($row['unit_value'] ?? 1);
            $creditsValue = trim((string) ($row['credits'] ?? ''));
            $enabled = !empty($row['enabled']) && $row['enabled'] === '1';
            $valid = in_array($capability, $capabilities, true)
                && in_array($unitType, ['request', 'image', 'second'], true)
                && $unitValue > 0
                && $creditsValue !== ''
                && preg_match('/^\d+$/', $creditsValue) === 1;

            if (!$existing && !$valid) {
                continue;
            }
            $data = [
                'unit_type' => in_array($unitType, ['request', 'image', 'second'], true) ? $unitType : 'request',
                'unit_value' => $unitValue > 0 ? min(1000000, $unitValue) : 1,
                'credits' => $valid ? min(1000000, (int) $creditsValue) : 0,
                'config_json' => '{}',
                'enabled' => $valid && $enabled ? 1 : 0,
                'updated_at' => $now,
            ];
            if ($existing) {
                Db::name('ai_model_prices')->where('id', $existing['id'])->update($data);
            } else {
                Db::name('ai_model_prices')->insert($data + [
                    'ai_model_id' => $modelId,
                    'capability_code' => $capability,
                    'created_at' => $now,
                ]);
            }
        }
    }

    public function saveCapabilityPrices(array $input): int
    {
        $updated = 0;
        Db::transaction(function () use ($input, &$updated): void {
            foreach ($input as $modelIdValue => $modelPrices) {
                $modelId = max(0, (int) $modelIdValue);
                $modelPrices = is_array($modelPrices) ? $modelPrices : [];
                if ($modelId <= 0 || $modelPrices === []) {
                    continue;
                }
                $model = Db::name('ai_models')->where('id', $modelId)->find();
                if (!$model) {
                    continue;
                }
                $modelCapabilities = $this->decodeList($model['capabilities_json'] ?? '[]');
                foreach ($modelPrices as $capability => $priceInput) {
                    $capability = (string) $capability;
                    if (!AiCapability::exists($capability) || !in_array($capability, $modelCapabilities, true)) {
                        continue;
                    }
                    $row = is_array($priceInput) ? $priceInput : [];
                    $strategyInput = strtolower(trim((string) ($row['strategy'] ?? '')));
                    if ($strategyInput !== '' && !in_array($strategyInput, ['inherit', 'custom', 'platform'], true)) {
                        throw new InvalidArgumentException(
                            $model['display_name'] . ' 的“' . AiCapability::label($capability) . '”价格策略不正确。'
                        );
                    }
                    $creditsValue = trim((string) ($row['credits'] ?? ''));
                    $isInternalCapability = !AiCapability::isMerchantBillable($capability);
                    $legacyEnabled = !empty($row['enabled']) && (string) $row['enabled'] === '1';
                    $strategy = $isInternalCapability
                        ? 'platform'
                        : ($strategyInput !== '' ? $strategyInput : ($legacyEnabled
                            ? ($creditsValue !== '' && preg_match('/^\d+$/', $creditsValue) === 1 && (int) $creditsValue === 0 ? 'platform' : 'custom')
                            : 'inherit'));
                    $existing = Db::name('ai_model_prices')
                        ->where('ai_model_id', $modelId)
                        ->where('capability_code', $capability)
                        ->order('id', 'desc')
                        ->find();
                    if ($strategy === 'inherit') {
                        if ($existing) {
                            Db::name('ai_model_prices')
                                ->where('ai_model_id', $modelId)
                                ->where('capability_code', $capability)
                                ->delete();
                        }
                        $updated++;
                        continue;
                    }
                    if ($strategy === 'platform') {
                        $creditsValue = '0';
                    }
                    if ($creditsValue === '' || preg_match('/^\d+$/', $creditsValue) !== 1 || (int) $creditsValue > 1000000) {
                        throw new InvalidArgumentException(
                            $model['display_name'] . ' 的“' . AiCapability::label($capability) . '”价格必须是 0 到 1000000 之间的整数。'
                        );
                    }
                    if ($strategy === 'custom' && (int) $creditsValue === 0) {
                        throw new InvalidArgumentException(
                            $model['display_name'] . ' 的“' . AiCapability::label($capability) . '”专属价必须大于 0；免费向商户开放请使用“平台承担”。'
                        );
                    }
                    $unitType = trim((string) ($row['unit_type'] ?? $this->defaultPriceUnitType($capability)));
                    if (!in_array($unitType, ['request', 'image', 'second'], true)) {
                        throw new InvalidArgumentException(
                            $model['display_name'] . ' 的“' . AiCapability::label($capability) . '”计量单位不正确。'
                        );
                    }
                    $unitValueRaw = trim((string) ($row['unit_value'] ?? '1'));
                    if (!is_numeric($unitValueRaw) || (float) $unitValueRaw <= 0 || (float) $unitValueRaw > 1000000) {
                        throw new InvalidArgumentException(
                            $model['display_name'] . ' 的“' . AiCapability::label($capability) . '”单位数量必须大于 0。'
                        );
                    }
                    $now = Web::now();
                    $data = [
                        'unit_type' => $unitType,
                        'unit_value' => (float) $unitValueRaw,
                        'credits' => (int) $creditsValue,
                        'config_json' => json_encode(['strategy' => $strategy], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'enabled' => 1,
                        'updated_at' => $now,
                    ];
                    if ($existing) {
                        Db::name('ai_model_prices')->where('id', $existing['id'])->update($data);
                    } else {
                        Db::name('ai_model_prices')->insert($data + [
                            'ai_model_id' => $modelId,
                            'capability_code' => $capability,
                            'created_at' => $now,
                        ]);
                    }
                    $updated++;
                }
            }
        });

        return $updated;
    }

    public function saveCreativePrices(array $input): int
    {
        return $this->saveCapabilityPrices($input);
    }

    private function defaultPriceUnitType(string $capability): string
    {
        return match ($capability) {
            AiCapability::IMAGE_GENERATION, AiCapability::IMAGE_EDIT => 'image',
            AiCapability::VIDEO_IMAGE_TO_VIDEO, AiCapability::VIDEO_TEXT_TO_VIDEO => 'second',
            default => 'request',
        };
    }

    public function saveRoutes(array $routes, int $adminId): void
    {
        Db::transaction(function () use ($routes, $adminId): void {
            $creativePoolModelIds = [];
            foreach (AiCapability::codes() as $capability) {
                $route = is_array($routes[$capability] ?? null) ? $routes[$capability] : [];
                if (in_array($capability, self::CREATIVE_POOL_CAPABILITIES, true)) {
                    $poolIds = array_values(array_unique(array_filter(
                        array_map('intval', (array) ($route['pool'] ?? [])),
                        static fn (int $id): bool => $id > 0
                    )));
                    $defaultId = max(0, (int) ($route['default'] ?? 0));
                    foreach ($poolIds as $modelId) {
                        $this->assertModelSupports($modelId, $capability, '开放模型', true);
                    }
                    if ($defaultId > 0 && !in_array($defaultId, $poolIds, true)) {
                        throw new InvalidArgumentException('“' . AiCapability::label($capability) . '”默认模型必须包含在开放模型池中。');
                    }
                    if ($defaultId === 0 && $poolIds !== []) {
                        $defaultId = $poolIds[0];
                    }
                    if ($defaultId > 0) {
                        $defaultModel = $this->model($defaultId);
                    if (!$defaultModel || (string) ($defaultModel['release_stage'] ?? 'active') !== 'active') {
                        throw new InvalidArgumentException(
                                '“' . AiCapability::label($capability) . '”默认模型必须处于生产阶段。'
                        );
                        }
                    }
                    $this->writeCreativePoolRoute($capability, $poolIds, $defaultId ?: null, $adminId);
                    $creativePoolModelIds = array_merge($creativePoolModelIds, $poolIds);
                    continue;
                }
                $primaryId = max(0, (int) ($route['primary'] ?? 0));
                $this->assertModelSupports($primaryId, $capability, '主模型', true);
                $primaryModel = $primaryId > 0 ? $this->model($primaryId) : null;
                $primaryStage = (string) ($primaryModel['release_stage'] ?? 'active');
                if ($primaryModel && $primaryStage !== 'active') {
                    throw new InvalidArgumentException('候选或归档模型不能作为“' . AiCapability::label($capability) . '”主模型，请先完成真实测试并正式启用。');
                }
                $routeConfig = [];
                $routeConfig['failure_mode'] = 'manual_retry';
                $routeConfig['fallback'] = 'none';
                $this->writeRoute($capability, $primaryId ?: null, null, $adminId, $routeConfig);
            }
            $creativePoolModelIds = array_values(array_unique($creativePoolModelIds));
            foreach (Db::name('ai_models')->field('id,capabilities_json,user_selectable')->select()->toArray() as $model) {
                $capabilities = $this->decodeList($model['capabilities_json'] ?? '[]');
                if (array_intersect($capabilities, self::CREATIVE_POOL_CAPABILITIES) === []) {
                    continue;
                }
                $selectable = in_array((int) $model['id'], $creativePoolModelIds, true) ? 1 : 0;
                if ((int) $model['user_selectable'] !== $selectable) {
                    Db::name('ai_models')->where('id', $model['id'])->update([
                        'user_selectable' => $selectable,
                        'updated_at' => Web::now(),
                    ]);
                }
            }
        });
    }

    public function routingMode(): string
    {
        return $this->settings->get('ai_routing_mode') === 'capability' ? 'capability' : 'legacy';
    }

    public function recordCapabilityTest(int $modelId, string $capability, array $result): array
    {
        $model = $this->model($modelId);
        if (!$model) {
            throw new InvalidArgumentException('模型不存在。');
        }
        if (!AiCapability::exists($capability) || !in_array($capability, $model['capabilities'], true)) {
            throw new InvalidArgumentException('模型未标注该能力，不能执行测试。');
        }

        $adapterConfig = json_decode((string) ($model['adapter_config_json'] ?? '{}'), true);
        $adapterConfig = is_array($adapterConfig) ? $adapterConfig : [];
        $tests = $this->capabilityTests($adapterConfig);
        $ok = !empty($result['ok']);
        $now = Web::now();
        $tests[$capability] = [
            'status' => $ok ? 'ready' : 'unavailable',
            'tested_at' => $now,
            'duration_ms' => max(0, (int) ($result['durationMs'] ?? 0)),
            'request_id' => mb_strimwidth(trim((string) ($result['requestId'] ?? '')), 0, 190, ''),
            'error' => $ok ? '' : mb_strimwidth(trim((string) ($result['message'] ?? '能力测试失败。')), 0, 1000, ''),
        ];
        $adapterConfig['capability_tests'] = $tests;
        $health = $this->aggregateHealth((array) $model['capabilities'], $tests);
        Db::name('ai_models')->where('id', $modelId)->update([
            'adapter_config_json' => json_encode($adapterConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'health_status' => $health,
            'last_error' => $ok ? null : $tests[$capability]['error'],
            'last_tested_at' => $now,
            'updated_at' => $now,
        ]);
        $relation = Db::name('ai_model_capabilities')
            ->where('ai_model_id', $modelId)
            ->where('capability_code', $capability)
            ->find();
        if ($relation) {
            Db::name('ai_model_capabilities')->where('id', (int) $relation['id'])->update([
                'test_status' => $ok ? 'passed' : 'failed',
                'updated_at' => $now,
            ]);
        }

        return $tests[$capability] + ['health_status' => $health];
    }

    public function assertCoreRoutesReady(array $routes): void
    {
        foreach ([AiCapability::SAFETY_TEXT, AiCapability::SAFETY_VISION, AiCapability::PRODUCT_VISION, AiCapability::CONTENT_PLANNER] as $capability) {
            $route = is_array($routes[$capability] ?? null) ? $routes[$capability] : [];
            $this->assertModelSupports(max(0, (int) ($route['primary'] ?? 0)), $capability, '主模型', true);
            if ((int) ($route['primary'] ?? 0) <= 0) {
                throw new InvalidArgumentException('启用能力路由前必须配置“' . AiCapability::label($capability) . '”主模型。');
            }
        }
    }

    private function ensureModel(
        string $modelId,
        string $provider,
        string $protocol,
        array $capabilities,
        array $catalogPayload = [],
        bool $mergeExistingCapabilities = false,
        string $gateway = 'sub2api'
    ): int
    {
        $gateway = in_array($gateway, ['sub2api', 'deepseek', 'zhipu', 'volcengine', 'openai'], true)
            ? $gateway
            : 'sub2api';
        $existing = Db::name('ai_models')->where('gateway', $gateway)->where('model_id', $modelId)->find();
        $now = Web::now();
        if ($existing) {
            $currentCapabilities = $this->decodeList($existing['capabilities_json'] ?? '[]');
            $mergedCapabilities = $mergeExistingCapabilities
                ? array_values(array_unique(array_merge($currentCapabilities, $capabilities)))
                : $currentCapabilities;
            [$inputModalities, $outputModalities] = $this->modalities($mergedCapabilities);
            Db::name('ai_models')->where('id', $existing['id'])->update([
                'provider' => ($existing['provider'] ?? '') !== '' ? $existing['provider'] : ($provider ?: null),
                'capabilities_json' => json_encode($mergedCapabilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'input_modalities_json' => json_encode($inputModalities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'output_modalities_json' => json_encode($outputModalities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'catalog_payload_json' => $catalogPayload !== [] ? json_encode($catalogPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $existing['catalog_payload_json'],
                'updated_at' => $now,
            ]);
            return (int) $existing['id'];
        }
        [$inputModalities, $outputModalities] = $this->modalities($capabilities);
        $limits = [];
        $limits['release_stage'] = 'candidate';
        $id = (int) Db::name('ai_models')->insertGetId([
            'gateway' => $gateway,
            'model_id' => $modelId,
            'display_name' => $modelId,
            'provider' => $provider ?: null, 'protocol' => in_array($protocol, self::PROTOCOLS, true) ? $protocol : 'chat',
            'capabilities_json' => json_encode(array_values(array_unique($capabilities)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'input_modalities_json' => json_encode($inputModalities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'output_modalities_json' => json_encode($outputModalities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'limits_json' => json_encode($limits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'adapter_config_json' => '{}',
            'catalog_payload_json' => $catalogPayload !== [] ? json_encode($catalogPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'user_selectable' => 0,
            'enabled' => 0,
            'sort_order' => 0,
            'health_status' => 'unknown',
            'source_visible' => 1,
            'source_last_seen_at' => $now,
            'lifecycle_status' => 'discovered',
            'archived_at' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        return $id;
    }

    private function writeRoute(string $capability, ?int $primaryId, ?int $fallbackId, ?int $adminId, array $routeConfig = []): void
    {
        $now = Web::now();
        $existing = Db::name('ai_capability_routes')->where('capability_code', $capability)->find();
        $config = $existing ? json_decode((string) ($existing['route_config_json'] ?? '{}'), true) : [];
        $config = is_array($config) ? $config : [];
        if ($routeConfig !== []) {
            $config = array_merge($config, $routeConfig);
        }
        $data = [
            'primary_model_id' => $primaryId, 'fallback_model_id' => $fallbackId,
            'routing_strategy' => 'primary_fallback',
            'route_config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'enabled' => $primaryId !== null ? 1 : 0, 'updated_by_admin_id' => $adminId,
            'updated_at' => $now,
        ];
        if ($existing) {
            Db::name('ai_capability_routes')->where('id', $existing['id'])->update($data);
            return;
        }
        Db::name('ai_capability_routes')->insert($data + ['capability_code' => $capability, 'created_at' => $now]);
    }

    private function writeCreativePoolRoute(string $capability, array $poolIds, ?int $defaultId, ?int $adminId): void
    {
        $now = Web::now();
        $config = [
            'pool_model_ids' => array_values($poolIds),
            'default_model_id' => $defaultId,
        ];
        $data = [
            'primary_model_id' => $defaultId,
            'fallback_model_id' => null,
            'routing_strategy' => 'selectable_pool',
            'route_config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'enabled' => $defaultId !== null ? 1 : 0,
            'updated_by_admin_id' => $adminId,
            'updated_at' => $now,
        ];
        $existing = Db::name('ai_capability_routes')->where('capability_code', $capability)->find();
        if ($existing) {
            Db::name('ai_capability_routes')->where('id', $existing['id'])->update($data);
            return;
        }
        Db::name('ai_capability_routes')->insert($data + ['capability_code' => $capability, 'created_at' => $now]);
    }

    private function modelInCreativePool(int $modelId): bool
    {
        foreach (Db::name('ai_capability_routes')->whereIn('capability_code', self::CREATIVE_POOL_CAPABILITIES)->select()->toArray() as $route) {
            if ((string) ($route['routing_strategy'] ?? '') !== 'selectable_pool') {
                continue;
            }
            $config = json_decode((string) ($route['route_config_json'] ?? '{}'), true);
            $poolIds = is_array($config)
                ? array_map('intval', (array) ($config['pool_model_ids'] ?? []))
                : [];
            if (in_array($modelId, $poolIds, true)) {
                return true;
            }
        }

        return false;
    }

    private function releasePolicy(mixed $limits): array
    {
        $decoded = is_array($limits) ? $limits : json_decode((string) $limits, true);
        $decoded = is_array($decoded) ? $decoded : [];
        $stage = (string) ($decoded['release_stage'] ?? 'active');
        if ($stage === 'gray') {
            $stage = 'candidate';
        }
        if (!in_array($stage, self::RELEASE_STAGES, true)) {
            $stage = 'candidate';
        }

        return [
            'release_stage' => $stage,
            'gray_merchant_ids' => [],
            'release_updated_at' => (string) ($decoded['release_updated_at'] ?? ''),
        ];
    }

    private function routeReferences(int $modelId): array
    {
        $references = ['primary' => [], 'fallback' => [], 'default' => [], 'pool' => []];
        foreach (Db::name('ai_capability_routes')->select()->toArray() as $route) {
            $capability = (string) $route['capability_code'];
            $label = AiCapability::label($capability);
            if ((int) ($route['primary_model_id'] ?? 0) === $modelId) {
                $references['primary'][] = $label;
            }
            if ((int) ($route['fallback_model_id'] ?? 0) === $modelId) {
                $references['fallback'][] = $label;
            }
            $config = json_decode((string) ($route['route_config_json'] ?? '{}'), true);
            $config = is_array($config) ? $config : [];
            if ((int) ($config['default_model_id'] ?? 0) === $modelId) {
                $references['default'][] = $label;
            }
            if (in_array($modelId, array_map('intval', (array) ($config['pool_model_ids'] ?? [])), true)) {
                $references['pool'][] = $label;
            }
        }

        return $references;
    }

    private function assertModelSupports(int $modelId, string $capability, string $label, bool $requireReady): void
    {
        if ($modelId === 0) {
            return;
        }
        $model = $this->model($modelId);
        if (!$model
            || (int) $model['enabled'] !== 1
            || (int) ($model['source_visible'] ?? 1) !== 1
            || !empty($model['archived_at'])
            || !in_array($capability, $model['capabilities'], true)) {
            throw new InvalidArgumentException($label . '未启用或不支持“' . AiCapability::label($capability) . '”。');
        }
        $relation = Db::name('ai_model_capabilities')
            ->where('ai_model_id', $modelId)
            ->where('capability_code', $capability)
            ->where('confirmation_status', 'confirmed')
            ->find();
        $testStatus = $relation
            ? match ((string) ($relation['test_status'] ?? 'pending')) {
                'passed' => 'ready',
                'failed', 'unavailable' => 'unavailable',
                default => 'unknown',
            }
            : 'unknown';
        if ($requireReady && $testStatus !== 'ready') {
            throw new InvalidArgumentException($label . '尚未通过“' . AiCapability::label($capability) . '”能力测试。');
        }
        if (!$requireReady && ($testStatus === 'unavailable' || (string) ($model['health_status'] ?? '') === 'unavailable')) {
            throw new InvalidArgumentException($label . '的“' . AiCapability::label($capability) . '”能力测试未通过。');
        }
    }

    private function modalities(array $capabilities): array
    {
        $input = ['text'];
        $output = [];
        foreach ($capabilities as $capability) {
            if (in_array($capability, [AiCapability::SAFETY_VISION, AiCapability::PRODUCT_VISION, AiCapability::IMAGE_EDIT, AiCapability::VIDEO_IMAGE_TO_VIDEO], true)) {
                $input[] = 'image';
            }
            if (in_array($capability, [AiCapability::SAFETY_TEXT, AiCapability::SAFETY_VISION, AiCapability::PRODUCT_VISION, AiCapability::CONTENT_PLANNER], true)) {
                $output[] = 'text';
            }
            if (in_array($capability, [AiCapability::IMAGE_GENERATION, AiCapability::IMAGE_EDIT], true)) {
                $output[] = 'image';
            }
            if (in_array($capability, [AiCapability::VIDEO_IMAGE_TO_VIDEO, AiCapability::VIDEO_TEXT_TO_VIDEO], true)) {
                $output[] = 'video';
            }
        }
        return [array_values(array_unique($input)), array_values(array_unique($output))];
    }

    private function decodeList(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    private function decodeObject(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
    }

    private function capabilityTests(mixed $value): array
    {
        $config = is_array($value) ? $value : json_decode((string) $value, true);
        $tests = is_array($config) && is_array($config['capability_tests'] ?? null) ? $config['capability_tests'] : [];
        return array_filter($tests, static fn (mixed $test, mixed $capability): bool =>
            is_string($capability) && AiCapability::exists($capability) && is_array($test), ARRAY_FILTER_USE_BOTH
        );
    }

    private function aggregateHealth(array $capabilities, array $tests): string
    {
        $statuses = [];
        foreach ($capabilities as $capability) {
            $statuses[] = (string) ($tests[$capability]['status'] ?? 'unknown');
        }
        if ($statuses === [] || count(array_filter($statuses, static fn (string $status): bool => $status !== 'unknown')) === 0) {
            return 'unknown';
        }
        if (count(array_filter($statuses, static fn (string $status): bool => $status === 'ready')) === count($statuses)) {
            return 'ready';
        }
        if (count(array_filter($statuses, static fn (string $status): bool => $status === 'unavailable')) === count($statuses)) {
            return 'unavailable';
        }
        return 'degraded';
    }
}
