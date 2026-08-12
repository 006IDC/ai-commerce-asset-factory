<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use Throwable;
use think\facade\Db;

final class Sub2ApiClient
{
    private const MAX_HEALTH_RESPONSE_BYTES = 1024 * 1024;
    private const MAX_API_RESPONSE_BYTES = 40 * 1024 * 1024;

    public function __construct(
        private readonly SystemSettings $settings,
        private readonly GenerationExecutionAttemptService $executionAttempts
    ) {
    }

    public function configuration(): array
    {
        $baseUrl = rtrim(trim($this->settings->get('sub2api_base_url')), '/');
        $serviceKey = trim($this->settings->get('sub2api_service_key'));
        $enabled = $this->settings->get('sub2api_enabled') === '1';

        return [
            'enabled' => $enabled,
            'configured' => $enabled && $this->validBaseUrl($baseUrl) && $serviceKey !== '',
            'baseUrl' => $baseUrl,
            'keyConfigured' => $serviceKey !== '',
            'timeoutSeconds' => max(30, min(600, (int) $this->settings->get('sub2api_timeout_seconds'))),
            'imageAsyncEnabled' => $this->settings->get('sub2api_image_async_enabled') === '1',
            'imageGatewayMode' => 'catalog',
        ];
    }

    public function enabledFor(?int $merchantId, string $capability, string $model): bool
    {
        $configuration = $this->configuration();
        if (!$configuration['configured']) {
            return false;
        }
        if (in_array($capability, [
            AiCapability::SAFETY_TEXT,
            AiCapability::SAFETY_VISION,
            AiCapability::PRODUCT_VISION,
            AiCapability::CONTENT_PLANNER,
        ], true)) {
            return trim($model) !== '';
        }
        if (
            (
                !in_array($capability, [AiCapability::IMAGE_GENERATION, AiCapability::IMAGE_EDIT], true)
                && !in_array($capability, [AiCapability::VIDEO_IMAGE_TO_VIDEO, AiCapability::VIDEO_TEXT_TO_VIDEO], true)
            )
            || trim($model) === ''
        ) {
            return false;
        }

        return true;
    }

