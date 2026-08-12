<?php

declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;

/** Canonical detail-page screen keys shared by planning, preflight and workers. */
final class DetailPageScreenType
{
    public const CANONICAL = [
        'detail_01_hero', 'detail_02_overview', 'detail_03_benefit', 'detail_04_benefit',
        'detail_05_detail', 'detail_06_function', 'detail_07_scene', 'detail_08_spec',
        'detail_09_audience', 'detail_10_trust',
    ];

    public const ALIASES = [
        'detail_04_secondary' => 'detail_04_benefit',
        'detail_09_lifestyle' => 'detail_09_audience',
    ];

    public static function normalize(mixed $value): string
    {
        $value = trim((string) $value);
        return self::ALIASES[$value] ?? $value;
    }

    public static function normalizeList(mixed $values, bool $strict = true): array
    {
        $values = is_array($values) ? $values : [$values];
        $allowed = self::CANONICAL;
        $normalized = [];
        foreach ($values as $value) {
            $screen = self::normalize($value);
            if ($screen === '') {
                continue;
            }
            if (!in_array($screen, $allowed, true)) {
                if ($strict) {
                    throw new InvalidArgumentException('详情页屏类型无效：' . $screen . '。');
                }
                continue;
            }
            $normalized[] = $screen;
        }
        return array_values($normalized);
    }

    public static function isCanonical(string $value): bool
    {
        return in_array($value, self::CANONICAL, true);
    }
}
