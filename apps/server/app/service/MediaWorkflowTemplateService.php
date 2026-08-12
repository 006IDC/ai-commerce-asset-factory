<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use RuntimeException;
use think\facade\Db;

final class MediaWorkflowTemplateService
{
    public const CANVAS_EDIT_TEMPLATE = 'canvas_edit_v1';

    public function templates(): array
    {
        $rows = Db::name('media_workflow_templates')
            ->whereLike('code', 'canvas_edit_%')
            ->order('enabled', 'desc')
            ->order('code', 'asc')
            ->select()
            ->toArray();
        return array_map([$this, 'normalizeRow'], $rows);
    }

    public function template(string $code, bool $enabledOnly = true): ?array
    {
        $code = trim($code);
        if (!$this->isCanvasTemplateCode($code)) {
            return null;
        }
        $query = Db::name('media_workflow_templates')->where('code', $code);
        if ($enabledOnly) {
            $query->where('enabled', 1);
        }
        $row = $query->find();
        return $row ? $this->normalizeRow($row) : null;
    }

    public function ensureBuiltIns(): int
    {
        $changed = $this->deleteRetiredTemplates();
        $template = $this->canvasTemplate();
        $workflowJson = $this->encode($template['workflow']);
        $inputMapJson = $this->encode($template['inputMap']);
        $outputMapJson = $this->encode($template['outputMap']);
        $workflowHash = $this->payloadHash($workflowJson, $inputMapJson, $outputMapJson);
        $now = Web::now();
        $existing = Db::name('media_workflow_templates')->where('code', self::CANVAS_EDIT_TEMPLATE)->find();

        if ($existing) {
            $structureChanged = trim((string) ($existing['workflow_json'] ?? '')) !== $workflowJson
                || trim((string) ($existing['input_map_json'] ?? '')) !== $inputMapJson
                || trim((string) ($existing['output_map_json'] ?? '')) !== $outputMapJson;
            if ($structureChanged || (int) ($existing['version'] ?? 0) < (int) $template['version']) {
                Db::name('media_workflow_templates')->where('id', (int) $existing['id'])->update([
                    'name' => $template['name'],
                    'engine' => 'comfyui',
                    'media_type' => 'image',
                    'version' => $template['version'],
                    'workflow_json' => $workflowJson,
                    'input_map_json' => $inputMapJson,
                    'output_map_json' => $outputMapJson,
                    'workflow_hash' => $workflowHash,
                    'enabled' => 0,
                    'last_test_status' => 'untested',
                    'last_test_error' => null,
                    'last_tested_at' => null,
                    'updated_by_admin_id' => null,
                    'updated_at' => $now,
                ]);
                $changed++;
            }
            return $changed;
        }

        Db::name('media_workflow_templates')->insert([
            'code' => self::CANVAS_EDIT_TEMPLATE,
            'name' => $template['name'],
            'engine' => 'comfyui',
            'media_type' => 'image',
            'version' => $template['version'],
            'workflow_json' => $workflowJson,
            'input_map_json' => $inputMapJson,
            'output_map_json' => $outputMapJson,
            'workflow_hash' => $workflowHash,
            'enabled' => 0,
            'last_test_status' => 'untested',
            'last_test_error' => null,
            'last_tested_at' => null,
            'updated_by_admin_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $changed + 1;
    }

    public function byId(int $id): ?array
    {
        $row = Db::name('media_workflow_templates')->where('id', $id)->find();
        if (!$row || !$this->isCanvasTemplateCode((string) ($row['code'] ?? ''))) {
            return null;
        }
        return $this->normalizeRow($row);
    }

    public function compile(string $code, array $variables): array
    {
        $template = $this->template($code);
        if ($template === null) {
            throw new RuntimeException('指定的画布 ComfyUI 工作流模板尚未启用。');
        }
        return $this->compileTemplate($template, $variables);
    }

    public function compileForTest(int $id, array $variables): array
    {
        $template = $this->byId($id);
        if ($template === null) {
            throw new RuntimeException('指定的画布 ComfyUI 工作流模板不存在。');
        }
        return $this->compileTemplate($template, $variables);
    }

    public function compileCaptured(array $snapshot, array $variables): array
    {
        $this->assertCanvasTemplateCode((string) ($snapshot['workflow_template_code'] ?? ''));
        $workflow = is_array($snapshot['workflow_definition'] ?? null) ? $snapshot['workflow_definition'] : [];
        $inputMap = is_array($snapshot['workflow_input_map'] ?? null) ? $snapshot['workflow_input_map'] : [];
        $outputMap = is_array($snapshot['workflow_output_map'] ?? null) ? $snapshot['workflow_output_map'] : [];
        $expectedHash = trim((string) ($snapshot['workflow_template_hash'] ?? ''));
        if ($workflow === [] || array_is_list($workflow) || $expectedHash === '') {
            throw new RuntimeException('画布任务缺少完整的 ComfyUI 工作流执行快照。');
        }
        $workflowJson = $this->encode($workflow);
        $inputMapJson = $this->encode($inputMap);
        $outputMapJson = $this->encode($outputMap);
        if (!hash_equals($expectedHash, $this->payloadHash($workflowJson, $inputMapJson, $outputMapJson))) {
            throw new RuntimeException('画布 ComfyUI 工作流执行快照校验失败。');
        }
        return $this->compileTemplate([
            'id' => (int) ($snapshot['workflow_template_id'] ?? 0),
            'code' => (string) $snapshot['workflow_template_code'],
            'version' => max(1, (int) ($snapshot['workflow_template_version'] ?? 1)),
            'workflowHash' => $expectedHash,
            'workflow' => $workflow,
            'inputMap' => $inputMap,
            'outputMap' => $outputMap,
        ], $variables);
    }

    public function recordTest(int $id, array $result): void
    {
        $template = $this->byId($id);
        if ($template === null) {
            throw new RuntimeException('画布工作流模板不存在。');
        }
        Db::name('media_workflow_templates')->where('id', $id)->update([
            'last_test_status' => !empty($result['ok']) ? 'ready' : 'failed',
            'last_test_error' => !empty($result['ok']) ? null : mb_strimwidth((string) ($result['message'] ?? '测试失败'), 0, 1000, ''),
            'last_tested_at' => Web::now(),
            'updated_at' => Web::now(),
        ]);
        (new WorkflowReleaseService())->markTemplateTested($id, $result, 0);
    }

    public function recordDependencyTest(int $id, array $result): void
    {
        if ($this->byId($id) === null) {
            throw new RuntimeException('画布工作流模板不存在。');
        }
        Db::name('media_workflow_templates')->where('id', $id)->update([
            'last_test_status' => !empty($result['ok']) ? 'dependencies_ready' : 'failed',
            'last_test_error' => !empty($result['ok']) ? null : mb_strimwidth((string) ($result['message'] ?? '节点检查失败'), 0, 1000, ''),
            'last_tested_at' => Web::now(),
            'updated_at' => Web::now(),
        ]);
    }

    public function testAgainstNodes(int $id, array $nodes): array
    {
        $template = $this->byId($id);
        if ($template === null) {
            throw new RuntimeException('画布工作流模板不存在。');
        }
        $available = array_fill_keys(array_map('strval', array_keys($nodes)), true);
        $required = [];
        foreach ($template['workflow'] as $node) {
            $classType = trim((string) ($node['class_type'] ?? ''));
            if ($classType !== '') {
                $required[$classType] = true;
            }
        }
        $missing = array_values(array_filter(
            array_keys($required),
            static fn (string $classType): bool => !isset($available[$classType])
        ));
        $result = $missing === []
            ? ['ok' => true, 'message' => '画布模板所需节点均已安装；还需完成一次真实结果测试才能发布。']
            : ['ok' => false, 'message' => 'ComfyUI 缺少节点：' . implode('、', $missing)];
        $result['requiredNodes'] = array_keys($required);
        $result['missingNodes'] = $missing;
        $this->recordDependencyTest($id, $result);
        return $result;
    }

    private function deleteRetiredTemplates(): int
    {
        $changed = 0;
        $changed += Db::name('creative_products')
            ->where('code', '<>', 'canvas_edit')
            ->update([
                'current_workflow_version_id' => null,
                'previous_workflow_version_id' => null,
                'updated_at' => Web::now(),
            ]);
        $changed += Db::name('workflow_release_events')->where('product_code', '<>', 'canvas_edit')->delete();
        $changed += Db::name('workflow_template_versions')->where('template_code', 'not like', 'canvas_edit_%')->delete();
        $changed += Db::name('media_workflow_templates')->where('code', 'not like', 'canvas_edit_%')->delete();
        return $changed;
    }

    private function compileTemplate(array $template, array $variables): array
    {
        $this->assertCanvasTemplateCode((string) ($template['code'] ?? ''));
        $workflow = $template['workflow'];
        $promptTemplate = trim((string) ($template['outputMap']['prompt_template'] ?? ''));
        if ($promptTemplate !== '' && array_key_exists('prompt', $variables)) {
            $variables['prompt'] = trim(strtr($promptTemplate, ['{prompt}' => (string) $variables['prompt']]));
        }
        foreach ($template['inputMap'] as $variable => $paths) {
            if (!array_key_exists($variable, $variables)) {
                continue;
            }
            foreach ((array) $paths as $path) {
                $this->setPath($workflow, trim((string) $path), $variables[$variable]);
            }
        }
        return [
            'workflow' => $workflow,
            'template' => $template,
            'snapshot' => [
                'workflow_template_id' => (int) ($template['id'] ?? 0),
                'workflow_template_code' => (string) $template['code'],
                'workflow_template_version' => (int) $template['version'],
                'workflow_template_hash' => (string) $template['workflowHash'],
            ],
        ];
    }

    private function normalizeRow(array $row): array
    {
        $inputMap = $this->decodeStoredObject($row['input_map_json'] ?? '{}');
        $outputMap = $this->decodeStoredObject($row['output_map_json'] ?? '{}');
        return $row + [
            'workflow' => $this->decodeStoredObject($row['workflow_json'] ?? '{}'),
            'inputMap' => $inputMap,
            'outputMap' => $outputMap,
            'workflowHash' => (string) ($row['workflow_hash'] ?? ''),
            'promptTemplate' => (string) ($outputMap['prompt_template'] ?? ''),
            'promptVariables' => ['{prompt}' => '画布编辑完整提示词'],
        ];
    }

    private function canvasTemplate(): array
    {
        return [
            'name' => '画布 AI 编辑 v1（ComfyUI 专用）',
            'version' => 4,
            'workflow' => [
                '1' => [
                    'class_type' => 'AssetFactorySub2ApiImage',
                    'inputs' => [
                        'prompt' => '',
                        'negative_prompt' => '',
                        'reference_images_json' => '[]',
                        'mask_image' => '',
                        'sub2api_model' => '',
                        'size' => '1024x1024',
                        'seed' => 1,
                        'job_no' => '',
                        'item_id' => 0,
                        'workflow_code' => self::CANVAS_EDIT_TEMPLATE,
                        'workflow_version' => 4,
                        'idempotency_key' => '',
                    ],
                    '_meta' => ['title' => '画布 AI 编辑 · Sub2API 生成'],
                ],
                '2' => [
                    'class_type' => 'AssetFactorySaveImage',
                    'inputs' => [
                        'image' => ['1', 0],
                        'sub2api_request_id' => ['1', 1],
                        'model' => ['1', 2],
                        'duration_ms' => ['1', 3],
                        'usage_json' => ['1', 4],
                        'output_prefix' => 'asset-factory/canvas-edit',
                    ],
                    '_meta' => ['title' => '画布 AI 编辑 · 保存结果'],
                ],
            ],
            'inputMap' => [
                'prompt' => ['1.inputs.prompt'],
                'negative_prompt' => ['1.inputs.negative_prompt'],
                'reference_images_json' => ['1.inputs.reference_images_json'],
                'mask_image' => ['1.inputs.mask_image'],
                'model' => ['1.inputs.sub2api_model'],
                'size' => ['1.inputs.size'],
                'seed' => ['1.inputs.seed'],
                'job_no' => ['1.inputs.job_no'],
                'generation_item_id' => ['1.inputs.item_id'],
                'workflow_code' => ['1.inputs.workflow_code'],
                'workflow_version' => ['1.inputs.workflow_version'],
                'idempotency_key' => ['1.inputs.idempotency_key'],
                'output_prefix' => ['2.inputs.output_prefix'],
            ],
            'outputMap' => [
                'media_type' => 'image',
                'provider_owner' => 'sub2api',
                'required_nodes' => ['AssetFactorySub2ApiImage', 'AssetFactorySaveImage'],
                'supported_models' => [],
                'asset_types' => ['canvas_edit'],
                'asset_type_prefixes' => [],
                'requires_reference' => true,
                'prompt_template' => '{prompt}',
            ],
        ];
    }

    private function setPath(array &$target, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $cursor =& $target;
        foreach ($segments as $index => $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                throw new RuntimeException("工作流输入映射路径不存在：{$path}");
            }
            if ($index === count($segments) - 1) {
                $cursor[$segment] = $value;
                return;
            }
            $cursor =& $cursor[$segment];
        }
    }

    private function isCanvasTemplateCode(string $code): bool
    {
        return str_starts_with(trim($code), 'canvas_edit_');
    }

    private function assertCanvasTemplateCode(string $code): void
    {
        if (!$this->isCanvasTemplateCode($code)) {
            throw new RuntimeException('ComfyUI 仅用于画布编辑。');
        }
    }

    private function decodeStoredObject(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
    }

    private function encode(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('无法编码画布工作流模板。');
        }
        return $encoded;
    }

    private function payloadHash(string $workflowJson, string $inputMapJson, string $outputMapJson): string
    {
        return hash('sha256', $workflowJson . "\n" . $inputMapJson . "\n" . $outputMapJson);
    }
}
