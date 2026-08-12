<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;
use app\support\Web;
use think\facade\Db;

final class StorageMigration
{
    public function __construct(private readonly AssetStorage $storage)
    {
    }

    public function status(): array
    {
        return [
            'localAssets' => Db::name('assets')->whereLike('file_url', '/storage/%')->where('status', '<>', 'deleted')->count(),
            'localSkus' => Db::name('skus')->whereLike('source_image_path', '/storage/%')->where('status', '<>', 'deleted')->count(),
            'localDetailProjects' => Db::name('detail_page_projects')->whereLike('source_image_url', '/storage/%')->count(),
            'pending' => Db::name('storage_migrations')->whereIn('status', ['pending', 'processing', 'failed'])->count(),
            'completed' => Db::name('storage_migrations')->where('status', 'completed')->count(),
            'failed' => Db::name('storage_migrations')->where('status', 'failed')->count(),
            'recent' => Db::name('storage_migrations')->order('id', 'desc')->limit(30)->select()->toArray(),
        ];
    }

    public function scan(): int
    {
        $count = 0;
        $assets = Db::name('assets')->whereLike('file_url', '/storage/%')->where('status', '<>', 'deleted')->field('id,merchant_id,file_url')->select()->toArray();
        foreach ($assets as $asset) {
            $count += $this->queue('asset', (int) $asset['id'], (int) $asset['merchant_id'], (string) $asset['file_url']);
        }
        $skus = Db::name('skus')->whereLike('source_image_path', '/storage/%')->where('status', '<>', 'deleted')->field('id,merchant_id,source_image_path')->select()->toArray();
        foreach ($skus as $sku) {
            $count += $this->queue('sku', (int) $sku['id'], (int) $sku['merchant_id'], (string) $sku['source_image_path']);
        }
        $projects = Db::name('detail_page_projects')->whereLike('source_image_url', '/storage/%')->field('id,merchant_id,source_image_url')->select()->toArray();
        foreach ($projects as $project) {
            $count += $this->queue('detail_project', (int) $project['id'], (int) $project['merchant_id'], (string) $project['source_image_url']);
        }

        return $count;
    }

    public function processBatch(int $limit = 10): array
    {
        $configuration = $this->storage->configuration();
        if (!$configuration['remote'] || !$configuration['configured']) {
            throw new RuntimeException('请先配置、测试并启用远程对象存储');
        }

        $rows = Db::name('storage_migrations')
            ->whereIn('status', ['pending', 'failed'])
            ->where('attempts', '<', 5)
            ->order('id', 'asc')->limit(max(1, min($limit, 30)))->select()->toArray();
        $completed = 0;
        $failed = 0;
        foreach ($rows as $row) {
            Db::name('storage_migrations')->where('id', $row['id'])->update([
                'status' => 'processing',
                'attempts' => (int) $row['attempts'] + 1,
                'error_message' => null,
                'updated_at' => Web::now(),
            ]);
            try {
                $targetUrl = $this->storage->migrateLocalUrl((string) $row['source_url']);
                Db::transaction(function () use ($row, $targetUrl): void {
                    if ($row['source_type'] === 'asset') {
                        $updated = Db::name('assets')->where('id', $row['source_id'])->where('file_url', $row['source_url'])->update([
                            'file_url' => $targetUrl,
                            'backup_file_url' => $row['source_url'],
                            'updated_at' => Web::now(),
                        ]);
                    } elseif ($row['source_type'] === 'sku') {
                        $updated = Db::name('skus')->where('id', $row['source_id'])->where('source_image_path', $row['source_url'])->update([
                            'source_image_path' => $targetUrl,
                            'source_image_backup_path' => $row['source_url'],
                            'updated_at' => Web::now(),
                        ]);
                    } else {
                        $updated = Db::name('detail_page_projects')->where('id', $row['source_id'])->where('source_image_url', $row['source_url'])->update([
                            'source_image_url' => $targetUrl,
                            'source_image_backup_url' => $row['source_url'],
                            'updated_at' => Web::now(),
                        ]);
                    }
                    if (!$updated) {
                        throw new RuntimeException('业务记录已变化，请重新扫描');
                    }
                    Db::name('storage_migrations')->where('id', $row['id'])->update([
                        'target_url' => $targetUrl,
                        'status' => 'completed',
                        'error_message' => null,
                        'completed_at' => Web::now(),
                        'updated_at' => Web::now(),
                    ]);
                });
                $completed++;
            } catch (\Throwable $exception) {
                Db::name('storage_migrations')->where('id', $row['id'])->update([
                    'status' => 'failed',
                    'error_message' => mb_strimwidth($exception->getMessage(), 0, 500, '...'),
                    'updated_at' => Web::now(),
                ]);
                $failed++;
            }
        }

        return ['processed' => count($rows), 'completed' => $completed, 'failed' => $failed];
    }

    private function queue(string $type, int $sourceId, int $merchantId, string $sourceUrl): int
    {
        $existing = Db::name('storage_migrations')->where('source_type', $type)->where('source_id', $sourceId)->find();
        $now = Web::now();
        if ($existing) {
            if ($existing['source_url'] === $sourceUrl && $existing['status'] !== 'completed') {
                return 0;
            }
            Db::name('storage_migrations')->where('id', $existing['id'])->update([
                'source_url' => $sourceUrl,
                'target_url' => null,
                'status' => 'pending',
                'attempts' => 0,
                'error_message' => null,
                'completed_at' => null,
                'updated_at' => $now,
            ]);
            return 1;
        }

        Db::execute(
            'INSERT INTO storage_migrations (source_type,source_id,merchant_id,source_url,target_url,status,attempts,error_message,completed_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$type, $sourceId, $merchantId, $sourceUrl, null, 'pending', 0, null, null, $now, $now]
        );
        return 1;
    }
}
