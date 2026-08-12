<?php

declare(strict_types=1);

namespace app\service;

final class ComfyUiClient
{
    public function __construct(private readonly SystemSettings $settings)
    {
    }

    public function configuration(): array
    {
        $mode = strtolower(trim($this->settings->get('comfyui_api_mode')));
        $mode = in_array($mode, ['local', 'cloud'], true) ? $mode : 'local';
        $baseUrl = rtrim(trim($this->settings->get('comfyui_base_url')), '/');
        $baseUrls = $this->normalizeBaseUrls(
            $baseUrl,
            $this->settings->get('comfyui_base_urls'),
            $mode
        );
        $baseUrl = $baseUrl !== '' ? $baseUrl : (string) ($baseUrls[0] ?? '');
        $apiKey = trim($this->settings->get('comfyui_api_key'));
        $enabled = $this->settings->get('comfyui_enabled') === '1';

        return [
            'enabled' => $enabled,
            'configured' => $enabled && $baseUrls !== [] && ($mode === 'local' || $apiKey !== ''),
            'mode' => $mode,
            'baseUrl' => $baseUrl,
            'baseUrls' => $baseUrls,
            'executionSlots' => count($baseUrls),
            'keyConfigured' => $apiKey !== '',
            'timeoutSeconds' => max(5, min(180, (int) $this->settings->get('comfyui_request_timeout_seconds'))),
            'workflowTimeoutSeconds' => max(60, min(1200, (int) $this->settings->get('comfyui_workflow_timeout_seconds'))),
        ];
    }

    public function testConnection(): array
    {
        $runtime = $this->runtimeStatus();
        return [
            'ok' => (string) ($runtime['status'] ?? '') === 'ready',
            'message' => (string) ($runtime['message'] ?? 'ComfyUI 运行状态检测结束。'),
            'nodeCount' => (int) ($runtime['nodeCount'] ?? 0),
            'runtime' => $runtime,
        ];
    }

