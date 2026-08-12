<?php

declare(strict_types=1);

namespace app\service;

final class AiCapability
{
    public const SAFETY_TEXT = 'safety_text';
    public const SAFETY_VISION = 'safety_vision';
    public const PRODUCT_VISION = 'product_vision';
    public const CONTENT_PLANNER = 'content_planner';
    public const IMAGE_GENERATION = 'image_generation';
    public const IMAGE_EDIT = 'image_edit';
    public const VIDEO_IMAGE_TO_VIDEO = 'video_i2v';
    public const VIDEO_TEXT_TO_VIDEO = 'video_t2v';

    public static function definitions(): array
    {
        return [
            self::SAFETY_TEXT => ['label' => '文本内容审核', 'description' => '审核提示词、商品资料和用户输入。', 'user_selectable' => false],
            self::SAFETY_VISION => ['label' => '图片内容审核', 'description' => '审核上传图、参考图和生成结果。', 'user_selectable' => false],
            self::PRODUCT_VISION => ['label' => '商品图片识别', 'description' => '提取商品事实、包装、结构和一致性约束。', 'user_selectable' => false],
            self::CONTENT_PLANNER => ['label' => '文案与创作策划', 'description' => '整理 brief、文案、详情页和视频脚本。', 'user_selectable' => false],
            self::IMAGE_GENERATION => ['label' => '图片生成', 'description' => '无参考图的文字生图。', 'user_selectable' => true],
            self::IMAGE_EDIT => ['label' => '参考图编辑', 'description' => '带商品参考图的生成和画布编辑。', 'user_selectable' => true],
            self::VIDEO_IMAGE_TO_VIDEO => ['label' => '图生视频', 'description' => '从商品图生成短视频。', 'user_selectable' => true],
            self::VIDEO_TEXT_TO_VIDEO => ['label' => '文生视频', 'description' => '从文字脚本生成视频。', 'user_selectable' => true],
        ];
    }

    public static function codes(): array
    {
        return array_keys(self::definitions());
    }

    public static function exists(string $capability): bool
    {
        return isset(self::definitions()[$capability]);
    }

    public static function label(string $capability): string
    {
        return (string) (self::definitions()[$capability]['label'] ?? $capability);
    }

    public static function allowsUserSelection(string $capability): bool
    {
        return (bool) (self::definitions()[$capability]['user_selectable'] ?? false);
    }

    public static function isMerchantBillable(string $capability): bool
    {
        return in_array($capability, [
            self::IMAGE_GENERATION,
            self::IMAGE_EDIT,
            self::VIDEO_IMAGE_TO_VIDEO,
            self::VIDEO_TEXT_TO_VIDEO,
        ], true);
    }
}
