<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\support\RemoteMediaUrlGuard;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$rejects = static function (string $url) use ($assert): void {
    try {
        RemoteMediaUrlGuard::publicHttpsTarget($url);
        $assert(false, 'Expected URL to be rejected: ' . $url);
    } catch (RuntimeException) {
        $assert(true, 'URL rejected');
    }
};

$target = RemoteMediaUrlGuard::publicHttpsTarget('https://8.8.8.8/media/result.png');
$assert($target['url'] === 'https://8.8.8.8/media/result.png', 'Public HTTPS URL must be preserved.');
$assert($target['resolve'] === ['8.8.8.8:443:8.8.8.8'], 'Public address must be pinned for cURL.');

foreach ([
    'http://8.8.8.8/media.png',
    'https://127.0.0.1/media.png',
    'https://10.0.0.8/media.png',
    'https://169.254.169.254/latest/meta-data',
    'https://[::1]/media.png',
    'https://user:pass@8.8.8.8/media.png',
    'https://8.8.8.8:8443/media.png',
    'https://8.8.8.8/media.png#fragment',
] as $url) {
    $rejects($url);
}

putenv('ASSET_FACTORY_REMOTE_MEDIA_TEST_ORIGIN=http://127.0.0.1:8090');
$localTestTarget = RemoteMediaUrlGuard::publicHttpsTarget('http://127.0.0.1:8090/mock-video.mp4');
$assert($localTestTarget['resolve'] === ['127.0.0.1:8090:127.0.0.1'], 'Explicit CLI smoke origin must be DNS pinned.');
$rejects('http://127.0.0.1:8091/mock-video.mp4');
putenv('ASSET_FACTORY_REMOTE_MEDIA_TEST_ORIGIN');

$worker = file_get_contents(dirname(__DIR__) . '/app/command/WorkGenerationJobs.php') ?: '';
$storage = file_get_contents(dirname(__DIR__) . '/app/service/AssetStorage.php') ?: '';
$assert(substr_count($worker, 'CURLOPT_FOLLOWLOCATION => false') >= 1, 'Image worker must not follow unvalidated redirects.');
$assert(substr_count($storage, 'CURLOPT_FOLLOWLOCATION => false') >= 2, 'Media storage downloads must not follow unvalidated redirects.');
$assert(str_contains($worker, "CURLOPT_RESOLVE => \$target['resolve']"), 'Image worker must pin validated DNS.');
$assert(substr_count($storage, "CURLOPT_RESOLVE => \$target['resolve']") >= 2, 'Media storage downloads must pin validated DNS.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Remote media URL guard tests completed successfully.\n");