    public function runtimeStatus(): array
    {
        $configuration = $this->configuration();
        $base = [
            'status' => $configuration['enabled'] ? 'offline' : 'disabled',
            'statusLabel' => $configuration['enabled'] ? '连接异常' : '未启用',
            'message' => $configuration['enabled']
                ? 'ComfyUI 连接参数不完整或服务暂时不可达。'
                : 'ComfyUI 执行器尚未启用。',
            'checkedAt' => date('Y-m-d H:i:s'),
            'latencyMs' => 0,
            'managed' => $configuration['mode'] === 'local'
                && $this->isLoopbackUrl((string) $configuration['baseUrl']),
            'version' => '',
            'pythonVersion' => '',
            'torchVersion' => '',
            'device' => '',
            'queueRunning' => 0,
            'queuePending' => 0,
            'nodeCount' => 0,
            'controlledNodeReady' => false,
            'executionSlots' => (int) ($configuration['executionSlots'] ?? 0),
            'readySlots' => 0,
            'slotStatus' => [],
        ];
        if (!$configuration['configured']) {
            return $base;
        }

        $startedAt = microtime(true);
        $systemPath = $configuration['mode'] === 'cloud' ? '/api/system_stats' : '/system_stats';
        $system = $this->requestJson('GET', $systemPath, null, 5, 3);
        $base['latencyMs'] = (int) round((microtime(true) - $startedAt) * 1000);
        if (empty($system['ok'])) {
            $base['message'] = (string) ($system['message'] ?? 'ComfyUI 服务未响应。');
            return $base;
        }

        $systemData = is_array($system['data'] ?? null) ? $system['data'] : [];
        $systemInfo = is_array($systemData['system'] ?? null) ? $systemData['system'] : $systemData;
        $devices = is_array($systemData['devices'] ?? null) ? $systemData['devices'] : [];
        $firstDevice = is_array($devices[0] ?? null) ? $devices[0] : [];
        $base['version'] = trim((string) (
            $systemInfo['comfyui_version']
            ?? $systemInfo['version']
            ?? $systemData['comfyui_version']
            ?? ''
        ));
        $base['pythonVersion'] = trim((string) ($systemInfo['python_version'] ?? ''));
        $base['torchVersion'] = trim((string) ($systemInfo['pytorch_version'] ?? $systemInfo['torch_version'] ?? ''));
        $base['device'] = trim((string) (
            $firstDevice['name']
            ?? $firstDevice['type']
            ?? $systemInfo['device']
            ?? ''
        ));

        $queuePath = $configuration['mode'] === 'cloud' ? '/api/queue' : '/queue';
        $queue = ['ok' => true];
        foreach ((array) ($configuration['baseUrls'] ?? [$configuration['baseUrl']]) as $slot => $slotBaseUrl) {
            $slotQueue = $this->requestJson('GET', $queuePath, null, 5, 3, (string) $slotBaseUrl);
            $slotData = is_array($slotQueue['data'] ?? null) ? $slotQueue['data'] : [];
            $running = !empty($slotQueue['ok'])
                ? $this->queueCount($slotData['queue_running'] ?? $slotData['running'] ?? [])
                : 0;
            $pending = !empty($slotQueue['ok'])
                ? $this->queueCount($slotData['queue_pending'] ?? $slotData['pending'] ?? [])
                : 0;
            $base['queueRunning'] += $running;
            $base['queuePending'] += $pending;
            $base['readySlots'] += !empty($slotQueue['ok']) ? 1 : 0;
            $base['slotStatus'][] = [
                'slot' => $slot + 1,
                'ready' => !empty($slotQueue['ok']),
                'running' => $running,
                'pending' => $pending,
            ];
            if (empty($slotQueue['ok'])) {
                $queue['ok'] = false;
            }
        }

        $objectPath = $configuration['mode'] === 'cloud' ? '/api/object_info' : '/object_info';
        $objects = $this->requestJson('GET', $objectPath, null, 8, 3);
        $nodes = !empty($objects['ok']) && is_array($objects['data'] ?? null) ? $objects['data'] : [];
        $base['nodeCount'] = count($nodes);
        $base['controlledNodeReady'] = isset($nodes['AssetFactorySub2ApiImage'])
            || isset($nodes['AssetFactoryRoutedImage'])
            || isset($nodes['AssetFactorySeedreamProductImage']);
        if ($base['controlledNodeReady'] && !empty($queue['ok']) && !empty($objects['ok'])) {
            $base['status'] = 'ready';
            $base['statusLabel'] = '运行正常';
            $base['message'] = 'ComfyUI 运行正常，队列、节点目录和受控电商节点均可用。';
            return $base;
        }

        $base['status'] = 'degraded';
        $base['statusLabel'] = '部分可用';
        $missing = [];
        if (empty($queue['ok'])) {
            $missing[] = '队列接口';
        }
        if (empty($objects['ok'])) {
            $missing[] = '节点目录';
        }
        if (!$base['controlledNodeReady']) {
            $missing[] = '受控电商节点';
        }
        $base['message'] = 'ComfyUI 服务可达，但' . implode('、', $missing) . '尚未就绪。';
        return $base;
    }

    public function objectInfo(): array
    {
        $configuration = $this->configuration();
        if (!$configuration['configured']) {
            return ['ok' => false, 'message' => 'ComfyUI 尚未启用或连接参数不完整。', 'nodes' => []];
        }

        $path = $configuration['mode'] === 'cloud' ? '/api/object_info' : '/object_info';
        $result = $this->requestJson('GET', $path);
        $nodes = $result['ok'] && is_array($result['data'] ?? null) ? $result['data'] : [];
        return $result + ['nodes' => $nodes];
    }

    public function submitWorkflow(array $workflow, string $clientId, array $extraData = []): array
    {
        if ($workflow === [] || array_is_list($workflow)) {
            return ['ok' => false, 'message' => '工作流不是有效的 ComfyUI API 格式。'];
        }
        $configuration = $this->configuration();
        $path = $configuration['mode'] === 'cloud' ? '/api/prompt' : '/prompt';
        $payload = [
            'prompt' => $workflow,
            'client_id' => trim($clientId) !== '' ? trim($clientId) : bin2hex(random_bytes(12)),
        ];
        if ($extraData !== []) {
            $payload['extra_data'] = $extraData;
        }
        [$slotIndex, $baseUrl] = $this->executionSlot($configuration, $extraData);
        $result = $this->requestJson(
            'POST',
            $path,
            $payload,
            max(30, $configuration['timeoutSeconds']),
            null,
            $baseUrl
        );
        if (!$result['ok']) {
            return $result;
        }
        $promptId = trim((string) ($result['data']['prompt_id'] ?? ''));
        if ($promptId === '') {
            return ['ok' => false, 'message' => 'ComfyUI 已响应，但没有返回 prompt_id。', 'data' => $result['data'] ?? []];
        }

        $locator = count((array) ($configuration['baseUrls'] ?? [])) > 1
            ? 'u' . rtrim(strtr(base64_encode($baseUrl), '+/', '-_'), '=') . ':' . $promptId
            : $promptId;
        return array_merge($result, [
            'data' => [
                'id' => $locator,
                'request_id' => $locator,
                'status' => 'queued',
                'execution_slot' => $slotIndex + 1,
                'number' => $result['data']['number'] ?? null,
                'node_errors' => $result['data']['node_errors'] ?? [],
            ],
            'requestId' => $locator,
        ]);
    }

