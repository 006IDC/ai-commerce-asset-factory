<?php

declare(strict_types=1);

namespace app\command;

use app\support\RemoteMediaUrlGuard;
use app\service\AssetStorage;
use app\service\AssetTrashService;
use app\service\GenerationItemQueue;
use app\service\GenerationExecutionAttemptService;
use app\service\GenerationWorkerLaneService;
use app\service\GenerationSafetyGate;
use app\service\FreeGenerationRequestContract;
use app\service\MediaWorkflowEngine;
use app\service\NewApiClient;
use app\service\PromptTemplateService;
use app\service\SystemSettings;
use app\service\VisionTaskQueue;
use app\service\WorkerMonitor;
use app\service\WorkflowPendingException;
use app\support\Audit;
use app\support\Web;
use RuntimeException;
use Throwable;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

final class WorkGenerationJobs extends Command
{
    private const MAX_GENERATED_IMAGE_BYTES = 25 * 1024 * 1024;
    private const MAX_GENERATED_IMAGE_DIMENSION = 4096;
    private const MAX_GENERATED_IMAGE_PIXELS = 16 * 1024 * 1024;

    private MediaWorkflowEngine $mediaWorkflows;
    private FreeGenerationRequestContract $freeRequestContract;

    protected function configure(): void
    {
        $this->setName('jobs:work')
            ->addOption('once', null, Option::VALUE_NONE, 'Process at most one queued job')
            ->addOption('drain', null, Option::VALUE_NONE, 'Process queued jobs until the queue is empty, then exit')
            ->addOption('slot', null, Option::VALUE_REQUIRED, 'Worker pool slot number', '1')
            ->setDescription('Process queued media generation jobs through the captured AI route');
    }

    protected function execute(Input $input, Output $output): int
    {
        $client = $this->app->make(NewApiClient::class);
        $this->mediaWorkflows = $this->app->make(MediaWorkflowEngine::class);
        $this->freeRequestContract = $this->app->make(FreeGenerationRequestContract::class);
        $storage = $this->app->make(AssetStorage::class);
        $trash = $this->app->make(AssetTrashService::class);
        $prompts = $this->app->make(PromptTemplateService::class);
        $items = $this->app->make(GenerationItemQueue::class);
        $executionAttempts = $this->app->make(GenerationExecutionAttemptService::class);
        $safetyGate = $this->app->make(GenerationSafetyGate::class);
        $settings = $this->app->make(SystemSettings::class);
        $visionTasks = $this->app->make(VisionTaskQueue::class);
        $monitor = $this->app->make(WorkerMonitor::class);
        $laneScheduler = $this->app->make(GenerationWorkerLaneService::class);
        $once = (bool) $input->getOption('once');
        $drain = (bool) $input->getOption('drain');
        $slot = max(1, min(10, (int) $input->getOption('slot')));
        if ($once && $drain) {
            $output->writeln('<error>--once and --drain cannot be used together.</error>');
            return 2;
        }
        $monitor->start($once ? 'once' : ($drain ? 'drain' : 'daemon'), $slot);
        $lastTrashCheck = 0;

        try {
            do {
                $monitor->heartbeat('checking');
                $globalConcurrency = max(1, min(10, (int) $settings->get('worker_global_concurrency')));
                $visionConcurrency = max(2, min(4, (int) $settings->get('worker_vision_concurrency')));
                $lanes = $laneScheduler->resolve($slot, $globalConcurrency, $visionConcurrency);
                if ($lanes['paused']) {
                    $monitor->heartbeat('paused');
                    if ($once || $drain) {
                        return 0;
                    }
                    sleep(5);
                    continue;
                }
                if ($slot === 1 && time() - $lastTrashCheck >= 60) {
                    try {
                        $trash->runScheduled(50);
                        $items->recoverStale(20);
                    } catch (Throwable $exception) {
                        $output->writeln('<comment>Recycle-bin cleanup skipped: ' . $exception->getMessage() . '</comment>');
                    }
                    $lastTrashCheck = time();
                }
                $merchantConcurrency = max(1, min(5, (int) $settings->get('worker_merchant_concurrency')));
                $jobConcurrency = max(1, min(5, (int) $settings->get('worker_job_concurrency')));
                $visionSlot = (bool) $lanes['vision'];
                try {
                    $visionResult = $visionSlot ? $visionTasks->processNext($slot) : null;
                    if ($visionResult !== null) {
                        $visionTask = $visionResult['task'];
                        $monitor->recordJob((string) $visionTask['task_no'], (string) $visionResult['status'], (string) ($visionResult['message'] ?? ''));
                        $output->writeln('<info>Vision ' . $visionResult['status'] . ': ' . $visionTask['task_no'] . '</info>');
                    }
                    $safetyResult = !$visionSlot && $slot === 1 ? $safetyGate->processNext() : null;
                    if ($safetyResult !== null) {
                        $safetyJob = $safetyResult['job'];
                        $monitor->recordJob((string) $safetyJob['job_no'], (string) $safetyResult['status'], (string) ($safetyResult['message'] ?? ''));
                        $output->writeln('<info>Safety ' . $safetyResult['status'] . ': ' . $safetyJob['job_no'] . '</info>');
                    }
                    $processed = false;
                    if (!$visionSlot && $visionResult === null && $safetyResult === null) {
                        $processed = $this->processNextItem($client, $storage, $prompts, $items, $executionAttempts, $monitor, $output, $slot, $merchantConcurrency, $jobConcurrency);
                        if (!$processed) {
                            $processed = $this->processNext($client, $storage, $prompts, $monitor, $output);
                        }
                    }
                    $processed = $processed || $safetyResult !== null || $visionResult !== null;
                } catch (Throwable $exception) {
                    if (!str_contains(strtolower($exception->getMessage()), 'database is locked')) {
                        throw $exception;
                    }
                    $output->writeln('<comment>SQLite queue is busy; worker slot will retry.</comment>');
                    usleep(random_int(100000, 300000));
                    $processed = false;
                }
                if ($once) {
                    return 0;
                }
                if ($drain && !$processed) {
                    return 0;
                }
                if (!$processed) {
                    $monitor->heartbeat('idle');
                    if ($visionSlot) {
                        usleep(500000);
                    } else {
                        sleep(3);
                    }
                }
            } while (true);
        } finally {
            $monitor->stop();
        }
    }

    private function processNextItem(NewApiClient $client, AssetStorage $storage, PromptTemplateService $prompts, GenerationItemQueue $queue, GenerationExecutionAttemptService $executionAttempts, WorkerMonitor $monitor, Output $output, int $slot, int $merchantLimit, int $jobLimit): bool
    {
        $prepared = $queue->prepareNextJob();
        if ($prepared && !empty($prepared['_rejected'])) {
            $monitor->recordJob((string) $prepared['job_no'], 'failed', (string) $prepared['_message']);
            $output->writeln('<error>Rejected ' . $prepared['job_no'] . ': ' . $prepared['_message'] . '</error>');
            return true;
        }
        $claim = $queue->claimNext($slot, $merchantLimit, $jobLimit);
        if (!$claim) {
            return $prepared !== null;
        }

        $job = $this->jobWithCapturedModel($claim['job']);
        $item = $claim['item'];
        $job['_generation_item_id'] = (int) $item['id'];
        $job['_generation_request_id'] = (string) ($item['request_id'] ?? '');
        $job['_generation_started_at'] = (string) ($item['started_at'] ?? '');
        $job['_manual_retry_no'] = max(0, (int) ($item['manual_retry_count'] ?? 0));
        $attemptId = $executionAttempts->start($item, $job);
        $monitor->heartbeat('working', (string) $job['job_no']);
        try {
            $result = (string) $job['platform'] === 'free'
                ? $this->runFreeItem($client, $storage, $prompts, $job, $item, $claim['merchant'])
                : ((int) ($job['detail_page_project_id'] ?? 0) > 0
                    ? $this->runDetailPageItem($client, $storage, $prompts, $job, $item, $claim['merchant'])
                    : $this->runSkuItem($client, $storage, $prompts, $job, $item, $claim['merchant']));
            $aggregate = $queue->complete((int) $item['id'], (int) $result['assetId'], $result['requestId']);
            $executionAttempts->succeed(
                $attemptId,
                $result['requestId'],
                (int) $result['assetId'],
                is_array($result['providerMetadata'] ?? null) ? $result['providerMetadata'] : []
            );
            if (($aggregate['status'] ?? '') === 'completed' && (int) ($job['detail_page_project_id'] ?? 0) > 0) {
                Db::name('detail_page_projects')->where('id', (int) $job['detail_page_project_id'])->update(['status' => 'completed', 'updated_at' => Web::now()]);
            }
            $monitor->recordJob((string) $job['job_no'], (string) ($aggregate['status'] ?? 'running'));
            $output->writeln('<info>Completed ' . $job['job_no'] . ' image ' . $item['position'] . '/' . $item['type_total'] . '</info>');
        } catch (WorkflowPendingException $exception) {
            $aggregate = $queue->deferWorkflow(
                (int) $item['id'],
                $exception->getMessage(),
                $exception->needsManualReview()
            );
            $monitor->recordJob(
                (string) $job['job_no'],
                (string) ($aggregate['status'] ?? 'running'),
                $exception->getMessage()
            );
            $output->writeln(
                '<comment>Deferred ' . $job['job_no'] . ' image ' . $item['position']
                . ': ' . $exception->getMessage() . '</comment>'
            );
        } catch (Throwable $exception) {
            $friendly = localized_job_error($exception->getMessage());
            $aggregate = $queue->fail((int) $item['id'], $exception->getMessage());
            $executionAttempts->fail($attemptId, $exception->getMessage());
            if (in_array((string) ($aggregate['status'] ?? ''), ['failed', 'partial'], true) && (int) ($job['detail_page_project_id'] ?? 0) > 0) {
                Db::name('detail_page_projects')->where('id', (int) $job['detail_page_project_id'])->update([
                    'status' => (string) $aggregate['status'],
                    'updated_at' => Web::now(),
                ]);
            }
            Audit::log('system', 0, 'generation_item.failed', 'generation_job_item', $item['id'], '127.0.0.1', [
                'job_id' => $job['id'], 'position' => $item['position'], 'message' => $exception->getMessage(),
            ]);
            $monitor->recordJob((string) $job['job_no'], (string) ($aggregate['status'] ?? 'failed'), $exception->getMessage());
            $output->writeln('<error>Failed ' . $job['job_no'] . ' image ' . $item['position'] . ': ' . $friendly . '</error>');
        }

        return true;
    }

