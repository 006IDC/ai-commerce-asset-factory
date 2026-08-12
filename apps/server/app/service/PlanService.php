<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use think\facade\Db;

final class PlanService
{
    private const LIMIT_FIELDS = [
        'skus' => 'sku_limit',
        'batch_images' => 'batch_image_limit',
        'concurrent_jobs' => 'concurrent_job_limit',
    ];

    public function activePlans(): array
    {
        return Db::name('subscription_plans')
            ->where('status', 'active')
            ->order('sort_order', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    public function catalogForMerchant(int $merchantId): array
    {
        $subscription = $this->currentSubscription($merchantId);
        $plans = $this->activePlans();
        foreach ($plans as &$plan) {
            $quote = $this->purchaseQuote($plan, $subscription);
            $plan = array_merge($plan, $quote);
        }
        unset($plan);

        return ['plans' => $plans, 'subscription' => $subscription];
    }

    public function currentSubscription(int $merchantId): ?array
    {
        $now = Web::now();
        $subscription = Db::name('merchant_subscriptions')
            ->where('merchant_id', $merchantId)
            ->where('status', 'active')
            ->where('starts_at', '<=', $now)
            ->where('expires_at', '>', $now)
            ->order('id', 'desc')
            ->find();

        if ($subscription) {
            return $subscription;
        }
        $expiredCount = Db::name('merchant_subscriptions')
            ->where('merchant_id', $merchantId)
            ->where('status', 'active')
            ->where('expires_at', '<=', $now)
            ->count();
        if ($expiredCount > 0) {
            Db::name('merchant_subscriptions')
                ->where('merchant_id', $merchantId)
                ->where('status', 'active')
                ->where('expires_at', '<=', $now)
                ->update(['status' => 'expired']);
            Db::name('merchants')->where('id', $merchantId)->update(['plan_code' => 'trial', 'updated_at' => $now]);
        }

        return null;
    }

    public function generationQuote(int $merchantId, int $assetCount, int $unitCredits): array
    {
        $subscription = $this->currentSubscription($merchantId);
        if (!$subscription || (int) $subscription['generation_unlimited'] !== 1) {
            return [
                'allowed' => true,
                'credits' => max(0, $assetCount * $unitCredits),
                'subscription' => $subscription,
                'usedToday' => 0,
                'dailyLimit' => 0,
            ];
        }

        $dailyLimit = max(0, (int) $subscription['daily_generation_limit']);
        $usedToday = $this->generationUsageToday($merchantId);
        if ($dailyLimit > 0 && $usedToday + $assetCount > $dailyLimit) {
            return [
                'allowed' => false,
                'credits' => 0,
                'subscription' => $subscription,
                'usedToday' => $usedToday,
                'dailyLimit' => $dailyLimit,
                'message' => "今日套餐生成额度已用 {$usedToday}/{$dailyLimit} 张，本任务需要 {$assetCount} 张。",
            ];
        }

        return [
            'allowed' => true,
            'credits' => 0,
            'subscription' => $subscription,
            'usedToday' => $usedToday,
            'dailyLimit' => $dailyLimit,
        ];
    }

    public function recognitionCredits(int $merchantId, int $standardCredits): int
    {
        $subscription = $this->currentSubscription($merchantId);
        return $subscription && (int) $subscription['recognition_unlimited'] === 1
            ? 0
            : max(0, $standardCredits);
    }

    public function limit(int $merchantId, string $limit): int
    {
        $field = self::LIMIT_FIELDS[$limit] ?? null;
        if ($field === null) {
            return 0;
        }
        $subscription = $this->currentSubscription($merchantId);
        return $subscription ? max(0, (int) ($subscription[$field] ?? 0)) : 0;
    }

    public function batchImageLimit(int $merchantId, int $systemLimit): int
    {
        $configured = $this->limit($merchantId, 'batch_images');
        return $configured > 0 ? min($systemLimit, $configured) : $systemLimit;
    }

    public function canStartJobs(int $merchantId, int $additionalJobs = 1): array
    {
        $limit = $this->limit($merchantId, 'concurrent_jobs');
        if ($limit <= 0) {
            return ['allowed' => true, 'limit' => 0, 'active' => 0];
        }
        $active = (int) Db::name('generation_jobs')->where('merchant_id', $merchantId)
            ->whereIn('status', ['pending_gateway', 'queued', 'running', 'manual_review'])->count();
        return [
            'allowed' => $active + max(1, $additionalJobs) <= $limit,
            'limit' => $limit,
            'active' => $active,
            'message' => "当前套餐最多同时进行 {$limit} 个生成任务，已有 {$active} 个任务进行中。",
        ];
    }

    public function purchase(int $planId, int $merchantId, int $userId): array
    {
        return Db::transaction(function () use ($planId, $merchantId, $userId): array {
            $plan = Db::name('subscription_plans')->where('id', $planId)->lock(true)->find();
            if (!$plan || $plan['status'] !== 'active') {
                throw new \RuntimeException('套餐不存在或已下架。');
            }
            $merchant = Db::name('merchants')->where('id', $merchantId)->lock(true)->find();
            if (!$merchant || $merchant['status'] !== 'active') {
                throw new \RuntimeException('商户账户当前不可用。');
            }
            $now = Web::now();
            $active = Db::name('merchant_subscriptions')
                ->where('merchant_id', $merchantId)
                ->where('status', 'active')
                ->where('expires_at', '>', $now)
                ->lock(true)
                ->find();
            $quote = $this->purchaseQuote($plan, $active ?: null, $now);
            if (!$quote['canPurchase']) {
                throw new \RuntimeException((string) $quote['disabledReason']);
            }

            $price = (int) $quote['payableCredits'];
            $available = $this->availableCredits($merchantId, (int) $merchant['credit_balance']);
            if ($available < $price) {
                throw new \RuntimeException("可用积分不足，当前可用 {$available} 点，购买该套餐需要 {$price} 点。");
            }
            $balance = (int) $merchant['credit_balance'] - $price;
            $durationDays = max(1, (int) $plan['duration_days']);
            $expiresAt = date('Y-m-d H:i:s', strtotime($now . " +{$durationDays} days"));
            if ($active) {
                Db::name('merchant_subscriptions')->where('id', $active['id'])->update(['status' => 'upgraded']);
            }
            $subscriptionId = (int) Db::name('merchant_subscriptions')->insertGetId([
                'merchant_id' => $merchantId,
                'plan_id' => $plan['id'],
                'plan_code' => $plan['code'],
                'plan_name' => $plan['name'],
                'price_credits' => max(0, (int) $plan['price_credits']),
                'tier_level' => max(1, (int) ($plan['tier_level'] ?? 1)),
                'audience_label' => ($plan['audience_label'] ?? null) ?: null,
                'starts_at' => $now,
                'expires_at' => $expiresAt,
                'status' => 'active',
                'generation_unlimited' => (int) $plan['generation_unlimited'],
                'recognition_unlimited' => (int) $plan['recognition_unlimited'],
                'daily_generation_limit' => (int) $plan['daily_generation_limit'],
                'sku_limit' => max(0, (int) ($plan['sku_limit'] ?? 0)),
                'batch_image_limit' => max(0, (int) ($plan['batch_image_limit'] ?? 0)),
                'concurrent_job_limit' => max(0, (int) ($plan['concurrent_job_limit'] ?? 0)),
                'purchased_at' => $now,
                'created_at' => $now,
            ]);
            Db::name('merchants')->where('id', $merchantId)->update([
                'plan_code' => $plan['code'],
                'credit_balance' => $balance,
                'updated_at' => $now,
            ]);
            if ($price > 0) {
                Db::name('credit_ledger')->insert([
                    'merchant_id' => $merchantId,
                    'type' => 'plan_purchase',
                    'amount' => -$price,
                    'balance_after' => $balance,
                    'source' => 'plan_purchase',
                    'reference_type' => 'merchant_subscription',
                    'reference_id' => (string) $subscriptionId,
                    'note' => ($active ? '升级套餐：' : '购买套餐：') . $plan['name']
                        . ((int) $quote['upgradeCredit'] > 0 ? '（原套餐抵扣 ' . (int) $quote['upgradeCredit'] . ' 积分）' : ''),
                    'created_at' => $now,
                ]);
            }

            return [
                'id' => $subscriptionId,
                'planName' => (string) $plan['name'],
                'priceCredits' => $price,
                'listPriceCredits' => max(0, (int) $plan['price_credits']),
                'upgradeCredit' => (int) $quote['upgradeCredit'],
                'purchaseMode' => (string) $quote['purchaseMode'],
                'balance' => $balance,
                'expiresAt' => $expiresAt,
                'userId' => $userId,
            ];
        });
    }

    public function assignByAdmin(int $planId, int $merchantId, int $adminId, int $durationDays): array
    {
        return Db::transaction(function () use ($planId, $merchantId, $adminId, $durationDays): array {
            $plan = Db::name('subscription_plans')->where('id', $planId)->lock(true)->find();
            if (!$plan) {
                throw new \RuntimeException('所选套餐不存在。');
            }
            $merchant = Db::name('merchants')->where('id', $merchantId)->lock(true)->find();
            if (!$merchant) {
                throw new \RuntimeException('商户不存在。');
            }
            $durationDays = $durationDays > 0 ? $durationDays : (int) $plan['duration_days'];
            if ($durationDays < 1 || $durationDays > 3650) {
                throw new \RuntimeException('发放有效期应为 1 到 3650 天。');
            }
            $now = Web::now();
            Db::name('merchant_subscriptions')
                ->where('merchant_id', $merchantId)
                ->where('status', 'active')
                ->where('expires_at', '>', $now)
                ->update(['status' => 'replaced']);
            $expiresAt = date('Y-m-d H:i:s', strtotime($now . " +{$durationDays} days"));
            $subscriptionId = (int) Db::name('merchant_subscriptions')->insertGetId([
                'merchant_id' => $merchantId,
                'plan_id' => $plan['id'],
                'plan_code' => $plan['code'],
                'plan_name' => $plan['name'],
                'price_credits' => 0,
                'tier_level' => max(1, (int) ($plan['tier_level'] ?? 1)),
                'audience_label' => ($plan['audience_label'] ?? null) ?: null,
                'starts_at' => $now,
                'expires_at' => $expiresAt,
                'status' => 'active',
                'generation_unlimited' => (int) $plan['generation_unlimited'],
                'recognition_unlimited' => (int) $plan['recognition_unlimited'],
                'daily_generation_limit' => (int) $plan['daily_generation_limit'],
                'sku_limit' => max(0, (int) ($plan['sku_limit'] ?? 0)),
                'batch_image_limit' => max(0, (int) ($plan['batch_image_limit'] ?? 0)),
                'concurrent_job_limit' => max(0, (int) ($plan['concurrent_job_limit'] ?? 0)),
                'purchased_at' => $now,
                'created_at' => $now,
            ]);
            Db::name('merchants')->where('id', $merchantId)->update([
                'plan_code' => $plan['code'],
                'updated_at' => $now,
            ]);

            return [
                'id' => $subscriptionId,
                'planName' => (string) $plan['name'],
                'expiresAt' => $expiresAt,
                'durationDays' => $durationDays,
                'adminId' => $adminId,
            ];
        });
    }

    public function cancelByAdmin(int $merchantId): ?array
    {
        return Db::transaction(function () use ($merchantId): ?array {
            $merchant = Db::name('merchants')->where('id', $merchantId)->lock(true)->find();
            if (!$merchant) {
                throw new \RuntimeException('商户不存在。');
            }
            $now = Web::now();
            $subscription = Db::name('merchant_subscriptions')
                ->where('merchant_id', $merchantId)
                ->where('status', 'active')
                ->where('expires_at', '>', $now)
                ->order('id', 'desc')
                ->lock(true)
                ->find();
            if (!$subscription) {
                return null;
            }
            Db::name('merchant_subscriptions')
                ->where('merchant_id', $merchantId)
                ->where('status', 'active')
                ->where('expires_at', '>', $now)
                ->update(['status' => 'cancelled']);
            Db::name('merchants')->where('id', $merchantId)->update(['plan_code' => 'trial', 'updated_at' => $now]);

            return $subscription;
        });
    }

    public function generationUsageToday(int $merchantId): int
    {
        $start = date('Y-m-d 00:00:00');
        $completedAssets = (int) Db::name('assets')
            ->where('merchant_id', $merchantId)
            ->where('created_at', '>=', $start)
            ->count();
        $pendingJobs = Db::name('generation_jobs')
            ->where('merchant_id', $merchantId)
            ->whereIn('status', ['pending_gateway', 'queued', 'running', 'manual_review'])
            ->where('created_at', '>=', $start)
            ->field('asset_types')
            ->select()
            ->toArray();
        foreach ($pendingJobs as $job) {
            $completedAssets += count(decode_json_list($job['asset_types'] ?? '[]'));
        }

        return $completedAssets;
    }

    private function availableCredits(int $merchantId, int $balance): int
    {
        $committed = (int) Db::name('generation_jobs')
            ->where('merchant_id', $merchantId)
            ->whereIn('status', ['queued', 'pending_gateway'])
            ->sum('estimated_credits');

        return max(0, $balance - $committed);
    }

    private function purchaseQuote(array $plan, ?array $active, ?string $now = null): array
    {
        $listPrice = max(0, (int) ($plan['price_credits'] ?? 0));
        if (!$active) {
            return [
                'canPurchase' => true,
                'purchaseMode' => 'purchase',
                'upgradeCredit' => 0,
                'payableCredits' => $listPrice,
                'disabledReason' => '',
            ];
        }

        $currentTier = max(1, (int) ($active['tier_level'] ?? 1));
        $targetTier = max(1, (int) ($plan['tier_level'] ?? 1));
        if ($targetTier <= $currentTier) {
            return [
                'canPurchase' => false,
                'purchaseMode' => (int) ($active['plan_id'] ?? 0) === (int) ($plan['id'] ?? 0) ? 'current' : ($targetTier === $currentTier ? 'same_level' : 'lower'),
                'upgradeCredit' => 0,
                'payableCredits' => $listPrice,
                'disabledReason' => $targetTier === $currentTier ? '当前已是同等级套餐。' : '生效期间只能升级到更高等级套餐。',
            ];
        }

        $nowTimestamp = strtotime($now ?? Web::now()) ?: time();
        $startsAt = strtotime((string) ($active['starts_at'] ?? '')) ?: $nowTimestamp;
        $expiresAt = strtotime((string) ($active['expires_at'] ?? '')) ?: $nowTimestamp;
        $totalSeconds = max(1, $expiresAt - $startsAt);
        $remainingSeconds = max(0, $expiresAt - $nowTimestamp);
        $paidValue = max(0, (int) ($active['price_credits'] ?? 0));
        $upgradeCredit = min($listPrice, (int) floor($paidValue * $remainingSeconds / $totalSeconds));

        return [
            'canPurchase' => true,
            'purchaseMode' => 'upgrade',
            'upgradeCredit' => $upgradeCredit,
            'payableCredits' => max(0, $listPrice - $upgradeCredit),
            'disabledReason' => '',
        ];
    }
}
