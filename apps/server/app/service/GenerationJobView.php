<?php

declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class GenerationJobView
{
    public function enrich(array $jobs): array
    {
        $jobIds = array_values(array_filter(array_map(static fn (array $job): int => (int) ($job['id'] ?? 0), $jobs)));
        $timings = [];
        $itemsByJob = [];
        $assetsByJob = [];
        $assetsByItem = [];
        $videoRequestsByJob = [];
        $modelNames = [];
        $resolvedModelIds = array_values(array_unique(array_filter(array_map(
            static fn (array $job): int => max(0, (int) ($job['resolved_ai_model_id'] ?? 0)),
            $jobs
        ))));
        if ($resolvedModelIds !== []) {
            foreach (Db::name('ai_models')->whereIn('id', $resolvedModelIds)
                ->field('id,model_id,display_name')->select()->toArray() as $row) {
                $modelNames[(int) $row['id']] = trim((string) ($row['display_name'] ?? ''))
                    ?: (string) ($row['model_id'] ?? '');
            }
        }
        if ($jobIds !== []) {
            foreach (Db::name('generation_job_items')->whereIn('job_id', $jobIds)
                ->field('id,job_id,position,asset_type,status,asset_id,request_id,stage_timestamps_json,last_retry_reason,started_at,completed_at')
                ->select()->toArray() as $row) {
                $jobId = (int) $row['job_id'];
                $itemsByJob[$jobId][] = $row;
                $startedAt = trim((string) ($row['started_at'] ?? ''));
                $completedAt = trim((string) ($row['completed_at'] ?? ''));
                if ($startedAt !== '' && (!isset($timings[$jobId]['generation_started_at']) || $startedAt < $timings[$jobId]['generation_started_at'])) {
                    $timings[$jobId]['generation_started_at'] = $startedAt;
                }
                if ($completedAt !== '' && (!isset($timings[$jobId]['generation_completed_at']) || $completedAt > $timings[$jobId]['generation_completed_at'])) {
                    $timings[$jobId]['generation_completed_at'] = $completedAt;
                }
            }
            foreach (Db::name('assets')->whereIn('job_id', $jobIds)->where('status', 'active')
                ->field('id,job_id,generation_item_id,asset_type,media_type,file_url,poster_url')->select()->toArray() as $row) {
                if (trim((string) ($row['file_url'] ?? '')) === '') {
                    continue;
                }
                $jobId = (int) $row['job_id'];
                $assetsByJob[$jobId][] = $row;
                $itemId = (int) ($row['generation_item_id'] ?? 0);
                if ($itemId > 0) {
                    $assetsByItem[$itemId] = $row;
                }
            }
            foreach (Db::name('video_generation_requests')->whereIn('job_id', $jobIds)
                ->field('job_id,provider_request_id,provider_status,provider_progress,submit_attempts,duration_seconds,reference_image_url,last_polled_at')
                ->select()->toArray() as $row) {
                $videoRequestsByJob[(int) $row['job_id']] = $row;
            }
        }

        foreach ($jobs as &$job) {
            $jobId = (int) $job['id'];
            $timing = $timings[$jobId] ?? [];
            $jobItems = $itemsByJob[$jobId] ?? [];
            $activeAssets = $assetsByJob[$jobId] ?? [];
            $isVideo = (string) ($job['output_kind'] ?? 'image') === 'video';
            $videoRequest = $videoRequestsByJob[$jobId] ?? null;
            $startedAt = (string) ($timing['generation_started_at'] ?? '');
            $completedAt = (string) ($timing['generation_completed_at'] ?? '');
            $job['generation_started_at'] = $startedAt;
            $job['generation_completed_at'] = $completedAt;
            $job['duration_label'] = $this->durationLabel($job, $startedAt, $completedAt);
            $job['progress_message'] = VisibleCopy::merchantProgress(
                \user_credit_terms((string) ($job['progress_message'] ?? ''))
            );
            $rawError = trim((string) ($job['error_message'] ?? ''));
            $job['error_message'] = $rawError === '' ? '' : \localized_job_error($rawError);
            $job['raw_error_message'] = $rawError;
            $routeSnapshot = json_decode((string) ($job['route_snapshot_json'] ?? ''), true);
            $routeSnapshot = is_array($routeSnapshot) ? $routeSnapshot : [];
            $mediaSnapshot = is_array($routeSnapshot['mediaWorkflow'] ?? null)
                ? $routeSnapshot['mediaWorkflow']
                : (is_array($routeSnapshot['media_workflow'] ?? null) ? $routeSnapshot['media_workflow'] : []);
            $job['execution_method_label'] = (string) ($mediaSnapshot['workflow_engine'] ?? 'direct') === 'comfyui'
                ? 'ComfyUI 工作流'
                : 'Direct / Sub2API';
            $resolvedModelId = max(0, (int) ($job['resolved_ai_model_id'] ?? 0));
            $job['display_model_name'] = trim((string) ($routeSnapshot['displayName'] ?? ''))
                ?: trim((string) ($modelNames[$resolvedModelId] ?? ''))
                ?: trim((string) ($job['model'] ?? ''))
                ?: '系统默认模型';

            $requestIds = [];
            $validCompletedItems = 0;
            foreach ($jobItems as $item) {
                $requestId = trim((string) ($item['request_id'] ?? ''));
                if ($requestId !== '') {
                    $requestIds[$requestId] = true;
                }
                if ((string) ($item['status'] ?? '') === 'completed' && isset($assetsByItem[(int) $item['id']])) {
                    $validCompletedItems++;
                }
            }
            $assetTypes = \decode_json_list($job['asset_types'] ?? '[]');
            $expectedResults = $isVideo
                ? 1
                : max(
                    count($jobItems),
                    count($assetTypes),
                    max(0, (int) ($job['progress_total'] ?? 0))
                );
            $availableResults = $isVideo
                ? count(array_filter($activeAssets, static fn (array $asset): bool => (string) ($asset['media_type'] ?? 'image') === 'video'))
                : ($jobItems !== [] ? $validCompletedItems : count($activeAssets));
            if ($expectedResults === 0 && $availableResults > 0) {
                $expectedResults = $availableResults;
            }
            $job['item_count'] = count($jobItems);
            $job['available_result_count'] = $availableResults;
            $job['expected_result_count'] = $expectedResults;
            $job['result_preview_urls'] = array_values(array_slice(array_map(
                static fn (array $asset): string => (string) (
                    (string) ($asset['media_type'] ?? 'image') === 'video'
                        ? ($asset['poster_url'] ?? '')
                        : ($asset['file_url'] ?? '')
                ),
                $activeAssets
            ), 0, 3));
            $job['result_preview_urls'] = array_values(array_filter($job['result_preview_urls']));
            if ($videoRequest) {
                $providerRequestId = trim((string) ($videoRequest['provider_request_id'] ?? ''));
                if ($providerRequestId !== '') {
                    $requestIds[$providerRequestId] = true;
                }
                $job['video_provider_status'] = (string) ($videoRequest['provider_status'] ?? '');
                $job['video_provider_progress'] = max(0, min(100, (int) ($videoRequest['provider_progress'] ?? 0)));
                $job['video_duration_seconds'] = max(0, (int) ($videoRequest['duration_seconds'] ?? 0));
                $job['video_reference_image'] = (string) ($videoRequest['reference_image_url'] ?? '');
            }
            $job['request_ids'] = array_keys($requestIds);
            foreach ($jobItems as &$jobItem) {
                $jobItem['stage_timestamps'] = $this->object($jobItem['stage_timestamps_json'] ?? null);
                unset($jobItem['stage_timestamps_json']);
            }
            unset($jobItem);
            $job['generation_items'] = $jobItems;
            $job['can_cancel'] = $this->canCancel($job, $jobItems, $videoRequest);
            $job['processing_status'] = $this->processingStatus((string) ($job['status'] ?? ''));
            $job['result_status'] = $this->resultStatus(
                (string) ($job['status'] ?? ''),
                $availableResults,
                $expectedResults
            );
            $job['result_status_label'] = match ($job['result_status']) {
                'available' => '结果可用',
                'partial' => '部分可用',
                'missing' => '结果缺失',
                'pending' => '等待结果',
                default => '暂无结果',
            };
        }
        unset($job);
        return $jobs;
    }

    private function canCancel(array $job, array $items, ?array $videoRequest): bool
    {
        if (!in_array((string) ($job['status'] ?? ''), ['pending_gateway', 'queued', 'running'], true)
            || trim((string) ($job['new_api_request_id'] ?? '')) !== '') {
            return false;
        }
        if ((string) ($job['output_kind'] ?? 'image') === 'video') {
            if ($videoRequest === null) {
                return in_array((string) ($job['status'] ?? ''), ['pending_gateway', 'queued'], true);
            }
            return (string) ($videoRequest['provider_status'] ?? '') === 'queued'
                && trim((string) ($videoRequest['provider_request_id'] ?? '')) === ''
                && (int) ($videoRequest['submit_attempts'] ?? 0) === 0;
        }
        if ($items === []) {
            return in_array((string) ($job['status'] ?? ''), ['pending_gateway', 'queued'], true);
        }
        foreach ($items as $item) {
            if (!in_array((string) ($item['status'] ?? ''), ['pending_gateway', 'queued'], true)
                || trim((string) ($item['request_id'] ?? '')) !== ''
                || !empty($item['started_at'])) {
                return false;
            }
        }
        return true;
    }

    private function processingStatus(string $status): string
    {
        return match ($status) {
            'pending_gateway', 'queued' => 'waiting',
            'running', 'processing', 'retrying', 'workflow_processing' => 'running',
            'manual_review' => 'manual_review',
            'completed', 'partial' => 'completed',
            'failed', 'result_missing' => 'failed',
            'cancelled' => 'cancelled',
            default => $status,
        };
    }

    private function resultStatus(string $status, int $available, int $expected): string
    {
        if (in_array($status, ['pending_gateway', 'queued', 'running', 'processing', 'retrying', 'workflow_processing', 'manual_review'], true)) {
            return 'pending';
        }
        if ($expected > 0 && $available >= $expected) {
            return 'available';
        }
        if ($available > 0) {
            return 'partial';
        }
        if (in_array($status, ['completed', 'partial', 'failed', 'result_missing'], true) && $expected > 0) {
            return 'missing';
        }
        return 'none';
    }

    private function durationLabel(array $job, string $startedAt, string $completedAt): string
    {
        $status = (string) ($job['status'] ?? '');
        if ($startedAt === '') {
            if (in_array($status, ['pending_gateway', 'queued'], true)) {
                return '尚未开始';
            }
            $startedAt = (string) ($job['created_at'] ?? '');
        }
        $started = strtotime($startedAt);
        if ($started === false) {
            return '-';
        }
        if (in_array($status, ['running', 'processing', 'workflow_processing', 'manual_review'], true)) {
            return '已用 ' . $this->formatSeconds(max(0, time() - $started));
        }
        $ended = strtotime($completedAt !== '' ? $completedAt : (string) ($job['updated_at'] ?? ''));
        if ($ended === false || $ended < $started) {
            return '-';
        }
        return $this->formatSeconds($ended - $started);
    }

    private function formatSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' 秒';
        }
        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;
        if ($minutes < 60) {
            return $minutes . ' 分 ' . $remaining . ' 秒';
        }
        $hours = intdiv($minutes, 60);
        return $hours . ' 小时 ' . ($minutes % 60) . ' 分';
    }

    private function object(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function list(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }
}
