<?php

declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/** Minimal input check for the direct Sub2API creation path. */
final class ProductCreativeReadinessService
{
    public const READY = 'ready';
    public const NEEDS_INPUT = 'needs_input';
    public const FAILED = 'failed';

    public function forSku(int $skuId, int $merchantId, ?int $projectId = null): array
    {
        $sku = $this->sku($skuId, $merchantId);
        if (!$sku) {
            return $this->result(false, self::FAILED, $skuId, 0,
                [$this->missing('sku', '商品档案')], '/workspace/generate');
        }

        $query = Db::name('detail_page_projects')->where('merchant_id', $merchantId)
            ->where('sku_id', $skuId)->where('status', '<>', 'deleted');
        if ($projectId !== null && $projectId > 0) {
            $query->where('id', $projectId);
        }
        $project = $query->order('updated_at', 'desc')->order('id', 'desc')->find();
        $missing = $this->essentialSkuMissing($sku, true);
        if (!$project) {
            $missing[] = $this->missing('detail_project', '详情页方案');
        } else {
            $this->requireText($missing, 'platform', '销售平台', $project['platform'] ?? '');
            $this->requireText($missing, 'language', '画面语言', $project['language'] ?? '');
            $this->requireList($missing, 'screen_plan', '详情页策划', $project['screen_plan'] ?? []);
        }
        $missing = $this->uniqueIssues($missing);
        $ready = $missing === [];
        $snapshot = [
            'contract' => 'direct_product_readiness_v1',
            'sku_id' => $skuId,
            'project_id' => (int) ($project['id'] ?? 0),
            'product_name' => trim((string) ($sku['name'] ?? '')),
            'category' => trim((string) ($sku['category'] ?? '')),
            'reference_image' => $this->referenceImage($sku),
            'platform' => (string) ($project['platform'] ?? ''),
            'language' => (string) ($project['language'] ?? ''),
            'screen_plan' => $this->list($project['screen_plan'] ?? []),
            'missing' => $missing,
        ];
        return $this->result($ready, $ready ? self::READY : self::NEEDS_INPUT, $skuId,
            (int) ($project['id'] ?? 0), $missing,
            $ready ? '' : '/workspace/skus/' . $skuId . '?section=foundation', $snapshot);
    }

    /** Product images need only an identifiable product and its reference image. */
    public function forVisualSku(int $skuId, int $merchantId, string $unusedMode = 'marketing_creative'): array
    {
        unset($unusedMode);
        $sku = $this->sku($skuId, $merchantId);
        if (!$sku) {
            return $this->result(false, self::FAILED, $skuId, 0,
                [$this->missing('sku', '商品档案')], '/workspace/generate');
        }
        $missing = $this->essentialSkuMissing($sku, true);
        $ready = $missing === [];
        return $this->result($ready, $ready ? self::READY : self::NEEDS_INPUT, $skuId, 0, $missing,
            $ready ? '' : '/workspace/skus/' . $skuId . '?section=foundation', [
                'contract' => 'direct_product_image_readiness_v1',
                'sku_id' => $skuId,
                'product_name' => trim((string) ($sku['name'] ?? '')),
                'reference_image' => $this->referenceImage($sku),
                'missing' => $missing,
            ]);
    }

    public function forProject(int $projectId, int $merchantId): array
    {
        $project = Db::name('detail_page_projects')->where('id', $projectId)
            ->where('merchant_id', $merchantId)->where('status', '<>', 'deleted')->find();
        if (!$project || (int) ($project['sku_id'] ?? 0) <= 0) {
            return $this->result(false, self::FAILED, 0, $projectId,
                [$this->missing('sku', '关联商品档案')], '/workspace/detail-pages/' . $projectId);
        }
        return $this->forSku((int) $project['sku_id'], $merchantId, $projectId);
    }

    public function responsePayload(array $readiness): array
    {
        return GenerationOperationResult::failure(
            'product_not_ready', 'direct_product_readiness',
            '商品基础资料尚未就绪，本次未创建任务、未预扣点数，也未调用模型服务。',
            false, false, 'not_applicable', true, null, null, [
                'state' => (string) ($readiness['state'] ?? self::NEEDS_INPUT),
                'missing' => (array) ($readiness['missing'] ?? []),
                'blockers' => [], 'recovery' => (array) ($readiness['recovery'] ?? []),
                'readinessSnapshotHash' => (string) ($readiness['snapshotHash'] ?? ''),
            ]
        );
    }

    public function humanMessage(array $readiness): string
    {
        $labels = [];
        foreach ((array) ($readiness['missing'] ?? []) as $issue) {
            $label = trim(is_array($issue) ? (string) ($issue['label'] ?? '') : (string) $issue);
            if ($label !== '') { $labels[$label] = true; }
        }
        return '商品基础资料尚未就绪' . ($labels === [] ? '' : '：' . implode('、', array_keys($labels)))
            . '。本次未创建任务、未预扣点数，也未调用模型。';
    }

    public function recoveryUrl(array $readiness, string $fallback = '/workspace/generate'): string
    {
        $url = trim((string) (($readiness['recovery']['url'] ?? '')));
        $parts = $url === '' ? false : parse_url($url);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])
            || !str_starts_with((string) ($parts['path'] ?? ''), '/workspace/')) {
            return $fallback;
        }
        return $url;
    }

    private function sku(int $skuId, int $merchantId): ?array
    {
        $row = $skuId > 0 ? Db::name('skus')->where('id', $skuId)
            ->where('merchant_id', $merchantId)->where('status', '<>', 'deleted')->find() : null;
        return is_array($row) ? $row : null;
    }

    private function essentialSkuMissing(array $sku, bool $requireCategory): array
    {
        $missing = [];
        $this->requireText($missing, 'product_name', '商品名称', $sku['name'] ?? '');
        if ($requireCategory) {
            $this->requireText($missing, 'category', '商品类目', $sku['category'] ?? '');
        }
        if ($this->referenceImage($sku) === '') {
            $missing[] = $this->missing('reference_image', '商品参考图');
        }
        return $missing;
    }

    private function referenceImage(array $sku): string
    {
        return trim((string) ($sku['source_image_path'] ?? $sku['source_image_backup_path'] ?? ''));
    }

    private function requireText(array &$missing, string $code, string $label, mixed $value): void
    {
        if (trim((string) $value) === '') { $missing[] = $this->missing($code, $label); }
    }

    private function requireList(array &$missing, string $code, string $label, mixed $value): void
    {
        if ($this->list($value) === []) { $missing[] = $this->missing($code, $label); }
    }

    private function list(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? array_values(array_filter($value, static fn (mixed $item): bool => $item !== '' && $item !== null)) : [];
    }

    private function missing(string $code, string $label): array
    {
        return ['code' => $code, 'label' => $label];
    }

    private function uniqueIssues(array $issues): array
    {
        $unique = [];
        foreach ($issues as $issue) { $unique[(string) ($issue['code'] ?? $issue['label'] ?? '')] = $issue; }
        return array_values($unique);
    }

    private function result(bool $ready, string $state, int $skuId, int $projectId, array $missing,
        string $recoveryUrl, array $snapshot = []): array
    {
        $snapshot += ['sku_id' => $skuId, 'project_id' => $projectId, 'state' => $state, 'missing' => $missing];
        return [
            'ready' => $ready, 'state' => $state, 'skuId' => $skuId, 'projectId' => $projectId,
            'missing' => $missing, 'blockers' => [],
            'recovery' => ['url' => $recoveryUrl, 'action' => 'complete_product_inputs'],
            'snapshot' => $snapshot,
            'snapshotHash' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }
}
