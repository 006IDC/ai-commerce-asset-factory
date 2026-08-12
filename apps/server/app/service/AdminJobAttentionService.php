<?php

declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Single source of truth for admin job badges and their destination lists.
 */
final class AdminJobAttentionService
{
    public const ATTENTION_STATUSES = ['failed', 'partial', 'result_missing', 'manual_review'];
    public const QUEUE_STATUSES = ['queued', 'pending_gateway'];

    public function attentionCount(string $period = '24h'): int
    {
        $query = Db::name('generation_jobs')->alias('j');
        $this->applyAttention($query, $period);
        return (int) $query->count();
    }

    public function queueCount(): int
    {
        return (int) Db::name('generation_jobs')
            ->whereIn('status', self::QUEUE_STATUSES)
            ->count();
    }

    public function applyAttention(mixed $query, string $period = '24h'): void
    {
        $activeResultCount = "(SELECT COUNT(*) FROM assets attention_assets WHERE attention_assets.job_id = j.id AND attention_assets.status = 'active' AND attention_assets.file_url <> '')";
        $expectedResultCount = "(CASE WHEN j.output_kind = 'video' THEN 1 WHEN j.progress_total > 0 THEN j.progress_total ELSE 1 END)";
        $query->where(static function ($nested) use ($activeResultCount, $expectedResultCount): void {
            $nested->whereIn('j.status', self::ATTENTION_STATUSES)
                ->whereOr(static function ($missing) use ($activeResultCount, $expectedResultCount): void {
                    $missing->whereIn('j.status', ['completed', 'partial'])
                        ->whereRaw($activeResultCount . ' < ' . $expectedResultCount);
                });
        });
        $this->applyPeriod($query, $period, 'j.updated_at');
    }

    public function applyQueue(mixed $query): void
    {
        $query->whereIn('j.status', self::QUEUE_STATUSES);
    }

    private function applyPeriod(mixed $query, string $period, string $column): void
    {
        $hours = ['24h' => 24, '7d' => 168, '30d' => 720][$period] ?? 0;
        if ($hours > 0) {
            $query->where($column, '>=', date('Y-m-d H:i:s', time() - $hours * 3600));
        }
    }
}
