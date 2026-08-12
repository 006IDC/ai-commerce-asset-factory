<?php

declare(strict_types=1);

namespace app\support;

use think\facade\Db;
use think\facade\Session;

final class LoginThrottle
{
    private const ACCOUNT_LIMIT = 5;
    private const IP_LIMIT = 20;
    private const WINDOW_SECONDS = 900;

    public static function isLocked(string $scope, string $identifier, string $ipAddress): bool
    {
        if ((int) Session::get("login_throttle.{$scope}.locked_until", 0) > time()) {
            return true;
        }

        $now = time();
        return self::recentCount($scope, 'account', self::hash($identifier), $now) >= self::ACCOUNT_LIMIT
            || self::recentCount($scope, 'ip', self::hash($ipAddress), $now) >= self::IP_LIMIT;
    }

    public static function remainingSeconds(string $scope, string $identifier, string $ipAddress): int
    {
        $sessionRemaining = max(0, (int) Session::get("login_throttle.{$scope}.locked_until", 0) - time());
        $accountRemaining = self::remainingFor($scope, 'account', self::hash($identifier));
        $ipRemaining = self::remainingFor($scope, 'ip', self::hash($ipAddress));

        return max($sessionRemaining, $accountRemaining, $ipRemaining);
    }

    public static function fail(string $scope, string $identifier, string $ipAddress): void
    {
        $key = "login_throttle.{$scope}";
        $attempts = (int) Session::get("{$key}.attempts", 0) + 1;
        Session::set("{$key}.attempts", $attempts);
        if ($attempts >= 5) {
            Session::set("{$key}.locked_until", time() + self::WINDOW_SECONDS);
        }

        $now = date('Y-m-d H:i:s');
        $events = [
            ['scope' => $scope, 'key_type' => 'account', 'key_hash' => self::hash($identifier), 'created_at' => $now],
            ['scope' => $scope, 'key_type' => 'ip', 'key_hash' => self::hash($ipAddress), 'created_at' => $now],
        ];
        // PostgreSQL infers insertAll() UNION parameters as text, which cannot be assigned to TIMESTAMP.
        foreach ($events as $event) {
            Db::name('login_throttle_events')->insert($event);
        }
        Db::name('login_throttle_events')->where('created_at', '<', date('Y-m-d H:i:s', time() - 2592000))->delete();
    }

    public static function clear(string $scope, string $identifier): void
    {
        Session::delete("login_throttle.{$scope}");
        Db::name('login_throttle_events')
            ->where('scope', $scope)
            ->where('key_type', 'account')
            ->where('key_hash', self::hash($identifier))
            ->delete();
    }

    private static function recentCount(string $scope, string $keyType, string $keyHash, int $now): int
    {
        return (int) Db::name('login_throttle_events')
            ->where('scope', $scope)
            ->where('key_type', $keyType)
            ->where('key_hash', $keyHash)
            ->where('created_at', '>=', date('Y-m-d H:i:s', $now - self::WINDOW_SECONDS))
            ->count();
    }

    private static function remainingFor(string $scope, string $keyType, string $keyHash): int
    {
        $last = Db::name('login_throttle_events')
            ->where('scope', $scope)
            ->where('key_type', $keyType)
            ->where('key_hash', $keyHash)
            ->order('id', 'desc')
            ->value('created_at');
        if (!$last) {
            return 0;
        }

        return max(0, strtotime((string) $last) + self::WINDOW_SECONDS - time());
    }

    private static function hash(string $value): string
    {
        return hash('sha256', trim(strtolower($value)));
    }
}
