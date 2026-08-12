<?php

declare(strict_types=1);

namespace app\support;

use think\facade\Session;

final class AuthSession
{
    private const ADMIN_FINGERPRINT_KEY = 'admin_auth_fingerprint';
    private const MERCHANT_FINGERPRINT_KEY = 'merchant_auth_fingerprint';

    public static function bindAdmin(array $admin): void
    {
        self::bind(self::ADMIN_FINGERPRINT_KEY, (string) ($admin['password_hash'] ?? ''));
    }

    public static function bindMerchant(array $user): void
    {
        self::bind(self::MERCHANT_FINGERPRINT_KEY, (string) ($user['password_hash'] ?? ''));
    }

    public static function adminIsCurrent(array $admin): bool
    {
        return self::matches(
            (string) Session::get(self::ADMIN_FINGERPRINT_KEY, ''),
            (string) ($admin['password_hash'] ?? '')
        );
    }

    public static function merchantIsCurrent(array $user): bool
    {
        return self::matches(
            (string) Session::get(self::MERCHANT_FINGERPRINT_KEY, ''),
            (string) ($user['password_hash'] ?? '')
        );
    }

    public static function fingerprint(string $passwordHash): string
    {
        return $passwordHash === ''
            ? ''
            : hash('sha256', "asset-factory/auth-session/v1\0" . $passwordHash);
    }

    public static function matches(string $storedFingerprint, string $passwordHash): bool
    {
        $currentFingerprint = self::fingerprint($passwordHash);
        return $storedFingerprint !== ''
            && $currentFingerprint !== ''
            && hash_equals($currentFingerprint, $storedFingerprint);
    }

    private static function bind(string $key, string $passwordHash): void
    {
        $fingerprint = self::fingerprint($passwordHash);
        if ($fingerprint === '') {
            throw new \InvalidArgumentException('Cannot bind an authenticated session without a password hash.');
        }
        Session::set($key, $fingerprint);
    }
}
