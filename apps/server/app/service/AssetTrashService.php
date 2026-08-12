<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use think\App;
use think\facade\Db;

final class AssetTrashService
{
    private const RETENTION_DAYS = 7;
    private const CLEANUP_INTERVAL = 3600;

    public function __construct(
        private readonly AssetStorage $storage,
        private readonly App $app,
    ) {
    }

    public function purgeForMerchant(int $merchantId, array $assetIds): array
    {
        return $this->purge($assetIds, $merchantId);
    }

    public function purgeExpired(int $limit = 50): array
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::RETENTION_DAYS * 86400);
        $ids = Db::name('assets')->where('status', 'deleted')->whereNotNull('deleted_at')
            ->where('deleted_at', '<=', $cutoff)->order('deleted_at', 'asc')->order('id', 'asc')
            ->limit(max(1, min(200, $limit)))->column('id');

        return $this->purge(array_map('intval', $ids), null);
    }

    public function runScheduled(int $limit = 50): ?array
    {
        $path = rtrim($this->app->getRuntimePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'asset-trash-cleanup.lock';
        $handle = fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            return null;
        }
        try {
            $lastRun = (int) trim((string) stream_get_contents($handle));
            if ($lastRun > 0 && time() - $lastRun < self::CLEANUP_INTERVAL) {
                return null;
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) time());
            fflush($handle);

            return $this->purgeExpired($limit);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function purgeDeadline(string $deletedAt): string
    {
        $timestamp = strtotime($deletedAt) ?: time();
        return date('Y-m-d H:i:s', $timestamp + self::RETENTION_DAYS * 86400);
    }

    private function purge(array $assetIds, ?int $merchantId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $assetIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return ['purged' => 0, 'failed' => 0, 'warnings' => []];
        }
        $query = Db::name('assets')->whereIn('id', array_slice($ids, 0, 200))->where('status', 'deleted');
        if ($merchantId !== null) {
            $query->where('merchant_id', $merchantId);
        }
        $assets = $query->select()->toArray();
        if ($assets === []) {
            return ['purged' => 0, 'failed' => 0, 'warnings' => []];
        }

        $selectedIds = array_map('intval', array_column($assets, 'id'));
        $urlAssetIds = [];
        foreach ($assets as $asset) {
            foreach (array_unique([(string) $asset['file_url'], (string) ($asset['backup_file_url'] ?? '')]) as $url) {
                if ($url !== '') {
                    $urlAssetIds[$url][] = (int) $asset['id'];
                }
            }
        }

        $failedIds = [];
        $warnings = [];
        foreach ($urlAssetIds as $url => $affectedIds) {
            $shared = Db::name('assets')->whereNotIn('id', $selectedIds)->where(function ($query) use ($url): void {
                $query->where('file_url', $url)->whereOr('backup_file_url', $url);
            })->count() > 0;
            if ($shared) {
                continue;
            }
            try {
                $result = $this->storage->deleteStoredUrl($url);
                if (!empty($result['warning'])) {
                    $warnings[] = (string) $result['warning'];
                }
            } catch (\Throwable $exception) {
                foreach ($affectedIds as $id) {
                    $failedIds[$id] = true;
                }
                $warnings[] = $exception->getMessage();
            }
        }

        $purgeIds = array_values(array_filter($selectedIds, static fn (int $id): bool => !isset($failedIds[$id])));
        if ($purgeIds !== []) {
            Db::name('assets')->whereIn('id', $purgeIds)->where('status', 'deleted')->update([
                'status' => 'purged',
                'file_url' => '',
                'backup_file_url' => null,
                'purged_at' => Web::now(),
                'updated_at' => Web::now(),
            ]);
        }

        return [
            'purged' => count($purgeIds),
            'failed' => count($selectedIds) - count($purgeIds),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }
}
