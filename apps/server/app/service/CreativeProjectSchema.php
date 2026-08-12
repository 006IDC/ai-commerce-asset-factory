<?php

declare(strict_types=1);

namespace app\service;

final class CreativeProjectSchema
{
    public static function statements(string $driver): array
    {
        return match ($driver) {
            'mysql' => self::mysql(),
            'pgsql' => self::pgsql(),
            default => self::sqlite(),
        };
    }

    private static function mysql(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS creative_projects (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, merchant_id BIGINT UNSIGNED NOT NULL, created_by_user_id BIGINT UNSIGNED NULL, sku_id BIGINT UNSIGNED NULL, title VARCHAR(120) NOT NULL, project_type VARCHAR(20) NOT NULL DEFAULT 'image', status VARCHAR(20) NOT NULL DEFAULT 'active', cover_asset_id BIGINT UNSIGNED NULL, document_json LONGTEXT NOT NULL, document_hash CHAR(64) NOT NULL, document_version INT NOT NULL DEFAULT 1, current_revision_no INT NOT NULL DEFAULT 1, legacy_canvas_migrated TINYINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_creative_projects_merchant (merchant_id, status, updated_at, id), KEY idx_creative_projects_sku (sku_id, status, id), CONSTRAINT fk_creative_projects_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id), CONSTRAINT fk_creative_projects_user FOREIGN KEY (created_by_user_id) REFERENCES merchant_users(id) ON DELETE SET NULL, CONSTRAINT fk_creative_projects_sku FOREIGN KEY (sku_id) REFERENCES skus(id) ON DELETE SET NULL, CONSTRAINT fk_creative_projects_cover FOREIGN KEY (cover_asset_id) REFERENCES assets(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS creative_project_revisions (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, project_id BIGINT UNSIGNED NOT NULL, revision_no INT NOT NULL, schema_version INT NOT NULL DEFAULT 2, source VARCHAR(20) NOT NULL DEFAULT 'auto', document_hash CHAR(64) NOT NULL, document_json LONGTEXT NOT NULL, created_by_user_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_creative_project_revision (project_id, revision_no), KEY idx_creative_project_revisions_created (project_id, created_at, id), CONSTRAINT fk_creative_revisions_project FOREIGN KEY (project_id) REFERENCES creative_projects(id) ON DELETE CASCADE, CONSTRAINT fk_creative_revisions_user FOREIGN KEY (created_by_user_id) REFERENCES merchant_users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS creative_project_assets (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, project_id BIGINT UNSIGNED NOT NULL, asset_id BIGINT UNSIGNED NOT NULL, node_uuid VARCHAR(64) NOT NULL, role VARCHAR(30) NOT NULL DEFAULT 'source', created_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_creative_project_node (project_id, node_uuid), KEY idx_creative_project_assets_asset (asset_id, project_id), CONSTRAINT fk_creative_project_assets_project FOREIGN KEY (project_id) REFERENCES creative_projects(id) ON DELETE CASCADE, CONSTRAINT fk_creative_project_assets_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS creative_project_runs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, project_id BIGINT UNSIGNED NOT NULL, node_uuid VARCHAR(64) NULL, job_id BIGINT UNSIGNED NOT NULL, run_type VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'queued', input_snapshot_json LONGTEXT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_creative_project_run_job (job_id), KEY idx_creative_project_runs_project (project_id, status, id), CONSTRAINT fk_creative_project_runs_project FOREIGN KEY (project_id) REFERENCES creative_projects(id) ON DELETE CASCADE, CONSTRAINT fk_creative_project_runs_job FOREIGN KEY (job_id) REFERENCES generation_jobs(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }

    private static function pgsql(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS creative_projects (id BIGSERIAL PRIMARY KEY, merchant_id BIGINT NOT NULL REFERENCES merchants(id), created_by_user_id BIGINT NULL REFERENCES merchant_users(id) ON DELETE SET NULL, sku_id BIGINT NULL REFERENCES skus(id) ON DELETE SET NULL, title VARCHAR(120) NOT NULL, project_type VARCHAR(20) NOT NULL DEFAULT 'image', status VARCHAR(20) NOT NULL DEFAULT 'active', cover_asset_id BIGINT NULL REFERENCES assets(id) ON DELETE SET NULL, document_json TEXT NOT NULL, document_hash CHAR(64) NOT NULL, document_version INTEGER NOT NULL DEFAULT 1, current_revision_no INTEGER NOT NULL DEFAULT 1, legacy_canvas_migrated SMALLINT NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL)",
            'CREATE INDEX IF NOT EXISTS idx_creative_projects_merchant ON creative_projects(merchant_id, status, updated_at, id)',
            'CREATE INDEX IF NOT EXISTS idx_creative_projects_sku ON creative_projects(sku_id, status, id)',
            "CREATE TABLE IF NOT EXISTS creative_project_revisions (id BIGSERIAL PRIMARY KEY, project_id BIGINT NOT NULL REFERENCES creative_projects(id) ON DELETE CASCADE, revision_no INTEGER NOT NULL, schema_version INTEGER NOT NULL DEFAULT 2, source VARCHAR(20) NOT NULL DEFAULT 'auto', document_hash CHAR(64) NOT NULL, document_json TEXT NOT NULL, created_by_user_id BIGINT NULL REFERENCES merchant_users(id) ON DELETE SET NULL, created_at TIMESTAMP NOT NULL, UNIQUE(project_id, revision_no))",
            'CREATE INDEX IF NOT EXISTS idx_creative_project_revisions_created ON creative_project_revisions(project_id, created_at, id)',
            "CREATE TABLE IF NOT EXISTS creative_project_assets (id BIGSERIAL PRIMARY KEY, project_id BIGINT NOT NULL REFERENCES creative_projects(id) ON DELETE CASCADE, asset_id BIGINT NOT NULL REFERENCES assets(id) ON DELETE CASCADE, node_uuid VARCHAR(64) NOT NULL, role VARCHAR(30) NOT NULL DEFAULT 'source', created_at TIMESTAMP NOT NULL, UNIQUE(project_id, node_uuid))",
            'CREATE INDEX IF NOT EXISTS idx_creative_project_assets_asset ON creative_project_assets(asset_id, project_id)',
            "CREATE TABLE IF NOT EXISTS creative_project_runs (id BIGSERIAL PRIMARY KEY, project_id BIGINT NOT NULL REFERENCES creative_projects(id) ON DELETE CASCADE, node_uuid VARCHAR(64) NULL, job_id BIGINT NOT NULL UNIQUE REFERENCES generation_jobs(id) ON DELETE CASCADE, run_type VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'queued', input_snapshot_json TEXT NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL)",
            'CREATE INDEX IF NOT EXISTS idx_creative_project_runs_project ON creative_project_runs(project_id, status, id)',
        ];
    }

    private static function sqlite(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS creative_projects (id INTEGER PRIMARY KEY AUTOINCREMENT, merchant_id INTEGER NOT NULL, created_by_user_id INTEGER NULL, sku_id INTEGER NULL, title TEXT NOT NULL, project_type TEXT NOT NULL DEFAULT 'image', status TEXT NOT NULL DEFAULT 'active', cover_asset_id INTEGER NULL, document_json TEXT NOT NULL, document_hash TEXT NOT NULL, document_version INTEGER NOT NULL DEFAULT 1, current_revision_no INTEGER NOT NULL DEFAULT 1, legacy_canvas_migrated INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, FOREIGN KEY (merchant_id) REFERENCES merchants(id), FOREIGN KEY (created_by_user_id) REFERENCES merchant_users(id) ON DELETE SET NULL, FOREIGN KEY (sku_id) REFERENCES skus(id) ON DELETE SET NULL, FOREIGN KEY (cover_asset_id) REFERENCES assets(id) ON DELETE SET NULL)",
            'CREATE INDEX IF NOT EXISTS idx_creative_projects_merchant ON creative_projects(merchant_id, status, updated_at, id)',
            'CREATE INDEX IF NOT EXISTS idx_creative_projects_sku ON creative_projects(sku_id, status, id)',
            "CREATE TABLE IF NOT EXISTS creative_project_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, revision_no INTEGER NOT NULL, schema_version INTEGER NOT NULL DEFAULT 2, source TEXT NOT NULL DEFAULT 'auto', document_hash TEXT NOT NULL, document_json TEXT NOT NULL, created_by_user_id INTEGER NULL, created_at TEXT NOT NULL, UNIQUE(project_id, revision_no), FOREIGN KEY (project_id) REFERENCES creative_projects(id) ON DELETE CASCADE, FOREIGN KEY (created_by_user_id) REFERENCES merchant_users(id) ON DELETE SET NULL)",
            'CREATE INDEX IF NOT EXISTS idx_creative_project_revisions_created ON creative_project_revisions(project_id, created_at, id)',
            "CREATE TABLE IF NOT EXISTS creative_project_assets (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, asset_id INTEGER NOT NULL, node_uuid TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'source', created_at TEXT NOT NULL, UNIQUE(project_id, node_uuid), FOREIGN KEY (project_id) REFERENCES creative_projects(id) ON DELETE CASCADE, FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE)",
            'CREATE INDEX IF NOT EXISTS idx_creative_project_assets_asset ON creative_project_assets(asset_id, project_id)',
            "CREATE TABLE IF NOT EXISTS creative_project_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, node_uuid TEXT NULL, job_id INTEGER NOT NULL UNIQUE, run_type TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'queued', input_snapshot_json TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, FOREIGN KEY (project_id) REFERENCES creative_projects(id) ON DELETE CASCADE, FOREIGN KEY (job_id) REFERENCES generation_jobs(id) ON DELETE CASCADE)",
            'CREATE INDEX IF NOT EXISTS idx_creative_project_runs_project ON creative_project_runs(project_id, status, id)',
        ];
    }
}