    private function processNext(NewApiClient $client, AssetStorage $storage, PromptTemplateService $prompts, WorkerMonitor $monitor, Output $output): bool
    {
        $claim = Db::transaction(function () {
            $job = Db::name('generation_jobs')->where('output_kind', 'image')->where('status', 'queued')->where('safety_status', 'passed')->order('id', 'asc')->lock(true)->find();
            if (!$job) {
                return null;
            }
            if (Db::name('generation_job_items')->where('job_id', $job['id'])->count() > 0) {
                return null;
            }
            $merchant = Db::name('merchants')->where('id', $job['merchant_id'])->lock(true)->find();
            $assetTypes = decode_json_list($job['asset_types']);
            $completedCounts = array_count_values(Db::name('assets')->where('job_id', $job['id'])->where('status', 'active')->column('asset_type'));
            $encounteredCounts = [];
            $requiredCredits = 0;
            $remainingCount = 0;
            foreach ($assetTypes as $index => $assetType) {
                $encounteredCounts[$assetType] = ($encounteredCounts[$assetType] ?? 0) + 1;
                if (($completedCounts[$assetType] ?? 0) >= $encounteredCounts[$assetType]) {
                    continue;
                }
                $requiredCredits += $this->creditsForPosition((int) $job['estimated_credits'], count($assetTypes), $index);
                $remainingCount++;
            }
            $completedCount = max(0, count($assetTypes) - $remainingCount);
            if (!$merchant || $merchant['status'] !== 'active') {
                $message = '商户账户当前不可用，未调用模型接口';
                Db::name('generation_jobs')->where('id', $job['id'])->update([
                    'status' => 'failed',
                    'error_message' => $message,
                    'progress_stage' => 'failed',
                    'progress_message' => $message,
                    'updated_at' => Web::now(),
                ]);
                return ['job' => $job, 'ready' => false, 'message' => $message, 'reservation_id' => null];
            }
            if ((int) $merchant['credit_balance'] < $requiredCredits) {
                $message = "额度不足，任务需要 {$requiredCredits} 点，未调用模型接口";
                Db::name('generation_jobs')->where('id', $job['id'])->update([
                    'status' => 'failed',
                    'error_message' => $message,
                    'progress_stage' => 'failed',
                    'progress_message' => $message,
                    'updated_at' => Web::now(),
                ]);
                return ['job' => $job, 'ready' => false, 'message' => $message, 'reservation_id' => null];
            }
            $reservationId = null;
            if ($requiredCredits > 0) {
                $balance = (int) $merchant['credit_balance'] - $requiredCredits;
                $now = Web::now();
                Db::name('merchants')->where('id', $merchant['id'])->update(['credit_balance' => $balance, 'updated_at' => $now]);
                $reservationId = (int) Db::name('credit_ledger')->insertGetId([
                    'merchant_id' => $merchant['id'],
                    'type' => 'generation_reserve',
                    'amount' => -$requiredCredits,
                    'balance_after' => $balance,
                    'source' => 'generation_job',
                    'reference_type' => 'generation_job',
                    'reference_id' => (string) $job['id'],
                    'note' => $job['job_no'] . ' 额度预扣',
                    'created_at' => $now,
                ]);
            }
            Db::name('generation_jobs')->where('id', $job['id'])->update([
                'status' => 'running',
                'error_message' => null,
                'progress_current' => $completedCount,
                'progress_total' => count($assetTypes),
                'progress_stage' => 'preparing',
                'progress_message' => $completedCount > 0
                    ? "从 {$completedCount}/" . count($assetTypes) . ' 继续，正在准备商品资料和参考图'
                    : '正在准备商品资料和参考图',
                'updated_at' => Web::now(),
            ]);
            return [
                'job' => $job,
                'ready' => true,
                'message' => '',
                'reservation_id' => $reservationId,
                'reserved_credits' => $requiredCredits,
                'actual_credits_before' => (int) $job['actual_credits'],
            ];
        });

        if (!$claim) {
            return false;
        }
        $job = $this->jobWithCapturedModel($claim['job']);
        if (!$claim['ready']) {
            if ((string) $job['platform'] === 'canvas') {
                $this->syncCreativeProjectRun((int) $job['id'], 'failed');
            }
            Audit::log('system', 0, 'generation_job.rejected_before_gateway', 'generation_job', $job['id'], '127.0.0.1', [
                'message' => $claim['message'],
                'estimated_credits' => (int) $job['estimated_credits'],
            ]);
            $monitor->recordJob((string) $job['job_no'], 'failed', (string) $claim['message']);
            $output->writeln('<error>Rejected ' . $job['job_no'] . ': ' . $claim['message'] . '</error>');
            return true;
        }
        $job['_reservation_ledger_id'] = $claim['reservation_id'];
        $job['_reserved_credits'] = $claim['reserved_credits'];
        $job['_actual_credits_before'] = $claim['actual_credits_before'];
        if ((string) $job['platform'] === 'canvas') {
            $this->syncCreativeProjectRun((int) $job['id'], 'running');
        }

        $monitor->heartbeat('working', (string) $job['job_no']);
        try {
            $this->runJob($client, $storage, $prompts, $job);
            $monitor->recordJob((string) $job['job_no'], 'completed');
            $output->writeln('<info>Completed ' . $job['job_no'] . '</info>');
        } catch (Throwable $exception) {
            $friendlyMessage = localized_job_error($exception->getMessage());
            try {
                $this->settleFailedGenerationReservation($job);
            } catch (Throwable $refundException) {
                Audit::log('system', 0, 'generation_job.refund_failed', 'generation_job', $job['id'], '127.0.0.1', [
                    'message' => $refundException->getMessage(),
                ]);
            }
            $progress = Db::name('generation_jobs')->where('id', $job['id'])->field('progress_current,progress_total')->find() ?: [];
            $current = (int) ($progress['progress_current'] ?? 0);
            $total = (int) ($progress['progress_total'] ?? 0);
            $finalStatus = $current > 0 ? 'partial' : 'failed';
            $progressMessage = $finalStatus === 'partial'
                ? "已完成 {$current}/{$total} 张并保存到素材库；其余图片生成中断：{$friendlyMessage}"
                : '生成中断：' . $friendlyMessage;
            Db::name('generation_jobs')->where('id', $job['id'])->update([
                'status' => $finalStatus,
                'error_message' => mb_strimwidth($exception->getMessage(), 0, 500, '...'),
                'progress_stage' => $finalStatus,
                'progress_message' => mb_strimwidth($progressMessage, 0, 500, '...'),
                'updated_at' => Web::now(),
            ]);
            if ((string) $job['platform'] === 'canvas') {
                $this->syncCreativeProjectRun((int) $job['id'], $finalStatus);
            }
            Audit::log('system', 0, 'generation_job.' . $finalStatus, 'generation_job', $job['id'], '127.0.0.1', [
                'message' => $exception->getMessage(),
                'completed_items' => $current,
                'total_items' => $total,
            ]);
            $monitor->recordJob((string) $job['job_no'], $finalStatus, $exception->getMessage());
            $output->writeln('<error>' . ($finalStatus === 'partial' ? 'Partially completed ' : 'Failed ') . $job['job_no'] . ': ' . $exception->getMessage() . '</error>');
        }

        return true;
    }

    private function runJob(NewApiClient $client, AssetStorage $storage, PromptTemplateService $prompts, array $job): void
    {
        if ((string) $job['platform'] === 'canvas') {
            $this->runCanvasEdit($client, $storage, $prompts, $job);
            return;
        }
        if ((string) $job['platform'] === 'free') {
            $this->runFreeGeneration($client, $storage, $prompts, $job);
            return;
        }
        $merchant = Db::name('merchants')->where('id', $job['merchant_id'])->find();
        $sku = Db::name('skus')->where('id', $job['sku_id'])->where('merchant_id', $job['merchant_id'])->find();
        // SKU 商品基础是唯一的长期事实来源；详情页方案仅提供本次屏幕编排。
        if (!$merchant || !$sku || $merchant['status'] !== 'active' || $sku['status'] !== 'active') {
            throw new RuntimeException('商户或 SKU 当前不可用');
        }
        $assetTypes = decode_json_list($job['asset_types']);
        if ($assetTypes === []) {
            throw new RuntimeException('任务没有素材类型');
        }
        $total = count($assetTypes);
        $requestedCounts = array_count_values($assetTypes);
        $completedCounts = array_count_values(Db::name('assets')->where('job_id', $job['id'])->where('status', 'active')->column('asset_type'));
        $completedCount = 0;
        foreach ($requestedCounts as $assetType => $requestedCount) {
            $completedCount += min($requestedCount, (int) ($completedCounts[$assetType] ?? 0));
        }
        $this->updateProgress((int) $job['id'], $completedCount, $total, 'preparing', '正在读取商品参考图');

        $referenceImage = null;
        if (!empty($sku['source_image_path']) && $client->supportsImageEdit((string) $job['model'])) {
            $referenceImage = $this->skuReferenceImage($storage, $sku);
        }

        $encounteredCounts = [];
        foreach ($assetTypes as $index => $assetType) {
            $encounteredCounts[$assetType] = ($encounteredCounts[$assetType] ?? 0) + 1;
            $occurrence = $encounteredCounts[$assetType];
            $typeTotal = (int) ($requestedCounts[$assetType] ?? 1);
            if (($completedCounts[$assetType] ?? 0) >= $occurrence) {
                continue;
            }
            $position = $index + 1;
            $typeLabel = asset_type_label($assetType) . ($typeTotal > 1 ? "（第 {$occurrence}/{$typeTotal} 张）" : '');
            $this->updateProgress(
                (int) $job['id'],
                $completedCount,
                $total,
                'generating',
                "正在生成第 {$position}/{$total} 项：{$typeLabel}"
            );
            $prompt = $prompts->buildImagePrompt(
                $sku,
                (string) $job['platform'],
                (string) $assetType,
                $referenceImage !== null,
                $occurrence,
                $typeTotal,
                (string) ($job['language'] ?? 'zh-CN'),
                (string) ($job['task_brief'] ?? '')
            );
            $workflowContext = [
                'asset_type' => (string) $assetType,
                'position' => $position,
                'occurrence' => $occurrence,
            ];
            $result = $this->requestImageWithRetry($client, $job, $prompt, $referenceImage, $completedCount, $total, $typeLabel, null, '1024x1024', false, $workflowContext);
            if ($referenceImage !== null) {
                $result = $this->retryUnchangedReferenceImage($client, $prompts, $job, $referenceImage, $prompt, $result, $completedCount, $total, $typeLabel, '1024x1024', false, $workflowContext);
            }
            $item = $result['data']['data'][0] ?? null;
            if (!is_array($item)) {
                throw new RuntimeException('模型没有返回图片数据');
            }
            $storedImage = $this->persistImage($storage, $item, (int) $job['merchant_id'], (string) $job['job_no'], (string) $assetType . '-' . $occurrence);
            $asset = [
                'asset_type' => (string) $assetType,
                'file_url' => $storedImage['url'],
                'backup_file_url' => $storedImage['backupUrl'],
                'request_id' => $result['requestId'] ?? ($result['data']['id'] ?? null),
            ];
            $assetCredits = $this->creditsForPosition((int) $job['estimated_credits'], $total, $index);
            $this->saveGeneratedAsset($storage, $job, $merchant, $sku, $asset, $assetCredits, $occurrence);
            $completedCounts[$assetType] = max((int) ($completedCounts[$assetType] ?? 0), $occurrence);
            $completedCount++;
            $this->updateProgress(
                (int) $job['id'],
                $completedCount,
                $total,
                'generating',
                "已完成 {$completedCount}/{$total} 张：{$typeLabel}"
            );
        }

        $this->updateProgress((int) $job['id'], $total, $total, 'saving', '图片已生成，正在保存素材和用量记录');
        Db::transaction(function () use ($job, $total) {
            $now = Web::now();
            $reservationId = (int) ($job['_reservation_ledger_id'] ?? 0);
            if ($reservationId > 0) {
                $reservation = Db::name('credit_ledger')->where('id', $reservationId)->where('type', 'generation_reserve')->lock(true)->find();
                if (!$reservation) {
                    throw new RuntimeException('任务额度预扣记录不存在');
                }
                Db::name('credit_ledger')->where('id', $reservationId)->update([
                    'type' => 'consume',
                    'new_api_request_id' => Db::name('generation_jobs')->where('id', $job['id'])->value('new_api_request_id'),
                    'note' => $job['job_no'],
                ]);
            }
            Db::name('generation_jobs')->where('id', $job['id'])->update([
                'status' => 'completed',
                'error_message' => null,
                'progress_current' => $total,
                'progress_total' => $total,
                'progress_stage' => 'completed',
                'progress_message' => '全部素材已生成并保存',
                'updated_at' => $now,
            ]);
        });

        Audit::log('system', 0, 'generation_job.completed', 'generation_job', $job['id'], '127.0.0.1', [
            'assets' => $total,
            'credits' => (int) Db::name('generation_jobs')->where('id', $job['id'])->value('actual_credits'),
            'generation_mode' => $referenceImage !== null ? 'reference_image' : 'text',
        ]);
    }

