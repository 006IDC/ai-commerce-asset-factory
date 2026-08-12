<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use think\facade\Db;

final class CreativeProductService
{
    public const DEFINITIONS = [
        'product_report' => ['name' => '商品识别与产品报告', 'description' => '识别商品事实、约束和可编辑产品报告。', 'execution_mode' => 'direct', 'sort_order' => 10],
        'product_image' => ['name' => '商品主图', 'description' => '单张商品主图与一次性精确 SKU 输出。', 'execution_mode' => 'direct', 'sort_order' => 20],
        'listing_bundle' => ['name' => '商品套图', 'description' => '按商品资料和素材类型直接生成商品套图。', 'execution_mode' => 'direct', 'sort_order' => 30],
        'detail_page' => ['name' => '商品详情页', 'description' => '按商品资料和十屏脚本直接生成详情页图片。', 'execution_mode' => 'direct', 'sort_order' => 40],
        'free_create' => ['name' => '自由创作', 'description' => '对话式单图创作和多参考图创意表达。', 'execution_mode' => 'direct', 'sort_order' => 50],
        'canvas_edit' => ['name' => '画布编辑', 'description' => '画布全局编辑、局部编辑和受控重绘；作为唯一的 ComfyUI 业务入口。', 'execution_mode' => 'comfyui', 'sort_order' => 60],
        'product_video' => ['name' => '商品视频', 'description' => '分镜、图生视频、文生视频和批量场景；默认直接调用视频模型。', 'execution_mode' => 'direct', 'sort_order' => 70],
    ];

    public function ensureSeeded(): array
    {
        $changes = [];
        foreach (self::DEFINITIONS as $code => $definition) {
            $existing = Db::name('creative_products')->where('code', $code)->find();
            if (!$existing) {
                Db::name('creative_products')->insert($definition + [
                    'code' => $code,
                    'status' => 'active',
                    'created_at' => Web::now(),
                    'updated_at' => Web::now(),
                ]);
                $changes[] = 'creative_product.' . $code;
                continue;
            }
            $updates = [];
            foreach (['name', 'execution_mode', 'sort_order'] as $field) {
                if ((string) ($existing[$field] ?? '') !== (string) $definition[$field]) {
                    $updates[$field] = $definition[$field];
                }
            }
            if ($code !== 'canvas_edit' && max(0, (int) ($existing['current_workflow_version_id'] ?? 0)) > 0) {
                $updates['previous_workflow_version_id'] = (int) $existing['current_workflow_version_id'];
                $updates['current_workflow_version_id'] = null;
            }
            if ($updates !== []) {
                Db::name('creative_products')->where('id', (int) $existing['id'])->update($updates + ['updated_at' => Web::now()]);
                $changes[] = 'creative_product_updated.' . $code;
            }
        }
        return $changes;
    }

    public function productsWithMetrics(): array
    {
        $products = Db::name('creative_products')->where('status', 'active')->order('sort_order', 'asc')->select()->toArray();
        foreach ($products as &$product) {
            $product['workflow_version'] = (int) ($product['current_workflow_version_id'] ?? 0) > 0
                ? Db::name('workflow_template_versions')->where('id', (int) $product['current_workflow_version_id'])->find()
                : null;
            $product['metrics_7d'] = ['success_rate' => 0, 'quality_issues' => 0];
            $product['metrics_30d'] = ['success_rate' => 0, 'quality_issues' => 0];
        }
        unset($product);
        return $products;
    }

    public function product(string $code): ?array
    {
        $product = Db::name('creative_products')->where('code', $code)->find();
        return $product ?: null;
    }

    public function productCodeForJob(array $job, ?array $item = null): string
    {
        if ((string) ($job['platform'] ?? '') === 'free') {
            return 'free_create';
        }
        if ((string) ($job['platform'] ?? '') === 'canvas') {
            return 'canvas_edit';
        }
        if ((string) ($job['output_kind'] ?? 'image') === 'video') {
            return 'product_video';
        }
        if ((int) ($job['detail_page_project_id'] ?? 0) > 0) {
            return 'detail_page';
        }
        if ((string) ($job['bundle_no'] ?? '') !== '' || count(decode_json_list($job['asset_types'] ?? '[]')) > 1) {
            return 'listing_bundle';
        }
        return str_starts_with((string) ($item['asset_type'] ?? ''), 'detail_') ? 'detail_page' : 'product_image';
    }

}
