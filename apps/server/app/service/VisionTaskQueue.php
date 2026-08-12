<?php

declare(strict_types=1);

namespace app\service;

use app\support\Audit;
use app\support\Web;
use RuntimeException;
use Throwable;
use think\facade\Db;

/**
 * Durable queue for product recognition and one-call product onboarding.
 *
 * Product onboarding deliberately has no fact contract, approval state,
 * evidence snapshot or planner join. One rich vision call returns both the
 * editable product report and the detail-page plan, then the result is saved.
 */
final class VisionTaskQueue
{
    private const CACHE_TTL_DAYS = 30;
    private const MAX_AUTOMATIC_ATTEMPTS = 2;
    private const AUTOMATIC_RETRY_DELAY_SECONDS = 15;
    private const STALE_TASK_SECONDS = 10 * 60;

    public function __construct(
        private readonly NewApiClient $newApi,
        private readonly AssetStorage $storage,
        private readonly PromptTemplateService $prompts,
        private readonly SkuProductReport $profiles,
        private readonly RecognitionBillingService $billing,
        private readonly ContentSafetyService $contentSafety,
        private readonly GenerationPreferenceService $preferences,
        private readonly ProductCreativeReadinessService $readiness,
        private readonly ProductLaunchTargetService $launchTargets
    ) {
    }

    public function requestHash(array $image, array $context, string $model): string
    {
        $reference = [
            '已有商品名称' => mb_strimwidth(trim((string) ($context['name'] ?? '')), 0, 160, ''),
            '已有商品类目' => mb_strimwidth(trim((string) ($context['category'] ?? '')), 0, 100, ''),
            '已有商品卖点' => mb_strimwidth(trim((string) ($context['sellingPoints'] ?? '')), 0, 2000, ''),
            '已有商品详情' => mb_strimwidth(trim((string) ($context['productDescription'] ?? '')), 0, 4000, ''),
        ];
        $prompt = $this->prompts->buildAnalysisPrompt($reference);
        return hash('sha256', implode("\0", [
            AiCapability::PRODUCT_VISION,
            hash('sha256', (string) ($image['content'] ?? '')),
            hash('sha256', $prompt),
            trim($model),
            trim((string) ($context['resolvedGateway'] ?? 'sub2api')),
            trim((string) ($context['purpose'] ?? 'product_profile')),
        ]));
    }

    public function cachedResult(string $requestHash): ?array
    {
        $cache = Db::name('vision_result_cache')->where('request_hash', $requestHash)
            ->where('expires_at', '>', Web::now())->find();
        if (!$cache) {
            return null;
        }
        $result = $this->jsonObject($cache['result_json'] ?? null);
        if (empty($result['ok']) || !is_array($result['product'] ?? null)) {
            return null;
        }
        Db::name('vision_result_cache')->where('id', $cache['id'])->inc('hit_count')
            ->update(['updated_at' => Web::now()]);
        $result['cacheHit'] = true;
        $result['cacheId'] = (int) $cache['id'];
        return $result;
    }

    public function activeTask(int $merchantId, string $requestHash): ?array
    {
        return Db::name('vision_tasks')->where('merchant_id', $merchantId)
            ->where('request_hash', $requestHash)
            ->whereIn('status', ['queued', 'running', 'retrying'])
            ->order('id', 'desc')->find() ?: null;
    }

