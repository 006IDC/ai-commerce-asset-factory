<?php

declare(strict_types=1);

use app\service\SystemSettings;
use app\service\Sub2ApiClient;
use think\App;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', [
    'enabled:',
    'gray-merchant-ids:',
    'key-file::',
    'base-url::',
    'timeout::',
    'admin-id::',
    'allow-global',
]);
$enabled = (string) ($options['enabled'] ?? '');
if (!in_array($enabled, ['0', '1'], true)) {
    fwrite(STDERR, "Usage: php scripts/configure-sub2api-client.php --enabled=0|1 --gray-merchant-ids=1,2\n");
    exit(2);
}
$grayMerchantIds = array_values(array_unique(array_filter(array_map(
    'intval',
    preg_split('/[\s,]+/', trim((string) ($options['gray-merchant-ids'] ?? ''))) ?: []
), static fn (int $id): bool => $id > 0)));
if ($enabled === '1' && $grayMerchantIds === [] && !array_key_exists('allow-global', $options)) {
    fwrite(STDERR, "Refusing a global cutover without --allow-global. Supply at least one gray merchant ID.\n");
    exit(2);
}

$keyFile = (string) ($options['key-file']
    ?? '/etc/asset-factory/sub2api/asset-factory-thinkphp-production.env');
if (!is_file($keyFile) || !is_readable($keyFile)) {
    fwrite(STDERR, "Protected Sub2API key file is missing or unreadable.\n");
    exit(2);
}
if ((fileperms($keyFile) & 0077) !== 0) {
    fwrite(STDERR, "Protected Sub2API key file must not be accessible by group or other users.\n");
    exit(2);
}
$secretValues = parse_ini_file($keyFile, false, INI_SCANNER_RAW);
$secretValues = is_array($secretValues) ? $secretValues : [];
$serviceKey = trim((string) ($secretValues['SUB2API_SERVICE_KEY'] ?? ''));
$baseUrl = rtrim(trim((string) (
    $options['base-url']
    ?? $secretValues['SUB2API_BASE_URL']
    ?? 'http://127.0.0.1:3002'
)), '/');
if ($serviceKey === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
    fwrite(STDERR, "Protected Sub2API key file does not contain a usable service key and base URL.\n");
    exit(2);
}

$timeout = max(30, min(600, (int) ($options['timeout'] ?? 450)));
$adminId = max(1, (int) ($options['admin-id'] ?? 1));
$app = (new App(dirname(__DIR__)))->initialize();
$settings = $app->make(SystemSettings::class);
$changed = $settings->setMany([
    'sub2api_enabled' => $enabled,
    'sub2api_base_url' => $baseUrl,
    'sub2api_service_key' => $serviceKey,
    'sub2api_timeout_seconds' => (string) $timeout,
    'sub2api_image_async_enabled' => '0',
    'sub2api_gray_merchant_ids' => json_encode($grayMerchantIds, JSON_UNESCAPED_SLASHES),
], $adminId);
$runtime = $app->make(Sub2ApiClient::class)->runtimeStatus();
if ($enabled === '1' && (string) ($runtime['status'] ?? '') !== 'ready') {
    $settings->setMany(['sub2api_enabled' => '0'], $adminId);
    fwrite(STDERR, 'Sub2API settings were saved, but the runtime check failed: '
        . (string) ($runtime['message'] ?? 'unknown error')
        . '. The client was disabled automatically.' . PHP_EOL);
    exit(1);
}

echo implode('|', [
    'sub2api-client',
    $enabled === '1' ? 'enabled' : 'disabled',
    'gray=' . implode(',', $grayMerchantIds),
    'timeout=' . $timeout,
    'key_fingerprint=' . substr(hash('sha256', $serviceKey), 0, 16),
    'runtime=' . (string) ($runtime['status'] ?? 'unknown'),
    'models=' . (int) ($runtime['modelCount'] ?? 0),
    'changed=' . count($changed),
]) . PHP_EOL;