    private function runSkuItem(NewApiClient $client, AssetStorage $storage, PromptTemplateService $prompts, array $job, array $queueItem, array $merchant): array
    {
        $sku = Db::name('skus')->where('id', $job['sku_id'])->where('merchant_id', $job['merchant_id'])->find();
        $sku = $sku ?: null;
        if (!$sku || $merchant['status'] !== 'active' || $sku['status'] !== 'active') {
            throw new RuntimeException('商户或 SKU 当前不可用');
        }
        $total = max(1, (int) $job['progress_total']);
        $completed = (int) Db::name('generation_job_items')->where('job_id', $job['id'])->where('status', 'completed')->count();
        $referenceImage = null;
        if (!empty($sku['source_image_path']) && $client->supportsImageEdit((string) $job['model'])) {
            $referenceImage = $this->skuReferenceImage($storage, $sku);
        }
        $assetType = (string) $queueItem['asset_type'];
        $occurrence = max(1, (int) $queueItem['occurrence']);
        $typeTotal = max(1, (int) $queueItem['type_total']);
        $typeLabel = asset_type_label($assetType) . ($typeTotal > 1 ? "（第 {$occurrence}/{$typeTotal} 张）" : '');
        $this->updateProgress((int) $job['id'], $completed, $total, 'generating', "并行生成中：{$typeLabel}");
        $prompt = $prompts->buildImagePrompt(
            $sku,
            (string) $job['platform'],
            $assetType,
            $referenceImage !== null,
            $occurrence,
            $typeTotal,
            (string) ($job['language'] ?? 'zh-CN'),
            (string) ($job['task_brief'] ?? '')
        );
        $output = $this->skuOutputDimensions($sku);
        $workflowContext = [
            'asset_type' => $assetType,
            'position' => (int) ($queueItem['position'] ?? 1),
            'occurrence' => $occurrence,
        ];
        $result = $this->requestImageWithRetry($client, $job, $prompt, $referenceImage, $completed, $total, $typeLabel, null, $this->canvasUpstreamSize($output['width'], $output['height']), true, $workflowContext);
        if ($referenceImage !== null) {
            $result = $this->retryUnchangedReferenceImage(
                $client,
                $prompts,
                $job,
                $referenceImage,
                $prompt,
                $result,
                $completed,
                $total,
                $typeLabel,
                $this->canvasUpstreamSize($output['width'], $output['height']),
                true,
                $workflowContext
            );
        }
        $this->assertItemActive((int) $job['id'], (int) $queueItem['id']);
        $image = $result['data']['data'][0] ?? null;
        if (!is_array($image)) {
            throw new RuntimeException('模型没有返回图片数据');
        }
        $stored = $this->persistCanvasImage(
            $storage,
            $image,
            (int) $job['merchant_id'],
            (string) $job['job_no'] . '-' . $assetType . '-' . $occurrence,
            $output['width'],
            $output['height'],
            $this->generatedOutputResizeMode($output['width'], $output['height'])
        );
        $requestId = $result['requestId'] ?? ($result['data']['id'] ?? null);
        $assetId = $this->saveGeneratedAsset($storage, $job, $merchant, $sku, [
            'asset_type' => $assetType, 'file_url' => $stored['url'], 'backup_file_url' => $stored['backupUrl'], 'request_id' => $requestId,
        ], (int) $queueItem['estimated_credits'], $occurrence, (int) $queueItem['id']);

        return [
            'assetId' => $assetId,
            'requestId' => $requestId ? (string) $requestId : null,
            'providerMetadata' => is_array($result['providerMetadata'] ?? null) ? $result['providerMetadata'] : [],
        ];
    }

    private function bundleCampaignProject(array $job): ?array
    {
        if ((string) ($job['bundle_part'] ?? '') !== 'assets' || trim((string) ($job['bundle_no'] ?? '')) === '') {
            return null;
        }
        $projectId = (int) Db::name('generation_jobs')
            ->where('merchant_id', (int) $job['merchant_id'])
            ->where('bundle_no', (string) $job['bundle_no'])
            ->where('bundle_part', 'detail')
            ->whereNotNull('detail_page_project_id')
            ->order('id', 'desc')
            ->value('detail_page_project_id');
        if ($projectId <= 0) {
            return null;
        }
        $project = Db::name('detail_page_projects')->where('id', $projectId)
            ->where('merchant_id', (int) $job['merchant_id'])->find();
        return $project ?: null;
    }

    private function contentSetContext(int $jobId, array $queueItem): array
    {
        $items = Db::name('generation_job_items')->where('job_id', $jobId)->order('position', 'asc')->field('position,asset_type')->select()->toArray();
        $position = max(1, (int) ($queueItem['position'] ?? 1));
        $previousTypes = [];
        foreach ($items as $item) {
            if ((int) $item['position'] >= $position) {
                break;
            }
            $previousTypes[] = (string) $item['asset_type'];
        }

        return ['position' => $position, 'total' => max(1, count($items)), 'previous_types' => $previousTypes];
    }

    private function customMaterialRequest(array $job, string $assetType): string
    {
        if (preg_match('/^custom_material_(\d+)$/', $assetType, $matches) !== 1) {
            return '';
        }
        $requests = decode_json_list($job['custom_material_requests'] ?? '[]');
        return trim((string) ($requests[max(0, (int) $matches[1] - 1)] ?? ''));
    }

    private function runDetailPageItem(NewApiClient $client, AssetStorage $storage, PromptTemplateService $prompts, array $job, array $queueItem, array $merchant): array
    {
        $project = Db::name('detail_page_projects')->where('id', (int) $job['detail_page_project_id'])
            ->where('merchant_id', (int) $job['merchant_id'])->find();
        if (!$project || $merchant['status'] !== 'active') {
            throw new RuntimeException('详情页项目或商户当前不可用');
        }
        if (!$client->supportsImageEdit((string) $job['model'])) {
            throw new RuntimeException('当前图片模型不支持详情页参考图编辑');
        }
        $total = max(1, (int) $job['progress_total']);
        $completed = (int) Db::name('generation_job_items')->where('job_id', $job['id'])->where('status', 'completed')->count();
        $assetType = (string) $queueItem['asset_type'];
        $typeLabel = asset_type_label($assetType);
        $this->updateProgress((int) $job['id'], $completed, $total, 'generating', "并行生成详情页：{$typeLabel}");
        $skuFoundation = (int) ($project['sku_id'] ?? 0) > 0
            ? Db::name('skus')->where('id', (int) $project['sku_id'])->where('merchant_id', (int) $job['merchant_id'])->find()
            : null;
        $referenceImage = $skuFoundation
            && !empty($skuFoundation['source_image_path'])
            ? $this->skuReferenceImage($storage, $skuFoundation)
            : $storage->readImageWithBackup((string) $project['source_image_url'], (string) ($project['source_image_backup_url'] ?? ''));
        if ($skuFoundation) {
            $project = array_replace($project, [
                'product_name' => $skuFoundation['name'] ?: $project['product_name'], 'category' => $skuFoundation['category'] ?: $project['category'],
                'materials' => $skuFoundation['report_materials'] ?: $project['materials'], 'appearance' => $skuFoundation['report_appearance'] ?: $project['appearance'],
                'target_audience' => $skuFoundation['report_target_audience'] ?: $project['target_audience'],
                'use_scenarios' => $skuFoundation['report_use_scenarios'] ?: $project['use_scenarios'], 'core_functions' => $skuFoundation['report_core_functions'] ?: $project['core_functions'],
                'brand_notes' => $skuFoundation['report_brand_notes'] ?: $project['brand_notes'],
            ]);
        }
        $output = $this->detailOutputDimensions($job);
        $upstreamSize = $this->canvasUpstreamSize($output['width'], $output['height']);
        $prompt = $prompts->buildDetailPagePrompt(
            $project,
            $assetType,
            $output['width'],
            $output['height']
        );
        $workflowContext = [
            'asset_type' => $assetType,
            'position' => (int) ($queueItem['position'] ?? 1),
            'occurrence' => max(1, (int) ($queueItem['occurrence'] ?? 1)),
        ];
        $result = $this->requestImageWithRetry($client, $job, $prompt, $referenceImage, $completed, $total, $typeLabel, null, $upstreamSize, true, $workflowContext);
        $this->assertItemActive((int) $job['id'], (int) $queueItem['id']);
        $image = $result['data']['data'][0] ?? null;
        if (!is_array($image)) {
            throw new RuntimeException('模型没有返回详情页图片数据');
        }
        if ($this->imageMatchesAnyReference($image, $referenceImage)) {
            $result = $this->retryUnchangedReferenceImage(
                $client,
                $prompts,
                $job,
                $referenceImage,
                $prompt,
                $result,
                $completed,
                $total,
                $typeLabel,
                $upstreamSize,
                true,
                $workflowContext,
                true
            );
            $image = $result['data']['data'][0] ?? null;
            if (!is_array($image)) {
                throw new RuntimeException('模型没有返回详情页重试图片数据');
            }
        }
        $stored = $this->persistCanvasImage(
            $storage,
            $image,
            (int) $job['merchant_id'],
            (string) $job['job_no'] . '-' . $queueItem['position'],
            $output['width'],
            $output['height'],
            $this->generatedOutputResizeMode($output['width'], $output['height'])
        );
        $requestId = $result['requestId'] ?? ($result['data']['id'] ?? null);
        $sku = (int) ($project['sku_id'] ?? 0) > 0
            ? Db::name('skus')->where('id', (int) $project['sku_id'])->where('merchant_id', (int) $job['merchant_id'])->find()
            : null;
        $assetId = $this->saveGeneratedAsset($storage, $job, $merchant, $sku ?: null, [
            'asset_type' => $assetType, 'file_url' => $stored['url'], 'backup_file_url' => $stored['backupUrl'], 'request_id' => $requestId,
        ], (int) $queueItem['estimated_credits'], 1, (int) $queueItem['id']);
        return [
            'assetId' => $assetId,
            'requestId' => $requestId ? (string) $requestId : null,
            'providerMetadata' => is_array($result['providerMetadata'] ?? null) ? $result['providerMetadata'] : [],
        ];
    }

