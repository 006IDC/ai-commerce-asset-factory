<?php

declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class AdminSystemStatusService
{
    public function __construct(
        private readonly Sub2ApiClient $sub2Api,
        private readonly ComfyUiClient $comfyUi,
        private readonly WorkerMonitor $workers,
        private readonly SystemSettings $settings
    ) {
    }

    public function status(): array
    {
        $services = [];
        $sub2Api = $this->sub2Api->runtimeStatus();
        $services[] = $this->item(
            'AI 网关',
            (string) ($sub2Api['status'] ?? 'offline'),
            (string) ($sub2Api['message'] ?? ''),
            (string) ($sub2Api['status'] ?? '') === 'ready' ? '模型调用与目录同步可用。' : '模型调用可能失败，请检查 AI 网关连接。',
            '/admin/ai-settings'
        );
        $comfy = $this->comfyUi->runtimeStatus();
        $services[] = $this->item(
            '媒体工作流',
            (string) ($comfy['status'] ?? 'offline'),
            (string) ($comfy['message'] ?? ''),
            (string) ($comfy['status'] ?? '') === 'ready' ? '复杂套图与详情页工作流可用。' : '复杂媒体工作流可能不可用。',
            '/admin/creative-functions'
        );
        $pool = $this->workers->poolStatuses(10);
        $online = count(array_filter($pool, static fn (array $worker): bool => in_array((string) ($worker['health'] ?? ''), ['online', 'recent'], true)));
        $services[] = $this->item(
            '生成队列',
            $online > 0 ? 'ready' : 'offline',
            $online > 0 ? "{$online} 个图片任务处理进程在线。" : '没有检测到可用的图片任务处理进程。',
            $online > 0 ? '图片任务会正常继续处理。' : '新图片任务会停留在等待状态。',
            '/admin/worker'
        );
        try {
            Db::query('SELECT 1');
            $services[] = $this->item('业务数据库', 'ready', '数据库连接正常。', '商户、任务、账务与追溯数据可读写。');
        } catch (\Throwable) {
            $services[] = $this->item('业务数据库', 'offline', '数据库连接失败。', '管理后台与创作功能会受到影响。');
        }
        $storageDriver = trim($this->settings->get('storage_driver')) ?: 'local';
        $services[] = $this->item(
            '素材存储',
            'ready',
            $storageDriver === 'local' ? '使用本机存储并保留本地备份。' : '对象存储已配置。',
            '素材保存和下载配置可用。',
            '/admin/storage'
        );
        $smtpConfigured = trim($this->settings->get('smtp_host')) !== ''
            && trim($this->settings->get('smtp_from_email')) !== '';
        $services[] = $this->item(
            '邮件通知',
            $smtpConfigured ? 'ready' : 'disabled',
            $smtpConfigured ? '邮件服务已配置。' : '邮件服务尚未配置。',
            $smtpConfigured ? '验证码和系统通知可发送。' : '不影响创作，但邮件验证码和通知不可用。',
            '/admin/email'
        );
        $services[] = $this->item('缓存服务', 'disabled', '当前版本未单独启用 Redis。', '不影响当前系统运行。');
        return [
            'services' => $services,
            'healthy' => count(array_filter($services, static fn (array $item): bool => $item['status'] === 'ready')),
            'attention' => count(array_filter($services, static fn (array $item): bool => in_array($item['status'], ['offline', 'degraded'], true))),
            'checked_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function item(string $name, string $status, string $message, string $impact, string $href = ''): array
    {
        $status = in_array($status, ['ready', 'degraded', 'offline', 'disabled'], true) ? $status : 'offline';
        return [
            'name' => $name,
            'status' => $status,
            'label' => [
                'ready' => '运行正常',
                'degraded' => '部分可用',
                'offline' => '需要处理',
                'disabled' => '未启用',
            ][$status],
            'message' => $message,
            'impact' => $impact,
            'href' => $href,
        ];
    }
}
