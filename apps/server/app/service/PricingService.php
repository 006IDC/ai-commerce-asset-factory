<?php

declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class PricingService
{
    private const UNIT_TYPES = ['request', 'image', 'second'];

    public function __construct(private readonly SystemSettings $settings)
    {
    }

    public function configuration(): array
    {
        return [
            'imageGenerationCredits' => $this->imageGenerationCredits(),
            'videoGenerationCreditsPerSecond' => $this->videoGenerationCreditsPerSecond(),
            'canvasEditCredits' => $this->canvasEditCredits(),
            'productRecognitionCredits' => $this->productRecognitionCredits(),
            'promptOptimizationCredits' => $this->promptOptimizationCredits(),
        ];
    }

    public function imageGenerationCredits(): int
    {
        return $this->credits('image_generation_credits', 8);
    }

    public function productRecognitionCredits(): int
    {
        return 0;
    }

    public function videoGenerationCreditsPerSecond(): int
    {
        return $this->credits('video_generation_credits_per_second', 6);
    }

    public function creditsForUnits(array $price, float $units): int
    {
        $credits = max(0, (int) ($price['credits'] ?? 0));
        $unitValue = max(0.000001, (float) ($price['unit_value'] ?? 1));
        return max(0, (int) ceil(max(0, $units) / $unitValue) * $credits);
    }

    public function canvasEditCredits(): int
    {
        return $this->credits('canvas_edit_credits', $this->imageGenerationCredits());
    }

    public function promptOptimizationCredits(): int
    {
        return 0;
    }

    /**
     * Resolve the merchant-facing price for one billable unit.
     * Model-specific prices are optional so existing deployments keep their
     * legacy prices until an administrator explicitly configures an override.
     */
    public function priceForModel(
        string $capability,
        ?string $modelName,
        int $legacyFallback,
        ?int $modelDbId = null,
        ?string $gateway = null
    ): array
    {
        $modelName = trim((string) $modelName);
        if (!AiCapability::isMerchantBillable($capability)) {
            return [
                'capability' => $capability,
                'model_id' => $modelName,
                'model_db_id' => null,
                'unit_type' => 'request',
                'unit_value' => 1,
                'credits' => 0,
                'source' => 'platform',
                'price_id' => null,
            ];
        }
        $modelDbId = $modelDbId !== null && $modelDbId > 0 ? $modelDbId : null;
        if ($modelDbId !== null) {
            $resolved = Db::name('ai_models')
                ->where('id', $modelDbId)
                ->where('model_id', $modelName)
                ->value('id');
            $modelDbId = $resolved === null ? null : (int) $resolved;
        } elseif ($modelName !== '') {
            $query = Db::name('ai_models')->where('model_id', $modelName);
            $gateway = strtolower(trim((string) $gateway));
            if ($gateway !== '' && $gateway !== 'sub2api') {
                $query->where('gateway', $gateway);
            }
            $resolved = $query->order('id', 'desc')->value('id');
            $modelDbId = $resolved === null ? null : (int) $resolved;
        }

        if ($modelDbId !== null) {
            $price = Db::name('ai_model_prices')
                ->where('ai_model_id', $modelDbId)
                ->where('capability_code', $capability)
                ->where('enabled', 1)
                ->order('id', 'desc')
                ->find();
            if ($price) {
                $unitType = (string) ($price['unit_type'] ?? 'request');
                $unitValue = (float) ($price['unit_value'] ?? 1);
                if (!in_array($unitType, self::UNIT_TYPES, true)) {
                    $unitType = 'request';
                }
                if ($unitValue <= 0) {
                    $unitValue = 1;
                }

                return [
                    'capability' => $capability,
                    'model_id' => $modelName,
                    'model_db_id' => $modelDbId,
                    'unit_type' => $unitType,
                    'unit_value' => $unitValue,
                    'credits' => max(0, (int) ($price['credits'] ?? 0)),
                    'source' => 'model',
                    'price_id' => (int) $price['id'],
                ];
            }
        }

        $fallbackUnit = in_array($capability, [AiCapability::VIDEO_IMAGE_TO_VIDEO, AiCapability::VIDEO_TEXT_TO_VIDEO], true)
            ? 'second'
            : (in_array($capability, [AiCapability::IMAGE_GENERATION, AiCapability::IMAGE_EDIT], true) ? 'image' : 'request');
        return [
            'capability' => $capability,
            'model_id' => $modelName,
            'model_db_id' => $modelDbId,
            'unit_type' => $fallbackUnit,
            'unit_value' => 1,
            'credits' => max(0, $legacyFallback),
            'source' => 'legacy',
            'price_id' => null,
        ];
    }

    public function modelPrices(int $modelId): array
    {
        $prices = [];
        foreach (Db::name('ai_model_prices')->where('ai_model_id', $modelId)->order('id', 'asc')->select()->toArray() as $price) {
            $prices[(string) $price['capability_code']] = [
                'unit_type' => in_array((string) ($price['unit_type'] ?? ''), self::UNIT_TYPES, true) ? (string) $price['unit_type'] : 'request',
                'unit_value' => (float) ($price['unit_value'] ?? 1),
                'credits' => max(0, (int) ($price['credits'] ?? 0)),
                'enabled' => (int) ($price['enabled'] ?? 0) === 1,
                'id' => (int) $price['id'],
            ];
        }

        return $prices;
    }

    public function unitTypes(): array
    {
        return self::UNIT_TYPES;
    }

    private function credits(string $key, int $fallback): int
    {
        $value = $this->settings->get($key);
        if (!preg_match('/^\d+$/', $value)) {
            return $fallback;
        }

        return min(1000000, (int) $value);
    }
}