    private function attachProductReport(array $sku): array
    {
        $hasSkuReport = (string) ($sku['report_status'] ?? '') === 'ready';
        if ($hasSkuReport) {
            return $sku;
        }
        $report = Db::name('detail_page_projects')->where('merchant_id', (int) $sku['merchant_id'])
            ->where('sku_id', (int) $sku['id'])->order('id', 'desc')->find();
        if (!$report) {
            return $sku;
        }
        foreach (['materials', 'appearance', 'target_audience', 'use_scenarios', 'core_functions', 'brand_notes'] as $field) {
            $sku['report_' . $field] = $report[$field] ?? null;
        }
        return $sku;
    }

    private function runFreeItem(NewApiClient $client, AssetStorage $storage, PromptTemplateService $prompts, array $job, array $queueItem, array $merchant): array
    {
        $request = Db::name('free_generation_jobs')->where('job_id', $job['id'])->find();
        if ($merchant['status'] !== 'active' || !$request) {
            throw new RuntimeException('自由创作任务当前不可用');
        }
        $total = max(1, (int) $job['progress_total']);
        $completed = (int) Db::name('generation_job_items')->where('job_id', $job['id'])->where('status', 'completed')->count();
        $width = max(320, min(2048, (int) ($request['output_width'] ?? 1024)));
        $height = max(320, min(2048, (int) ($request['output_height'] ?? 1024)));
        $referenceImage = $this->freeReferenceImage($storage, $request);
        $this->freezeFreeOutboundReference((int) $job['id'], $request, $referenceImage);
        $this->freeRequestContract->assertFrozen(
            $request,
            $referenceImage,
            $this->capturedCapability($job),
            trim((string) ($job['client_submission_id'] ?? '')) !== ''
        );
        if ($referenceImage !== null) {
            if (!$client->supportsImageEdit((string) $job['model'])) {
                throw new RuntimeException('当前图片模型不支持自由创作参考图编辑');
            }
        }
        $occurrence = max(1, (int) $queueItem['occurrence']);
        $prompt = $prompts->buildFreeGenerationPrompt(
            trim((string) $request['prompt']),
            $width,
            $height,
            $occurrence,
            $total,
            $referenceImage !== null,
            (int) ($referenceImage['_reference_count'] ?? 0),
            (string) ($job['language'] ?? 'zh-CN')
        );
        $workflowContext = [
            'asset_type' => 'free',
            'position' => (int) ($queueItem['position'] ?? $occurrence),
            'occurrence' => $occurrence,
        ];
        if (trim((string) ($job['client_submission_id'] ?? '')) !== '') {
            $workflowContext['expected_reference_count'] = max(0, (int) ($request['reference_count'] ?? 0));
            $workflowContext['reference_fingerprints'] = $this->freeRequestContract->fingerprints($request);
        }
        $result = $this->requestImageWithRetry($client, $job, $prompt, $referenceImage, $completed, $total, '自由创作图', null, $this->canvasUpstreamSize($width, $height), true, $workflowContext);
        if ($referenceImage !== null) {
            $result = $this->retryUnchangedReferenceImage($client, $prompts, $job, $referenceImage, $prompt, $result, $completed, $total, '自由创作图', $this->canvasUpstreamSize($width, $height), true, $workflowContext);
        }
        $this->assertItemActive((int) $job['id'], (int) $queueItem['id']);
        $image = $result['data']['data'][0] ?? null;
        if (!is_array($image)) {
            throw new RuntimeException('模型没有返回自由创作图片数据');
        }
        $stored = $this->persistCanvasImage(
            $storage,
            $image,
            (int) $job['merchant_id'],
            (string) $job['job_no'] . '-' . $occurrence,
            $width,
            $height,
            $this->generatedOutputResizeMode($width, $height)
        );
        $requestId = $result['requestId'] ?? ($result['data']['id'] ?? null);
        $assetId = $this->saveGeneratedAsset($storage, $job, $merchant, null, [
            'asset_type' => 'free', 'file_url' => $stored['url'], 'backup_file_url' => $stored['backupUrl'], 'request_id' => $requestId,
        ], (int) $queueItem['estimated_credits'], $occurrence, (int) $queueItem['id']);

        return [
            'assetId' => $assetId,
            'requestId' => $requestId ? (string) $requestId : null,
            'providerMetadata' => is_array($result['providerMetadata'] ?? null) ? $result['providerMetadata'] : [],
        ];
    }

    private function runFreeGeneration(NewApiClient $client, AssetStorage $storage, PromptTemplateService $prompts, array $job): void
    {
        $merchant = Db::name('merchants')->where('id', $job['merchant_id'])->find();
        $request = Db::name('free_generation_jobs')->where('job_id', $job['id'])->find();
        if (!$merchant || $merchant['status'] !== 'active' || !$request) {
            throw new RuntimeException('自由创作任务当前不可用');
        }
        $assetTypes = decode_json_list($job['asset_types']);
        if ($assetTypes === []) {
            throw new RuntimeException('自由创作任务没有生成数量');
        }
        $total = count($assetTypes);
        $completedCount = (int) Db::name('assets')->where('job_id', $job['id'])->where('asset_type', 'free')->where('status', 'active')->count();
        $width = max(320, min(2048, (int) ($request['output_width'] ?? 1024)));
        $height = max(320, min(2048, (int) ($request['output_height'] ?? 1024)));
        $upstreamSize = $this->canvasUpstreamSize($width, $height);
        $referenceImage = $this->freeReferenceImage($storage, $request);
        $this->freezeFreeOutboundReference((int) $job['id'], $request, $referenceImage);
        $this->freeRequestContract->assertFrozen(
            $request,
            $referenceImage,
            $this->capturedCapability($job),
            trim((string) ($job['client_submission_id'] ?? '')) !== ''
        );
        if ($referenceImage !== null) {
            if (!$client->supportsImageEdit((string) $job['model'])) {
                throw new RuntimeException('当前图片模型不支持自由创作参考图编辑');
            }
        }
        $this->updateProgress((int) $job['id'], $completedCount, $total, 'preparing', $referenceImage ? '正在读取自由创作参考图' : '正在准备自由创作提示词');
        for ($index = $completedCount; $index < $total; $index++) {
            $occurrence = $index + 1;
            $this->updateProgress((int) $job['id'], $index, $total, 'generating', "正在生成自由创作图片 {$occurrence}/{$total}");
            $prompt = $prompts->buildFreeGenerationPrompt(
                trim((string) $request['prompt']),
                $width,
                $height,
                $occurrence,
                $total,
                $referenceImage !== null,
                (int) ($referenceImage['_reference_count'] ?? 0),
                (string) ($job['language'] ?? 'zh-CN')
            );
            $workflowContext = [
                'asset_type' => 'free',
                'position' => $occurrence,
                'occurrence' => $occurrence,
            ];
            if (trim((string) ($job['client_submission_id'] ?? '')) !== '') {
                $workflowContext['expected_reference_count'] = max(0, (int) ($request['reference_count'] ?? 0));
                $workflowContext['reference_fingerprints'] = $this->freeRequestContract->fingerprints($request);
            }
            $result = $this->requestImageWithRetry($client, $job, $prompt, $referenceImage, $index, $total, '自由创作图', null, $upstreamSize, true, $workflowContext);
            if ($referenceImage !== null) {
                $result = $this->retryUnchangedReferenceImage($client, $prompts, $job, $referenceImage, $prompt, $result, $index, $total, '自由创作图', $upstreamSize, true, $workflowContext);
            }
            $this->assertJobActive((int) $job['id']);
            $item = $result['data']['data'][0] ?? null;
            if (!is_array($item)) {
                throw new RuntimeException('模型没有返回自由创作图片数据');
            }
            $this->updateProgress((int) $job['id'], $index, $total, 'resizing', "正在校正第 {$occurrence}/{$total} 张为 {$width}×{$height}");
            $storedImage = $this->persistCanvasImage(
                $storage,
                $item,
                (int) $job['merchant_id'],
                (string) $job['job_no'] . '-' . $occurrence,
                $width,
                $height,
                $this->generatedOutputResizeMode($width, $height)
            );
            $assetCredits = $this->creditsForPosition((int) $job['estimated_credits'], $total, $index);
            $this->saveGeneratedAsset($storage, $job, $merchant, null, [
                'asset_type' => 'free',
                'file_url' => $storedImage['url'],
                'backup_file_url' => $storedImage['backupUrl'],
                'request_id' => $result['requestId'] ?? ($result['data']['id'] ?? null),
            ], $assetCredits, $occurrence);
            $completedCount++;
            $this->updateProgress((int) $job['id'], $completedCount, $total, 'generating', "已完成自由创作图片 {$completedCount}/{$total}");
        }

        Db::transaction(function () use ($job, $total): void {
            $now = Web::now();
            $reservationId = (int) ($job['_reservation_ledger_id'] ?? 0);
            if ($reservationId > 0) {
                $reservation = Db::name('credit_ledger')->where('id', $reservationId)->where('type', 'generation_reserve')->lock(true)->find();
                if (!$reservation) {
                    throw new RuntimeException('自由创作任务额度预扣记录不存在');
                }
                Db::name('credit_ledger')->where('id', $reservationId)->update([
                    'type' => 'consume',
                    'new_api_request_id' => Db::name('generation_jobs')->where('id', $job['id'])->value('new_api_request_id'),
                    'note' => $job['job_no'] . ' 自由创作',
                ]);
            }
            Db::name('generation_jobs')->where('id', $job['id'])->update([
                'status' => 'completed',
                'error_message' => null,
                'progress_current' => $total,
                'progress_total' => $total,
                'progress_stage' => 'completed',
                'progress_message' => '自由创作图片已全部生成并归档',
                'updated_at' => $now,
            ]);
            Db::name('free_generation_jobs')->where('job_id', $job['id'])->update(['updated_at' => $now]);
        });
        Audit::log('system', 0, 'free_generation.completed', 'generation_job', $job['id'], '127.0.0.1', [
            'assets' => $total,
            'output_size' => $width . 'x' . $height,
            'has_reference' => $referenceImage !== null,
        ]);
    }

