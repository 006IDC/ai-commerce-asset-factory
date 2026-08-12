<?php

declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use think\facade\Db;

final class AdminPromptManagementService
{
    private const BUSINESS_NAMES = [
        'product_report' => '商品分析与产品报告',
        'product_image' => '商品主图',
        'listing_bundle' => '商品套图',
        'detail_page' => '商品详情页',
        'free_create' => '自由创作',
        'product_video' => '商品视频',
    ];

    public function dashboard(): array
    {
        $businesses = [];
        foreach (self::BUSINESS_NAMES as $code => $name) {
            $prompts = $this->corePrompts($code);
            $businesses[] = [
                'code' => $code,
                'name' => $name,
                'prompt_status' => $prompts === [] ? '待配置' : '使用中',
                'prompt_count' => count($prompts),
            ];
        }
        return [
            'businesses' => $businesses,
            'inspiration_count' => (int) Db::name('prompt_templates')->where('scene', PromptTemplateService::SCENE_FREE)->count(),
        ];
    }

    public function inspirationTemplates(): array
    {
        return Db::name('prompt_templates')->where('scene', PromptTemplateService::SCENE_FREE)
            ->order('status', 'asc')->order('is_system', 'desc')->order('id', 'asc')->select()->toArray();
    }

    public function business(string $productCode, string $contextCode = '', bool $unusedPreview = false): array
    {
        unset($contextCode, $unusedPreview);
        if (!isset(self::BUSINESS_NAMES[$productCode])) {
            throw new InvalidArgumentException('生成业务不存在。');
        }
        $prompts = $this->corePrompts($productCode);
        return [
            'product' => [
                'code' => $productCode,
                'display_name' => self::BUSINESS_NAMES[$productCode],
            ],
            'contexts' => [],
            'selected_context' => ['code' => 'all', 'label' => '全部提示词模板'],
            'visible_prompts' => $prompts,
        ];
    }

    public function corePrompt(string $productCode, int $templateId): array
    {
        foreach ($this->corePrompts($productCode) as $prompt) {
            if ((int) $prompt['id'] === $templateId) {
                return $prompt;
            }
        }
        throw new InvalidArgumentException('该提示词不属于当前生成业务。');
    }

    public function corePrompts(string $productCode): array
    {
        $result = [];
        foreach ($this->definitions($productCode) as $definition) {
            $template = Db::name('prompt_templates')->where('code', $definition['template_code'])->find();
            if (!$template) {
                continue;
            }
            $result[] = $template + [
                'product_code' => $productCode,
                'product_name' => self::BUSINESS_NAMES[$productCode] ?? $productCode,
                'display_description' => $definition['description'],
                'impact_scope' => $definition['impact_scope'],
                'display_status' => (string) ($template['status'] ?? '') === 'active' ? '使用中' : '已停用',
                'shared' => !empty($definition['shared']),
            ];
        }
        return $result;
    }

    public function productForTemplate(int $templateId): ?string
    {
        foreach (array_keys(self::BUSINESS_NAMES) as $productCode) {
            foreach ($this->definitions($productCode) as $definition) {
                $template = Db::name('prompt_templates')->where('code', $definition['template_code'])->find();
                if ((int) ($template['id'] ?? 0) === $templateId) {
                    return $productCode;
                }
            }
        }
        return null;
    }

    private function definitions(string $productCode): array
    {
        $item = static fn (string $code, string $label, string $scope, string $description, bool $shared = false): array => [
            'template_code' => $code, 'label' => $label, 'impact_scope' => $scope,
            'description' => $description, 'shared' => $shared,
        ];
        if ($productCode === 'product_report') {
            return [
                $item('builtin.product_analysis', '商品识别主提示词', '商品参考图识别', '提取可核对商品事实和待确认信息。'),
                $item('builtin.detail_report', '通用产品报告', '通用与跨境产品报告', '整理商品报告和详情页素材底稿。'),
                $item('builtin.detail_report.taobao', '淘宝产品报告', '淘宝平台产品报告', '整理淘宝商品报告和详情页素材底稿。'),
                $item('builtin.detail_report.douyin', '抖音产品报告', '抖音平台产品报告', '整理抖音商品报告和详情页素材底稿。'),
                $item('builtin.detail_report.xiaohongshu', '小红书产品报告', '小红书平台产品报告', '整理小红书商品报告和详情页素材底稿。'),
            ];
        }
        if ($productCode === 'product_image') {
            return [$item('builtin.image.main', '商品主图', '商品主图', '决定商品主图的画面要求。', true)];
        }
        if ($productCode === 'listing_bundle') {
            $result = [];
            foreach (asset_type_options() as $type => $option) {
                $result[] = $item('builtin.image.' . $type, (string) $option['label'], '套图 · ' . (string) $option['label'], '直接影响该类型成品。', $type === 'main');
            }
            return $result;
        }
        if ($productCode === 'detail_page') {
            $result = [];
            foreach (detail_page_screen_options() as $type => $option) {
                $result[] = $item('builtin.detail.' . $type, (string) $option['label'], (string) $option['label'], '直接影响该屏成品。');
            }
            return $result;
        }
        if ($productCode === 'product_video') {
            return [
                $item('builtin.video.product', '商品图生视频', '单段商品视频', '直接影响商品图生视频请求。'),
                $item('builtin.video.storyboard_plan', '视频分镜策划', '分镜脚本', '直接影响分镜脚本。'),
                $item('builtin.video.storyboard_scene', '单镜头视频', '分镜视频片段', '直接影响每个镜头的视频请求。'),
            ];
        }
        if ($productCode === 'free_create') {
            return [
                $item(
                    'builtin.system.free_runtime',
                    '自由创作完整生成模板',
                    '自由创作图片',
                    '把用户输入、画幅、语言和参考图要求组成一次直接生成请求。'
                ),
            ];
        }
        return [];
    }
}