    public function enqueue(array $input): array
    {
        $taskType = trim((string) ($input['task_type'] ?? 'product_profile')) ?: 'product_profile';
        $clientSubmissionId = trim((string) ($input['client_submission_id'] ?? ''));
        if ($taskType === 'product_launch' && $clientSubmissionId !== '') {
            $existing = $this->taskByClientSubmission((int) $input['merchant_id'], $clientSubmissionId);
            if ($existing) {
                $existing['_deduplicated'] = true;
                return $existing;
            }
        }

        $now = Web::now();
        try {
            $id = (int) Db::name('vision_tasks')->insertGetId([
                'task_no' => 'VISION-' . date('ymd-His') . '-' . strtoupper(bin2hex(random_bytes(4))),
                'merchant_id' => (int) $input['merchant_id'],
                'user_id' => (int) ($input['user_id'] ?? 0) ?: null,
                'sku_id' => (int) ($input['sku_id'] ?? 0) ?: null,
                'task_type' => $taskType,
                'priority' => max(1, min(100, (int) ($input['priority'] ?? 60))),
                'status' => 'queued',
                'progress_percent' => max(0, min(100, (int) ($input['progress_percent'] ?? 8))),
                'progress_stage' => trim((string) ($input['progress_stage'] ?? 'queued')) ?: 'queued',
                'progress_message' => trim((string) ($input['progress_message'] ?? '任务已提交，等待后台处理。')) ?: null,
                'client_submission_id' => $clientSubmissionId !== '' ? $clientSubmissionId : null,
                'input_image_count' => max(1, (int) ($input['input_image_count'] ?? 1)),
                'original_input_bytes' => max(0, (int) ($input['original_input_bytes'] ?? 0)),
                'normalized_input_bytes' => max(0, (int) ($input['normalized_input_bytes'] ?? 0)),
                'image_url' => (string) $input['image_url'],
                'image_backup_url' => trim((string) ($input['image_backup_url'] ?? '')) ?: null,
                'temporary_input' => !empty($input['temporary_input']) ? 1 : 0,
                'image_hash' => (string) $input['image_hash'],
                'request_hash' => (string) $input['request_hash'],
                'context_json' => json_encode((array) $input['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'result_json' => null,
                'diagnostic_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'model' => (string) $input['model'],
                'fallback_model' => trim((string) ($input['fallback_model'] ?? '')) ?: null,
                'pricing_snapshot_json' => json_encode((array) ($input['pricing_snapshot'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'credits' => max(0, (int) ($input['credits'] ?? 0)),
                'ledger_id' => (int) ($input['ledger_id'] ?? 0) ?: null,
                'billing_reference' => trim((string) ($input['billing_reference'] ?? '')) ?: null,
                'request_id' => null,
                'attempts' => 0,
                'next_retry_at' => null,
                'worker_slot' => null,
                'error_message' => null,
                'started_at' => null,
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Throwable $exception) {
            if ($taskType === 'product_launch' && $clientSubmissionId !== '') {
                $existing = $this->taskByClientSubmission((int) $input['merchant_id'], $clientSubmissionId);
                if ($existing) {
                    $existing['_deduplicated'] = true;
                    return $existing;
                }
            }
            throw $exception;
        }
        return Db::name('vision_tasks')->where('id', $id)->find()
            ?: throw new RuntimeException('视觉任务创建失败。');
    }

    public function taskByClientSubmission(int $merchantId, string $clientSubmissionId): ?array
    {
        $clientSubmissionId = trim($clientSubmissionId);
        if ($merchantId <= 0 || $clientSubmissionId === '') {
            return null;
        }
        return Db::name('vision_tasks')->where('merchant_id', $merchantId)
            ->where('client_submission_id', $clientSubmissionId)
            ->where('task_type', 'product_launch')->order('id', 'desc')->find() ?: null;
    }

    public function processNext(int $workerSlot): ?array
    {
        $this->recoverStale();
        $task = $this->claimNext($workerSlot);
        if (!$task) {
            return null;
        }
        try {
            $context = $this->jsonObject($task['context_json'] ?? null);
            if ((string) ($task['task_type'] ?? '') === 'product_launch') {
                return $this->processProductLaunch($task, $context);
            }
            $cached = $this->cachedResult((string) $task['request_hash']);
            if ($cached) {
                return $this->completeRecognition($task, $cached, true);
            }
            $this->updateProgress((int) $task['id'], 42, 'recognizing', 'AI 正在识别商品并整理商品资料。');
            $image = $this->storage->readImageWithBackup((string) $task['image_url'], (string) ($task['image_backup_url'] ?? ''));
            $result = $this->newApi->analyzeProductImage($image, $context + [
                'merchantId' => (int) $task['merchant_id'],
                'resolvedModel' => (string) $task['model'],
                'fallbackModel' => (string) ($task['fallback_model'] ?? ''),
            ]);
            if (empty($result['ok']) || !is_array($result['product'] ?? null)) {
                return $this->handleFailure($task, (string) ($result['message'] ?? '商品识别失败。'), (array) ($result['diagnostic'] ?? []));
            }
            $this->storeCache($task, $result);
            return $this->completeRecognition($task, $result, false);
        } catch (ContentSafetyViolationException $exception) {
            return $this->handleFailure($task, $exception->getMessage(), [
                'failure_type' => 'content_safety_violation',
                'retryable' => false,
            ]);
        } catch (ContentSafetyUnavailableException $exception) {
            return $this->handleFailure($task, $exception->getMessage(), [
                'failure_type' => 'content_safety_unavailable',
                // Successful checks are already cached by ContentSafetyService.
                // One bounded task retry therefore rechecks only the image whose
                // upstream moderation call failed; it does not resend the batch.
                'retryable' => true,
            ]);
        } catch (Throwable $exception) {
            return $this->handleFailure($task, $exception->getMessage());
        }
    }

    public function retryProductLaunch(int $taskId, int $merchantId): bool
    {
        return Db::transaction(function () use ($taskId, $merchantId): bool {
            $task = Db::name('vision_tasks')->where('id', $taskId)
                ->where('merchant_id', $merchantId)->where('task_type', 'product_launch')
                ->lock(true)->find();
            if (!$task || (string) ($task['status'] ?? '') !== 'failed') {
                return false;
            }
            $billingReference = trim((string) ($task['billing_reference'] ?? ''));
            $ledgerId = (int) ($task['ledger_id'] ?? 0) ?: null;
            if ((int) ($task['credits'] ?? 0) > 0 && $billingReference !== '') {
                $refunded = Db::name('credit_ledger')->where('merchant_id', $merchantId)
                    ->where('type', 'recognition_refund')->where('reference_id', $billingReference)->find();
                if ($refunded) {
                    $root = preg_replace('/-retry-\d+$/', '', $billingReference) ?: $billingReference;
                    $cycle = (int) Db::name('credit_ledger')->where('merchant_id', $merchantId)
                        ->where('type', 'recognition_consume')->whereLike('reference_id', $root . '%')->count() + 1;
                    $billingReference = mb_strimwidth($root . '-retry-' . $cycle, 0, 100, '');
                    [$ledgerId] = $this->billing->reserve($merchantId, (int) $task['credits'], $billingReference);
                }
            }
            Db::name('vision_tasks')->where('id', $taskId)->update([
                'status' => 'queued', 'progress_percent' => 8, 'progress_stage' => 'queued',
                'progress_message' => '任务已重新排队，将从原始商品图重新处理。',
                'ledger_id' => $ledgerId, 'billing_reference' => $billingReference ?: null,
                'diagnostic_json' => '{}', 'result_json' => null, 'request_id' => null,
                'attempts' => 0, 'next_retry_at' => null, 'worker_slot' => null,
                'error_message' => null, 'started_at' => null, 'completed_at' => null,
                'updated_at' => Web::now(),
            ]);
            return true;
        });
    }

    public function resumeAfterSkuCorrection(int $skuId, int $merchantId): array
    {
        return ['resumed' => false, 'readiness' => $this->readiness->forSku($skuId, $merchantId)];
    }

    public function statusPayload(int $taskId, int $merchantId): ?array
    {
        $task = Db::name('vision_tasks')->where('id', $taskId)->where('merchant_id', $merchantId)->find();
        if (!$task) {
            return null;
        }
        $status = (string) $task['status'];
        if ($status === 'completed') {
            $result = $this->jsonObject($task['result_json'] ?? null);
            return $result !== [] ? $result + [
                'ok' => true, 'queued' => false, 'taskId' => (int) $task['id'],
                'status' => 'completed', 'progressPercent' => 100,
                'progressStage' => (string) ($task['progress_stage'] ?? 'completed'),
            ] : ['ok' => false, 'status' => 'failed', 'message' => '识别结果读取失败。'];
        }
        if ($status === 'failed') {
            $failure = $this->jsonObject($task['result_json'] ?? null);
            return $failure + [
                'ok' => false, 'queued' => false, 'taskId' => (int) $task['id'],
                'status' => 'failed', 'progressPercent' => 100, 'progressStage' => 'failed',
                'message' => (string) ($task['error_message'] ?: '商品识别失败。'),
                'balance' => (int) Db::name('merchants')->where('id', $merchantId)->value('credit_balance'),
            ];
        }
        $position = (int) Db::name('vision_tasks')->whereIn('status', ['queued', 'retrying'])
            ->where(static function ($query) use ($task): void {
                $query->where('priority', '>', (int) $task['priority'])
                    ->whereOr(static function ($query) use ($task): void {
                        $query->where('priority', '=', (int) $task['priority'])
                            ->where('id', '<', (int) $task['id']);
                    });
            })->count() + 1;
        return [
            'ok' => true, 'queued' => true, 'taskId' => (int) $task['id'],
            'status' => $status, 'taskType' => (string) ($task['task_type'] ?? 'product_profile'),
            'progressPercent' => max(1, min(99, (int) ($task['progress_percent'] ?? 8))),
            'progressStage' => (string) ($task['progress_stage'] ?? $status),
            'position' => $status === 'running' ? 0 : $position,
            'attempt' => max(0, (int) ($task['attempts'] ?? 0)),
            'maxAttempts' => self::MAX_AUTOMATIC_ATTEMPTS,
            'nextRetryAt' => (string) ($task['next_retry_at'] ?? ''),
            'message' => trim((string) ($task['progress_message'] ?? '')) ?: ($status === 'running'
                ? 'AI 正在识别商品并整理上新资料。'
                : "视觉任务排队中，当前前方约 {$position} 项。"),
        ];
    }

    public function stats(): array
    {
        return [
            'queued' => (int) Db::name('vision_tasks')->whereIn('status', ['queued', 'retrying'])->count(),
            'running' => (int) Db::name('vision_tasks')->where('status', 'running')->count(),
            'completedToday' => (int) Db::name('vision_tasks')->where('status', 'completed')
                ->where('completed_at', '>=', date('Y-m-d 00:00:00'))->count(),
            'cacheEntries' => (int) Db::name('vision_result_cache')->where('expires_at', '>', Web::now())->count(),
            'cacheHits' => (int) Db::name('vision_result_cache')->sum('hit_count'),
        ];
    }

    private function claimNext(int $workerSlot): ?array
    {
        $now = Web::now();
        $candidateIds = Db::name('vision_tasks')->whereIn('status', ['queued', 'retrying'])
            ->where(static function ($query) use ($now): void {
                $query->whereNull('next_retry_at')->whereOr('next_retry_at', '<=', $now);
            })->order('priority', 'desc')->order('id', 'asc')->limit(20)->column('id');
        foreach ($candidateIds as $candidateId) {
            $claimed = Db::transaction(function () use ($candidateId, $workerSlot): ?array {
                $task = Db::name('vision_tasks')->where('id', (int) $candidateId)->lock(true)->find();
                if (!$task || !in_array((string) $task['status'], ['queued', 'retrying'], true)) {
                    return null;
                }
                if (!empty($task['next_retry_at']) && (string) $task['next_retry_at'] > Web::now()) {
                    return null;
                }
                $now = Web::now();
                Db::name('vision_tasks')->where('id', $task['id'])->update([
                    'status' => 'running', 'progress_percent' => max(15, (int) ($task['progress_percent'] ?? 0)),
                    'progress_stage' => 'preparing',
                    'progress_message' => (string) ($task['task_type'] ?? '') === 'product_launch'
                        ? '后台正在准备商品图与上新资料。' : '后台正在准备商品识别。',
                    'worker_slot' => $workerSlot, 'attempts' => (int) $task['attempts'] + 1,
                    'started_at' => $task['started_at'] ?: $now, 'next_retry_at' => null, 'updated_at' => $now,
                ]);
                return Db::name('vision_tasks')->where('id', $task['id'])->find();
            });
            if ($claimed) {
                return $claimed;
            }
        }
        return null;
    }

    private function completeRecognition(array $task, array $result, bool $cacheHit): array
    {
        $credits = max(0, (int) $task['credits']);
        $balance = (int) Db::name('merchants')->where('id', $task['merchant_id'])->value('credit_balance');
        if ($cacheHit && $credits > 0) {
            $balance = $this->billing->refund((int) $task['merchant_id'], $credits,
                (string) $task['billing_reference'], '商品识别命中缓存，退回预扣点数');
            $credits = 0;
        }
        if (!empty($task['sku_id'])) {
            $this->profiles->applyAnalysis((int) $task['sku_id'], (int) $task['merchant_id'], (array) $result['product']);
        }
        $requestId = trim((string) ($result['requestId'] ?? ''));
        $result['message'] = $cacheHit ? '已复用相同商品图的识别结果，本次未扣点数。'
            : ($credits > 0 ? "商品信息识别完成，已扣除 {$credits} 点数。" : '商品信息识别完成，本次免费。');
        $result['creditsCharged'] = $credits;
        $result['balance'] = $balance;
        $result['cacheHit'] = $cacheHit;
        $result['pricingSnapshot'] = $this->jsonObject($task['pricing_snapshot_json'] ?? null);
        $this->completeTask($task, $result, $requestId);
        if (!$cacheHit) {
            $this->recordUsage($task, $result, $credits, $requestId);
        }
        $this->cleanupTemporaryInput($task);
        return ['task' => $task, 'status' => 'completed', 'cacheHit' => $cacheHit];
    }

    private function processProductLaunch(array $task, array $context): array
    {
        $merchant = Db::name('merchants')->where('id', (int) $task['merchant_id'])->find();
        $user = Db::name('merchant_users')->where('id', (int) ($task['user_id'] ?? 0))
            ->where('merchant_id', (int) $task['merchant_id'])->find();
        if (!$merchant || !$user || (string) ($merchant['status'] ?? '') !== 'active') {
            return $this->handleFailure($task, '商户账户当前不可用。');
        }

        $this->updateProgress((int) $task['id'], 24, 'safety', '正在检查商品图与创作内容。');
        $image = $this->storage->readImageWithBackup((string) $task['image_url'], (string) ($task['image_backup_url'] ?? ''));
        $additionalImages = [];
        foreach ($this->additionalReferenceRecords($context) as $reference) {
            $additionalImages[] = $this->storage->readImageWithBackup((string) $reference['url'], (string) ($reference['backup_url'] ?? ''));
        }
        $ipAddress = trim((string) ($context['submitted_ip'] ?? '')) ?: '127.0.0.1';
        $this->contentSafety->enforceImages($merchant, $user, array_merge([$image], $additionalImages), 'product_launch_image', $ipAddress, 2);
        $this->contentSafety->enforceText($merchant, $user, mb_strimwidth(trim((string) ($context['brief'] ?? '')), 0, 2000, ''), 'product_launch_text', $ipAddress);

        $this->updateProgress((int) $task['id'], 48, 'recognizing', 'AI 正在一次性整理商品报告和详情页方案。');
        $visionRoute = $this->jsonObject($context['vision_route_snapshot'] ?? null);
        $result = $this->newApi->analyzeDetailPageProduct(
            $image,
            (string) ($context['platform'] ?? 'amazon'),
            (string) ($context['language'] ?? 'en-US'),
            mb_strimwidth(trim((string) ($context['brief'] ?? '')), 0, 2000, ''),
            [],
            (int) $task['merchant_id'],
            (string) ($visionRoute['resolvedModel'] ?? $task['model']),
            (string) ($visionRoute['fallbackModel'] ?? $task['fallback_model'] ?? ''),
            $additionalImages,
            (string) ($visionRoute['resolvedGateway'] ?? $context['resolvedGateway'] ?? ''),
            (string) ($visionRoute['fallbackGateway'] ?? $context['fallbackGateway'] ?? ''),
            'vision-task:' . (int) $task['id'] . ':product-report'
        );
        if (empty($result['ok']) || !is_array($result['report'] ?? null)) {
            return $this->handleFailure($task, (string) ($result['message'] ?? '商品资料识别失败。'), (array) ($result['diagnostic'] ?? []));
        }

        $this->updateProgress((int) $task['id'], 82, 'saving', '正在保存商品资料和上新方案。');
        $report = (array) $result['report'];
        $product = $this->preComfyProductFromReport($report, $context);
        [$skuId, $projectId] = $this->persistProductLaunch($task, $context, $product, $report, (string) ($result['requestId'] ?? ''));
        $launchMode = match ((string) ($context['launch_plan'] ?? 'smart')) {
            'quick' => 'assets', 'detail' => 'detail', default => 'bundle',
        };
        $requestId = trim((string) ($result['requestId'] ?? ''));
        $payload = GenerationOperationResult::success([
            'status' => 'completed', 'queued' => false, 'progressStage' => 'completed', 'progressPercent' => 100,
            'message' => '商品资料和详情页方案已创建，可以开始创作。',
            'redirectUrl' => '/workspace/generate?mode=' . $launchMode . '&sku_id=' . $skuId . '&project_id=' . $projectId . '&launch_task_id=' . (int) $task['id'],
            'skuId' => $skuId, 'projectId' => $projectId, 'product' => $product,
            'creditsCharged' => max(0, (int) $task['credits']),
            'balance' => (int) Db::name('merchants')->where('id', $task['merchant_id'])->value('credit_balance'),
            'model' => (string) ($result['model'] ?? $task['model']), 'requestId' => $requestId ?: null,
        ]);
        Db::name('vision_tasks')->where('id', $task['id'])->update(['sku_id' => $skuId, 'updated_at' => Web::now()]);
        $this->completeTask($task, $payload, $requestId);
        $this->recordUsage($task, ['model' => (string) ($result['model'] ?? $task['model'])], max(0, (int) $task['credits']), $requestId);
        Audit::log('system', 0, 'product_launch.completed', 'vision_task', (int) $task['id'], '127.0.0.1', [
            'merchant_id' => (int) $task['merchant_id'], 'sku_id' => $skuId, 'project_id' => $projectId,
            'model' => (string) ($result['model'] ?? $task['model']),
        ]);
        return ['task' => $task + ['sku_id' => $skuId], 'status' => 'completed', 'message' => $payload['message']];
    }

    private function persistProductLaunch(array $task, array $context, array $product, array $report, string $requestId): array
    {
        $merchantId = (int) $task['merchant_id'];
        $userId = (int) ($task['user_id'] ?? 0);
        return Db::transaction(function () use ($task, $context, $product, $report, $requestId, $merchantId, $userId): array {
            $skuCode = 'AI-LAUNCH-' . (int) $task['id'];
            $existing = Db::name('skus')->where('merchant_id', $merchantId)->where('sku_code', $skuCode)->find();
            $now = Web::now();
            if ($existing) {
                $skuId = (int) $existing['id'];
            } else {
                $name = trim((string) ($product['name'] ?? $context['product_name'] ?? '')) ?: '待完善商品 ' . date('m-d H:i');
                $category = trim((string) ($product['category'] ?? '')) ?: '待完善类目';
                $skuId = (int) Db::name('skus')->insertGetId([
                    'merchant_id' => $merchantId, 'sku_code' => $skuCode, 'name' => $name, 'category' => $category,
                    'selling_points' => json_encode((array) ($product['sellingPoints'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'creative_brief' => null, 'product_description' => trim((string) ($product['productDescription'] ?? '')) ?: null,
                    'preferred_image_ratio' => (string) ($context['image_ratio'] ?? 'auto'),
                    'preferred_image_model' => trim((string) ($context['image_model'] ?? '')) ?: null,
                    'search_terms' => json_encode((array) ($product['searchTerms'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'source_image_path' => (string) $task['image_url'],
                    'source_image_backup_path' => trim((string) ($task['image_backup_url'] ?? '')) ?: null,
                    'reference_images_json' => json_encode($this->additionalReferenceRecords($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $this->profiles->applyAnalysis($skuId, $merchantId, $product);
            $this->launchTargets->persistFromLaunch($skuId, $merchantId, $context);
            $this->preferences->remember($userId, (string) ($context['platform'] ?? 'amazon'), (string) ($context['language'] ?? 'en-US'));

            $projectId = (int) (Db::name('detail_page_projects')->where('merchant_id', $merchantId)
                ->where('sku_id', $skuId)->where('source_image_url', (string) $task['image_url'])
                ->order('id', 'desc')->value('id') ?? 0);
            if ($projectId <= 0) {
                $data = $this->projectData($report);
                $data['product_name'] = $data['product_name'] ?: (string) ($product['name'] ?? '待完善商品');
                $data['category'] = $data['category'] ?: (string) ($product['category'] ?? '待完善类目');
                $projectId = (int) Db::name('detail_page_projects')->insertGetId($data + [
                    'merchant_id' => $merchantId, 'created_by_user_id' => $userId, 'sku_id' => $skuId,
                    'creative_brief' => trim((string) ($context['brief'] ?? '')) ?: null,
                    'source_image_url' => (string) $task['image_url'],
                    'source_image_backup_url' => trim((string) ($task['image_backup_url'] ?? '')) ?: null,
                    'platform' => (string) ($context['platform'] ?? 'amazon'),
                    'language' => (string) ($context['language'] ?? 'en-US'),
                    'status' => 'draft', 'created_at' => $now, 'updated_at' => $now,
                ]);
                if (trim((string) ($context['brief'] ?? '')) !== '') {
                    $this->insertMessage($projectId, $merchantId, 'user', (string) $context['brief'], null, $now);
                }
                $this->insertMessage($projectId, $merchantId, 'assistant',
                    (string) ($report['assistant_reply'] ?? '详情页方案已自动创建。'), $requestId ?: null, $now);
            }
            return [$skuId, $projectId];
        });
    }

    private function completeTask(array $task, array $result, string $requestId): void
    {
        $now = Web::now();
        Db::name('vision_tasks')->where('id', $task['id'])->update([
            'status' => 'completed', 'progress_percent' => 100, 'progress_stage' => 'completed',
            'progress_message' => (string) ($result['message'] ?? '商品资料处理完成。'),
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'request_id' => $requestId ?: null, 'error_message' => null, 'worker_slot' => null,
            'completed_at' => $now, 'updated_at' => $now,
        ]);
        if (!empty($task['ledger_id']) && $requestId !== '') {
            Db::name('credit_ledger')->where('id', $task['ledger_id'])->update(['new_api_request_id' => $requestId]);
        }
    }

    private function handleFailure(array $task, string $message, array $diagnostic = []): array
    {
        $fresh = Db::name('vision_tasks')->where('id', (int) $task['id'])->find();
        if ($fresh) {
            $task = $fresh;
        }
        $attempts = max(1, (int) $task['attempts']);
        $diagnosticRetryable = array_key_exists('retryable', $diagnostic)
            ? (bool) $diagnostic['retryable']
            : $this->retryable($message);
        if ($attempts < self::MAX_AUTOMATIC_ATTEMPTS && $diagnosticRetryable) {
            $delay = self::AUTOMATIC_RETRY_DELAY_SECONDS;
            Db::name('vision_tasks')->where('id', $task['id'])->update([
                'status' => 'retrying', 'progress_stage' => 'retrying',
                'progress_message' => "上游服务暂时繁忙，第 {$attempts}/" . self::MAX_AUTOMATIC_ATTEMPTS
                    . " 次处理未完成，{$delay} 秒后进行最后一次自动重试。",
                'next_retry_at' => date('Y-m-d H:i:s', time() + $delay), 'worker_slot' => null,
                'diagnostic_json' => json_encode($diagnostic, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'error_message' => mb_strimwidth($message, 0, 500, '...'), 'updated_at' => Web::now(),
            ]);
            return ['task' => $task, 'status' => 'retrying', 'message' => $message];
        }
        $balance = $this->billing->refund((int) $task['merchant_id'], (int) $task['credits'], (string) $task['billing_reference']);
        $friendly = localized_job_error($message);
        $now = Web::now();
        Db::name('vision_tasks')->where('id', $task['id'])->update([
            'status' => 'failed', 'progress_percent' => 100, 'progress_stage' => 'failed',
            'progress_message' => $friendly,
            'result_json' => json_encode(GenerationOperationResult::failure(
                'vision_task_failed', (string) ($task['progress_stage'] ?? 'vision'), $friendly,
                true, (int) $task['credits'] > 0, (int) $task['credits'] > 0 ? 'refunded' : 'not_applicable', false,
                (int) $task['id'], null, ['balance' => $balance]
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'diagnostic_json' => json_encode($diagnostic, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'worker_slot' => null, 'error_message' => mb_strimwidth($friendly, 0, 500, '...'),
            'completed_at' => $now, 'updated_at' => $now,
        ]);
        $this->cleanupTemporaryInput($task);
        Audit::log('system', 0, 'vision_task.failed', 'vision_task', (int) $task['id'], '127.0.0.1', [
            'merchant_id' => (int) $task['merchant_id'], 'message' => $friendly,
        ]);
        return ['task' => $task, 'status' => 'failed', 'message' => $friendly];
    }

    private function storeCache(array $task, array $result): void
    {
        $now = Web::now();
        $payload = [
            'capability_code' => AiCapability::PRODUCT_VISION, 'image_hash' => (string) $task['image_hash'],
            'model' => (string) ($result['model'] ?? $task['model']),
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'request_id' => trim((string) ($result['requestId'] ?? '')) ?: null,
            'expires_at' => date('Y-m-d H:i:s', time() + self::CACHE_TTL_DAYS * 86400), 'updated_at' => $now,
        ];
        Db::transaction(function () use ($task, $payload, $now): void {
            $existing = Db::name('vision_result_cache')->where('request_hash', $task['request_hash'])->lock(true)->find();
            if ($existing) {
                Db::name('vision_result_cache')->where('id', $existing['id'])->update($payload);
            } else {
                Db::name('vision_result_cache')->insert($payload + [
                    'request_hash' => (string) $task['request_hash'], 'hit_count' => 0, 'created_at' => $now,
                ]);
            }
        });
    }

    private function recordUsage(array $task, array $result, int $credits, string $requestId): void
    {
        try {
            if ($requestId !== '' && Db::name('usage_records')->where('new_api_request_id', $requestId)
                ->where('provider', 'product_recognition')->count() > 0) {
                return;
            }
            $now = Web::now();
            Db::name('usage_records')->insert([
                'merchant_id' => $task['merchant_id'], 'job_id' => null,
                'new_api_request_id' => $requestId !== '' ? $requestId : null,
                'model' => (string) ($result['model'] ?? $task['model']), 'provider' => 'product_recognition',
                'business_credits' => $credits, 'gateway_cost' => 0,
                'status' => $requestId !== '' ? 'pending' : 'untracked',
                'occurred_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } catch (Throwable $exception) {
            Audit::log('system', 0, 'product_recognition.usage_record_failed', 'vision_task', (int) $task['id'], '127.0.0.1', [
                'request_id' => $requestId, 'message' => $exception->getMessage(),
            ]);
        }
    }

    private function recoverStale(): void
    {
        $staleTasks = Db::name('vision_tasks')->where('status', 'running')
            ->where('updated_at', '<', date('Y-m-d H:i:s', time() - self::STALE_TASK_SECONDS))
            ->limit(20)->select()->toArray();
        foreach ($staleTasks as $task) {
            $this->handleFailure(
                $task,
                '后台处理进程已中断。为避免重复调用模型，本任务已经停止，请手动重新处理。',
                ['internal_error_code' => 'vision_task_stale', 'retryable' => false]
            );
        }
        Db::name('vision_result_cache')->where('expires_at', '<=', Web::now())->delete();
    }

    private function retryable(string $message): bool
    {
        $message = strtolower($message);
        foreach (['429', '503', '504', '524', 'rate limit', 'too many requests', 'no available account',
            'temporarily unavailable', 'timeout', 'timed out', 'connection', '请求过多', '并发', '繁忙', '超时', '暂时不可用'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function cleanupTemporaryInput(array $task): void
    {
        // Product-launch uploads become the SKU's durable source material and
        // must remain available for a merchant-initiated retry after failure.
        if ((string) ($task['task_type'] ?? '') === 'product_launch' || empty($task['temporary_input'])) {
            return;
        }
        $urls = [(string) $task['image_url'], (string) ($task['image_backup_url'] ?? '')];
        foreach ($this->additionalReferenceRecords($this->jsonObject($task['context_json'] ?? null)) as $reference) {
            $urls[] = (string) $reference['url'];
            $urls[] = (string) ($reference['backup_url'] ?? '');
        }
        foreach (array_unique(array_filter($urls)) as $url) {
            try { $this->storage->deleteStoredUrl($url); } catch (Throwable) {}
        }
    }

    private function preComfyProductFromReport(array $report, array $context): array
    {
        $appearance = $this->reportText($report['appearance'] ?? '');
        return [
            'name' => $this->reportText($report['product_name'] ?? $context['product_name'] ?? ''),
            'category' => $this->reportText($report['category'] ?? ''),
            'searchTerms' => array_slice($this->jsonList($report['search_terms'] ?? []), 0, 5),
            'sellingPoints' => array_slice($this->jsonList($report['selling_points'] ?? []), 0, 12),
            'productDescription' => $this->reportText($report['product_description'] ?? ''),
            'foundation' => [
                'materials' => $this->reportText($report['materials'] ?? ''),
                'appearance' => $appearance,
                'primary_colors' => $this->visibleColors($appearance),
                'target_audience' => $this->reportText($report['target_audience'] ?? ''),
                'use_scenarios' => $this->jsonList($report['use_scenarios'] ?? []),
                'core_functions' => $this->jsonList($report['core_functions'] ?? []),
                'uncertain_points' => $this->jsonList($report['uncertain_points'] ?? []),
                'prohibited_claims' => $this->jsonList($report['prohibited_claims'] ?? []),
                'brand_notes' => $this->reportText($report['brand_notes'] ?? ''),
                'analysis_source' => 'pre_comfy_direct_vision',
            ],
        ];
    }

    private function visibleColors(string $appearance): array
    {
        $colors = [];
        if (preg_match_all('/(?:米白|奶白|象牙白|藏青|墨绿|玫瑰金|香槟金|(?:深|浅|亮|暗)?[红橙黄绿青蓝紫粉棕褐黑白灰金银]色)/u', $appearance, $matches) > 0) {
            $colors = $matches[0];
        }
        return array_values(array_unique(array_slice($colors, 0, 8)));
    }

    private function additionalReferenceRecords(array $context): array
    {
        $records = [];
        foreach (array_slice((array) ($context['reference_images'] ?? []), 0, 5) as $index => $reference) {
            if (!is_array($reference) || trim((string) ($reference['url'] ?? '')) === '') {
                continue;
            }
            $records[] = [
                'url' => trim((string) $reference['url']),
                'backup_url' => trim((string) ($reference['backup_url'] ?? '')) ?: null,
                'hash' => trim((string) ($reference['hash'] ?? '')) ?: null,
                'position' => max(2, (int) ($reference['position'] ?? $index + 2)),
            ];
        }
        return $records;
    }

    private function projectData(array $report): array
    {
        $data = [];
        foreach (['product_name', 'category', 'materials', 'appearance', 'target_audience', 'product_description', 'brand_notes'] as $field) {
            $data[$field] = $this->reportText($report[$field] ?? '') ?: null;
        }
        foreach (['use_scenarios', 'selling_points', 'core_functions', 'uncertain_points', 'prohibited_claims', 'screen_plan'] as $field) {
            $data[$field] = json_encode(is_array($report[$field] ?? null) ? $report[$field] : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $data;
    }

    private function reportText(mixed $value): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) { return trim((string) $value); }
        if (is_bool($value)) { return $value ? '是' : '否'; }
        if (!is_array($value)) { return ''; }
        return implode('；', array_values(array_unique(array_filter(
            array_map(fn (mixed $item): string => $this->reportText($item), $value),
            static fn (string $item): bool => $item !== ''
        ))));
    }

    private function jsonObject(mixed $value): array
    {
        if (is_array($value)) { return $value; }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function jsonList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : (trim($value) !== '' ? [$value] : []);
        }
        if (!is_array($value)) { return []; }
        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim(is_scalar($item) ? (string) $item : ''), $value
        ), static fn (string $item): bool => $item !== ''));
    }

    private function insertMessage(int $projectId, int $merchantId, string $role, string $content, ?string $requestId, string $now): void
    {
        Db::name('detail_page_messages')->insert([
            'project_id' => $projectId, 'merchant_id' => $merchantId, 'role' => $role,
            'content' => $content, 'request_id' => $requestId ?: null, 'created_at' => $now,
        ]);
    }

    private function updateProgress(int $taskId, int $percent, string $stage, string $message): void
    {
        Db::name('vision_tasks')->where('id', $taskId)->update([
            'progress_percent' => max(0, min(100, $percent)), 'progress_stage' => $stage,
            'progress_message' => mb_strimwidth($message, 0, 255, ''), 'updated_at' => Web::now(),
        ]);
    }
}
