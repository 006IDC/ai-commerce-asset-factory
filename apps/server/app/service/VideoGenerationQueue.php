<?php

declare(strict_types=1);

namespace app\service;

use app\support\Audit;
use app\support\Web;
use RuntimeException;
use Throwable;
use think\facade\Db;

final class VideoGenerationQueue
{
    private const SUBMIT_STATUSES = ['queued', 'retrying'];
    private const POLL_STATUSES = ['submitted', 'pending', 'queued', 'running', 'processing', 'retrying', 'manual_review'];
    private const FINAL_STATUSES = ['succeeded', 'failed', 'cancelled'];

    public function __construct(
        private readonly MediaWorkflowEngine $workflowEngine,
        private readonly AssetStorage $storage,
        private readonly SystemSettings $settings,
        private readonly AiModelCallRecorder $callRecorder,
        private readonly CreativeProjectService $projects
    ) {
    }

    public function processNext(): ?array
    {
        $this->recoverInterruptedClaims();
        $this->settleCancelledJobs();

        $claim = $this->claimSubmission();
        if ($claim !== null) {
            return $this->submit($claim);
        }
        $claim = $this->claimPoll();
        if ($claim !== null) {
            return $this->poll($claim);
        }
        return null;
    }

    public function stats(): array
    {
        $rows = Db::name('video_generation_requests')
            ->field('provider_status,COUNT(*) AS total')
            ->group('provider_status')
            ->select()
            ->toArray();
        $stats = ['queued' => 0, 'running' => 0, 'failed' => 0, 'completed' => 0];
        foreach ($rows as $row) {
            $status = strtolower((string) $row['provider_status']);
            $count = (int) $row['total'];
            if (in_array($status, ['queued', 'retrying', 'submitted', 'pending'], true)) {
                $stats['queued'] += $count;
            } elseif (in_array($status, ['submitting', 'polling', 'running', 'processing'], true)) {
                $stats['running'] += $count;
            } elseif ($status === 'succeeded') {
                $stats['completed'] += $count;
            } elseif (in_array($status, ['failed', 'cancelled'], true)) {
                $stats['failed'] += $count;
            }
        }
        return $stats;
    }

    public function requeue(int $jobId, ?int $merchantId = null): bool
    {
        $requeued = Db::transaction(function () use ($jobId, $merchantId): bool {
            $jobQuery = Db::name('generation_jobs')->where('id', $jobId)->where('output_kind', 'video');
            if ($merchantId !== null && $merchantId > 0) {
                $jobQuery->where('merchant_id', $merchantId);
            }
            $job = $jobQuery->lock(true)->find();
            $request = $job ? Db::name('video_generation_requests')->where('job_id', $jobId)->lock(true)->find() : null;
            $status = (string) ($job['status'] ?? '');
            $retryableStatuses = ['failed', 'partial', 'result_missing', 'pending_gateway'];
            if ($status === 'completed') {
                $hasActiveResult = Db::name('assets')
                    ->where('job_id', $jobId)
                    ->where('media_type', 'video')
                    ->where('status', 'active')
                    ->where('file_url', '<>', '')
                    ->find();
                if ($hasActiveResult) {
                    return false;
                }
                $retryableStatuses[] = 'completed';
            }
            if (!$job || !$request || !in_array($status, $retryableStatuses, true)) {
                return false;
            }
            $now = Web::now();
            Db::name('video_generation_requests')->where('id', (int) $request['id'])->update([
                'provider_request_id' => null,
                'provider_status' => 'queued',
                'provider_progress' => 0,
                'submit_attempts' => 0,
                'poll_attempts' => 0,
                'next_poll_at' => null,
                'last_polled_at' => null,
                'video_url' => null,
                'last_frame_url' => null,
                'result_payload_json' => null,
                'reservation_ledger_id' => null,
                'updated_at' => $now,
            ]);
            $safetyReset = (string) ($job['safety_status'] ?? '') === 'failed' ? [
                'safety_status' => 'pending',
                'safety_attempts' => 0,
                'safety_next_retry_at' => null,
                'safety_checked_at' => null,
            ] : [];
            Db::name('generation_jobs')->where('id', $jobId)->update($safetyReset + [
                'status' => 'queued',
                'actual_credits' => 0,
                'progress_current' => 0,
                'progress_total' => 100,
                'progress_stage' => (string) ($job['safety_status'] ?? '') === 'failed' ? 'safety_pending' : 'queued',
                'progress_message' => (string) ($job['safety_status'] ?? '') === 'failed'
                    ? '视频任务已重新排队，等待后台安全检查'
                    : '视频任务已重新排队，等待后台处理',
                'error_message' => null,
                'new_api_request_id' => null,
                'updated_at' => $now,
            ]);
            return true;
        });
        if ($requeued) {
            $this->projects->updateRunStatus($jobId, 'queued');
        }
        return $requeued;
    }

