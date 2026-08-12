<?php

declare(strict_types=1);

use app\service\SystemSettings;
use app\service\VideoGenerationQueue;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = (new App(dirname(__DIR__)))->initialize();
$settings = $app->make(SystemSettings::class);
$queue = $app->make(VideoGenerationQueue::class);
$adminId = (int) Db::name('admin_users')->order('id', 'asc')->value('id');
if ($adminId <= 0) {
    throw new RuntimeException('Video reference URL test requires an administrator record.');
}

$method = new ReflectionMethod(VideoGenerationQueue::class, 'absolutePublicUrl');
Db::startTrans();
try {
    $settings->setMany(['app_public_url' => 'https://studio.example.com'], $adminId);

    $relative = $method->invoke($queue, '/storage/generated/source/example.png');
    if ($relative !== 'https://studio.example.com/storage/generated/source/example.png') {
        throw new RuntimeException('Relative video reference image did not resolve against APP_PUBLIC_URL.');
    }

    $absolute = $method->invoke($queue, 'https://cdn.example.com/product.png');
    if ($absolute !== 'https://cdn.example.com/product.png') {
        throw new RuntimeException('Absolute video reference URL was unexpectedly changed.');
    }

    if ($method->invoke($queue, 'storage/generated/source/example.png') !== ''
        || $method->invoke($queue, 'javascript:alert(1)') !== '') {
        throw new RuntimeException('Invalid video reference paths were not rejected.');
    }

    echo "[ok] video reference URLs resolve through the configured public application URL\n";
} finally {
    Db::rollback();
}
