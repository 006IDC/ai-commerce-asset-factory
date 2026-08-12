<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use RuntimeException;
use think\facade\Db;

final class AiBusinessCompatibilityService
{
    public const PRODUCTS = [
        'product_report' => ['label' => '商品识别与产品报告', 'channel' => 'direct', 'capabilities' => [AiCapability::PRODUCT_VISION, AiCapability::CONTENT_PLANNER]],
        'product_image' => ['label' => '商品主图', 'channel' => 'direct', 'capabilities' => [AiCapability::IMAGE_GENERATION, AiCapability::IMAGE_EDIT]],
        'listing_bundle' => ['label' => '商品套图', 'channel' => 'direct', 'capabilities' => [AiCapability::IMAGE_GENERATION, AiCapability::IMAGE_EDIT]],
        'detail_page' => ['label' => '商品详情页', 'channel' => 'direct', 'capabilities' => [AiCapability::IMAGE_EDIT]],
        'free_create' => ['label' => '自由创作', 'channel' => 'direct', 'capabilities' => [AiCapability::IMAGE_GENERATION, AiCapability::IMAGE_EDIT]],
        'canvas_edit' => ['label' => '画布编辑', 'channel' => 'comfyui', 'capabilities' => [AiCapability::IMAGE_EDIT]],
        'product_video' => ['label' => '商品视频', 'channel' => 'direct', 'capabilities' => [AiCapability::VIDEO_IMAGE_TO_VIDEO, AiCapability::VIDEO_TEXT_TO_VIDEO]],
    ];