    private function runCanvasEdit(NewApiClient $client, AssetStorage $storage, PromptTemplateService $prompts, array $job): void
    {
        $merchant = Db::name('merchants')->where('id', $job['merchant_id'])->find();
        $edit = Db::name('canvas_edits')->where('job_id', $job['id'])->find();
        $sourceAsset = $edit
            ? Db::name('assets')->where('id', $edit['source_asset_id'])->where('merchant_id', $job['merchant_id'])->where('status', 'active')->find()
            : null;
        if (!$merchant || $merchant['status'] !== 'active' || !$edit || !$sourceAsset) {
            throw new RuntimeException('画布源素材或编辑任务当前不可用');
        }

        $this->updateProgress((int) $job['id'], 0, 1, 'preparing', '正在读取原始素材和编辑参数');
        $sourceImage = $storage->readImageWithBackup((string) $sourceAsset['file_url'], (string) ($sourceAsset['backup_file_url'] ?? ''));
        $operation = (string) ($edit['operation'] ?? 'ai_edit');
        $mode = (string) $edit['mode'];
        $mask = null;
        if ($operation === 'ai_edit' && $mode === 'regional') {
            $mask = $this->readCanvasMask((string) ($edit['mask_file'] ?? ''));
        }
        $outputWidth = max(320, min(2048, (int) ($edit['output_width'] ?? 1024)));
        $outputHeight = max(320, min(2048, (int) ($edit['output_height'] ?? 1024)));
        $resizeMode = in_array((string) ($edit['resize_mode'] ?? 'extend'), ['extend', 'crop', 'contain'], true)
            ? (string) $edit['resize_mode']
            : 'extend';
        if ($operation === 'resize_only') {
            $result = ['requestId' => null, 'data' => []];
            $item = ['b64_json' => base64_encode((string) $sourceImage['content'])];
            $this->updateProgress((int) $job['id'], 0, 1, 'resizing', "正在调整为 {$outputWidth}×{$outputHeight}");
        } else {
            $prompt = $prompts->buildCanvasPrompt($mode, (string) $edit['preset'], (string) $edit['instruction'], $outputWidth, $outputHeight, $resizeMode);
            $modeLabel = $mode === 'regional' ? '局部区域编辑' : '整图指令编辑';
            $upstreamSize = $this->canvasUpstreamSize($outputWidth, $outputHeight);
            $this->updateProgress((int) $job['id'], 0, 1, 'generating', "正在执行{$modeLabel}，目标 {$outputWidth}×{$outputHeight}");
            $result = $this->requestImageWithRetry(
                $client,
                $job,
                $prompt,
                $sourceImage,
                0,
                1,
                $modeLabel,
                $mask,
                $upstreamSize,
                true,
                ['asset_type' => 'canvas_edit']
            );
            $this->assertJobActive((int) $job['id']);
            $item = $result['data']['data'][0] ?? null;
            if (!is_array($item)) {
                throw new RuntimeException('模型没有返回编辑后的图片数据');
            }
            $result = $this->retryUnchangedCanvasEdit(
                $client,
                $prompts,
                $job,
                $sourceImage,
                $prompt,
                $result,
                $mode,
                $mask,
                $upstreamSize
            );
            $item = $result['data']['data'][0] ?? null;
            if (!is_array($item)) {
                throw new RuntimeException('模型没有返回画布重试后的图片数据');
            }
            $this->updateProgress((int) $job['id'], 0, 1, 'resizing', "正在校正为 {$outputWidth}×{$outputHeight} 标准尺寸");
        }
        $storedImage = $this->persistCanvasImage($storage, $item, (int) $job['merchant_id'], (string) $job['job_no'], $outputWidth, $outputHeight, $resizeMode);
        $asset = [
            'asset_type' => 'canvas_edit',
            'file_url' => $storedImage['url'],
            'backup_file_url' => $storedImage['backupUrl'],
            'request_id' => $result['requestId'] ?? ($result['data']['id'] ?? null),
        ];
        $sku = !empty($sourceAsset['sku_id'])
            ? Db::name('skus')->where('id', $sourceAsset['sku_id'])->where('merchant_id', $job['merchant_id'])->find()
            : null;
        $this->saveGeneratedAsset($storage, $job, $merchant, $sku ?: null, $asset, (int) $job['estimated_credits'], 1);
        $this->updateProgress((int) $job['id'], 1, 1, 'saving', $operation === 'resize_only' ? '尺寸调整完成，正在归档到素材库' : '编辑完成，正在归档到素材库');

        Db::transaction(function () use ($job, $operation): void {
            $now = Web::now();
            $reservationId = (int) ($job['_reservation_ledger_id'] ?? 0);
            if ($reservationId > 0) {
                $reservation = Db::name('credit_ledger')->where('id', $reservationId)->where('type', 'generation_reserve')->lock(true)->find();
                if (!$reservation) {
                    throw new RuntimeException('画布任务额度预扣记录不存在');
                }
                Db::name('credit_ledger')->where('id', $reservationId)->update([
                    'type' => 'consume',
                    'new_api_request_id' => Db::name('generation_jobs')->where('id', $job['id'])->value('new_api_request_id'),
                    'note' => $job['job_no'] . ' 画布编辑',
                ]);
            }
            Db::name('generation_jobs')->where('id', $job['id'])->update([
                'status' => 'completed',
                'error_message' => null,
                'progress_current' => 1,
                'progress_total' => 1,
                'progress_stage' => 'completed',
                'progress_message' => $operation === 'resize_only' ? '尺寸调整完成，结果已归档到素材库' : '画布编辑完成，结果已归档到素材库',
                'updated_at' => $now,
            ]);
            Db::name('canvas_edits')->where('job_id', $job['id'])->update(['updated_at' => $now]);
        });
        $this->syncCreativeProjectRun((int) $job['id'], 'completed');

        $this->deleteCanvasMask((string) ($edit['mask_file'] ?? ''));
        Audit::log('system', 0, 'canvas_edit.completed', 'generation_job', $job['id'], '127.0.0.1', [
            'source_asset_id' => (int) $sourceAsset['id'],
            'operation' => $operation,
            'mode' => $mode,
            'preset' => (string) $edit['preset'],
            'output_preset' => (string) ($edit['output_preset'] ?? 'square_1_1'),
            'output_size' => $outputWidth . 'x' . $outputHeight,
            'resize_mode' => $resizeMode,
        ]);
    }

    private function syncCreativeProjectRun(int $jobId, string $status): void
    {
        try {
            Db::name('creative_project_runs')->where('job_id', $jobId)->update([
                'status' => $status,
                'updated_at' => Web::now(),
            ]);
        } catch (Throwable) {
            // Canvas jobs must remain processable during a rolling schema upgrade.
        }
    }

    private function requestImageWithRetry(NewApiClient $client, array $job, string $prompt, ?array $referenceImage, int $completed, int $total, string $typeLabel, ?array $mask = null, string $size = '1024x1024', bool $allowSizeFallback = false, array $workflowContext = []): array
    {
        $workflowResult = (string) ($job['platform'] ?? '') === 'canvas'
            ? $this->mediaWorkflows->requestImage($job, $prompt, $referenceImage, $mask, $size, $workflowContext)
            : null;
        $capturedRoute = $this->capturedMediaRoute($job);
        $directContext = array_merge($workflowContext, [
            'merchant_id' => (int) $job['merchant_id'],
            'job_id' => (int) $job['id'],
            'generation_item_id' => (int) ($job['_generation_item_id'] ?? 0),
            'require_sub2api' => $capturedRoute['requireSub2Api'],
        ]);
        if ($referenceImage !== null) {
            $directContext['outbound_reference_sha256'] = hash('sha256', (string) ($referenceImage['content'] ?? ''));
        }
        $callKeySuffix = trim((string) ($workflowContext['call_key_suffix'] ?? ''));
        $result = $workflowResult ?? ($referenceImage !== null
            ? $client->editImage(
                (string) $job['model'],
                $prompt,
                $referenceImage,
                $mask,
                $size,
                $capturedRoute['modelId'],
                $capturedRoute['gateway'],
                array_merge($directContext, [
                    'call_key' => 'image-edit:' . (int) $job['id'] . ':' . (int) ($job['_generation_item_id'] ?? 0)
                        . ':manual-retry:' . max(0, (int) ($job['_manual_retry_no'] ?? 0)) . $callKeySuffix,
                ])
            )
            : $client->generateImage(
                (string) $job['model'],
                $prompt,
                $size,
                $capturedRoute['modelId'],
                $capturedRoute['gateway'],
                array_merge($directContext, [
                    'call_key' => 'image-generate:' . (int) $job['id'] . ':' . (int) ($job['_generation_item_id'] ?? 0)
                        . ':manual-retry:' . max(0, (int) ($job['_manual_retry_no'] ?? 0)) . $callKeySuffix,
                ])
            ));
        if (!empty($result['ok'])) {
            return $result;
        }
        if (!empty($result['pending'])) {
            throw new WorkflowPendingException(
                (string) ($result['message'] ?? '模型正在处理中。'),
                !empty($result['manualReview'])
            );
        }
        throw new RuntimeException((string) ($result['message'] ?? '图片生成接口请求失败'));
    }

    private function retryUnchangedReferenceImage(NewApiClient $client, PromptTemplateService $prompts, array $job, array $referenceImage, string $prompt, array $result, int $completed, int $total, string $typeLabel, string $size = '1024x1024', bool $allowSizeFallback = false, array $workflowContext = [], bool $detailPage = false): array
    {
        $image = $result['data']['data'][0] ?? null;
        if (!is_array($image)) {
            throw new RuntimeException('模型没有返回图片数据');
        }
        if (!$this->imageMatchesAnyReference($image, $referenceImage)) {
            return $result;
        }

        Audit::log('system', 0, 'generation_item.unchanged_reference_retry', 'generation_job', $job['id'], '127.0.0.1', [
            'asset_type' => $typeLabel,
            'request_id' => $result['requestId'] ?? ($result['data']['id'] ?? null),
        ]);
        $retryPrompt = $detailPage
            ? $prompts->buildDetailRetryPrompt($prompt)
            : $prompts->buildImageReferenceRetryPrompt($prompt);
        $retryResult = $this->requestImageWithRetry(
            $client,
            $job,
            $retryPrompt,
            $referenceImage,
            $completed,
            $total,
            $typeLabel,
            null,
            $size,
            $allowSizeFallback,
            array_merge($workflowContext, ['call_key_suffix' => ':unchanged-reference-retry'])
        );
        $retryImage = $retryResult['data']['data'][0] ?? null;
        if (!is_array($retryImage)) {
            throw new RuntimeException('模型没有返回重试图片数据');
        }
        if ($this->imageMatchesAnyReference($retryImage, $referenceImage)) {
            throw new RuntimeException('上游连续两次原样返回参考图，结果已拦截且不会扣除额度');
        }
        return $retryResult;
    }

