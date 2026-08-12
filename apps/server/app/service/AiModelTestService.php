<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use RuntimeException;
use think\facade\Db;

final class AiModelTestService
{
    public function __construct(
        private readonly Sub2ApiClient $sub2Api,
        private readonly NewApiClient $newApi,
        private readonly MediaWorkflowEngine $workflows,
        private readonly ModelCatalogService $legacyCatalog,
        private readonly AiBusinessCompatibilityService $compatibilities
    ) {
    }

    public function confirmCapability(int $modelId, string $capability, bool $confirmed, int $adminId): array
    {
        if (!AiCapability::exists($capability)) {
            throw new RuntimeException('能力类型不正确。');
        }
        $model = $this->model($modelId);
        if (!$model) {
            throw new RuntimeException('模型不存在。');
        }
        if (!empty($model['archived_at'])) {
            throw new RuntimeException('归档模型不能修改能力。');
        }
        if (!$confirmed && $this->assignedToCapability($modelId, $capability)) {
            throw new RuntimeException('该模型仍在此能力的功能分配中，请先替换主模型、默认模型或开放池。');
        }

        $now = Web::now();
        $relation = Db::name('ai_model_capabilities')
            ->where('ai_model_id', $modelId)
            ->where('capability_code', $capability)
            ->find();
        $data = [
            'confirmation_status' => $confirmed ? 'confirmed' : 'rejected',
            'test_status' => 'pending',
            'confirmed_by_admin_id' => $adminId > 0 ? $adminId : null,
            'notes' => $confirmed ? '管理员已确认能力，等待真实测试。' : '管理员确认该模型不具备此能力。',
            'updated_at' => $now,
        ];
        if ($relation) {
            Db::name('ai_model_capabilities')->where('id', (int) $relation['id'])->update($data);
        } else {
            Db::name('ai_model_capabilities')->insert($data + [
                'ai_model_id' => $modelId,
                'capability_code' => $capability,
                'suggested_by' => 'administrator',
                'last_test_id' => null,
                'created_at' => $now,
            ]);
        }

        $capabilities = json_decode((string) ($model['capabilities_json'] ?? '[]'), true);
        $capabilities = is_array($capabilities) ? array_values(array_filter(array_map('strval', $capabilities), [AiCapability::class, 'exists'])) : [];
        $capabilities = $confirmed
            ? array_values(array_unique([...$capabilities, $capability]))
            : array_values(array_filter($capabilities, static fn (string $item): bool => $item !== $capability));
        [$inputs, $outputs] = $this->modalities($capabilities);
        Db::name('ai_models')->where('id', $modelId)->update([
            'capabilities_json' => json_encode($capabilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'input_modalities_json' => json_encode($inputs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'output_modalities_json' => json_encode($outputs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'protocol' => $this->protocol($capabilities, (string) ($model['protocol'] ?? 'chat')),
            'health_status' => 'unknown',
            'lifecycle_status' => 'configured',
            'updated_at' => $now,
        ]);
        return Db::name('ai_model_capabilities')
            ->where('ai_model_id', $modelId)
            ->where('capability_code', $capability)
            ->find();
    }

    public function runProtocolTest(int $modelId, int $adminId): array
    {
        $model = $this->model($modelId);
        if (!$model || !empty($model['archived_at']) || (int) ($model['source_visible'] ?? 1) !== 1) {
            throw new RuntimeException('模型不存在。');
        }
        $runtime = $this->sub2Api->runtimeStatus();
        $ok = (string) ($runtime['status'] ?? '') === 'ready'
            && in_array((string) $model['model_id'], array_map('strval', (array) ($runtime['models'] ?? [])), true);
        $result = [
            'ok' => $ok,
            'message' => $ok ? 'Sub2API 目录中可以读取到该模型别名。' : 'Sub2API 目录中没有该模型别名。',
            'durationMs' => (int) ($runtime['latencyMs'] ?? 0),
            'requestId' => null,
        ];
        $testId = $this->recordTest($modelId, 'catalog_presence', 'protocol', null, 'direct', null, $result, false, $adminId);
        return $result + ['testId' => $testId];
    }

    public function runCapabilityTest(int $modelId, string $capability, bool $paidConfirmed, int $adminId): array
    {
        if (!$paidConfirmed) {
            throw new RuntimeException('真实能力测试可能产生上游费用，请勾选费用确认后再执行。');
        }
        $model = $this->assertTestable($modelId, $capability);
        $result = $this->newApi->testModelCapability($modelId, (string) $model['model_id'], $capability);
        $testId = $this->recordTest($modelId, $capability, 'capability', null, 'direct', null, $result, true, $adminId);
        $testStatus = !empty($result['pending'])
            ? 'pending'
            : (!empty($result['ok']) ? 'passed' : 'failed');
        Db::name('ai_model_capabilities')
            ->where('ai_model_id', $modelId)
            ->where('capability_code', $capability)
            ->update([
                'confirmation_status' => $testStatus === 'passed' ? 'confirmed' : 'suggested',
                'test_status' => $testStatus,
                'last_test_id' => $testId,
                'confirmed_by_admin_id' => $testStatus === 'passed' && $adminId > 0 ? $adminId : null,
                'notes' => $testStatus === 'passed'
                    ? '真实输出测试通过，系统自动记录为已验证能力。'
                    : (!empty($result['pending']) ? '真实输出测试进行中。' : '真实输出测试未通过。'),
                'updated_at' => Web::now(),
            ]);
        if (empty($result['pending'])) {
            $this->legacyCatalog->recordCapabilityTest($modelId, $capability, $result);
        }
        $this->refreshLifecycle($modelId);
        return $result + ['testId' => $testId];
    }

    public function refreshVideoTest(int $modelId, int $testId, int $adminId): array
    {
        $model = $this->model($modelId);
        if (!$model
            || !empty($model['archived_at'])
            || (int) ($model['source_visible'] ?? 1) !== 1) {
            throw new RuntimeException('模型不存在或已经离开当前目录。');
        }
        $test = Db::name('ai_capability_tests')
            ->where('id', $testId)
            ->where('ai_model_id', $modelId)
            ->where('test_scope', 'capability')
            ->find();
        $capability = trim((string) ($test['capability_code'] ?? ''));
        if (!$test
            || !in_array($capability, [AiCapability::VIDEO_IMAGE_TO_VIDEO, AiCapability::VIDEO_TEXT_TO_VIDEO], true)) {
            throw new RuntimeException('待刷新的视频测试不存在。');
        }
        if ((string) ($test['status'] ?? '') !== 'pending') {
            throw new RuntimeException('该视频测试已经结束，无需再次刷新。');
        }
        $previous = json_decode((string) ($test['result_json'] ?? '{}'), true);
        $previous = is_array($previous) ? $previous : [];
        $taskId = trim((string) (
            $previous['output_summary']['task_id']
            ?? $previous['request_id']
            ?? $test['request_id']
            ?? ''
        ));
        if ($taskId === '') {
            throw new RuntimeException('视频测试缺少上游任务标识，不能安全刷新。');
        }
        $result = $this->newApi->refreshVideoCapabilityTest(
            $modelId,
            (string) $model['model_id'],
            $capability,
            $taskId
        );
        $safeResult = $this->safeResult($result, null);
        $status = !empty($result['pending'])
            ? 'pending'
            : (!empty($result['ok']) ? 'passed' : 'failed');
        Db::name('ai_capability_tests')->where('id', $testId)->update([
            'status' => $status,
            'request_id' => $safeResult['request_id'] !== '' ? $safeResult['request_id'] : $taskId,
            'duration_ms' => $safeResult['duration_ms'],
            'result_json' => json_encode(
                $safeResult,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ),
            'tested_by_admin_id' => $adminId > 0 ? $adminId : ($test['tested_by_admin_id'] ?? null),
        ]);
        Db::name('ai_model_capabilities')
            ->where('ai_model_id', $modelId)
            ->where('capability_code', $capability)
            ->update([
                'confirmation_status' => $status === 'passed' ? 'confirmed' : 'suggested',
                'test_status' => $status,
                'last_test_id' => $testId,
                'confirmed_by_admin_id' => $status === 'passed' && $adminId > 0 ? $adminId : null,
                'notes' => $status === 'passed'
                    ? '同一视频测试任务已完成，系统自动记录为已验证能力。'
                    : ($status === 'pending' ? '同一视频测试任务仍在生成中。' : '视频真实输出测试未通过。'),
                'updated_at' => Web::now(),
            ]);
        if ($status !== 'pending') {
            $this->legacyCatalog->recordCapabilityTest($modelId, $capability, $result);
        }
        $this->refreshLifecycle($modelId);
        return $result + ['testId' => $testId, 'status' => $status];
    }

    public function runCompatibilityTest(
        int $modelId,
        string $capability,
        string $productCode,
        bool $paidConfirmed,
        int $adminId
    ): array {
        if (!$paidConfirmed) {
            throw new RuntimeException('真实业务兼容测试可能产生上游费用，请勾选费用确认后再执行。');
        }
        $model = $this->assertTestable($modelId, $capability, true);
        $product = AiBusinessCompatibilityService::PRODUCTS[$productCode] ?? null;
        if (!$product || !in_array($capability, (array) $product['capabilities'], true)) {
            throw new RuntimeException('模型能力与生成业务不匹配。');
        }
        $channel = (string) $product['channel'];
        if ($channel === 'direct') {
            $result = $this->newApi->testModelCapability($modelId, (string) $model['model_id'], $capability);
        } else {
            if (in_array($capability, [AiCapability::VIDEO_IMAGE_TO_VIDEO, AiCapability::VIDEO_TEXT_TO_VIDEO], true)) {
                throw new RuntimeException('视频工作流真实结果测试尚未实现完整终态轮询，当前保持未开放。');
            }
            $templateId = $this->workflowTemplateId($productCode);
            if ($templateId <= 0) {
                throw new RuntimeException('该生成业务没有可测试的当前 ComfyUI 工作流。');
            }
            $result = $this->workflows->testImageTemplate($templateId, $modelId);
        }

        $testId = $this->recordTest(
            $modelId,
            $capability,
            'business',
            $productCode,
            $channel,
            $channel === 'comfyui' ? $this->workflowVersionId($productCode) : null,
            $result,
            true,
            $adminId
        );
        $this->compatibilities->record(
            $modelId,
            $capability,
            $productCode,
            $channel,
            !empty($result['ok']) ? 'passed' : 'failed',
            $testId,
            (string) ($result['message'] ?? ''),
            $adminId
        );
        return $result + ['testId' => $testId];
    }

    private function assertTestable(int $modelId, string $capability, bool $requirePassedCapability = false): array
    {
        if (!AiCapability::exists($capability)) {
            throw new RuntimeException('能力类型不正确。');
        }
        $model = $this->model($modelId);
        if (!$model || !empty($model['archived_at'])) {
            throw new RuntimeException('模型不存在或已经归档。');
        }
        if ((int) ($model['source_visible'] ?? 1) !== 1) {
            throw new RuntimeException('模型已不在 Sub2API 目录中，不能执行新测试。');
        }
        $relation = Db::name('ai_model_capabilities')
            ->where('ai_model_id', $modelId)
            ->where('capability_code', $capability)
            ->find();
        if (!$relation) {
            throw new RuntimeException('系统没有识别到“' . AiCapability::label($capability) . '”候选测试建议，请先重新同步模型目录。');
        }
        if ($requirePassedCapability && (string) ($relation['test_status'] ?? '') !== 'passed') {
            throw new RuntimeException('请先完成“' . AiCapability::label($capability) . '”模型能力真实测试。');
        }
        return $model;
    }

    private function recordTest(
        int $modelId,
        string $capability,
        string $scope,
        ?string $productCode,
        string $channel,
        ?int $workflowVersionId,
        array $result,
        bool $paidConfirmed,
        int $adminId
    ): int {
        $safeResult = $this->safeResult($result, $workflowVersionId);
        $status = !empty($result['pending'])
            ? 'pending'
            : ($safeResult['ok'] ? 'passed' : 'failed');
        return (int) Db::name('ai_capability_tests')->insertGetId([
            'ai_model_id' => $modelId,
            'capability_code' => $capability,
            'test_scope' => $scope,
            'product_code' => $productCode,
            'execution_channel' => $channel,
            'workflow_version_id' => $workflowVersionId,
            'status' => $status,
            'request_id' => $safeResult['request_id'] !== '' ? $safeResult['request_id'] : null,
            'duration_ms' => $safeResult['duration_ms'],
            'paid_confirmed' => $paidConfirmed ? 1 : 0,
            'result_json' => json_encode($safeResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            'tested_by_admin_id' => $adminId > 0 ? $adminId : null,
            'created_at' => Web::now(),
        ]);
    }

    private function safeResult(array $result, ?int $workflowVersionId): array
    {
        return [
            'ok' => !empty($result['ok']),
            'pending' => !empty($result['pending']),
            'message' => mb_strimwidth(trim((string) ($result['message'] ?? '')), 0, 1000, ''),
            'request_id' => mb_strimwidth(trim((string) ($result['requestId'] ?? $result['promptId'] ?? '')), 0, 190, ''),
            'duration_ms' => max(0, (int) ($result['durationMs'] ?? 0)),
            'artifact_url' => (
                str_starts_with((string) ($result['artifactUrl'] ?? ''), '/storage/workflow-tests/')
                || str_starts_with((string) ($result['artifactUrl'] ?? ''), '/storage/model-tests/')
            )
                ? (string) $result['artifactUrl']
                : '',
            'workflow_version_id' => $workflowVersionId,
            'workflow_version' => max(0, (int) ($result['workflowVersion'] ?? 0)),
            'input_summary' => $this->testInputSummary($result),
            'output_summary' => $this->testOutputSummary($result),
            'failure' => [
                'node_id' => mb_strimwidth(trim((string) ($result['failedNodeId'] ?? '')), 0, 100, ''),
                'node_type' => mb_strimwidth(trim((string) ($result['failedNodeType'] ?? '')), 0, 190, ''),
                'exception_type' => mb_strimwidth(trim((string) ($result['exceptionType'] ?? '')), 0, 190, ''),
                'artifact_error' => mb_strimwidth(trim((string) ($result['artifactError'] ?? '')), 0, 500, ''),
            ],
        ];
    }

    private function workflowTemplateId(string $productCode): int
    {
        $versionId = $this->workflowVersionId($productCode) ?? 0;
        $version = $versionId > 0 ? Db::name('workflow_template_versions')->where('id', $versionId)->find() : null;
        if (!$version) {
            return 0;
        }
        $templateId = max(0, (int) ($version['template_id'] ?? 0));
        if ($templateId > 0) {
            return $templateId;
        }
        return max(0, (int) Db::name('media_workflow_templates')
            ->where('code', (string) ($version['template_code'] ?? ''))
            ->value('id'));
    }

    private function workflowVersionId(string $productCode): ?int
    {
        $id = (int) Db::name('creative_products')
            ->where('code', $productCode)
            ->value('current_workflow_version_id');
        return $id > 0 ? $id : null;
    }

    private function testInputSummary(array $result): array
    {
        $input = is_array($result['inputSnapshot'] ?? null) ? $result['inputSnapshot'] : [];
        $summary = [];
        foreach ([
            'workflow_code',
            'workflow_version',
            'model',
            'capability',
            'size',
            'requires_reference',
            'reference_count',
            'seed',
            'prompt_summary',
        ] as $key) {
            if (array_key_exists($key, $input)) {
                $summary[$key] = $this->safeSummaryValue($input[$key]);
            }
        }
        return $summary;
    }

    private function testOutputSummary(array $result): array
    {
        $output = is_array($result['outputSummary'] ?? null) ? $result['outputSummary'] : [];
        $summary = [];
        foreach (['type', 'text', 'url', 'mime', 'width', 'height', 'bytes', 'sha256', 'task_id', 'status'] as $key) {
            if (!array_key_exists($key, $output)) {
                continue;
            }
            $value = $output[$key];
            if ($key === 'url'
                && !str_starts_with((string) $value, '/storage/workflow-tests/')
                && !str_starts_with((string) $value, '/storage/model-tests/')) {
                continue;
            }
            $summary[$key] = $this->safeSummaryValue($value);
        }
        return $summary;
    }

    private function safeSummaryValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 4) {
            return '[truncated]';
        }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        if (is_string($value)) {
            return mb_strimwidth($value, 0, 1000, '');
        }
        if (!is_array($value)) {
            return mb_strimwidth((string) $value, 0, 1000, '');
        }
        $safe = [];
        foreach (array_slice($value, 0, 30, true) as $key => $item) {
            $name = mb_strimwidth((string) $key, 0, 100, '');
            if (preg_match('/(?:key|token|secret|authorization|password)/i', $name) === 1) {
                continue;
            }
            $safe[$name] = is_array($item)
                ? array_map(fn (mixed $nested): mixed => $this->safeSummaryValue($nested, $depth + 1), array_slice($item, 0, 30, true))
                : $this->safeSummaryValue($item, $depth + 1);
        }
        return $safe;
    }

    private function refreshLifecycle(int $modelId): void
    {
        $relations = Db::name('ai_model_capabilities')
            ->where('ai_model_id', $modelId)
            ->where('confirmation_status', 'confirmed')
            ->select()
            ->toArray();
        $ready = $relations !== [] && count(array_filter($relations, static fn (array $relation): bool =>
            (string) ($relation['test_status'] ?? '') === 'passed'
        )) === count($relations);
        Db::name('ai_models')->where('id', $modelId)->update([
            'lifecycle_status' => $ready ? 'available' : 'configured',
            'updated_at' => Web::now(),
        ]);
    }

    private function assignedToCapability(int $modelId, string $capability): bool
    {
        $route = Db::name('ai_capability_routes')->where('capability_code', $capability)->find();
        if (!$route) {
            return false;
        }
        if ((int) ($route['primary_model_id'] ?? 0) === $modelId
            || (int) ($route['fallback_model_id'] ?? 0) === $modelId) {
            return true;
        }
        $config = json_decode((string) ($route['route_config_json'] ?? '{}'), true);
        return is_array($config)
            && ((int) ($config['default_model_id'] ?? 0) === $modelId
                || in_array($modelId, array_map('intval', (array) ($config['pool_model_ids'] ?? [])), true));
    }

    private function model(int $id): ?array
    {
        $model = Db::name('ai_models')->where('id', $id)->find();
        return $model ?: null;
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

    private function protocol(array $capabilities, string $current): string
    {
        if (array_intersect($capabilities, [AiCapability::VIDEO_IMAGE_TO_VIDEO, AiCapability::VIDEO_TEXT_TO_VIDEO])) {
            return 'video_async';
        }
        if (in_array(AiCapability::IMAGE_EDIT, $capabilities, true)) {
            return 'image_edit';
        }
        if (in_array(AiCapability::IMAGE_GENERATION, $capabilities, true)) {
            return 'image_generation';
        }
        return in_array($current, ['chat', 'moderations'], true) ? $current : 'chat';
    }
}
