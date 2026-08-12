<?php

declare(strict_types=1);

use app\service\Sub2ApiModelSyncService;
use think\App;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['admin-id::']);
$adminId = max(1, (int) ($options['admin-id'] ?? 1));
$app = (new App(dirname(__DIR__)))->initialize();
$summary = $app->make(Sub2ApiModelSyncService::class)->sync($adminId);

echo implode('|', [
    'sub2api-model-sync',
    'batch=' . (int) ($summary['batch_id'] ?? 0),
    'discovered=' . count((array) ($summary['discovered_aliases'] ?? [])),
    'new=' . count((array) ($summary['new_aliases'] ?? [])),
    'missing=' . count((array) ($summary['missing_aliases'] ?? [])),
]) . PHP_EOL;
