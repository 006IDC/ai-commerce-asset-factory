<?php

declare(strict_types=1);

namespace app\service;

use app\support\RemoteMediaUrlGuard;
use RuntimeException;

final class AssetStorage
{
    private const REMOTE_DRIVERS = ['cos', 'oss', 'r2'];

    public function __construct(private readonly SystemSettings $settings)
    {
    }

    public function configuration(): array
    {
        $driver = $this->settings->get('storage_driver');
        if (!in_array($driver, array_merge(['local'], self::REMOTE_DRIVERS), true)) {
            $driver = 'local';
        }

        $bucket = trim($this->settings->get('storage_bucket'));
        $region = trim($this->settings->get('storage_region'));
        $rawEndpoint = rtrim(trim($this->settings->get('storage_endpoint')), '/');
        $endpoint = $this->resolveEndpoint($driver, $rawEndpoint, $bucket, $region);
        $publicUrl = rtrim(trim($this->settings->get('storage_public_url')), '/');
        if ($publicUrl === '' && in_array($driver, ['cos', 'oss'], true)) {
            $publicUrl = $endpoint;
        }
        $accessKey = $this->settings->get('storage_access_key');
        $secretKey = $this->settings->get('storage_secret_key');

        return [
            'driver' => $driver,
            'driverLabel' => $this->driverLabel($driver),
            'remote' => in_array($driver, self::REMOTE_DRIVERS, true),
            'configured' => $driver === 'local' || ($endpoint !== '' && $bucket !== '' && $region !== '' && $accessKey !== '' && $secretKey !== '' && $publicUrl !== ''),
            'endpoint' => $rawEndpoint,
            'resolvedEndpoint' => $endpoint,
            'region' => $region,
            'bucket' => $bucket,
            'accessKeyConfigured' => $accessKey !== '',
            'secretKeyConfigured' => $secretKey !== '',
            'publicUrl' => $publicUrl,
            'pathPrefix' => trim($this->settings->get('storage_path_prefix'), '/'),
            'localBackupEnabled' => $this->settings->get('storage_local_backup_enabled') !== '0',
        ];
    }

    public function storeImage(string $content, int $merchantId, string $jobNo, string $assetType): string
    {
        return $this->storeImageRecord($content, $merchantId, $jobNo, $assetType)['url'];
    }

    public function storeImageRecord(string $content, int $merchantId, string $jobNo, string $assetType): array
    {
        if ($content === '') {
            throw new RuntimeException('待保存的图片内容为空');
        }
        $config = $this->configuration();
        if (!$config['configured']) {
            throw new RuntimeException('对象存储配置不完整，请联系管理员检查存储设置');
        }

        $filename = strtolower((string) preg_replace('/[^A-Za-z0-9-]/', '-', $jobNo))
            . '-' . strtolower((string) preg_replace('/[^A-Za-z0-9-]/', '-', $assetType))
            . '-' . bin2hex(random_bytes(3)) . '.png';
        $key = $this->objectKey($config['pathPrefix'], (string) $merchantId, date('Y/m'), $filename);

        return $this->storeRecord($config, $key, $content, 'image/png');
    }

    public function storeVideoRecord(string $content, string $mime, int $merchantId, string $jobNo, string $assetType): array
    {
        if ($content === '' || strlen($content) > 200 * 1024 * 1024) {
            throw new RuntimeException('待保存的视频为空或超过 200MB');
        }
        $extension = match (strtolower(trim($mime))) {
            'video/mp4', 'application/mp4' => 'mp4',
            'video/webm' => 'webm',
            default => throw new RuntimeException('生成视频格式无法识别，仅支持 MP4 和 WebM'),
        };
        $config = $this->configuration();
        if (!$config['configured']) {
            throw new RuntimeException('对象存储配置不完整，请联系管理员检查存储设置');
        }
        $filename = strtolower((string) preg_replace('/[^A-Za-z0-9-]/', '-', $jobNo))
            . '-' . strtolower((string) preg_replace('/[^A-Za-z0-9-]/', '-', $assetType))
            . '-' . bin2hex(random_bytes(3)) . '.' . $extension;
        $key = $this->objectKey($config['pathPrefix'], (string) $merchantId, date('Y/m'), $filename);

        return $this->storeRecord($config, $key, $content, $mime);
    }