    private function retryUnchangedCanvasEdit(NewApiClient $client, PromptTemplateService $prompts, array $job, array $referenceImage, string $prompt, array $result, string $mode, ?array $mask, string $size): array
    {
        $image = $result['data']['data'][0] ?? null;
        if (!is_array($image)) {
            throw new RuntimeException('模型没有返回编辑后的图片数据');
        }

        $comparison = $mode === 'regional'
            ? $this->canvasRegionalEditMatchesReference($image, $referenceImage, $mask)
            : ['matches' => $this->imageMatchesReference($image, $referenceImage), 'method' => 'normalized_pixels'];
        if (!$comparison['matches']) {
            return $result;
        }

        Audit::log('system', 0, 'canvas_edit.unchanged_source_retry', 'generation_job', $job['id'], '127.0.0.1', [
            'mode' => $mode,
            'comparison' => $comparison['method'],
            'request_id' => $result['requestId'] ?? ($result['data']['id'] ?? null),
        ]);
        $retryResult = $this->requestImageWithRetry(
            $client,
            $job,
            $prompts->buildCanvasRetryPrompt($prompt, $mode),
            $referenceImage,
            0,
            1,
            $mode === 'regional' ? '局部区域编辑' : '整图指令编辑',
            $mask,
            $size,
            true,
            ['asset_type' => 'canvas_edit', 'call_key_suffix' => ':unchanged-source-retry']
        );
        $retryImage = $retryResult['data']['data'][0] ?? null;
        if (!is_array($retryImage)) {
            throw new RuntimeException('模型没有返回画布重试图片数据');
        }
        $retryComparison = $mode === 'regional'
            ? $this->canvasRegionalEditMatchesReference($retryImage, $referenceImage, $mask)
            : ['matches' => $this->imageMatchesReference($retryImage, $referenceImage), 'method' => 'normalized_pixels'];
        if ($retryComparison['matches']) {
            throw new RuntimeException('画布编辑连续两次返回未修改的原图，结果已拦截且不会扣除额度');
        }
        return $retryResult;
    }

