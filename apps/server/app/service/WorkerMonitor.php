<?php

declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use Throwable;
use think\App;

final class WorkerMonitor
{
    private int $slot = 1;

    public function __construct(private readonly App $app)
    {
    }

    public function start(string $mode, int $slot = 1): void
    {
        $this->slot = max(1, min(10, $slot));
        $now = $this->now();
        $this->write([
            'slot' => $this->slot,
            'pid' => getmypid(),
            'mode' => $mode,
            'state' => 'idle',
            'started_at' => $now,
            'last_heartbeat_at' => $now,
            'last_job_no' => null,
            'last_job_status' => null,
            'last_job_at' => null,
            'last_error' => null,
        ]);
    }

    public function heartbeat(string $state, ?string $jobNo = null): void
    {
        $data = $this->read();
        $data['pid'] = getmypid();
        $data['state'] = $state;
        $data['last_heartbeat_at'] = $this->now();
        if ($jobNo !== null) {
            $data['last_job_no'] = $jobNo;
        }
        $this->write($data);
    }

    public function recordJob(string $jobNo, string $status, ?string $error = null): void
    {
        $data = $this->read();
        $data['state'] = 'idle';
        $data['last_heartbeat_at'] = $this->now();
        $data['last_job_no'] = $jobNo;
        $data['last_job_status'] = $status;
        $data['last_job_at'] = $this->now();
        $data['last_error'] = $error === null ? null : mb_strimwidth($error, 0, 500, '...');
        $this->write($data);
    }

    public function stop(): void
    {
        $data = $this->read();
        if ($data === []) {
            return;
        }
        $data['state'] = 'stopped';
        $data['last_heartbeat_at'] = $this->now();
        $this->write($data);
    }

    public function status(): array
    {
        $data = $this->read();
        if ($data === []) {
            return $this->emptyStatus();
        }

        $heartbeatAt = $this->timestamp((string) ($data['last_heartbeat_at'] ?? ''));
        $startedAt = $this->timestamp((string) ($data['started_at'] ?? ''));
        $age = $heartbeatAt === null ? null : max(0, time() - $heartbeatAt);
        $state = (string) ($data['state'] ?? 'unknown');
        $mode = (string) ($data['mode'] ?? 'daemon');
        $pid = (int) ($data['pid'] ?? 0);
        $threshold = $state === 'working' ? 600 : 15;
        $processAlive = $this->processAlive($pid);
        $online = $state !== 'stopped' && $age !== null && $age <= $threshold;
        $recentOnce = in_array($mode, ['once', 'drain'], true) && $state === 'stopped' && $age !== null && $age <= 120;

        if ($online) {
            $health = 'online';
            $healthLabel = '运行正常';
        } elseif ($recentOnce) {
            $health = 'recent';
            $healthLabel = '最近执行正常';
        } else {
            $health = 'stale';
            $healthLabel = '心跳超时';
        }

        return $data + [
            'health' => $health,
            'healthLabel' => $healthLabel,
            'stateLabel' => $this->stateLabel($state),
            'modeLabel' => $this->modeLabel($mode),
            'heartbeatAge' => $age,
            'heartbeatAgeLabel' => $this->durationLabel($age),
            'uptimeLabel' => $startedAt === null ? '-' : $this->durationLabel(max(0, time() - $startedAt)),
            'processAlive' => $processAlive,
            'command' => $this->daemonCommand(),
            'onceCommand' => $this->onceCommand(),
            'scheduledCommand' => $this->scheduledCommand(),
            'installCommand' => $this->installCommand(),
            'serviceName' => 'asset-factory-worker@1..10',
            'slot' => $this->slot,
        ];
    }

    public function poolStatuses(int $maxSlots = 10): array
    {
        $originalSlot = $this->slot;
        $statuses = [];
        for ($slot = 1; $slot <= max(1, min(10, $maxSlots)); $slot++) {
            $this->slot = $slot;
            $statuses[] = $this->status();
        }
        $this->slot = $originalSlot;
        return $statuses;
    }

    private function emptyStatus(): array
    {
        return [
            'health' => 'never',
            'healthLabel' => '尚未检测到',
            'state' => 'unknown',
            'stateLabel' => '未启动',
            'mode' => 'daemon',
            'modeLabel' => '常驻守护模式',
            'pid' => null,
            'started_at' => null,
            'last_heartbeat_at' => null,
            'last_job_no' => null,
            'last_job_status' => null,
            'last_job_at' => null,
            'last_error' => null,
            'heartbeatAge' => null,
            'heartbeatAgeLabel' => '-',
            'uptimeLabel' => '-',
            'processAlive' => null,
            'command' => $this->daemonCommand(),
            'onceCommand' => $this->onceCommand(),
            'scheduledCommand' => $this->scheduledCommand(),
            'installCommand' => $this->installCommand(),
            'serviceName' => 'asset-factory-worker@1..10',
            'slot' => $this->slot,
        ];
    }

    private function stateLabel(string $state): string
    {
        return [
            'idle' => '空闲等待',
            'checking' => '正在检查队列',
            'working' => '正在处理任务',
            'paused' => '等待并发名额',
            'stopped' => '已停止',
        ][$state] ?? '状态未知';
    }

    private function modeLabel(string $mode): string
    {
        return match ($mode) {
            'once' => '定时单任务模式',
            'drain' => '宝塔计划任务模式',
            default => '常驻守护模式',
        };
    }

    private function daemonCommand(): string
    {
        return 'cd ' . escapeshellarg(rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR)) . ' && php think jobs:work';
    }

    private function onceCommand(): string
    {
        return 'cd ' . escapeshellarg(rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR)) . ' && php think jobs:work --once';
    }

    private function scheduledCommand(): string
    {
        $think = $this->app->getRootPath() . 'think';
        return 'flock -n /tmp/asset-factory-worker.lock php ' . escapeshellarg($think) . ' jobs:work --drain';
    }

    private function installCommand(): string
    {
        return 'sudo bash ' . escapeshellarg($this->app->getRootPath() . 'scripts/install-worker-service.sh');
    }

    private function durationLabel(?int $seconds): string
    {
        if ($seconds === null) {
            return '-';
        }
        if ($seconds < 60) {
            return $seconds . ' 秒';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60) . ' 分钟';
        }
        if ($seconds < 86400) {
            return floor($seconds / 3600) . ' 小时 ' . floor(($seconds % 3600) / 60) . ' 分钟';
        }
        return floor($seconds / 86400) . ' 天 ' . floor(($seconds % 86400) / 3600) . ' 小时';
    }

    private function processAlive(int $pid): ?bool
    {
        if ($pid <= 0 || !function_exists('posix_kill')) {
            return null;
        }

        return @posix_kill($pid, 0);
    }

    private function timestamp(string $value): ?int
    {
        if ($value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    private function read(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function write(array $data): void
    {
        $path = $this->path();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }
        $temporary = $path . '.tmp-' . getmypid();
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($payload === false || file_put_contents($temporary, $payload . PHP_EOL, LOCK_EX) === false) {
            return;
        }
        @chmod($temporary, 0664);
        @rename($temporary, $path);
    }

    private function path(): string
    {
        return $this->app->getRootPath() . 'runtime/worker-status-' . $this->slot . '.json';
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
