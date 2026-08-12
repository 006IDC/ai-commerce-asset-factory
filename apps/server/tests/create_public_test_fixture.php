<?php

declare(strict_types=1);

use app\support\Web;
use think\App;
use think\facade\Db;

if ((string) getenv('ASSET_FACTORY_ALLOW_TEST_FIXTURE') !== '1') {
    fwrite(STDERR, "Refusing to create a test merchant without ASSET_FACTORY_ALLOW_TEST_FIXTURE=1.\n");
    exit(2);
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$app = (new App($root))->initialize();
if ((string) env('DB_DRIVER', 'sqlite') !== 'sqlite') {
    fwrite(STDERR, "The public CI fixture is restricted to SQLite.\n");
    exit(2);
}

$database = (string) env('DB_DATABASE', 'database/asset_factory.sqlite');
$database = str_starts_with($database, DIRECTORY_SEPARATOR)
    ? $database
    : $root . DIRECTORY_SEPARATOR . ltrim($database, DIRECTORY_SEPARATOR);
$rootReal = realpath($root);
$databaseDirectory = realpath(dirname($database));
if ($rootReal === false || $databaseDirectory === false
    || !str_starts_with($databaseDirectory . DIRECTORY_SEPARATOR, $rootReal . DIRECTORY_SEPARATOR)
) {
    fwrite(STDERR, "The public CI fixture database must be inside the test application directory.\n");
    exit(2);
}

$existing = Db::name('merchant_users')->where('email', 'merchant@example.com')->find();
if ($existing) {
    echo "Public SQLite test fixture already exists.\n";
    exit(0);
}

$now = Web::now();
Db::transaction(static function () use ($now): void {
    $merchantId = (int) Db::name('merchants')->insertGetId([
        'name' => 'Example Merchant',
        'slug' => 'example-merchant-' . bin2hex(random_bytes(5)),
        'status' => 'active',
        'plan_code' => 'test',
        'credit_balance' => 10000,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $userId = (int) Db::name('merchant_users')->insertGetId([
        'merchant_id' => $merchantId,
        'name' => 'Example User',
        'email' => 'merchant@example.com',
        'password_hash' => password_hash('ExampleOnly!2026', PASSWORD_DEFAULT),
        'role' => 'owner',
        'status' => 'active',
        'last_login_at' => $now,
        'last_login_ip' => '127.0.0.1',
        'preferred_generation_platform' => 'taobao',
        'preferred_generation_language' => 'zh-CN',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    Db::name('merchants')->where('id', $merchantId)->update([
        'owner_user_id' => $userId,
        'updated_at' => $now,
    ]);
});

echo "Public SQLite test fixture created.\n";
