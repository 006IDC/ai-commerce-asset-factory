<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use RuntimeException;
use think\facade\Db;

final class RecognitionBillingService
{
    public function availableCredits(int $merchantId, int $balance): int
    {
        $pendingJobs = Db::name('generation_jobs')
            ->where('merchant_id', $merchantId)
            ->whereIn('status', ['queued', 'pending_gateway'])
            ->field('estimated_credits,actual_credits')
            ->select()
            ->toArray();
        $committed = array_reduce($pendingJobs, static function (int $total, array $job): int {
            return $total + max(0, (int) $job['estimated_credits'] - (int) $job['actual_credits']);
        }, 0);

        return max(0, $balance - $committed);
    }

    public function reserve(int $merchantId, int $credits, string $reference): array
    {
        if ($credits <= 0) {
            $balance = (int) Db::name('merchants')->where('id', $merchantId)->value('credit_balance');
            return [null, $balance];
        }

        return Db::transaction(function () use ($merchantId, $credits, $reference): array {
            $merchant = Db::name('merchants')->where('id', $merchantId)->lock(true)->find();
            if (!$merchant || $merchant['status'] !== 'active') {
                throw new RuntimeException('商户账户当前不可用。');
            }
            $balance = (int) $merchant['credit_balance'] - $credits;
            if ($this->availableCredits($merchantId, (int) $merchant['credit_balance']) < $credits || $balance < 0) {
                throw new RuntimeException("点数不足，商品识别需要 {$credits} 点数。");
            }
            $now = Web::now();
            Db::name('merchants')->where('id', $merchantId)->update(['credit_balance' => $balance, 'updated_at' => $now]);
            $ledgerId = Db::name('credit_ledger')->insertGetId([
                'merchant_id' => $merchantId,
                'type' => 'recognition_consume',
                'amount' => -$credits,
                'balance_after' => $balance,
                'source' => 'product_recognition',
                'reference_type' => 'product_recognition',
                'reference_id' => $reference,
                'note' => 'AI 商品识别',
                'created_at' => $now,
            ]);

            return [(int) $ledgerId, $balance];
        });
    }

    public function refund(int $merchantId, int $credits, string $reference, string $note = 'AI 商品识别失败退款'): int
    {
        if ($credits <= 0) {
            return (int) Db::name('merchants')->where('id', $merchantId)->value('credit_balance');
        }

        return Db::transaction(function () use ($merchantId, $credits, $reference, $note): int {
            $merchant = Db::name('merchants')->where('id', $merchantId)->lock(true)->find();
            if (!$merchant) {
                throw new RuntimeException('商户账户不存在，无法退回识别点数。');
            }
            $existingRefund = Db::name('credit_ledger')
                ->where('merchant_id', $merchantId)
                ->where('type', 'recognition_refund')
                ->where('reference_id', $reference)
                ->find();
            if ($existingRefund) {
                return (int) $merchant['credit_balance'];
            }
            $balance = (int) $merchant['credit_balance'] + $credits;
            $now = Web::now();
            Db::name('merchants')->where('id', $merchantId)->update(['credit_balance' => $balance, 'updated_at' => $now]);
            Db::name('credit_ledger')->insert([
                'merchant_id' => $merchantId,
                'type' => 'recognition_refund',
                'amount' => $credits,
                'balance_after' => $balance,
                'source' => 'product_recognition',
                'reference_type' => 'product_recognition',
                'reference_id' => $reference,
                'note' => mb_strimwidth($note, 0, 190, ''),
                'created_at' => $now,
            ]);

            return $balance;
        });
    }
}
