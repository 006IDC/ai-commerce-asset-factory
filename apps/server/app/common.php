<?php

if (!function_exists('escape_html')) {
    function escape_html(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('decode_json_list')) {
    function decode_json_list(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('chinese_sentence')) {
    function chinese_sentence(mixed $value): string
    {
        $text = trim((string) $value);
        // rtrim() treats its char list as bytes and can cut a UTF-8 Chinese
        // character in half when Chinese punctuation is included in that list.
        $text = preg_replace('/[。！？；，,.!?;\s]+$/u', '', $text) ?? $text;
        return $text === '' ? '' : $text . '。';
    }
}

if (!function_exists('password_is_strong')) {
    function password_is_strong(string $password): bool
    {
        return strlen($password) >= 10
            && preg_match('/[A-Za-z]/', $password) === 1
            && preg_match('/\d/', $password) === 1;
    }
}

if (!function_exists('job_status_label')) {
    function job_status_label(mixed $status): string
    {
        return [
            'pending_gateway' => '等待模型配置',
            'queued' => '排队中',
            'running' => '生成中',
            'workflow_processing' => '模型处理中',
            'slow_processing' => '处理较慢',
            'manual_review' => '待人工核对',
            'draft' => '报告待确认',
            'generating' => '详情页生成中',
            'completed' => '已完成',
            'partial' => '部分完成',
            'failed' => '生成失败',
            'result_missing' => '结果缺失',
            'cancelled' => '已取消',
            'upgraded' => '已升级',
            'purged' => '已彻底删除',
            'replaced' => '已被替换',
            'untracked' => '待补充请求 ID',
            'unused' => '未兑换',
            'redeemed' => '已兑换',
            'disabled' => '已停用',
            'expired' => '已过期',
        ][(string) $status] ?? (string) $status;
    }
}

if (!function_exists('platform_label')) {
    function platform_label(mixed $platform): string
    {
        return [
            'douyin' => '抖音',
            'taobao' => '淘宝',
            'xiaohongshu' => '小红书',
            'amazon' => 'Amazon',
            'tiktok_shop' => 'TikTok Shop',
            'shopify' => 'Shopify',
            'ebay' => 'eBay',
            'etsy' => 'Etsy',
            'shopee' => 'Shopee',
            'lazada' => 'Lazada',
            'temu' => 'Temu',
            'aliexpress' => 'AliExpress',
            'walmart' => 'Walmart Marketplace',
            'mercado_libre' => 'Mercado Libre',
            'canvas' => '画布编辑',
            'free' => '自由创作',
        ][(string) $platform] ?? (string) $platform;
    }
}

if (!function_exists('generation_platform_options')) {
    function generation_platform_options(bool $includeAll = false): array
    {
        $options = [
            'douyin' => '抖音', 'taobao' => '淘宝', 'xiaohongshu' => '小红书', 'amazon' => 'Amazon',
            'tiktok_shop' => 'TikTok Shop', 'shopify' => 'Shopify', 'ebay' => 'eBay', 'etsy' => 'Etsy',
            'shopee' => 'Shopee', 'lazada' => 'Lazada', 'temu' => 'Temu', 'aliexpress' => 'AliExpress',
            'walmart' => 'Walmart Marketplace', 'mercado_libre' => 'Mercado Libre',
        ];
        return $includeAll ? ['*' => '全部平台'] + $options : $options;
    }
}

if (!function_exists('generation_language_options')) {
    function generation_language_options(): array
    {
        return [
            'zh-CN' => '简体中文', 'zh-TW' => '繁体中文', 'en-US' => 'English', 'ja-JP' => '日本語',
            'ko-KR' => '한국어', 'es-ES' => 'Español', 'de-DE' => 'Deutsch', 'fr-FR' => 'Français',
            'pt-BR' => 'Português', 'ar-SA' => 'العربية',
        ];
    }
}

if (!function_exists('generation_language_label')) {
    function generation_language_label(mixed $language): string
    {
        return generation_language_options()[(string) $language] ?? (string) $language;
    }
}

if (!function_exists('detail_page_screen_options')) {
    function detail_page_screen_options(): array
    {
        return [
            'detail_01_hero' => ['label' => '第 1 屏 · 核心价值首屏', 'hint' => '商品主视觉与一句核心利益点'],
            'detail_02_overview' => ['label' => '第 2 屏 · 产品总览', 'hint' => '快速说明产品定位与适用对象'],
            'detail_03_benefit' => ['label' => '第 3 屏 · 核心卖点', 'hint' => '聚焦最有购买推动力的卖点'],
            'detail_04_benefit' => ['label' => '第 4 屏 · 次要卖点', 'hint' => '补充第二个差异化价值'],
            'detail_05_detail' => ['label' => '第 5 屏 · 关键细节', 'hint' => '实物展示可见细节，服务展示交付与保障'],
            'detail_06_function' => ['label' => '第 6 屏 · 功能结构', 'hint' => '结构拆解、功能说明或使用步骤'],
            'detail_07_scene' => ['label' => '第 7 屏 · 使用场景', 'hint' => '在真实使用环境中表达价值'],
            'detail_08_spec' => ['label' => '第 8 屏 · 规格与方案', 'hint' => '实物规格或服务配置方案'],
            'detail_09_audience' => ['label' => '第 9 屏 · 人群生活方式', 'hint' => '目标用户与生活方式共鸣'],
            'detail_10_trust' => ['label' => '第 10 屏 · 信任收尾', 'hint' => '包装、清单、服务或购买总结'],
        ];
    }
}

if (!function_exists('asset_type_label')) {
    function asset_type_label(mixed $type): string
    {
        if (preg_match('/^custom_material_(\d+)$/', (string) $type, $matches) === 1) {
            return '补充上新素材 · ' . $matches[1];
        }
        if (isset(detail_page_screen_options()[(string) $type])) {
            return detail_page_screen_options()[(string) $type]['label'];
        }
        return [
            'main' => '商品主图',
            'secondary' => '商品副图',
            'scene' => '实际使用场景',
            'lifestyle' => '生活方式氛围图',
            'detail' => '材质细节特写',
            'multi_angle' => '多视角展示图',
            'feature' => '卖点构图图',
            'package' => '包装套装图',
            'model' => '模特 / 手持展示图',
            'cover' => '短视频封面',
            'canvas_edit' => '画布编辑图',
            'canvas_upload' => '画布本地图片',
            'canvas_design' => '空白画板作品',
            'free' => '自由创作图',
            'product_video' => '商品短视频',
        ][(string) $type] ?? (string) $type;
    }
}

if (!function_exists('custom_material_asset_types')) {
    function custom_material_asset_types(array $requests): array
    {
        return array_map(static fn (int $index): string => 'custom_material_' . ($index + 1), array_keys($requests));
    }
}

if (!function_exists('asset_type_options')) {
    function asset_type_options(): array
    {
        return [
            'main' => ['label' => '商品主图', 'hint' => '干净、突出商品主体'],
            'secondary' => ['label' => '商品副图', 'hint' => '换构图展示商品卖点'],
            'scene' => ['label' => '实际使用场景', 'hint' => '放入真实生活使用环境'],
            'lifestyle' => ['label' => '生活方式氛围图', 'hint' => '营造审美和消费场景'],
            'detail' => ['label' => '材质细节特写', 'hint' => '突出材质、工艺和结构'],
            'multi_angle' => ['label' => '多视角展示图', 'hint' => '同画面呈现多个角度'],
            'feature' => ['label' => '卖点构图图', 'hint' => '局部放大并生成中文卖点标注'],
            'package' => ['label' => '包装套装图', 'hint' => '展示包装、配件和组合'],
            'model' => ['label' => '模特 / 手持展示图', 'hint' => '适合穿戴、手持和体感商品'],
            'cover' => ['label' => '短视频封面', 'hint' => '主体集中、适合封面裁切'],
        ];
    }
}

if (!function_exists('asset_library_type_options')) {
    function asset_library_type_options(): array
    {
        return asset_type_options() + detail_page_screen_options() + [
            'canvas_edit' => ['label' => '画布编辑图', 'hint' => '由现有素材局部修改生成'],
            'canvas_upload' => ['label' => '画布本地图片', 'hint' => '从本地直接加入画布的未归类素材'],
            'canvas_design' => ['label' => '空白画板作品', 'hint' => '由文字、图片和基础形状组合保存'],
            'free' => ['label' => '自由创作图', 'hint' => '由提示词或临时参考图生成'],
            'product_video' => ['label' => '商品短视频', 'hint' => '由商品参考图生成的短视频素材'],
        ];
    }
}

if (!function_exists('asset_type_list_label')) {
    function asset_type_list_label(mixed $types): string
    {
        $items = decode_json_list($types);
        $counts = array_count_values($items);
        $labels = [];
        foreach (array_values(array_unique($items)) as $type) {
            $count = (int) ($counts[$type] ?? 0);
            $labels[] = asset_type_label($type) . ($count > 1 ? ' ×' . $count : '');
        }
        return implode('、', $labels);
    }
}

if (!function_exists('generation_asset_quantity_limit')) {
    function generation_asset_quantity_limit(): int
    {
        return 10;
    }
}

if (!function_exists('generation_quantity_picker')) {
    function generation_quantity_picker(
        string $name,
        int $value = 1,
        int $max = 10,
        string $id = '',
        array $valueAttributes = [],
        string $ariaLabel = '生成数量',
        bool $disabled = false
    ): string {
        $max = max(1, $max);
        $value = max(1, min($max, $value));
        $quickMax = min(4, $max);
        $customSelected = $value > $quickMax;
        $customMin = min($max, $quickMax + 1);
        $selectId = $id !== '' ? $id : 'quantity-' . substr(hash('sha256', $name), 0, 10);
        $attributes = ' data-quantity-value';
        foreach ($valueAttributes as $attribute => $attributeValue) {
            if ($attributeValue === false || $attributeValue === null) {
                continue;
            }
            $attributes .= ' ' . escape_html((string) $attribute);
            if ($attributeValue !== true && $attributeValue !== '') {
                $attributes .= '="' . escape_html((string) $attributeValue) . '"';
            }
        }
        if ($disabled) {
            $attributes .= ' disabled';
        }

        $options = '';
        for ($quantity = 1; $quantity <= $quickMax; $quantity++) {
            $options .= '<option value="' . $quantity . '"' . ($value === $quantity ? ' selected' : '') . '>' . $quantity . ' 张</option>';
        }
        if ($max > $quickMax) {
            $options .= '<option value="custom"' . ($customSelected ? ' selected' : '') . '>自定义</option>';
        }

        return '<span class="quantity-picker" data-quantity-picker data-quantity-max="' . $max . '">'
            . '<select class="input quantity-picker-select" id="' . escape_html($selectId) . '" data-quantity-quick aria-label="' . escape_html($ariaLabel) . '">'
            . $options
            . '</select>'
            . ($max > $quickMax
                ? '<input class="input quantity-picker-custom" type="number" min="' . $customMin . '" max="' . $max . '" step="1" inputmode="numeric" value="' . ($customSelected ? $value : $customMin) . '" placeholder="' . $customMin . '-' . $max . '" data-quantity-custom aria-label="自定义生成数量，' . $customMin . ' 到 ' . $max . ' 张"' . ($customSelected ? '' : ' hidden disabled') . '><span class="quantity-picker-custom-unit" data-quantity-custom-unit aria-hidden="true"' . ($customSelected ? '' : ' hidden') . '>张</span>'
                : '')
            . '<input type="hidden" name="' . escape_html($name) . '" value="' . $value . '"' . $attributes . '>'
            . '</span>';
    }
}

if (!function_exists('sku_profile_completeness')) {
    function sku_profile_completeness(array $sku): array
    {
        $hasText = static fn (string $field): bool => trim((string) ($sku[$field] ?? '')) !== '';
        $checks = [
            ['label' => '商品参考图', 'weight' => 40, 'section' => 'profile', 'anchor' => 'source_image', 'complete' => $hasText('source_image_path') || $hasText('source_image_backup_path')],
            ['label' => '商品名称', 'weight' => 30, 'section' => 'profile', 'anchor' => 'name', 'complete' => $hasText('name')],
            ['label' => '商品类目', 'weight' => 30, 'section' => 'profile', 'anchor' => 'category', 'complete' => $hasText('category')],
        ];

        $score = 0;
        $missing = [];
        foreach ($checks as $check) {
            if ($check['complete']) {
                $score += (int) $check['weight'];
                continue;
            }
            unset($check['complete']);
            $missing[] = $check;
        }

        return [
            'score' => min(100, $score),
            'missing' => $missing,
            'complete' => $missing === [],
        ];
    }
}

if (!function_exists('canvas_output_presets')) {
    function generation_image_ratio_options(): array
    {
        return [
            'auto' => ['label' => '智能', 'width' => 1024, 'height' => 1024, 'preview' => '1 / 1'],
            '1:1' => ['label' => '1:1', 'width' => 1024, 'height' => 1024, 'preview' => '1 / 1'],
            '16:9' => ['label' => '16:9', 'width' => 1920, 'height' => 1080, 'preview' => '16 / 9'],
            '9:16' => ['label' => '9:16', 'width' => 1080, 'height' => 1920, 'preview' => '9 / 16'],
            '4:3' => ['label' => '4:3', 'width' => 1200, 'height' => 900, 'preview' => '4 / 3'],
            '3:4' => ['label' => '3:4', 'width' => 1080, 'height' => 1440, 'preview' => '3 / 4'],
            '3:2' => ['label' => '3:2', 'width' => 1500, 'height' => 1000, 'preview' => '3 / 2'],
            '2:3' => ['label' => '2:3', 'width' => 1000, 'height' => 1500, 'preview' => '2 / 3'],
            '3:1' => ['label' => '3:1', 'width' => 1800, 'height' => 600, 'preview' => '3 / 1'],
            '1:3' => ['label' => '1:3', 'width' => 600, 'height' => 1800, 'preview' => '1 / 3'],
            '21:9' => ['label' => '21:9', 'width' => 1890, 'height' => 810, 'preview' => '21 / 9'],
            '9:21' => ['label' => '9:21', 'width' => 810, 'height' => 1890, 'preview' => '9 / 21'],
            '5:4' => ['label' => '5:4', 'width' => 1250, 'height' => 1000, 'preview' => '5 / 4'],
            '4:5' => ['label' => '4:5', 'width' => 1080, 'height' => 1350, 'preview' => '4 / 5'],
        ];
    }

    function canvas_output_presets(): array
    {
        return [
            'square_1_1' => ['label' => '1:1 方图', 'ratio' => '1:1', 'platform' => '电商主图', 'width' => 1024, 'height' => 1024, 'preview' => '1 / 1'],
            'landscape_4_3' => ['label' => '4:3 横图', 'ratio' => '4:3', 'platform' => '商品展示 / 信息流', 'width' => 1200, 'height' => 900, 'preview' => '4 / 3'],
            'portrait_3_4' => ['label' => '3:4 竖图', 'ratio' => '3:4', 'platform' => '电商封面 / 小红书', 'width' => 1080, 'height' => 1440, 'preview' => '3 / 4'],
            'portrait_4_5' => ['label' => '4:5 竖图', 'ratio' => '4:5', 'platform' => '商品展示 / 社媒', 'width' => 1080, 'height' => 1350, 'preview' => '4 / 5'],
            'landscape_16_9' => ['label' => '16:9 横图', 'ratio' => '16:9', 'platform' => '横版视频 / 广告', 'width' => 1920, 'height' => 1080, 'preview' => '16 / 9'],
            'portrait_9_16' => ['label' => '9:16 竖图', 'ratio' => '9:16', 'platform' => '抖音 / 快手 / 视频号', 'width' => 1080, 'height' => 1920, 'preview' => '9 / 16'],
            'landscape_3_2' => ['label' => '3:2 横图', 'ratio' => '3:2', 'platform' => '横版商品展示', 'width' => 1500, 'height' => 1000, 'preview' => '3 / 2'],
            'portrait_2_3' => ['label' => '2:3 竖图', 'ratio' => '2:3', 'platform' => '竖版商品展示', 'width' => 1000, 'height' => 1500, 'preview' => '2 / 3'],
            'banner_3_1' => ['label' => '3:1 横幅', 'ratio' => '3:1', 'platform' => '店铺横幅', 'width' => 1800, 'height' => 600, 'preview' => '3 / 1'],
            'portrait_1_3' => ['label' => '1:3 长图', 'ratio' => '1:3', 'platform' => '长图创作', 'width' => 600, 'height' => 1800, 'preview' => '1 / 3'],
            'ultrawide_21_9' => ['label' => '21:9 超宽图', 'ratio' => '21:9', 'platform' => '超宽横幅', 'width' => 1890, 'height' => 810, 'preview' => '21 / 9'],
            'portrait_9_21' => ['label' => '9:21 长竖图', 'ratio' => '9:21', 'platform' => '超长竖图', 'width' => 810, 'height' => 1890, 'preview' => '9 / 21'],
            'landscape_5_4' => ['label' => '5:4 横图', 'ratio' => '5:4', 'platform' => '商品展示', 'width' => 1250, 'height' => 1000, 'preview' => '5 / 4'],
        ];
    }
}

if (!function_exists('generation_job_image_limit')) {
    function generation_job_image_limit(): int
    {
        return 30;
    }
}

if (!function_exists('detail_page_output_presets')) {
    function detail_page_output_presets(): array
    {
        return [
            'portrait_2_3' => ['label' => '2:3 标准竖版', 'hint' => '通用详情页', 'width' => 1024, 'height' => 1536],
            'square_1_1' => ['label' => '1:1 正方形详情图', 'hint' => '适合方形详情页与商品内容模块', 'width' => 1200, 'height' => 1200],
            'portrait_3_4' => ['label' => '3:4 电商竖图', 'hint' => '移动端商品详情', 'width' => 1200, 'height' => 1600],
            'portrait_4_5' => ['label' => '4:5 商品竖图', 'hint' => '社媒与电商通用', 'width' => 1080, 'height' => 1350],
            'custom' => ['label' => '自定义尺寸', 'hint' => '512-2048 像素', 'width' => null, 'height' => null],
        ];
    }

    function detail_page_output_dimensions(string $preset, mixed $customWidth = null, mixed $customHeight = null): array
    {
        $presets = detail_page_output_presets();
        if (!isset($presets[$preset])) {
            throw new InvalidArgumentException('请选择有效的详情页输出规格。');
        }
        if ($preset !== 'custom') {
            return [
                'preset' => $preset,
                'width' => (int) $presets[$preset]['width'],
                'height' => (int) $presets[$preset]['height'],
            ];
        }

        $width = filter_var($customWidth, FILTER_VALIDATE_INT);
        $height = filter_var($customHeight, FILTER_VALIDATE_INT);
        if ($width === false || $height === false || $width < 512 || $width > 2048 || $height < 512 || $height > 2048) {
            throw new InvalidArgumentException('自定义详情页宽高必须是 512-2048 之间的整数像素。');
        }
        if ($width * $height > 4194304) {
            throw new InvalidArgumentException('自定义详情页总像素不能超过 2048×2048。');
        }

        return ['preset' => 'custom', 'width' => $width, 'height' => $height];
    }
}

if (!function_exists('record_status_label')) {
    function record_status_label(mixed $status): string
    {
        return [
            'active' => '正常',
            'suspended' => '已停用',
            'deleted' => '已删除',
            'archived' => '已归档',
            'pending' => '待处理',
            'processing' => '处理中',
            'matched' => '已对账',
            'untracked' => '待补充请求 ID',
            'ignored' => '已忽略',
            'completed' => '已完成',
            'failed' => '失败',
            'cancelled' => '已取消',
        ][(string) $status] ?? (string) $status;
    }
}

if (!function_exists('member_role_label')) {
    function member_role_label(mixed $role): string
    {
        return [
            'owner' => '店主',
            'admin' => '管理员',
            'member' => '成员',
            'super_admin' => '超级管理员',
        ][(string) $role] ?? (string) $role;
    }
}

if (!function_exists('ledger_type_label')) {
    function ledger_type_label(mixed $type): string
    {
        return [
            'consume' => '任务消耗',
            'admin_grant' => '管理员发放',
            'admin_deduct' => '管理员扣减',
            'refund' => '额度退回',
            'topup' => '充值',
            'grant' => '试用发放',
            'card_redeem' => '卡密兑换',
            'recognition_consume' => '商品识别消耗',
            'recognition_refund' => '商品识别退款',
            'content_violation_penalty' => '违规内容处罚',
            'generation_reserve' => '任务额度预扣',
            'generation_refunded' => '已退款预扣',
            'generation_refund' => '任务失败退款',
            'plan_purchase' => '套餐购买',
        ][(string) $type] ?? (string) $type;
    }
}

if (!function_exists('ledger_source_label')) {
    function ledger_source_label(mixed $source): string
    {
        return [
            'generation_job' => '生成任务',
            'admin_adjustment' => '后台调额',
            'registration' => '注册赠送',
            'trial_registration' => '注册试用',
            'redemption_card' => '点数卡密',
            'product_recognition' => 'AI 商品识别',
            'content_moderation' => '内容安全审核',
            'plan_purchase' => '套餐购买',
        ][(string) $source] ?? (string) $source;
    }
}

if (!function_exists('plan_code_label')) {
    function plan_code_label(mixed $code): string
    {
        return [
            'trial' => '按次计费账户',
        ][(string) $code] ?? (string) $code;
    }
}

if (!function_exists('user_credit_terms')) {
    function user_credit_terms(mixed $message): string
    {
        return str_replace(
            ['点额度', '额度不足', '预扣额度', '未消耗额度', '退回额度', '扣除额度', '可用额度'],
            ['点数', '点数不足', '预扣点数', '未消耗点数', '退回点数', '扣除点数', '可用点数'],
            (string) $message
        );
    }
}

if (!function_exists('localized_job_error')) {
    function localized_job_error(mixed $message): string
    {
        if (!class_exists(\app\service\VisibleCopy::class, false)) {
            require_once __DIR__ . '/service/VisibleCopy.php';
        }

        return \app\service\VisibleCopy::merchantGenerationError($message);
    }
}

if (!function_exists('job_failure_guidance')) {
    function job_failure_guidance(mixed $message, int $actualCredits = 0, int $estimatedCredits = 0): array
    {
        $raw = (string) $message;
        $localized = localized_job_error($raw);
        if ($localized === '') {
            $localized = '任务未返回可用结果。';
        }
        $lower = mb_strtolower($raw);
        $solution = '稍后重试即可继续使用原任务参数，不需要重新创建商品。';
        $action = '重新生成';
        $actionType = 'retry';

        if (str_contains($lower, '额度不足') || str_contains($lower, '点数不足')) {
            $solution = '先补充点数或开通包含生成权益的套餐，再回到任务中心重试。';
            $action = '补充点数';
            $actionType = 'credits';
        } elseif (str_contains($lower, '429') || str_contains($lower, '503')
            || str_contains($lower, 'rate limit') || str_contains($lower, 'too many requests')
            || str_contains($lower, 'no available account') || str_contains($lower, 'temporarily unavailable')
            || str_contains($lower, '内容安全服务暂时不可用') || str_contains($lower, '内容安全检查失败')
            || str_contains($lower, '审核服务暂时不可用') || str_contains($lower, '上游资源不足')) {
            $solution = '这不是内容违规。任务参数已保留，请等待模型服务恢复后在原任务重试。';
        } elseif (str_contains($lower, '内容安全检测未通过')
            || str_contains($lower, '内容安全审核未通过')
            || str_contains($lower, '命中平台违禁词')
            || str_contains($lower, '安全策略')
            || str_contains($lower, 'policy violation')
            || str_contains($lower, 'moderation blocked')) {
            $solution = '修改容易引发误判或违规的文字、图片后重新提交；被拦截的原始请求不会进入生成模型。';
            $action = '修改内容';
            $actionType = 'edit';
        } elseif (str_contains($lower, '原图') || str_contains($lower, '未修改') || str_contains($lower, 'unchanged reference')) {
            $solution = '补充更明确的修改目标，或更换角度清晰的参考图后重新生成。';
            $action = '调整要求';
            $actionType = 'edit';
        } elseif (str_contains($lower, '模型') || str_contains($lower, 'channel') || str_contains($lower, 'gateway')) {
            $solution = '任务参数已保留。请稍后重试；如持续出现，由管理员检查对应模型渠道。';
        }

        $billing = $actualCredits > 0
            ? "已按成功产出的图片结算 {$actualCredits} 点，失败图片不扣费。"
            : ($estimatedCredits > 0
                ? '本次未产出成功图片，不会产生图片生成费用；预扣点数会自动退回。'
                : '本次未产生图片生成费用。');
        if (str_contains($raw, '已扣除')) {
            $billing = '内容安全处罚按系统规则单独结算，具体记录可在点数流水中查看。';
        }

        return [
            'what' => $localized,
            'billing' => $billing,
            'solution' => $solution,
            'action' => $action,
            'actionType' => $actionType,
        ];
    }
}
