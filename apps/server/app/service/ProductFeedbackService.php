<?php

declare(strict_types=1);

namespace app\service;

final class ProductFeedbackService
{
    public function modules(): array
    {
        return [
            'sku' => 'SKU 商品管理',
            'product_report' => '商品识别与产品报告',
            'material_generation' => 'SKU 素材生成',
            'detail_page' => '详情页套图',
            'free_create' => '自由创作',
            'asset_library' => '素材库',
            'canvas' => '画布编辑',
            'tasks' => '任务记录',
            'account' => '账户、套餐与额度',
            'mobile' => '手机端体验',
            'other' => '其他',
        ];
    }

    public function issueTypes(): array
    {
        return [
            'result_quality' => '生成结果不够好',
            'hard_to_use' => '操作流程不清楚',
            'slow' => '处理速度太慢',
            'failed' => '报错或生成失败',
            'missing_feature' => '缺少需要的功能',
            'cost' => '积分或套餐不合适',
            'other' => '其他问题',
        ];
    }

    public function improvementTypes(): array
    {
        return [
            'image_quality' => '提升生图质量',
            'prompt_quality' => '优化提示词与模板',
            'workflow' => '简化操作流程',
            'speed_stability' => '提升速度与稳定性',
            'asset_management' => '完善素材管理',
            'canvas_edit' => '增强画布与改图',
            'cross_border' => '增加跨境平台能力',
            'pricing_plan' => '优化积分与套餐',
            'other' => '其他改进',
        ];
    }

    public function statuses(): array
    {
        return [
            'new' => '待查看',
            'reviewing' => '评估中',
            'planned' => '已进入计划',
            'resolved' => '已完成',
            'closed' => '已关闭',
        ];
    }

    public function priorities(): array
    {
        return [
            'low' => '低',
            'normal' => '普通',
            'high' => '高',
            'urgent' => '紧急',
        ];
    }

    public function label(array $options, mixed $value): string
    {
        return $options[(string) $value] ?? (string) $value;
    }

    public function valid(array $options, string $value): bool
    {
        return array_key_exists($value, $options);
    }
}
