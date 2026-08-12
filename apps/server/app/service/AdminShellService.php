<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use think\facade\Db;

final class AdminShellService
{
    public function navigation(string $currentPath): array
    {
        $groups = [
            ['label' => '工作台', 'items' => [
                ['label' => '运营工作台', 'icon' => 'layout-dashboard', 'href' => '/admin'],
                ['label' => '生成任务', 'icon' => 'list-checks', 'href' => '/admin/jobs'],
            ]],
            ['label' => '业务', 'items' => [
                ['label' => '商户', 'icon' => 'building-2', 'href' => '/admin/merchants'],
                ['label' => '用户反馈', 'icon' => 'messages-square', 'href' => '/admin/feedback'],
                ['label' => '运营设置', 'icon' => 'megaphone', 'href' => '/admin/operations'],
            ]],
            ['label' => 'AI 创作', 'items' => [
                ['label' => '模型与价格', 'icon' => 'route', 'href' => '/admin/ai-settings', 'match_paths' => [
                    '/admin/ai-settings', '/admin/ai-capabilities', '/admin/ai-models', '/admin/ai-assignments', '/admin/ai-compatibility',
                ]],
                ['label' => '提示词管理', 'icon' => 'wand-sparkles', 'href' => '/admin/prompts', 'match_paths' => [
                    '/admin/prompts', '/admin/creative-functions',
                ]],
                ['label' => '质量问题', 'icon' => 'shield-alert', 'href' => '/admin/quality-appeals'],
            ]],
            ['label' => '财务', 'items' => [
                ['label' => '套餐与权益', 'icon' => 'layers-3', 'href' => '/admin/plans'],
                ['label' => '商户侧定价', 'icon' => 'badge-dollar-sign', 'href' => '/admin/pricing'],
                ['label' => '点数卡密', 'icon' => 'ticket-check', 'href' => '/admin/cards'],
            ]],
            ['label' => '系统', 'items' => [
                ['label' => '系统状态', 'icon' => 'heart-pulse', 'href' => '/admin/system-status'],
                ['label' => '内容安全', 'icon' => 'shield-check', 'href' => '/admin/security'],
                ['label' => '素材存储', 'icon' => 'hard-drive-upload', 'href' => '/admin/storage'],
                ['label' => '邮件服务', 'icon' => 'mail', 'href' => '/admin/email'],
                ['label' => '运行组件', 'icon' => 'activity', 'href' => '/admin/worker', 'expert' => true],
                ['label' => '配置审计', 'icon' => 'sliders-horizontal', 'href' => '/admin/configuration-audit', 'expert' => true],
                ['label' => '审计日志', 'icon' => 'scroll-text', 'href' => '/admin/audit', 'expert' => true],
            ]],
        ];
        foreach ($groups as &$group) {
            foreach ($group['items'] as &$item) {
                $path = (string) parse_url((string) $item['href'], PHP_URL_PATH);
                $matches = (array) ($item['match_paths'] ?? [$path]);
                $item['active'] = $path === '/admin'
                    ? $currentPath === '/admin'
                    : count(array_filter(
                        $matches,
                        static fn (string $match): bool => str_starts_with($currentPath, $match)
                    )) > 0;
            }
            unset($item);
        }
        unset($group);
        return $groups;
    }

    public function breadcrumbs(string $currentPath, string $pageTitle): array
    {
        $domain = match (true) {
            str_starts_with($currentPath, '/admin/merchants'),
            str_starts_with($currentPath, '/admin/jobs'),
            str_starts_with($currentPath, '/admin/quality-appeals'),
            str_starts_with($currentPath, '/admin/feedback'),
            str_starts_with($currentPath, '/admin/operations') => '业务运营',
            str_starts_with($currentPath, '/admin/ai-capabilities'),
            str_starts_with($currentPath, '/admin/ai-models'),
            str_starts_with($currentPath, '/admin/ai-assignments'),
            str_starts_with($currentPath, '/admin/ai-compatibility'),
            str_starts_with($currentPath, '/admin/ai-settings'),
            str_starts_with($currentPath, '/admin/prompts'),
            str_starts_with($currentPath, '/admin/creative-functions') => 'AI 创作',
            str_starts_with($currentPath, '/admin/plans'),
            str_starts_with($currentPath, '/admin/pricing'),
            str_starts_with($currentPath, '/admin/cards') => '财务',
            str_starts_with($currentPath, '/admin/security'),
            str_starts_with($currentPath, '/admin/system-status'),
            str_starts_with($currentPath, '/admin/worker'),
            str_starts_with($currentPath, '/admin/storage'),
            str_starts_with($currentPath, '/admin/email'),
            str_starts_with($currentPath, '/admin/audit'),
            str_starts_with($currentPath, '/admin/configuration-audit') => '安全与系统',
            default => '工作台',
        };
        return [$domain, $pageTitle];
    }