    public function runtimeStatus(): array
    {
        $configuration = $this->configuration();
        $status = [
            'status' => $configuration['enabled'] ? 'offline' : 'disabled',
            'statusLabel' => $configuration['enabled'] ? '连接异常' : '未启用',
            'message' => $configuration['enabled']
                ? 'Sub2API 连接参数不完整或服务暂时不可达。'
                : 'Sub2API 简单媒体链路尚未启用。',
            'checkedAt' => Web::now(),
            'latencyMs' => 0,
            'modelCount' => 0,
            'models' => [],
        ];
        if (!$configuration['configured']) {
            return $status;
        }

        $body = '';
        $responseTooLarge = false;
        $curl = curl_init($configuration['baseUrl'] . '/v1/models');
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . trim($this->settings->get('sub2api_service_key')),
            ],
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$responseTooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_HEALTH_RESPONSE_BYTES) {
                    $responseTooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $started = microtime(true);
        $ok = curl_exec($curl);
        $error = curl_error($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $status['latencyMs'] = (int) round((microtime(true) - $started) * 1000);
        if ($ok === false || $error !== '' || $responseTooLarge || $httpStatus < 200 || $httpStatus >= 300) {
            $status['message'] = $httpStatus > 0
                ? ($responseTooLarge ? 'Sub2API 健康检查响应超过 1MB 安全上限。' : "Sub2API 健康检查返回 HTTP {$httpStatus}。")
                : 'Sub2API 健康检查连接失败。';
            return $status;
        }

        $decoded = json_decode((string) $body, true);
        $rows = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $models = array_values(array_unique(array_filter(array_map(
            static fn (mixed $row): string => is_array($row) ? trim((string) ($row['id'] ?? '')) : '',
            $rows
        ))));
        sort($models);
        $status['status'] = 'ready';
        $status['statusLabel'] = '运行正常';
        $status['message'] = 'Sub2API 服务密钥有效，模型目录可读取。';
        $status['modelCount'] = count($models);
        $status['models'] = $models;
        return $status;
    }

    public function requestJson(
        string $capability,
        string $path,
        array $payload,
        array $context = []
    ): array {
        return $this->request($capability, 'POST', $path, $payload, $context, false);
    }

    public function requestJsonMethod(
        string $capability,
        string $method,
        string $path,
        ?array $payload,
        array $context = []
    ): array {
        return $this->request($capability, $method, $path, $payload, $context, false);
    }

    public function requestMultipart(
        string $capability,
        string $path,
        array $payload,
        array $context = []
    ): array {
        return $this->request($capability, 'POST', $path, $payload, $context, true);
    }

    private function request(
        string $capability,
        string $method,
        string $path,
        ?array $payload,
        array $context,
        bool $multipart
    ): array {
        $configuration = $this->configuration();
        $requestTimeout = (int) $configuration['timeoutSeconds'];
        $requestedTimeout = (int) ($context['timeout_seconds'] ?? 0);
        if ($requestedTimeout > 0) {
            $requestTimeout = min($requestTimeout, max(10, min(600, $requestedTimeout)));
        }
        $method = strtoupper(trim($method));
        if (!in_array($method, ['GET', 'POST'], true)) {
            return [
                'handled' => true,
                'ok' => false,
                'message' => 'Sub2API 请求方法不受支持。',
                'status' => 405,
            ];
        }
        $model = trim((string) ($payload['model'] ?? $context['model'] ?? ''));
        $merchantId = isset($context['merchant_id']) ? (int) $context['merchant_id'] : null;
        if (!$this->enabledFor($merchantId, $capability, $model)) {
            return [
                'handled' => false,
                'ok' => false,
                'message' => '当前请求没有可用的 Sub2API 能力路由。',
                'status' => 0,
            ];
        }

        $clientRequestId = $this->clientRequestId($capability, $context);
        $this->markDispatched($context, $clientRequestId);
        $responseHeaders = [];
        $body = '';
        $responseTooLarge = false;
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . trim($this->settings->get('sub2api_service_key')),
            'X-Request-ID: ' . $clientRequestId,
            'Idempotency-Key: ' . $clientRequestId,
        ];
        if (!$multipart) {
            $headers[] = 'Content-Type: application/json';
        }

        $curl = curl_init($configuration['baseUrl'] . '/' . ltrim($path, '/'));
        $curlOptions = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($line, 0, $separator)));
                    $value = trim(substr($line, $separator + 1));
                    if ($name !== '') {
                        $responseHeaders[$name] = $value;
                    }
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$responseTooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_API_RESPONSE_BYTES) {
                    $responseTooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($method !== 'GET' && $payload !== null) {
            $curlOptions[CURLOPT_POSTFIELDS] = $multipart
                ? $payload
                : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($curl, $curlOptions);
        $started = microtime(true);
        $ok = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $providerRequestId = $this->responseRequestId($responseHeaders, $clientRequestId);

        if ($ok === false || $error !== '' || $responseTooLarge) {
            return [
                'handled' => true,
                'ok' => false,
                'message' => $responseTooLarge
                    ? 'Sub2API 响应超过 40MB 安全上限，任务已停止且不会自动重发。'
                    : 'Sub2API 请求结果不确定，为避免重复扣费不会自动重发，请重新创建任务。',
                'status' => 0,
                'retryAfter' => 0,
                'requestId' => $providerRequestId,
                'clientRequestId' => $clientRequestId,
                'sub2apiExecution' => true,
                'sub2apiAccepted' => false,
                'dispatchAttempted' => true,
                'dispatchAmbiguous' => true,
                'responseTooLarge' => $responseTooLarge,
                'retryable' => false,
                'durationMs' => $durationMs,
            ];
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            return [
                'handled' => true,
                'ok' => false,
                'message' => 'Sub2API 返回了无法识别的响应，为避免重复扣费不会自动重发。',
                'status' => $status > 0 ? $status : 502,
                'retryAfter' => 0,
                'requestId' => $providerRequestId,
                'clientRequestId' => $clientRequestId,
                'sub2apiExecution' => true,
                'sub2apiAccepted' => $status >= 200 && $status < 300,
                'dispatchAttempted' => true,
                'dispatchAmbiguous' => $status === 0 || ($status >= 200 && $status < 300),
                'retryable' => false,
                'durationMs' => $durationMs,
                'data' => [],
            ];
        }

        if ($status < 200 || $status >= 300) {
            $message = $this->errorMessage($decoded);
            return [
                'handled' => true,
                'ok' => false,
                'message' => $message !== '' ? 'Sub2API：' . $message : "Sub2API 返回 HTTP {$status}。",
                'status' => $status,
                'retryAfter' => $this->retryAfter($responseHeaders),
                'requestId' => $providerRequestId,
                'clientRequestId' => $clientRequestId,
                'sub2apiExecution' => true,
                'sub2apiAccepted' => false,
                'dispatchAttempted' => true,
                'dispatchAmbiguous' => false,
                'retryable' => false,
                'durationMs' => $durationMs,
                'data' => $decoded,
            ];
        }

        return [
            'handled' => true,
            'ok' => true,
            'message' => 'Sub2API 请求成功。',
            'status' => $status,
            'retryAfter' => 0,
            'requestId' => trim((string) ($decoded['id'] ?? '')) ?: $providerRequestId,
            'clientRequestId' => $clientRequestId,
            'sub2apiExecution' => true,
            'sub2apiAccepted' => true,
            'dispatchAttempted' => true,
            'dispatchAmbiguous' => false,
            'retryable' => false,
            'durationMs' => $durationMs,
            'data' => $decoded,
        ];
    }

    private function markDispatched(array $context, string $requestId): void
    {
        $itemId = max(0, (int) ($context['generation_item_id'] ?? 0));
        $jobId = max(0, (int) ($context['job_id'] ?? 0));
        $marker = 'sub2api:' . $requestId;
        try {
            Db::transaction(function () use ($itemId, $jobId, $marker): void {
                if ($itemId > 0) {
                    $item = Db::name('generation_job_items')->where('id', $itemId)->lock(true)->find();
                    if ($item && (string) ($item['status'] ?? '') === 'processing'
                        && trim((string) ($item['request_id'] ?? '')) === '') {
                        Db::name('generation_job_items')->where('id', $itemId)->update([
                            'request_id' => $marker,
                            'updated_at' => Web::now(),
                        ]);
                    }
                    $this->executionAttempts->markSubmitted($itemId, $marker);
                }
                if ($jobId > 0) {
                    $job = Db::name('generation_jobs')->where('id', $jobId)->lock(true)->find();
                    if ($job && trim((string) ($job['new_api_request_id'] ?? '')) === '') {
                        Db::name('generation_jobs')->where('id', $jobId)->update([
                            'new_api_request_id' => $marker,
                            'updated_at' => Web::now(),
                        ]);
                    }
                }
            });
        } catch (Throwable) {
            // Dispatch is still protected by the deterministic idempotency key when persistence is unavailable.
        }
    }

    private function clientRequestId(string $capability, array $context): string
    {
        $callKey = trim((string) ($context['call_key'] ?? ''));
        if ($callKey === '') {
            $callKey = implode(':', [
                $capability,
                max(0, (int) ($context['job_id'] ?? 0)),
                max(0, (int) ($context['generation_item_id'] ?? 0)),
            ]);
        }
        return sprintf(
            'af-%d-%d-%s',
            max(0, (int) ($context['job_id'] ?? 0)),
            max(0, (int) ($context['generation_item_id'] ?? 0)),
            substr(hash('sha256', $callKey), 0, 24)
        );
    }

    private function responseRequestId(array $headers, string $fallback): string
    {
        foreach (['x-request-id', 'request-id', 'x-trace-id'] as $name) {
            $value = trim((string) ($headers[$name] ?? ''));
            if ($value !== '') {
                return mb_strimwidth($value, 0, 190, '');
            }
        }
        return $fallback;
    }

    private function retryAfter(array $headers): int
    {
        $value = trim((string) ($headers['retry-after'] ?? ''));
        return ctype_digit($value) ? max(0, min(3600, (int) $value)) : 0;
    }

    private function errorMessage(array $decoded): string
    {
        $error = $decoded['error'] ?? null;
        if (is_array($error)) {
            return trim((string) ($error['message'] ?? $error['code'] ?? ''));
        }
        if (is_string($error)) {
            return trim($error);
        }
        return trim((string) ($decoded['message'] ?? ''));
    }

    private function merchantIds(string $encoded): array
    {
        $decoded = json_decode($encoded, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_unique(array_filter(
            array_map('intval', $decoded),
            static fn (int $id): bool => $id > 0
        )));
    }

    private function validBaseUrl(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true)
            && parse_url($url, PHP_URL_USER) === null
            && parse_url($url, PHP_URL_PASS) === null
            && parse_url($url, PHP_URL_FRAGMENT) === null;
    }
}