    public function uploadImage(array $image, string $filename, string $subfolder = ''): array
    {
        $configuration = $this->configuration();
        if (!$configuration['configured']) {
            return ['ok' => false, 'message' => 'ComfyUI 尚未启用或连接参数不完整。', 'status' => 0];
        }
        if ($configuration['mode'] !== 'local') {
            return ['ok' => false, 'message' => '当前 ComfyUI Cloud 适配器尚未开放参考图上传。', 'status' => 0];
        }
        $content = $image['content'] ?? null;
        $mime = strtolower(trim((string) ($image['mime'] ?? '')));
        if (!is_string($content) || $content === '' || strlen($content) > 20 * 1024 * 1024) {
            return ['ok' => false, 'message' => 'ComfyUI 参考图为空或超过 20MB。', 'status' => 0];
        }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return ['ok' => false, 'message' => 'ComfyUI 参考图只支持 JPG、PNG 或 WebP。', 'status' => 0];
        }
        $filename = $this->safeFilename($filename, $mime);
        $subfolder = $this->safeSubfolder($subfolder);
        $temporary = tempnam(sys_get_temp_dir(), 'comfy-upload-');
        if ($temporary === false || file_put_contents($temporary, $content, LOCK_EX) === false) {
            return ['ok' => false, 'message' => '无法准备 ComfyUI 参考图上传文件。', 'status' => 0];
        }
        try {
            $result = $this->requestMultipart('/upload/image', [
                'image' => curl_file_create($temporary, $mime, $filename),
                'type' => 'input',
                'subfolder' => $subfolder,
                'overwrite' => 'true',
            ], max(30, $configuration['timeoutSeconds']));
        } finally {
            @unlink($temporary);
        }
        if (!$result['ok']) {
            return $result;
        }
        $name = trim((string) ($result['data']['name'] ?? ''));
        $storedSubfolder = trim((string) ($result['data']['subfolder'] ?? $subfolder), "/\\");
        if ($name === '') {
            return ['ok' => false, 'message' => 'ComfyUI 已接收上传，但没有返回文件名。', 'status' => 502];
        }
        $result['reference'] = ($storedSubfolder !== '' ? $storedSubfolder . '/' : '') . $name;
        return $result;
    }

    public function downloadOutput(string $url): array
    {
        $configuration = $this->configuration();
        $url = trim($url);
        $owned = false;
        foreach ((array) ($configuration['baseUrls'] ?? [$configuration['baseUrl']]) as $baseUrl) {
            if (str_starts_with($url, rtrim((string) $baseUrl, '/') . '/')) {
                $owned = true;
                break;
            }
        }
        if (!$configuration['configured'] || $url === '' || !$owned) {
            return ['ok' => false, 'message' => 'ComfyUI 输出地址不属于当前受控服务。', 'status' => 0];
        }
        $headers = ['Accept: image/jpeg,image/png,image/webp,application/octet-stream'];
        $key = trim($this->settings->get('comfyui_api_key'));
        if ($key !== '') {
            $headers[] = 'X-API-Key: ' . $key;
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        $content = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$content): int {
                if (strlen($content) + strlen($chunk) > 25 * 1024 * 1024) {
                    return 0;
                }
                $content .= $chunk;
                return strlen($chunk);
            },
        ]);
        $executed = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $contentType = strtolower(trim(explode(';', (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE))[0]));
        if ($executed === false || $status < 200 || $status >= 300 || $content === '') {
            return ['ok' => false, 'message' => '读取 ComfyUI 输出失败：' . ($error ?: 'HTTP ' . $status), 'status' => $status];
        }
        if ($contentType !== '' && !in_array($contentType, ['image/jpeg', 'image/png', 'image/webp', 'application/octet-stream'], true)) {
            return ['ok' => false, 'message' => 'ComfyUI 返回的文件不是受支持的图片。', 'status' => $status];
        }
        if (@getimagesizefromstring($content) === false) {
            return ['ok' => false, 'message' => 'ComfyUI 返回的图片无法解码。', 'status' => $status];
        }
        return ['ok' => true, 'message' => 'ComfyUI 图片输出读取成功。', 'status' => $status, 'content' => $content, 'mime' => $contentType];
    }

    public function workflowStatus(string $promptId): array
    {
        $promptId = trim($promptId);
        if ($promptId === '') {
            return ['ok' => false, 'message' => 'ComfyUI prompt_id 不能为空。'];
        }
        $configuration = $this->configuration();
        [$rawPromptId, $baseUrl, $slotIndex] = $this->resolvePromptLocator($promptId, $configuration);
        if ($configuration['mode'] === 'cloud') {
            return $this->cloudStatus($rawPromptId);
        }

        $result = $this->requestJson(
            'GET',
            '/history/' . rawurlencode($rawPromptId),
            null,
            null,
            null,
            $baseUrl
        );
        if (!$result['ok']) {
            return $result;
        }
        $history = is_array($result['data'][$rawPromptId] ?? null)
            ? $result['data'][$rawPromptId]
            : (is_array($result['data'] ?? null) && isset($result['data']['outputs']) ? $result['data'] : null);
        if ($history === null) {
            $queue = $this->requestJson('GET', '/queue', null, 5, 3, $baseUrl);
            $known = !empty($queue['ok'])
                && $this->containsScalar((array) ($queue['data'] ?? []), $rawPromptId);
            return [
                'ok' => true,
                'message' => $known
                    ? 'ComfyUI 任务仍在队列或执行中。'
                    : 'ComfyUI 已不再保存此任务，可能在执行器重启时丢失。',
                'data' => [
                    'status' => $known ? 'running' : 'lost',
                    'progress' => $known ? 45 : 100,
                    'prompt_known' => $known,
                    'execution_slot' => $slotIndex + 1,
                ],
                'requestId' => $promptId,
            ];
        }
        $status = is_array($history['status'] ?? null) ? $history['status'] : [];
        $completed = !empty($status['completed']);
        $statusText = strtolower(trim((string) ($status['status_str'] ?? '')));
        $error = $this->executionErrorDetails($status);
        if ($error['message'] !== '' || in_array($statusText, ['error', 'failed'], true)) {
            return [
                'ok' => true,
                'message' => $error['message'] !== '' ? $error['message'] : 'ComfyUI 工作流执行失败。',
                'data' => [
                    'status' => 'failed',
                    'progress' => 100,
                    'message' => $error['message'],
                    'failed_node_id' => $error['nodeId'],
                    'failed_node_type' => $error['nodeType'],
                    'exception_type' => $error['exceptionType'],
                ],
            ];
        }
        $rawOutputs = is_array($history['outputs'] ?? null) ? $history['outputs'] : [];
        $outputs = $this->normalizeOutputs($rawOutputs, $baseUrl);
        $providerMetadata = $this->providerMetadata($rawOutputs);
        if (!$completed && $outputs === []) {
            return [
                'ok' => true,
                'message' => 'ComfyUI 工作流正在执行。',
                'data' => ['status' => 'running', 'progress' => 65],
            ];
        }

        if ($outputs === []) {
            return [
                'ok' => true,
                'message' => 'ComfyUI 工作流已结束，但没有可用输出。',
                'data' => [
                    'status' => 'result_missing',
                    'progress' => 100,
                    'outputs' => [],
                    'video_url' => '',
                    'image_url' => '',
                ],
                'requestId' => $promptId,
            ];
        }

        return [
            'ok' => true,
            'message' => 'ComfyUI 工作流已完成。',
            'data' => [
                'status' => 'completed',
                'progress' => 100,
                'outputs' => $outputs,
                'video_url' => $this->firstMediaUrl($outputs, 'video'),
                'image_url' => $this->firstMediaUrl($outputs, 'image'),
                'provider_metadata' => $providerMetadata,
            ],
            'requestId' => $promptId,
        ];
    }

    private function cloudStatus(string $promptId): array
    {
        $result = $this->requestJson('GET', '/api/job/' . rawurlencode($promptId) . '/status');
        if (!$result['ok']) {
            return $result;
        }
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $status = strtolower(trim((string) ($data['status'] ?? 'pending')));
        $normalizedStatus = match ($status) {
            'completed', 'success', 'succeeded' => 'completed',
            'failed', 'error', 'cancelled', 'canceled' => 'failed',
            'in_progress', 'running', 'processing' => 'running',
            default => 'pending',
        };
        $rawOutputs = is_array($data['outputs'] ?? null) ? $data['outputs'] : [];
        $outputs = $this->normalizeOutputs($rawOutputs);
        $data['status'] = $normalizedStatus;
        $data['progress'] = $normalizedStatus === 'completed' ? 100 : max(10, min(95, (int) ($data['progress'] ?? 0)));
        $data['outputs'] = $outputs;
        $data['provider_metadata'] = $this->providerMetadata($rawOutputs);
        $data['video_url'] = trim((string) ($data['video_url'] ?? $this->firstMediaUrl($outputs, 'video')));
        $data['image_url'] = trim((string) ($data['image_url'] ?? $this->firstMediaUrl($outputs, 'image')));
        return array_merge($result, ['data' => $data, 'requestId' => $promptId]);
    }

    private function providerMetadata(array $outputs): array
    {
        $metadata = [];
        $this->collectProviderMetadata($outputs, $metadata);
        return $metadata;
    }

    private function collectProviderMetadata(array $value, array &$metadata): void
    {
        $requestId = trim((string) ($value['sub2api_request_id'] ?? ''));
        if ($requestId !== '') {
            $metadata['sub2api_request_id'] = mb_strimwidth($requestId, 0, 190, '');
            $model = trim((string) ($value['model'] ?? ''));
            if ($model !== '') {
                $metadata['model'] = mb_strimwidth($model, 0, 190, '');
            }
            $metadata['duration_ms'] = max(0, (int) ($value['duration_ms'] ?? 0));
            $metadata['usage'] = is_array($value['usage'] ?? null) ? $value['usage'] : [];
            $metadata['quality'] = is_array($value['quality'] ?? null) ? $value['quality'] : [];
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                $this->collectProviderMetadata($child, $metadata);
            }
        }
    }

    private function normalizeOutputs(array $outputs, ?string $baseUrl = null): array
    {
        $normalized = [];
        $this->collectOutputs($outputs, $normalized, $baseUrl);
        return array_values($normalized);
    }

    private function collectOutputs(array $value, array &$normalized, ?string $baseUrl = null): void
    {
        if (isset($value['filename']) && is_string($value['filename']) && trim($value['filename']) !== '') {
            $filename = trim($value['filename']);
            $subfolder = trim((string) ($value['subfolder'] ?? ''));
            $type = trim((string) ($value['type'] ?? 'output')) ?: 'output';
            $key = $type . ':' . $subfolder . ':' . $filename;
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $mediaType = in_array($extension, ['mp4', 'webm', 'mov', 'mkv', 'gif'], true) ? 'video' : 'image';
            $normalized[$key] = [
                'filename' => $filename,
                'subfolder' => $subfolder,
                'type' => $type,
                'media_type' => $mediaType,
                'url' => $this->viewUrl($filename, $subfolder, $type, $baseUrl),
            ];
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                $this->collectOutputs($child, $normalized, $baseUrl);
            }
        }
    }

    private function firstMediaUrl(array $outputs, string $mediaType): string
    {
        foreach ($outputs as $output) {
            if ((string) ($output['media_type'] ?? '') === $mediaType) {
                return trim((string) ($output['url'] ?? ''));
            }
        }
        return '';
    }

    private function viewUrl(
        string $filename,
        string $subfolder = '',
        string $type = 'output',
        ?string $baseUrl = null
    ): string
    {
        $configuration = $this->configuration();
        $prefix = $configuration['mode'] === 'cloud' ? '/api/view' : '/view';
        return rtrim($baseUrl ?? (string) $configuration['baseUrl'], '/') . $prefix . '?' . http_build_query([
            'filename' => $filename,
            'subfolder' => $subfolder,
            'type' => $type,
        ]);
    }

    private function executionErrorDetails(array $status): array
    {
        foreach ((array) ($status['messages'] ?? []) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $type = strtolower((string) ($message[0] ?? ''));
            $payload = is_array($message[1] ?? null) ? $message[1] : [];
            if (str_contains($type, 'error')) {
                return [
                    'message' => mb_strimwidth(
                        trim((string) ($payload['exception_message'] ?? $payload['message'] ?? 'ComfyUI 节点执行失败。')),
                        0,
                        1000,
                        ''
                    ),
                    'nodeId' => mb_strimwidth(trim((string) ($payload['node_id'] ?? '')), 0, 100, ''),
                    'nodeType' => mb_strimwidth(trim((string) ($payload['node_type'] ?? '')), 0, 200, ''),
                    'exceptionType' => mb_strimwidth(trim((string) ($payload['exception_type'] ?? '')), 0, 200, ''),
                ];
            }
        }
        return ['message' => '', 'nodeId' => '', 'nodeType' => '', 'exceptionType' => ''];
    }

    private function requestJson(
        string $method,
        string $path,
        ?array $payload = null,
        ?int $timeout = null,
        ?int $connectTimeout = null,
        ?string $baseUrl = null
    ): array
    {
        $configuration = $this->configuration();
        if (!$configuration['configured']) {
            return ['ok' => false, 'message' => 'ComfyUI 尚未启用或连接参数不完整。', 'status' => 0];
        }
        $headers = ['Accept: application/json'];
        $key = trim($this->settings->get('comfyui_api_key'));
        if ($key !== '') {
            $headers[] = 'X-API-Key: ' . $key;
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $baseUrl = rtrim($baseUrl ?? (string) $configuration['baseUrl'], '/');
        $controlledUrls = array_values(array_unique(array_merge(
            (array) ($configuration['baseUrls'] ?? []),
            [(string) ($configuration['baseUrl'] ?? '')]
        )));
        if (!in_array($baseUrl, $controlledUrls, true)) {
            return ['ok' => false, 'message' => 'ComfyUI 执行槽地址不在受控配置中。', 'status' => 0];
        }
        $curl = curl_init($baseUrl . '/' . ltrim($path, '/'));
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout ?? 8,
            CURLOPT_TIMEOUT => $timeout ?? $configuration['timeoutSeconds'],
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($payload !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($body === false || $error !== '') {
            return ['ok' => false, 'message' => 'ComfyUI 连接失败：' . ($error ?: '未知网络错误'), 'status' => 0];
        }
        $decoded = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded)
                ? trim((string) ($decoded['error']['message'] ?? $decoded['error'] ?? $decoded['message'] ?? ''))
                : '';
            return [
                'ok' => false,
                'message' => $message !== '' ? 'ComfyUI：' . $message : "ComfyUI 返回 HTTP {$status}。",
                'status' => $status,
                'data' => is_array($decoded) ? $decoded : [],
            ];
        }
        return [
            'ok' => true,
            'message' => 'ComfyUI 请求成功。',
            'status' => $status,
            'data' => is_array($decoded) ? $decoded : [],
        ];
    }

    private function requestMultipart(string $path, array $payload, int $timeout): array
    {
        $configuration = $this->configuration();
        if (!$configuration['configured']) {
            return ['ok' => false, 'message' => 'ComfyUI 尚未启用或连接参数不完整。', 'status' => 0];
        }
        $headers = ['Accept: application/json'];
        $key = trim($this->settings->get('comfyui_api_key'));
        if ($key !== '') {
            $headers[] = 'X-API-Key: ' . $key;
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        $curl = curl_init($configuration['baseUrl'] . '/' . ltrim($path, '/'));
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($body === false || $error !== '') {
            return ['ok' => false, 'message' => 'ComfyUI 上传失败：' . ($error ?: '未知网络错误'), 'status' => 0];
        }
        $decoded = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded)
                ? trim((string) ($decoded['error']['message'] ?? $decoded['error'] ?? $decoded['message'] ?? ''))
                : '';
            return [
                'ok' => false,
                'message' => $message !== '' ? 'ComfyUI：' . $message : "ComfyUI 返回 HTTP {$status}。",
                'status' => $status,
                'data' => is_array($decoded) ? $decoded : [],
            ];
        }
        return [
            'ok' => true,
            'message' => 'ComfyUI 上传成功。',
            'status' => $status,
            'data' => is_array($decoded) ? $decoded : [],
        ];
    }

    private function safeFilename(string $filename, string $mime): string
    {
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
        $stem = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo(basename($filename), PATHINFO_FILENAME)) ?: 'reference';
        $stem = substr(trim($stem, '.-_'), 0, 100);
        return ($stem !== '' ? $stem : 'reference') . '.' . $extension;
    }

    private function safeSubfolder(string $subfolder): string
    {
        $segments = array_values(array_filter(array_map(
            static fn (string $segment): string => substr(preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($segment)) ?: '', 0, 80),
            preg_split('#[\\\\/]+#', trim($subfolder)) ?: []
        )));
        return implode('/', array_slice($segments, 0, 4));
    }

    private function queueCount(mixed $value): int
    {
        if (is_array($value)) {
            return count($value);
        }
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function isLoopbackUrl(string $url): bool
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }

    private function normalizeBaseUrls(string $primary, string $configured, string $mode): array
    {
        $decoded = json_decode(trim($configured), true);
        if (!is_array($decoded)) {
            $decoded = preg_split('/[\s,;]+/', trim($configured)) ?: [];
        }
        $candidates = $decoded === [] ? [$primary] : array_map('strval', $decoded);
        $urls = [];
        foreach ($candidates as $candidate) {
            $candidate = rtrim(trim((string) $candidate), '/');
            if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_URL) === false) {
                continue;
            }
            if ($mode === 'local' && !$this->isLoopbackUrl($candidate)) {
                continue;
            }
            if (!in_array($candidate, $urls, true)) {
                $urls[] = $candidate;
            }
        }
        $primary = rtrim(trim($primary), '/');
        if ($urls === []
            && filter_var($primary, FILTER_VALIDATE_URL) !== false
            && ($mode !== 'local' || $this->isLoopbackUrl($primary))) {
            $urls[] = $primary;
        }
        return $mode === 'cloud' ? array_slice($urls, 0, 1) : array_slice($urls, 0, 5);
    }

    private function executionSlot(array $configuration, array $extraData): array
    {
        $urls = array_values((array) ($configuration['baseUrls'] ?? []));
        if ($urls === []) {
            return [0, (string) ($configuration['baseUrl'] ?? '')];
        }
        $assetFactory = is_array($extraData['asset_factory'] ?? null)
            ? $extraData['asset_factory']
            : [];
        $stableId = max(0, (int) (
            $assetFactory['generation_item_id']
            ?? $assetFactory['job_id']
            ?? 0
        ));
        $slot = $stableId > 0 ? (($stableId - 1) % count($urls)) : 0;
        return [$slot, (string) $urls[$slot]];
    }

    private function resolvePromptLocator(string $locator, array $configuration): array
    {
        $urls = array_values((array) ($configuration['baseUrls'] ?? []));
        if (preg_match('/^u([A-Za-z0-9_-]+):(.+)$/', $locator, $matches) === 1) {
            $encoded = strtr((string) $matches[1], '-_', '+/');
            $padding = strlen($encoded) % 4;
            if ($padding !== 0) {
                $encoded .= str_repeat('=', 4 - $padding);
            }
            $decodedUrl = base64_decode($encoded, true);
            $controlledUrls = array_values(array_unique(array_merge(
                $urls,
                [(string) ($configuration['baseUrl'] ?? '')]
            )));
            if (is_string($decodedUrl) && in_array($decodedUrl, $controlledUrls, true)) {
                $slot = array_search($decodedUrl, $urls, true);
                return [
                    (string) $matches[2],
                    $decodedUrl,
                    $slot === false ? 0 : (int) $slot,
                ];
            }
        }
        if (preg_match('/^s(\d+):(.+)$/', $locator, $matches) === 1) {
            $slot = max(0, (int) $matches[1] - 1);
            if (isset($urls[$slot])) {
                return [(string) $matches[2], (string) $urls[$slot], $slot];
            }
        }
        return [
            $locator,
            (string) ($configuration['baseUrl'] ?? $urls[0] ?? ''),
            0,
        ];
    }

    private function containsScalar(array $value, string $needle): bool
    {
        foreach ($value as $item) {
            if (is_array($item)) {
                if ($this->containsScalar($item, $needle)) {
                    return true;
                }
                continue;
            }
            if (is_scalar($item) && (string) $item === $needle) {
                return true;
            }
        }
        return false;
    }
}
