<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use think\facade\Db;

final class MerchantWorkspaceService
{
    public function sidebarProjects(int $merchantId, int $limit = 24): array
    {
        if ($merchantId <= 0) {
            return [];
        }

        $preferences = [];
        try {
            foreach (Db::name('workspace_project_preferences')->where('merchant_id', $merchantId)->select()->toArray() as $row) {
                $preferences[$this->key((string) $row['project_type'], (int) $row['project_id'])] = $row;
            }
        } catch (\Throwable) {
            // The shell must still render before an upgrade creates the preference table.
        }

        $latestSkuJobs = [];
        $skuJobs = Db::name('generation_jobs')
            ->field('id,sku_id,status,asset_types,bundle_no,bundle_part,progress_current,progress_total,updated_at,created_at')
            ->where('merchant_id', $merchantId)
            ->where('sku_id', '>', 0)
            ->whereNull('merchant_hidden_at')
            ->order('id', 'desc')
            ->limit(120)
            ->select()
            ->toArray();
        foreach ($skuJobs as $job) {
            $skuId = (int) $job['sku_id'];
            if ($skuId > 0 && !isset($latestSkuJobs[$skuId])) {
                $latestSkuJobs[$skuId] = $job;
            }
        }

        $projects = [];
        $skus = Db::name('skus')
            ->field('id,name,sku_code,category,source_image_path,source_image_backup_path,report_status,updated_at,created_at')
            ->where('merchant_id', $merchantId)
            ->where('status', 'active')
            ->order('updated_at', 'desc')
            ->order('id', 'desc')
            ->limit(36)
            ->select()
            ->toArray();
        foreach ($skus as $sku) {
            $id = (int) $sku['id'];
            $job = $latestSkuJobs[$id] ?? null;
            $project = [
                'type' => 'sku',
                'id' => $id,
                'key' => $this->key('sku', $id),
                'name' => trim((string) $sku['name']) ?: (string) $sku['sku_code'],
                'kind' => $job ? $this->jobKind($job) : '商品上新',
                'status' => $job ? (string) $job['status'] : ((string) ($sku['report_status'] ?? '') === 'ready' ? 'ready' : 'draft'),
                'statusLabel' => $job ? job_status_label((string) $job['status']) : ((string) ($sku['report_status'] ?? '') === 'ready' ? '商品资料已就绪' : '等待完善'),
                'thumbnail' => (string) ($sku['source_image_path'] ?? ''),
                'thumbnailFallback' => (string) ($sku['source_image_backup_path'] ?? ''),
                'url' => '/workspace/generate?sku_id=' . $id,
                'updatedAt' => (string) ($job['updated_at'] ?? $sku['updated_at'] ?? $sku['created_at']),
            ];
            $projects[] = $this->applyPreference($project, $preferences[$project['key']] ?? null);
        }

        $freeJobs = Db::name('generation_jobs')->alias('j')
            ->leftJoin('free_generation_jobs f', 'f.job_id=j.id')
            ->field('j.id,j.job_no,j.status,j.progress_current,j.progress_total,j.updated_at,j.created_at,f.prompt,f.reference_image_url,f.reference_image_backup_url')
            ->where('j.merchant_id', $merchantId)
            ->where('j.platform', 'free')
            ->whereNull('j.merchant_hidden_at')
            ->order('j.id', 'desc')
            ->limit(18)
            ->select()
            ->toArray();
        $freeJobIds = array_values(array_filter(array_map(static fn (array $row): int => (int) $row['id'], $freeJobs)));
        $freePreviews = [];
        if ($freeJobIds !== []) {
            foreach (Db::name('assets')->field('job_id,file_url,backup_file_url')->whereIn('job_id', $freeJobIds)
                ->where('status', 'active')->order('id', 'asc')->select()->toArray() as $asset) {
                $freePreviews[(int) $asset['job_id']] ??= $asset;
            }
        }
        foreach ($freeJobs as $job) {
            $id = (int) $job['id'];
            $preview = $freePreviews[$id] ?? null;
            $prompt = trim((string) ($job['prompt'] ?? ''));
            $project = [
                'type' => 'free',
                'id' => $id,
                'key' => $this->key('free', $id),
                'name' => $prompt !== '' ? mb_strimwidth($prompt, 0, 34, '...') : '自由创作 ' . (string) $job['job_no'],
                'kind' => '自由创作',
                'status' => (string) $job['status'],
                'statusLabel' => job_status_label((string) $job['status']),
                'thumbnail' => (string) ($preview['file_url'] ?? $job['reference_image_url'] ?? ''),
                'thumbnailFallback' => (string) ($preview['backup_file_url'] ?? $job['reference_image_backup_url'] ?? ''),
                'url' => '/workspace/free-create?project_id=' . $id,
                'updatedAt' => (string) ($job['updated_at'] ?? $job['created_at']),
            ];
            $projects[] = $this->applyPreference($project, $preferences[$project['key']] ?? null);
        }

        try {
            $canvasProjects = Db::name('creative_projects')->alias('p')
                ->leftJoin('assets a', 'a.id=p.cover_asset_id')
                ->field('p.id,p.title,p.project_type,p.updated_at,a.file_url,a.backup_file_url')
                ->where('p.merchant_id', $merchantId)
                ->where('p.status', 'active')
                ->order('p.updated_at', 'desc')
                ->order('p.id', 'desc')
                ->limit(24)
                ->select()
                ->toArray();
            foreach ($canvasProjects as $canvasProject) {
                $id = (int) $canvasProject['id'];
                $project = [
                    'type' => 'canvas',
                    'id' => $id,
                    'key' => $this->key('canvas', $id),
                    'name' => trim((string) $canvasProject['title']) ?: '未命名画布',
                    'kind' => (string) $canvasProject['project_type'] === 'video' ? '视频创作' : '创作画布',
                    'status' => 'ready',
                    'statusLabel' => '已自动保存',
                    'thumbnail' => (string) ($canvasProject['file_url'] ?? ''),
                    'thumbnailFallback' => (string) ($canvasProject['backup_file_url'] ?? ''),
                    'url' => '/workspace/canvas?project_id=' . $id,
                    'updatedAt' => (string) $canvasProject['updated_at'],
                ];
                $projects[] = $this->applyPreference($project, $preferences[$project['key']] ?? null);
            }
        } catch (\Throwable) {
            // Older installations continue to render until the upgrade creates creative projects.
        }

        $projects = array_values(array_filter($projects, static fn (array $project): bool => empty($project['hidden'])));
        usort($projects, static function (array $left, array $right): int {
            if ((int) $left['pinned'] !== (int) $right['pinned']) {
                return (int) $right['pinned'] <=> (int) $left['pinned'];
            }
            return strcmp((string) $right['updatedAt'], (string) $left['updatedAt']);
        });
        $projects = array_slice($projects, 0, max(1, $limit));
        foreach ($projects as &$project) {
            $project['timeGroup'] = $this->timeGroup((string) $project['updatedAt']);
        }
        unset($project);

        return $projects;
    }

