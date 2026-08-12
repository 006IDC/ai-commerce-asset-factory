<?php

declare(strict_types=1);

namespace app\service;

use app\support\Audit;
use app\support\Web;
use RuntimeException;
use think\facade\Db;

final class GenerationItemQueue
{
    public function createItems(int $jobId, int $merchantId, array $assetTypes, int $estimatedCredits, string $status): void
    {
        if ($assetTypes === [] || Db::name('generation_job_items')->where('job_id', $jobId)->count() > 0) {
            return;
        }
        $typeTotals = array_count_values($assetTypes);
        $occurrences = [];
        $total = count($assetTypes);
        $now = Web::now();
        foreach (array_values($assetTypes) as $index => $assetType) {
            $assetType = (string) $assetType;
            $occurrences[$assetType] = ($occurrences[$assetType] ?? 0) + 1;
            Db::name('generation_job_items')->insert([
                'job_id' => $jobId,
                'merchant_id' => $merchantId,
                'position' => $index + 1,
                'asset_type' => $assetType,
                'occurrence' => $occurrences[$assetType],
                'type_total' => (int) ($typeTotals[$assetType] ?? 1),
                'estimated_credits' => $this->creditsForPosition($estimatedCredits, $total, $index),
                'status' => $status,
                'attempts' => 0,
                'stage_timestamps_json' => json_encode(
                    ['queued_at' => $now],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function hasItems(int $jobId): bool
    {
        return Db::name('generation_job_items')->where('job_id', $jobId)->count() > 0;
    }

    public function prepareNextJob(): ?array
    {
        $jobId = (int) (Db::name('generation_jobs')->alias('j')
            ->join('generation_job_items i', 'i.job_id = j.id')
            ->where('j.output_kind', 'image')
            ->where('j.status', 'queued')->where('j.safety_status', 'passed')->where('i.status', 'queued')
            ->order('j.id', 'asc')->value('j.id') ?? 0);
        if ($jobId <= 0) {
            return null;
        }

        return $this->transactionWithRetry(function () use ($jobId): ?array {
            $job = Db::name('generation_jobs')->where('id', $jobId)->find();
            if (!$job || $job['status'] !== 'queued') {
                return null;
            }
            $merchant = Db::name('merchants')->where('id', $job['merchant_id'])->lock(true)->find();
            $job = Db::name('generation_jobs')->where('id', $jobId)->lock(true)->find();
            if (!$job || $job['status'] !== 'queued') {
                return null;
            }
            $items = Db::name('generation_job_items')->where('job_id', $jobId)->where('status', 'queued')
                ->whereNull('reservation_ledger_id')->lock(true)->select()->toArray();
            if ($items === []) {
                Db::name('generation_jobs')->where('id', $jobId)->update(['status' => 'running', 'updated_at' => Web::now()]);
                return $job;
            }
            $requiredCredits = array_sum(array_map(static fn (array $item): int => max(0, (int) $item['estimated_credits']), $items));
            if (!$merchant || $merchant['status'] !== 'active' || (int) $merchant['credit_balance'] < $requiredCredits) {
                $message = !$merchant || $merchant['status'] !== 'active'
                    ? '商户账户当前不可用，未调用模型接口'
                    : "额度不足，任务剩余图片需要 {$requiredCredits} 点，未调用模型接口";
                $now = Web::now();
                Db::name('generation_job_items')->whereIn('id', array_column($items, 'id'))->update([
                    'status' => 'failed', 'error_message' => $message, 'completed_at' => $now, 'updated_at' => $now,
                ]);
                Db::name('generation_jobs')->where('id', $jobId)->update([
                    'status' => 'failed', 'error_message' => $message, 'progress_stage' => 'failed',
                    'progress_message' => $message, 'updated_at' => $now,
                ]);
                return $job + ['_rejected' => true, '_message' => $message];
            }

            $reservationId = null;
            $now = Web::now();
            if ($requiredCredits > 0) {
                $balance = (int) $merchant['credit_balance'] - $requiredCredits;
                Db::name('merchants')->where('id', $merchant['id'])->update(['credit_balance' => $balance, 'updated_at' => $now]);
                $reservationRound = (int) Db::name('credit_ledger')
                    ->where('merchant_id', $merchant['id'])
                    ->where('source', 'generation_job')
                    ->where('reference_type', 'generation_job')
                    ->where('reference_id', (string) $jobId)
                    ->whereIn('type', ['generation_reserve', 'generation_refunded', 'consume'])
                    ->count() + 1;
                $reservationId = (int) Db::name('credit_ledger')->insertGetId([
                    'merchant_id' => $merchant['id'], 'type' => 'generation_reserve', 'amount' => -$requiredCredits,
                    'balance_after' => $balance, 'source' => 'generation_job', 'reference_type' => 'generation_job',
                    'reference_id' => (string) $jobId,
                    'idempotency_key' => 'generation-reserve:job:' . $jobId . ':round:' . $reservationRound . ':items:'
                        . substr(hash('sha256', implode(',', array_map('intval', array_column($items, 'id')))), 0, 24),
                    'note' => $job['job_no'] . ' 图片子任务额度预扣', 'created_at' => $now,
                ]);
            }
            Db::name('generation_job_items')->whereIn('id', array_column($items, 'id'))->update([
                'reservation_ledger_id' => $reservationId, 'updated_at' => $now,
            ]);
            Db::name('generation_jobs')->where('id', $jobId)->update([
                'status' => 'running', 'error_message' => null, 'progress_stage' => 'generating',
                'progress_message' => '图片子任务已拆分，正在并行生成', 'updated_at' => $now,
            ]);

            return $job;
        });
    }

    public function claimNext(int $workerSlot, int $merchantLimit, int $jobLimit): ?array
    {
        $now = Web::now();
        $candidateIds = Db::name('generation_job_items')->alias('i')
            ->join('generation_jobs j', 'j.id = i.job_id')
            ->where('j.output_kind', 'image')
            ->whereIn('i.status', ['queued', 'workflow_processing', 'manual_review'])
            ->whereIn('j.status', ['running', 'manual_review'])
            ->where(static function ($query) use ($now): void {
                $query->whereNull('i.next_retry_at')->whereOr('i.next_retry_at', '<=', $now);
            })
            ->order('i.id', 'asc')->limit(100)->column('i.id');
        foreach ($candidateIds as $candidateId) {
            $claim = $this->transactionWithRetry(function () use ($candidateId, $workerSlot, $merchantLimit, $jobLimit): ?array {
                $item = Db::name('generation_job_items')->where('id', (int) $candidateId)->find();
                if (!$item || !in_array((string) $item['status'], ['queued', 'workflow_processing', 'manual_review'], true)) {
                    return null;
                }
                $previousStatus = (string) $item['status'];
                $merchant = Db::name('merchants')->where('id', $item['merchant_id'])->lock(true)->find();
                $job = Db::name('generation_jobs')->where('id', $item['job_id'])->lock(true)->find();
                $item = Db::name('generation_job_items')->where('id', $item['id'])->lock(true)->find();
                if (!$merchant || !$job || !$item || $merchant['status'] !== 'active'
                    || !in_array((string) $job['status'], ['running', 'manual_review'], true)
                    || !in_array((string) $item['status'], ['queued', 'workflow_processing', 'manual_review'], true)
                    || (!empty($item['next_retry_at']) && (string) $item['next_retry_at'] > Web::now())) {
                    return null;
                }
                // A ComfyUI request remains provider-active while it is in
                // workflow_processing. Counting only the short local
                // `processing` claim window let one worker submit an entire
                // batch immediately and bypass both concurrency limits.
                $providerActiveStatuses = ['processing', 'workflow_processing'];
                $merchantActive = (int) Db::name('generation_job_items')
                    ->where('merchant_id', $merchant['id'])
                    ->whereIn('status', $providerActiveStatuses)
                    ->count();
                $jobActive = (int) Db::name('generation_job_items')
                    ->where('job_id', $job['id'])
                    ->whereIn('status', $providerActiveStatuses)
                    ->count();
                // Polling an already-submitted ComfyUI request does not add a
                // provider task. Applying the submission limit here would
                // deadlock exactly when the active count reaches the limit:
                // every workflow_processing item would be refused before it
                // could be polled to completion.
                $pollingExistingRequest = in_array($previousStatus, ['workflow_processing', 'manual_review'], true)
                    && str_starts_with((string) ($item['request_id'] ?? ''), 'comfyui:');
                if (!$pollingExistingRequest
                    && ($merchantActive >= $merchantLimit || $jobActive >= $jobLimit)) {
                    return null;
                }
                $now = Web::now();
                $stages = $this->object($item['stage_timestamps_json'] ?? null);
                $stages['claimed_at'] ??= $now;
                $stages['generation_started_at'] ??= $now;
                Db::name('generation_job_items')->where('id', $item['id'])->update([
                    'status' => 'processing',
                    'attempts' => $previousStatus === 'queued' ? (int) $item['attempts'] + 1 : (int) $item['attempts'],
                    'worker_slot' => $workerSlot,
                    'started_at' => $previousStatus === 'queued' ? $now : ($item['started_at'] ?? $now),
                    'next_retry_at' => null,
                    'error_message' => null,
                    'stage_timestamps_json' => json_encode($stages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => $now,
                ]);
                $completed = (int) Db::name('generation_job_items')->where('job_id', $job['id'])->where('status', 'completed')->count();
                $processing = (int) Db::name('generation_job_items')->where('job_id', $job['id'])->where('status', 'processing')->count();
                $waiting = (int) Db::name('generation_job_items')->where('job_id', $job['id'])
                    ->whereIn('status', ['queued', 'workflow_processing', 'manual_review'])->count();
                $total = (int) Db::name('generation_job_items')->where('job_id', $job['id'])->count();
                Db::name('generation_jobs')->where('id', $job['id'])->update([
                    'status' => 'running', 'progress_current' => $completed, 'progress_total' => $total,
                    'progress_stage' => 'generating',
                    'progress_message' => "已完成 {$completed}/{$total} 张，生成中 {$processing} 张，等待 {$waiting} 张",
                    'updated_at' => $now,
                ]);
                $item['status'] = 'processing';
                $item['attempts'] = $previousStatus === 'queued' ? (int) $item['attempts'] + 1 : (int) $item['attempts'];
                $item['worker_slot'] = $workerSlot;
                $item['stage_timestamps_json'] = json_encode($stages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return ['item' => $item, 'job' => $job, 'merchant' => $merchant];
            });
            if ($claim !== null) {
                return $claim;
            }
        }

        return null;
    }

    public function complete(int $itemId, int $assetId, ?string $requestId): array
    {
        return $this->finish($itemId, 'completed', $assetId, $requestId, null);
    }

    public function fail(int $itemId, string $message): array
    {
        return $this->finish($itemId, 'failed', null, null, mb_strimwidth($message, 0, 500, '...'));
    }

    public function deferWorkflow(int $itemId, string $message, bool $manualReview = false): array
    {
        return $this->transactionWithRetry(function () use ($itemId, $message, $manualReview): array {
            $item = Db::name('generation_job_items')->where('id', $itemId)->find();
            if (!$item) {
                return ['status' => 'ignored'];
            }
            $job = Db::name('generation_jobs')->where('id', (int) $item['job_id'])->lock(true)->find();
            $item = Db::name('generation_job_items')->where('id', $itemId)->lock(true)->find();
            if (!$item || (string) $item['status'] !== 'processing'
                || !str_starts_with((string) ($item['request_id'] ?? ''), 'comfyui:')) {
                return ['status' => 'ignored'];
            }
            $now = Web::now();
            $status = $manualReview ? 'manual_review' : 'workflow_processing';
            $stages = $this->object($item['stage_timestamps_json'] ?? null);
            $stages['provider_submitted_at'] ??= (string) ($item['started_at'] ?? $now);
            $stages['provider_poll_deferred_at'] = $now;
            Db::name('generation_job_items')->where('id', $itemId)->update([
                'status' => $status,
                'worker_slot' => null,
                'next_retry_at' => date('Y-m-d H:i:s', time() + ($manualReview ? 3600 : 10)),
                'last_retry_reason' => mb_strimwidth($message, 0, 500, ''),
                'stage_timestamps_json' => json_encode($stages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'error_message' => null,
                'completed_at' => null,
                'updated_at' => $now,
            ]);
            if (!$job) {
                return ['status' => $status];
            }
            return $this->syncParentLocked($job);
        });
    }

    public function retryItems(int $jobId, string $status): int
    {
        $this->markMissingResults($jobId);
        $retryStatuses = $status === 'pending_gateway'
            ? ['pending_gateway', 'failed', 'result_missing']
            : ['failed', 'result_missing'];
        $itemIds = $this->manualRetryableItemIds($jobId, $retryStatuses);
        if ($itemIds === []) {
            return 0;
        }
        return (int) Db::name('generation_job_items')->whereIn('id', $itemIds)->update([
            'status' => $status === 'pending_gateway' ? 'pending_gateway' : 'queued',
            'request_id' => null,
            'worker_slot' => null, 'reservation_ledger_id' => null,
            'next_retry_at' => null, 'last_retry_reason' => null,
            'manual_retry_count' => Db::raw('manual_retry_count + 1'),
            'stage_timestamps_json' => json_encode(['queued_at' => Web::now()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_message' => null, 'started_at' => null, 'completed_at' => null, 'updated_at' => Web::now(),
        ]);
    }

    public function activatePendingItems(int $jobId): int
    {
        $this->markMissingResults($jobId);
        $itemIds = $this->manualRetryableItemIds($jobId, ['pending_gateway', 'failed', 'result_missing']);
        if ($itemIds === []) {
            return 0;
        }
        return (int) Db::name('generation_job_items')->whereIn('id', $itemIds)->update([
            'status' => 'queued', 'request_id' => null, 'worker_slot' => null, 'reservation_ledger_id' => null,
            'next_retry_at' => null, 'last_retry_reason' => null,
            'manual_retry_count' => Db::raw('manual_retry_count + 1'),
            'stage_timestamps_json' => json_encode(['queued_at' => Web::now()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_message' => null, 'started_at' => null, 'completed_at' => null, 'updated_at' => Web::now(),
        ]);
    }

    private function manualRetryableItemIds(int $jobId, array $statuses): array
    {
        $rows = Db::name('generation_job_items')
            ->where('job_id', $jobId)
            ->whereIn('status', $statuses)
            ->field('id,status,request_id')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $itemIds = [];
        foreach ($rows as $row) {
            $requestId = trim((string) ($row['request_id'] ?? ''));
            if ($requestId === '') {
                $itemIds[] = (int) $row['id'];
                continue;
            }
            if ((string) ($row['status'] ?? '') !== 'failed') {
                continue;
            }
            $attemptStatus = (string) (Db::name('generation_execution_attempts')
                ->where('generation_item_id', (int) $row['id'])
                ->order('attempt_no', 'desc')
                ->value('status') ?? '');
            if ($attemptStatus === 'failed') {
                $itemIds[] = (int) $row['id'];
            }
        }
        return array_values(array_unique(array_filter($itemIds)));
    }

    public function cancel(int $jobId, int $merchantId): ?array
    {
        return $this->transactionWithRetry(function () use ($jobId, $merchantId): ?array {
            $merchant = Db::name('merchants')->where('id', $merchantId)->lock(true)->find();
            $job = Db::name('generation_jobs')->where('id', $jobId)->where('merchant_id', $merchantId)->lock(true)->find();
            if (!$merchant || !$job || !in_array((string) $job['status'], ['pending_gateway', 'queued', 'running'], true)) {
                return null;
            }
            $items = Db::name('generation_job_items')->where('job_id', $jobId)->lock(true)->select()->toArray();
            $videoRequest = (string) ($job['output_kind'] ?? 'image') === 'video'
                ? Db::name('video_generation_requests')->where('job_id', $jobId)->lock(true)->find()
                : null;
            if (!$this->isCancelable($job, $items, $videoRequest ?: null)) {
                return null;
            }
            $now = Web::now();
            Db::name('generation_job_items')->where('job_id', $jobId)
                ->whereIn('status', ['pending_gateway', 'queued'])->update([
                    'status' => 'cancelled', 'worker_slot' => null, 'error_message' => '用户取消任务',
                    'completed_at' => $now, 'updated_at' => $now,
                ]);
            $items = Db::name('generation_job_items')->where('job_id', $jobId)->select()->toArray();
            $this->settleReservations($job, $items);
            $completed = count(array_filter($items, static fn (array $item): bool => $item['status'] === 'completed'));
            $total = count($items) ?: max(0, (int) ($job['progress_total'] ?? 0));
            Db::name('generation_jobs')->where('id', $jobId)->update([
                'status' => 'cancelled', 'error_message' => null,
                'progress_current' => $completed, 'progress_total' => $total,
                'progress_stage' => 'cancelled',
                'progress_message' => $completed > 0
                    ? "任务已取消，已保留 {$completed}/{$total} 张已完成图片"
                    : '任务已取消，未产生图片，未消耗额度已退回',
                'updated_at' => $now,
            ]);
            return ['completed' => $completed, 'total' => $total];
        });
    }

    public function canCancel(int $jobId, ?int $merchantId = null): bool
    {
        $query = Db::name('generation_jobs')->where('id', $jobId);
        if ($merchantId !== null && $merchantId > 0) {
            $query->where('merchant_id', $merchantId);
        }
        $job = $query->find();
        if (!$job) {
            return false;
        }
        $items = Db::name('generation_job_items')->where('job_id', $jobId)->select()->toArray();
        $videoRequest = (string) ($job['output_kind'] ?? 'image') === 'video'
            ? Db::name('video_generation_requests')->where('job_id', $jobId)->find()
            : null;
        return $this->isCancelable($job, $items, $videoRequest ?: null);
    }

    private function isCancelable(array $job, array $items, ?array $videoRequest): bool
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

    public function recoverStale(int $minutes = 20): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - max(10, $minutes) * 60);
        $now = Web::now();
        $protectedJobIds = array_map('intval', Db::name('generation_job_items')
            ->where('status', 'processing')
            ->where('updated_at', '<', $cutoff)
            ->whereNotNull('request_id')
            ->where('request_id', '<>', '')
            ->distinct(true)
            ->column('job_id'));
        $protected = (int) Db::name('generation_job_items')->where('status', 'processing')
            ->where('updated_at', '<', $cutoff)
            ->whereNotNull('request_id')
            ->where('request_id', '<>', '')
            ->update([
                'status' => 'workflow_processing',
                'worker_slot' => null,
                'next_retry_at' => date('Y-m-d H:i:s', time() + 10),
                'last_retry_reason' => '上游请求已提交，Worker 心跳中断后继续使用原请求号对账。',
                'error_message' => null,
                'completed_at' => null,
                'updated_at' => $now,
            ]);
        $requeued = (int) Db::name('generation_job_items')->where('status', 'processing')
            ->where('updated_at', '<', $cutoff)
            ->where(static function ($query): void {
                $query->whereNull('request_id')->whereOr('request_id', '');
            })->update([
                'status' => 'queued', 'worker_slot' => null, 'error_message' => 'Worker 心跳中断，子任务已自动重新排队',
                'started_at' => null, 'updated_at' => $now,
            ]);
        foreach ($protectedJobIds as $jobId) {
            $this->transactionWithRetry(function () use ($jobId): void {
                $job = Db::name('generation_jobs')->where('id', $jobId)->lock(true)->find();
                if ($job) {
                    $this->syncParentLocked($job);
                }
            });
        }
        return $protected + $requeued;
    }

    public function counts(): array
    {
        $counts = [
            'queued' => 0,
            'processing' => 0,
            'workflow_processing' => 0,
            'manual_review' => 0,
            'completed' => 0,
            'failed' => 0,
            'result_missing' => 0,
        ];
        $rows = Db::name('generation_job_items')->field('status,COUNT(*) AS total')->group('status')->select()->toArray();
        foreach ($rows as $row) {
            if (array_key_exists((string) $row['status'], $counts)) {
                $counts[(string) $row['status']] = (int) $row['total'];
            }
        }
        return $counts;
    }

    private function finish(int $itemId, string $status, ?int $assetId, ?string $requestId, ?string $message): array
    {
        return $this->transactionWithRetry(function () use ($itemId, $status, $assetId, $requestId, $message): array {
            $item = Db::name('generation_job_items')->where('id', $itemId)->find();
            if (!$item) {
                return ['status' => 'ignored'];
            }
            Db::name('merchants')->where('id', $item['merchant_id'])->lock(true)->find();
            $job = Db::name('generation_jobs')->where('id', $item['job_id'])->lock(true)->find();
            $item = Db::name('generation_job_items')->where('id', $itemId)->lock(true)->find();
            if (!$item || $item['status'] !== 'processing') {
                return ['status' => 'ignored'];
            }
            if ($status === 'completed') {
                $asset = $assetId === null ? null : Db::name('assets')
                    ->where('id', $assetId)
                    ->where('job_id', (int) $item['job_id'])
                    ->where('merchant_id', (int) $item['merchant_id'])
                    ->where('generation_item_id', (int) $item['id'])
                    ->where('status', 'active')
                    ->where('file_url', '<>', '')
                    ->lock(true)
                    ->find();
                if (!$asset) {
                    $status = 'result_missing';
                    $assetId = null;
                    $message = '模型处理已结束，但没有保存出可用图片，未产生的图片不会扣费。';
                }
            }
            $now = Web::now();
            $stages = $this->object($item['stage_timestamps_json'] ?? null);
            $stages[$status === 'completed' ? 'saved_at' : 'terminal_at'] = $now;
            $persistedRequestId = trim((string) ($item['request_id'] ?? ''));
            if ($requestId === null || trim($requestId) === '') {
                $requestId = $persistedRequestId !== '' ? $persistedRequestId : null;
            }
            Db::name('generation_job_items')->where('id', $itemId)->update([
                'status' => $status, 'asset_id' => $assetId, 'request_id' => $requestId,
                'stage_timestamps_json' => json_encode($stages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'error_message' => $message, 'worker_slot' => null, 'completed_at' => $now, 'updated_at' => $now,
            ]);
            if (!$job) {
                return ['status' => 'orphaned'];
            }
            return $this->syncParentLocked($job);
        });
    }

    private function syncParentLocked(array $job): array
    {
        $this->markMissingResults((int) $job['id']);
        $items = Db::name('generation_job_items')->where('job_id', $job['id'])->select()->toArray();
        $total = count($items);
        $completed = count(array_filter($items, static fn (array $item): bool => $item['status'] === 'completed'));
        $failed = count(array_filter($items, static fn (array $item): bool => in_array($item['status'], ['failed', 'result_missing'], true)));
        $missing = count(array_filter($items, static fn (array $item): bool => $item['status'] === 'result_missing'));
        $processing = count(array_filter($items, static fn (array $item): bool => $item['status'] === 'processing'));
        $workflowProcessing = count(array_filter(
            $items,
            static fn (array $item): bool => $item['status'] === 'workflow_processing'
        ));
        $manualReview = count(array_filter($items, static fn (array $item): bool => $item['status'] === 'manual_review'));
        $waiting = $total - $completed - $failed - $processing;
        $now = Web::now();
        if ($processing + $waiting > 0) {
            $parentStatus = $manualReview > 0 ? 'manual_review' : 'running';
            $stage = $manualReview > 0
                ? 'manual_review'
                : ($workflowProcessing > 0 ? 'workflow_processing' : ($processing > 0 ? 'generating' : 'queued'));
            $message = $manualReview > 0
                ? "已完成 {$completed}/{$total} 张，{$manualReview} 张待人工核对；原请求号已保留，不会重发或自动退款"
                : ($workflowProcessing > 0
                    ? "已完成 {$completed}/{$total} 张，{$workflowProcessing} 张模型处理中，系统正复用原请求号收取结果"
                    : "已完成 {$completed}/{$total} 张，生成中 {$processing} 张，等待 {$waiting} 张");
            Db::name('generation_jobs')->where('id', $job['id'])->update([
                'status' => $parentStatus, 'progress_current' => $completed, 'progress_total' => $total,
                'progress_stage' => $stage,
                'progress_message' => $message,
                'updated_at' => $now,
            ]);
            return compact(
                'completed',
                'failed',
                'processing',
                'workflowProcessing',
                'manualReview',
                'waiting',
                'total'
            ) + ['status' => $parentStatus];
        }

        $this->settleReservations($job, $items);
        $firstError = '';
        foreach ($items as $item) {
            if (in_array($item['status'], ['failed', 'result_missing'], true) && (string) $item['error_message'] !== '') {
                $firstError = (string) $item['error_message'];
                break;
            }
        }
        $finalStatus = $completed === $total ? 'completed' : ($completed > 0 ? 'partial' : 'failed');
        $message = match ($finalStatus) {
            'completed' => "全部 {$total} 张图片已生成并保存到素材库",
            'partial' => "已完成 {$completed}/{$total} 张并保存到素材库，可只重试未完成图片",
            default => $missing > 0
                ? "任务未保存出可用图片，{$missing} 个结果缺失，可重新排队"
                : "本次 {$total} 张图片均未生成成功，可重新排队",
        };
        Db::name('generation_jobs')->where('id', $job['id'])->update([
            'status' => $finalStatus, 'error_message' => $firstError !== '' ? $firstError : null,
            'progress_current' => $completed, 'progress_total' => $total,
            'progress_stage' => $finalStatus, 'progress_message' => $message, 'updated_at' => $now,
        ]);
        Audit::log('system', 0, 'generation_job.' . $finalStatus, 'generation_job', $job['id'], '127.0.0.1', [
            'completed_items' => $completed, 'failed_items' => $failed, 'missing_results' => $missing, 'parallel_queue' => true,
        ]);

        return compact('completed', 'failed', 'missing', 'processing', 'waiting', 'total') + ['status' => $finalStatus];
    }

    private function markMissingResults(int $jobId): int
    {
        $completedItems = Db::name('generation_job_items')->where('job_id', $jobId)->where('status', 'completed')
            ->field('id,asset_id')->select()->toArray();
        if ($completedItems === []) {
            return 0;
        }
        $itemIds = array_map(static fn (array $item): int => (int) $item['id'], $completedItems);
        $validItemIds = array_map('intval', Db::name('assets')->where('job_id', $jobId)
            ->whereIn('generation_item_id', $itemIds)->where('status', 'active')->where('file_url', '<>', '')
            ->column('generation_item_id'));
        $missingItemIds = array_values(array_diff($itemIds, $validItemIds));
        if ($missingItemIds === []) {
            return 0;
        }
        return (int) Db::name('generation_job_items')->whereIn('id', $missingItemIds)->where('status', 'completed')->update([
            'status' => 'result_missing',
            'error_message' => '模型处理已结束，但没有保存出可用图片，未产生的图片不会扣费。',
            'updated_at' => Web::now(),
        ]);
    }

    private function settleReservations(array $job, array $items): void
    {
        $reservationIds = array_values(array_unique(array_filter(array_map('intval', array_column($items, 'reservation_ledger_id')))));
        $legacyReservationIds = Db::name('credit_ledger')->where('merchant_id', $job['merchant_id'])
            ->where('type', 'generation_reserve')->where('reference_type', 'generation_job')
            ->where('reference_id', (string) $job['id'])->column('id');
        $reservationIds = array_values(array_unique(array_merge($reservationIds, array_map('intval', $legacyReservationIds))));
        foreach ($reservationIds as $reservationId) {
            $reservation = Db::name('credit_ledger')->where('id', $reservationId)->lock(true)->find();
            if (!$reservation || $reservation['type'] !== 'generation_reserve') {
                continue;
            }
            $reserved = abs((int) $reservation['amount']);
            $consumed = 0;
            foreach ($items as $item) {
                if ((int) $item['reservation_ledger_id'] === $reservationId && $item['status'] === 'completed') {
                    $consumed += max(0, (int) $item['estimated_credits']);
                }
            }
            if ($items === []) {
                $consumed = max(0, (int) ($job['actual_credits'] ?? 0));
            }
            $consumed = min($reserved, $consumed);
            $refund = $reserved - $consumed;
            Db::name('credit_ledger')->where('id', $reservationId)->update($consumed > 0 ? [
                'type' => 'consume', 'amount' => -$consumed, 'note' => $job['job_no'] . ' 图片子任务结算',
            ] : [
                'type' => 'generation_refunded', 'note' => $job['job_no'] . ' 图片子任务预扣已退回',
            ]);
            if ($consumed > 0) {
                foreach ($items as $item) {
                    if ((int) ($item['reservation_ledger_id'] ?? 0) === $reservationId
                        && (string) ($item['status'] ?? '') === 'completed') {
                        Db::name('generation_job_items')->where('id', (int) $item['id'])->update([
                        'charged_credits' => max(0, (int) ($item['estimated_credits'] ?? 0)),
                        'consume_ledger_id' => $reservationId,
                        'updated_at' => Web::now(),
                    ]);
                    }
                }
            }
            if ($refund > 0) {
                $merchant = Db::name('merchants')->where('id', $job['merchant_id'])->lock(true)->find();
                if (!$merchant) {
                    throw new RuntimeException('商户账户不存在，无法退回图片子任务额度');
                }
                $balance = (int) $merchant['credit_balance'] + $refund;
                $now = Web::now();
                Db::name('merchants')->where('id', $merchant['id'])->update(['credit_balance' => $balance, 'updated_at' => $now]);
                $refundLedgerId = (int) Db::name('credit_ledger')->insertGetId([
                    'merchant_id' => $merchant['id'], 'type' => 'generation_refund', 'amount' => $refund,
                    'balance_after' => $balance, 'source' => 'generation_job', 'reference_type' => 'credit_ledger',
                    'reference_id' => (string) $reservationId,
                    'idempotency_key' => 'technical-refund:reservation:' . $reservationId,
                    'note' => $job['job_no'] . ' 失败图片退款', 'created_at' => $now,
                ]);
                $failedIds = [];
                foreach ($items as $item) {
                    if ((int) ($item['reservation_ledger_id'] ?? 0) === $reservationId
                        && in_array((string) ($item['status'] ?? ''), ['failed', 'result_missing', 'cancelled'], true)) {
                        $failedIds[] = (int) $item['id'];
                    }
                }
                if ($failedIds !== []) {
                    Db::name('generation_job_items')->whereIn('id', $failedIds)->update([
                        'charged_credits' => 0,
                        'technical_refund_ledger_id' => $refundLedgerId,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    private function creditsForPosition(int $totalCredits, int $count, int $index): int
    {
        if ($count <= 0 || $totalCredits <= 0) {
            return 0;
        }
        $base = intdiv($totalCredits, $count);
        return $base + ($index < ($totalCredits % $count) ? 1 : 0);
    }

    private function transactionWithRetry(callable $callback): mixed
    {
        for ($attempt = 1; $attempt <= 12; $attempt++) {
            try {
                return Db::transaction($callback);
            } catch (\Throwable $exception) {
                $message = strtolower($exception->getMessage());
                $retryable = str_contains($message, 'database is locked')
                    || str_contains($message, 'deadlock detected')
                    || str_contains($message, 'serialization failure');
                if (!$retryable || $attempt >= 12) {
                    throw $exception;
                }
                try {
                    Db::connect()->close();
                } catch (\Throwable) {
                }
                usleep(random_int(50000, 150000) * $attempt);
            }
        }
        throw new RuntimeException('数据库并发事务重试失败');
    }

    private function object(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
