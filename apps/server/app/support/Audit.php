<?php

declare(strict_types=1);

namespace app\support;

use think\facade\Db;

final class Audit
{
    public static function log(
        string $actorType,
        int $actorId,
        string $action,
        string $targetType,
        string|int|null $targetId,
        string $ipAddress,
        array $details = []
    ): void {
        Db::name('audit_logs')->insert([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId === null ? null : (string) $targetId,
            'ip_address' => $ipAddress,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => Web::now(),
        ]);
    }
}
