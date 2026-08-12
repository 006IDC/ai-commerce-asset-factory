<?php

declare(strict_types=1);

namespace app\service;

final class AiCapabilityCenterSchema
{
    public static function statements(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                "CREATE TABLE IF NOT EXISTS ai_model_sync_batches (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, source VARCHAR(30) NOT NULL DEFAULT 'sub2api', status VARCHAR(30) NOT NULL, discovered_count INT NOT NULL DEFAULT 0, new_count INT NOT NULL DEFAULT 0, missing_count INT NOT NULL DEFAULT 0, summary_json LONGTEXT NOT NULL, initiated_by_admin_id BIGINT UNSIGNED NULL, started_at DATETIME NOT NULL, completed_at DATETIME NULL, PRIMARY KEY (id), KEY idx_ai_model_sync_batches_started (started_at, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS ai_model_capabilities (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ai_model_id BIGINT UNSIGNED NOT NULL, capability_code VARCHAR(60) NOT NULL, confirmation_status VARCHAR(30) NOT NULL DEFAULT 'suggested', test_status VARCHAR(30) NOT NULL DEFAULT 'pending', suggested_by VARCHAR(30) NOT NULL DEFAULT 'catalog', confirmed_by_admin_id BIGINT UNSIGNED NULL, last_test_id BIGINT UNSIGNED NULL, notes TEXT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_ai_model_capability (ai_model_id, capability_code), KEY idx_ai_model_capabilities_status (capability_code, confirmation_status, test_status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS ai_capability_tests (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ai_model_id BIGINT UNSIGNED NOT NULL, capability_code VARCHAR(60) NOT NULL, test_scope VARCHAR(30) NOT NULL, product_code VARCHAR(60) NULL, execution_channel VARCHAR(20) NOT NULL DEFAULT 'direct', workflow_version_id BIGINT UNSIGNED NULL, status VARCHAR(30) NOT NULL, request_id VARCHAR(190) NULL, duration_ms INT NOT NULL DEFAULT 0, paid_confirmed TINYINT NOT NULL DEFAULT 0, result_json LONGTEXT NOT NULL, tested_by_admin_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_ai_capability_tests_lookup (ai_model_id, capability_code, created_at), KEY idx_ai_capability_tests_business (product_code, execution_channel, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS ai_business_compatibilities (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ai_model_id BIGINT UNSIGNED NOT NULL, capability_code VARCHAR(60) NOT NULL, product_code VARCHAR(60) NOT NULL, execution_channel VARCHAR(20) NOT NULL, workflow_version_id BIGINT UNSIGNED NULL, status VARCHAR(30) NOT NULL DEFAULT 'pending', last_test_id BIGINT UNSIGNED NULL, restriction_json LONGTEXT NOT NULL, notes TEXT NULL, updated_by_admin_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_ai_business_compatibility (ai_model_id, capability_code, product_code, execution_channel), KEY idx_ai_business_compatibilities_ready (product_code, capability_code, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ];
        }
        if ($driver === 'pgsql') {
            return [
                "CREATE TABLE IF NOT EXISTS ai_model_sync_batches (id BIGSERIAL PRIMARY KEY, source VARCHAR(30) NOT NULL DEFAULT 'sub2api', status VARCHAR(30) NOT NULL, discovered_count INTEGER NOT NULL DEFAULT 0, new_count INTEGER NOT NULL DEFAULT 0, missing_count INTEGER NOT NULL DEFAULT 0, summary_json TEXT NOT NULL DEFAULT '{}', initiated_by_admin_id BIGINT NULL, started_at TIMESTAMP NOT NULL, completed_at TIMESTAMP NULL)",
                'CREATE INDEX IF NOT EXISTS idx_ai_model_sync_batches_started ON ai_model_sync_batches(started_at, id)',
                "CREATE TABLE IF NOT EXISTS ai_model_capabilities (id BIGSERIAL PRIMARY KEY, ai_model_id BIGINT NOT NULL, capability_code VARCHAR(60) NOT NULL, confirmation_status VARCHAR(30) NOT NULL DEFAULT 'suggested', test_status VARCHAR(30) NOT NULL DEFAULT 'pending', suggested_by VARCHAR(30) NOT NULL DEFAULT 'catalog', confirmed_by_admin_id BIGINT NULL, last_test_id BIGINT NULL, notes TEXT NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL, UNIQUE (ai_model_id, capability_code))",
                'CREATE INDEX IF NOT EXISTS idx_ai_model_capabilities_status ON ai_model_capabilities(capability_code, confirmation_status, test_status)',
                "CREATE TABLE IF NOT EXISTS ai_capability_tests (id BIGSERIAL PRIMARY KEY, ai_model_id BIGINT NOT NULL, capability_code VARCHAR(60) NOT NULL, test_scope VARCHAR(30) NOT NULL, product_code VARCHAR(60) NULL, execution_channel VARCHAR(20) NOT NULL DEFAULT 'direct', workflow_version_id BIGINT NULL, status VARCHAR(30) NOT NULL, request_id VARCHAR(190) NULL, duration_ms INTEGER NOT NULL DEFAULT 0, paid_confirmed SMALLINT NOT NULL DEFAULT 0, result_json TEXT NOT NULL DEFAULT '{}', tested_by_admin_id BIGINT NULL, created_at TIMESTAMP NOT NULL)",
                'CREATE INDEX IF NOT EXISTS idx_ai_capability_tests_lookup ON ai_capability_tests(ai_model_id, capability_code, created_at)',
                'CREATE INDEX IF NOT EXISTS idx_ai_capability_tests_business ON ai_capability_tests(product_code, execution_channel, status)',
                "CREATE TABLE IF NOT EXISTS ai_business_compatibilities (id BIGSERIAL PRIMARY KEY, ai_model_id BIGINT NOT NULL, capability_code VARCHAR(60) NOT NULL, product_code VARCHAR(60) NOT NULL, execution_channel VARCHAR(20) NOT NULL, workflow_version_id BIGINT NULL, status VARCHAR(30) NOT NULL DEFAULT 'pending', last_test_id BIGINT NULL, restriction_json TEXT NOT NULL DEFAULT '{}', notes TEXT NULL, updated_by_admin_id BIGINT NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL, UNIQUE (ai_model_id, capability_code, product_code, execution_channel))",
                'CREATE INDEX IF NOT EXISTS idx_ai_business_compatibilities_ready ON ai_business_compatibilities(product_code, capability_code, status)',
            ];
        }

