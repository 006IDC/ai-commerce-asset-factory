<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use RuntimeException;
use think\facade\Db;

final class WorkflowReleaseService
{
    public function captureTemplate(int $templateId, int $adminId): array
    {
        $template = Db::name('media_workflow_templates')->where('id', $templateId)->find();
        if (!$template) {
            throw new RuntimeException('工作流模板不存在。');
        }
        $existing = Db::name('workflow_template_versions')
            ->where('template_code', (string) $template['code'])
            ->where('workflow_hash', (string) $template['workflow_hash'])
            ->find();
        if ($existing) {
            return $existing;
        }
        $version = (int) Db::name('workflow_template_versions')->where('template_code', (string) $template['code'])->max('version') + 1;
        $outputMap = $this->decodeObject($template['output_map_json'] ?? '{}');
        $id = (int) Db::name('workflow_template_versions')->insertGetId([
            'template_id' => $templateId,
            'template_code' => (string) $template['code'],
            'version' => max(1, $version),
            'workflow_json' => (string) $template['workflow_json'],
            'input_map_json' => (string) $template['input_map_json'],
            'output_map_json' => (string) $template['output_map_json'],
            'dependency_manifest_json' => json_encode($outputMap['required_nodes'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'workflow_hash' => (string) $template['workflow_hash'],
            'status' => (string) $template['last_test_status'] === 'ready' ? 'tested' : 'draft',
            'last_test_result_json' => json_encode([
                'status' => (string) $template['last_test_status'],
                'error' => (string) ($template['last_test_error'] ?? ''),
                'tested_at' => $template['last_tested_at'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_by_admin_id' => $adminId,
            'published_at' => null,
            'created_at' => Web::now(),
        ]);
        return Db::name('workflow_template_versions')->where('id', $id)->find();
    }

    public function markTemplateTested(int $templateId, array $result, int $adminId): array
    {
        $version = $this->captureTemplate($templateId, $adminId);
        Db::name('workflow_template_versions')->where('id', (int) $version['id'])->update([
            'status' => !empty($result['ok']) ? 'tested' : 'draft',
            'last_test_result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return Db::name('workflow_template_versions')->where('id', (int) $version['id'])->find();
    }

    public function publish(string $productCode, int $workflowVersionId, int $adminId, string $reason): array
    {
        if ($productCode !== 'canvas_edit') {
            throw new RuntimeException('ComfyUI 工作流只能发布到画布编辑。');
        }
        return Db::transaction(function () use ($productCode, $workflowVersionId, $adminId, $reason): array {
            $product = Db::name('creative_products')->where('code', $productCode)->lock(true)->find();
            $version = Db::name('workflow_template_versions')->where('id', $workflowVersionId)->lock(true)->find();
            if (!$product || !$version) {
                throw new RuntimeException('创作产品或工作流版本不存在。');
            }
            if ((string) $product['execution_mode'] !== 'comfyui') {
                throw new RuntimeException('Direct 创作产品不需要发布 ComfyUI 工作流。');
            }
            if (!in_array((string) $version['status'], ['tested', 'published'], true)) {
                throw new RuntimeException('工作流必须先通过依赖检查和真实测试。');
            }
            if (!$this->codeMatchesProduct((string) $version['template_code'], $productCode)) {
                throw new RuntimeException('工作流版本与创作产品不匹配。');
            }
            $previousId = max(0, (int) ($product['current_workflow_version_id'] ?? 0));
            if ($previousId === $workflowVersionId) {
                return $version;
            }
            $now = Web::now();
            Db::name('workflow_template_versions')->where('id', $workflowVersionId)->update([
                'status' => 'published',
                'published_at' => $now,
            ]);
            Db::name('creative_products')->where('id', (int) $product['id'])->update([
                'previous_workflow_version_id' => $previousId > 0 ? $previousId : null,
                'current_workflow_version_id' => $workflowVersionId,
                'updated_at' => $now,
            ]);
            Db::name('workflow_release_events')->insert([
                'product_code' => $productCode,
                'workflow_version_id' => $workflowVersionId,
                'previous_workflow_version_id' => $previousId > 0 ? $previousId : null,
                'event_type' => 'published',
                'reason' => mb_strimwidth(trim($reason), 0, 1000, ''),
                'created_by_admin_id' => $adminId,
                'created_at' => $now,
            ]);
            return Db::name('workflow_template_versions')->where('id', $workflowVersionId)->find();
        });
    }

    public function rollback(string $productCode, int $adminId, string $reason): array
    {
        if ($productCode !== 'canvas_edit') {
            throw new RuntimeException('ComfyUI 工作流只能用于画布编辑。');
        }
        return Db::transaction(function () use ($productCode, $adminId, $reason): array {
            $product = Db::name('creative_products')->where('code', $productCode)->lock(true)->find();
            if (!$product) {
                throw new RuntimeException('创作产品不存在。');
            }
            $currentId = max(0, (int) ($product['current_workflow_version_id'] ?? 0));
            $previousId = max(0, (int) ($product['previous_workflow_version_id'] ?? 0));
            if ($currentId <= 0 || $previousId <= 0) {
                throw new RuntimeException('没有可回滚的上一工作流版本。');
            }
            $previous = Db::name('workflow_template_versions')->where('id', $previousId)->lock(true)->find();
            if (!$previous) {
                throw new RuntimeException('上一工作流版本不存在。');
            }
            $now = Web::now();
            Db::name('creative_products')->where('id', (int) $product['id'])->update([
                'current_workflow_version_id' => $previousId,
                'previous_workflow_version_id' => $currentId,
                'updated_at' => $now,
            ]);
            Db::name('workflow_release_events')->insert([
                'product_code' => $productCode,
                'workflow_version_id' => $previousId,
                'previous_workflow_version_id' => $currentId,
                'event_type' => 'rolled_back',
                'reason' => mb_strimwidth(trim($reason), 0, 1000, ''),
                'created_by_admin_id' => $adminId,
                'created_at' => $now,
            ]);
            return $previous;
        });
    }

    public function snapshotForProduct(string $productCode): ?array
    {
        if ($productCode !== 'canvas_edit') {
            return null;
        }
        $product = Db::name('creative_products')->where('code', $productCode)->find();
        if (!$product || (string) $product['execution_mode'] !== 'comfyui') {
            return null;
        }
        $versionId = max(0, (int) ($product['current_workflow_version_id'] ?? 0));
        $version = $versionId > 0 ? Db::name('workflow_template_versions')->where('id', $versionId)->find() : null;
        if (!$version) {
            return null;
        }
        return [
            'workflow_release_id' => (int) $version['id'],
            'workflow_template_code' => (string) $version['template_code'],
            'workflow_template_version' => (int) $version['version'],
            'workflow_template_hash' => (string) $version['workflow_hash'],
            'workflow_definition' => $this->decodeObject($version['workflow_json']),
            'workflow_input_map' => $this->decodeObject($version['input_map_json']),
            'workflow_output_map' => $this->decodeObject($version['output_map_json']),
            'workflow_required_nodes' => $this->decodeList($version['dependency_manifest_json']),
            'workflow_release_status' => (string) $version['status'],
        ];
    }

    private function codeMatchesProduct(string $code, string $productCode): bool
    {
        return $productCode === 'canvas_edit' && str_starts_with($code, 'canvas_edit_');
    }

    private function decodeObject(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function decodeList(mixed $value): array
    {
        $decoded = $this->decodeObject($value);
        return array_is_list($decoded) ? $decoded : [];
    }
}
