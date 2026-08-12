<?php

declare(strict_types=1);

use app\service\GenerationQualityAppealService;
use app\support\Web;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = (new App(dirname(__DIR__)))->initialize();
$appeals = $app->make(GenerationQualityAppealService::class);
$user = Db::name('merchant_users')->alias('u')
    ->join('merchants m', 'm.id = u.merchant_id')
    ->where('m.status', 'active')
    ->field('u.id,u.merchant_id')
    ->order('u.id', 'asc')
    ->find();
$adminId = (int) Db::name('admin_users')->order('id', 'asc')->value('id');
if (!$user || $adminId <= 0) {
    throw new RuntimeException('Quality appeal test requires an active merchant user and administrator.');
}

function assertQualityAppeal(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$merchantId = (int) $user['merchant_id'];
$userId = (int) $user['id'];
$chargedCredits = 7;
$now = Web::now();

Db::startTrans();
try {
    $merchant = Db::name('merchants')->where('id', $merchantId)->lock(true)->find();
    $originalBalance = (int) $merchant['credit_balance'];
    $chargedBalance = $originalBalance - $chargedCredits;
    Db::name('merchants')->where('id', $merchantId)->update([
        'credit_balance' => $chargedBalance,
        'updated_at' => $now,
    ]);

    $jobId = (int) Db::name('generation_jobs')->insertGetId([
        'merchant_id' => $merchantId,
        'sku_id' => null,
        'job_no' => 'QUALITY-APPEAL-' . strtoupper(bin2hex(random_bytes(5))),
        'platform' => 'free',
        'asset_types' => '["free","free"]',
        'status' => 'completed',
        'estimated_credits' => $chargedCredits * 2,
        'actual_credits' => $chargedCredits * 2,
        'model' => 'quality-test-model',
        'progress_current' => 2,
        'progress_total' => 2,
        'progress_stage' => 'completed',
        'language' => 'zh-CN',
        'safety_status' => 'passed',
        'output_kind' => 'image',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $consumeLedgerId = (int) Db::name('credit_ledger')->insertGetId([
        'merchant_id' => $merchantId,
        'type' => 'consume',
        'amount' => -$chargedCredits,
        'balance_after' => $chargedBalance,
        'source' => 'generation',
        'reference_type' => 'generation_job_item',
        'reference_id' => 'pending',
        'idempotency_key' => 'quality-test-consume:' . $jobId,
        'note' => '质量申诉合同测试',
        'created_at' => $now,
    ]);
    $itemBase = [
        'job_id' => $jobId,
        'merchant_id' => $merchantId,
        'asset_type' => 'free',
        'occurrence' => 1,
        'type_total' => 2,
        'estimated_credits' => $chargedCredits,
        'status' => 'completed',
        'attempts' => 1,
        'charged_credits' => $chargedCredits,
        'completed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ];
    $itemId = (int) Db::name('generation_job_items')->insertGetId($itemBase + [
        'position' => 1,
        'consume_ledger_id' => $consumeLedgerId,
    ]);
    Db::name('credit_ledger')->where('id', $consumeLedgerId)->update(['reference_id' => (string) $itemId]);
    $siblingItemId = (int) Db::name('generation_job_items')->insertGetId($itemBase + [
        'position' => 2,
        'occurrence' => 2,
        'consume_ledger_id' => null,
    ]);
    $assetId = (int) Db::name('assets')->insertGetId([
        'merchant_id' => $merchantId,
        'sku_id' => null,
        'job_id' => $jobId,
        'generation_item_id' => $itemId,
        'asset_type' => 'free',
        'file_url' => '/storage/quality-test-' . $itemId . '.png',
        'media_type' => 'image',
        'mime_type' => 'image/png',
        'width' => 1024,
        'height' => 1024,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    Db::name('generation_job_items')->where('id', $itemId)->update(['asset_id' => $assetId]);

    $appeal = $appeals->submit(
        $merchantId,
        $userId,
        $assetId,
        'too_similar',
        '生成结果几乎原样返回参考图，没有执行明确要求的背景变化。',
        '127.0.0.1'
    );
    assertQualityAppeal((string) $appeal['status'] === 'pending', 'Quality appeal was not submitted as pending.');

    $duplicateRejected = false;
    try {
        $appeals->submit(
            $merchantId,
            $userId,
            $assetId,
            'too_similar',
            '同一张结果重复提交质量申诉，应该被系统阻止。',
            '127.0.0.1'
        );
    } catch (RuntimeException $exception) {
        $duplicateRejected = str_contains($exception->getMessage(), '已有处理中申诉');
    }
    assertQualityAppeal($duplicateRejected, 'A second active appeal was accepted for one generation item.');

    $refunded = $appeals->review(
        (int) $appeal['id'],
        'approve_refund',
        '证据确认结果与参考图过于相似。',
        '审核通过，本张图片额度已退回。',
        $adminId,
        '127.0.0.1'
    );
    $refundLedgerId = (int) ($refunded['refund_ledger_id'] ?? 0);
    $refundLedger = Db::name('credit_ledger')->where('id', $refundLedgerId)->find();
    $itemAfter = Db::name('generation_job_items')->where('id', $itemId)->find();
    $siblingAfter = Db::name('generation_job_items')->where('id', $siblingItemId)->find();
    assertQualityAppeal(
        (string) $refunded['status'] === 'refunded'
            && $refundLedgerId > 0
            && (string) ($refundLedger['type'] ?? '') === 'quality_refund'
            && (int) ($refundLedger['amount'] ?? 0) === $chargedCredits
            && (int) ($itemAfter['quality_refund_ledger_id'] ?? 0) === $refundLedgerId,
        'Approved quality appeal did not create an exact per-item refund ledger.'
    );
    assertQualityAppeal(
        (int) Db::name('merchants')->where('id', $merchantId)->value('credit_balance') === $originalBalance,
        'Quality refund did not restore the exact charged credits.'
    );
    assertQualityAppeal(
        (int) ($siblingAfter['quality_refund_ledger_id'] ?? 0) === 0
            && (string) $siblingAfter['status'] === 'completed'
            && (int) Db::name('generation_jobs')->where('id', $jobId)->value('actual_credits') === $chargedCredits * 2,
        'One-item quality refund changed its successful sibling or parent generation result.'
    );

    $secondReview = $appeals->review(
        (int) $appeal['id'],
        'approve_refund',
        '重复审核调用用于验证幂等。',
        '额度已经退回。',
        $adminId,
        '127.0.0.1'
    );
    assertQualityAppeal(
        (int) $secondReview['refund_ledger_id'] === $refundLedgerId
            && (int) Db::name('credit_ledger')->where('idempotency_key', 'quality-refund:item:' . $itemId)->count() === 1
            && (int) Db::name('merchants')->where('id', $merchantId)->value('credit_balance') === $originalBalance,
        'Repeated quality approval created a duplicate refund.'
    );
    assertQualityAppeal(
        (int) Db::name('generation_quality_appeal_events')->where('appeal_id', (int) $appeal['id'])->count() >= 3,
        'Quality appeal event history is not append-only.'
    );
    assertQualityAppeal(
        (int) Db::name('quality_improvement_items')
            ->where('product_code', 'free_create')
            ->where('issue_type', 'too_similar')
            ->count() >= 1,
        'Approved quality issue did not enter the improvement loop.'
    );

    echo "Quality appeal and per-item refund tests completed successfully.\n";
} finally {
    Db::rollBack();
}
