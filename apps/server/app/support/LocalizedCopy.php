<?php

declare(strict_types=1);

namespace app\support;

use RuntimeException;
use Transliterator;

final class LocalizedCopy
{
    private const CHINESE_TRANSLITERATORS = [
        'zh-CN' => 'Traditional-Simplified',
        'zh-TW' => 'Simplified-Traditional',
    ];

    public static function normalize(string $value, string $language): string
    {
        $value = trim($value);
        $transliteratorId = self::CHINESE_TRANSLITERATORS[$language] ?? '';
        if ($value === '' || $transliteratorId === '' || preg_match('/\p{Han}/u', $value) !== 1) {
            return $value;
        }
        if (!class_exists(Transliterator::class)) {
            throw new RuntimeException('缺少 PHP intl Transliterator，无法保证中文文案字形一致。');
        }
        $transliterator = Transliterator::create($transliteratorId);
        if (!$transliterator instanceof Transliterator) {
            throw new RuntimeException('无法初始化中文文案字形转换器：' . $transliteratorId);
        }
        $normalized = $transliterator->transliterate($value);
        if (!is_string($normalized)) {
            throw new RuntimeException('中文文案字形转换失败。');
        }
        return trim($normalized);
    }

    public static function isConsistent(string $value, string $language): bool
    {
        return trim($value) === self::normalize($value, $language);
    }

    public static function promptRule(string $language): string
    {
        return match ($language) {
            'zh-TW' => '所有消费者可见中文必须逐字使用繁体字形，禁止混入任何简体字；语义相同但字形为简体也属于失败。',
            'zh-CN' => '所有消费者可见中文必须逐字使用简体字形，禁止混入任何繁体字；语义相同但字形为繁体也属于失败。',
            default => '',
        };
    }
}
