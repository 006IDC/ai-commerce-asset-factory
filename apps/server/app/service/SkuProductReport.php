<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use think\facade\Db;

/**
 * Editable product report used by direct generation and detail-page planning.
 * It deliberately contains no evidence ledger, image-role contract or approval state.
 */
final class SkuProductReport
{
    public function isReady(array $sku): bool
    {
        return trim((string) ($sku['name'] ?? '')) !== ''
            && trim((string) ($sku['category'] ?? '')) !== ''
            && trim((string) ($sku['source_image_path'] ?? $sku['source_image_backup_path'] ?? '')) !== '';
    }

    public function searchTerms(array $sku): array
    {
        $terms = $this->list($sku['search_terms'] ?? []);
        return $terms !== [] ? $terms : array_values(array_filter([
            trim((string) ($sku['name'] ?? '')),
            trim((string) ($sku['category'] ?? '')),
        ]));
    }

    public function reportContext(array $sku): array
    {
        return array_filter([
            '商品名称' => trim((string) ($sku['name'] ?? '')),
            '商品类目' => trim((string) ($sku['category'] ?? '')),
            '商品摘要' => trim((string) ($sku['product_description'] ?? '')),
            '核心卖点' => $this->list($sku['selling_points'] ?? []),
            '外观、结构与包装' => trim((string) ($sku['report_appearance'] ?? '')),
            '商品主色' => $this->profileColors($sku),
            '材质与工艺' => trim((string) ($sku['report_materials'] ?? '')),
            '可见品牌信息' => trim((string) ($sku['report_brand_notes'] ?? '')),
            '核心功能' => $this->list($sku['report_core_functions'] ?? []),
            '使用场景' => $this->list($sku['report_use_scenarios'] ?? []),
            '目标人群' => trim((string) ($sku['report_target_audience'] ?? '')),
            '待核对信息' => $this->list($sku['report_uncertain_points'] ?? []),
            '风险表述' => $this->list($sku['report_prohibited_claims'] ?? []),
        ], static fn (mixed $value): bool => is_array($value) ? $value !== [] : trim((string) $value) !== '');
    }

    public function applyAnalysis(int $skuId, int $merchantId, array $product): void
    {
        $sku = Db::name('skus')->where('id', $skuId)->where('merchant_id', $merchantId)->find();
        if (!$sku) {
            return;
        }
        $draft = is_array($product['recognitionDraft'] ?? null) ? (array) $product['recognitionDraft'] : [];
        if ($draft !== []) {
            $product = array_replace($product, $draft);
        }
        $foundation = is_array($product['foundation'] ?? null) ? (array) $product['foundation'] : $product;
        $name = $this->limit($product['name'] ?? $foundation['name'] ?? $sku['name'] ?? '', 160);
        $category = $this->limit($product['category'] ?? $foundation['category'] ?? $sku['category'] ?? '', 100);
        $sellingPoints = $this->list($product['sellingPoints'] ?? $product['selling_points'] ?? $foundation['selling_points'] ?? []);
        $searchTerms = $this->list($product['searchTerms'] ?? $product['search_terms'] ?? $foundation['search_terms'] ?? []);
        if ($searchTerms === []) {
            $searchTerms = array_values(array_filter([$name, $category]));
        }
        $description = $this->limit($product['productDescription'] ?? $product['product_description'] ?? $foundation['product_description'] ?? '', 4000);
        $appearance = $this->limit($foundation['appearance'] ?? '', 1600);
        $colors = $this->list($foundation['primary_colors'] ?? $foundation['colors'] ?? []);
        if ($colors === []) {
            $colors = $this->visibleColors($appearance);
        }
        $now = Web::now();
        $payload = [
            'name' => $name !== '' ? $name : (string) ($sku['name'] ?? ''),
            'category' => $category !== '' ? $category : (string) ($sku['category'] ?? ''),
            'selling_points' => $this->json($sellingPoints),
            'product_description' => $description !== '' ? $description : null,
            'search_terms' => $this->json($searchTerms),
            'report_materials' => $this->nullable($foundation['materials'] ?? ''),
            'report_appearance' => $appearance !== '' ? $appearance : null,
            'report_target_audience' => $this->nullable($foundation['target_audience'] ?? ''),
            'report_use_scenarios' => $this->json($this->list($foundation['use_scenarios'] ?? [])),
            'report_core_functions' => $this->json($this->list($foundation['core_functions'] ?? $foundation['visible_components'] ?? [])),
            'report_uncertain_points' => $this->json($this->list($foundation['uncertain_points'] ?? [])),
            'report_prohibited_claims' => $this->json($this->list($foundation['prohibited_claims'] ?? [])),
            'report_brand_notes' => $this->nullable($foundation['brand_notes'] ?? ''),
            'foundation_source' => 'ai',
            'report_status' => $name !== '' && $category !== '' ? 'ready' : 'incomplete',
            'report_updated_at' => $now,
            'updated_at' => $now,
        ];
        Db::name('skus')->where('id', $skuId)->where('merchant_id', $merchantId)->update($payload);
    }

