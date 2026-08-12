<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use InvalidArgumentException;
use think\facade\Db;

final class AdminOnboardingService
{
    public const STEPS = [
        'welcome' => ['label' => '欢迎使用', 'description' => '了解配置流程和控制面边界。'],
        'connect_gateway' => ['label' => '连接 AI 网关', 'description' => '确认 Sub2API 已启用且连接参数完整。'],
        'sync_models' => ['label' => '同步模型', 'description' => '读取 Sub2API 当前可见模型，不复制上游密钥。'],
        'recommendations' => ['label' => '确认推荐配置', 'description' => '只使用来源可见且测试通过的模型。'],
        'assign_functions' => ['label' => '分配功能', 'description' => '为文本、视觉、安全、图片和视频分配模型。'],
        'verify_models' => ['label' => '检查可用性', 'description' => '查看历史真实测试状态；图片与视频测试必须手动确认费用。'],
        'set_prices' => ['label' => '设置价格', 'description' => '为商户可选模型设置点数价格。'],
        'review_prompts' => ['label' => '确认提示词', 'description' => '检查核心提示词与灵感模板。'],
        'first_creation' => ['label' => '首次创作', 'description' => '前往商户工作台发起首个测试；向导不会自动产生付费请求。'],
        'complete' => ['label' => '完成配置', 'description' => '进入运营工作台。'],
    ];

    public function enabled(): bool
    {
        return filter_var(
            env('ADMIN_ONBOARDING_ENABLED', false),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    public function progress(int $adminId): array
    {
        $row = Db::name('admin_onboarding_progress')->where('admin_id', $adminId)->find();
        if (!$row) {
            $row = $this->create($adminId, 'pending');
        }
        $state = json_decode((string) ($row['step_state_json'] ?? '{}'), true);
        $row['step_state'] = is_array($state) ? $state : [];
        $row['steps'] = self::STEPS;
        $row['percent'] = $this->percent($row['step_state']);
        return $row;
    }

    public function isComplete(int $adminId): bool
    {
        return (string) ($this->progress($adminId)['status'] ?? '') === 'completed';
    }

    public function createPending(int $adminId): array
    {
        return $this->create($adminId, 'pending');
    }

    public function createCompleted(int $adminId): array
    {
        return $this->create($adminId, 'completed');
    }

    public function advance(int $adminId, string $step, array $details = []): array
    {
        if (!isset(self::STEPS[$step])) {
            throw new InvalidArgumentException('未知的配置步骤。');
        }
        $progress = $this->progress($adminId);
        if ((string) ($progress['status'] ?? '') === 'completed') {
            return $progress;
        }
        if ((string) ($progress['current_step'] ?? '') !== $step) {
            throw new InvalidArgumentException('请按向导顺序完成当前配置步骤。');
        }
        $state = (array) $progress['step_state'];
        $now = Web::now();
        $state[$step] = [
            'status' => 'completed',
            'completed_at' => $now,
            'details' => $this->safeDetails($details),
        ];
        $keys = array_keys(self::STEPS);
        $position = array_search($step, $keys, true);
        $next = $keys[min(count($keys) - 1, (int) $position + 1)];
        $completed = $step === 'complete';
        Db::name('admin_onboarding_progress')->where('admin_id', $adminId)->update([
            'status' => $completed ? 'completed' : 'in_progress',
            'current_step' => $completed ? 'complete' : $next,
            'step_state_json' => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'started_at' => $progress['started_at'] ?: $now,
            'completed_at' => $completed ? $now : null,
            'updated_at' => $now,
        ]);
        return $this->progress($adminId);
    }

    public function skipVideo(int $adminId): array
    {
        $progress = $this->progress($adminId);
        $state = (array) $progress['step_state'];
        $state['optional_video'] = [
            'status' => 'skipped',
            'completed_at' => Web::now(),
            'details' => ['reason' => '当前不启用未经真实测试的视频模型'],
        ];
        Db::name('admin_onboarding_progress')->where('admin_id', $adminId)->update([
            'step_state_json' => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => Web::now(),
        ]);
        return $this->progress($adminId);
    }

    private function create(int $adminId, string $status): array
    {
        $existing = Db::name('admin_onboarding_progress')->where('admin_id', $adminId)->find();
        if ($existing) {
            return $existing;
        }
        $now = Web::now();
        $state = [];
        if ($status === 'completed') {
            foreach (array_keys(self::STEPS) as $step) {
                $state[$step] = ['status' => 'completed', 'completed_at' => $now, 'details' => ['migration' => true]];
            }
        }
        $id = (int) Db::name('admin_onboarding_progress')->insertGetId([
            'admin_id' => $adminId,
            'status' => $status,
            'current_step' => $status === 'completed' ? 'complete' : 'welcome',
            'step_state_json' => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'started_at' => $status === 'completed' ? $now : null,
            'completed_at' => $status === 'completed' ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return Db::name('admin_onboarding_progress')->where('id', $id)->find();
    }

    private function percent(array $state): int
    {
        $completed = 0;
        foreach (array_keys(self::STEPS) as $step) {
            if ((string) ($state[$step]['status'] ?? '') === 'completed') {
                $completed++;
            }
        }
        return (int) round(($completed / count(self::STEPS)) * 100);
    }

    private function safeDetails(array $details): array
    {
        foreach (array_keys($details) as $key) {
            if (preg_match('/key|password|secret|token/i', (string) $key)) {
                unset($details[$key]);
            }
        }
        return $details;
    }
}