        return [
            "CREATE TABLE IF NOT EXISTS ai_model_sync_batches (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT NOT NULL DEFAULT 'sub2api', status TEXT NOT NULL, discovered_count INTEGER NOT NULL DEFAULT 0, new_count INTEGER NOT NULL DEFAULT 0, missing_count INTEGER NOT NULL DEFAULT 0, summary_json TEXT NOT NULL DEFAULT '{}', initiated_by_admin_id INTEGER NULL, started_at TEXT NOT NULL, completed_at TEXT NULL)",
            'CREATE INDEX IF NOT EXISTS idx_ai_model_sync_batches_started ON ai_model_sync_batches(started_at, id)',
            "CREATE TABLE IF NOT EXISTS ai_model_capabilities (id INTEGER PRIMARY KEY AUTOINCREMENT, ai_model_id INTEGER NOT NULL, capability_code TEXT NOT NULL, confirmation_status TEXT NOT NULL DEFAULT 'suggested', test_status TEXT NOT NULL DEFAULT 'pending', suggested_by TEXT NOT NULL DEFAULT 'catalog', confirmed_by_admin_id INTEGER NULL, last_test_id INTEGER NULL, notes TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (ai_model_id, capability_code))",
            'CREATE INDEX IF NOT EXISTS idx_ai_model_capabilities_status ON ai_model_capabilities(capability_code, confirmation_status, test_status)',
            "CREATE TABLE IF NOT EXISTS ai_capability_tests (id INTEGER PRIMARY KEY AUTOINCREMENT, ai_model_id INTEGER NOT NULL, capability_code TEXT NOT NULL, test_scope TEXT NOT NULL, product_code TEXT NULL, execution_channel TEXT NOT NULL DEFAULT 'direct', workflow_version_id INTEGER NULL, status TEXT NOT NULL, request_id TEXT NULL, duration_ms INTEGER NOT NULL DEFAULT 0, paid_confirmed INTEGER NOT NULL DEFAULT 0, result_json TEXT NOT NULL DEFAULT '{}', tested_by_admin_id INTEGER NULL, created_at TEXT NOT NULL)",
            'CREATE INDEX IF NOT EXISTS idx_ai_capability_tests_lookup ON ai_capability_tests(ai_model_id, capability_code, created_at)',
            'CREATE INDEX IF NOT EXISTS idx_ai_capability_tests_business ON ai_capability_tests(product_code, execution_channel, status)',
            "CREATE TABLE IF NOT EXISTS ai_business_compatibilities (id INTEGER PRIMARY KEY AUTOINCREMENT, ai_model_id INTEGER NOT NULL, capability_code TEXT NOT NULL, product_code TEXT NOT NULL, execution_channel TEXT NOT NULL, workflow_version_id INTEGER NULL, status TEXT NOT NULL DEFAULT 'pending', last_test_id INTEGER NULL, restriction_json TEXT NOT NULL DEFAULT '{}', notes TEXT NULL, updated_by_admin_id INTEGER NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (ai_model_id, capability_code, product_code, execution_channel))",
            'CREATE INDEX IF NOT EXISTS idx_ai_business_compatibilities_ready ON ai_business_compatibilities(product_code, capability_code, status)',
        ];
    }
}
