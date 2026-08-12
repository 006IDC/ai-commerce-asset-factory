<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use InvalidArgumentException;
use think\facade\Db;

final class AiModelCatalogService
{
    public function dashboard(): array
    {
        $pool = Db::name('ai_models')
            ->where('source_visible', 1)
            ->whereNull('archived_at')
            ->order('sort_order', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        foreach ($pool as &$model) {
            $model = $this->normalize($model);
            $model['pool_status'] = $this->poolStatus($model);
        }
        unset($model);
        $models = array_values(array_filter(
            $pool,
            static fn (array $model): bool => (array) ($model['assigned_uses'] ?? []) !== []
        ));

        return [
            'models' => $models,
            'pool' => $pool,
            'definitions' => AiCapability::definitions(),
            'stats' => [
                'total' => count($pool),
                'assigned' => count($models),
                'tested' => count(array_filter($pool, static fn (array $model): bool =>
                    !empty($model['output_test']['passed'])
                )),
            ],
        ];
    }

    public function model(int $id): ?array
    {
        $model = Db::name('ai_models')
            ->where('id', $id)
            ->where('source_visible', 1)
            ->whereNull('archived_at')
            ->find();
        if (!$model) {
            return null;
        }
        $model = $this->normalize($model);
        $model['tests'] = Db::name('ai_capability_tests')
            ->where('ai_model_id', $id)
            ->order('id', 'desc')
            ->limit(30)
            ->select()
            ->toArray();
        foreach ($model['tests'] as &$test) {
            $decoded = json_decode((string) ($test['result_json'] ?? '{}'), true);
            $test['result'] = is_array($decoded) ? $decoded : [];
        }
        unset($test);
        $model['compatibilities'] = Db::name('ai_business_compatibilities')
            ->where('ai_model_id', $id)
            ->order('product_code', 'asc')
            ->order('capability_code', 'asc')
            ->select()
            ->toArray();
        $model['prices'] = Db::name('ai_model_prices')
            ->where('ai_model_id', $id)
            ->order('capability_code', 'asc')
            ->select()
            ->toArray();
        return $model;
    }

    public function update(int $id, array $input, int $adminId): array
    {
        $model = $this->model($id);
        if (!$model) {
            throw new InvalidArgumentException('模型不存在。');
        }
        $displayName = mb_strimwidth(trim((string) ($input['display_name'] ?? '')), 0, 160, '');
        $provider = mb_strimwidth(trim((string) ($input['provider'] ?? '')), 0, 80, '');
        $description = mb_strimwidth(trim((string) ($input['merchant_description'] ?? '')), 0, 180, '');
        if ($displayName === '') {
            throw new InvalidArgumentException('请填写前台可读的模型名称。');
        }
        $limits = is_array($model['limits']) ? $model['limits'] : [];
        $limits['merchant_description'] = $description;
        $limits['minimum_tier'] = max(0, min(999, (int) ($input['minimum_tier'] ?? 0)));
        $enabled = !empty($input['enabled']) ? 1 : 0;
        if ($enabled === 1 && empty($model['source_visible'])) {
            throw new InvalidArgumentException('该模型已不在 Sub2API 目录中，不能启用新任务。');
        }
        $lifecycle = $enabled === 1 ? 'configured' : 'paused';
        if ($this->allConfirmedCapabilitiesReady((array) $model['capability_relations']) && $enabled === 1) {
            $lifecycle = 'available';
        }
        Db::name('ai_models')->where('id', $id)->update([
            'display_name' => $displayName,
            'provider' => $provider !== '' ? $provider : null,
            'limits_json' => json_encode($limits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'enabled' => $enabled,
            'sort_order' => max(0, min(100000, (int) ($input['sort_order'] ?? 0))),
            'lifecycle_status' => $lifecycle,
            'updated_at' => Web::now(),
        ]);
        return $this->model($id) ?? $model;
    }

    public function archive(int $id, int $adminId): array
    {
        $model = Db::name('ai_models')->where('id', $id)->find();
        if (!$model) {
            throw new InvalidArgumentException('模型不存在。');
        }
        if ($this->hasActiveAssignment($id)) {
            throw new InvalidArgumentException('该模型仍在功能分配或开放池中，请先替换后再归档。');
        }
        Db::name('ai_models')->where('id', $id)->update([
            'enabled' => 0,
            'user_selectable' => 0,
            'lifecycle_status' => 'archived',
            'archived_at' => Web::now(),
            'updated_at' => Web::now(),
        ]);
        return Db::name('ai_models')->where('id', $id)->find() ?? $model;
    }

    private function normalize(array $model): array
    {
        $model['capabilities'] = $this->decodeList($model['capabilities_json'] ?? '[]');
        $model['input_modalities'] = $this->decodeList($model['input_modalities_json'] ?? '[]');
        $model['output_modalities'] = $this->decodeList($model['output_modalities_json'] ?? '[]');
        $model['limits'] = $this->decodeObject($model['limits_json'] ?? '{}');
        $model['source_visible'] = (int) ($model['source_visible'] ?? 1) === 1;
        $model['capability_relations'] = Db::name('ai_model_capabilities')
            ->where('ai_model_id', (int) $model['id'])
            ->order('capability_code', 'asc')
            ->select()
            ->toArray();
        $protocolTest = Db::name('ai_capability_tests')
            ->where('ai_model_id', (int) $model['id'])
            ->where('test_scope', 'protocol')
            ->order('id', 'desc')
            ->find();
        $confirmed = array_values(array_filter(
            $model['capability_relations'],
            static fn (array $relation): bool =>
                (string) ($relation['confirmation_status'] ?? '') === 'confirmed'
        ));
        $passed = array_values(array_filter(
            $confirmed,
            static fn (array $relation): bool => (string) ($relation['test_status'] ?? '') === 'passed'
        ));
        $model['connection_test'] = [
            'status' => (string) ($protocolTest['status'] ?? 'pending'),
            'tested_at' => (string) ($protocolTest['created_at'] ?? ''),
        ];
        $model['output_test'] = [
            'passed' => $confirmed !== [] && count($passed) === count($confirmed),
            'passed_count' => count($passed),
            'confirmed_count' => count($confirmed),
        ];
        $model['assigned_uses'] = $this->assignedUses((int) $model['id']);
        return $model;
    }

    private function assignedUses(int $modelId): array
    {
        $uses = [];
        foreach (Db::name('ai_capability_routes')->where('enabled', 1)->select()->toArray() as $route) {
            $config = $this->decodeObject($route['route_config_json'] ?? '{}');
            $assigned = (int) ($route['primary_model_id'] ?? 0) === $modelId
                || (int) ($config['default_model_id'] ?? 0) === $modelId
                || in_array($modelId, array_map('intval', (array) ($config['pool_model_ids'] ?? [])), true);
            if ($assigned) {
                $uses[] = AiCapability::label((string) $route['capability_code']);
            }
        }
        return array_values(array_unique($uses));
    }

    private function poolStatus(array $model): string
    {
        if ((array) ($model['assigned_uses'] ?? []) !== []) {
            return 'assigned';
        }
        $relations = (array) ($model['capability_relations'] ?? []);
        if (count(array_filter($relations, static fn (array $relation): bool =>
            (string) ($relation['test_status'] ?? '') === 'failed'
        )) > 0) {
            return 'failed';
        }
        if (count(array_filter($relations, static fn (array $relation): bool =>
            (string) ($relation['test_status'] ?? '') === 'pending'
            && (int) ($relation['last_test_id'] ?? 0) > 0
        )) > 0) {
            return 'testing';
        }
        if (count(array_filter($relations, static fn (array $relation): bool =>
            (string) ($relation['test_status'] ?? '') === 'passed'
        )) > 0) {
            return 'passed';
        }
        return 'pending';
    }

    private function allConfirmedCapabilitiesReady(array $relations): bool
    {
        $confirmed = array_values(array_filter($relations, static fn (array $relation): bool =>
            (string) ($relation['confirmation_status'] ?? '') === 'confirmed'
        ));
        return $confirmed !== [] && count(array_filter($confirmed, static fn (array $relation): bool =>
            (string) ($relation['test_status'] ?? '') === 'passed'
        )) === count($confirmed);
    }

    private function hasActiveAssignment(int $modelId): bool
    {
        foreach (Db::name('ai_capability_routes')->select()->toArray() as $route) {
            if ((int) ($route['primary_model_id'] ?? 0) === $modelId
                || (int) ($route['fallback_model_id'] ?? 0) === $modelId) {
                return true;
            }
            $config = $this->decodeObject($route['route_config_json'] ?? '{}');
            if ((int) ($config['default_model_id'] ?? 0) === $modelId
                || in_array($modelId, array_map('intval', (array) ($config['pool_model_ids'] ?? [])), true)) {
                return true;
            }
        }
        return false;
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
}