    public function dashboard(): array
    {
        $models = Db::name('ai_models')
            ->where('source_visible', 1)
            ->whereNull('archived_at')
            ->order('sort_order', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $compatibilities = [];
        foreach (Db::name('ai_business_compatibilities')->select()->toArray() as $row) {
            $compatibilities[$this->key(
                (int) $row['ai_model_id'],
                (string) $row['capability_code'],
                (string) $row['product_code'],
                (string) $row['execution_channel']
            )] = $row;
        }
        foreach ($models as &$model) {
            $capabilities = json_decode((string) ($model['capabilities_json'] ?? '[]'), true);
            $model['capabilities'] = is_array($capabilities) ? array_values(array_map('strval', $capabilities)) : [];
            $model['matrix'] = [];
            foreach (self::PRODUCTS as $productCode => $product) {
                foreach ((array) $product['capabilities'] as $capability) {
                    if (!in_array($capability, $model['capabilities'], true)) {
                        continue;
                    }
                    $key = $this->key((int) $model['id'], $capability, $productCode, (string) $product['channel']);
                    $model['matrix'][] = [
                        'product_code' => $productCode,
                        'product_label' => (string) $product['label'],
                        'channel' => (string) $product['channel'],
                        'capability' => $capability,
                        'capability_label' => AiCapability::label($capability),
                        'status' => (string) ($compatibilities[$key]['status'] ?? 'pending'),
                        'notes' => (string) ($compatibilities[$key]['notes'] ?? ''),
                        'last_test_id' => (int) ($compatibilities[$key]['last_test_id'] ?? 0),
                        'workflow_version_id' => (int) ($compatibilities[$key]['workflow_version_id'] ?? 0),
                        'restrictions' => $this->decodeObject($compatibilities[$key]['restriction_json'] ?? '{}'),
                    ];
                }
            }
        }
        unset($model);
        return [
            'products' => self::PRODUCTS,
            'models' => $models,
        ];
    }

    public function isReady(int $modelId, string $capability, string $productCode, string $channel): bool
    {
        if (!AiCapability::isMerchantBillable($capability)) {
            return true;
        }
        if (!isset(self::PRODUCTS[$productCode])
            || (string) self::PRODUCTS[$productCode]['channel'] !== $channel
            || !in_array($capability, (array) self::PRODUCTS[$productCode]['capabilities'], true)) {
            return false;
        }
        $passed = Db::name('ai_business_compatibilities')
            ->where('ai_model_id', $modelId)
            ->where('capability_code', $capability)
            ->where('product_code', $productCode)
            ->where('execution_channel', $channel)
            ->where('status', 'passed')
            ->count() > 0;
        if ($passed) {
            return true;
        }
        // 商品套图和详情页现在复用已经真实验证过的 Direct 图片传输，
        // 不应因为历史上只登记了 ComfyUI 业务维度而阻断恢复后的直连路径。
        if ($channel === 'direct' && in_array($productCode, ['listing_bundle', 'detail_page'], true)) {
            return Db::name('ai_business_compatibilities')
                ->where('ai_model_id', $modelId)
                ->where('capability_code', $capability)
                ->where('product_code', 'product_image')
                ->where('execution_channel', 'direct')
                ->where('status', 'passed')
                ->count() > 0;
        }
        if ($channel === 'direct' && $productCode === 'product_video') {
            return Db::name('ai_model_capabilities')
                ->where('ai_model_id', $modelId)
                ->where('capability_code', $capability)
                ->where('confirmation_status', 'confirmed')
                ->where('test_status', 'passed')
                ->count() > 0;
        }
        return false;
    }

    public function assertReady(int $modelId, string $capability, string $productCode, string $channel): void
    {
        if ($this->isReady($modelId, $capability, $productCode, $channel)) {
            return;
        }
        $productLabel = (string) (self::PRODUCTS[$productCode]['label'] ?? $productCode);
        throw new RuntimeException(
            '所选模型尚未通过“' . $productLabel . ' · ' . AiCapability::label($capability)
            . ' · ' . ($channel === 'comfyui' ? '工作流执行' : '直接调用') . '”真实兼容测试。'
        );
    }

    public function record(
        int $modelId,
        string $capability,
        string $productCode,
        string $channel,
        string $status,
        ?int $testId,
        string $notes,
        int $adminId
    ): array {
        if (!isset(self::PRODUCTS[$productCode])
            || !in_array($capability, (array) self::PRODUCTS[$productCode]['capabilities'], true)
            || (string) self::PRODUCTS[$productCode]['channel'] !== $channel) {
            throw new RuntimeException('业务兼容测试维度不正确。');
        }
        if (!in_array($status, ['pending', 'passed', 'failed', 'blocked'], true)) {
            throw new RuntimeException('业务兼容状态不正确。');
        }
        $now = Web::now();
        $existing = Db::name('ai_business_compatibilities')
            ->where('ai_model_id', $modelId)
            ->where('capability_code', $capability)
            ->where('product_code', $productCode)
            ->where('execution_channel', $channel)
            ->find();
        $data = [
            'status' => $status,
            'last_test_id' => $testId,
            'workflow_version_id' => $channel === 'comfyui'
                ? $this->currentWorkflowVersionId($productCode)
                : null,
            'restriction_json' => (string) ($existing['restriction_json'] ?? '{}'),
            'notes' => mb_strimwidth(trim($notes), 0, 1000, ''),
            'updated_by_admin_id' => $adminId > 0 ? $adminId : null,
            'updated_at' => $now,
        ];
        if ($existing) {
            Db::name('ai_business_compatibilities')->where('id', (int) $existing['id'])->update($data);
            return Db::name('ai_business_compatibilities')->where('id', (int) $existing['id'])->find();
        }
        $id = (int) Db::name('ai_business_compatibilities')->insertGetId($data + [
            'ai_model_id' => $modelId,
            'capability_code' => $capability,
            'product_code' => $productCode,
            'execution_channel' => $channel,
            'created_at' => $now,
        ]);
        return Db::name('ai_business_compatibilities')->where('id', $id)->find();
    }

    public function productForProfile(string $capability, string $profile): string
    {
        $profile = strtolower(trim($profile));
        if (in_array($capability, [AiCapability::VIDEO_IMAGE_TO_VIDEO, AiCapability::VIDEO_TEXT_TO_VIDEO], true)) {
            return 'product_video';
        }
        return match ($profile) {
            'free', 'free_create' => 'free_create',
            'image_edit', 'canvas' => 'canvas_edit',
            'detail_page', 'detail' => 'detail_page',
            'listing_bundle', 'assets' => 'listing_bundle',
            default => 'product_image',
        };
    }

    public function channelForProduct(string $productCode): string
    {
        return (string) (self::PRODUCTS[$productCode]['channel'] ?? 'direct');
    }

    private function key(int $modelId, string $capability, string $productCode, string $channel): string
    {
        return implode(':', [$modelId, $capability, $productCode, $channel]);
    }

    private function currentWorkflowVersionId(string $productCode): ?int
    {
        $id = (int) Db::name('creative_products')
            ->where('code', $productCode)
            ->value('current_workflow_version_id');
        return $id > 0 ? $id : null;
    }

    private function decodeObject(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