    public function downloadVideo(string $url): array
    {
        $target = RemoteMediaUrlGuard::publicHttpsTarget($url);
        $content = '';
        $responseType = '';
        $curl = curl_init($target['url']);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE => $target['resolve'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$responseType): int {
                if (stripos($header, 'Content-Type:') === 0) {
                    $responseType = trim(explode(';', substr($header, 13), 2)[0]);
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$content): int {
                if (strlen($content) + strlen($chunk) > 200 * 1024 * 1024) {
                    return 0;
                }
                $content .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        if ($ok === false || $status < 200 || $status >= 300 || $content === '') {
            throw new RuntimeException('下载生成视频失败：' . ($error ?: 'HTTP ' . $status));
        }
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: '';
        $mime = in_array($detected, ['video/mp4', 'video/webm'], true) ? $detected : strtolower($responseType);
        if (!in_array($mime, ['video/mp4', 'application/mp4', 'video/webm'], true)) {
            throw new RuntimeException('上游返回的内容不是可识别的视频文件');
        }
        return ['content' => $content, 'mime' => $mime === 'application/mp4' ? 'video/mp4' : $mime];
    }

    public function storeSourceImage(string $content, string $mime, int $merchantId, string $skuCode): string
    {
        return $this->storeSourceImageRecord($content, $mime, $merchantId, $skuCode)['url'];
    }

    public function storeSourceImageRecord(string $content, string $mime, int $merchantId, string $skuCode): array
    {
        if ($content === '' || strlen($content) > 10 * 1024 * 1024) {
            throw new RuntimeException('商品原图为空或超过 10MB');
        }
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new RuntimeException('商品图仅支持 JPG、PNG 和 WebP'),
        };
        $config = $this->configuration();
        if (!$config['configured']) {
            throw new RuntimeException('素材存储配置不完整，请联系管理员检查存储设置');
        }
        $safeSku = strtolower((string) preg_replace('/[^A-Za-z0-9-]/', '-', $skuCode));
        $key = $this->objectKey($config['pathPrefix'], 'source', (string) $merchantId, $safeSku . '-' . bin2hex(random_bytes(4)) . '.' . $extension);

        return $this->storeRecord($config, $key, $content, $mime);
    }

    public function migrateLocalUrl(string $url): string
    {
        $config = $this->configuration();
        if (!$config['remote'] || !$config['configured']) {
            throw new RuntimeException('请先配置并启用远程对象存储');
        }
        if (!str_starts_with($url, '/storage/')) {
            throw new RuntimeException('迁移来源不是本地素材地址');
        }
        $key = rawurldecode(substr($url, strlen('/storage/')));
        if ($key === '' || str_contains($key, '..')) {
            throw new RuntimeException('本地素材路径无效');
        }
        $image = $this->readImage($url);
        $this->requestRemote('PUT', $config, $key, $image['content'], $image['mime']);
        $this->requestRemote('HEAD', $config, $key, '', $image['mime']);

        return $config['publicUrl'] . '/' . $this->encodePath($key);
    }

    public function readImageWithBackup(string $url, ?string $backupUrl = null): array
    {
        try {
            return $this->readImage($url);
        } catch (\Throwable $exception) {
            if ($backupUrl === null || $backupUrl === '' || $backupUrl === $url) {
                throw $exception;
            }
            return $this->readImage($backupUrl);
        }
    }

    public function readVideoWithBackup(string $url, ?string $backupUrl = null): array
    {
        try {
            return $this->readVideo($url);
        } catch (\Throwable $exception) {
            if ($backupUrl === null || $backupUrl === '' || $backupUrl === $url) {
                throw $exception;
            }
            return $this->readVideo($backupUrl);
        }
    }

    public function readVideo(string $url): array
    {
        if (str_starts_with($url, '/storage/')) {
            $relative = rawurldecode(substr($url, strlen('/storage/')));
            if ($relative === '' || str_contains($relative, '..')) {
                throw new RuntimeException('视频素材路径无效');
            }
            $path = $this->localPath($relative);
            $content = is_file($path) ? file_get_contents($path) : false;
            if ($content === false || $content === '') {
                throw new RuntimeException('视频素材文件不存在');
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: '';
        } else {
            $download = $this->downloadVideo($url);
            $content = $download['content'];
            $mime = $download['mime'];
        }
        $extension = match ($mime) {
            'video/mp4', 'application/mp4' => 'mp4',
            'video/webm' => 'webm',
            default => throw new RuntimeException('视频素材格式无法识别'),
        };

        return [
            'content' => $content,
            'mime' => $mime === 'application/mp4' ? 'video/mp4' : $mime,
            'filename' => 'video.' . $extension,
        ];
    }

    public function deleteStoredUrl(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['deleted' => false, 'warning' => null];
        }
        if (str_starts_with($url, '/storage/')) {
            $key = rawurldecode(substr($url, strlen('/storage/')));
            if ($key === '' || str_contains($key, '..') || str_starts_with($key, '/')) {
                throw new RuntimeException('本地素材路径无效，无法删除');
            }
            $path = $this->localPath($key);
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('本地素材文件删除失败');
            }
            return ['deleted' => true, 'warning' => null];
        }

        $config = $this->configuration();
        $publicPrefix = rtrim((string) $config['publicUrl'], '/');
        if (!$config['remote'] || !$config['configured'] || $publicPrefix === '' || !str_starts_with($url, $publicPrefix . '/')) {
            return ['deleted' => false, 'warning' => '历史对象地址与当前存储配置不匹配，已清除素材记录但无法自动删除远程文件：' . $url];
        }
        $key = rawurldecode(substr($url, strlen($publicPrefix) + 1));
        if ($key === '' || str_contains($key, '..')) {
            throw new RuntimeException('对象存储路径无效，无法删除');
        }
        $this->requestRemote('DELETE', $config, $key);

        return ['deleted' => true, 'warning' => null];
    }

