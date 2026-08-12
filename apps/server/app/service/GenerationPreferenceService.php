<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use think\facade\Db;

final class GenerationPreferenceService
{
    public function forUser(int $userId, string $defaultPlatform = 'douyin', string $defaultLanguage = 'zh-CN'): array
    {
        $user = Db::name('merchant_users')->where('id', $userId)
            ->field('preferred_generation_platform,preferred_generation_language')->find() ?: [];
        $platform = (string) ($user['preferred_generation_platform'] ?? '');
        $language = (string) ($user['preferred_generation_language'] ?? '');

        return [
            'platform' => isset(generation_platform_options()[$platform]) ? $platform : $defaultPlatform,
            'language' => isset(generation_language_options()[$language]) ? $language : $defaultLanguage,
        ];
    }

    public function remember(int $userId, string $platform, string $language): void
    {
        if (!isset(generation_platform_options()[$platform]) || !isset(generation_language_options()[$language])) {
            return;
        }
        Db::name('merchant_users')->where('id', $userId)->update([
            'preferred_generation_platform' => $platform,
            'preferred_generation_language' => $language,
            'updated_at' => Web::now(),
        ]);
    }
}
