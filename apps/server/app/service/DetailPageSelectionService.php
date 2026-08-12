<?php

declare(strict_types=1);

namespace app\service;

final class DetailPageSelectionService
{
    /**
     * Pick the most useful modules for the requested image count, then restore
     * the page's narrative order for the generated set.
     */
    public function select(array $project, int $count): array
    {
        $options = detail_page_screen_options();
        $count = max(1, min(count($options), $count));
        $sellingPoints = decode_json_list($project['selling_points'] ?? '[]');
        $scenarios = decode_json_list($project['use_scenarios'] ?? '[]');
        $functions = decode_json_list($project['core_functions'] ?? '[]');
        $isService = (new ProductOfferingClassifier())->isService($project);
        $hasMaterials = !$isService && (trim((string) ($project['materials'] ?? '')) !== '' || trim((string) ($project['appearance'] ?? '')) !== '');
        $hasAudience = trim((string) ($project['target_audience'] ?? '')) !== '';
        $description = trim((string) ($project['product_description'] ?? ''));
        $hasSpecifications = preg_match('/\d|尺寸|规格|容量|重量|长度|宽度|高度|size|dimension|capacity|weight|measurement/iu', $description) === 1;
        $hasTrust = trim((string) ($project['brand_notes'] ?? '')) !== '' || trim((string) ($project['creative_brief'] ?? '')) !== '';

        $scores = $isService ? [
            'detail_01_hero' => 1000,
            'detail_03_benefit' => $sellingPoints !== [] ? 980 : 760,
            'detail_07_scene' => $scenarios !== [] ? 950 : 720,
            'detail_06_function' => $functions !== [] ? 930 : 740,
            'detail_02_overview' => 900,
            'detail_04_benefit' => count($sellingPoints) > 1 ? 880 : 700,
            'detail_10_trust' => $hasTrust ? 860 : 680,
            'detail_09_audience' => $hasAudience ? 820 : 640,
            'detail_08_spec' => $hasSpecifications ? 800 : 620,
            'detail_05_detail' => 420,
        ] : [
            'detail_01_hero' => 1000,
            'detail_03_benefit' => $sellingPoints !== [] ? 980 : 740,
            'detail_07_scene' => $scenarios !== [] ? 940 : 690,
            'detail_05_detail' => $hasMaterials ? 920 : 680,
            'detail_06_function' => $functions !== [] ? 900 : 660,
            'detail_02_overview' => 860,
            'detail_04_benefit' => count($sellingPoints) > 1 ? 840 : 700,
            'detail_09_audience' => $hasAudience ? 790 : 620,
            'detail_08_spec' => $hasSpecifications ? 740 : 540,
            'detail_10_trust' => $hasTrust ? 680 : 500,
        ];
        arsort($scores, SORT_NUMERIC);
        $selected = array_slice(array_keys($scores), 0, $count);

        return array_values(array_filter(array_keys($options), static fn (string $key): bool => in_array($key, $selected, true)));
    }
}