    public function readImage(string $url): array
    {
        if (str_starts_with($url, '/storage/')) {
            $relative = rawurldecode(substr($url, strlen('/storage/')));
            if ($relative === '' || str_contains($relative, '..')) {
                throw new RuntimeException('商品原图路径无效');
            }
            $path = $this->localPath($relative);
            $content = is_file($path) ? file_get_contents($path) : false;
            if ($content === false) {
                throw new RuntimeException('商品原图文件不存在');
            }
        } else {
            $content = $this->downloadPublicImage($url);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: '';
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new RuntimeException('商品原图格式无法识别'),
        };

        return ['content' => $content, 'mime' => $mime, 'filename' => 'reference.' . $extension];
    }

    public function testConnection(): string
    {
        $config = $this->configuration();
        if (!$config['configured']) {
            throw new RuntimeException('存储配置不完整，请先保存完整配置');
        }

        $key = $this->objectKey($config['pathPrefix'], '_connection-test', 'storage-' . bin2hex(random_bytes(4)) . '.txt');
        if ($config['driver'] === 'local') {
            $url = $this->storeLocal($key, 'asset-factory-storage-test');
            $path = $this->localPath($key);
            if (!is_file($path) || !unlink($path)) {
                throw new RuntimeException('本地存储写入成功，但测试文件清理失败');
            }
            return '本地存储读写正常：' . $url;
        }

        $this->requestRemote('PUT', $config, $key, 'asset-factory-storage-test', 'text/plain');
        $this->requestRemote('DELETE', $config, $key);
        return $config['driverLabel'] . ' 连接正常，测试文件已写入并清理。';
    }

