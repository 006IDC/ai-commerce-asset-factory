<?php

declare(strict_types=1);

use app\service\GenerationItemQueue;
use app\service\GenerationJobView;
use app\service\GenerationExecutionAttemptService;
use app\support\Web;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = (new App(dirname(__DIR__)))->initialize();
$queue = $app->make(GenerationItemQueue::class);
$view = $app->make(GenerationJobView::class);
$executionAttempts = $app->make(GenerationExecutionAttemptService::class);
$merchantId = (int) Db::name('merchants')->order('id', 'asc')->value('id');
if ($merchantId <= 0) {
    throw new RuntimeException('Job result consistency test requires a merchant.');
}

$now = Web::now();
$suffix = strtoupper(bin2hex(random_bytes(4)));
$jobIds = [];
$ledgerIds = [];
$originalBalance = (int) Db::name('merchants')->where('id', $merchantId)->value('credit_balance');

$createJob = static function (string $name, array $types, string $status = 'running') use ($merchantId, $now, $suffix, &$jobIds): int {
    $jobId = (int) Db::name('generation_jobs')->insertGetId([
        'merchant_id' => $merchantId,
        'sku_id' => null,
        'job_no' => 'RESULT-' . $name . '-' . $suffix,
        'platform' => 'taobao',
        'language' => 'zh-CN',
        'asset_types' => json_encode($types),
        'safety_status' => 'passed',
        'status' => $status,
        'estimated_credits' => count($types) * 2,
        'actual_credits' => 0,
        'progress_current' => 0,
        'progress_total' => count($types),
        'progress_stage' => $status,
        'progress_message' => '测试任务',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $jobIds[] = $jobId;
    return $jobId;
};

$createItem = static function (int $jobId, int $position, string $status = 'processing', ?int $reservationId = null) use ($merchantId, $now): int {
    return (int) Db::name('generation_job_items')->insertGetId([
        'job_id' => $jobId,
        'merchant_id' => $merchantId,
        'position' => $position,
        'asset_type' => $position === 1 ? 'main' : 'secondary',
        'occurrence' => 1,
        'type_total' => 1,
        'estimated_credits' => 2,
        'status' => $status,
        'attempts' => 1,
        'reservation_ledger_id' => $reservationId,
        'started_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
};

try {
    if (localized_job_error('') !== '') {
        throw new RuntimeException('Empty errors must not be replaced with a failure message.');
    }
    echo "[ok] empty job errors remain empty\n";

    $missingJobId = $createJob('MISSING', ['main']);
    Db::name('merchants')->where('id', $merchantId)->update(['credit_balance' => $originalBalance - 2, 'updated_at' => $now]);
    $reservationId = (int) Db::name('credit_ledger')->insertGetId([
        'merchant_id' => $merchantId,
        'type' => 'generation_reserve',
        'amount' => -2,
        'balance_after' => $originalBalance - 2,
        'source' => 'generation_job',
        'reference_type' => 'generation_job',
        'reference_id' => (string) $missingJobId,
        'note' => '结果一致性测试预扣',
        'created_at' => $now,
    ]);
    $ledgerIds[] = $reservationId;
    $missingItemId = $createItem($missingJobId, 1, 'processing', $reservationId);
    $missingResult = $queue->complete($missingItemId, PHP_INT_MAX, 'missing-request');
    $missingJob = Db::name('generation_jobs')->where('id', $missingJobId)->find();
    $missingItem = Db::name('generation_job_items')->where('id', $missingItemId)->find();
    $balanceAfterMissing = (int) Db::name('merchants')->where('id', $merchantId)->value('credit_balance');
    if (($missingResult['status'] ?? '') !== 'failed' || ($missingJob['status'] ?? '') !== 'failed'
        || ($missingItem['status'] ?? '') !== 'result_missing' || $balanceAfterMissing !== $originalBalance) {
        throw new RuntimeException('Missing output was incorrectly marked completed or credits were not refunded.');
    }
    echo "[ok] completed worker call without an active asset becomes result_missing and refunds credits\n";

    $completeJobId = $createJob('COMPLETE', ['main']);
    $completeItemId = $createItem($completeJobId, 1);
    $assetId = (int) Db::name('assets')->insertGetId([
        'merchant_id' => $merchantId,
        'sku_id' => null,
        'job_id' => $completeJobId,
        'generation_item_id' => $completeItemId,
        'asset_type' => 'main',
        'file_url' => '/storage/tests/result-' . strtolower($suffix) . '.png',
        'backup_file_url' => null,
        'score' => null,
        'is_favorite' => 0,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $completeResult = $queue->complete($completeItemId, $assetId, 'valid-request');
    $completeView = $view->enrich([Db::name('generation_jobs')->where('id', $completeJobId)->find()])[0];
    if (($completeResult['status'] ?? '') !== 'completed' || $completeView['result_status'] !== 'available'
        || (int) $completeView['available_result_count'] !== 1) {
        throw new RuntimeException('Valid completed output was not classified as available.');
    }
    echo "[ok] completed image task requires and exposes an active asset\n";

    $partialJobId = $createJob('PARTIAL', ['main', 'secondary']);
    $firstItemId = $createItem($partialJobId, 1);
    $secondItemId = $createItem($partialJobId, 2);
    $partialAssetId = (int) Db::name('assets')->insertGetId([
        'merchant_id' => $merchantId,
        'sku_id' => null,
        'job_id' => $partialJobId,
        'generation_item_id' => $firstItemId,
        'asset_type' => 'main',
        'file_url' => '/storage/tests/partial-' . strtolower($suffix) . '.png',
        'backup_file_url' => null,
        'score' => null,
        'is_favorite' => 0,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $queue->complete($firstItemId, $partialAssetId, 'partial-valid-request');
    $partialResult = $queue->fail($secondItemId, '永久性测试失败');
    $partialView = $view->enrich([Db::name('generation_jobs')->where('id', $partialJobId)->find()])[0];
    if (($partialResult['status'] ?? '') !== 'partial' || $partialView['result_status'] !== 'partial'
        || (int) $partialView['available_result_count'] !== 1 || (int) $partialView['expected_result_count'] !== 2) {
        throw new RuntimeException('Partial output was not classified consistently.');
    }
    echo "[ok] partial output reports 1/2 available without a zero-percent failure state\n";

    $terminalJobId = $createJob('TERMINAL-RETRY', ['main']);
    $terminalItemId = $createItem($terminalJobId, 1);
    Db::name('generation_job_items')->where('id', $terminalItemId)->update([
        'request_id' => 'sub2api:terminal-safety-policy',
        'updated_at' => $now,
    ]);
    $terminalItem = Db::name('generation_job_items')->where('id', $terminalItemId)->find();
    $terminalJob = Db::name('generation_jobs')->where('id', $terminalJobId)->find();
    $attemptId = $executionAttempts->start($terminalItem ?: [], $terminalJob ?: []);
    $terminalMessage = 'Sub2API：图片被上游安全策略拦截，请换个描述后重试';
    $executionAttempts->fail($attemptId, $terminalMessage, 'sub2api:terminal-safety-policy');
    $queue->fail($terminalItemId, $terminalMessage);
    if ($queue->activatePendingItems($terminalJobId) !== 1) {
        throw new RuntimeException('A provider-terminal failed item could not be explicitly retried from its original job.');
    }
    $terminalRetry = Db::name('generation_job_items')->where('id', $terminalItemId)->find();
    if (($terminalRetry['status'] ?? '') !== 'queued'
        || trim((string) ($terminalRetry['request_id'] ?? '')) !== ''
        || (int) ($terminalRetry['manual_retry_count'] ?? 0) !== 1) {
        throw new RuntimeException('Manual terminal retry did not clear the old marker and advance its idempotency round.');
    }
    echo "[ok] an explicitly failed provider attempt can retry from the original job with a new idempotency round\n";

    $historicalJobId = $createJob('HISTORICAL', ['main'], 'completed');
    $historicalItemId = $createItem($historicalJobId, 1, 'completed');
    Db::name('generation_job_items')->where('id', $historicalItemId)->update(['completed_at' => $now]);
    $historicalView = $view->enrich([Db::name('generation_jobs')->where('id', $historicalJobId)->find()])[0];
    if ($historicalView['result_status'] !== 'missing' || $historicalView['error_message'] !== '') {
        throw new RuntimeException('Historical completed task without an asset was not exposed as result missing.');
    }
    if ($queue->activatePendingItems($historicalJobId) !== 1
        || Db::name('generation_job_items')->where('id', $historicalItemId)->value('status') !== 'queued') {
        throw new RuntimeException('Missing historical result could not be prepared for requeue.');
    }
    echo "[ok] historical completed task with no asset is filterable and can be requeued\n";
} finally {
    if ($jobIds !== []) {
        $generationItemIds = Db::name('generation_job_items')->whereIn('job_id', $jobIds)->column('id');
        Db::name('assets')->whereIn('job_id', $jobIds)->delete();
        if ($generationItemIds !== []) {
            Db::name('generation_execution_attempts')->whereIn('generation_item_id', $generationItemIds)->delete();
        }
        Db::name('generation_job_items')->whereIn('job_id', $jobIds)->delete();
        Db::name('credit_ledger')->where('merchant_id', $merchantId)->where(function ($query) use ($jobIds, $ledgerIds): void {
            if ($ledgerIds !== []) {
                $query->whereIn('id', $ledgerIds);
            }
            $query->whereOr(function ($nested) use ($ledgerIds): void {
                $nested->where('reference_type', 'credit_ledger')->whereIn('reference_id', array_map('strval', $ledgerIds));
            });
        })->delete();
        Db::name('generation_jobs')->whereIn('id', $jobIds)->delete();
        Db::name('audit_logs')->where('target_type', 'generation_job')->whereIn('target_id', array_map('strval', $jobIds))->delete();
    }
    Db::name('merchants')->where('id', $merchantId)->update(['credit_balance' => $originalBalance, 'updated_at' => Web::now()]);
}

echo "Job result consistency tests completed successfully.\n";