    public function updatePreference(int $merchantId, string $type, int $id, string $action, string $name = ''): array
    {
        if (!$this->ownsProject($merchantId, $type, $id)) {
            throw new \RuntimeException('创作项目不存在或不属于当前商户。');
        }
        if (!in_array($action, ['rename', 'pin', 'unpin', 'hide'], true)) {
            throw new \RuntimeException('项目操作无效。');
        }
        $name = mb_strimwidth(trim($name), 0, 120, '');
        if ($action === 'rename' && mb_strlen($name) < 2) {
            throw new \RuntimeException('项目名称至少需要 2 个字。');
        }
        if ($action === 'rename' && $type === 'canvas') {
            Db::name('creative_projects')
                ->where('id', $id)
                ->where('merchant_id', $merchantId)
                ->where('status', '<>', 'deleted')
                ->update(['title' => $name, 'updated_at' => Web::now()]);
        }

        $now = Web::now();
        $query = Db::name('workspace_project_preferences')
            ->where('merchant_id', $merchantId)
            ->where('project_type', $type)
            ->where('project_id', $id);
        $existing = $query->find();
        $data = [
            'custom_name' => $action === 'rename' ? $name : ($existing['custom_name'] ?? null),
            'pinned' => $action === 'pin' ? 1 : ($action === 'unpin' ? 0 : (int) ($existing['pinned'] ?? 0)),
            'hidden' => $action === 'hide' ? 1 : (int) ($existing['hidden'] ?? 0),
            'updated_at' => $now,
        ];
        if ($existing) {
            $query->update($data);
        } else {
            Db::name('workspace_project_preferences')->insert($data + [
                'merchant_id' => $merchantId,
                'project_type' => $type,
                'project_id' => $id,
                'created_at' => $now,
            ]);
        }

        return ['type' => $type, 'id' => $id, 'action' => $action, 'name' => $name];
    }

    private function ownsProject(int $merchantId, string $type, int $id): bool
    {
        return match ($type) {
            'sku' => Db::name('skus')->where('id', $id)->where('merchant_id', $merchantId)->where('status', '<>', 'deleted')->count() > 0,
            'free' => Db::name('generation_jobs')->where('id', $id)->where('merchant_id', $merchantId)->where('platform', 'free')->count() > 0,
            'canvas' => Db::name('creative_projects')->where('id', $id)->where('merchant_id', $merchantId)->where('status', '<>', 'deleted')->count() > 0,
            default => false,
        };
    }

    private function applyPreference(array $project, ?array $preference): array
    {
        $project['pinned'] = (int) ($preference['pinned'] ?? 0);
        $project['hidden'] = (int) ($preference['hidden'] ?? 0);
        $customName = trim((string) ($preference['custom_name'] ?? ''));
        if ($customName !== '') {
            $project['name'] = $customName;
        }
        return $project;
    }

    private function jobKind(array $job): string
    {
        if (trim((string) ($job['bundle_no'] ?? '')) !== '') {
            return '完整商品套图';
        }
        $types = decode_json_list($job['asset_types'] ?? '[]');
        return in_array('detail_page', $types, true) || str_starts_with((string) ($types[0] ?? ''), 'detail_')
            ? '详情页创作'
            : '商品图片';
    }

    private function key(string $type, int $id): string
    {
        return $type . ':' . $id;
    }

    private function timeGroup(string $updatedAt): string
    {
        $timestamp = strtotime($updatedAt);
        if ($timestamp === false) {
            return '更早';
        }
        $today = strtotime(date('Y-m-d 00:00:00'));
        if ($timestamp >= $today) {
            return '今天';
        }
        if ($timestamp >= $today - 6 * 86400) {
            return '最近 7 天';
        }
        return '更早';
    }
}