    private function saveGeneratedAsset(AssetStorage $storage, array $job, array $merchant, ?array $sku, array $asset, int $assetCredits, int $occurrence, ?int $generationItemId = null): int
    {
        try {
            $result = $this->transactionWithRetry(function () use ($job, $merchant, $sku, $asset, $assetCredits, $occurrence, $generationItemId): array {
            $lockedJob = Db::name('generation_jobs')->where('id', $job['id'])->lock(true)->find();
            if (!$lockedJob || (string) $lockedJob['status'] === 'cancelled') {
                throw new RuntimeException('任务已取消，上游返回结果已丢弃');
            }
            if ($generationItemId !== null) {
                $existing = Db::name('assets')->where('generation_item_id', $generationItemId)->lock(true)->find();
                if ($existing) {
                    if ((string) ($existing['status'] ?? '') !== 'active') {
                        Db::name('assets')->where('id', (int) $existing['id'])->update([
                            'file_url' => $asset['file_url'],
                            'backup_file_url' => $asset['backup_file_url'],
                            'status' => 'active',
                            'deleted_at' => null,
                            'purged_at' => null,
                            'updated_at' => Web::now(),
                        ]);
                    }
                    return ['id' => (int) $existing['id'], 'adopted' => (string) ($existing['status'] ?? '') !== 'active'];
                }
            }
            $existingAssets = Db::name('assets')->where('job_id', $job['id'])
                ->where('asset_type', $asset['asset_type'])->where('status', 'active')->field('id')->lock(true)->select()->toArray();
            $existingCount = count($existingAssets);
            if ($generationItemId === null && $existingCount >= $occurrence) {
                return ['id' => (int) $existingAssets[$occurrence - 1]['id'], 'adopted' => false];
            }
            $now = Web::now();
            $assetId = (int) Db::name('assets')->insertGetId([
                'merchant_id' => $merchant['id'],
                'sku_id' => $sku['id'] ?? null,
                'job_id' => $job['id'],
                'generation_item_id' => $generationItemId,
                'asset_type' => $asset['asset_type'],
                'file_url' => $asset['file_url'],
                'backup_file_url' => $asset['backup_file_url'],
                'score' => null,
                'is_favorite' => 0,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($asset['request_id']) {
                Db::name('usage_records')->insert([
                    'merchant_id' => $merchant['id'],
                    'job_id' => $job['id'],
                    'new_api_request_id' => $asset['request_id'],
                    'model' => $job['model'],
                    'provider' => null,
                    'business_credits' => $assetCredits,
                    'gateway_cost' => 0,
                    'status' => 'pending',
                    'occurred_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            Db::name('generation_jobs')->where('id', $job['id'])->update([
                'actual_credits' => (int) $lockedJob['actual_credits'] + $assetCredits,
                'new_api_request_id' => $lockedJob['new_api_request_id'] ?: $asset['request_id'],
                'updated_at' => $now,
            ]);
            return ['id' => $assetId, 'adopted' => true];
            });
        } catch (Throwable $exception) {
            $this->cleanupUnclaimedStoredAsset($storage, $asset);
            throw $exception;
        }
        if (empty($result['adopted'])) {
            $this->cleanupUnclaimedStoredAsset($storage, $asset);
        }
        return (int) $result['id'];
    }

    private function cleanupUnclaimedStoredAsset(AssetStorage $storage, array $asset): void
    {
        $seen = [];
        foreach (['file_url', 'backup_file_url'] as $key) {
            $url = trim((string) ($asset[$key] ?? ''));
            if ($url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            try {
                $storage->deleteStoredUrl($url);
            } catch (Throwable) {
            }
        }
    }

    private function assertItemActive(int $jobId, int $itemId): void
    {
        $jobStatus = (string) Db::name('generation_jobs')->where('id', $jobId)->value('status');
        $itemStatus = (string) Db::name('generation_job_items')->where('id', $itemId)->value('status');
        if ($jobStatus === 'cancelled' || $itemStatus === 'cancelled') {
            throw new RuntimeException('任务已取消，上游返回结果已丢弃');
        }
    }

    private function assertJobActive(int $jobId): void
    {
        if ((string) Db::name('generation_jobs')->where('id', $jobId)->value('status') === 'cancelled') {
            throw new RuntimeException('任务已取消，上游返回结果已丢弃');
        }
    }

    private function transactionWithRetry(callable $callback): mixed
    {
        for ($attempt = 1; $attempt <= 12; $attempt++) {
            try {
                return Db::transaction($callback);
            } catch (Throwable $exception) {
                $message = strtolower($exception->getMessage());
                $retryable = str_contains($message, 'database is locked')
                    || str_contains($message, 'deadlock detected')
                    || str_contains($message, 'serialization failure');
                if (!$retryable || $attempt >= 12) {
                    throw $exception;
                }
                try {
                    Db::connect()->close();
                } catch (Throwable) {
                }
                usleep(random_int(50000, 150000) * $attempt);
            }
        }
        throw new RuntimeException('数据库并发事务重试失败');
    }

    private function updateProgress(int $jobId, int $current, int $total, string $stage, string $message): void
    {
        $this->transactionWithRetry(function () use ($jobId, $current, $total, $stage, $message): void {
            Db::name('generation_jobs')->where('id', $jobId)->update([
                'progress_current' => max(0, min($current, $total)),
                'progress_total' => max(0, $total),
                'progress_stage' => $stage,
                'progress_message' => $message,
                'updated_at' => Web::now(),
            ]);
        });
    }

    private function settleFailedGenerationReservation(array $job): void
    {
        $reservationId = (int) ($job['_reservation_ledger_id'] ?? 0);
        if ($reservationId <= 0) {
            return;
        }
        Db::transaction(function () use ($job, $reservationId): void {
            $reservation = Db::name('credit_ledger')->where('id', $reservationId)->lock(true)->find();
            if (!$reservation || $reservation['type'] !== 'generation_reserve') {
                return;
            }
            $merchant = Db::name('merchants')->where('id', $job['merchant_id'])->lock(true)->find();
            if (!$merchant) {
                throw new RuntimeException('商户账户不存在，无法退回任务预扣额度');
            }
            $reservedCredits = abs((int) $reservation['amount']);
            $currentActualCredits = (int) Db::name('generation_jobs')->where('id', $job['id'])->value('actual_credits');
            $consumedCredits = max(0, min($reservedCredits, $currentActualCredits - (int) ($job['_actual_credits_before'] ?? 0)));
            $refundCredits = $reservedCredits - $consumedCredits;
            $balance = (int) $merchant['credit_balance'] + $refundCredits;
            $now = Web::now();
            Db::name('credit_ledger')->where('id', $reservationId)->update($consumedCredits > 0 ? [
                'type' => 'consume',
                'amount' => -$consumedCredits,
                'note' => $job['job_no'] . ' 部分素材生成成功',
            ] : [
                'type' => 'generation_refunded',
                'note' => $job['job_no'] . ' 预扣已退回',
            ]);
            if ($refundCredits > 0) {
                Db::name('merchants')->where('id', $merchant['id'])->update(['credit_balance' => $balance, 'updated_at' => $now]);
                Db::name('credit_ledger')->insert([
                    'merchant_id' => $merchant['id'],
                    'type' => 'generation_refund',
                    'amount' => $refundCredits,
                    'balance_after' => $balance,
                    'source' => 'generation_job',
                    'reference_type' => 'credit_ledger',
                    'reference_id' => (string) $reservationId,
                    'note' => $job['job_no'] . ' 未生成部分退款',
                    'created_at' => $now,
                ]);
            }
        });
    }

    private function canvasUpstreamSize(int $width, int $height): string
    {
        $ratio = $width / max(1, $height);
        if ($ratio >= 1.15) {
            return '1536x1024';
        }
        if ($ratio <= 0.87) {
            return '1024x1536';
        }

        return '1024x1024';
    }

    private function skuOutputDimensions(array $sku): array
    {
        $ratio = (string) ($sku['preferred_image_ratio'] ?? 'auto');
        $options = generation_image_ratio_options();
        $selected = $options[$ratio] ?? $options['auto'];
        return ['width' => (int) $selected['width'], 'height' => (int) $selected['height']];
    }

    private function detailOutputDimensions(array $job): array
    {
        $context = json_decode((string) ($job['task_context_json'] ?? ''), true);
        $context = is_array($context) ? $context : [];
        $width = (int) ($context['detail_output_width'] ?? 1024);
        $height = (int) ($context['detail_output_height'] ?? 1536);
        if ($width < 512 || $width > 2048 || $height < 512 || $height > 2048 || $width * $height > 4194304) {
            return ['width' => 1024, 'height' => 1536];
        }

        return ['width' => $width, 'height' => $height];
    }

    private function generatedOutputResizeMode(int $width, int $height): string
    {
        [$upstreamWidth, $upstreamHeight] = array_map('intval', explode('x', $this->canvasUpstreamSize($width, $height), 2));
        $targetRatio = $width / max(1, $height);
        $upstreamRatio = $upstreamWidth / max(1, $upstreamHeight);

        // The gateway only accepts square, 3:2 and 2:3 canvases. Cropping a near-but-not-equal
        // canvas removes the marketing copy placed at the edge, so preserve it with a soft fill.
        return abs($targetRatio - $upstreamRatio) < 0.005 ? 'crop' : 'preserve';
    }

    private function persistCanvasImage(AssetStorage $storage, array $item, int $merchantId, string $jobNo, int $width, int $height, string $resizeMode): array
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('服务器未安装 GD 扩展，无法输出平台标准尺寸');
        }
        if (!empty($item['b64_json'])) {
            $content = $this->decodeGeneratedImage((string) $item['b64_json']);
        } elseif (!empty($item['url'])) {
            $content = $this->download((string) $item['url']);
        } else {
            throw new RuntimeException('模型返回中没有图片 URL 或 base64 数据');
        }
        $this->assertGeneratedImageBounds($content);
        $source = @imagecreatefromstring($content);
        if ($source === false) {
            throw new RuntimeException('模型返回的图片无法进行尺寸处理');
        }
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $destination = imagecreatetruecolor($width, $height);
        if ($destination === false) {
            throw new RuntimeException('服务器无法创建目标尺寸画布');
        }
        $preserveContent = $resizeMode === 'preserve';
        if ($preserveContent) {
            $backgroundScale = max($width / $sourceWidth, $height / $sourceHeight);
            $backgroundWidth = max(1, (int) round($sourceWidth * $backgroundScale));
            $backgroundHeight = max(1, (int) round($sourceHeight * $backgroundScale));
            imagecopyresampled(
                $destination,
                $source,
                (int) floor(($width - $backgroundWidth) / 2),
                (int) floor(($height - $backgroundHeight) / 2),
                0,
                0,
                $backgroundWidth,
                $backgroundHeight,
                $sourceWidth,
                $sourceHeight
            );
            // A softened edge extension keeps the requested platform ratio without cutting
            // product labels, marketing text or the main subject from the generated image.
            for ($pass = 0; $pass < 1; $pass++) {
                imagefilter($destination, IMG_FILTER_GAUSSIAN_BLUR);
            }
        } else {
            $white = imagecolorallocate($destination, 255, 255, 255);
            imagefill($destination, 0, 0, $white);
        }
        $scale = $resizeMode === 'contain' || $preserveContent
            ? min($width / $sourceWidth, $height / $sourceHeight)
            : max($width / $sourceWidth, $height / $sourceHeight);
        $drawWidth = max(1, (int) round($sourceWidth * $scale));
        $drawHeight = max(1, (int) round($sourceHeight * $scale));
        $destinationX = (int) floor(($width - $drawWidth) / 2);
        $destinationY = (int) floor(($height - $drawHeight) / 2);
        imagecopyresampled($destination, $source, $destinationX, $destinationY, 0, 0, $drawWidth, $drawHeight, $sourceWidth, $sourceHeight);
        ob_start();
        $encoded = imagepng($destination, null, 6);
        $output = (string) ob_get_clean();
        unset($destination, $source);
        if (!$encoded || $output === '') {
            throw new RuntimeException('目标尺寸图片编码失败');
        }

        return $storage->storeImageRecord($output, $merchantId, $jobNo, 'canvas-edit-' . $width . 'x' . $height);
    }

    private function skuReferenceImage(AssetStorage $storage, array $sku): array
    {
        $images = [$storage->readImageWithBackup(
            (string) ($sku['source_image_path'] ?? ''),
            (string) ($sku['source_image_backup_path'] ?? '')
        )];
        $records = json_decode((string) ($sku['reference_images_json'] ?? '[]'), true);
        foreach (array_slice(is_array($records) ? $records : [], 0, 5) as $record) {
            if (!is_array($record) || trim((string) ($record['url'] ?? '')) === '') {
                continue;
            }
            $images[] = $storage->readImageWithBackup(
                (string) $record['url'],
                (string) ($record['backup_url'] ?? '')
            );
        }
        if (count($images) === 1) {
            $images[0]['_reference_originals'] = $images;
            $images[0]['_reference_count'] = 1;
            return $images[0];
        }
        if (!extension_loaded('gd')) {
            throw new RuntimeException('服务器缺少 GD 扩展，暂时无法合并多张商品参考图');
        }

        $count = count($images);
        $columns = $count <= 4 ? 2 : 3;
        $rows = (int) ceil($count / $columns);
        $cellSize = 512;
        $canvas = imagecreatetruecolor($columns * $cellSize, $rows * $cellSize);
        if ($canvas === false) {
            throw new RuntimeException('无法创建多参考图画板');
        }
        $background = imagecolorallocate($canvas, 244, 247, 250);
        $cellBackground = imagecolorallocate($canvas, 255, 255, 255);
        $border = imagecolorallocate($canvas, 203, 214, 225);
        imagefill($canvas, 0, 0, $background);
        foreach ($images as $index => $image) {
            $source = @imagecreatefromstring((string) ($image['content'] ?? ''));
            if ($source === false) {
                throw new RuntimeException('其中一张商品参考图无法解码');
            }
            $column = $index % $columns;
            $row = (int) floor($index / $columns);
            $x = $column * $cellSize;
            $y = $row * $cellSize;
            imagefilledrectangle($canvas, $x + 10, $y + 10, $x + $cellSize - 10, $y + $cellSize - 10, $cellBackground);
            imagerectangle($canvas, $x + 10, $y + 10, $x + $cellSize - 10, $y + $cellSize - 10, $border);
            $sourceWidth = max(1, imagesx($source));
            $sourceHeight = max(1, imagesy($source));
            $scale = min(($cellSize - 40) / $sourceWidth, ($cellSize - 40) / $sourceHeight);
            $width = max(1, (int) round($sourceWidth * $scale));
            $height = max(1, (int) round($sourceHeight * $scale));
            imagecopyresampled(
                $canvas,
                $source,
                $x + (int) floor(($cellSize - $width) / 2),
                $y + (int) floor(($cellSize - $height) / 2),
                0,
                0,
                $width,
                $height,
                $sourceWidth,
                $sourceHeight
            );
        }
        ob_start();
        $encoded = imagepng($canvas, null, 6);
        $content = (string) ob_get_clean();
        if (!$encoded || $content === '') {
            throw new RuntimeException('多参考图画板编码失败');
        }
        return [
            'content' => $content,
            'mime' => 'image/png',
            'filename' => 'product-reference-board.png',
            '_reference_originals' => $images,
            '_reference_count' => $count,
        ];
    }

    private function freeReferenceImage(AssetStorage $storage, array $request): ?array
    {
        $primary = trim((string) ($request['reference_image_url'] ?? ''));
        if ($primary === '') {
            return null;
        }
        return $this->skuReferenceImage($storage, [
            'source_image_path' => $primary,
            'source_image_backup_path' => (string) ($request['reference_image_backup_url'] ?? ''),
            'reference_images_json' => (string) ($request['reference_images_json'] ?? '[]'),
        ]);
    }

    private function freezeFreeOutboundReference(int $jobId, array $request, ?array $referenceImage): void
    {
        $actual = $referenceImage === null
            ? ''
            : hash('sha256', (string) ($referenceImage['content'] ?? ''));
        $frozen = trim((string) ($request['outbound_reference_sha256'] ?? ''));
        if ($frozen !== '' && !hash_equals($frozen, $actual)) {
            throw new RuntimeException('自由创作实际上传的参考图画板与已冻结内容不一致，任务已停止。');
        }
        if ($frozen === '' && $actual !== '') {
            Db::name('free_generation_jobs')
                ->where('job_id', $jobId)
                ->where(static function ($query): void {
                    $query->whereNull('outbound_reference_sha256')->whereOr('outbound_reference_sha256', '');
                })
                ->update(['outbound_reference_sha256' => $actual, 'updated_at' => Web::now()]);
            $stored = trim((string) Db::name('free_generation_jobs')
                ->where('job_id', $jobId)
                ->value('outbound_reference_sha256'));
            if ($stored === '' || !hash_equals($stored, $actual)) {
                throw new RuntimeException('自由创作参考图画板冻结失败，任务已停止。');
            }
        }
    }

    private function imageMatchesAnyReference(array $item, array $referenceImage): bool
    {
        $generated = $this->imageContent($item);
        if ($generated === null) {
            return false;
        }
        $references = is_array($referenceImage['_reference_originals'] ?? null)
            ? $referenceImage['_reference_originals']
            : [$referenceImage];
        foreach ($references as $referenceImageItem) {
            $reference = is_array($referenceImageItem) ? (string) ($referenceImageItem['content'] ?? '') : '';
            if ($reference === '') {
                continue;
            }
            if (hash_equals(hash('sha256', $reference), hash('sha256', $generated))
                || $this->visuallyMatchesReference($reference, $generated)) {
                return true;
            }
        }
        return false;
    }

    private function imageMatchesReference(array $item, array $referenceImage): bool
    {
        $reference = (string) ($referenceImage['content'] ?? '');
        if ($reference === '') {
            return false;
        }
        if (!empty($item['b64_json'])) {
            $generated = base64_decode((string) $item['b64_json'], true);
            if ($generated === false) {
                return false;
            }
        } elseif (!empty($item['url'])) {
            $generated = $this->download((string) $item['url']);
        } else {
            return false;
        }

        if (hash_equals(hash('sha256', $reference), hash('sha256', $generated))) {
            return true;
        }

        // Gateways commonly recompress the supplied image before returning it. Compare
        // normalized pixels as well, so metadata or encoding changes cannot bypass the guard.
        return $this->visuallyMatchesReference($reference, $generated);
    }

    /**
     * A local canvas edit is allowed to keep nearly all pixels unchanged. Only inspect the
     * painted (transparent) mask area, while an exact source return is always rejected.
     *
     * @return array{matches: bool, method: string}
     */
    private function canvasRegionalEditMatchesReference(array $item, array $referenceImage, ?array $mask): array
    {
        if ($this->imageBytesMatchReference($item, $referenceImage)) {
            return ['matches' => true, 'method' => 'sha256'];
        }
        if ($mask === null || !extension_loaded('gd')) {
            return ['matches' => false, 'method' => 'mask_unavailable'];
        }

        $reference = (string) ($referenceImage['content'] ?? '');
        $maskContent = (string) ($mask['content'] ?? '');
        $generated = $this->imageContent($item);
        if ($reference === '' || $maskContent === '' || $generated === null) {
            return ['matches' => false, 'method' => 'mask_unavailable'];
        }
        $source = @imagecreatefromstring($reference);
        $candidate = @imagecreatefromstring($generated);
        $maskImage = @imagecreatefromstring($maskContent);
        if ($source === false || $candidate === false || $maskImage === false) {
            return ['matches' => false, 'method' => 'mask_unavailable'];
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            return ['matches' => false, 'method' => 'mask_unavailable'];
        }
        $step = max(1, (int) floor(max($sourceWidth, $sourceHeight) / 512));
        $difference = 0.0;
        $samples = 0;
        for ($y = 0; $y < $sourceHeight; $y += $step) {
            $maskY = min(imagesy($maskImage) - 1, (int) floor($y * imagesy($maskImage) / $sourceHeight));
            $candidateY = min(imagesy($candidate) - 1, (int) floor($y * imagesy($candidate) / $sourceHeight));
            for ($x = 0; $x < $sourceWidth; $x += $step) {
                $maskX = min(imagesx($maskImage) - 1, (int) floor($x * imagesx($maskImage) / $sourceWidth));
                $maskPixel = imagecolorat($maskImage, $maskX, $maskY);
                $alpha = ($maskPixel >> 24) & 0x7f;
                if ($alpha < 64) {
                    continue;
                }
                $candidateX = min(imagesx($candidate) - 1, (int) floor($x * imagesx($candidate) / $sourceWidth));
                $referencePixel = imagecolorat($source, $x, $y);
                $candidatePixel = imagecolorat($candidate, $candidateX, $candidateY);
                foreach ([16, 8, 0] as $shift) {
                    $difference += abs((($referencePixel >> $shift) & 0xff) - (($candidatePixel >> $shift) & 0xff));
                }
                $samples++;
            }
        }
        unset($source, $candidate, $maskImage);
        if ($samples < 8) {
            return ['matches' => false, 'method' => 'mask_too_small'];
        }

        $normalizedDifference = $difference / ($samples * 3 * 255);
        return ['matches' => $normalizedDifference <= 0.035, 'method' => 'masked_pixels'];
    }

    private function imageBytesMatchReference(array $item, array $referenceImage): bool
    {
        $reference = (string) ($referenceImage['content'] ?? '');
        $generated = $this->imageContent($item);
        return $reference !== '' && $generated !== null
            && hash_equals(hash('sha256', $reference), hash('sha256', $generated));
    }

    private function imageContent(array $item): ?string
    {
        if (!empty($item['b64_json'])) {
            $content = base64_decode((string) $item['b64_json'], true);
            return $content === false ? null : $content;
        }
        if (!empty($item['url'])) {
            return $this->download((string) $item['url']);
        }
        return null;
    }

    private function visuallyMatchesReference(string $reference, string $generated): bool
    {
        if (!extension_loaded('gd')) {
            return false;
        }
        $source = @imagecreatefromstring($reference);
        $candidate = @imagecreatefromstring($generated);
        if ($source === false || $candidate === false) {
            return false;
        }
        $canUsePerceptualHash = imagesx($source) >= 16 && imagesy($source) >= 16
            && imagesx($candidate) >= 16 && imagesy($candidate) >= 16;

        $size = 32;
        $sourceThumb = imagecreatetruecolor($size, $size);
        $candidateThumb = imagecreatetruecolor($size, $size);
        if ($sourceThumb === false || $candidateThumb === false) {
            return false;
        }

        imagecopyresampled($sourceThumb, $source, 0, 0, 0, 0, $size, $size, imagesx($source), imagesy($source));
        imagecopyresampled($candidateThumb, $candidate, 0, 0, 0, 0, $size, $size, imagesx($candidate), imagesy($candidate));
        $difference = 0.0;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $referencePixel = imagecolorat($sourceThumb, $x, $y);
                $generatedPixel = imagecolorat($candidateThumb, $x, $y);
                foreach ([16, 8, 0] as $shift) {
                    $difference += abs((($referencePixel >> $shift) & 0xff) - (($generatedPixel >> $shift) & 0xff));
                }
            }
        }
        // A gateway may add a tiny watermark, recompress, or make a light colour tweak
        // before returning the supplied image. Those are still a failed reference-image
        // edit, while a real recreation changes the frame far beyond this threshold.
        $normalizedDifference = $difference / ($size * $size * 3 * 255);
        if ($normalizedDifference <= 0.045) {
            return true;
        }

        return $canUsePerceptualHash && $this->hasNearIdenticalPerceptualHash($sourceThumb, $candidateThumb);
    }

