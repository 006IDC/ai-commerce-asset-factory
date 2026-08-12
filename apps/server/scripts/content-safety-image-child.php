#!/usr/bin/env php
<?php

declare(strict_types=1);

use app\service\ContentSafetyService;
use app\service\ContentSafetyUnavailableException;
use app\service\ContentSafetyViolationException;
use think\App;

require dirname(__DIR__) . '/vendor/autoload.php';

$input = stream_get_contents(STDIN);
$payload = is_string($input) ? @unserialize($input, ['allowed_classes' => false]) : null;
if (!is_array($payload)) {
    fwrite(STDOUT, json_encode(['ok' => false, 'type' => 'error', 'message' => '图片安全检查子进程输入无效。'], JSON_UNESCAPED_UNICODE));
    exit(2);
}

$app = (new App(dirname(__DIR__)))->initialize();
$service = $app->make(ContentSafetyService::class);

try {
    $result = $service->enforceImage(
        (array) ($payload['merchant'] ?? []),
        (array) ($payload['user'] ?? []),
        (array) ($payload['image'] ?? []),
        (string) ($payload['source'] ?? ''),
        (string) ($payload['ip_address'] ?? '')
    );
    $output = ['ok' => true, 'result' => $result];
    $exitCode = 0;
} catch (Throwable $exception) {
    $output = [
        'ok' => false,
        'type' => $exception instanceof ContentSafetyViolationException ? 'violation'
            : ($exception instanceof ContentSafetyUnavailableException ? 'unavailable' : 'error'),
        'message' => $exception->getMessage(),
    ];
    $exitCode = 1;
}

fwrite(STDOUT, json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}');
exit($exitCode);
