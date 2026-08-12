<?php

declare(strict_types=1);

use think\App;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(2);
}

$appRoot = dirname(__DIR__);
$output = '';
$backupRoot = rtrim((string) getenv('ASSET_FACTORY_BACKUP_ROOT'), '/');
if ($backupRoot === '') {
    $backupRoot = '/root/asset-factory-backups';
}
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--app-root=')) {
        $appRoot = rtrim(substr($argument, strlen('--app-root=')), '/');
    } elseif (str_starts_with($argument, '--output=')) {
        $output = rtrim(substr($argument, strlen('--output=')), '/');
    }
}
if (!is_dir($backupRoot) && !mkdir($backupRoot, 0700, true)) {
    fwrite(STDERR, "Backup root cannot be created.\n");
    exit(2);
}
$output = $output !== '' ? $output : $backupRoot . '/scheduled-' . date('Ymd-His');
$resolvedBackupRoot = realpath($backupRoot);
$outputName = basename($output);
if ($output === ''
    || $resolvedBackupRoot === false
    || realpath(dirname($output)) !== $resolvedBackupRoot
    || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]+$/', $outputName)
) {
    fwrite(STDERR, "Usage: php scripts/backup-production.php --output={$backupRoot}/pre-VERSION-TIMESTAMP [--app-root=/opt/asset-factory/apps/server]\n");
    exit(2);
}
$output = $resolvedBackupRoot . '/' . $outputName;
if (!is_file($appRoot . '/vendor/autoload.php') || !is_file($appRoot . '/.env')) {
    fwrite(STDERR, "Application root or .env is missing.\n");
    exit(2);
}
if (file_exists($output) || !mkdir($output, 0700, true)) {
    fwrite(STDERR, "Backup output already exists or cannot be created.\n");
    exit(2);
}

try {
    require $appRoot . '/vendor/autoload.php';
    (new App($appRoot))->initialize();

    if (!copy($appRoot . '/.env', $output . '/.env')) {
        throw new RuntimeException('Failed to copy .env.');
    }
    chmod($output . '/.env', 0600);
    runCommand([
        'tar', '--dereference', '-czf', $output . '/application-before.tar.gz',
        '--exclude=server/runtime', '--exclude=server/public/storage',
        '-C', dirname($appRoot), basename($appRoot),
    ]);
    chmod($output . '/application-before.tar.gz', 0600);
    runCommand(['tar', '-tzf', $output . '/application-before.tar.gz'], null, true);

    $default = (string) config('database.default');
    $connection = (array) config('database.connections.' . $default);
    $databaseFile = $output . '/database-before.dump';
    if (($connection['type'] ?? '') === 'pgsql') {
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['PGPASSWORD'] = (string) ($connection['password'] ?? '');
        runCommand([
            'pg_dump', '-h', (string) ($connection['hostname'] ?? '127.0.0.1'),
            '-p', (string) ($connection['hostport'] ?? '5432'),
            '-U', (string) ($connection['username'] ?? ''),
            '-F', 'c', '-f', $databaseFile,
            (string) ($connection['database'] ?? ''),
        ], $environment);
        runCommand(['pg_restore', '-l', $databaseFile], null, true);
    } elseif (($connection['type'] ?? '') === 'sqlite') {
        $source = (string) ($connection['database'] ?? '');
        if ($source === '' || !copy($source, $databaseFile)) {
            throw new RuntimeException('Failed to copy SQLite database.');
        }
    } else {
        throw new RuntimeException('Unsupported production database driver: ' . ($connection['type'] ?? $default));
    }
    chmod($databaseFile, 0600);

    $storageRoot = $appRoot . '/public/storage';
    $manifest = [];
    if (is_dir($storageRoot)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($storageRoot, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $manifest[] = substr($path, strlen($storageRoot) + 1)
                . "\t" . $file->getSize() . "\t" . $file->getMTime();
        }
    }
    sort($manifest, SORT_STRING);
    if (is_dir($storageRoot)) {
        $storageArchive = $output . '/storage-before.tar.gz';
        runCommand([
            'tar', '--dereference', '-czf', $storageArchive,
            '-C', dirname($storageRoot), basename($storageRoot),
        ]);
        chmod($storageArchive, 0600);
        runCommand(['tar', '-tzf', $storageArchive], null, true);
    }
    file_put_contents($output . '/storage-files.txt', implode("\n", $manifest) . ($manifest !== [] ? "\n" : ''));
    file_put_contents($output . '/recovery-manifest.txt', implode("\n", [
        'created_at=' . date(DATE_ATOM),
        'app_root=' . $appRoot,
        'version=' . trim((string) file_get_contents($appRoot . '/VERSION')),
        'database_driver=' . $default,
        'storage_file_count=' . count($manifest),
    ]) . "\n");
    chmod($output . '/storage-files.txt', 0600);
    chmod($output . '/recovery-manifest.txt', 0600);

    $checksums = [];
    foreach (new DirectoryIterator($output) as $file) {
        if ($file->isFile() && $file->getFilename() !== 'SHA256SUMS') {
            $checksums[] = hash_file('sha256', $file->getPathname()) . '  ' . $file->getFilename();
        }
    }
    sort($checksums, SORT_STRING);
    file_put_contents($output . '/SHA256SUMS', implode("\n", $checksums) . "\n");
    chmod($output . '/SHA256SUMS', 0600);

    echo json_encode([
        'ok' => true,
        'output' => $output,
        'version' => trim((string) file_get_contents($appRoot . '/VERSION')),
        'database_bytes' => filesize($databaseFile),
        'storage_files' => count($manifest),
        'checksums' => count($checksums),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'message' => $exception->getMessage(),
        'incomplete_output' => $output,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

function runCommand(array $command, ?array $environment = null, bool $discardStdout = false): void
{
    $pipes = [];
    $process = proc_open($command, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start backup command.');
    }
    $stdout = $discardStdout ? '' : stream_get_contents($pipes[1]);
    if ($discardStdout) {
        while (!feof($pipes[1])) {
            fread($pipes[1], 8192);
        }
    }
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException(trim((string) $stderr) ?: trim((string) $stdout) ?: 'Backup command failed.');
    }
}