    public function ensureFoundation(int $skuId, int $merchantId): ?array
    {
        $sku = Db::name('skus')->where('id', $skuId)->where('merchant_id', $merchantId)->find();
        if (!$sku) {
            return null;
        }
        $status = $this->isReady($sku) ? 'ready' : 'incomplete';
        if ((string) ($sku['report_status'] ?? '') !== $status) {
            Db::name('skus')->where('id', $skuId)->where('merchant_id', $merchantId)->update([
                'report_status' => $status,
                'updated_at' => Web::now(),
            ]);
            $sku['report_status'] = $status;
        }
        return $sku;
    }

    public function updateFoundation(int $skuId, int $merchantId, array $input): void
    {
        $sku = Db::name('skus')->where('id', $skuId)->where('merchant_id', $merchantId)->find();
        if (!$sku) {
            return;
        }
        $now = Web::now();
        $appearance = trim((string) ($input['appearance'] ?? ''));
        $colors = $this->list($input['primary_colors'] ?? []);
        if ($colors !== [] && !str_contains($appearance, implode('、', $colors))) {
            $appearance = trim($appearance . "\n主色：" . implode('、', $colors));
        }
        Db::name('skus')->where('id', $skuId)->where('merchant_id', $merchantId)->update([
            'report_materials' => $this->nullable($input['materials'] ?? ''),
            'report_appearance' => $this->nullable($appearance),
            'report_target_audience' => $this->nullable($input['target_audience'] ?? ''),
            'report_use_scenarios' => $this->json($this->list($input['use_scenarios'] ?? [])),
            'report_core_functions' => $this->json($this->list($input['core_functions'] ?? [])),
            'report_uncertain_points' => $this->json($this->list($input['uncertain_points'] ?? [])),
            'report_prohibited_claims' => $this->json($this->list($input['prohibited_claims'] ?? [])),
            'report_brand_notes' => $this->nullable($input['brand_notes'] ?? ''),
            'foundation_source' => 'manual',
            'report_status' => $this->isReady($sku) ? 'ready' : 'incomplete',
            'report_updated_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function confirmBasicFields(int $skuId, int $merchantId): void
    {
        $sku = Db::name('skus')->where('id', $skuId)->where('merchant_id', $merchantId)->find();
        if (!$sku) {
            return;
        }
        Db::name('skus')->where('id', $skuId)->where('merchant_id', $merchantId)->update([
            'foundation_source' => 'manual',
            'report_status' => $this->isReady($sku) ? 'ready' : 'incomplete',
            'report_updated_at' => Web::now(),
            'updated_at' => Web::now(),
        ]);
    }

    public function importReport(int $skuId, int $merchantId, array $report): void
    {
        $sku = Db::name('skus')->where('id', $skuId)->where('merchant_id', $merchantId)->find();
        if (!$sku) {
            return;
        }
        $this->applyAnalysis($skuId, $merchantId, [
            'name' => $report['product_name'] ?? $sku['name'] ?? '',
            'category' => $report['category'] ?? $sku['category'] ?? '',
            'sellingPoints' => $report['selling_points'] ?? decode_json_list($sku['selling_points'] ?? '[]'),
            'searchTerms' => $report['search_terms'] ?? decode_json_list($sku['search_terms'] ?? '[]'),
            'productDescription' => $report['product_description'] ?? $sku['product_description'] ?? '',
            'foundation' => [
                'materials' => $report['materials'] ?? '',
                'appearance' => $report['appearance'] ?? '',
                'target_audience' => $report['target_audience'] ?? '',
                'use_scenarios' => $report['use_scenarios'] ?? [],
                'core_functions' => $report['core_functions'] ?? [],
                'uncertain_points' => $report['uncertain_points'] ?? [],
                'prohibited_claims' => $report['prohibited_claims'] ?? [],
                'brand_notes' => $report['brand_notes'] ?? '',
            ],
        ]);
    }

    private function profileColors(array $sku): array
    {
        return $this->visibleColors((string) ($sku['report_appearance'] ?? ''));
    }

    private function visibleColors(string $appearance): array
    {
        if (preg_match_all('/(?:米白|奶白|象牙白|藏青|墨绿|玫瑰金|香槟金|(?:深|浅|亮|暗)?[红橙黄绿青蓝紫粉棕褐黑白灰金银]色)/u', $appearance, $matches) > 0) {
            return array_values(array_unique(array_slice($matches[0], 0, 8)));
        }
        return [];
    }

    private function list(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = preg_split('/[\r\n,，、；;]+/u', $value) ?: [];
            }
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => mb_strimwidth(trim(is_scalar($item) ? (string) $item : ''), 0, 500, ''),
            $value
        ))));
    }

    private function json(array $value): string
    {
        return json_encode(array_values($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function nullable(mixed $value): ?string
    {
        $text = $this->limit($value, 1600);
        return $text !== '' ? $text : null;
    }

    private function limit(mixed $value, int $length): string
    {
        $text = is_array($value) ? implode('；', $this->list($value)) : trim((string) $value);
        return mb_strimwidth($text, 0, $length, '');
    }
}