    /**
     * Shared fact source for the bell badge and the task-reminder panel.
     *
     * The panel deliberately keeps zero-count categories out of its action list,
     * while preserving an explicit available/error state for the shell.
     */
    public function taskReminder(): array
    {
        $since = date('Y-m-d H:i:s', time() - 86400);
        $jobs = new AdminJobAttentionService();
        try {
            $qualityAppeals = (int) Db::name('generation_quality_appeals')
                ->whereIn('status', ['pending', 'reviewing', 'need_info'])
                ->count();
            $items = [
                [
                    'label' => '质量申诉',
                    'count' => $qualityAppeals,
                    'href' => '/admin/quality-appeals',
                    'detail' => '等待人工查看、补充信息或处理',
                ],
                [
                    'label' => '需处理任务',
                    'count' => $jobs->attentionCount('24h'),
                    'href' => '/admin/jobs?attention=1&period=24h',
                    'detail' => '最近 24 小时失败、部分完成或结果缺失',
                ],
                [
                    'label' => '排队任务',
                    'count' => $jobs->queueCount(),
                    'href' => '/admin/jobs?queue=1',
                    'detail' => '当前排队或等待进入生成服务',
                ],
                [
                    'label' => '安全事件',
                    'count' => (int) Db::name('content_violations')->where('created_at', '>=', $since)->count(),
                    'href' => '/admin/security',
                    'detail' => '最近 24 小时需要关注的内容安全事件',
                ],
            ];
        } catch (\Throwable) {
            return [
                'available' => false,
                'total' => 0,
                'items' => [],
            ];
        }
        $items = array_values(array_filter($items, static fn (array $item): bool => (int) $item['count'] > 0));
        return [
            'available' => true,
            'total' => array_sum(array_column($items, 'count')),
            'items' => $items,
        ];
    }

    public function todos(): array
    {
        return (array) ($this->taskReminder()['items'] ?? []);
    }

    public function recordVisit(int $adminId, string $path, string $title): void
    {
        if ($adminId <= 0 || !str_starts_with($path, '/admin') || str_starts_with($path, '/admin/api/')) {
            return;
        }
        $existing = Db::name('admin_navigation_preferences')->where('admin_id', $adminId)->where('path', $path)->find();
        $payload = ['title' => mb_strimwidth(trim($title), 0, 160, ''), 'last_visited_at' => Web::now(), 'updated_at' => Web::now()];
        if ($existing) {
            Db::name('admin_navigation_preferences')->where('id', (int) $existing['id'])->update($payload);
            return;
        }
        Db::name('admin_navigation_preferences')->insert($payload + [
            'admin_id' => $adminId,
            'path' => mb_strimwidth($path, 0, 255, ''),
            'is_favorite' => 0,
            'created_at' => Web::now(),
        ]);
    }

    public function recentAndFavorites(int $adminId): array
    {
        $recent = Db::name('admin_navigation_preferences')->where('admin_id', $adminId)
            ->order('last_visited_at', 'desc')->limit(6)->select()->toArray();
        $favorites = Db::name('admin_navigation_preferences')->where('admin_id', $adminId)
            ->where('is_favorite', 1)->order('updated_at', 'desc')->limit(6)->select()->toArray();
        return ['recent' => $recent, 'favorites' => $favorites];
    }

    public function toggleFavorite(int $adminId, string $path, string $title): bool
    {
        $this->recordVisit($adminId, $path, $title);
        $row = Db::name('admin_navigation_preferences')->where('admin_id', $adminId)->where('path', $path)->find();
        $favorite = empty($row['is_favorite']);
        Db::name('admin_navigation_preferences')->where('id', (int) $row['id'])->update([
            'is_favorite' => $favorite ? 1 : 0,
            'updated_at' => Web::now(),
        ]);
        return $favorite;
    }

    public function search(string $query): array
    {
        $query = mb_strimwidth(trim($query), 0, 100, '');
        if ($query === '') {
            return [];
        }
        $like = '%' . $query . '%';
        $results = [];
        foreach (Db::name('merchants')->whereLike('name|slug', $like)->order('id', 'desc')->limit(8)->select()->toArray() as $row) {
            $results[] = ['type' => '商户', 'title' => (string) $row['name'], 'meta' => (string) $row['slug'], 'href' => '/admin/merchants/' . (int) $row['id']];
        }
        foreach (Db::name('generation_jobs')->whereLike('job_no|model|error_message', $like)->order('id', 'desc')->limit(8)->select()->toArray() as $row) {
            $results[] = ['type' => '任务', 'title' => (string) $row['job_no'], 'meta' => (string) $row['status'] . ' · ' . (string) ($row['model'] ?? ''), 'href' => '/admin/jobs?q=' . rawurlencode((string) $row['job_no'])];
        }
        foreach (Db::name('skus')->whereLike('name|sku_code|category', $like)->order('id', 'desc')->limit(8)->select()->toArray() as $row) {
            $results[] = ['type' => '商品', 'title' => (string) $row['name'], 'meta' => (string) $row['sku_code'], 'href' => '/admin/merchants/' . (int) $row['merchant_id']];
        }
        return array_slice($results, 0, 24);
    }
}
