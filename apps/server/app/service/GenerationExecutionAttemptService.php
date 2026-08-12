<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use think\facade\Db;

final class GenerationExecutionAttemptService
{
    public function start(array $item, array $job): int
    {
        $itemId = max(0, (int) ($item['id'] ?? 0));
        $attemptNo = max(1, (int) ($item['attempts'] ?? 1));
        if ($itemId <= 0) {
            return 0;
        }
        $existing = Db::name('generation_execution_attempts')
            ->where('generation_item_id', $itemId)
            ->where('attempt_no', $attemptNo)
            ->find();
        if ($existing && !in_array((string) ($existing['status'] ?? ''), ['succeeded', 'failed'], true)) {
            return (int) $existing['id'];
        }
        if ($existing) {
            $attemptNo = max(
                $attemptNo,
                (int) Db::name('generation_execution_attempts')
                    ->where('generation_item_id', $itemId)
                    ->max('attempt_no') + 1
            );
        }
        if ($attemptNo > (int) ($item['attempts'] ?? 0)) {
            Db::name('generation_job_items')->where('id', $itemId)->update([
                'attempts' => $attemptNo,
                'updated_at' => Web::now(),
            ]);
        }

        $routing = $this->object($job['route_snapshot_json'] ?? null);
        $channel = (string) ($job['platform'] ?? '') === 'canvas' ? 'comfyui' : 'direct';
        $idempotencyKey = "generation-item:{$itemId}:attempt:{$attemptNo}";
        $now = Web::now();
        try {
            return (int) Db::name('generation_execution_attempts')->insertGetId([
                'generation_item_id' => $itemId,
                'attempt_no' => $attemptNo,
                'execution_channel' => $channel,
                'capability' => trim((string) ($routing['capability'] ?? AiCapability::IMAGE_GENERATION)),
                'logical_model' => trim((string) ($routing['logical_model'] ?? $job['model'] ?? '')) ?: null,
                'sub2api_model_alias' => trim((string) ($job['model'] ?? '')) ?: null,
                'model_adapter_code' => null,
                'model_adapter_version' => null,
                'model_adapter_hash' => null,
                'workflow_code' => null,
                'workflow_version' => null,
                'workflow_hash' => null,
                'client_request_id' => null,
                'comfyui_prompt_id' => null,
                'sub2api_request_id' => null,
                'provider_task_id' => null,
                'idempotency_key' => $idempotencyKey,
                'status' => 'started',
                'duration_ms' => null,
                'usage_summary_json' => '{}',
                'request_summary_json' => json_encode([
                    'job_id' => max(0, (int) ($job['id'] ?? 0)),
                    'job_no' => (string) ($job['job_no'] ?? ''),
                    'asset_type' => (string) ($item['asset_type'] ?? ''),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'result_summary_json' => '{}',
                'error_code' => null,
                'error_message' => null,
                'failed_node' => null,
                'started_at' => $now,
                'completed_at' => null,
                'created_at' => $now,
            ]);
        } catch (\Throwable) {
            return (int) (Db::name('generation_execution_attempts')
                ->where('idempotency_key', $idempotencyKey)
                ->value('id') ?? 0);
        }
    }

    public function markSubmitted(int $itemId, string $requestId): void
    {
        $attempt = Db::name('generation_execution_attempts')
            ->where('generation_item_id', $itemId)
            ->order('attempt_no', 'desc')
            ->find();
        if (!$attempt || in_array((string) $attempt['status'], ['succeeded', 'failed'], true)) {
            return;
        }
        Db::name('generation_execution_attempts')->where('id', (int) $attempt['id'])->update(
            ['status' => 'submitted'] + $this->requestColumns($requestId)
        );
    }

    public function markProviderMetadata(int $itemId, string $comfyPromptId, array $metadata): void
    {
        $attempt = Db::name('generation_execution_attempts')
            ->where('generation_item_id', $itemId)
            ->order('attempt_no', 'desc')
            ->find();
        if (!$attempt) {
            return;
        }
        $sub2ApiRequestId = mb_strimwidth(trim((string) ($metadata['sub2api_request_id'] ?? '')), 0, 190, '');
        $comfyPromptId = mb_strimwidth(trim($comfyPromptId), 0, 190, '');
        $usage = is_array($metadata['usage'] ?? null) ? $metadata['usage'] : [];
        $updates = [
            'comfyui_prompt_id' => $comfyPromptId !== '' ? $comfyPromptId : ($attempt['comfyui_prompt_id'] ?? null),
            'sub2api_request_id' => $sub2ApiRequestId !== '' ? $sub2ApiRequestId : ($attempt['sub2api_request_id'] ?? null),
            'provider_task_id' => $sub2ApiRequestId !== '' ? $sub2ApiRequestId : ($attempt['provider_task_id'] ?? null),
            'usage_summary_json' => json_encode([
                'provider_usage' => $usage,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ];
        Db::name('generation_execution_attempts')->where('id', (int) $attempt['id'])->update($updates);
    }

    public function succeed(int $attemptId, ?string $requestId, int $assetId, array $providerMetadata = []): void
    {
        $this->finish($attemptId, 'succeeded', $requestId, [
            'asset_id' => $assetId,
            'result_saved' => true,
        ], null);
    }

    public function fail(int $attemptId, string $message, ?string $requestId = null): void
    {
        $this->finish($attemptId, 'failed', $requestId, [
            'result_saved' => false,
        ], $message);
    }

    private function finish(int $attemptId, string $status, ?string $requestId, array $result, ?string $message): void
    {
        if ($attemptId <= 0) {
            return;
        }
        $attempt = Db::name('generation_execution_attempts')->where('id', $attemptId)->find();
        if (!$attempt || in_array((string) $attempt['status'], ['succeeded', 'failed'], true)) {
            return;
        }
        if ($requestId === null || trim($requestId) === '') {
            $requestId = (string) (Db::name('generation_job_items')
                ->where('id', (int) $attempt['generation_item_id'])
                ->value('request_id') ?? '');
        }
        $durationMs = max(0, (int) round(
            (microtime(true) - (strtotime((string) $attempt['started_at']) ?: time())) * 1000
        ));
        Db::name('generation_execution_attempts')->where('id', $attemptId)->update([
            'status' => $status,
            'duration_ms' => $durationMs,
            'result_summary_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_code' => $message === null
                ? null
                : 'generation_failed',
            'error_message' => $message === null ? null : mb_strimwidth($message, 0, 1000, ''),
            'completed_at' => Web::now(),
        ] + $this->requestColumns((string) $requestId));
    }

    private function requestColumns(string $requestId): array
    {
        $requestId = mb_strimwidth(trim($requestId), 0, 190, '');
        if ($requestId === '') {
            return [];
        }
        if (str_starts_with($requestId, 'comfyui:')) {
            return [
                'client_request_id' => $requestId,
                'comfyui_prompt_id' => substr($requestId, strlen('comfyui:')),
                'provider_task_id' => $requestId,
            ];
        }
        if (str_starts_with($requestId, 'sub2api:')) {
            return [
                'client_request_id' => substr($requestId, strlen('sub2api:')),
                'sub2api_request_id' => $requestId,
            ];
        }
        return [
            'sub2api_request_id' => $requestId,
            'provider_task_id' => $requestId,
        ];
    }

    private function object(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
