<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use RuntimeException;
use think\facade\Db;
use Throwable;

final class Sub2ApiModelSyncService
{
    public function __construct(private readonly Sub2ApiClient $sub2Api)
    {
    }

    public function sync(int $adminId): array
    {
        $startedAt = Web::now();
        $batchId = (int) Db::name('ai_model_sync_batches')->insertGetId([
            'source' => 'sub2api',
            'status' => 'running',
            'discovered_count' => 0,
            'new_count' => 0,
            'missing_count' => 0,
            'summary_json' => '{}',
            'initiated_by_admin_id' => $adminId > 0 ? $adminId : null,
            'started_at' => $startedAt,
            'completed_at' => null,
        ]);

        try {
            $runtime = $this->sub2Api->runtimeStatus();
            if ((string) ($runtime['status'] ?? '') !== 'ready') {
                throw new RuntimeException((string) ($runtime['message'] ?? 'Sub2API 模型目录不可用。'));
            }
            $aliases = array_values(array_unique(array_filter(array_map(
                static fn (mixed $alias): string => mb_strimwidth(trim((string) $alias), 0, 190, ''),
                (array) ($runtime['models'] ?? [])
            ))));
            sort($aliases);
            $now = Web::now();
            $newAliases = [];
            $missingAliases = [];

            Db::transaction(function () use ($aliases, $now, &$newAliases, &$missingAliases): void {
                $visibleAliases = array_fill_keys($aliases, true);
                foreach ($aliases as $alias) {
                    $existing = Db::name('ai_models')
                        ->where('gateway', 'sub2api')
                        ->where('model_id', $alias)
                        ->find();
                    $catalogPayload = json_encode([
                        'source' => 'sub2api',
                        'alias' => $alias,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($existing) {
                        $update = [
                            'source_visible' => 1,
                            'source_last_seen_at' => $now,
                            'catalog_payload_json' => $catalogPayload,
                            'updated_at' => $now,
                        ];
                        if ((string) ($existing['lifecycle_status'] ?? '') === 'unavailable') {
                            $update += [
                                'lifecycle_status' => 'discovered',
                                'health_status' => 'unknown',
                                'last_error' => '模型重新出现在 Sub2API 目录，需重新确认和测试后才能用于新任务。',
                            ];
                            Db::name('ai_model_capabilities')->where('ai_model_id', (int) $existing['id'])->update([
                                'test_status' => 'pending',
                                'updated_at' => $now,
                            ]);
                        }
                        Db::name('ai_models')->where('id', (int) $existing['id'])->update($update);
                        $this->ensureSuggestions((int) $existing['id'], $alias, $now);
                        continue;
                    }

                    $limits = [
                        'release_stage' => 'candidate',
                        'merchant_description' => '',
                        'minimum_tier' => 0,
                    ];
                    $modelId = (int) Db::name('ai_models')->insertGetId([
                        'gateway' => 'sub2api',
                        'model_id' => $alias,
                        'display_name' => $this->humanName($alias),
                        'provider' => null,
                        'protocol' => 'chat',
                        'capabilities_json' => '[]',
                        'input_modalities_json' => '["text"]',
                        'output_modalities_json' => '[]',
                        'limits_json' => json_encode($limits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'adapter_config_json' => '{}',
                        'catalog_payload_json' => $catalogPayload,
                        'user_selectable' => 0,
                        'enabled' => 0,
                        'sort_order' => 0,
                        'health_status' => 'unknown',
                        'last_error' => '新发现模型：能力尚未确认，尚未执行真实测试。',
                        'last_tested_at' => null,
                        'source_visible' => 1,
                        'source_last_seen_at' => $now,
                        'lifecycle_status' => 'discovered',
                        'archived_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $this->ensureSuggestions($modelId, $alias, $now);
                    $newAliases[] = $alias;
                }

                foreach (Db::name('ai_models')->where('gateway', 'sub2api')->whereNull('archived_at')->select()->toArray() as $model) {
                    $alias = trim((string) ($model['model_id'] ?? ''));
                    if ($alias === '' || isset($visibleAliases[$alias])) {
                        continue;
                    }
                    $missingAliases[] = $alias;
                    Db::name('ai_models')->where('id', (int) $model['id'])->update([
                        'source_visible' => 0,
                        'lifecycle_status' => 'unavailable',
                        'health_status' => 'unavailable',
                        'last_error' => '模型已不在 Sub2API 公开目录中，新任务不可使用；历史任务与账务继续保留。',
                        'updated_at' => $now,
                    ]);
                }
            });

            $summary = [
                'discovered_aliases' => $aliases,
                'new_aliases' => array_values(array_unique($newAliases)),
                'missing_aliases' => array_values(array_unique($missingAliases)),
                'sensitive_fields_copied' => false,
            ];
            Db::name('ai_model_sync_batches')->where('id', $batchId)->update([
                'status' => 'completed',
                'discovered_count' => count($aliases),
                'new_count' => count($summary['new_aliases']),
                'missing_count' => count($summary['missing_aliases']),
                'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'completed_at' => Web::now(),
            ]);
            return $summary + ['batch_id' => $batchId];
        } catch (Throwable $exception) {
            Db::name('ai_model_sync_batches')->where('id', $batchId)->update([
                'status' => 'failed',
                'summary_json' => json_encode([
                    'message' => mb_strimwidth($exception->getMessage(), 0, 500, ''),
                    'sensitive_fields_copied' => false,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'completed_at' => Web::now(),
            ]);
            throw $exception;
        }
    }

    public function recentBatches(int $limit = 10): array
    {
        return Db::name('ai_model_sync_batches')
            ->order('id', 'desc')
            ->limit(max(1, min(50, $limit)))
            ->select()
            ->toArray();
    }

    public function lastSuccessfulBatch(): ?array
    {
        $row = Db::name('ai_model_sync_batches')
            ->where('status', 'completed')
            ->order('id', 'desc')
            ->find();
        return $row ?: null;
    }

    private function ensureSuggestions(int $modelId, string $alias, string $now): void
    {
        foreach ($this->suggestedCapabilities($alias) as $capability) {
            $existing = Db::name('ai_model_capabilities')
                ->where('ai_model_id', $modelId)
                ->where('capability_code', $capability)
                ->find();
            if ($existing) {
                continue;
            }
            Db::name('ai_model_capabilities')->insert([
                'ai_model_id' => $modelId,
                'capability_code' => $capability,
                'confirmation_status' => 'suggested',
                'test_status' => 'pending',
                'suggested_by' => 'name_heuristic',
                'confirmed_by_admin_id' => null,
                'last_test_id' => null,
                'notes' => '仅依据模型别名给出的待确认建议，不代表真实能力。',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function suggestedCapabilities(string $alias): array
    {
        $name = strtolower($alias);
        if (str_contains($name, 'seedance') || str_contains($name, 'video')) {
            return [AiCapability::VIDEO_IMAGE_TO_VIDEO, AiCapability::VIDEO_TEXT_TO_VIDEO];
        }
        if (str_contains($name, 'image') || str_contains($name, 'seedream')) {
            return [AiCapability::IMAGE_GENERATION, AiCapability::IMAGE_EDIT];
        }
        if (str_contains($name, 'vision') || preg_match('/(?:^|[-_.])\\d+(?:\\.\\d+)?v(?:[-_.]|$)/', $name) === 1) {
            return [AiCapability::PRODUCT_VISION, AiCapability::SAFETY_VISION];
        }
        return [AiCapability::CONTENT_PLANNER, AiCapability::SAFETY_TEXT];
    }

    private function humanName(string $alias): string
    {
        return mb_convert_case(str_replace(['-', '_'], ' ', $alias), MB_CASE_TITLE, 'UTF-8');
    }
}