    private function hasNearIdenticalPerceptualHash(\GdImage $source, \GdImage $candidate): bool
    {
        // Tiny placeholders have no meaningful spatial structure. Their dHash is all
        // zeros even when their colours differ, so keep the pixel-difference result.
        if (imagesx($source) < 16 || imagesy($source) < 16 || imagesx($candidate) < 16 || imagesy($candidate) < 16) {
            return false;
        }
        $sourceHash = $this->differenceHash($source);
        $candidateHash = $this->differenceHash($candidate);
        if ($sourceHash === '' || $candidateHash === '' || strlen($sourceHash) !== strlen($candidateHash)) {
            return false;
        }
        $distance = 0;
        for ($index = 0, $length = strlen($sourceHash); $index < $length; $index++) {
            $distance += substr_count(decbin(ord($sourceHash[$index]) ^ ord($candidateHash[$index])), '1');
        }

        // A 32px thumbnail produces 31x32 comparison bits. Near-identical image frames
        // survive encoding changes with a very small Hamming distance.
        return $distance <= 18;
    }

    private function differenceHash(\GdImage $image): string
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 2 || $height < 1) {
            return '';
        }
        $bits = '';
        for ($y = 0; $y < $height; $y++) {
            $previous = null;
            for ($x = 0; $x < $width; $x++) {
                $pixel = imagecolorat($image, $x, $y);
                $luminance = ((($pixel >> 16) & 0xff) * 299) + ((($pixel >> 8) & 0xff) * 587) + (($pixel & 0xff) * 114);
                if ($previous !== null) {
                    $bits .= $previous > $luminance ? '1' : '0';
                }
                $previous = $luminance;
            }
        }
        $hash = '';
        for ($offset = 0, $length = strlen($bits); $offset < $length; $offset += 8) {
            $hash .= chr(bindec(substr($bits, $offset, 8)));
        }
        return $hash;
    }

    private function readCanvasMask(string $filename): array
    {
        $safeName = basename($filename);
        if ($safeName === '' || $safeName !== $filename) {
            throw new RuntimeException('画布遮罩文件无效');
        }
        $path = rtrim($this->app->getRuntimePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'canvas-masks' . DIRECTORY_SEPARATOR . $safeName;
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false || $content === '') {
            throw new RuntimeException('画布遮罩文件不存在，请重新提交编辑任务');
        }

        return ['content' => $content, 'mime' => 'image/png', 'filename' => 'canvas-mask.png'];
    }

    private function deleteCanvasMask(string $filename): void
    {
        $safeName = basename($filename);
        if ($safeName === '' || $safeName !== $filename) {
            return;
        }
        $path = rtrim($this->app->getRuntimePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'canvas-masks' . DIRECTORY_SEPARATOR . $safeName;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function creditsForPosition(int $estimatedCredits, int $total, int $index): int
    {
        if ($estimatedCredits <= 0 || $total <= 0) {
            return 0;
        }
        $base = intdiv($estimatedCredits, $total);
        return $base + ($index < ($estimatedCredits % $total) ? 1 : 0);
    }

    private function jobWithCapturedModel(array $job): array
    {
        $snapshot = json_decode((string) ($job['route_snapshot_json'] ?? ''), true);
        if (is_array($snapshot)) {
            $capturedModel = trim((string) ($snapshot['resolvedModel'] ?? ''));
            if ($capturedModel !== '') {
                $job['model'] = $capturedModel;
            }
        }
        return $job;
    }

    private function capturedMediaRoute(array $job): array
    {
        $snapshot = json_decode((string) ($job['route_snapshot_json'] ?? ''), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $modelId = max(0, (int) ($snapshot['resolvedModelId'] ?? $job['resolved_ai_model_id'] ?? 0));
        $gateway = strtolower(trim((string) ($snapshot['resolvedGateway'] ?? '')));
        if ($modelId <= 0) {
            $modelName = trim((string) ($snapshot['resolvedModel'] ?? $job['model'] ?? ''));
            if ($modelName !== '') {
                $model = Db::name('ai_models')
                    ->where('model_id', $modelName)
                    ->where('enabled', 1)
                    ->field('id,gateway')
                    ->order('id', 'desc')
                    ->find();
                if ($model) {
                    $modelId = (int) $model['id'];
                    if ($gateway === '' || in_array($gateway, ['sub2api', 'new_api', 'legacy'], true)) {
                        $gateway = strtolower(trim((string) ($model['gateway'] ?? 'sub2api')));
                    }
                }
            }
        }
        return [
            'modelId' => $modelId > 0 ? $modelId : null,
            'gateway' => $gateway !== '' ? $gateway : null,
            'requireSub2Api' => true,
        ];
    }

    private function capturedCapability(array $job): string
    {
        $snapshot = json_decode((string) ($job['route_snapshot_json'] ?? ''), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $media = is_array($snapshot['mediaWorkflow'] ?? null)
            ? $snapshot['mediaWorkflow']
            : (is_array($snapshot['media_workflow'] ?? null) ? $snapshot['media_workflow'] : []);
        return trim((string) (
            $snapshot['capability']
            ?? $snapshot['workflowCapability']
            ?? $media['workflow_capability']
            ?? $media['capability']
            ?? ''
        ));
    }

    private function persistImage(AssetStorage $storage, array $item, int $merchantId, string $jobNo, string $assetType): array
    {
        if (!empty($item['b64_json'])) {
            $content = $this->decodeGeneratedImage((string) $item['b64_json']);
        } elseif (!empty($item['url'])) {
            $content = $this->download((string) $item['url']);
        } else {
            throw new RuntimeException('模型返回中没有图片 URL 或 base64 数据');
        }
        $this->assertGeneratedImageBounds($content);

        return $storage->storeImageRecord($content, $merchantId, $jobNo, $assetType);
    }

    private function decodeGeneratedImage(string $encoded): string
    {
        $encoded = trim($encoded);
        $maxEncodedBytes = (int) ceil(self::MAX_GENERATED_IMAGE_BYTES * 4 / 3) + 8;
        if ($encoded === '' || strlen($encoded) > $maxEncodedBytes) {
            throw new RuntimeException('模型返回的图片编码为空或超过 25MB 安全上限');
        }
        $content = base64_decode($encoded, true);
        if (!is_string($content)) {
            throw new RuntimeException('模型返回的图片编码无效');
        }
        $this->assertGeneratedImageBounds($content);
        return $content;
    }

    private function assertGeneratedImageBounds(string $content): void
    {
        if ($content === '' || strlen($content) > self::MAX_GENERATED_IMAGE_BYTES) {
            throw new RuntimeException('模型返回的图片为空或超过 25MB 安全上限');
        }
        $size = @getimagesizefromstring($content);
        $width = (int) ($size[0] ?? 0);
        $height = (int) ($size[1] ?? 0);
        if (!is_array($size) || $width <= 0 || $height <= 0
            || $width > self::MAX_GENERATED_IMAGE_DIMENSION
            || $height > self::MAX_GENERATED_IMAGE_DIMENSION
            || $width * $height > self::MAX_GENERATED_IMAGE_PIXELS) {
            throw new RuntimeException('模型返回的图片尺寸无效或超过 4096px/1600万像素安全上限');
        }
    }

    private function download(string $url): string
    {
        $target = RemoteMediaUrlGuard::publicHttpsTarget($url);
        $content = '';
        $curl = curl_init($target['url']);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE => $target['resolve'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$content): int {
                if (strlen($content) + strlen($chunk) > 20 * 1024 * 1024) {
                    return 0;
                }
                $content .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        if ($ok === false || $status < 200 || $status >= 300 || $content === '') {
            throw new RuntimeException('下载生成图片失败：' . ($error ?: 'HTTP ' . $status));
        }

        return $content;
    }
}
