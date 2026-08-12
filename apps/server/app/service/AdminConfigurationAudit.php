<?php

declare(strict_types=1);

namespace app\service;

final class AdminConfigurationAudit
{
    public function rows(): array
    {
        $rows = [];
        foreach (SystemSettings::definitions() as $key => $definition) {
            $classification = $this->classification($key);
            $rows[] = [
                'key' => $key,
                'reader' => $this->reader($key),
                'default' => !empty($definition['secret']) ? '••••••' : (string) $definition['default'],
                'required' => $this->required($key),
                'change_frequency' => $this->frequency($classification),
                'risk' => $this->risk($key, (bool) $definition['secret']),
                'classification' => $classification,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['classification'], $a['key']] <=> [$b['classification'], $b['key']]);
        return $rows;
    }

    public function summary(array $rows): array
    {
        $summary = ['自动' => 0, '向导' => 0, '专家' => 0, '废弃' => 0];
        foreach ($rows as $row) {
            $summary[$row['classification']] = ($summary[$row['classification']] ?? 0) + 1;
        }
        return $summary;
    }

    private function classification(string $key): string
    {
        if (in_array($key, ['deepseek_text_model', 'zhipu_vision_model', 'default_vision_model', 'ai_routing_gray_merchant_ids'], true)) {
            return '废弃';
        }
        if (str_starts_with($key, 'worker_') || str_starts_with($key, 'comfyui_') || in_array($key, ['ai_routing_mode', 'ai_execution_mode', 'ai_native_capabilities', 'content_safety_terms'], true)) {
            return '专家';
        }
        if (str_starts_with($key, 'storage_') || str_starts_with($key, 'smtp_') || str_starts_with($key, 'geetest_') || str_ends_with($key, '_credits')) {
            return '向导';
        }
        return '自动';
    }

    private function reader(string $key): string
    {
        return match (true) {
            str_starts_with($key, 'comfyui_') => 'MediaWorkflowEngine / ComfyUiClient',
            str_starts_with($key, 'worker_'), str_starts_with($key, 'video_worker_') => '任务 Worker',
            str_starts_with($key, 'storage_') => 'AssetStorage',
            str_starts_with($key, 'smtp_') => '邮件服务',
            str_starts_with($key, 'content_safety_') => 'ContentSafetyService',
            str_starts_with($key, 'tavily_') => 'MarketResearchService',
            str_starts_with($key, 'geetest_') => '登录与注册验证',
            str_contains($key, 'credits') => 'PricingService / PlanService',
            str_contains($key, 'model'), str_starts_with($key, 'ai_') => 'AI 路由与模型目录',
            default => '系统控制面',
        };
    }

    private function required(string $key): bool
    {
        return in_array($key, ['app_public_url', 'default_image_model', 'storage_driver', 'content_safety_enabled'], true);
    }

    private function frequency(string $classification): string
    {
        return match ($classification) {
            '向导' => '初始化或供应商变更',
            '专家' => '故障处理或容量调整',
            '废弃' => '不再修改',
            default => '系统自动维护',
        };
    }

    private function risk(string $key, bool $secret): string
    {
        if ($secret || str_contains($key, 'routing') || str_contains($key, 'safety') || str_contains($key, 'workflow')) {
            return '高';
        }
        if (str_contains($key, 'credits') || str_contains($key, 'concurrency') || str_contains($key, 'storage')) {
            return '中';
        }
        return '低';
    }
}