    private function storeLocal(string $key, string $content): string
    {
        $path = $this->localPath($key);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建本地素材存储目录');
        }
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException('保存生成图片失败');
        }

        return '/storage/' . $this->encodePath($key);
    }

    private function storeRecord(array $config, string $key, string $content, string $contentType): array
    {
        if ($config['driver'] === 'local') {
            return ['url' => $this->storeLocal($key, $content), 'backupUrl' => null];
        }

        $backupUrl = $config['localBackupEnabled'] ? $this->storeLocal($key, $content) : null;
        try {
            $this->requestRemote('PUT', $config, $key, $content, $contentType);
        } catch (\Throwable $exception) {
            if ($backupUrl !== null) {
                try {
                    $this->deleteStoredUrl($backupUrl);
                } catch (\Throwable) {
                }
            }
            throw $exception;
        }

        return ['url' => $config['publicUrl'] . '/' . $this->encodePath($key), 'backupUrl' => $backupUrl];
    }

    private function downloadPublicImage(string $url): string
    {
        $target = RemoteMediaUrlGuard::publicHttpsTarget($url);
        $content = '';
        $curl = curl_init($target['url']);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE => $target['resolve'],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$content): int {
                if (strlen($content) + strlen($chunk) > 10 * 1024 * 1024) {
                    return 0;
                }
                $content .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        if ($ok === false || $status < 200 || $status >= 300 || $content === '') {
            throw new RuntimeException('读取商品原图失败：' . ($error ?: 'HTTP ' . $status));
        }

        return $content;
    }

    private function localPath(string $key): string
    {
        return app()->getRootPath() . 'public/storage/' . $key;
    }

    private function requestRemote(string $method, array $config, string $key, string $content = '', string $contentType = 'application/octet-stream'): void
    {
        if ($config['driver'] === 'cos') {
            $this->requestCos($method, $config, $key, $content, $contentType);
            return;
        }
        if ($config['driver'] === 'oss') {
            $this->requestOss($method, $config, $key, $content, $contentType);
            return;
        }

        $this->requestR2($method, $config, $key, $content, $contentType);
    }

    private function requestCos(string $method, array $config, string $key, string $content, string $contentType): void
    {
        $path = '/' . $this->encodePath($key);
        $url = $config['resolvedEndpoint'] . $path;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $now = time();
        $keyTime = $now . ';' . ($now + 600);
        $signKey = hash_hmac('sha1', $keyTime, $this->settings->get('storage_secret_key'));
        $httpString = strtolower($method) . "\n" . $path . "\n\n" . 'host=' . rawurlencode($host) . "\n";
        $stringToSign = "sha1\n" . $keyTime . "\n" . sha1($httpString) . "\n";
        $signature = hash_hmac('sha1', $stringToSign, $signKey);
        $authorization = 'q-sign-algorithm=sha1&q-ak=' . rawurlencode($this->settings->get('storage_access_key'))
            . '&q-sign-time=' . $keyTime . '&q-key-time=' . $keyTime
            . '&q-header-list=host&q-url-param-list=&q-signature=' . $signature;

        $this->executeRequest($method, $url, $content, [
            'Authorization: ' . $authorization,
            'Content-Type: ' . $contentType,
            'Host: ' . $host,
        ]);
    }

    private function requestOss(string $method, array $config, string $key, string $content, string $contentType): void
    {
        $path = '/' . $this->encodePath($key);
        $url = $config['resolvedEndpoint'] . $path;
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $resource = '/' . $config['bucket'] . $path;
        $stringToSign = $method . "\n\n" . $contentType . "\n" . $date . "\n" . $resource;
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->settings->get('storage_secret_key'), true));

        $this->executeRequest($method, $url, $content, [
            'Authorization: OSS ' . $this->settings->get('storage_access_key') . ':' . $signature,
            'Content-Type: ' . $contentType,
            'Date: ' . $date,
        ]);
    }

    private function requestR2(string $method, array $config, string $key, string $content, string $contentType): void
    {
        $path = $config['driver'] === 'r2'
            ? '/' . rawurlencode($config['bucket']) . '/' . $this->encodePath($key)
            : '/' . $this->encodePath($key);
        $url = $config['resolvedEndpoint'] . $path;
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        if ($port !== null) {
            $host .= ':' . $port;
        }
        $payloadHash = hash('sha256', $content);
        $amzDate = gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);
        $canonicalHeaders = 'host:' . strtolower($host) . "\n"
            . 'x-amz-content-sha256:' . $payloadHash . "\n"
            . 'x-amz-date:' . $amzDate . "\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        $canonicalRequest = $method . "\n" . $path . "\n\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;
        $scope = $date . '/' . $config['region'] . '/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);
        $dateKey = hash_hmac('sha256', $date, 'AWS4' . $this->settings->get('storage_secret_key'), true);
        $regionKey = hash_hmac('sha256', $config['region'], $dateKey, true);
        $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $this->settings->get('storage_access_key') . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

        $this->executeRequest($method, $url, $content, [
            'Authorization: ' . $authorization,
            'Content-Type: ' . $contentType,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $amzDate,
        ]);
    }

    private function executeRequest(string $method, string $url, string $content, array $headers): void
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($method === 'PUT') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $content);
        }
        $body = (string) curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        if ($error !== '' || (($status < 200 || $status >= 300) && !($method === 'DELETE' && $status === 404))) {
            $detail = trim(strip_tags($body));
            if (mb_strlen($detail) > 300) {
                $detail = mb_substr($detail, 0, 300) . '...';
            }
            throw new RuntimeException('对象存储请求失败：' . ($error !== '' ? $error : 'HTTP ' . $status . ($detail !== '' ? '，' . $detail : '')));
        }
    }

    private function resolveEndpoint(string $driver, string $endpoint, string $bucket, string $region): string
    {
        if ($endpoint !== '') {
            return $endpoint;
        }
        if ($driver === 'cos' && $bucket !== '' && $region !== '') {
            return 'https://' . $bucket . '.cos.' . $region . '.myqcloud.com';
        }
        if ($driver === 'oss' && $bucket !== '' && $region !== '') {
            return 'https://' . $bucket . '.oss-' . $region . '.aliyuncs.com';
        }

        return '';
    }

    private function objectKey(string ...$segments): string
    {
        return implode('/', array_values(array_filter(array_map(static fn (string $segment): string => trim($segment, '/'), $segments), static fn (string $segment): bool => $segment !== '')));
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function driverLabel(string $driver): string
    {
        return match ($driver) {
            'cos' => '腾讯云 COS',
            'oss' => '阿里云 OSS',
            'r2' => 'Cloudflare R2',
            default => '本地存储',
        };
    }
}
