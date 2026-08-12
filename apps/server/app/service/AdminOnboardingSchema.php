<?php

declare(strict_types=1);

namespace app\service;

final class AdminOnboardingSchema
{
    public static function statements(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                "CREATE TABLE IF NOT EXISTS admin_onboarding_progress (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    admin_id BIGINT UNSIGNED NOT NULL,
                    status VARCHAR(40) NOT NULL DEFAULT 'pending',
                    current_step VARCHAR(40) NOT NULL DEFAULT 'welcome',
                    step_state_json LONGTEXT NOT NULL,
                    started_at DATETIME NULL,
                    completed_at DATETIME NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_admin_onboarding_admin (admin_id),
                    KEY idx_admin_onboarding_status (status, updated_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ];
        }
        $id = $driver === 'mysql'
            ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'
            : ($driver === 'pgsql' ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT');
        $adminId = $driver === 'mysql' ? 'BIGINT UNSIGNED NOT NULL' : 'BIGINT NOT NULL';
        $string = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(40)';
        $timestamp = $driver === 'sqlite' ? 'TEXT' : ($driver === 'pgsql' ? 'TIMESTAMP' : 'DATETIME');

        return [
            "CREATE TABLE IF NOT EXISTS admin_onboarding_progress (
                id {$id},
                admin_id {$adminId},
                status {$string} NOT NULL DEFAULT 'pending',
                current_step {$string} NOT NULL DEFAULT 'welcome',
                step_state_json TEXT NOT NULL,
                started_at {$timestamp} NULL,
                completed_at {$timestamp} NULL,
                created_at {$timestamp} NOT NULL,
                updated_at {$timestamp} NOT NULL
            )",
            'CREATE UNIQUE INDEX IF NOT EXISTS uk_admin_onboarding_admin ON admin_onboarding_progress(admin_id)',
            'CREATE INDEX IF NOT EXISTS idx_admin_onboarding_status ON admin_onboarding_progress(status, updated_at)',
        ];
    }
}
