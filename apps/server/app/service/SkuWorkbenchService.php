<?php

declare(strict_types=1);

namespace app\service;

/** Builds the compact merchant product report and its three creation entries. */
final class SkuWorkbenchService
{
    public const VERSION = 'sku_workbench_v2';

    public function build(array $sku, array $projects, array $targetState): array
    {
        $targets = array_values((array) ($targetState['targets'] ?? []));
        $hasReference = trim((string) ($sku['source_image_path'] ?? $sku['source_image_backup_path'] ?? '')) !== '';
        $basicMissing = [];
        foreach (['商品名称' => $sku['name'] ?? '', '商品类目' => $sku['category'] ?? ''] as $label => $value) {
            if (trim((string) $value) === '') {
                $basicMissing[] = $label;
            }
        }
        if (!$hasReference) {
            $basicMissing[] = '商品参考图';
        }

        $hasPlan = false;
        foreach ($projects as $project) {
            if (decode_json_list($project['screen_plan'] ?? '[]') !== []) {
                $hasPlan = true;
                break;
            }
        }
        $basicReady = $basicMissing === [];
        $readiness = [
            $this->readiness('product', '商品资料', $basicReady, $basicMissing, '名称、类目和参考图是唯一必填资料'),
            $this->readiness('report', '产品报告', $basicReady, $basicMissing, '可编辑的商品摘要，不作为出图审批门槛'),
            $this->readiness('detail', '详情页方案', $hasPlan, $hasPlan ? [] : ['至少一个详情页方案'], '只在创建详情页时需要'),
            $this->readiness('delivery', '投放配置', $targets !== [], $targets === [] ? ['至少一个平台与画面语言'] : [], '决定渠道和画面语言'),
        ];

        return [
            'schema_version' => self::VERSION,
            'ready' => $basicReady,
            'visual_ready' => $basicReady,
            'readiness' => $readiness,
            'targets' => $targets,
            'launch_direction' => (string) ($targetState['launch_direction'] ?? ''),
            'uses' => $this->creationUses($basicReady, $hasPlan),
        ];
    }

    private function readiness(string $code, string $label, bool $ready, array $missing, string $impact): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'ready' => $ready,
            'missing' => array_values(array_filter($missing)),
            'impact' => $impact,
        ];
    }

    private function creationUses(bool $basicReady, bool $hasPlan): array
    {
        return [
            [
                'code' => 'full_package',
                'label' => '整套上新素材',
                'recommended' => true,
                'enabled' => $basicReady && $hasPlan,
                'blocked_reason' => !$basicReady ? '先补全名称、类目和参考图' : '先建立详情页方案',
                'listing_count' => 6,
                'detail_count' => 10,
                'description' => '6 张上架图片 + 完整十屏详情页',
            ],
            [
                'code' => 'listing_images', 'label' => '只做上架图片', 'recommended' => false,
                'enabled' => $basicReady,
                'blocked_reason' => $basicReady ? '' : '先补全名称和参考图',
                'listing_count' => 6, 'detail_count' => 0, 'description' => '主图、副图、卖点与场景共 6 张',
            ],
            [
                'code' => 'detail_page', 'label' => '只做详情页', 'recommended' => false,
                'enabled' => $basicReady && $hasPlan,
                'blocked_reason' => !$basicReady ? '先补全商品资料' : '先建立详情页方案',
                'listing_count' => 0, 'detail_count' => 10, 'description' => '按当前商品资料生成完整十屏',
            ],
        ];
    }
}