    private function claimSubmission(): ?array
    {
        $now = Web::now();
        $candidateIds = Db::name('video_generation_requests')->alias('v')
            ->join('generation_jobs j', 'j.id=v.job_id')
            ->where('j.output_kind', 'video')
            ->where('j.safety_status', 'passed')
            ->whereIn('j.status', ['queued', 'running'])
            ->whereIn('v.provider_status', self::SUBMIT_STATUSES)
            ->whereNull('v.provider_request_id')
            ->where(static function ($query) use ($now): void {
                $query->whereNull('v.next_poll_at')->whereOr('v.next_poll_at', '<=', $now);
            })
            ->order('v.id', 'asc')
            ->limit(20)
            ->column('v.id');
        foreach ($candidateIds as $candidateId) {
            $claim = Db::transaction(function () use ($candidateId): ?array {
                $request = Db::name('video_generation_requests')->where('id', (int) $candidateId)->lock(true)->find();
                if (!$request
                    || !in_array((string) $request['provider_status'], self::SUBMIT_STATUSES, true)
                    || trim((string) ($request['provider_request_id'] ?? '')) !== ''
                    || (!empty($request['next_poll_at']) && (string) $request['next_poll_at'] > Web::now())) {
                    return null;
                }
                $job = Db::name('generation_jobs')->where('id', (int) $request['job_id'])->lock(true)->find();
                if (!$job
                    || (string) ($job['output_kind'] ?? 'image') !== 'video'
                    || (string) $job['safety_status'] !== 'passed'
                    || !in_array((string) $job['status'], ['queued', 'running'], true)) {
                    return null;
                }
                Db::name('video_generation_requests')->where('id', (int) $request['id'])->update([
                    'provider_status' => 'submitting',
                    'submit_attempts' => (int) $request['submit_attempts'] + 1,
                    'updated_at' => Web::now(),
                ]);
                $request['provider_status'] = 'submitting';
                $request['submit_attempts'] = (int) $request['submit_attempts'] + 1;
                return ['request' => $request, 'job' => $job];
            });
            if ($claim !== null) {
                return $claim;
            }
        }
        return null;
    }

    private function claimPoll(): ?array
    {
        $now = Web::now();
        $candidateIds = Db::name('video_generation_requests')->alias('v')
            ->join('generation_jobs j', 'j.id=v.job_id')
            ->where('j.output_kind', 'video')
            ->whereIn('j.status', ['running', 'manual_review'])
            ->whereIn('v.provider_status', self::POLL_STATUSES)
            ->whereNotNull('v.provider_request_id')
            ->where('v.provider_request_id', '<>', '')
            ->where(static function ($query) use ($now): void {
                $query->whereNull('v.next_poll_at')->whereOr('v.next_poll_at', '<=', $now);
            })
            ->order('v.next_poll_at', 'asc')
            ->order('v.id', 'asc')
            ->limit(50)
            ->column('v.id');
        foreach ($candidateIds as $candidateId) {
            $claim = Db::transaction(function () use ($candidateId): ?array {
                $request = Db::name('video_generation_requests')->where('id', (int) $candidateId)->lock(true)->find();
                if (!$request
                    || !in_array((string) $request['provider_status'], self::POLL_STATUSES, true)
                    || trim((string) ($request['provider_request_id'] ?? '')) === ''
                    || (!empty($request['next_poll_at']) && (string) $request['next_poll_at'] > Web::now())) {
                    return null;
                }
                $job = Db::name('generation_jobs')->where('id', (int) $request['job_id'])->lock(true)->find();
                if (!$job || !in_array((string) $job['status'], ['running', 'manual_review'], true)
                    || (string) ($job['output_kind'] ?? 'image') !== 'video') {
                    return null;
                }
                Db::name('video_generation_requests')->where('id', (int) $request['id'])->update([
                    'provider_status' => 'polling',
                    'poll_attempts' => (int) $request['poll_attempts'] + 1,
                    'last_polled_at' => Web::now(),
                    'updated_at' => Web::now(),
                ]);
                $request['provider_status'] = 'polling';
                $request['poll_attempts'] = (int) $request['poll_attempts'] + 1;
                return ['request' => $request, 'job' => $job];
            });
            if ($claim !== null) {
                return $claim;
            }
        }
        return null;
    }

