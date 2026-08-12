<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use think\facade\Db;

/** Stores reusable delivery intent without duplicating product facts. */
final class ProductLaunchTargetService
{
    public const VERSION = 'product_launch_targets_v1';

    public function forSku(array $sku, ?array $launchTask = null, array $projects = []): array
    {
        $saved = $this->object($sku['launch_targets_json'] ?? null);
        $targets = $this->normalizeTargets($saved['targets'] ?? []);
        $direction = trim((string) ($sku['launch_direction'] ?? $saved['launch_direction'] ?? ''));
        if ($targets === []) {
            $context = $this->object($launchTask['context_json'] ?? null);
            $platform = trim((string) ($context['platform'] ?? ''));
            $language = trim((string) ($context['language'] ?? ''));
            $direction = $direction !== '' ? $direction : trim((string) ($context['brief'] ?? ''));
            if ($platform !== '' && $language !== '') {
                $targets[] = $this->target($platform, $language, true);
            }
        }
        foreach ($projects as $project) {
            if (!is_array($project)) {
                continue;
            }
            $platform = trim((string) ($project['platform'] ?? ''));
            $language = trim((string) ($project['language'] ?? ''));
            if ($platform !== '' && $language !== '') {
                $targets[] = $this->target($platform, $language, $targets === []);
            }
        }
        $targets = $this->normalizeTargets($targets);
        if ($targets !== [] && !array_filter($targets, static fn (array $item): bool => !empty($item['primary']))) {
            $targets[0]['primary'] = true;
        }
        return [
            'schema_version' => self::VERSION,
            'targets' => $targets,
            'launch_direction' => mb_strimwidth($direction, 0, 1200, ''),
        ];
    }

    public function save(int $skuId, int $merchantId, mixed $platforms, mixed $languages, string $direction = ''): array
    {
        $platforms = is_array($platforms) ? array_values($platforms) : [$platforms];
        $languages = is_array($languages) ? array_values($languages) : [$languages];
        $count = min(12, max(count($platforms), count($languages)));
        $targets = [];
        for ($index = 0; $index < $count; $index++) {
            $platform = trim((string) ($platforms[$index] ?? $platforms[0] ?? ''));
            $language = trim((string) ($languages[$index] ?? $languages[0] ?? ''));
            if ($platform === '' || $language === '') {
                continue;
            }
            $targets[] = $this->target($platform, $language, $index === 0);
        }
        $payload = [
            'schema_version' => self::VERSION,
            'targets' => $this->normalizeTargets($targets),
            'launch_direction' => mb_strimwidth(trim($direction), 0, 1200, ''),
            'updated_at' => Web::now(),
        ];
        Db::name('skus')->where('id', $skuId)->where('merchant_id', $merchantId)->update([
            'launch_targets_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'launch_direction' => $payload['launch_direction'] !== '' ? $payload['launch_direction'] : null,
            'updated_at' => Web::now(),
        ]);
        return $payload;
    }

    public function persistFromLaunch(int $skuId, int $merchantId, array $context): void
    {
        $this->save(
            $skuId,
            $merchantId,
            [(string) ($context['platform'] ?? 'amazon')],
            [(string) ($context['language'] ?? 'en-US')],
            (string) ($context['brief'] ?? '')
        );
    }

    private function normalizeTargets(mixed $targets): array
    {
        $result = [];
        foreach (is_array($targets) ? $targets : [] as $target) {
            if (!is_array($target)) {
                continue;
            }
            $platform = trim((string) ($target['platform'] ?? ''));
            $language = trim((string) ($target['language'] ?? ''));
            if (!isset(generation_platform_options()[$platform]) || !isset(generation_language_options()[$language])) {
                continue;
            }
            $key = $platform . ':' . $language;
            $result[$key] = $this->target($platform, $language, !empty($target['primary']));
        }
        return array_values(array_slice($result, 0, 12, true));
    }

    private function target(string $platform, string $language, bool $primary): array
    {
        return [
            'key' => $platform . ':' . $language,
            'platform' => $platform,
            'platform_label' => platform_label($platform),
            'language' => $language,
            'language_label' => generation_language_label($language),
            'primary' => $primary,
        ];
    }

    private function object(mixed $value): array
    {
        if (is_array($value)) {
            return array_is_list($value) ? [] : $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
    }
}
