<?php

declare(strict_types=1);

namespace app\command;

use app\service\AdminOnboardingService;
use app\support\Web;
use app\support\Installer;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

final class Install extends Command
{
    protected function configure(): void
    {
        $this->setName('app:install')->setDescription('Create database tables and the initial administrator');
    }

    protected function execute(Input $input, Output $output): int
    {
        $driver = (string) env('DB_DRIVER', 'sqlite');
        if (!in_array($driver, ['sqlite', 'pgsql', 'mysql'], true)) {
            $output->error('DB_DRIVER must be sqlite, pgsql or mysql.');
            return 1;
        }

        $schema = $this->app->getRootPath() . "database/schema.{$driver}.sql";
        if ($driver === 'sqlite') {
            $database = (string) env('DB_DATABASE', $this->app->getRootPath() . 'database/asset_factory.sqlite');
            if (!str_starts_with($database, DIRECTORY_SEPARATOR)) {
                $database = $this->app->getRootPath() . ltrim($database, '/');
            }
            $directory = dirname($database);
            if (!is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
        }

        $database = Db::connect()->connect();
        $schemaSql = (string) file_get_contents($schema);
        if ($driver === 'mysql') {
            foreach (preg_split('/;\s*(?:\r?\n|$)/', $schemaSql) ?: [] as $statement) {
                $statement = trim($statement);
                if ($statement !== '') {
                    $database->exec($statement);
                }
            }
        } else {
            $database->exec($schemaSql);
        }
        $email = strtolower(trim((string) env('ADMIN_EMAIL', 'admin@example.com')));
        $existing = Db::name('admin_users')->where('email', $email)->find();
        if (!$existing) {
            $password = trim((string) env('ADMIN_INITIAL_PASSWORD', ''));
            $generated = false;
            if ($password === '') {
                $password = rtrim(strtr(base64_encode(random_bytes(18)), '+/', 'AZ'), '=');
                $generated = true;
            }
            if (strlen($password) < 12) {
                $output->error('ADMIN_INITIAL_PASSWORD must contain at least 12 characters.');
                return 1;
            }

            $now = Web::now();
            $adminId = (int) Db::name('admin_users')->insertGetId([
                'name' => '系统管理员',
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'super_admin',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->app->make(AdminOnboardingService::class)->createPending($adminId);
            $output->writeln("<info>Administrator created: {$email}</info>");
            if ($generated) {
                $output->writeln("<comment>Generated password (shown once): {$password}</comment>");
            }
        } else {
            $output->writeln("<comment>Administrator already exists: {$email}</comment>");
        }

        $output->writeln('<info>Database installation completed.</info>');
        $installer = $this->app->make(Installer::class);
        $installer->initializeApplication();
        $installer->writeLock(['driver' => $driver, 'installed_by' => 'cli']);
        return 0;
    }
}
