<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\AssetStorage;
use app\service\SystemSettings;
use app\service\StorageMigration;
use app\support\Audit;
use app\support\Web;
use think\Response;
use think\facade\Db;
use think\facade\Session;

final class AdminStorage extends BaseController
{
    public function index(AssetStorage $storage, StorageMigration $migration): Response
    {
        $admin = $this->admin();
        $configuration = $storage->configuration();
        $migrationStatus = $migration->status();
        return $this->render('admin/storage', compact('admin', 'configuration', 'migrationStatus') + ['pageTitle' => '素材存储']);
    }

    public function save(SystemSettings $settings, AssetStorage $storage): Response
    {
        $admin = $this->admin();
        $driver = (string) $this->request->post('driver', 'local');
        $endpoint = rtrim(trim((string) $this->request->post('endpoint', '')), '/');
        $region = trim((string) $this->request->post('region', ''));
        $bucket = trim((string) $this->request->post('bucket', ''));
        $accessKey = trim((string) $this->request->post('access_key', ''));
        $secretKey = trim((string) $this->request->post('secret_key', ''));
        $publicUrl = rtrim(trim((string) $this->request->post('public_url', '')), '/');
        $pathPrefix = trim((string) $this->request->post('path_prefix', 'generated'), '/');
        $localBackupEnabled = $this->request->post('local_backup_enabled', '') === '1';

        if (!in_array($driver, ['local', 'cos', 'oss', 'r2'], true)) {
            return Web::redirect('/admin/storage', '存储类型不正确。', 'error');
        }
        if ($pathPrefix === '' || mb_strlen($pathPrefix) > 120 || !preg_match('/^[A-Za-z0-9_\/-]+$/', $pathPrefix)) {
            return Web::redirect('/admin/storage', '存储目录只能包含字母、数字、斜杠、横线和下划线。', 'error');
        }
        if ($driver !== 'local') {
            if ($bucket === '' || mb_strlen($bucket) > 190 || !preg_match('/^[A-Za-z0-9._-]+$/', $bucket)) {
                return Web::redirect('/admin/storage', 'Bucket 名称格式不正确。', 'error');
            }
            if ($region === '' || mb_strlen($region) > 80 || !preg_match('/^[A-Za-z0-9-]+$/', $region)) {
                return Web::redirect('/admin/storage', '地域标识格式不正确。', 'error');
            }
            if (($driver === 'r2' && !$this->isHttpUrl($endpoint)) || ($endpoint !== '' && !$this->isHttpUrl($endpoint))) {
                return Web::redirect('/admin/storage', '请填写有效的服务端点地址。', 'error');
            }
            if (!$this->isHttpUrl($publicUrl)) {
                return Web::redirect('/admin/storage', '远程存储必须配置可公开访问的素材域名。', 'error');
            }
            $current = $storage->configuration();
            $providerChanged = $current['driver'] !== $driver;
            if (($accessKey === '' && (!$current['accessKeyConfigured'] || $providerChanged)) || ($secretKey === '' && (!$current['secretKeyConfigured'] || $providerChanged))) {
                return Web::redirect('/admin/storage', '首次配置远程存储必须填写 AccessKey 和 SecretKey。', 'error');
            }
        }

        $values = [
            'storage_driver' => $driver,
            'storage_endpoint' => $endpoint,
            'storage_region' => $region,
            'storage_bucket' => $bucket,
            'storage_public_url' => $publicUrl,
            'storage_path_prefix' => $pathPrefix,
            'storage_local_backup_enabled' => $localBackupEnabled ? '1' : '0',
        ];
        if ($accessKey !== '') {
            $values['storage_access_key'] = $accessKey;
        }
        if ($secretKey !== '') {
            $values['storage_secret_key'] = $secretKey;
        }

        try {
            $changed = $settings->setMany($values, (int) $admin['id']);
        } catch (\Throwable $exception) {
            return Web::redirect('/admin/storage', $exception->getMessage(), 'error');
        }
        Audit::log('admin', (int) $admin['id'], 'storage.settings.update', 'system_settings', null, $this->request->ip(), [
            'driver' => $driver,
            'changed_keys' => $changed,
            'credentials_updated' => $accessKey !== '' || $secretKey !== '',
        ]);

        return Web::redirect('/admin/storage', '素材存储配置已保存。');
    }

    public function test(AssetStorage $storage): Response
    {
        $admin = $this->admin();
        try {
            $message = $storage->testConnection();
        } catch (\Throwable $exception) {
            return Web::redirect('/admin/storage', $exception->getMessage(), 'error');
        }
        Audit::log('admin', (int) $admin['id'], 'storage.connection.test', 'system_settings', null, $this->request->ip(), ['driver' => $storage->configuration()['driver']]);
        return Web::redirect('/admin/storage', $message);
    }

    public function scan(StorageMigration $migration): Response
    {
        $admin = $this->admin();
        $count = $migration->scan();
        Audit::log('admin', (int) $admin['id'], 'storage.migration.scan', 'storage_migration', null, $this->request->ip(), ['queued' => $count]);
        return Web::redirect('/admin/storage', '扫描完成，新增或重置 ' . $count . ' 条迁移任务。');
    }

    public function migrate(StorageMigration $migration): Response
    {
        $admin = $this->admin();
        try {
            $result = $migration->processBatch(30);
        } catch (\Throwable $exception) {
            return Web::redirect('/admin/storage', $exception->getMessage(), 'error');
        }
        Audit::log('admin', (int) $admin['id'], 'storage.migration.process', 'storage_migration', null, $this->request->ip(), $result);
        return Web::redirect('/admin/storage', "本批处理 {$result['processed']} 条，成功 {$result['completed']} 条，失败 {$result['failed']} 条。");
    }

    private function admin(): array
    {
        return Db::name('admin_users')->where('id', (int) Session::get('admin_user_id'))->find();
    }

    private function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
