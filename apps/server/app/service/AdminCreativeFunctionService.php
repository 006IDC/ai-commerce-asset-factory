<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use InvalidArgumentException;
use think\facade\Db;

final class AdminCreativeFunctionService
{
    public function __construct(
        private readonly CreativeProductService $products,
        private readonly AdminPromptManagementService $prompts,
        private readonly AiCapabilityAssignmentService $assignments
    ) {
    }

    public function dashboard(): array
    {
        $products = $this->products->productsWithMetrics();
        $assignmentData = $this->assignments->dashboard();
        foreach ($products as &$product) {
            $capabilities = $this->capabilitiesForProduct((string) $product['code']);
            $models = [];
            $defaultModel = null;
            foreach ($capabilities as $capability) {
                $route = (array) ($assignmentData['routes'][$capability] ?? []);
                $defaultId = (int) ($route['default_model_id'] ?? $route['primary_model_id'] ?? 0);
                foreach ((array) ($assignmentData['options'][$capability] ?? []) as $model) {
                    if (empty($model['source_visible']) || empty($model['enabled']) || !empty($model['archived_at'])) {
                        continue;
                    }
                    $models[(int) $model['id']] = $model;
                    if ((int) $model['id'] === $defaultId) {
                        $defaultModel = $model;
                    }
                }
            }
            $product['capability_labels'] = array_map([AiCapability::class, 'label'], $capabilities);
            $product['available_models'] = array_values($models);
            $product['default_model'] = $defaultModel;
            $product['core_prompts'] = $this->prompts->corePrompts((string) $product['code']);
            $metrics = (array) ($product['metrics_7d'] ?? []);
            $product['success_rate'] = (float) ($metrics['success_rate'] ?? 0);
            $product['issue'] = $this->issue($product);
        }
        unset($product);
        return ['products' => $products];
    }

    public function update(string $code, array $input, int $adminId): array
    {
        $product = Db::name('creative_products')->where('code', $code)->find();
        if (!$product) {
            throw new InvalidArgumentException('创作功能不存在。');
        }
        $description = mb_strimwidth(trim((string) ($input['description'] ?? '')), 0, 500, '');
        if ($description === '') {
            throw new InvalidArgumentException('请填写面向运营人员和用户可理解的功能说明。');
        }
        Db::name('creative_products')->where('id', (int) $product['id'])->update([
            'status' => !empty($input['enabled']) ? 'active' : 'inactive',
            'description' => $description,
            'updated_at' => Web::now(),
        ]);
        return Db::name('creative_products')->where('id', (int) $product['id'])->find();
    }

    public function product(string $code): ?array
    {
        foreach ((array) ($this->dashboard()['products'] ?? []) as $product) {
            if ((string) $product['code'] === $code) {
                return $product;
            }
        }
        return null;
    }

    private function capabilitiesForProduct(string $code): array
    {
        return match ($code) {
            'product_report' => [AiCapability::PRODUCT_VISION, AiCapability::CONTENT_PLANNER],
            'product_image', 'listing_bundle', 'detail_page', 'free_create' => [AiCapability::IMAGE_GENERATION, AiCapability::IMAGE_EDIT],
            'canvas_edit' => [AiCapability::IMAGE_EDIT],
            'product_video' => [AiCapability::VIDEO_IMAGE_TO_VIDEO, AiCapability::VIDEO_TEXT_TO_VIDEO],
            default => [],
        };
    }

    private function issue(array $product): string
    {
        if ((string) ($product['status'] ?? '') !== 'active') {
            return '功能已关闭';
        }
        if (empty($product['available_models'])) {
            return '没有已开放且可用的模型';
        }
        if ((string) ($product['code'] ?? '') !== 'canvas_edit' && empty($product['core_prompts'])) {
            return '缺少日常可维护的核心提示词';
        }
        return '';
    }
}
