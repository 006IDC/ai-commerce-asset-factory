<?php

declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class AiCapabilityAssignmentService
{
    public function __construct(private readonly ModelCatalogService $legacyCatalog)
    {
    }

    public function dashboard(): array
    {
        $models = Db::name('ai_models')
            ->where('source_visible', 1)
            ->whereNull('archived_at')
            ->order('sort_order', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $options = [];
        $assignedIds = [];
        foreach (Db::name('ai_capability_routes')->where('enabled', 1)->select()->toArray() as $activeRoute) {
            $config = json_decode((string) ($activeRoute['route_config_json'] ?? '{}'), true);
            $config = is_array($config) ? $config : [];
            $assignedIds[] = (int) ($activeRoute['primary_model_id'] ?? 0);
            $assignedIds[] = (int) ($config['default_model_id'] ?? 0);
            $assignedIds = array_merge($assignedIds, array_map('intval', (array) ($config['pool_model_ids'] ?? [])));
        }
        $assignedIds = array_values(array_unique(array_filter($assignedIds)));
        foreach ($models as &$model) {
            $capabilities = json_decode((string) ($model['capabilities_json'] ?? '[]'), true);
            $model['capabilities'] = is_array($capabilities) ? array_values(array_map('strval', $capabilities)) : [];
            $model['capability_relations'] = Db::name('ai_model_capabilities')
                ->where('ai_model_id', (int) $model['id'])
                ->select()
                ->toArray();
            $model['prices'] = [];
            foreach (Db::name('ai_model_prices')->where('ai_model_id', (int) $model['id'])->select()->toArray() as $price) {
                $model['prices'][(string) $price['capability_code']] = $price;
            }
            foreach ($model['capabilities'] as $capability) {
                $verified = count(array_filter(
                    $model['capability_relations'],
                    static fn (array $relation): bool =>
                        (string) ($relation['capability_code'] ?? '') === $capability
                        && (string) ($relation['test_status'] ?? '') === 'passed'
                )) > 0;
                if ($verified) {
                    $options[$capability][] = $model;
                }
            }
        }
        unset($model);

        $routes = [];
        foreach (Db::name('ai_capability_routes')->select()->toArray() as $route) {
            $config = json_decode((string) ($route['route_config_json'] ?? '{}'), true);
            $config = is_array($config) ? $config : [];
            $route['pool_model_ids'] = array_values(array_map('intval', (array) ($config['pool_model_ids'] ?? [])));
            $route['default_model_id'] = max(0, (int) ($config['default_model_id'] ?? $route['primary_model_id'] ?? 0));
            $routes[(string) $route['capability_code']] = $route;
        }
        return [
            'models' => array_values(array_filter(
                $models,
                static fn (array $model): bool => in_array((int) $model['id'], $assignedIds, true)
            )),
            'options' => $options,
            'routes' => $routes,
            'definitions' => AiCapability::definitions(),
        ];
    }

    public function saveRoutes(array $routes, int $adminId): void
    {
        $this->legacyCatalog->saveRoutes($routes, $adminId);
    }

    public function savePrices(array $prices): int
    {
        return $this->legacyCatalog->saveCapabilityPrices($prices);
    }
}