    private function submit(array $claim): array
    {
        $request = $claim['request'];
        $job = $claim['job'];
        try {
            $request = $this->reserveCredits($job, $request);
            $reference = $this->absolutePublicUrl((string) ($request['reference_image_url'] ?? ''));
            $startedAt = microtime(true);
            $result = $this->workflowEngine->submitVideo($job, $request, $reference);
            $capability = $reference !== '' ? AiCapability::VIDEO_IMAGE_TO_VIDEO : AiCapability::VIDEO_TEXT_TO_VIDEO;
            $this->callRecorder->record(
                $capability,
                (int) ($job['resolved_ai_model_id'] ?? 0) ?: null,
                $result,
                (int) round((microtime(true) - $startedAt) * 1000),
                $result['ok'] ? 'success' : 'failed',
                ['merchant_id' => $job['merchant_id'], 'job_id' => $job['id'], 'payload' => ['prompt' => $request['prompt']]]
            );
            if (!$result['ok']) {
                throw new VideoGatewayException(
                    (string) ($result['message'] ?? '视频上游提交失败'),
                    $this->retryable($result)
                );
            }
            $taskId = trim((string) ($result['data']['id'] ?? $result['data']['request_id'] ?? ''));
            if ($taskId === '') {
                throw new VideoGatewayException('视频上游没有返回任务 ID', false);
            }
            $nextPollAt = date('Y-m-d H:i:s', time() + $this->pollInterval());
            $latestPayloadJson = Db::name('video_generation_requests')
                ->where('id', (int) $request['id'])
                ->value('request_payload_json');
            $requestSnapshot = json_decode((string) ($latestPayloadJson ?? $request['request_payload_json'] ?? ''), true);
            $requestSnapshot = is_array($requestSnapshot) ? $requestSnapshot : [];
            $requestSnapshot['workflow'] = is_array($result['workflowSnapshot'] ?? null)
                ? $result['workflowSnapshot']
                : $this->workflowEngine->requestSnapshot($request);
            $requestSnapshot['model'] = (string) $job['model'];
            $requestSnapshot['ratio'] = (string) $request['ratio'];
            $requestSnapshot['resolution'] = (string) $request['resolution'];
            $requestSnapshot['duration'] = (int) $request['duration_seconds'];
            $requestSnapshot['reference_count'] = empty($request['reference_image_url']) ? 0 : 1;
            Db::transaction(function () use ($request, $job, $result, $taskId, $nextPollAt, $requestSnapshot): void {
                Db::name('video_generation_requests')->where('id', (int) $request['id'])->update([
                    'provider_request_id' => $taskId,
                    'provider_status' => 'submitted',
                    'provider_progress' => 10,
                    'next_poll_at' => $nextPollAt,
                    'request_payload_json' => json_encode($requestSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'result_payload_json' => json_encode($result['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => Web::now(),
                ]);
                Db::name('generation_jobs')->where('id', (int) $job['id'])->update([
                    'status' => 'running',
                    'new_api_request_id' => $taskId,
                    'progress_current' => 10,
                    'progress_total' => 100,
                    'progress_stage' => 'video_queued',
                    'progress_message' => '视频任务已提交，正在等待上游生成',
                    'error_message' => null,
                    'updated_at' => Web::now(),
                ]);
            });
            $this->projects->updateRunStatus((int) $job['id'], 'running');
            Audit::log('system', 0, 'video_generation.submitted', 'generation_job', $job['id'], '127.0.0.1', [
                'provider_request_id' => $taskId,
                'duration_seconds' => (int) $request['duration_seconds'],
                'model' => (string) $job['model'],
            ]);
            return ['job' => $job, 'status' => 'submitted', 'message' => '视频任务已提交'];
        } catch (Throwable $exception) {
            $retryable = $exception instanceof VideoGatewayException && $exception->retryable;
            if ($retryable && (int) $request['submit_attempts'] < 5) {
                return $this->deferSubmission($job, $request, $exception->getMessage());
            }
            $this->failAndRefund($job, $request, $exception->getMessage());
            return ['job' => $job, 'status' => 'failed', 'message' => localized_job_error($exception->getMessage())];
        }
    }

    private function poll(array $claim): array
    {
        $request = $claim['request'];
        $job = $claim['job'];
        try {
            $result = $this->workflowEngine->videoStatus($request);
            if (!$result['ok']) {
                throw new VideoGatewayException(
                    (string) ($result['message'] ?? '读取视频任务状态失败'),
                    $this->retryable($result)
                );
            }
            $data = is_array($result['data'] ?? null) ? $result['data'] : [];
            $status = strtolower(trim((string) ($data['status'] ?? '')));
            if (in_array($status, ['succeeded', 'completed', 'success'], true)) {
                return $this->complete($job, $request, $data);
            }
            if (in_array($status, ['failed', 'error', 'cancelled', 'canceled', 'expired', 'result_missing'], true)) {
                $message = trim((string) ($data['error']['message'] ?? $data['message'] ?? '视频上游生成失败'));
                throw new VideoGatewayException($message, false);
            }
            $progress = max(10, min(95, (int) ($data['progress'] ?? 0)));
            if ($progress <= 10) {
                $progress = in_array($status, ['running', 'processing', 'in_progress'], true) ? 55 : 20;
            }
            $manualReview = $this->expired($job);
            $providerStatus = $manualReview
                ? 'manual_review'
                : (in_array($status, ['running', 'processing', 'in_progress'], true) ? 'running' : 'pending');
            $nextPollAt = date('Y-m-d H:i:s', time() + ($manualReview ? 3600 : $this->pollBackoff((int) $request['poll_attempts'])));
            $elapsed = max(0, time() - (strtotime((string) ($job['created_at'] ?? '')) ?: time()));
            Db::transaction(function () use ($request, $job, $data, $providerStatus, $progress, $nextPollAt, $elapsed): void {
                Db::name('video_generation_requests')->where('id', (int) $request['id'])->update([
                    'provider_status' => $providerStatus,
                    'provider_progress' => $progress,
                    'next_poll_at' => $nextPollAt,
                    'result_payload_json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => Web::now(),
                ]);
                Db::name('generation_jobs')->where('id', (int) $job['id'])->update([
                    'progress_current' => $progress,
                    'progress_total' => 100,
                    'status' => $providerStatus === 'manual_review' ? 'manual_review' : 'running',
                    'progress_stage' => $providerStatus === 'manual_review'
                        ? 'manual_review'
                        : ($elapsed >= 600 ? 'slow_processing' : ($providerStatus === 'running' ? 'video_generating' : 'video_queued')),
                    'progress_message' => $providerStatus === 'manual_review'
                        ? '视频长期没有终态，已进入待人工核对；原请求号继续保留，不会重发或自动退款'
                        : ($elapsed >= 600
                            ? '视频处理时间较长，系统会继续使用原请求号收取结果'
                            : ($providerStatus === 'running' ? '视频正在生成，可离开页面继续其他操作' : '视频正在上游排队')),
                    'updated_at' => Web::now(),
                ]);
            });
            $this->projects->updateRunStatus((int) $job['id'], $manualReview ? 'manual_review' : 'running');
            return ['job' => $job, 'status' => $providerStatus, 'message' => '视频任务状态已更新'];
        } catch (Throwable $exception) {
            $retryable = $exception instanceof VideoGatewayException && $exception->retryable;
            if ($retryable && trim((string) ($request['provider_request_id'] ?? '')) !== '') {
                $this->deferPoll($job, $request, $exception->getMessage(), $this->expired($job));
                return [
                    'job' => $job,
                    'status' => $this->expired($job) ? 'manual_review' : 'retrying',
                    'message' => $this->expired($job) ? '视频待人工核对，仍会继续查询原任务' : '状态读取失败，稍后自动重试',
                ];
            }
            $this->failAndRefund($job, $request, $exception->getMessage());
            return ['job' => $job, 'status' => 'failed', 'message' => localized_job_error($exception->getMessage())];
        }
    }

    private function complete(array $job, array $request, array $data): array
    {
        $videoUrl = $this->videoUrl($data);
        if ($videoUrl === '') {
            throw new VideoGatewayException('视频任务已完成，但上游没有返回可用视频地址', false);
        }
        try {
            $download = $this->storage->downloadVideo($videoUrl);
            $stored = $this->storage->storeVideoRecord(
                $download['content'],
                $download['mime'],
                (int) $job['merchant_id'],
                (string) $job['job_no'],
                'product-video'
            );
        } catch (Throwable $exception) {
            throw new VideoGatewayException(
                '视频已生成，但暂时无法保存到素材库：' . $exception->getMessage(),
                true
            );
        }
        $dimensions = $this->videoDimensions((string) $request['ratio'], (string) $request['resolution']);
        $assetId = Db::transaction(function () use ($job, $request, $data, $videoUrl, $stored, $download, $dimensions): int {
            $lockedJob = Db::name('generation_jobs')->where('id', (int) $job['id'])->lock(true)->find();
            $lockedRequest = Db::name('video_generation_requests')->where('id', (int) $request['id'])->lock(true)->find();
            if (!$lockedJob || !$lockedRequest) {
                throw new RuntimeException('视频任务记录不存在');
            }
            $existing = Db::name('assets')->where('job_id', (int) $job['id'])->where('media_type', 'video')->where('status', 'active')->find();
            $now = Web::now();
            if ($existing) {
                $assetId = (int) $existing['id'];
            } else {
                $assetId = (int) Db::name('assets')->insertGetId([
                    'merchant_id' => $job['merchant_id'],
                    'sku_id' => $job['sku_id'] ?: null,
                    'job_id' => $job['id'],
                    'generation_item_id' => null,
                    'asset_type' => 'product_video',
                    'media_type' => 'video',
                    'mime_type' => $download['mime'],
                    'duration_seconds' => (int) $request['duration_seconds'],
                    'poster_url' => (string) ($request['reference_image_url'] ?? ''),
                    'width' => $dimensions['width'],
                    'height' => $dimensions['height'],
                    'file_url' => $stored['url'],
                    'backup_file_url' => $stored['backupUrl'],
                    'score' => null,
                    'is_favorite' => 0,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $credits = max(0, (int) $lockedJob['estimated_credits']);
            $reservationId = (int) ($lockedRequest['reservation_ledger_id'] ?? 0);
            if ($reservationId > 0) {
                Db::name('credit_ledger')->where('id', $reservationId)->where('type', 'generation_reserve')->update([
                    'type' => 'consume',
                    'new_api_request_id' => (string) $lockedRequest['provider_request_id'],
                    'note' => $lockedJob['job_no'] . ' 商品视频生成',
                ]);
            }
            if (trim((string) $lockedRequest['provider_request_id']) !== ''
                && !Db::name('usage_records')->where('new_api_request_id', (string) $lockedRequest['provider_request_id'])->find()) {
                Db::name('usage_records')->insert([
                    'merchant_id' => $job['merchant_id'],
                    'job_id' => $job['id'],
                    'new_api_request_id' => $lockedRequest['provider_request_id'],
                    'model' => $lockedJob['model'],
                    'provider' => null,
                    'business_credits' => $credits,
                    'gateway_cost' => 0,
                    'status' => 'pending',
                    'occurred_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            Db::name('video_generation_requests')->where('id', (int) $lockedRequest['id'])->update([
                'provider_status' => 'succeeded',
                'provider_progress' => 100,
                'next_poll_at' => null,
                'video_url' => $videoUrl,
                'last_frame_url' => $this->lastFrameUrl($data),
                'result_payload_json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => $now,
            ]);
            Db::name('generation_jobs')->where('id', (int) $lockedJob['id'])->update([
                'status' => 'completed',
                'actual_credits' => $credits,
                'progress_current' => 100,
                'progress_total' => 100,
                'progress_stage' => 'completed',
                'progress_message' => '商品视频已生成并保存到素材库',
                'error_message' => null,
                'updated_at' => $now,
            ]);
            return $assetId;
        });
        Audit::log('system', 0, 'video_generation.completed', 'generation_job', $job['id'], '127.0.0.1', [
            'asset_id' => $assetId,
            'duration_seconds' => (int) $request['duration_seconds'],
            'credits' => (int) $job['estimated_credits'],
        ]);
        $this->projects->updateRunStatus((int) $job['id'], 'completed');
        $this->projects->attachRunOutput((int) $job['id'], $assetId, 'video');
        return ['job' => $job, 'status' => 'completed', 'message' => '视频已生成并保存', 'assetId' => $assetId];
    }

    private function reserveCredits(array $job, array $request): array
    {
        return Db::transaction(function () use ($job, $request): array {
            $lockedRequest = Db::name('video_generation_requests')->where('id', (int) $request['id'])->lock(true)->find();
            $lockedJob = Db::name('generation_jobs')->where('id', (int) $job['id'])->lock(true)->find();
            $merchant = Db::name('merchants')->where('id', (int) $job['merchant_id'])->lock(true)->find();
            if (!$lockedRequest || !$lockedJob || !$merchant || (string) $merchant['status'] !== 'active') {
                throw new RuntimeException('商户或视频任务当前不可用');
            }
            if ((int) ($lockedRequest['reservation_ledger_id'] ?? 0) > 0) {
                return $lockedRequest;
            }
            $credits = max(0, (int) $lockedJob['estimated_credits']);
            if ((int) $merchant['credit_balance'] < $credits) {
                throw new RuntimeException("可用点数不足，商品视频预计需要 {$credits} 点，未调用模型接口");
            }
            $reservationId = null;
            $now = Web::now();
            if ($credits > 0) {
                $balance = (int) $merchant['credit_balance'] - $credits;
                Db::name('merchants')->where('id', (int) $merchant['id'])->update(['credit_balance' => $balance, 'updated_at' => $now]);
                $reservationId = (int) Db::name('credit_ledger')->insertGetId([
                    'merchant_id' => $merchant['id'],
                    'type' => 'generation_reserve',
                    'amount' => -$credits,
                    'balance_after' => $balance,
                    'source' => 'generation_job',
                    'reference_type' => 'generation_job',
                    'reference_id' => (string) $lockedJob['id'],
                    'note' => $lockedJob['job_no'] . ' 商品视频额度预扣',
                    'created_at' => $now,
                ]);
            }
            Db::name('video_generation_requests')->where('id', (int) $lockedRequest['id'])->update([
                'reservation_ledger_id' => $reservationId,
                'updated_at' => $now,
            ]);
            Db::name('generation_jobs')->where('id', (int) $lockedJob['id'])->update([
                'status' => 'running',
                'progress_current' => 5,
                'progress_total' => 100,
                'progress_stage' => 'video_submitting',
                'progress_message' => '正在提交商品视频任务',
                'error_message' => null,
                'updated_at' => $now,
            ]);
            $lockedRequest['reservation_ledger_id'] = $reservationId;
            return $lockedRequest;
        });
    }

    private function deferSubmission(array $job, array $request, string $message): array
    {
        $delay = min(120, 10 * max(1, (int) $request['submit_attempts']));
        Db::transaction(function () use ($job, $request, $message, $delay): void {
            Db::name('video_generation_requests')->where('id', (int) $request['id'])->update([
                'provider_status' => 'retrying',
                'next_poll_at' => date('Y-m-d H:i:s', time() + $delay),
                'updated_at' => Web::now(),
            ]);
            Db::name('generation_jobs')->where('id', (int) $job['id'])->update([
                'status' => 'queued',
                'progress_stage' => 'retrying',
                'progress_message' => "视频上游暂时繁忙，{$delay} 秒后自动重试",
                'error_message' => mb_strimwidth($message, 0, 500, ''),
                'updated_at' => Web::now(),
            ]);
        });
        return ['job' => $job, 'status' => 'retrying', 'message' => '视频提交失败，稍后自动重试'];
    }

    private function deferPoll(array $job, array $request, string $message, bool $manualReview = false): void
    {
        $delay = $manualReview ? 3600 : $this->pollBackoff((int) $request['poll_attempts']);
        Db::transaction(function () use ($job, $request, $message, $delay, $manualReview): void {
            Db::name('video_generation_requests')->where('id', (int) $request['id'])->update([
                'provider_status' => $manualReview ? 'manual_review' : 'retrying',
                'next_poll_at' => date('Y-m-d H:i:s', time() + $delay),
                'updated_at' => Web::now(),
            ]);
            Db::name('generation_jobs')->where('id', (int) $job['id'])->update([
                'status' => $manualReview ? 'manual_review' : 'running',
                'progress_stage' => $manualReview ? 'manual_review' : 'retrying',
                'progress_message' => $manualReview
                    ? '视频长期没有终态，已进入待人工核对；原请求号继续保留，不会重发或自动退款'
                    : "暂时无法读取视频状态，{$delay} 秒后自动重试",
                'error_message' => mb_strimwidth($message, 0, 500, ''),
                'updated_at' => Web::now(),
            ]);
        });
    }

    private function failAndRefund(array $job, array $request, string $message): void
    {
        Db::transaction(function () use ($job, $request, $message): void {
            $lockedRequest = Db::name('video_generation_requests')->where('id', (int) $request['id'])->lock(true)->find();
            $lockedJob = Db::name('generation_jobs')->where('id', (int) $job['id'])->lock(true)->find();
            if (!$lockedRequest || !$lockedJob || in_array((string) $lockedRequest['provider_status'], self::FINAL_STATUSES, true)) {
                return;
            }
            $reservationId = (int) ($lockedRequest['reservation_ledger_id'] ?? 0);
            if ($reservationId > 0) {
                $reservation = Db::name('credit_ledger')->where('id', $reservationId)->lock(true)->find();
                if ($reservation && (string) $reservation['type'] === 'generation_reserve') {
                    $merchant = Db::name('merchants')->where('id', (int) $lockedJob['merchant_id'])->lock(true)->find();
                    if ($merchant) {
                        $refund = abs((int) $reservation['amount']);
                        $balance = (int) $merchant['credit_balance'] + $refund;
                        $now = Web::now();
                        Db::name('merchants')->where('id', (int) $merchant['id'])->update(['credit_balance' => $balance, 'updated_at' => $now]);
                        Db::name('credit_ledger')->where('id', $reservationId)->update([
                            'type' => 'generation_refunded',
                            'note' => $lockedJob['job_no'] . ' 商品视频预扣已退回',
                        ]);
                        Db::name('credit_ledger')->insert([
                            'merchant_id' => $merchant['id'],
                            'type' => 'generation_refund',
                            'amount' => $refund,
                            'balance_after' => $balance,
                            'source' => 'generation_job',
                            'reference_type' => 'credit_ledger',
                            'reference_id' => (string) $reservationId,
                            'note' => $lockedJob['job_no'] . ' 商品视频失败退款',
                            'created_at' => $now,
                        ]);
                    }
                }
            }
            $friendly = localized_job_error($message);
            Db::name('video_generation_requests')->where('id', (int) $lockedRequest['id'])->update([
                'provider_status' => 'failed',
                'next_poll_at' => null,
                'updated_at' => Web::now(),
            ]);
            Db::name('generation_jobs')->where('id', (int) $lockedJob['id'])->update([
                'status' => 'failed',
                'actual_credits' => 0,
                'progress_stage' => 'failed',
                'progress_message' => '商品视频生成失败，预扣点数已退回：' . $friendly,
                'error_message' => mb_strimwidth($message, 0, 500, ''),
                'updated_at' => Web::now(),
            ]);
        });
        Audit::log('system', 0, 'video_generation.failed', 'generation_job', $job['id'], '127.0.0.1', [
            'message' => mb_strimwidth($message, 0, 500, ''),
            'refunded' => true,
        ]);
        $this->projects->updateRunStatus((int) $job['id'], 'failed');
    }

    private function settleCancelledJobs(): void
    {
        $rows = Db::name('video_generation_requests')->alias('v')
            ->join('generation_jobs j', 'j.id=v.job_id')
            ->field('v.*,j.model,j.estimated_credits,j.output_kind,j.status AS job_status,j.merchant_id AS job_merchant_id,j.sku_id AS job_sku_id,j.job_no')
            ->where('j.output_kind', 'video')
            ->where('j.status', 'cancelled')
            ->whereNotIn('v.provider_status', self::FINAL_STATUSES)
            ->limit(20)
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $job = [
                'id' => $row['job_id'],
                'merchant_id' => $row['job_merchant_id'],
                'sku_id' => $row['job_sku_id'],
                'job_no' => $row['job_no'],
                'model' => $row['model'],
                'estimated_credits' => $row['estimated_credits'],
            ];
            $this->failAndRefund($job, $row, '任务已由用户取消');
            Db::name('video_generation_requests')->where('id', (int) $row['id'])->update([
                'provider_status' => 'cancelled',
                'updated_at' => Web::now(),
            ]);
            Db::name('generation_jobs')->where('id', (int) $row['job_id'])->update([
                'status' => 'cancelled',
                'progress_stage' => 'cancelled',
                'progress_message' => '任务已取消，未完成部分点数已退回',
                'updated_at' => Web::now(),
            ]);
        }
    }

    private function recoverInterruptedClaims(): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - 300);
        foreach (Db::name('video_generation_requests')->whereIn('provider_status', ['submitting', 'polling'])->where('updated_at', '<', $cutoff)->limit(50)->select()->toArray() as $request) {
            $hasProviderId = trim((string) ($request['provider_request_id'] ?? '')) !== '';
            Db::name('video_generation_requests')->where('id', (int) $request['id'])->update([
                'provider_status' => $hasProviderId ? 'running' : 'retrying',
                'next_poll_at' => Web::now(),
                'updated_at' => Web::now(),
            ]);
            Db::name('generation_jobs')->where('id', (int) $request['job_id'])->whereIn('status', ['queued', 'running'])->update([
                'status' => $hasProviderId ? 'running' : 'queued',
                'progress_stage' => 'retrying',
                'progress_message' => '后台已恢复任务，正在继续处理',
                'updated_at' => Web::now(),
            ]);
        }
    }

    private function videoUrl(array $data): string
    {
        return trim((string) (
            $data['video']['url']
            ?? $data['url']
            ?? $data['video_url']
            ?? $data['output']['video_url']
            ?? $data['content']['video_url']
            ?? ''
        ));
    }

    private function lastFrameUrl(array $data): string
    {
        return trim((string) (
            $data['last_frame_url']
            ?? $data['output']['last_frame_url']
            ?? $data['content']['last_frame_url']
            ?? ''
        ));
    }

    private function absolutePublicUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : '';
        }
        if (!str_starts_with($url, '/')) {
            return '';
        }
        $baseUrl = rtrim(trim($this->settings->get('app_public_url')), '/');
        if ($baseUrl === '') {
            $domain = trim((string) env('DOMAIN', ''));
            if ($domain !== '') {
                $baseUrl = preg_match('#^https?://#i', $domain) === 1
                    ? rtrim($domain, '/')
                    : 'https://' . rtrim($domain, '/');
            }
        }
        if ($baseUrl === ''
            || filter_var($baseUrl, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return '';
        }
        return $baseUrl . $url;
    }

    private function retryable(array $result): bool
    {
        if (in_array((int) ($result['status'] ?? 0), [0, 408, 425, 429, 500, 502, 503, 504, 524], true)) {
            return true;
        }
        $message = strtolower((string) ($result['message'] ?? ''));
        return str_contains($message, '自动重试')
            || str_contains($message, 'temporarily')
            || str_contains($message, 'timeout')
            || str_contains($message, 'rate limit')
            || str_contains($message, '请求过多');
    }

    private function pollInterval(): int
    {
        return max(3, min(60, (int) $this->settings->get('video_worker_poll_interval_seconds')));
    }

    private function pollBackoff(int $attempts): int
    {
        return min(60, $this->pollInterval() * max(1, min(6, (int) ceil($attempts / 4))));
    }

    private function expired(array $job): bool
    {
        $maxRuntime = max(86400, min(259200, (int) $this->settings->get('video_worker_max_runtime_seconds')));
        $createdAt = strtotime((string) ($job['created_at'] ?? '')) ?: time();
        return time() - $createdAt > $maxRuntime;
    }

    private function videoDimensions(string $ratio, string $resolution): array
    {
        $base = $resolution === '1080p' ? 1080 : 720;
        return match ($ratio) {
            '16:9' => ['width' => (int) round($base * 16 / 9), 'height' => $base],
            '1:1' => ['width' => $base, 'height' => $base],
            '4:3' => ['width' => (int) round($base * 4 / 3), 'height' => $base],
            '3:4' => ['width' => $base, 'height' => (int) round($base * 4 / 3)],
            default => ['width' => $base, 'height' => (int) round($base * 16 / 9)],
        };
    }
}

final class VideoGatewayException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable)
    {
        parent::__construct($message);
    }
}
