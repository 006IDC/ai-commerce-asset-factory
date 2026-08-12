<?php

declare(strict_types=1);

use app\service\ContentSafetyService;
use think\App;
use think\facade\Db;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$app = (new App($root))->initialize();
$merchant = Db::name('merchants')->order('id', 'asc')->find();
$user = $merchant ? Db::name('merchant_users')->where('merchant_id', (int) $merchant['id'])->order('id', 'asc')->find() : null;
if (!$merchant || !$user) {
    throw new RuntimeException('Content-safety subprocess regression requires a merchant and user fixture.');
}

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
if (!is_string($png)) {
    throw new RuntimeException('Unable to decode the content-safety image fixture.');
}

$settingKey = 'content_safety_external_enabled';
$originalSetting = Db::name('system_settings')->where('setting_key', $settingKey)->find();
$now = date('Y-m-d H:i:s');
if ($originalSetting) {
    Db::name('system_settings')->where('setting_key', $settingKey)->update([
        'setting_value' => '0', 'is_secret' => 0, 'updated_at' => $now,
    ]);
} else {
    Db::name('system_settings')->insert([
        'setting_key' => $settingKey, 'setting_value' => '0', 'is_secret' => 0,
        'updated_by' => null, 'created_at' => $now, 'updated_at' => $now,
    ]);
}

try {
    // This regression verifies subprocess isolation, de-duplication and parent
    // DB health. Force deterministic local moderation so a configured external
    // provider outage cannot turn an isolation test into an integration test.
    $service = $app->make(ContentSafetyService::class);
    $result = $service->enforceImages(
        $merchant,
        $user,
        [
            ['mime' => 'image/png', 'content' => $png, 'filename' => 'safety-a.png'],
            ['mime' => 'image/png', 'content' => $png . "\0", 'filename' => 'safety-b.png'],
        ],
        'content_safety_subprocess_regression',
        '127.0.0.1',
        2
    );
} finally {
    if ($originalSetting) {
        Db::name('system_settings')->where('setting_key', $settingKey)->update([
            'setting_value' => $originalSetting['setting_value'],
            'is_secret' => $originalSetting['is_secret'],
            'updated_by' => $originalSetting['updated_by'],
            'created_at' => $originalSetting['created_at'],
            'updated_at' => $originalSetting['updated_at'],
        ]);
    } else {
        Db::name('system_settings')->where('setting_key', $settingKey)->delete();
    }
}

if (count((array) ($result['checks'] ?? [])) !== 2) {
    throw new RuntimeException('Isolated content-safety subprocesses did not return both image checks.');
}
if ((int) Db::query('SELECT 1 AS healthy')[0]['healthy'] !== 1) {
    throw new RuntimeException('Parent database connection was not usable after isolated image checks.');
}

echo "Content-safety subprocess isolation regression completed successfully.\n";
