<?php

declare(strict_types=1);

namespace app\support;

use app\service\CreativeProductService;
use app\service\MediaWorkflowTemplateService;
use app\service\PromptTemplateService;
use app\service\SchemaUpgrade;
use PDO;
use RuntimeException;
use Throwable;
use think\App;
use think\facade\Db;

final class Installer
{
    public function __construct(private readonly App $app)
    {
    }

    public function isInstalled(): bool
    {
        if (is_file($this->lockPath())) {
            return true;
        }
        if (!is_file($this->envPath())) {
            return false;
        }

        try {
            if ((int) Db::name('admin_users')->count() > 0) {
                try {
                    $this->writeLock(['detected_existing_installation' => true]);
                } catch (Throwable) {
                    // Existing administrator data is authoritative even if the lock cannot be refreshed.
                }
                return true;
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    public function requirements(): array
    {
        $root = $this->app->getRootPath();
        $runtime = $root . 'runtime';
        $storage = $root . 'public/storage';
        $envWritable = is_file($this->envPath()) ? is_writable($this->envPath()) : is_writable($root);

        return [
            ['label' => 'PHP 8.0 或更高版本', 'ok' => version_compare(PHP_VERSION, '8.0.0', '>=')],
            ['label' => 'PDO 扩展', 'ok' => extension_loaded('pdo')],
            ['label' => 'cURL 扩展', 'ok' => extension_loaded('curl')],
            ['label' => 'Mbstring 扩展', 'ok' => extension_loaded('mbstring')],
            ['label' => 'OpenSSL 扩展', 'ok' => extension_loaded('openssl')],
            ['label' => 'Fileinfo 扩展', 'ok' => extension_loaded('fileinfo')],
            ['label' => 'GD 图像处理扩展', 'ok' => extension_loaded('gd')],
            ['label' => 'runtime 目录可写', 'ok' => $this->ensureWritableDirectory($runtime)],
            ['label' => 'public/storage 目录可写', 'ok' => $this->ensureWritableDirectory($storage)],
            ['label' => '.env 配置可写', 'ok' => $envWritable],
        ];
    }

    public function canInstall(): bool
    {
        foreach ($this->requirements() as $requirement) {
            if (!$requirement['ok']) {
                return false;
            }
        }

        return true;
    }

    public function install(array $values, bool $secureCookie): void
    {
        if ($this->isInstalled()) {
            throw new RuntimeException('系统已安装，禁止重复执行安装。');
        }
        if (!$this->canInstall()) {
            throw new RuntimeException('服务器环境未通过检查，请先修复不满足的项目。');
        }

        $driver = (string) ($values['db_driver'] ?? 'mysql');
        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new RuntimeException('不支持的数据库类型。');
        }
        $requiredExtension = ['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'sqlite' => 'pdo_sqlite'][$driver];
        if (!extension_loaded($requiredExtension)) {
            throw new RuntimeException("当前 PHP 未安装 {$requiredExtension} 扩展。");
        }

        $adminName = trim((string) ($values['admin_name'] ?? ''));
        $adminEmail = strtolower(trim((string) ($values['admin_email'] ?? '')));
        $adminPassword = (string) ($values['admin_password'] ?? '');
        if (mb_strlen($adminName) < 2 || mb_strlen($adminName) > 80) {
            throw new RuntimeException('管理员名称必须为 2 至 80 个字符。');
        }
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('管理员邮箱格式不正确。');
        }
        if (strlen($adminPassword) < 12 || !password_is_strong($adminPassword)) {
            throw new RuntimeException('管理员密码至少 12 位，并同时包含字母和数字。');
        }

        $database = $this->databaseSettings($driver, $values);
        $pdo = $this->connect($database);
        $envExisted = is_file($this->envPath());
        $previousEnv = $envExisted ? (string) file_get_contents($this->envPath()) : null;

        try {
            $this->writeEnv($database, $adminEmail, $secureCookie);
            $schemaPath = $this->app->getRootPath() . "database/schema.{$driver}.sql";
            $schema = file_get_contents($schemaPath);
            if ($schema === false) {
                throw new RuntimeException('数据库结构文件不存在。');
            }
            $this->executeSchema($pdo, $schema, $driver);
            $existingAdmins = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
            if ($existingAdmins > 0) {
                throw new RuntimeException('目标数据库已存在管理员数据，请使用空数据库。');
            }

            $now = Web::now();
            $statement = $pdo->prepare('INSERT INTO admin_users (name,email,password_hash,role,status,created_at,updated_at) VALUES (:name,:email,:password_hash,:role,:status,:created_at,:updated_at)');
            $statement->execute([
                'name' => $adminName,
                'email' => $adminEmail,
                'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
                'role' => 'super_admin',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $adminLookup = $pdo->prepare('SELECT id FROM admin_users WHERE email = :email ORDER BY id DESC LIMIT 1');
            $adminLookup->execute(['email' => $adminEmail]);
            $adminId = (int) $adminLookup->fetchColumn();
            if ($adminId <= 0) {
                throw new RuntimeException('管理员创建后无法初始化首次配置向导。');
            }
            $onboarding = $pdo->prepare(
                'INSERT INTO admin_onboarding_progress '
                . '(admin_id,status,current_step,step_state_json,started_at,completed_at,created_at,updated_at) '
                . 'VALUES (:admin_id,:status,:current_step,:step_state_json,NULL,NULL,:created_at,:updated_at)'
            );
            $onboarding->execute([
                'admin_id' => $adminId,
                'status' => 'pending',
                'current_step' => 'welcome',
                'step_state_json' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->reloadRuntimeConfiguration();
            $this->initializeApplication();
            $this->writeLock(['driver' => $driver, 'admin_email' => $adminEmail]);
        } catch (Throwable $exception) {
            if ($envExisted && $previousEnv !== null) {
                file_put_contents($this->envPath(), $previousEnv, LOCK_EX);
            } elseif (is_file($this->envPath())) {
                unlink($this->envPath());
            }
            throw $exception;
        }
    }

    /**
     * Apply the one canonical post-schema initialization sequence used by
     * browser installation, CLI installation and later upgrades.
     *
     * @return list<string>
     */
    public function initializeApplication(): array
    {
        $changes = $this->app->make(SchemaUpgrade::class)->run();
        $this->app->make(PromptTemplateService::class)->ensureBuiltIns();
        if ($this->app->make(MediaWorkflowTemplateService::class)->ensureBuiltIns() > 0) {
            $changes[] = 'media_workflow_templates.built_ins';
        }
        array_push($changes, ...$this->app->make(CreativeProductService::class)->ensureSeeded());

        return array_values(array_unique($changes));
    }

    public function writeLock(array $details = []): void
    {
        $runtime = dirname($this->lockPath());
        if (!$this->ensureWritableDirectory($runtime)) {
            throw new RuntimeException('无法写入安装锁文件。');
        }
        $payload = json_encode([
            'installed_at' => Web::now(),
            'app' => 'ai-commerce-asset-factory',
        ] + $details, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (file_put_contents($this->lockPath(), $payload . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('无法写入安装锁文件。');
        }
    }

    private function databaseSettings(string $driver, array $values): array
    {
        if ($driver === 'sqlite') {
            return [
                'driver' => 'sqlite',
                'database' => 'database/asset_factory.sqlite',
            ];
        }

        $host = trim((string) ($values['db_host'] ?? '127.0.0.1'));
        $port = (int) ($values['db_port'] ?? ($driver === 'mysql' ? 3306 : 5432));
        $name = trim((string) ($values['db_name'] ?? ''));
        $user = trim((string) ($values['db_user'] ?? ''));
        $password = (string) ($values['db_password'] ?? '');
        if ($host === '' || $name === '' || $user === '' || $port < 1 || $port > 65535) {
            throw new RuntimeException('请完整填写数据库连接信息。');
        }
        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $host)
            || !preg_match('/^[A-Za-z0-9_$-]+$/', $name)
            || !preg_match('/^[A-Za-z0-9._@-]+$/', $user)
            || str_contains($password, "\n")
            || str_contains($password, "\r")
            || strlen($password) > 500) {
            throw new RuntimeException('数据库连接信息格式不正确。');
        }

        return compact('driver', 'host', 'port', 'name', 'user', 'password');
    }

    private function connect(array $database): PDO
    {
        try {
            if ($database['driver'] === 'sqlite') {
                $path = $this->app->getRootPath() . $database['database'];
                if (!$this->ensureWritableDirectory(dirname($path))) {
                    throw new RuntimeException('SQLite 数据库目录不可写。');
                }
                $pdo = new PDO('sqlite:' . $path);
                $pdo->exec('PRAGMA foreign_keys = ON');
            } elseif ($database['driver'] === 'pgsql') {
                $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $database['host'], $database['port'], $database['name']);
                $pdo = new PDO($dsn, $database['user'], $database['password']);
            } else {
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $database['host'], $database['port'], $database['name']);
                $pdo = new PDO($dsn, $database['user'], $database['password']);
            }
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $pdo;
        } catch (Throwable $exception) {
            throw new RuntimeException('数据库连接失败：' . $exception->getMessage());
        }
    }

    private function writeEnv(array $database, string $adminEmail, bool $secureCookie): void
    {
        $lines = [
            'APP_DEBUG = false',
            'APP_TIMEZONE = Asia/Shanghai',
            'APP_KEY = ' . $this->quoteEnv('base64:' . base64_encode(random_bytes(32))),
            '',
            'DB_DRIVER = ' . $database['driver'],
        ];
        if ($database['driver'] === 'sqlite') {
            $lines[] = 'DB_DATABASE = ' . $this->quoteEnv($database['database']);
        } else {
            $lines[] = 'DB_HOST = ' . $this->quoteEnv($database['host']);
            $lines[] = 'DB_NAME = ' . $this->quoteEnv($database['name']);
            $lines[] = 'DB_USER = ' . $this->quoteEnv($database['user']);
            $lines[] = 'DB_PASS = ' . $this->quoteEnv($database['password']);
            $lines[] = 'DB_PORT = ' . (int) $database['port'];
        }
        $lines = array_merge($lines, [
            'DB_CHARSET = ' . ($database['driver'] === 'mysql' ? 'utf8mb4' : 'utf8'),
            '',
            'DEFAULT_LANG = zh-cn',
            'COOKIE_SECURE = ' . ($secureCookie ? 'true' : 'false'),
            'SESSION_EXPIRE = 7200',
            '',
            'ADMIN_EMAIL = ' . $this->quoteEnv($adminEmail),
            'ADMIN_INITIAL_PASSWORD =',
            '',
            'DEFAULT_IMAGE_MODEL = gpt-image-2',
            'DEFAULT_IMAGE_EDIT_MODE = auto',
            'DEEPSEEK_TEXT_MODEL = deepseek-v4-flash',
            'ZHIPU_VISION_MODEL = glm-4.6v',
            'DEFAULT_VISION_MODEL = glm-4.6v',
            'AI_ROUTING_MODE = capability',
            'AI_EXECUTION_MODE = native',
            'AI_NATIVE_CAPABILITIES = ["safety_text","safety_vision","product_vision","content_planner"]',
            'AI_ROUTING_GRAY_MERCHANT_IDS = []',
            'IMAGE_GENERATION_CREDITS = 8',
            'CANVAS_EDIT_CREDITS = 8',
            'PRODUCT_RECOGNITION_CREDITS = 0',
            'GEETEST_CAPTCHA_ID =',
            'GEETEST_PRIVATE_KEY =',
            'CONTENT_SAFETY_ENABLED = 1',
            'CONTENT_SAFETY_EXTERNAL_ENABLED = 1',
            'CONTENT_SAFETY_FAIL_CLOSED = 1',
            'CONTENT_SAFETY_MODEL = deepseek-v4-flash',
            'CONTENT_SAFETY_FALLBACK_MODEL = deepseek-v4-pro',
            'CONTENT_SAFETY_PENALTY_CREDITS = 5',
            'COMFYUI_ENABLED = 0',
            '',
            'STORAGE_DRIVER = local',
            'STORAGE_ENDPOINT =',
            'STORAGE_REGION =',
            'STORAGE_BUCKET =',
            'STORAGE_ACCESS_KEY =',
            'STORAGE_SECRET_KEY =',
            'STORAGE_PUBLIC_URL =',
            'STORAGE_PATH_PREFIX = generated',
            'STORAGE_LOCAL_BACKUP_ENABLED = 1',
            '',
            'SMTP_HOST =',
            'SMTP_PORT = 465',
            'SMTP_ENCRYPTION = ssl',
            'SMTP_USERNAME =',
            'SMTP_PASSWORD =',
            'SMTP_FROM_EMAIL =',
            'SMTP_FROM_NAME = ' . $this->quoteEnv('AI 电商素材工厂'),
        ]);

        $temporary = $this->envPath() . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('无法写入 .env 配置文件。');
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $this->envPath())) {
            @unlink($temporary);
            throw new RuntimeException('无法替换 .env 配置文件。');
        }
    }

    private function quoteEnv(string $value): string
    {
        return '"' . addcslashes($value, "\\\"") . '"';
    }

    /**
     * The browser installer writes .env after the current HTTP request has
     * already booted. Reload it and replace the database configuration before
     * ORM-backed upgrades are allowed to run in that same request.
     */
    private function reloadRuntimeConfiguration(): void
    {
        $this->app->make('env')->load($this->envPath());
        $databaseConfig = require $this->app->getConfigPath() . 'database.php';
        if (!is_array($databaseConfig)) {
            throw new RuntimeException('数据库运行配置无法加载。');
        }
        $this->app->make('config')->set($databaseConfig, 'database');
        $this->app->make('db')->connect(null, true);
    }

    private function executeSchema(PDO $pdo, string $schema, string $driver): void
    {
        if ($driver !== 'mysql') {
            $pdo->exec($schema);
            return;
        }

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $schema) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
    }

    private function envPath(): string
    {
        return $this->app->getRootPath() . '.env';
    }

    private function lockPath(): string
    {
        return $this->app->getRootPath() . 'runtime/install.lock';
    }

    private function ensureWritableDirectory(string $directory): bool
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        return is_writable($directory);
    }
}
