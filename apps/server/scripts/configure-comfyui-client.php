<?php

declare(strict_types=1);

use app\service\ComfyUiClient;
use app\service\SystemSettings;
use think\App;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', [
    'enabled:',
    'base-url::',
    'base-urls::',
    'request-timeout::',
    'workflow-timeout::',
    'admin-id::',
]);
$enabled = (string) ($options['enabled'] ?? '');
if (!in_array($enabled, ['0', '1'], true)) {
    fwrite(
        STDERR,
        "Usage: php scripts/configure-comfyui-client.php --enabled=0|1"
        . " [--base-url=http://127.0.0.1:8188]\n"
    );
    exit(2);
}

$baseUrl = rtrim(trim((string) ($options['base-url'] ?? 'http://127.0.0.1:8188')), '/');
if ($enabled === '1' && filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
    fwrite(STDERR, "ComfyUI base URL is invalid.\n");
    exit(2);
}
$baseUrlsInput = trim((string) ($options['base-urls'] ?? ''));
$baseUrls = $baseUrlsInput !== '' ? json_decode($baseUrlsInput, true) : [$baseUrl];
if (!is_array($baseUrls) || $baseUrls === []) {
    fwrite(STDERR, "ComfyUI base URLs must be a non-empty JSON array.\n");
    exit(2);
}
$baseUrls = array_values(array_unique(array_map(
    static fn (mixed $url): string => rtrim(trim((string) $url), '/'),
    $baseUrls
)));
foreach ($baseUrls as $candidateBaseUrl) {
    if (filter_var($candidateBaseUrl, FILTER_VALIDATE_URL) === false) {
        fwrite(STDERR, "ComfyUI base URL is invalid: {$candidateBaseUrl}\n");
        exit(2);
    }
}

$requestTimeout = max(5, min(180, (int) ($options['request-timeout'] ?? 30)));
$workflowTimeout = max(60, min(1200, (int) ($options['workflow-timeout'] ?? 600)));
$adminId = max(1, (int) ($options['admin-id'] ?? 1));

$app = (new App(dirname(__DIR__)))->initialize();
$settings = $app->make(SystemSettings::class);
$changed = $settings->setMany([
    'comfyui_enabled' => $enabled,
    'comfyui_api_mode' => 'local',
    'comfyui_base_url' => $baseUrl,
    'comfyui_base_urls' => json_encode($baseUrls, JSON_UNESCAPED_SLASHES),
    'comfyui_api_key' => '',
    'comfyui_request_timeout_seconds' => (string) $requestTimeout,
    'comfyui_workflow_timeout_seconds' => (string) $workflowTimeout,
], $adminId);

$runtime = $app->make(ComfyUiClient::class)->runtimeStatus();
if ($enabled === '1' && (string) ($runtime['status'] ?? '') !== 'ready') {
    $settings->setMany(['comfyui_enabled' => '0'], $adminId);
    fwrite(
        STDERR,
        'ComfyUI settings were saved, but the runtime check failed: '
        . (string) ($runtime['message'] ?? 'unknown error')
        . '. The client was disabled automatically.' . PHP_EOL
    );
    exit(1);
}

echo implode('|', [
    'comfyui-client',
    $enabled === '1' ? 'enabled' : 'disabled',
    'mode=local',
    'base_urls=' . count($baseUrls),
    'runtime=' . (string) ($runtime['status'] ?? 'unknown'),
    'version=' . (string) ($runtime['version'] ?? ''),
    'nodes=' . (int) ($runtime['nodeCount'] ?? 0),
    'controlled_node=' . (!empty($runtime['controlledNodeReady']) ? 'ready' : 'missing'),
    'changed=' . count($changed),
]) . PHP_EOL;
