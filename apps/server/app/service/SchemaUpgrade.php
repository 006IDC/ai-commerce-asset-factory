<?php

declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class SchemaUpgrade
{
    public function run(): array
    {
        $driver = (string) env('DB_DRIVER', 'sqlite');
        $changes = [];
        $onboardingTableExisted = $this->tableExists($driver, 'admin_onboarding_progress');
        array_push($changes, ...$this->retireRemovedFeatures($driver));
        if (Db::name('system_settings')->whereLike('setting_key', 'turnstile_%')->delete() > 0) {
            $changes[] = 'system_settings.turnstile_removed';
        }
        if (!$this->columnExists($driver, 'merchant_users', 'last_login_ip')) {
            $type = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(64)';
            Db::execute("ALTER TABLE merchant_users ADD COLUMN last_login_ip {$type} NULL");
            $changes[] = 'merchant_users.last_login_ip';
        }
        foreach (['preferred_generation_platform' => 40, 'preferred_generation_language' => 20] as $column => $length) {
            if (!$this->columnExists($driver, 'merchant_users', $column)) {
                $type = $driver === 'sqlite' ? 'TEXT' : "VARCHAR({$length})";
                Db::execute("ALTER TABLE merchant_users ADD COLUMN {$column} {$type} NULL");
                $changes[] = 'merchant_users.' . $column;
            }
        }
        if (!$this->columnExists($driver, 'skus', 'source_image_backup_path')) {
            Db::execute('ALTER TABLE skus ADD COLUMN source_image_backup_path TEXT NULL');
            $changes[] = 'skus.source_image_backup_path';
        }
        if (!$this->columnExists($driver, 'skus', 'reference_images_json')) {
            $type = $driver === 'mysql' ? 'LONGTEXT NULL' : "TEXT NOT NULL DEFAULT '[]'";
            Db::execute("ALTER TABLE skus ADD COLUMN reference_images_json {$type}");
            $changes[] = 'skus.reference_images_json';
        }
        if (!$this->columnExists($driver, 'skus', 'creative_brief')) {
            Db::execute('ALTER TABLE skus ADD COLUMN creative_brief TEXT NULL');
            $changes[] = 'skus.creative_brief';
        }
        $launchWorkspaceColumns = [
            'launch_targets_json' => $driver === 'mysql' ? 'LONGTEXT NULL' : "TEXT NOT NULL DEFAULT '{}'",
            'launch_direction' => 'TEXT NULL',
        ];
        foreach ($launchWorkspaceColumns as $column => $definition) {
            if (!$this->columnExists($driver, 'skus', $column)) {
                Db::execute("ALTER TABLE skus ADD COLUMN {$column} {$definition}");
                $changes[] = 'skus.' . $column;
            }
        }
        if (!$this->columnExists($driver, 'skus', 'product_description')) {
            Db::execute('ALTER TABLE skus ADD COLUMN product_description TEXT NULL');
            $changes[] = 'skus.product_description';
        }
        foreach (['preferred_image_ratio' => 20, 'preferred_image_model' => 160] as $column => $length) {
            if (!$this->columnExists($driver, 'skus', $column)) {
                $type = $driver === 'sqlite' ? 'TEXT' : "VARCHAR({$length})";
                Db::execute("ALTER TABLE skus ADD COLUMN {$column} {$type} NULL");
                $changes[] = 'skus.' . $column;
            }
        }
        $skuReportColumnsAdded = false;
        $legacyReportBackfillRequired = false;
        $skuReportColumns = [
            'search_terms' => $driver === 'mysql' ? 'LONGTEXT NULL' : "TEXT NOT NULL DEFAULT '[]'",
            'report_materials' => 'TEXT NULL',
            'report_appearance' => 'TEXT NULL',
            'report_target_audience' => 'TEXT NULL',
            'report_use_scenarios' => $driver === 'mysql' ? 'LONGTEXT NULL' : "TEXT NOT NULL DEFAULT '[]'",
            'report_core_functions' => $driver === 'mysql' ? 'LONGTEXT NULL' : "TEXT NOT NULL DEFAULT '[]'",
            'report_uncertain_points' => $driver === 'mysql' ? 'LONGTEXT NULL' : "TEXT NOT NULL DEFAULT '[]'",
            'report_prohibited_claims' => $driver === 'mysql' ? 'LONGTEXT NULL' : "TEXT NOT NULL DEFAULT '[]'",
            'report_brand_notes' => 'TEXT NULL',
            'foundation_source' => $driver === 'sqlite' ? "TEXT NULL" : "VARCHAR(30) NULL",
            'report_market_research_json' => 'TEXT NULL',
            'report_market_query' => 'TEXT NULL',
            'report_status' => $driver === 'sqlite' ? "TEXT NOT NULL DEFAULT 'draft'" : "VARCHAR(30) NOT NULL DEFAULT 'draft'",
            'report_source_project_id' => $driver === 'mysql' ? 'BIGINT UNSIGNED NULL' : ($driver === 'sqlite' ? 'INTEGER NULL' : 'BIGINT NULL'),
        ];
        foreach ($skuReportColumns as $column => $definition) {
            if (!$this->columnExists($driver, 'skus', $column)) {
                Db::execute("ALTER TABLE skus ADD COLUMN {$column} {$definition}");
                $changes[] = 'skus.' . $column;
                $skuReportColumnsAdded = true;
                if ($column !== 'foundation_source') {
                    $legacyReportBackfillRequired = true;
                }
            }
        }
        foreach (['report_market_updated_at', 'report_updated_at'] as $column) {
            if (!$this->columnExists($driver, 'skus', $column)) {
                $type = $driver === 'sqlite' ? 'TEXT' : ($driver === 'pgsql' ? 'TIMESTAMP' : 'DATETIME');
                Db::execute("ALTER TABLE skus ADD COLUMN {$column} {$type} NULL");
                $changes[] = 'skus.' . $column;
                $skuReportColumnsAdded = true;
            }
        }
        foreach ($this->detailPageTableSql($driver) as $sql) {
            Db::execute($sql);
        }
        $changes[] = 'detail_page_projects';
        $changes[] = 'detail_page_messages';
        if (!$this->columnExists($driver, 'detail_page_projects', 'sku_id')) {
            $type = $driver === 'mysql' ? 'BIGINT UNSIGNED' : ($driver === 'sqlite' ? 'INTEGER' : 'BIGINT');
            Db::execute("ALTER TABLE detail_page_projects ADD COLUMN sku_id {$type} NULL");
            $changes[] = 'detail_page_projects.sku_id';
        }
        if (!$this->indexExists($driver, 'detail_page_projects', 'idx_detail_projects_sku')) {
            Db::execute('CREATE INDEX idx_detail_projects_sku ON detail_page_projects(sku_id, id)');
            $changes[] = 'idx_detail_projects_sku';
        }
        foreach (['market_research_json', 'market_research_query'] as $column) {
            if (!$this->columnExists($driver, 'detail_page_projects', $column)) {
                Db::execute("ALTER TABLE detail_page_projects ADD COLUMN {$column} TEXT NULL");
                $changes[] = 'detail_page_projects.' . $column;
            }
        }
        if (!$this->columnExists($driver, 'detail_page_projects', 'market_research_updated_at')) {
            $type = $driver === 'sqlite' ? 'TEXT' : ($driver === 'pgsql' ? 'TIMESTAMP' : 'DATETIME');
            Db::execute("ALTER TABLE detail_page_projects ADD COLUMN market_research_updated_at {$type} NULL");
            $changes[] = 'detail_page_projects.market_research_updated_at';
        }
        foreach ($this->marketResearchTableSql($driver) as $sql) {
            Db::execute($sql);
        }
        $changes[] = 'market_research_cache';
        if ($legacyReportBackfillRequired) {
            $this->backfillSkuReports();
            $changes[] = 'skus.report_backfill';
        }
        if (!$this->columnExists($driver, 'assets', 'backup_file_url')) {
            Db::execute('ALTER TABLE assets ADD COLUMN backup_file_url TEXT NULL');
            $changes[] = 'assets.backup_file_url';
        }
        $assetMediaColumns = [
            'media_type' => $driver === 'sqlite' ? "TEXT NOT NULL DEFAULT 'image'" : "VARCHAR(20) NOT NULL DEFAULT 'image'",
            'mime_type' => $driver === 'sqlite' ? 'TEXT NULL' : 'VARCHAR(120) NULL',
            'duration_seconds' => 'INTEGER NULL',
            'poster_url' => 'TEXT NULL',
            'width' => 'INTEGER NULL',
            'height' => 'INTEGER NULL',
        ];
        foreach ($assetMediaColumns as $column => $definition) {
            if (!$this->columnExists($driver, 'assets', $column)) {
                Db::execute("ALTER TABLE assets ADD COLUMN {$column} {$definition}");
                $changes[] = 'assets.' . $column;
            }
        }
        foreach (['deleted_at', 'purged_at'] as $column) {
            if (!$this->columnExists($driver, 'assets', $column)) {
                $type = $driver === 'sqlite' ? 'TEXT' : ($driver === 'pgsql' ? 'TIMESTAMP' : 'DATETIME');
                Db::execute("ALTER TABLE assets ADD COLUMN {$column} {$type} NULL");
                $changes[] = 'assets.' . $column;
            }
        }
        Db::name('assets')->where('status', 'deleted')->whereNull('deleted_at')->update(['deleted_at' => Db::raw('updated_at')]);
        if (!$this->indexExists($driver, 'assets', 'idx_assets_trash_cleanup')) {
            Db::execute('CREATE INDEX idx_assets_trash_cleanup ON assets(status, deleted_at, id)');
            $changes[] = 'idx_assets_trash_cleanup';
        }
        if (!$this->columnExists($driver, 'generation_jobs', 'progress_current')) {
            Db::execute('ALTER TABLE generation_jobs ADD COLUMN progress_current INTEGER NOT NULL DEFAULT 0');
            $changes[] = 'generation_jobs.progress_current';
        }
        if (!$this->columnExists($driver, 'generation_jobs', 'output_kind')) {
            $type = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(20)';
            Db::execute("ALTER TABLE generation_jobs ADD COLUMN output_kind {$type} NOT NULL DEFAULT 'image'");
            $changes[] = 'generation_jobs.output_kind';
        }
        if (!$this->columnExists($driver, 'generation_jobs', 'progress_total')) {
            Db::execute('ALTER TABLE generation_jobs ADD COLUMN progress_total INTEGER NOT NULL DEFAULT 0');
            $changes[] = 'generation_jobs.progress_total';
        }
        if (!$this->columnExists($driver, 'generation_jobs', 'progress_stage')) {
            $stageType = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(40)';
            Db::execute("ALTER TABLE generation_jobs ADD COLUMN progress_stage {$stageType} NOT NULL DEFAULT 'queued'");
            $changes[] = 'generation_jobs.progress_stage';
        }
        if (!$this->columnExists($driver, 'generation_jobs', 'progress_message')) {
            Db::execute('ALTER TABLE generation_jobs ADD COLUMN progress_message TEXT NULL');
            $changes[] = 'generation_jobs.progress_message';
        }
        if (!$this->columnExists($driver, 'generation_jobs', 'detail_page_project_id')) {
            $type = $driver === 'mysql' ? 'BIGINT UNSIGNED' : ($driver === 'sqlite' ? 'INTEGER' : 'BIGINT');
            Db::execute("ALTER TABLE generation_jobs ADD COLUMN detail_page_project_id {$type} NULL");
            $changes[] = 'generation_jobs.detail_page_project_id';
        }
        if (!$this->columnExists($driver, 'generation_jobs', 'language')) {
            $type = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(20)';
            Db::execute("ALTER TABLE generation_jobs ADD COLUMN language {$type} NOT NULL DEFAULT 'zh-CN'");
            $changes[] = 'generation_jobs.language';
        }
        if (!$this->columnExists($driver, 'generation_jobs', 'merchant_hidden_at')) {
            $type = $driver === 'sqlite' ? 'TEXT' : ($driver === 'pgsql' ? 'TIMESTAMP' : 'DATETIME');
            Db::execute("ALTER TABLE generation_jobs ADD COLUMN merchant_hidden_at {$type} NULL");
            $changes[] = 'generation_jobs.merchant_hidden_at';
        }
        foreach (['bundle_no' => 80, 'bundle_part' => 20] as $column => $length) {
            if (!$this->columnExists($driver, 'generation_jobs', $column)) {
                $type = $driver === 'sqlite' ? 'TEXT' : "VARCHAR({$length})";
                Db::execute("ALTER TABLE generation_jobs ADD COLUMN {$column} {$type} NULL");
                $changes[] = 'generation_jobs.' . $column;
            }
        }
        if (!$this->columnExists($driver, 'generation_jobs', 'client_submission_id')) {
            $type = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(64)';
            Db::execute("ALTER TABLE generation_jobs ADD COLUMN client_submission_id {$type} NULL");
            $changes[] = 'generation_jobs.client_submission_id';
        }
        if (!$this->columnExists($driver, 'generation_jobs', 'submission_payload_hash')) {
            $type = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(64)';
            Db::execute("ALTER TABLE generation_jobs ADD COLUMN submission_payload_hash {$type} NULL");
            $changes[] = 'generation_jobs.submission_payload_hash';
        }
        if (!$this->columnExists($driver, 'generation_jobs', 'custom_material_requests')) {
            Db::execute('ALTER TABLE generation_jobs ADD COLUMN custom_material_requests TEXT NULL');
            $changes[] = 'generation_jobs.custom_material_requests';
        }
        foreach (['task_brief', 'task_context_json'] as $column) {
            if (!$this->columnExists($driver, 'generation_jobs', $column)) {
                Db::execute("ALTER TABLE generation_jobs ADD COLUMN {$column} TEXT NULL");
                $changes[] = 'generation_jobs.' . $column;
            }
        }
        $generationAiColumns = [
            'requested_model_id' => $driver === 'mysql' ? 'BIGINT UNSIGNED NULL' : ($driver === 'sqlite' ? 'INTEGER NULL' : 'BIGINT NULL'),
            'resolved_ai_model_id' => $driver === 'mysql' ? 'BIGINT UNSIGNED NULL' : ($driver === 'sqlite' ? 'INTEGER NULL' : 'BIGINT NULL'),
            'route_snapshot_json' => 'TEXT NULL',
            'pricing_snapshot_json' => 'TEXT NULL',
        ];
        foreach ($generationAiColumns as $column => $definition) {
            if (!$this->columnExists($driver, 'generation_jobs', $column)) {
                Db::execute("ALTER TABLE generation_jobs ADD COLUMN {$column} {$definition}");
                $changes[] = 'generation_jobs.' . $column;
            }
        }
        $generationSafetyColumns = [
            'submitted_by_user_id' => $driver === 'mysql' ? 'BIGINT UNSIGNED NULL' : ($driver === 'sqlite' ? 'INTEGER NULL' : 'BIGINT NULL'),
            'submitted_ip' => $driver === 'sqlite' ? 'TEXT NULL' : 'VARCHAR(64) NULL',
            'safety_status' => $driver === 'sqlite' ? "TEXT NOT NULL DEFAULT 'pending'" : "VARCHAR(30) NOT NULL DEFAULT 'pending'",
            'safety_attempts' => 'INTEGER NOT NULL DEFAULT 0',
            'safety_next_retry_at' => $driver === 'sqlite' ? 'TEXT NULL' : ($driver === 'pgsql' ? 'TIMESTAMP NULL' : 'DATETIME NULL'),
            'safety_checked_at' => $driver === 'sqlite' ? 'TEXT NULL' : ($driver === 'pgsql' ? 'TIMESTAMP NULL' : 'DATETIME NULL'),
        ];
        foreach ($generationSafetyColumns as $column => $definition) {
            if (!$this->columnExists($driver, 'generation_jobs', $column)) {
                Db::execute("ALTER TABLE generation_jobs ADD COLUMN {$column} {$definition}");
                $changes[] = 'generation_jobs.' . $column;
            }
        }
        if (!$this->indexExists($driver, 'generation_jobs', 'idx_generation_jobs_detail_project')) {
            Db::execute('CREATE INDEX idx_generation_jobs_detail_project ON generation_jobs(detail_page_project_id)');
            $changes[] = 'idx_generation_jobs_detail_project';
        }
        if (!$this->indexExists($driver, 'generation_jobs', 'idx_generation_jobs_bundle')) {
            Db::execute('CREATE INDEX idx_generation_jobs_bundle ON generation_jobs(bundle_no, bundle_part)');
            $changes[] = 'idx_generation_jobs_bundle';
        }
        if (!$this->indexExists($driver, 'generation_jobs', 'idx_generation_jobs_safety')) {
            Db::execute('CREATE INDEX idx_generation_jobs_safety ON generation_jobs(status, safety_status, safety_next_retry_at, id)');
            $changes[] = 'idx_generation_jobs_safety';
        }
        if (!$this->indexExists($driver, 'generation_jobs', 'uk_generation_jobs_submission')) {
            $duplicates = Db::name('generation_jobs')
                ->field('merchant_id,client_submission_id,COUNT(*) AS duplicate_count')
                ->whereNotNull('client_submission_id')
                ->where('client_submission_id', '<>', '')
                ->group('merchant_id,client_submission_id')
                ->having('COUNT(*) > 1')
                ->limit(1)
                ->select()
                ->toArray();
            if ($duplicates !== []) {
                throw new \RuntimeException('检测到重复的自由创作提交标识，已停止创建唯一索引；请先人工核对历史任务。');
            }
            Db::execute('CREATE UNIQUE INDEX uk_generation_jobs_submission ON generation_jobs(merchant_id, client_submission_id)');
            $changes[] = 'uk_generation_jobs_submission';
        }
        foreach ($this->generationItemTableSql($driver) as $sql) {
            Db::execute($sql);
        }
        $changes[] = 'generation_job_items';
        foreach ([
            'manual_retry_count' => 'INTEGER NOT NULL DEFAULT 0',
            'next_retry_at' => $driver === 'sqlite' ? 'TEXT NULL' : ($driver === 'pgsql' ? 'TIMESTAMP NULL' : 'DATETIME NULL'),
            'last_retry_reason' => 'TEXT NULL',
        ] as $column => $definition) {
            if (!$this->columnExists($driver, 'generation_job_items', $column)) {
                Db::execute("ALTER TABLE generation_job_items ADD COLUMN {$column} {$definition}");
                $changes[] = 'generation_job_items.' . $column;
            }
        }
        if (!$this->indexExists($driver, 'generation_job_items', 'idx_generation_items_retry')) {
            Db::execute('CREATE INDEX idx_generation_items_retry ON generation_job_items(status, next_retry_at, id)');
            $changes[] = 'idx_generation_items_retry';
        }
        if (!$this->columnExists($driver, 'assets', 'generation_item_id')) {
            $type = $driver === 'mysql' ? 'BIGINT UNSIGNED' : 'BIGINT';
            if ($driver === 'sqlite') {
                $type = 'INTEGER';
            }
            Db::execute("ALTER TABLE assets ADD COLUMN generation_item_id {$type} NULL");
            $changes[] = 'assets.generation_item_id';
        }
        if (!$this->indexExists($driver, 'assets', 'idx_assets_generation_item') && !$this->indexExists($driver, 'assets', 'uk_assets_generation_item')) {
            Db::execute('CREATE UNIQUE INDEX idx_assets_generation_item ON assets(generation_item_id)');
            $changes[] = 'idx_assets_generation_item';
        }
        foreach ($this->videoGenerationTableSql($driver) as $sql) {
            Db::execute($sql);
        }
        $changes[] = 'video_generation_requests';
        foreach ($this->migrationTableSql($driver) as $sql) {
            Db::execute($sql);
        }
        $changes[] = 'storage_migrations';
        foreach ($this->contentSafetyTableSql($driver) as $sql) {
            Db::execute($sql);
        }
        if (!$this->columnExists($driver, 'content_moderation_checks', 'cache_hit_count')) {
            Db::execute('ALTER TABLE content_moderation_checks ADD COLUMN cache_hit_count INTEGER NOT NULL DEFAULT 0');
            $changes[] = 'content_moderation_checks.cache_hit_count';
        }
        if (!$this->columnExists($driver, 'content_moderation_checks', 'last_cache_hit_at')) {
            $type = $driver === 'sqlite' ? 'TEXT' : ($driver === 'pgsql' ? 'TIMESTAMP' : 'DATETIME');
            Db::execute("ALTER TABLE content_moderation_checks ADD COLUMN last_cache_hit_at {$type} NULL");
            $changes[] = 'content_moderation_checks.last_cache_hit_at';
        }
        $changes[] = 'content_moderation_checks';
        $changes[] = 'content_violations';
        foreach ($this->loginThrottleTableSql($driver) as $sql) {
            Db::execute($sql);
        }
        $changes[] = 'login_throttle_events';
        foreach ($this->adminNavigationTableSql($driver) as $sql) {
            Db::execute($sql);
        }
        $changes[] = 'admin_navigation_preferences';
        foreach ($this->subscriptionTableSql($driver) as $sql) {
            Db::execute($sql);
        }
        $planTierAdded = false;
        if (!$this->columnExists($driver, 'subscription_plans', 'tier_level')) {
            Db::execute('ALTER TABLE subscription_plans ADD COLUMN tier_level INTEGER NOT NULL DEFAULT 1');
            $changes[] = 'subscription_plans.tier_level';
            $planTierAdded = true;
        }
        $subscriptionTierAdded = false;
        if (!$this->columnExists($driver, 'merchant_subscriptions', 'tier_level')) {
            Db::execute('ALTER TABLE merchant_subscriptions ADD COLUMN tier_level INTEGER NOT NULL DEFAULT 1');
            $changes[] = 'merchant_subscriptions.tier_level';
            $subscriptionTierAdded = true;
        }
        if ($planTierAdded) {
            Db::execute('UPDATE subscription_plans SET tier_level = CASE WHEN sort_order > 0 THEN sort_order ELSE 1 END');
        }
        if ($subscriptionTierAdded) {
            Db::execute('UPDATE merchant_subscriptions SET tier_level = COALESCE((SELECT tier_level FROM subscription_plans WHERE subscription_plans.id = merchant_subscriptions.plan_id), 1)');
        }
        $planColumns = [
            'audience_label' => $driver === 'sqlite' ? 'TEXT NULL' : 'VARCHAR(120) NULL',
            'sku_limit' => 'INTEGER NOT NULL DEFAULT 0',
            'batch_image_limit' => 'INTEGER NOT NULL DEFAULT 0',
            'concurrent_job_limit' => 'INTEGER NOT NULL DEFAULT 0',
        ];
        foreach ($planColumns as $column => $definition) {
            if (!$this->columnExists($driver, 'subscription_plans', $column)) {
                Db::execute("ALTER TABLE subscription_plans ADD COLUMN {$column} {$definition}");
                $changes[] = 'subscription_plans.' . $column;
            }
            if (!$this->columnExists($driver, 'merchant_subscriptions', $column)) {
                Db::execute("ALTER TABLE merchant_subscriptions ADD COLUMN {$column} {$definition}");
                $changes[] = 'merchant_subscriptions.' . $column;
            }
        }
        $changes[] = 'subscription_plans';
        $changes[] = 'merchant_subscriptions';
        foreach ($this->productFeedbackTableSql($driver) as $sql) {
            Db::execute($sql);
        }
        $changes[] = 'product_feedback';
        foreach ($this->promptTemplateTableSql($driver) as $sql) {
            Db::execute($sql);
        }
        if (!$this->columnExists($driver, 'prompt_templates', 'is_customized')) {
            $type = $driver === 'mysql' ? 'TINYINT' : ($driver === 'pgsql' ? 'SMALLINT' : 'INTEGER');
            Db::execute("ALTER TABLE prompt_templates ADD COLUMN is_customized {$type} NOT NULL DEFAULT 0");
            $changes[] = 'prompt_templates.is_customized';
        }
        $changes[] = 'prompt_templates';
        foreach ($this->imageToolResultTableSql($driver) as $sql) {
            Db::execute($sql);
        }
        $changes[] = 'image_tool_results';
        foreach ($this->assetBatchLabelTableSql($driver) as $sql) {
            Db::execute($sql);
        }
        $changes[] = 'asset_batch_labels';
        foreach ($this->canvasEditTableSql($driver) as $sql) {
            Db::execute($sql);
        }
        $changes[] = 'canvas_edits';
        foreach ($this->canvasWorkspaceTableSql($driver) as $sql) {
            Db::execute($sql);
        }
        $changes[] = 'canvas_workspaces';
        foreach (CreativeProjectSchema::statements($driver) as $sql) {
            Db::execute($sql);
        }
        $changes[] = 'creative_projects';
        $changes[] = 'creative_project_revisions';
        $changes[] = 'creative_project_assets';
        $changes[] = 'creative_project_runs';
        foreach ($this->workspaceProjectPreferenceTableSql($driver) as $sql) {
            Db::execute($sql);
        }
        $changes[] = 'workspace_project_preferences';
        foreach ($this->freeGenerationTableSql($driver) as $sql) {
            Db::execute($sql);
        }
        $changes[] = 'free_generation_jobs';
        if (!$this->columnExists($driver, 'free_generation_jobs', 'reference_images_json')) {
            $type = $driver === 'mysql' ? 'LONGTEXT NULL' : "TEXT NOT NULL DEFAULT '[]'";
            Db::execute("ALTER TABLE free_generation_jobs ADD COLUMN reference_images_json {$type}");
            $changes[] = 'free_generation_jobs.reference_images_json';
        }
        $freeGenerationColumns = [
            'prompt_template_id' => $driver === 'mysql' ? 'BIGINT UNSIGNED NULL' : ($driver === 'sqlite' ? 'INTEGER NULL' : 'BIGINT NULL'),
            'prompt_template_code' => $driver === 'sqlite' ? 'TEXT NULL' : 'VARCHAR(100) NULL',
            'prompt_template_name' => $driver === 'sqlite' ? 'TEXT NULL' : 'VARCHAR(160) NULL',
            'prompt_template_content' => $driver === 'mysql' ? 'LONGTEXT NULL' : 'TEXT NULL',
            'subject_text' => $driver === 'mysql' ? 'LONGTEXT NULL' : 'TEXT NULL',
            'user_addition' => $driver === 'mysql' ? 'LONGTEXT NULL' : 'TEXT NULL',
            'reference_count' => 'INTEGER NOT NULL DEFAULT 0',
            'reference_fingerprints_json' => $driver === 'mysql' ? 'LONGTEXT NULL' : "TEXT NOT NULL DEFAULT '[]'",
            'outbound_reference_sha256' => $driver === 'sqlite' ? 'TEXT NULL' : 'VARCHAR(64) NULL',
        ];
        foreach ($freeGenerationColumns as $column => $definition) {
            if (!$this->columnExists($driver, 'free_generation_jobs', $column)) {
                Db::execute("ALTER TABLE free_generation_jobs ADD COLUMN {$column} {$definition}");
                $changes[] = 'free_generation_jobs.' . $column;
            }
        }
        foreach ($this->aiCapabilityTableSql($driver) as $sql) {
            Db::execute($sql);
        }
        foreach ([
            'source_visible' => 'INTEGER NOT NULL DEFAULT 1',
            'source_last_seen_at' => $driver === 'sqlite' ? 'TEXT NULL' : ($driver === 'pgsql' ? 'TIMESTAMP NULL' : 'DATETIME NULL'),
            'lifecycle_status' => $driver === 'sqlite' ? "TEXT NOT NULL DEFAULT 'configured'" : "VARCHAR(30) NOT NULL DEFAULT 'configured'",
            'archived_at' => $driver === 'sqlite' ? 'TEXT NULL' : ($driver === 'pgsql' ? 'TIMESTAMP NULL' : 'DATETIME NULL'),
        ] as $column => $definition) {
            if (!$this->columnExists($driver, 'ai_models', $column)) {
                Db::execute("ALTER TABLE ai_models ADD COLUMN {$column} {$definition}");
                $changes[] = 'ai_models.' . $column;
            }
        }
        foreach (AiCapabilityCenterSchema::statements($driver) as $sql) {
            Db::execute($sql);
        }
        foreach ([
            'workflow_version_id' => $driver === 'sqlite' ? 'INTEGER NULL' : 'BIGINT NULL',
        ] as $column => $definition) {
            if (!$this->columnExists($driver, 'ai_capability_tests', $column)) {
                Db::execute("ALTER TABLE ai_capability_tests ADD COLUMN {$column} {$definition}");
                $changes[] = 'ai_capability_tests.' . $column;
            }
        }
        foreach ([
            'workflow_version_id' => $driver === 'sqlite' ? 'INTEGER NULL' : 'BIGINT NULL',
            'restriction_json' => $driver === 'mysql'
                ? 'LONGTEXT NULL'
                : "TEXT NOT NULL DEFAULT '{}'",
        ] as $column => $definition) {
            if (!$this->columnExists($driver, 'ai_business_compatibilities', $column)) {
                Db::execute("ALTER TABLE ai_business_compatibilities ADD COLUMN {$column} {$definition}");
                if ($column === 'restriction_json' && $driver === 'mysql') {
                    Db::name('ai_business_compatibilities')->whereNull('restriction_json')->update([
                        'restriction_json' => '{}',
                    ]);
                    Db::execute('ALTER TABLE ai_business_compatibilities MODIFY COLUMN restriction_json LONGTEXT NOT NULL');
                }
                $changes[] = 'ai_business_compatibilities.' . $column;
            }
        }
        array_push($changes, ...$this->backfillAiCapabilityCenter());
        $changes[] = 'ai_models';
        if ($this->columnExists($driver, 'ai_models', 'catalog_active')) {
            if ($this->indexExists($driver, 'ai_models', 'idx_ai_models_enabled')) {
                Db::execute($driver === 'mysql'
                    ? 'DROP INDEX idx_ai_models_enabled ON ai_models'
                    : 'DROP INDEX idx_ai_models_enabled');
            }
            Db::execute('ALTER TABLE ai_models DROP COLUMN catalog_active');
            Db::execute($driver === 'mysql'
                ? 'CREATE INDEX idx_ai_models_enabled ON ai_models(enabled, sort_order, id)'
                : 'CREATE INDEX IF NOT EXISTS idx_ai_models_enabled ON ai_models(enabled, sort_order, id)');
            $changes[] = 'ai_models.catalog_active_removed';
        }
        $changes[] = 'ai_capability_routes';
        $changes[] = 'ai_model_prices';
        $changes[] = 'ai_model_calls';
        $changes[] = 'ai_model_sync_batches';
        $changes[] = 'ai_model_capabilities';
        $changes[] = 'ai_capability_tests';
        $changes[] = 'ai_business_compatibilities';
        $aiModelCallColumns = [
            'attempt_no' => 'INTEGER NULL',
            'route_role' => $driver === 'sqlite' ? 'TEXT NULL' : 'VARCHAR(30) NULL',
            'protocol' => $driver === 'sqlite' ? 'TEXT NULL' : 'VARCHAR(40) NULL',
            'http_status' => 'INTEGER NULL',
            'actual_cost' => $driver === 'mysql' ? 'DECIMAL(16,6) NULL' : ($driver === 'pgsql' ? 'NUMERIC(16,6) NULL' : 'NUMERIC NULL'),
            'started_at' => $driver === 'sqlite' ? 'TEXT NULL' : ($driver === 'pgsql' ? 'TIMESTAMP NULL' : 'DATETIME NULL'),
            'finished_at' => $driver === 'sqlite' ? 'TEXT NULL' : ($driver === 'pgsql' ? 'TIMESTAMP NULL' : 'DATETIME NULL'),
        ];
        foreach ($aiModelCallColumns as $column => $definition) {
            if (!$this->columnExists($driver, 'ai_model_calls', $column)) {
                Db::execute("ALTER TABLE ai_model_calls ADD COLUMN {$column} {$definition}");
                $changes[] = 'ai_model_calls.' . $column;
            }
        }
        array_push($changes, ...$this->migrateModelCatalogToSub2Api($driver));
        array_push($changes, ...$this->dropLegacyProviderCallColumns($driver));
        array_push($changes, ...$this->dropLegacyProviderPoolTables($driver));
        foreach ($this->mediaWorkflowTemplateTableSql($driver) as $sql) {
            Db::execute($sql);
        }
        $changes[] = 'media_workflow_templates';
        foreach (ProductQualitySchema::statements($driver) as $sql) {
            Db::execute($sql);
        }
        foreach ([
            'charged_credits' => 'INTEGER NULL',
            'consume_ledger_id' => $driver === 'mysql' ? 'BIGINT UNSIGNED NULL' : ($driver === 'sqlite' ? 'INTEGER NULL' : 'BIGINT NULL'),
            'technical_refund_ledger_id' => $driver === 'mysql' ? 'BIGINT UNSIGNED NULL' : ($driver === 'sqlite' ? 'INTEGER NULL' : 'BIGINT NULL'),
            'quality_refund_ledger_id' => $driver === 'mysql' ? 'BIGINT UNSIGNED NULL' : ($driver === 'sqlite' ? 'INTEGER NULL' : 'BIGINT NULL'),
            'stage_timestamps_json' => $driver === 'mysql' ? 'LONGTEXT NULL' : "TEXT NOT NULL DEFAULT '{}'",
        ] as $column => $definition) {
            if (!$this->columnExists($driver, 'generation_job_items', $column)) {
                Db::execute("ALTER TABLE generation_job_items ADD COLUMN {$column} {$definition}");
                $changes[] = 'generation_job_items.' . $column;
            }
        }
        if (!$this->columnExists($driver, 'credit_ledger', 'idempotency_key')) {
            $definition = $driver === 'sqlite' ? 'TEXT NULL' : 'VARCHAR(190) NULL';
            Db::execute("ALTER TABLE credit_ledger ADD COLUMN idempotency_key {$definition}");
            $changes[] = 'credit_ledger.idempotency_key';
        }
        if (!$this->indexExists($driver, 'credit_ledger', 'uk_credit_ledger_idempotency')) {
            Db::execute($driver === 'mysql'
                ? 'CREATE UNIQUE INDEX uk_credit_ledger_idempotency ON credit_ledger(idempotency_key)'
                : 'CREATE UNIQUE INDEX IF NOT EXISTS uk_credit_ledger_idempotency ON credit_ledger(idempotency_key)');
            $changes[] = 'uk_credit_ledger_idempotency';
        }
        $changes[] = 'creative_products';
        $changes[] = 'workflow_template_versions';
        $changes[] = 'generation_execution_attempts';
        $changes[] = 'generation_quality_appeals';
        foreach ($this->visionTaskTableSql($driver) as $sql) {
            Db::execute($sql);
        }
        foreach ([
            'progress_percent' => 'INTEGER NOT NULL DEFAULT 0',
            'progress_stage' => $driver === 'sqlite' ? "TEXT NOT NULL DEFAULT 'queued'" : "VARCHAR(40) NOT NULL DEFAULT 'queued'",
            'progress_message' => $driver === 'sqlite' ? "TEXT NULL" : "VARCHAR(255) NULL",
            'diagnostic_json' => $driver === 'mysql' ? 'LONGTEXT NULL' : "TEXT NOT NULL DEFAULT '{}'",
            'input_image_count' => 'INTEGER NOT NULL DEFAULT 1',
            'original_input_bytes' => $driver === 'sqlite' ? 'INTEGER NOT NULL DEFAULT 0' : 'BIGINT NOT NULL DEFAULT 0',
            'normalized_input_bytes' => $driver === 'sqlite' ? 'INTEGER NOT NULL DEFAULT 0' : 'BIGINT NOT NULL DEFAULT 0',
            'client_submission_id' => $driver === 'sqlite' ? 'TEXT NULL' : 'VARCHAR(96) NULL',
        ] as $column => $definition) {
            if (!$this->columnExists($driver, 'vision_tasks', $column)) {
                Db::execute("ALTER TABLE vision_tasks ADD COLUMN {$column} {$definition}");
                $changes[] = 'vision_tasks.' . $column;
            }
        }
        if (!$this->indexExists($driver, 'vision_tasks', 'uk_vision_tasks_submission')) {
            Db::execute('CREATE UNIQUE INDEX uk_vision_tasks_submission ON vision_tasks(merchant_id, client_submission_id)');
            $changes[] = 'uk_vision_tasks_submission';
        }
        $changes[] = 'vision_tasks';
        $changes[] = 'vision_result_cache';
        foreach ([
            'output_preset' => $driver === 'sqlite' ? "TEXT NOT NULL DEFAULT 'square_1_1'" : "VARCHAR(40) NOT NULL DEFAULT 'square_1_1'",
            'output_width' => 'INTEGER NOT NULL DEFAULT 1024',
            'output_height' => 'INTEGER NOT NULL DEFAULT 1024',
            'resize_mode' => $driver === 'sqlite' ? "TEXT NOT NULL DEFAULT 'extend'" : "VARCHAR(20) NOT NULL DEFAULT 'extend'",
            'operation' => $driver === 'sqlite' ? "TEXT NOT NULL DEFAULT 'ai_edit'" : "VARCHAR(20) NOT NULL DEFAULT 'ai_edit'",
        ] as $column => $definition) {
            if (!$this->columnExists($driver, 'canvas_edits', $column)) {
                Db::execute("ALTER TABLE canvas_edits ADD COLUMN {$column} {$definition}");
                $changes[] = 'canvas_edits.' . $column;
            }
        }

        foreach (AdminOnboardingSchema::statements($driver) as $sql) {
            Db::execute($sql);
        }
        $changes[] = 'admin_onboarding_progress';
        if (!$onboardingTableExisted) {
            $onboarding = new AdminOnboardingService();
            foreach (Db::name('admin_users')->column('id') as $adminId) {
                $onboarding->createCompleted((int) $adminId);
            }
            $changes[] = 'admin_onboarding_progress.existing_install_completed';
        }
        $legacyNavigationIds = [];
        foreach (Db::name('admin_navigation_preferences')->field('id,path')->select()->toArray() as $navigationRow) {
            $path = (string) ($navigationRow['path'] ?? '');
            if (str_starts_with($path, '/admin/ai-capabilities')
                || str_starts_with($path, '/admin/ai-models')
                || str_starts_with($path, '/admin/ai-assignments')
                || str_starts_with($path, '/admin/ai-compatibility')
                || str_starts_with($path, '/admin/prompts/products/')) {
                $legacyNavigationIds[] = (int) $navigationRow['id'];
            }
        }
        $legacyAdminNavigation = $legacyNavigationIds === []
            ? 0
            : Db::name('admin_navigation_preferences')->whereIn('id', $legacyNavigationIds)->delete();
        if ($legacyAdminNavigation > 0) {
            $changes[] = 'admin_navigation_preferences.legacy_ai_entries_removed';
        }

        array_push($changes, ...$this->retirePodCarouselFeature($driver));
        array_push($changes, ...$this->retireLegacyImageModels());
        $promptTemplates = new PromptTemplateService();
        $promptTemplates->ensureBuiltIns();
        $changes[] = 'built_in_prompt_templates';
        if ($promptTemplates->flattenStructuredPromptTemplates() > 0) {
            $changes[] = 'prompt_templates.structured_templates_flattened';
        }
        if ($promptTemplates->restoreLegacyImageSafeAreaBoundary() > 0) {
            $changes[] = 'prompt_templates.image_safe_area_restored';
        }
        if ($promptTemplates->retireFreeRuntimeProductRestriction() > 0) {
            $changes[] = 'prompt_templates.free_runtime_product_restriction_removed';
        }

        return array_values(array_unique($changes));
    }

    private function retirePodCarouselFeature(string $driver): array
    {
        $changes = [];
        $oldProductCode = 'listing_bundle_pod';
        $productCode = 'listing_bundle';
        $retiredTemplatePrefix = 'builtin.system.pod_';
        $now = date('Y-m-d H:i:s');

        $oldProduct = Db::name('creative_products')->where('code', $oldProductCode)->find();
        $currentProduct = Db::name('creative_products')->where('code', $productCode)->find();
        if ($oldProduct && !$currentProduct) {
            Db::name('creative_products')->where('id', (int) $oldProduct['id'])->update([
                'code' => $productCode,
                'name' => '商品套图',
                'description' => '商品套图、批量规格和多输出编排。',
                'updated_at' => $now,
            ]);
            $changes[] = 'creative_products.listing_bundle';
        } elseif ($currentProduct) {
            Db::name('creative_products')->where('id', (int) $currentProduct['id'])->update([
                'name' => '商品套图',
                'description' => '商品套图、批量规格和多输出编排。',
                'updated_at' => $now,
            ]);
        }

        foreach ([
            'generation_quality_appeals',
            'quality_improvement_items',
        ] as $table) {
            if ($this->tableExists($driver, $table)) {
                Db::name($table)->where('product_code', $oldProductCode)->update(['product_code' => $productCode]);
            }
        }

        if ($this->tableExists($driver, 'prompt_templates')) {
            Db::name('prompt_templates')
                ->whereLike('code', $retiredTemplatePrefix . '%')
                ->whereOr(function ($query): void {
                    $query->whereLike('asset_type', 'pod_%');
                })
                ->delete();
        }
        $changes[] = 'prompt_templates.apparel_carousel_removed';

        return array_values(array_unique($changes));
    }

    private function retireLegacyImageModels(): array
    {
        $changes = [];
        $now = date('Y-m-d H:i:s');
        // These are historical direct-provider aliases that were retired when
        // ThinkPHP stopped owning upstream channels. The current public
        // Sub2API alias gpt-image-seedream-5 is intentionally not in this list:
        // it may be enabled again after fresh-install evidence is verified.
        $retiredAliases = [
            'doubao-seedream-5-0-260128',
            'seedream-5.0',
            'glm-image',
            'gpt-image-glm',
        ];
        $retiredRows = Db::name('ai_models')->whereIn('model_id', $retiredAliases)->select()->toArray();
        $retiredIds = array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $retiredRows
        )));
        $retiredChanged = false;
        foreach ($retiredRows as $retiredRow) {
            $modelUpdate = [];
            if ((int) ($retiredRow['enabled'] ?? 0) !== 0) {
                $modelUpdate['enabled'] = 0;
            }
            if ((int) ($retiredRow['user_selectable'] ?? 0) !== 0) {
                $modelUpdate['user_selectable'] = 0;
            }
            if ((string) ($retiredRow['health_status'] ?? '') !== 'maintenance') {
                $modelUpdate['health_status'] = 'maintenance';
            }
            if ((string) ($retiredRow['last_error'] ?? '') !== '模型已在 Sub2API 停用，仅保留历史记录。') {
                $modelUpdate['last_error'] = '模型已在 Sub2API 停用，仅保留历史记录。';
            }
            $limits = json_decode((string) ($retiredRow['limits_json'] ?? '{}'), true);
            $limits = is_array($limits) ? $limits : [];
            $updatedLimits = $limits;
            $updatedLimits['release_stage'] = 'candidate';
            unset($updatedLimits['gray_merchant_ids']);
            $updatedLimits['retired_at'] = (string) ($limits['retired_at'] ?? '') !== ''
                ? (string) $limits['retired_at']
                : $now;
            $updatedLimits['retired_reason'] = 'disabled_in_sub2api';
            if ($updatedLimits !== $limits) {
                $modelUpdate['limits_json'] = json_encode($updatedLimits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($modelUpdate !== []) {
                $modelUpdate['updated_at'] = $now;
                Db::name('ai_models')->where('id', (int) $retiredRow['id'])->update([
                    ...$modelUpdate,
                ]);
                $retiredChanged = true;
            }
        }
        if ($retiredChanged) {
            $changes[] = 'ai_models.seedream_glm_image_retired';
        }

        $gptImage = Db::name('ai_models')->where('model_id', 'gpt-image-2')->order('id', 'asc')->find();
        $gptImageId = (int) ($gptImage['id'] ?? 0);
        $routesChanged = false;
        foreach ([AiCapability::IMAGE_GENERATION, AiCapability::IMAGE_EDIT] as $capability) {
            $route = Db::name('ai_capability_routes')->where('capability_code', $capability)->find();
            if (!$route) {
                continue;
            }
            $config = json_decode((string) ($route['route_config_json'] ?? '{}'), true);
            $config = is_array($config) ? $config : [];
            $poolIds = array_values(array_unique(array_filter(
                array_map('intval', (array) ($config['pool_model_ids'] ?? [])),
                static fn (int $id): bool => $id > 0 && !in_array($id, $retiredIds, true)
            )));
            if ($poolIds === [] && $gptImageId > 0) {
                $poolIds = [$gptImageId];
            }
            $primaryId = (int) ($route['primary_model_id'] ?? 0);
            if ($primaryId <= 0 || in_array($primaryId, $retiredIds, true) || !in_array($primaryId, $poolIds, true)) {
                $primaryId = $gptImageId > 0 && in_array($gptImageId, $poolIds, true)
                    ? $gptImageId
                    : (int) ($poolIds[0] ?? 0);
            }
            $defaultId = (int) ($config['default_model_id'] ?? 0);
            if ($defaultId <= 0 || in_array($defaultId, $retiredIds, true) || !in_array($defaultId, $poolIds, true)) {
                $defaultId = $primaryId;
            }
            $updatedConfig = $config;
            $updatedConfig['pool_model_ids'] = $poolIds;
            $updatedConfig['default_model_id'] = $defaultId > 0 ? $defaultId : null;
            $updatedConfig['strategy'] = 'selectable_pool';
            $updatedConfig['failure_policy'] = 'manual_retry';
            $updatedConfig['failure_mode'] = 'manual_retry';
            $updatedConfig['fallback'] = 'none';
            unset($updatedConfig['fallback_model_ids']);
            $needsRouteUpdate = $primaryId !== (int) ($route['primary_model_id'] ?? 0)
                || !empty($route['fallback_model_id'])
                || (string) ($route['routing_strategy'] ?? '') !== 'selectable_pool'
                || $updatedConfig !== $config;
            if ($needsRouteUpdate) {
                Db::name('ai_capability_routes')->where('id', (int) $route['id'])->update([
                    'primary_model_id' => $primaryId > 0 ? $primaryId : null,
                    'fallback_model_id' => null,
                    'routing_strategy' => 'selectable_pool',
                    'route_config_json' => json_encode($updatedConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => $now,
                ]);
                $routesChanged = true;
            }
        }
        if ($routesChanged) {
            $changes[] = 'ai_routes.retired_image_models_removed';
        }

        if ($gptImageId > 0) {
            $defaultSetting = Db::name('system_settings')->where('setting_key', 'default_image_model')->find();
            if ($defaultSetting && in_array((string) ($defaultSetting['setting_value'] ?? ''), $retiredAliases, true)) {
                Db::name('system_settings')->where('id', (int) $defaultSetting['id'])->update([
                    'setting_value' => 'gpt-image-2',
                    'updated_at' => $now,
                ]);
                $changes[] = 'system_settings.default_image_model_retired_alias_removed';
            }
            if ($this->columnExists((string) env('DB_DRIVER', 'sqlite'), 'skus', 'preferred_image_model')) {
                $updatedSkus = Db::name('skus')->whereIn('preferred_image_model', $retiredAliases)->update([
                    'preferred_image_model' => 'gpt-image-2',
                    'updated_at' => $now,
                ]);
                if ($updatedSkus > 0) {
                    $changes[] = 'skus.retired_image_model_preferences_removed';
                }
            }
        }

        return array_values(array_unique($changes));
    }

    private function retireRemovedFeatures(string $driver): array
    {
        $changes = [];
        $hadLegacyProductLaunchStages = $this->tableExists($driver, 'product_launch_stages');
        $settingsDeleted = Db::name('system_settings')
            ->where(function ($query): void {
                $query->whereLike('setting_key', 'creativity_%')
                    ->whereOr('setting_key', 'douyin_selection_enabled')
                    ->whereOr('setting_key', 'douyin_default_store_limit');
            })
            ->delete();
        $settingsDeleted += Db::name('system_settings')->whereIn('setting_key', [
            'media_workflow_engine',
            'comfyui_image_gray_merchant_ids',
            'comfyui_v2_image_gray_merchant_ids',
            'generation_quality_gate_enabled',
            'generation_quality_visual_review_enabled',
            'generation_quality_retry_limit',
        ])->delete();
        if ($settingsDeleted > 0) {
            $changes[] = 'system_settings.removed_features_deleted';
        }

        if ($hadLegacyProductLaunchStages && $this->tableExists($driver, 'vision_tasks')) {
            $now = date('Y-m-d H:i:s');
            $reviewTasks = Db::name('vision_tasks')
                ->whereIn('status', ['needs_review', 'manual_review'])->select()->toArray();
            foreach ($reviewTasks as $task) {
                $skuId = max(0, (int) ($task['sku_id'] ?? 0));
                if ($skuId > 0) {
                    $result = json_decode((string) ($task['result_json'] ?? ''), true);
                    $result = is_array($result) ? $result : [];
                    $result = array_merge($result, [
                        'ok' => true,
                        'status' => 'completed',
                        'queued' => false,
                        'progressStage' => 'completed',
                        'progressPercent' => 100,
                        'message' => '历史商品资料已保留，可直接继续创作。',
                        'skuId' => $skuId,
                        'redirectUrl' => '/workspace/skus/' . $skuId . '?section=overview',
                    ]);
                    Db::name('vision_tasks')->where('id', (int) $task['id'])->update([
                        'status' => 'completed',
                        'progress_percent' => 100,
                        'progress_stage' => 'completed',
                        'progress_message' => '历史商品资料已保留，可直接继续创作。',
                        'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'worker_slot' => null,
                        'next_retry_at' => null,
                        'error_message' => null,
                        'completed_at' => $task['completed_at'] ?: $now,
                        'updated_at' => $now,
                    ]);
                    $changes[] = 'vision_tasks.legacy_review_completed';
                    continue;
                }
                $this->failRetiredVisionTask($task, $now);
                $changes[] = 'vision_tasks.legacy_review_failed';
            }

            foreach (Db::name('vision_tasks')->whereIn('status', ['processing', 'running', 'retrying'])
                ->select()->toArray() as $task) {
                $this->failRetiredVisionTask($task, $now);
                $changes[] = 'vision_tasks.legacy_active_failed';
            }
        }

        $tables = [
            'product_launch_stages',
            'prompt_quality_runs',
            'prompt_quality_cases',
            'prompt_release_sets',
            'prompt_template_revisions',
            'prompt_assembly_bindings',
            'detail_design_system_versions',
            'creativity_topic_posts',
            'creativity_collection_items',
            'creativity_interaction_events',
            'creativity_post_views',
            'creativity_reports',
            'creativity_comments',
            'creativity_reuses',
            'creativity_likes',
            'creativity_favorites',
            'creativity_post_assets',
            'creativity_follows',
            'creativity_collections',
            'creativity_taxonomies',
            'creativity_topics',
            'creativity_posts',
            'creativity_creator_profiles',
            'douyin_listing_drafts',
            'douyin_selection_candidates',
            'douyin_data_imports',
            'douyin_stores',
        ];
        foreach ($tables as $table) {
            if ($this->tableExists($driver, $table)) {
                Db::execute("DROP TABLE {$table}");
                $changes[] = $table . '.removed';
            }
        }

        if ($this->tableExists($driver, 'prompt_templates')) {
            $promptService = new PromptTemplateService();
            $activeSystemTypes = array_keys($promptService->systemTypes());
            $activeCodes = array_column($promptService->builtInDefinitions(), 'code');
            foreach (Db::name('prompt_templates')
                ->select()
                ->toArray() as $template) {
                $scene = (string) ($template['scene'] ?? '');
                $isSystem = (int) ($template['is_system'] ?? 0) === 1;
                $retiredSystemPrompt = $scene === PromptTemplateService::SCENE_SYSTEM
                    && !in_array((string) ($template['asset_type'] ?? ''), $activeSystemTypes, true);
                $retiredBuiltIn = $isSystem
                    && !in_array((string) ($template['code'] ?? ''), $activeCodes, true);
                $retiredCustomCore = !$isSystem && $scene !== PromptTemplateService::SCENE_FREE;
                if ($retiredSystemPrompt || $retiredBuiltIn || $retiredCustomCore) {
                    Db::name('prompt_templates')->where('id', (int) $template['id'])->delete();
                    $changes[] = 'prompt_templates.retired_template_removed';
                }
            }
        }

        if ($this->tableExists($driver, 'creative_products')) {
            Db::name('creative_products')->where('code', '<>', 'canvas_edit')->update([
                'current_workflow_version_id' => null,
                'previous_workflow_version_id' => null,
            ]);
        }
        if ($this->tableExists($driver, 'workflow_release_events')) {
            $deleted = Db::name('workflow_release_events')->where('product_code', '<>', 'canvas_edit')->delete();
            if ($deleted > 0) {
                $changes[] = 'workflow_release_events.non_canvas_removed';
            }
        }
        if ($this->tableExists($driver, 'workflow_template_versions')) {
            $deleted = Db::name('workflow_template_versions')->where('template_code', 'not like', 'canvas_edit_%')->delete();
            if ($deleted > 0) {
                $changes[] = 'workflow_template_versions.non_canvas_removed';
            }
        }
        if ($this->tableExists($driver, 'media_workflow_templates')) {
            $deleted = Db::name('media_workflow_templates')->where('code', 'not like', 'canvas_edit_%')->delete();
            if ($deleted > 0) {
                $changes[] = 'media_workflow_templates.non_canvas_removed';
            }
        }
        if ($this->tableExists($driver, 'ai_business_compatibilities')) {
            Db::name('ai_business_compatibilities')
                ->where('execution_channel', 'comfyui')
                ->where('product_code', '<>', 'canvas_edit')
                ->delete();
        }
        if ($this->tableExists($driver, 'ai_capability_tests')) {
            Db::name('ai_capability_tests')
                ->where('execution_channel', 'comfyui')
                ->where('product_code', '<>', 'canvas_edit')
                ->delete();
        }

        $columns = [
            'generation_jobs' => [
                'foundation_snapshot_json', 'creative_brief_json', 'generation_plan_json',
                'creative_output_mode', 'visual_profile_ready', 'visual_profile_readiness_json',
            ],
            'generation_job_items' => [
                'auto_retry_count', 'prompt_snapshot_json', 'workflow_snapshot_json', 'product_code',
                'routing_policy_json', 'prompt_snapshot_version', 'compiled_prompt_hash',
                'text_render_mode', 'quality_status', 'quality_reason_codes_json', 'quality_evidence_json',
            ],
            'detail_page_projects' => ['design_system_json', 'design_system_version_id'],
            'creative_products' => ['fidelity_policy', 'current_prompt_release_id', 'previous_prompt_release_id'],
            'workflow_template_versions' => ['prompt_wrapper_json', 'validation_result_json'],
            'ai_model_calls' => ['compiled_prompt_hash'],
            'merchants' => ['douyin_enabled', 'douyin_store_limit'],
            'subscription_plans' => ['feature_douyin_selection', 'douyin_store_limit'],
            'merchant_subscriptions' => ['feature_douyin_selection', 'douyin_store_limit'],
            'skus' => [
                'fact_response_state_json', 'foundation_constraints',
                'product_profile_json', 'product_profile_revision', 'product_profile_confirmed_at',
            ],
            'vision_tasks' => [
                'vision_route_snapshot_json', 'planner_route_snapshot_json', 'stage_metrics_json',
                'planner_model', 'planner_request_id', 'planner_attempts', 'profile_ready_at',
                'creative_state', 'facts_snapshot_json', 'intent_snapshot_json', 'creative_snapshot_json',
                'readiness_missing_json', 'readiness_blockers_json', 'creative_ready_at',
            ],
        ];
        foreach ($columns as $table => $tableColumns) {
            foreach ($tableColumns as $column) {
                if ($this->columnExists($driver, $table, $column)) {
                    Db::execute("ALTER TABLE {$table} DROP COLUMN {$column}");
                    $changes[] = $table . '.' . $column . '_removed';
                }
            }
        }
        return array_values(array_unique($changes));
    }

    private function failRetiredVisionTask(array $task, string $now): void
    {
        $credits = max(0, (int) ($task['credits'] ?? 0));
        $reference = trim((string) ($task['billing_reference'] ?? ''));
        if ($credits > 0 && $reference !== '') {
            (new RecognitionBillingService())->refund(
                (int) $task['merchant_id'],
                $credits,
                $reference,
                '旧商品建档流程退役，未完成任务退款'
            );
        }
        $message = '系统已升级为精简商品建档流程。旧任务已停止且不会自动重发，请手动重新处理。';
        Db::name('vision_tasks')->where('id', (int) $task['id'])->update([
            'status' => 'failed',
            'progress_percent' => 100,
            'progress_stage' => 'failed',
            'progress_message' => $message,
            'result_json' => json_encode([
                'ok' => false,
                'status' => 'failed',
                'queued' => false,
                'errorCode' => 'legacy_vision_pipeline_retired',
                'message' => $message,
                'refunded' => $credits > 0 && $reference !== '',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'worker_slot' => null,
            'next_retry_at' => null,
            'error_message' => $message,
            'completed_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function backfillAiCapabilityCenter(): array
    {
        $changes = [];
        $now = date('Y-m-d H:i:s');
        $compatibilitySeeds = [
            AiCapability::IMAGE_GENERATION => [
                ['product_image', 'direct'],
                ['free_create', 'direct'],
                ['listing_bundle', 'direct'],
            ],
            AiCapability::IMAGE_EDIT => [
                ['product_image', 'direct'],
                ['free_create', 'direct'],
                ['listing_bundle', 'direct'],
                ['detail_page', 'direct'],
                ['canvas_edit', 'comfyui'],
            ],
            AiCapability::VIDEO_IMAGE_TO_VIDEO => [
                ['product_video', 'direct'],
            ],
            AiCapability::VIDEO_TEXT_TO_VIDEO => [
                ['product_video', 'direct'],
            ],
        ];

        foreach (Db::name('ai_models')->order('id', 'asc')->select()->toArray() as $model) {
            $modelId = (int) $model['id'];
            $capabilities = json_decode((string) ($model['capabilities_json'] ?? '[]'), true);
            $capabilities = is_array($capabilities)
                ? array_values(array_filter(array_map('strval', $capabilities), [AiCapability::class, 'exists']))
                : [];
            $adapter = json_decode((string) ($model['adapter_config_json'] ?? '{}'), true);
            $tests = is_array($adapter) && is_array($adapter['capability_tests'] ?? null)
                ? $adapter['capability_tests']
                : [];
            foreach ($capabilities as $capability) {
                $legacyStatus = (string) ($tests[$capability]['status'] ?? 'unknown');
                $testStatus = match ($legacyStatus) {
                    'ready' => 'passed',
                    'unavailable' => 'failed',
                    default => 'pending',
                };
                $existing = Db::name('ai_model_capabilities')
                    ->where('ai_model_id', $modelId)
                    ->where('capability_code', $capability)
                    ->find();
                if (!$existing) {
                    Db::name('ai_model_capabilities')->insert([
                        'ai_model_id' => $modelId,
                        'capability_code' => $capability,
                        'confirmation_status' => 'confirmed',
                        'test_status' => $testStatus,
                        'suggested_by' => 'legacy_migration',
                        'confirmed_by_admin_id' => null,
                        'last_test_id' => null,
                        'notes' => '由 0.40.0 已有能力配置迁移，未改写历史测试结果。',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $changes[] = 'ai_model_capabilities.backfilled';
                }

                if ($testStatus !== 'passed'
                    || !isset($compatibilitySeeds[$capability])) {
                    continue;
                }
                foreach ($compatibilitySeeds[$capability] as [$productCode, $channel]) {
                    $compatibility = Db::name('ai_business_compatibilities')
                        ->where('ai_model_id', $modelId)
                        ->where('capability_code', $capability)
                        ->where('product_code', $productCode)
                        ->where('execution_channel', $channel)
                        ->find();
                    if ($compatibility) {
                        $updates = [];
                        if ($channel === 'comfyui' && (int) ($compatibility['workflow_version_id'] ?? 0) <= 0) {
                            $versionId = (int) Db::name('creative_products')
                                ->where('code', $productCode)
                                ->value('current_workflow_version_id');
                            if ($versionId > 0) {
                                $updates['workflow_version_id'] = $versionId;
                            }
                        }
                        if (trim((string) ($compatibility['restriction_json'] ?? '')) === '') {
                            $updates['restriction_json'] = '{}';
                        }
                        if ($updates !== []) {
                            $updates['updated_at'] = (string) ($compatibility['updated_at'] ?? $now);
                            Db::name('ai_business_compatibilities')
                                ->where('id', (int) $compatibility['id'])
                                ->update($updates);
                            $changes[] = 'ai_business_compatibilities.workflow_release_backfilled';
                        }
                        continue;
                    }
                    Db::name('ai_business_compatibilities')->insert([
                        'ai_model_id' => $modelId,
                        'capability_code' => $capability,
                        'product_code' => $productCode,
                        'execution_channel' => $channel,
                        'workflow_version_id' => $channel === 'comfyui'
                            ? ((int) Db::name('creative_products')->where('code', $productCode)->value('current_workflow_version_id') ?: null)
                            : null,
                        'status' => 'passed',
                        'last_test_id' => null,
                        'restriction_json' => '{}',
                        'notes' => '继承 0.39.0 已完成的 Direct 与 ComfyUI 真实生产验收。',
                        'updated_by_admin_id' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $changes[] = 'ai_business_compatibilities.production_evidence_backfilled';
                }
            }

            $lifecycle = (int) ($model['enabled'] ?? 0) === 1 ? 'available' : 'paused';
            if ((string) ($model['lifecycle_status'] ?? '') === ''
                || (string) ($model['lifecycle_status'] ?? '') === 'configured') {
                Db::name('ai_models')->where('id', $modelId)->update([
                    'lifecycle_status' => $lifecycle,
                    'updated_at' => (string) ($model['updated_at'] ?? $now),
                ]);
            }
        }

        return array_values(array_unique($changes));
    }

    private function columnExists(string $driver, string $table, string $column): bool
    {
        if ($driver === 'sqlite') {
            foreach (Db::query('PRAGMA table_info(' . $table . ')') as $row) {
                if ((string) ($row['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }
        if ($driver === 'pgsql') {
            return Db::query('SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ? LIMIT 1', [$table, $column]) !== [];
        }

        return Db::query('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1', [$table, $column]) !== [];
    }

    private function indexExists(string $driver, string $table, string $index): bool
    {
        if ($driver === 'sqlite') {
            foreach (Db::query('PRAGMA index_list(' . $table . ')') as $row) {
                if ((string) ($row['name'] ?? '') === $index) {
                    return true;
                }
            }
            return false;
        }
        if ($driver === 'pgsql') {
            return Db::query('SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ? LIMIT 1', [$table, $index]) !== [];
        }

        return Db::query('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1', [$table, $index]) !== [];
    }

    private function tableExists(string $driver, string $table): bool
    {
        if ($driver === 'sqlite') {
            return Db::query(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
                [$table]
            ) !== [];
        }
        if ($driver === 'pgsql') {
            return Db::query(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ? LIMIT 1',
                [$table]
            ) !== [];
        }

        return Db::query(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1',
            [$table]
        ) !== [];
    }

    private function dropLegacyProviderPoolTables(string $driver): array
    {
        $changes = [];
        foreach ([
            'ai_provider_leases',
            'ai_provider_channel_models',
            'ai_provider_model_bindings',
            'ai_provider_accounts',
            'ai_provider_channels',
        ] as $table) {
            if (!$this->tableExists($driver, $table)) {
                continue;
            }
            Db::execute('DROP TABLE ' . $table);
            $changes[] = $table . '.removed';
        }
        return $changes;
    }

    private function dropLegacyProviderCallColumns(string $driver): array
    {
        $changes = [];
        foreach (['idx_ai_model_calls_provider', 'idx_ai_model_calls_channel'] as $index) {
            if (!$this->indexExists($driver, 'ai_model_calls', $index)) {
                continue;
            }
            Db::execute($driver === 'mysql'
                ? 'DROP INDEX ' . $index . ' ON ai_model_calls'
                : 'DROP INDEX ' . $index);
            $changes[] = 'ai_model_calls.' . $index . '_removed';
        }
        foreach ([
            'provider_account_id',
            'provider_binding_id',
            'provider_channel_id',
            'provider_channel_model_id',
        ] as $column) {
            if (!$this->columnExists($driver, 'ai_model_calls', $column)) {
                continue;
            }
            Db::execute('ALTER TABLE ai_model_calls DROP COLUMN ' . $column);
            $changes[] = 'ai_model_calls.' . $column . '_removed';
        }
        return $changes;
    }

    private function migrateModelCatalogToSub2Api(string $driver): array
    {
        $changes = [];
        $legacyRows = Db::name('ai_models')->where('gateway', '<>', 'sub2api')->order('id', 'asc')->select()->toArray();
        foreach ($legacyRows as $legacy) {
            $legacyId = (int) $legacy['id'];
            $modelId = trim((string) ($legacy['model_id'] ?? ''));
            $target = Db::name('ai_models')
                ->where('gateway', 'sub2api')
                ->where('model_id', $modelId)
                ->find();
            if (!$target) {
                Db::name('ai_models')->where('id', $legacyId)->update([
                    'gateway' => 'sub2api',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $changes[] = 'ai_models.gateway_sub2api';
                continue;
            }

            $targetId = (int) $target['id'];
            Db::name('ai_capability_routes')->where('primary_model_id', $legacyId)->update(['primary_model_id' => $targetId]);
            Db::name('ai_capability_routes')->where('fallback_model_id', $legacyId)->update(['fallback_model_id' => $targetId]);
            Db::name('ai_model_prices')->where('ai_model_id', $legacyId)->update(['ai_model_id' => $targetId]);
            Db::name('ai_model_calls')->where('ai_model_id', $legacyId)->update(['ai_model_id' => $targetId]);
            if ($this->columnExists($driver, 'generation_jobs', 'resolved_ai_model_id')) {
                Db::name('generation_jobs')->where('resolved_ai_model_id', $legacyId)->update(['resolved_ai_model_id' => $targetId]);
            }
            if ($this->columnExists($driver, 'generation_jobs', 'requested_model_id')) {
                Db::name('generation_jobs')->where('requested_model_id', $legacyId)->update(['requested_model_id' => $targetId]);
            }
            foreach (Db::name('ai_capability_routes')->field('id,route_config_json')->select()->toArray() as $route) {
                $this->replaceStoredModelReference(
                    'ai_capability_routes',
                    (int) $route['id'],
                    'route_config_json',
                    (string) ($route['route_config_json'] ?? '{}'),
                    $legacyId,
                    $targetId
                );
            }
            if ($this->columnExists($driver, 'generation_jobs', 'route_snapshot_json')) {
                foreach (Db::name('generation_jobs')
                    ->field('id,route_snapshot_json')
                    ->whereNotNull('route_snapshot_json')
                    ->select()
                    ->toArray() as $job) {
                    $this->replaceStoredModelReference(
                        'generation_jobs',
                        (int) $job['id'],
                        'route_snapshot_json',
                        (string) ($job['route_snapshot_json'] ?? '{}'),
                        $legacyId,
                        $targetId
                    );
                }
            }
            Db::name('ai_models')->where('id', $legacyId)->delete();
            $changes[] = 'ai_models.gateway_duplicate_merged';
        }
        return array_values(array_unique($changes));
    }

    private function replaceStoredModelReference(
        string $table,
        int $id,
        string $column,
        string $encoded,
        int $legacyId,
        int $targetId
    ): void {
        $payload = json_decode($encoded, true);
        if (!is_array($payload)) {
            return;
        }
        $updated = $this->replaceModelReferenceValue($payload, $legacyId, $targetId);
        if ($updated === $payload) {
            return;
        }
        Db::name($table)->where('id', $id)->update([
            $column => json_encode($updated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function replaceModelReferenceValue(mixed $value, int $legacyId, int $targetId, string $key = ''): mixed
    {
        $scalarKeys = [
            'requestedModelId',
            'resolvedModelId',
            'fallbackModelId',
            'default_model_id',
            'workflow_direct_model_id',
            'logical_model_id',
        ];
        $listKeys = ['poolModelIds', 'pool_model_ids'];
        if (in_array($key, $scalarKeys, true) && is_numeric($value) && (int) $value === $legacyId) {
            return $targetId;
        }
        if (in_array($key, $listKeys, true) && is_array($value)) {
            return array_map(
                static fn (mixed $item): mixed => is_numeric($item) && (int) $item === $legacyId ? $targetId : $item,
                $value
            );
        }
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $childKey => $childValue) {
            $value[$childKey] = $this->replaceModelReferenceValue(
                $childValue,
                $legacyId,
                $targetId,
                is_string($childKey) ? $childKey : ''
            );
        }
        return $value;
    }

    private function backfillSkuReports(): void
    {
        foreach (Db::name('skus')->field('id,merchant_id')->select()->toArray() as $sku) {
            $project = Db::name('detail_page_projects')
                ->where('merchant_id', (int) $sku['merchant_id'])
                ->where('sku_id', (int) $sku['id'])
                ->order('id', 'desc')
                ->find();
            if (!$project) {
                continue;
            }
            Db::name('skus')->where('id', (int) $sku['id'])->update([
                'search_terms' => json_encode(array_values(array_unique(array_filter([(string) ($project['product_name'] ?? ''), (string) ($project['category'] ?? '')]))), JSON_UNESCAPED_UNICODE),
                'report_materials' => $project['materials'] ?? null,
                'report_appearance' => $project['appearance'] ?? null,
                'report_target_audience' => $project['target_audience'] ?? null,
                'report_use_scenarios' => (string) ($project['use_scenarios'] ?? '[]'),
                'report_core_functions' => (string) ($project['core_functions'] ?? '[]'),
                'report_uncertain_points' => (string) ($project['uncertain_points'] ?? '[]'),
                'report_prohibited_claims' => (string) ($project['prohibited_claims'] ?? '[]'),
                'report_brand_notes' => $project['brand_notes'] ?? null,
                'report_market_research_json' => $project['market_research_json'] ?? null,
                'report_market_query' => $project['market_research_query'] ?? null,
                'report_market_updated_at' => $project['market_research_updated_at'] ?? null,
                'report_status' => 'ready',
                'report_source_project_id' => (int) $project['id'],
                'report_updated_at' => $project['updated_at'] ?? null,
            ]);
        }
    }

    private function migrationTableSql(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                'CREATE TABLE IF NOT EXISTS storage_migrations (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, source_type VARCHAR(30) NOT NULL, source_id BIGINT UNSIGNED NOT NULL, merchant_id BIGINT UNSIGNED NOT NULL, source_url TEXT NOT NULL, target_url TEXT NULL, status VARCHAR(30) NOT NULL DEFAULT \'pending\', attempts INT NOT NULL DEFAULT 0, error_message TEXT NULL, completed_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_storage_migration_source (source_type, source_id), KEY idx_storage_migration_status (status, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }
        if ($driver === 'pgsql') {
            return [
                'CREATE TABLE IF NOT EXISTS storage_migrations (id BIGSERIAL PRIMARY KEY, source_type VARCHAR(30) NOT NULL, source_id BIGINT NOT NULL, merchant_id BIGINT NOT NULL, source_url TEXT NOT NULL, target_url TEXT NULL, status VARCHAR(30) NOT NULL DEFAULT \'pending\', attempts INTEGER NOT NULL DEFAULT 0, error_message TEXT NULL, completed_at TIMESTAMP NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL, UNIQUE (source_type, source_id))',
                'CREATE INDEX IF NOT EXISTS idx_storage_migration_status ON storage_migrations(status, id)',
            ];
        }

        return [
            'CREATE TABLE IF NOT EXISTS storage_migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, source_type TEXT NOT NULL, source_id INTEGER NOT NULL, merchant_id INTEGER NOT NULL, source_url TEXT NOT NULL, target_url TEXT NULL, status TEXT NOT NULL DEFAULT \'pending\', attempts INTEGER NOT NULL DEFAULT 0, error_message TEXT NULL, completed_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (source_type, source_id))',
            'CREATE INDEX IF NOT EXISTS idx_storage_migration_status ON storage_migrations(status, id)',
        ];
    }

    private function adminNavigationTableSql(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                'CREATE TABLE IF NOT EXISTS admin_navigation_preferences (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, admin_id BIGINT UNSIGNED NOT NULL, path VARCHAR(255) NOT NULL, title VARCHAR(160) NOT NULL, is_favorite TINYINT NOT NULL DEFAULT 0, last_visited_at DATETIME NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_admin_navigation_path (admin_id, path), KEY idx_admin_navigation_recent (admin_id, last_visited_at), CONSTRAINT fk_admin_navigation_admin FOREIGN KEY (admin_id) REFERENCES admin_users (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }
        if ($driver === 'pgsql') {
            return [
                'CREATE TABLE IF NOT EXISTS admin_navigation_preferences (id BIGSERIAL PRIMARY KEY, admin_id BIGINT NOT NULL REFERENCES admin_users(id), path VARCHAR(255) NOT NULL, title VARCHAR(160) NOT NULL, is_favorite SMALLINT NOT NULL DEFAULT 0, last_visited_at TIMESTAMP NOT NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL, UNIQUE (admin_id, path))',
                'CREATE INDEX IF NOT EXISTS idx_admin_navigation_recent ON admin_navigation_preferences(admin_id, last_visited_at DESC)',
            ];
        }
        return [
            'CREATE TABLE IF NOT EXISTS admin_navigation_preferences (id INTEGER PRIMARY KEY AUTOINCREMENT, admin_id INTEGER NOT NULL, path TEXT NOT NULL, title TEXT NOT NULL, is_favorite INTEGER NOT NULL DEFAULT 0, last_visited_at TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (admin_id, path), FOREIGN KEY (admin_id) REFERENCES admin_users(id))',
            'CREATE INDEX IF NOT EXISTS idx_admin_navigation_recent ON admin_navigation_preferences(admin_id, last_visited_at)',
        ];
    }

    private function marketResearchTableSql(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                'CREATE TABLE IF NOT EXISTS market_research_cache (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, merchant_id BIGINT UNSIGNED NOT NULL, sku_id BIGINT UNSIGNED NULL, query_hash CHAR(64) NOT NULL, query_text TEXT NOT NULL, result_json LONGTEXT NOT NULL, source_count INT NOT NULL DEFAULT 0, provider_credits INT NOT NULL DEFAULT 0, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_market_research_merchant_query (merchant_id, query_hash), KEY idx_market_research_expiry (expires_at), CONSTRAINT fk_market_research_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id), CONSTRAINT fk_market_research_sku FOREIGN KEY (sku_id) REFERENCES skus (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }
        if ($driver === 'pgsql') {
            return [
                'CREATE TABLE IF NOT EXISTS market_research_cache (id BIGSERIAL PRIMARY KEY, merchant_id BIGINT NOT NULL REFERENCES merchants(id), sku_id BIGINT NULL REFERENCES skus(id), query_hash CHAR(64) NOT NULL, query_text TEXT NOT NULL, result_json TEXT NOT NULL, source_count INTEGER NOT NULL DEFAULT 0, provider_credits INTEGER NOT NULL DEFAULT 0, expires_at TIMESTAMP NOT NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL, UNIQUE (merchant_id, query_hash))',
                'CREATE INDEX IF NOT EXISTS idx_market_research_expiry ON market_research_cache(expires_at)',
            ];
        }
        return [
            'CREATE TABLE IF NOT EXISTS market_research_cache (id INTEGER PRIMARY KEY AUTOINCREMENT, merchant_id INTEGER NOT NULL, sku_id INTEGER NULL, query_hash TEXT NOT NULL, query_text TEXT NOT NULL, result_json TEXT NOT NULL, source_count INTEGER NOT NULL DEFAULT 0, provider_credits INTEGER NOT NULL DEFAULT 0, expires_at TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (merchant_id, query_hash), FOREIGN KEY (merchant_id) REFERENCES merchants(id), FOREIGN KEY (sku_id) REFERENCES skus(id))',
            'CREATE INDEX IF NOT EXISTS idx_market_research_expiry ON market_research_cache(expires_at)',
        ];
    }

    private function detailPageTableSql(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                "CREATE TABLE IF NOT EXISTS detail_page_projects (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, merchant_id BIGINT UNSIGNED NOT NULL, created_by_user_id BIGINT UNSIGNED NULL, sku_id BIGINT UNSIGNED NULL, product_name VARCHAR(160) NOT NULL, category VARCHAR(100) NOT NULL, materials TEXT NULL, appearance TEXT NULL, target_audience TEXT NULL, use_scenarios LONGTEXT NOT NULL, selling_points LONGTEXT NOT NULL, core_functions LONGTEXT NOT NULL, uncertain_points LONGTEXT NOT NULL, prohibited_claims LONGTEXT NOT NULL, product_description LONGTEXT NULL, brand_notes TEXT NULL, creative_brief TEXT NULL, screen_plan LONGTEXT NOT NULL, source_image_url TEXT NOT NULL, source_image_backup_url TEXT NULL, platform VARCHAR(40) NOT NULL, language VARCHAR(20) NOT NULL DEFAULT 'zh-CN', status VARCHAR(30) NOT NULL DEFAULT 'draft', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_detail_projects_merchant (merchant_id, id), KEY idx_detail_projects_sku (sku_id, id), CONSTRAINT fk_detail_projects_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id), CONSTRAINT fk_detail_projects_user FOREIGN KEY (created_by_user_id) REFERENCES merchant_users (id), CONSTRAINT fk_detail_projects_sku FOREIGN KEY (sku_id) REFERENCES skus (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS detail_page_messages (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, project_id BIGINT UNSIGNED NOT NULL, merchant_id BIGINT UNSIGNED NOT NULL, role VARCHAR(20) NOT NULL, content LONGTEXT NOT NULL, request_id VARCHAR(190) NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_detail_messages_project (project_id, id), CONSTRAINT fk_detail_messages_project FOREIGN KEY (project_id) REFERENCES detail_page_projects (id), CONSTRAINT fk_detail_messages_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ];
        }
        if ($driver === 'pgsql') {
            return [
                "CREATE TABLE IF NOT EXISTS detail_page_projects (id BIGSERIAL PRIMARY KEY, merchant_id BIGINT NOT NULL REFERENCES merchants(id), created_by_user_id BIGINT NULL REFERENCES merchant_users(id), sku_id BIGINT NULL REFERENCES skus(id), product_name VARCHAR(160) NOT NULL, category VARCHAR(100) NOT NULL, materials TEXT NULL, appearance TEXT NULL, target_audience TEXT NULL, use_scenarios JSONB NOT NULL DEFAULT '[]'::jsonb, selling_points JSONB NOT NULL DEFAULT '[]'::jsonb, core_functions JSONB NOT NULL DEFAULT '[]'::jsonb, uncertain_points JSONB NOT NULL DEFAULT '[]'::jsonb, prohibited_claims JSONB NOT NULL DEFAULT '[]'::jsonb, product_description TEXT NULL, brand_notes TEXT NULL, creative_brief TEXT NULL, screen_plan JSONB NOT NULL DEFAULT '[]'::jsonb, source_image_url TEXT NOT NULL, source_image_backup_url TEXT NULL, platform VARCHAR(40) NOT NULL, language VARCHAR(20) NOT NULL DEFAULT 'zh-CN', status VARCHAR(30) NOT NULL DEFAULT 'draft', created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL)",
                'CREATE INDEX IF NOT EXISTS idx_detail_projects_merchant ON detail_page_projects(merchant_id, id)',
                "CREATE TABLE IF NOT EXISTS detail_page_messages (id BIGSERIAL PRIMARY KEY, project_id BIGINT NOT NULL REFERENCES detail_page_projects(id), merchant_id BIGINT NOT NULL REFERENCES merchants(id), role VARCHAR(20) NOT NULL, content TEXT NOT NULL, request_id VARCHAR(190) NULL, created_at TIMESTAMP NOT NULL)",
                'CREATE INDEX IF NOT EXISTS idx_detail_messages_project ON detail_page_messages(project_id, id)',
            ];
        }
        return [
            "CREATE TABLE IF NOT EXISTS detail_page_projects (id INTEGER PRIMARY KEY AUTOINCREMENT, merchant_id INTEGER NOT NULL, created_by_user_id INTEGER NULL, sku_id INTEGER NULL, product_name TEXT NOT NULL, category TEXT NOT NULL, materials TEXT NULL, appearance TEXT NULL, target_audience TEXT NULL, use_scenarios TEXT NOT NULL DEFAULT '[]', selling_points TEXT NOT NULL DEFAULT '[]', core_functions TEXT NOT NULL DEFAULT '[]', uncertain_points TEXT NOT NULL DEFAULT '[]', prohibited_claims TEXT NOT NULL DEFAULT '[]', product_description TEXT NULL, brand_notes TEXT NULL, creative_brief TEXT NULL, screen_plan TEXT NOT NULL DEFAULT '[]', source_image_url TEXT NOT NULL, source_image_backup_url TEXT NULL, platform TEXT NOT NULL, language TEXT NOT NULL DEFAULT 'zh-CN', status TEXT NOT NULL DEFAULT 'draft', created_at TEXT NOT NULL, updated_at TEXT NOT NULL, FOREIGN KEY (merchant_id) REFERENCES merchants(id), FOREIGN KEY (created_by_user_id) REFERENCES merchant_users(id), FOREIGN KEY (sku_id) REFERENCES skus(id))",
            'CREATE INDEX IF NOT EXISTS idx_detail_projects_merchant ON detail_page_projects(merchant_id, id)',
            "CREATE TABLE IF NOT EXISTS detail_page_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, merchant_id INTEGER NOT NULL, role TEXT NOT NULL, content TEXT NOT NULL, request_id TEXT NULL, created_at TEXT NOT NULL, FOREIGN KEY (project_id) REFERENCES detail_page_projects(id), FOREIGN KEY (merchant_id) REFERENCES merchants(id))",
            'CREATE INDEX IF NOT EXISTS idx_detail_messages_project ON detail_page_messages(project_id, id)',
        ];
    }

    private function generationItemTableSql(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                "CREATE TABLE IF NOT EXISTS generation_job_items (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, job_id BIGINT UNSIGNED NOT NULL, merchant_id BIGINT UNSIGNED NOT NULL, position INT NOT NULL, asset_type VARCHAR(60) NOT NULL, occurrence INT NOT NULL DEFAULT 1, type_total INT NOT NULL DEFAULT 1, estimated_credits INT NOT NULL DEFAULT 0, status VARCHAR(30) NOT NULL DEFAULT 'queued', attempts INT NOT NULL DEFAULT 0, manual_retry_count INT NOT NULL DEFAULT 0, next_retry_at DATETIME NULL, last_retry_reason TEXT NULL, worker_slot INT NULL, reservation_ledger_id BIGINT UNSIGNED NULL, asset_id BIGINT UNSIGNED NULL, request_id VARCHAR(190) NULL, error_message TEXT NULL, stage_timestamps_json LONGTEXT NULL, started_at DATETIME NULL, completed_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_generation_items_position (job_id, position), KEY idx_generation_items_queue (status, id), KEY idx_generation_items_retry (status, next_retry_at, id), KEY idx_generation_items_job (job_id, status, id), KEY idx_generation_items_merchant (merchant_id, status, id), CONSTRAINT fk_generation_items_job FOREIGN KEY (job_id) REFERENCES generation_jobs (id), CONSTRAINT fk_generation_items_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ];
        }
        if ($driver === 'pgsql') {
            return [
                "CREATE TABLE IF NOT EXISTS generation_job_items (id BIGSERIAL PRIMARY KEY, job_id BIGINT NOT NULL REFERENCES generation_jobs(id), merchant_id BIGINT NOT NULL REFERENCES merchants(id), position INTEGER NOT NULL, asset_type VARCHAR(60) NOT NULL, occurrence INTEGER NOT NULL DEFAULT 1, type_total INTEGER NOT NULL DEFAULT 1, estimated_credits INTEGER NOT NULL DEFAULT 0, status VARCHAR(30) NOT NULL DEFAULT 'queued', attempts INTEGER NOT NULL DEFAULT 0, manual_retry_count INTEGER NOT NULL DEFAULT 0, next_retry_at TIMESTAMP NULL, last_retry_reason TEXT NULL, worker_slot INTEGER NULL, reservation_ledger_id BIGINT NULL, asset_id BIGINT NULL, request_id VARCHAR(190) NULL, error_message TEXT NULL, stage_timestamps_json TEXT NOT NULL DEFAULT '{}', started_at TIMESTAMP NULL, completed_at TIMESTAMP NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL, UNIQUE (job_id, position))",
                'CREATE INDEX IF NOT EXISTS idx_generation_items_queue ON generation_job_items(status, id)',
                'CREATE INDEX IF NOT EXISTS idx_generation_items_job ON generation_job_items(job_id, status, id)',
                'CREATE INDEX IF NOT EXISTS idx_generation_items_merchant ON generation_job_items(merchant_id, status, id)',
            ];
        }

        return [
            "CREATE TABLE IF NOT EXISTS generation_job_items (id INTEGER PRIMARY KEY AUTOINCREMENT, job_id INTEGER NOT NULL, merchant_id INTEGER NOT NULL, position INTEGER NOT NULL, asset_type TEXT NOT NULL, occurrence INTEGER NOT NULL DEFAULT 1, type_total INTEGER NOT NULL DEFAULT 1, estimated_credits INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'queued', attempts INTEGER NOT NULL DEFAULT 0, manual_retry_count INTEGER NOT NULL DEFAULT 0, next_retry_at TEXT NULL, last_retry_reason TEXT NULL, worker_slot INTEGER NULL, reservation_ledger_id INTEGER NULL, asset_id INTEGER NULL, request_id TEXT NULL, error_message TEXT NULL, stage_timestamps_json TEXT NOT NULL DEFAULT '{}', started_at TEXT NULL, completed_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (job_id, position), FOREIGN KEY (job_id) REFERENCES generation_jobs(id), FOREIGN KEY (merchant_id) REFERENCES merchants(id))",
            'CREATE INDEX IF NOT EXISTS idx_generation_items_queue ON generation_job_items(status, id)',
            'CREATE INDEX IF NOT EXISTS idx_generation_items_job ON generation_job_items(job_id, status, id)',
            'CREATE INDEX IF NOT EXISTS idx_generation_items_merchant ON generation_job_items(merchant_id, status, id)',
        ];
    }

    private function contentSafetyTableSql(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                'CREATE TABLE IF NOT EXISTS content_moderation_checks (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, merchant_id BIGINT UNSIGNED NULL, user_id BIGINT UNSIGNED NULL, content_type VARCHAR(20) NOT NULL, content_hash VARCHAR(64) NOT NULL, source VARCHAR(80) NOT NULL, status VARCHAR(20) NOT NULL, categories TEXT NOT NULL, reason TEXT NULL, model VARCHAR(120) NULL, request_id VARCHAR(190) NULL, cache_hit_count INT NOT NULL DEFAULT 0, last_cache_hit_at DATETIME NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_moderation_checks_hash (content_hash, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                'CREATE TABLE IF NOT EXISTS content_violations (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, merchant_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NULL, content_type VARCHAR(20) NOT NULL, content_hash VARCHAR(64) NOT NULL, source VARCHAR(80) NOT NULL, categories TEXT NOT NULL, reason TEXT NULL, content_excerpt TEXT NULL, penalty_credits INT NOT NULL DEFAULT 0, warning_email_status VARCHAR(20) NOT NULL DEFAULT \'pending\', warning_error TEXT NULL, ip_address VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_content_violation_unique (merchant_id, content_type, content_hash), KEY idx_content_violations_created (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }
        if ($driver === 'pgsql') {
            return [
                'CREATE TABLE IF NOT EXISTS content_moderation_checks (id BIGSERIAL PRIMARY KEY, merchant_id BIGINT NULL, user_id BIGINT NULL, content_type VARCHAR(20) NOT NULL, content_hash VARCHAR(64) NOT NULL, source VARCHAR(80) NOT NULL, status VARCHAR(20) NOT NULL, categories TEXT NOT NULL, reason TEXT NULL, model VARCHAR(120) NULL, request_id VARCHAR(190) NULL, cache_hit_count INTEGER NOT NULL DEFAULT 0, last_cache_hit_at TIMESTAMP NULL, created_at TIMESTAMP NOT NULL)',
                'CREATE INDEX IF NOT EXISTS idx_moderation_checks_hash ON content_moderation_checks(content_hash, status)',
                'CREATE TABLE IF NOT EXISTS content_violations (id BIGSERIAL PRIMARY KEY, merchant_id BIGINT NOT NULL, user_id BIGINT NULL, content_type VARCHAR(20) NOT NULL, content_hash VARCHAR(64) NOT NULL, source VARCHAR(80) NOT NULL, categories TEXT NOT NULL, reason TEXT NULL, content_excerpt TEXT NULL, penalty_credits INTEGER NOT NULL DEFAULT 0, warning_email_status VARCHAR(20) NOT NULL DEFAULT \'pending\', warning_error TEXT NULL, ip_address VARCHAR(64) NOT NULL, created_at TIMESTAMP NOT NULL, UNIQUE (merchant_id, content_type, content_hash))',
                'CREATE INDEX IF NOT EXISTS idx_content_violations_created ON content_violations(created_at)',
            ];
        }

        return [
            'CREATE TABLE IF NOT EXISTS content_moderation_checks (id INTEGER PRIMARY KEY AUTOINCREMENT, merchant_id INTEGER NULL, user_id INTEGER NULL, content_type TEXT NOT NULL, content_hash TEXT NOT NULL, source TEXT NOT NULL, status TEXT NOT NULL, categories TEXT NOT NULL, reason TEXT NULL, model TEXT NULL, request_id TEXT NULL, cache_hit_count INTEGER NOT NULL DEFAULT 0, last_cache_hit_at TEXT NULL, created_at TEXT NOT NULL)',
            'CREATE INDEX IF NOT EXISTS idx_moderation_checks_hash ON content_moderation_checks(content_hash, status)',
            'CREATE TABLE IF NOT EXISTS content_violations (id INTEGER PRIMARY KEY AUTOINCREMENT, merchant_id INTEGER NOT NULL, user_id INTEGER NULL, content_type TEXT NOT NULL, content_hash TEXT NOT NULL, source TEXT NOT NULL, categories TEXT NOT NULL, reason TEXT NULL, content_excerpt TEXT NULL, penalty_credits INTEGER NOT NULL DEFAULT 0, warning_email_status TEXT NOT NULL DEFAULT \'pending\', warning_error TEXT NULL, ip_address TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE (merchant_id, content_type, content_hash))',
            'CREATE INDEX IF NOT EXISTS idx_content_violations_created ON content_violations(created_at)',
        ];
    }

    private function loginThrottleTableSql(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                'CREATE TABLE IF NOT EXISTS login_throttle_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, scope VARCHAR(30) NOT NULL, key_type VARCHAR(20) NOT NULL, key_hash CHAR(64) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_login_throttle_lookup (scope, key_type, key_hash, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }
        if ($driver === 'pgsql') {
            return [
                'CREATE TABLE IF NOT EXISTS login_throttle_events (id BIGSERIAL PRIMARY KEY, scope VARCHAR(30) NOT NULL, key_type VARCHAR(20) NOT NULL, key_hash CHAR(64) NOT NULL, created_at TIMESTAMP NOT NULL)',
                'CREATE INDEX IF NOT EXISTS idx_login_throttle_lookup ON login_throttle_events(scope, key_type, key_hash, created_at)',
            ];
        }

        return [
            'CREATE TABLE IF NOT EXISTS login_throttle_events (id INTEGER PRIMARY KEY AUTOINCREMENT, scope TEXT NOT NULL, key_type TEXT NOT NULL, key_hash TEXT NOT NULL, created_at TEXT NOT NULL)',
            'CREATE INDEX IF NOT EXISTS idx_login_throttle_lookup ON login_throttle_events(scope, key_type, key_hash, created_at)',
        ];
    }

    private function subscriptionTableSql(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                'CREATE TABLE IF NOT EXISTS subscription_plans (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, code VARCHAR(40) NOT NULL, name VARCHAR(120) NOT NULL, description TEXT NULL, duration_days INT NOT NULL, price_credits INT NOT NULL DEFAULT 0, generation_unlimited TINYINT NOT NULL DEFAULT 0, recognition_unlimited TINYINT NOT NULL DEFAULT 0, daily_generation_limit INT NOT NULL DEFAULT 0, status VARCHAR(30) NOT NULL DEFAULT \'active\', sort_order INT NOT NULL DEFAULT 0, created_by_admin_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_subscription_plans_code (code), KEY idx_subscription_plans_status (status, sort_order), CONSTRAINT fk_subscription_plans_admin FOREIGN KEY (created_by_admin_id) REFERENCES admin_users (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                'CREATE TABLE IF NOT EXISTS merchant_subscriptions (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, merchant_id BIGINT UNSIGNED NOT NULL, plan_id BIGINT UNSIGNED NOT NULL, plan_code VARCHAR(40) NOT NULL, plan_name VARCHAR(120) NOT NULL, price_credits INT NOT NULL, starts_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, status VARCHAR(30) NOT NULL DEFAULT \'active\', generation_unlimited TINYINT NOT NULL DEFAULT 0, recognition_unlimited TINYINT NOT NULL DEFAULT 0, daily_generation_limit INT NOT NULL DEFAULT 0, purchased_at DATETIME NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_merchant_subscriptions_active (merchant_id, status, expires_at), CONSTRAINT fk_merchant_subscriptions_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id), CONSTRAINT fk_merchant_subscriptions_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }
        if ($driver === 'pgsql') {
            return [
                'CREATE TABLE IF NOT EXISTS subscription_plans (id BIGSERIAL PRIMARY KEY, code VARCHAR(40) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL, description TEXT NULL, duration_days INTEGER NOT NULL, price_credits INTEGER NOT NULL DEFAULT 0, generation_unlimited SMALLINT NOT NULL DEFAULT 0, recognition_unlimited SMALLINT NOT NULL DEFAULT 0, daily_generation_limit INTEGER NOT NULL DEFAULT 0, status VARCHAR(30) NOT NULL DEFAULT \'active\', sort_order INTEGER NOT NULL DEFAULT 0, created_by_admin_id BIGINT NULL REFERENCES admin_users(id), created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL)',
                'CREATE TABLE IF NOT EXISTS merchant_subscriptions (id BIGSERIAL PRIMARY KEY, merchant_id BIGINT NOT NULL REFERENCES merchants(id), plan_id BIGINT NOT NULL REFERENCES subscription_plans(id), plan_code VARCHAR(40) NOT NULL, plan_name VARCHAR(120) NOT NULL, price_credits INTEGER NOT NULL, starts_at TIMESTAMP NOT NULL, expires_at TIMESTAMP NOT NULL, status VARCHAR(30) NOT NULL DEFAULT \'active\', generation_unlimited SMALLINT NOT NULL DEFAULT 0, recognition_unlimited SMALLINT NOT NULL DEFAULT 0, daily_generation_limit INTEGER NOT NULL DEFAULT 0, purchased_at TIMESTAMP NOT NULL, created_at TIMESTAMP NOT NULL)',
                'CREATE INDEX IF NOT EXISTS idx_merchant_subscriptions_active ON merchant_subscriptions(merchant_id, status, expires_at)',
            ];
        }

        return [
            'CREATE TABLE IF NOT EXISTS subscription_plans (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, description TEXT NULL, duration_days INTEGER NOT NULL, price_credits INTEGER NOT NULL DEFAULT 0, generation_unlimited INTEGER NOT NULL DEFAULT 0, recognition_unlimited INTEGER NOT NULL DEFAULT 0, daily_generation_limit INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT \'active\', sort_order INTEGER NOT NULL DEFAULT 0, created_by_admin_id INTEGER NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS merchant_subscriptions (id INTEGER PRIMARY KEY AUTOINCREMENT, merchant_id INTEGER NOT NULL, plan_id INTEGER NOT NULL, plan_code TEXT NOT NULL, plan_name TEXT NOT NULL, price_credits INTEGER NOT NULL, starts_at TEXT NOT NULL, expires_at TEXT NOT NULL, status TEXT NOT NULL DEFAULT \'active\', generation_unlimited INTEGER NOT NULL DEFAULT 0, recognition_unlimited INTEGER NOT NULL DEFAULT 0, daily_generation_limit INTEGER NOT NULL DEFAULT 0, purchased_at TEXT NOT NULL, created_at TEXT NOT NULL)',
            'CREATE INDEX IF NOT EXISTS idx_merchant_subscriptions_active ON merchant_subscriptions(merchant_id, status, expires_at)',
        ];
    }

    private function promptTemplateTableSql(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                'CREATE TABLE IF NOT EXISTS prompt_templates (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, code VARCHAR(100) NOT NULL, name VARCHAR(160) NOT NULL, scene VARCHAR(40) NOT NULL, platform VARCHAR(40) NOT NULL DEFAULT \'*\', asset_type VARCHAR(60) NOT NULL DEFAULT \'*\', content LONGTEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT \'active\', is_default TINYINT NOT NULL DEFAULT 0, is_system TINYINT NOT NULL DEFAULT 0, is_customized TINYINT NOT NULL DEFAULT 0, created_by_admin_id BIGINT UNSIGNED NULL, updated_by_admin_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_prompt_templates_code (code), KEY idx_prompt_templates_resolve (scene, platform, asset_type, status, is_default)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }
        if ($driver === 'pgsql') {
            return [
                'CREATE TABLE IF NOT EXISTS prompt_templates (id BIGSERIAL PRIMARY KEY, code VARCHAR(100) NOT NULL UNIQUE, name VARCHAR(160) NOT NULL, scene VARCHAR(40) NOT NULL, platform VARCHAR(40) NOT NULL DEFAULT \'*\', asset_type VARCHAR(60) NOT NULL DEFAULT \'*\', content TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT \'active\', is_default SMALLINT NOT NULL DEFAULT 0, is_system SMALLINT NOT NULL DEFAULT 0, is_customized SMALLINT NOT NULL DEFAULT 0, created_by_admin_id BIGINT NULL, updated_by_admin_id BIGINT NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL)',
                'CREATE INDEX IF NOT EXISTS idx_prompt_templates_resolve ON prompt_templates(scene, platform, asset_type, status, is_default)',
            ];
        }

        return [
            'CREATE TABLE IF NOT EXISTS prompt_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, scene TEXT NOT NULL, platform TEXT NOT NULL DEFAULT \'*\', asset_type TEXT NOT NULL DEFAULT \'*\', content TEXT NOT NULL, status TEXT NOT NULL DEFAULT \'active\', is_default INTEGER NOT NULL DEFAULT 0, is_system INTEGER NOT NULL DEFAULT 0, is_customized INTEGER NOT NULL DEFAULT 0, created_by_admin_id INTEGER NULL, updated_by_admin_id INTEGER NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)',
            'CREATE INDEX IF NOT EXISTS idx_prompt_templates_resolve ON prompt_templates(scene, platform, asset_type, status, is_default)',
        ];
    }

    private function productFeedbackTableSql(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                'CREATE TABLE IF NOT EXISTS product_feedback (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, merchant_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, module VARCHAR(40) NOT NULL, rating INT NOT NULL, issue_type VARCHAR(40) NOT NULL, improvement_type VARCHAR(40) NOT NULL, desired_improvement LONGTEXT NOT NULL, usage_context LONGTEXT NULL, related_reference VARCHAR(190) NULL, allow_contact TINYINT NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT \'new\', priority VARCHAR(20) NOT NULL DEFAULT \'normal\', admin_note LONGTEXT NULL, handled_by_admin_id BIGINT UNSIGNED NULL, handled_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_product_feedback_status (status, priority, id), KEY idx_product_feedback_merchant (merchant_id, id), KEY idx_product_feedback_improvement (improvement_type, id), CONSTRAINT fk_product_feedback_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id), CONSTRAINT fk_product_feedback_user FOREIGN KEY (user_id) REFERENCES merchant_users (id), CONSTRAINT fk_product_feedback_admin FOREIGN KEY (handled_by_admin_id) REFERENCES admin_users (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }
        if ($driver === 'pgsql') {
            return [
                'CREATE TABLE IF NOT EXISTS product_feedback (id BIGSERIAL PRIMARY KEY, merchant_id BIGINT NOT NULL REFERENCES merchants(id), user_id BIGINT NOT NULL REFERENCES merchant_users(id), module VARCHAR(40) NOT NULL, rating INTEGER NOT NULL, issue_type VARCHAR(40) NOT NULL, improvement_type VARCHAR(40) NOT NULL, desired_improvement TEXT NOT NULL, usage_context TEXT NULL, related_reference VARCHAR(190) NULL, allow_contact SMALLINT NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT \'new\', priority VARCHAR(20) NOT NULL DEFAULT \'normal\', admin_note TEXT NULL, handled_by_admin_id BIGINT NULL REFERENCES admin_users(id), handled_at TIMESTAMP NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL)',
                'CREATE INDEX IF NOT EXISTS idx_product_feedback_status ON product_feedback(status, priority, id)',
                'CREATE INDEX IF NOT EXISTS idx_product_feedback_merchant ON product_feedback(merchant_id, id)',
                'CREATE INDEX IF NOT EXISTS idx_product_feedback_improvement ON product_feedback(improvement_type, id)',
            ];
        }

        return [
            'CREATE TABLE IF NOT EXISTS product_feedback (id INTEGER PRIMARY KEY AUTOINCREMENT, merchant_id INTEGER NOT NULL, user_id INTEGER NOT NULL, module TEXT NOT NULL, rating INTEGER NOT NULL, issue_type TEXT NOT NULL, improvement_type TEXT NOT NULL, desired_improvement TEXT NOT NULL, usage_context TEXT NULL, related_reference TEXT NULL, allow_contact INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT \'new\', priority TEXT NOT NULL DEFAULT \'normal\', admin_note TEXT NULL, handled_by_admin_id INTEGER NULL, handled_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, FOREIGN KEY (merchant_id) REFERENCES merchants(id), FOREIGN KEY (user_id) REFERENCES merchant_users(id), FOREIGN KEY (handled_by_admin_id) REFERENCES admin_users(id))',
            'CREATE INDEX IF NOT EXISTS idx_product_feedback_status ON product_feedback(status, priority, id)',
            'CREATE INDEX IF NOT EXISTS idx_product_feedback_merchant ON product_feedback(merchant_id, id)',
            'CREATE INDEX IF NOT EXISTS idx_product_feedback_improvement ON product_feedback(improvement_type, id)',
        ];
    }

    private function imageToolResultTableSql(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                'CREATE TABLE IF NOT EXISTS image_tool_results (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, merchant_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, asset_id BIGINT UNSIGNED NOT NULL, result_type VARCHAR(40) NOT NULL, content LONGTEXT NOT NULL, model VARCHAR(160) NULL, request_id VARCHAR(190) NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_image_tool_results_merchant (merchant_id, result_type, id), KEY idx_image_tool_results_asset (asset_id, id), CONSTRAINT fk_image_tool_results_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id), CONSTRAINT fk_image_tool_results_user FOREIGN KEY (user_id) REFERENCES merchant_users (id), CONSTRAINT fk_image_tool_results_asset FOREIGN KEY (asset_id) REFERENCES assets (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }
        if ($driver === 'pgsql') {
            return [
                'CREATE TABLE IF NOT EXISTS image_tool_results (id BIGSERIAL PRIMARY KEY, merchant_id BIGINT NOT NULL REFERENCES merchants(id), user_id BIGINT NOT NULL REFERENCES merchant_users(id), asset_id BIGINT NOT NULL REFERENCES assets(id), result_type VARCHAR(40) NOT NULL, content TEXT NOT NULL, model VARCHAR(160) NULL, request_id VARCHAR(190) NULL, created_at TIMESTAMP NOT NULL)',
                'CREATE INDEX IF NOT EXISTS idx_image_tool_results_merchant ON image_tool_results(merchant_id, result_type, id DESC)',
                'CREATE INDEX IF NOT EXISTS idx_image_tool_results_asset ON image_tool_results(asset_id, id DESC)',
            ];
        }

        return [
            'CREATE TABLE IF NOT EXISTS image_tool_results (id INTEGER PRIMARY KEY AUTOINCREMENT, merchant_id INTEGER NOT NULL, user_id INTEGER NOT NULL, asset_id INTEGER NOT NULL, result_type TEXT NOT NULL, content TEXT NOT NULL, model TEXT NULL, request_id TEXT NULL, created_at TEXT NOT NULL, FOREIGN KEY (merchant_id) REFERENCES merchants(id), FOREIGN KEY (user_id) REFERENCES merchant_users(id), FOREIGN KEY (asset_id) REFERENCES assets(id))',
            'CREATE INDEX IF NOT EXISTS idx_image_tool_results_merchant ON image_tool_results(merchant_id, result_type, id DESC)',
            'CREATE INDEX IF NOT EXISTS idx_image_tool_results_asset ON image_tool_results(asset_id, id DESC)',
        ];
    }

    private function assetBatchLabelTableSql(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                'CREATE TABLE IF NOT EXISTS asset_batch_labels (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, merchant_id BIGINT UNSIGNED NOT NULL, batch_key VARCHAR(190) NOT NULL, label VARCHAR(120) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_asset_batch_labels_key (merchant_id, batch_key), CONSTRAINT fk_asset_batch_labels_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }
        if ($driver === 'pgsql') {
            return [
                'CREATE TABLE IF NOT EXISTS asset_batch_labels (id BIGSERIAL PRIMARY KEY, merchant_id BIGINT NOT NULL REFERENCES merchants(id), batch_key VARCHAR(190) NOT NULL, label VARCHAR(120) NOT NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL, UNIQUE (merchant_id, batch_key))',
            ];
        }
        return [
            'CREATE TABLE IF NOT EXISTS asset_batch_labels (id INTEGER PRIMARY KEY AUTOINCREMENT, merchant_id INTEGER NOT NULL, batch_key TEXT NOT NULL, label TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (merchant_id, batch_key), FOREIGN KEY (merchant_id) REFERENCES merchants(id))',
        ];
    }

    private function canvasEditTableSql(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                'CREATE TABLE IF NOT EXISTS canvas_edits (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, job_id BIGINT UNSIGNED NOT NULL, source_asset_id BIGINT UNSIGNED NOT NULL, mode VARCHAR(20) NOT NULL DEFAULT \'global\', preset VARCHAR(40) NOT NULL DEFAULT \'custom\', instruction LONGTEXT NOT NULL, mask_file TEXT NULL, output_preset VARCHAR(40) NOT NULL DEFAULT \'square_1_1\', output_width INT NOT NULL DEFAULT 1024, output_height INT NOT NULL DEFAULT 1024, resize_mode VARCHAR(20) NOT NULL DEFAULT \'extend\', operation VARCHAR(20) NOT NULL DEFAULT \'ai_edit\', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_canvas_edits_job (job_id), KEY idx_canvas_edits_source (source_asset_id), CONSTRAINT fk_canvas_edits_job FOREIGN KEY (job_id) REFERENCES generation_jobs (id), CONSTRAINT fk_canvas_edits_source FOREIGN KEY (source_asset_id) REFERENCES assets (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }
        if ($driver === 'pgsql') {
            return [
                'CREATE TABLE IF NOT EXISTS canvas_edits (id BIGSERIAL PRIMARY KEY, job_id BIGINT NOT NULL UNIQUE REFERENCES generation_jobs(id), source_asset_id BIGINT NOT NULL REFERENCES assets(id), mode VARCHAR(20) NOT NULL DEFAULT \'global\', preset VARCHAR(40) NOT NULL DEFAULT \'custom\', instruction TEXT NOT NULL, mask_file TEXT NULL, output_preset VARCHAR(40) NOT NULL DEFAULT \'square_1_1\', output_width INTEGER NOT NULL DEFAULT 1024, output_height INTEGER NOT NULL DEFAULT 1024, resize_mode VARCHAR(20) NOT NULL DEFAULT \'extend\', operation VARCHAR(20) NOT NULL DEFAULT \'ai_edit\', created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL)',
                'CREATE INDEX IF NOT EXISTS idx_canvas_edits_source ON canvas_edits(source_asset_id)',
            ];
        }

        return [
            'CREATE TABLE IF NOT EXISTS canvas_edits (id INTEGER PRIMARY KEY AUTOINCREMENT, job_id INTEGER NOT NULL UNIQUE, source_asset_id INTEGER NOT NULL, mode TEXT NOT NULL DEFAULT \'global\', preset TEXT NOT NULL DEFAULT \'custom\', instruction TEXT NOT NULL, mask_file TEXT NULL, output_preset TEXT NOT NULL DEFAULT \'square_1_1\', output_width INTEGER NOT NULL DEFAULT 1024, output_height INTEGER NOT NULL DEFAULT 1024, resize_mode TEXT NOT NULL DEFAULT \'extend\', operation TEXT NOT NULL DEFAULT \'ai_edit\', created_at TEXT NOT NULL, updated_at TEXT NOT NULL, FOREIGN KEY (job_id) REFERENCES generation_jobs(id), FOREIGN KEY (source_asset_id) REFERENCES assets(id))',
            'CREATE INDEX IF NOT EXISTS idx_canvas_edits_source ON canvas_edits(source_asset_id)',
        ];
    }

    private function canvasWorkspaceTableSql(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                'CREATE TABLE IF NOT EXISTS canvas_workspaces (merchant_id BIGINT UNSIGNED NOT NULL, graph_json LONGTEXT NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (merchant_id), CONSTRAINT fk_canvas_workspaces_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }
        if ($driver === 'pgsql') {
            return [
                'CREATE TABLE IF NOT EXISTS canvas_workspaces (merchant_id BIGINT PRIMARY KEY REFERENCES merchants(id), graph_json TEXT NOT NULL, updated_at TIMESTAMP NOT NULL)',
            ];
        }

        return [
            'CREATE TABLE IF NOT EXISTS canvas_workspaces (merchant_id INTEGER PRIMARY KEY, graph_json TEXT NOT NULL, updated_at TEXT NOT NULL, FOREIGN KEY (merchant_id) REFERENCES merchants(id))',
        ];
    }

    private function workspaceProjectPreferenceTableSql(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                'CREATE TABLE IF NOT EXISTS workspace_project_preferences (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, merchant_id BIGINT UNSIGNED NOT NULL, project_type VARCHAR(20) NOT NULL, project_id BIGINT UNSIGNED NOT NULL, custom_name VARCHAR(120) NULL, pinned TINYINT NOT NULL DEFAULT 0, hidden TINYINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_workspace_project_preference (merchant_id, project_type, project_id), KEY idx_workspace_project_sidebar (merchant_id, hidden, pinned, updated_at), CONSTRAINT fk_workspace_project_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }
        if ($driver === 'pgsql') {
            return [
                'CREATE TABLE IF NOT EXISTS workspace_project_preferences (id BIGSERIAL PRIMARY KEY, merchant_id BIGINT NOT NULL REFERENCES merchants(id), project_type VARCHAR(20) NOT NULL, project_id BIGINT NOT NULL, custom_name VARCHAR(120) NULL, pinned SMALLINT NOT NULL DEFAULT 0, hidden SMALLINT NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL, UNIQUE (merchant_id, project_type, project_id))',
                'CREATE INDEX IF NOT EXISTS idx_workspace_project_sidebar ON workspace_project_preferences(merchant_id, hidden, pinned, updated_at)',
            ];
        }

        return [
            'CREATE TABLE IF NOT EXISTS workspace_project_preferences (id INTEGER PRIMARY KEY AUTOINCREMENT, merchant_id INTEGER NOT NULL, project_type TEXT NOT NULL, project_id INTEGER NOT NULL, custom_name TEXT NULL, pinned INTEGER NOT NULL DEFAULT 0, hidden INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (merchant_id, project_type, project_id), FOREIGN KEY (merchant_id) REFERENCES merchants(id))',
            'CREATE INDEX IF NOT EXISTS idx_workspace_project_sidebar ON workspace_project_preferences(merchant_id, hidden, pinned, updated_at)',
        ];
    }

    private function freeGenerationTableSql(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                'CREATE TABLE IF NOT EXISTS free_generation_jobs (job_id BIGINT UNSIGNED NOT NULL, prompt LONGTEXT NOT NULL, prompt_template_id BIGINT UNSIGNED NULL, prompt_template_code VARCHAR(100) NULL, prompt_template_name VARCHAR(160) NULL, prompt_template_content LONGTEXT NULL, subject_text LONGTEXT NULL, user_addition LONGTEXT NULL, reference_image_url TEXT NULL, reference_image_backup_url TEXT NULL, reference_images_json LONGTEXT NULL, reference_count INT NOT NULL DEFAULT 0, reference_fingerprints_json LONGTEXT NULL, outbound_reference_sha256 VARCHAR(64) NULL, output_preset VARCHAR(40) NOT NULL DEFAULT \'square_1_1\', output_width INT NOT NULL DEFAULT 1024, output_height INT NOT NULL DEFAULT 1024, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (job_id), CONSTRAINT fk_free_generation_job FOREIGN KEY (job_id) REFERENCES generation_jobs (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }
        if ($driver === 'pgsql') {
            return [
                'CREATE TABLE IF NOT EXISTS free_generation_jobs (job_id BIGINT PRIMARY KEY REFERENCES generation_jobs(id), prompt TEXT NOT NULL, prompt_template_id BIGINT NULL, prompt_template_code VARCHAR(100) NULL, prompt_template_name VARCHAR(160) NULL, prompt_template_content TEXT NULL, subject_text TEXT NULL, user_addition TEXT NULL, reference_image_url TEXT NULL, reference_image_backup_url TEXT NULL, reference_images_json TEXT NOT NULL DEFAULT \'[]\', reference_count INTEGER NOT NULL DEFAULT 0, reference_fingerprints_json TEXT NOT NULL DEFAULT \'[]\', outbound_reference_sha256 VARCHAR(64) NULL, output_preset VARCHAR(40) NOT NULL DEFAULT \'square_1_1\', output_width INTEGER NOT NULL DEFAULT 1024, output_height INTEGER NOT NULL DEFAULT 1024, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL)',
            ];
        }

        return [
            'CREATE TABLE IF NOT EXISTS free_generation_jobs (job_id INTEGER PRIMARY KEY, prompt TEXT NOT NULL, prompt_template_id INTEGER NULL, prompt_template_code TEXT NULL, prompt_template_name TEXT NULL, prompt_template_content TEXT NULL, subject_text TEXT NULL, user_addition TEXT NULL, reference_image_url TEXT NULL, reference_image_backup_url TEXT NULL, reference_images_json TEXT NOT NULL DEFAULT \'[]\', reference_count INTEGER NOT NULL DEFAULT 0, reference_fingerprints_json TEXT NOT NULL DEFAULT \'[]\', outbound_reference_sha256 TEXT NULL, output_preset TEXT NOT NULL DEFAULT \'square_1_1\', output_width INTEGER NOT NULL DEFAULT 1024, output_height INTEGER NOT NULL DEFAULT 1024, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, FOREIGN KEY (job_id) REFERENCES generation_jobs(id))',
        ];
    }

    private function videoGenerationTableSql(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                "CREATE TABLE IF NOT EXISTS video_generation_requests (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, job_id BIGINT UNSIGNED NOT NULL, merchant_id BIGINT UNSIGNED NOT NULL, sku_id BIGINT UNSIGNED NULL, source_asset_id BIGINT UNSIGNED NULL, reference_image_url TEXT NULL, reference_image_backup_url TEXT NULL, prompt LONGTEXT NOT NULL, motion_preset VARCHAR(40) NOT NULL DEFAULT 'product_showcase', ratio VARCHAR(10) NOT NULL DEFAULT '9:16', resolution VARCHAR(20) NOT NULL DEFAULT '720p', duration_seconds INT NOT NULL DEFAULT 5, generate_audio TINYINT NOT NULL DEFAULT 0, provider_request_id VARCHAR(190) NULL, provider_status VARCHAR(40) NOT NULL DEFAULT 'queued', provider_progress INT NOT NULL DEFAULT 0, submit_attempts INT NOT NULL DEFAULT 0, poll_attempts INT NOT NULL DEFAULT 0, next_poll_at DATETIME NULL, last_polled_at DATETIME NULL, video_url TEXT NULL, last_frame_url TEXT NULL, request_payload_json LONGTEXT NULL, result_payload_json LONGTEXT NULL, reservation_ledger_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_video_requests_job (job_id), KEY idx_video_requests_queue (provider_status, next_poll_at, id), KEY idx_video_requests_merchant (merchant_id, id), CONSTRAINT fk_video_requests_job FOREIGN KEY (job_id) REFERENCES generation_jobs (id), CONSTRAINT fk_video_requests_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id), CONSTRAINT fk_video_requests_sku FOREIGN KEY (sku_id) REFERENCES skus (id), CONSTRAINT fk_video_requests_source_asset FOREIGN KEY (source_asset_id) REFERENCES assets (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ];
        }
        if ($driver === 'pgsql') {
            return [
                "CREATE TABLE IF NOT EXISTS video_generation_requests (id BIGSERIAL PRIMARY KEY, job_id BIGINT NOT NULL UNIQUE REFERENCES generation_jobs(id), merchant_id BIGINT NOT NULL REFERENCES merchants(id), sku_id BIGINT NULL REFERENCES skus(id), source_asset_id BIGINT NULL REFERENCES assets(id), reference_image_url TEXT NULL, reference_image_backup_url TEXT NULL, prompt TEXT NOT NULL, motion_preset VARCHAR(40) NOT NULL DEFAULT 'product_showcase', ratio VARCHAR(10) NOT NULL DEFAULT '9:16', resolution VARCHAR(20) NOT NULL DEFAULT '720p', duration_seconds INTEGER NOT NULL DEFAULT 5, generate_audio SMALLINT NOT NULL DEFAULT 0, provider_request_id VARCHAR(190) NULL, provider_status VARCHAR(40) NOT NULL DEFAULT 'queued', provider_progress INTEGER NOT NULL DEFAULT 0, submit_attempts INTEGER NOT NULL DEFAULT 0, poll_attempts INTEGER NOT NULL DEFAULT 0, next_poll_at TIMESTAMP NULL, last_polled_at TIMESTAMP NULL, video_url TEXT NULL, last_frame_url TEXT NULL, request_payload_json TEXT NULL, result_payload_json TEXT NULL, reservation_ledger_id BIGINT NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL)",
                'CREATE INDEX IF NOT EXISTS idx_video_requests_queue ON video_generation_requests(provider_status, next_poll_at, id)',
                'CREATE INDEX IF NOT EXISTS idx_video_requests_merchant ON video_generation_requests(merchant_id, id)',
            ];
        }

        return [
            "CREATE TABLE IF NOT EXISTS video_generation_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, job_id INTEGER NOT NULL UNIQUE, merchant_id INTEGER NOT NULL, sku_id INTEGER NULL, source_asset_id INTEGER NULL, reference_image_url TEXT NULL, reference_image_backup_url TEXT NULL, prompt TEXT NOT NULL, motion_preset TEXT NOT NULL DEFAULT 'product_showcase', ratio TEXT NOT NULL DEFAULT '9:16', resolution TEXT NOT NULL DEFAULT '720p', duration_seconds INTEGER NOT NULL DEFAULT 5, generate_audio INTEGER NOT NULL DEFAULT 0, provider_request_id TEXT NULL, provider_status TEXT NOT NULL DEFAULT 'queued', provider_progress INTEGER NOT NULL DEFAULT 0, submit_attempts INTEGER NOT NULL DEFAULT 0, poll_attempts INTEGER NOT NULL DEFAULT 0, next_poll_at TEXT NULL, last_polled_at TEXT NULL, video_url TEXT NULL, last_frame_url TEXT NULL, request_payload_json TEXT NULL, result_payload_json TEXT NULL, reservation_ledger_id INTEGER NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, FOREIGN KEY (job_id) REFERENCES generation_jobs(id), FOREIGN KEY (merchant_id) REFERENCES merchants(id), FOREIGN KEY (sku_id) REFERENCES skus(id), FOREIGN KEY (source_asset_id) REFERENCES assets(id))",
            'CREATE INDEX IF NOT EXISTS idx_video_requests_queue ON video_generation_requests(provider_status, next_poll_at, id)',
            'CREATE INDEX IF NOT EXISTS idx_video_requests_merchant ON video_generation_requests(merchant_id, id)',
        ];
    }

    private function aiCapabilityTableSql(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                "CREATE TABLE IF NOT EXISTS ai_models (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, gateway VARCHAR(30) NOT NULL DEFAULT 'sub2api', model_id VARCHAR(190) NOT NULL, display_name VARCHAR(160) NOT NULL, provider VARCHAR(80) NULL, protocol VARCHAR(40) NOT NULL DEFAULT 'chat', capabilities_json LONGTEXT NOT NULL, input_modalities_json LONGTEXT NOT NULL, output_modalities_json LONGTEXT NOT NULL, limits_json LONGTEXT NOT NULL, adapter_config_json LONGTEXT NOT NULL, catalog_payload_json LONGTEXT NULL, user_selectable TINYINT NOT NULL DEFAULT 0, enabled TINYINT NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0, health_status VARCHAR(30) NOT NULL DEFAULT 'unknown', last_error TEXT NULL, last_tested_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_ai_models_gateway_id (gateway, model_id), KEY idx_ai_models_enabled (enabled, sort_order, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS ai_capability_routes (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, capability_code VARCHAR(60) NOT NULL, primary_model_id BIGINT UNSIGNED NULL, fallback_model_id BIGINT UNSIGNED NULL, routing_strategy VARCHAR(30) NOT NULL DEFAULT 'primary_fallback', route_config_json LONGTEXT NOT NULL, enabled TINYINT NOT NULL DEFAULT 1, updated_by_admin_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_ai_routes_capability (capability_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS ai_model_prices (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ai_model_id BIGINT UNSIGNED NOT NULL, capability_code VARCHAR(60) NOT NULL, unit_type VARCHAR(30) NOT NULL DEFAULT 'request', unit_value DECIMAL(14,4) NOT NULL DEFAULT 1, credits INT NOT NULL DEFAULT 0, config_json LONGTEXT NOT NULL, enabled TINYINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_ai_model_prices_lookup (ai_model_id, capability_code, enabled)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS ai_model_calls (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, merchant_id BIGINT UNSIGNED NULL, job_id BIGINT UNSIGNED NULL, capability_code VARCHAR(60) NOT NULL, ai_model_id BIGINT UNSIGNED NULL, request_id VARCHAR(190) NULL, status VARCHAR(30) NOT NULL, attempt_no INT NULL, route_role VARCHAR(30) NULL, protocol VARCHAR(40) NULL, http_status INT NULL, duration_ms INT NULL, input_units DECIMAL(16,4) NULL, output_units DECIMAL(16,4) NULL, estimated_cost DECIMAL(16,6) NULL, actual_cost DECIMAL(16,6) NULL, error_code VARCHAR(80) NULL, error_message TEXT NULL, prompt_hash CHAR(64) NULL, started_at DATETIME NULL, finished_at DATETIME NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_ai_model_calls_capability (capability_code, created_at), KEY idx_ai_model_calls_model (ai_model_id, status, created_at), KEY idx_ai_model_calls_job (job_id, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ];
        }
        if ($driver === 'pgsql') {
            return [
                "CREATE TABLE IF NOT EXISTS ai_models (id BIGSERIAL PRIMARY KEY, gateway VARCHAR(30) NOT NULL DEFAULT 'sub2api', model_id VARCHAR(190) NOT NULL, display_name VARCHAR(160) NOT NULL, provider VARCHAR(80) NULL, protocol VARCHAR(40) NOT NULL DEFAULT 'chat', capabilities_json TEXT NOT NULL DEFAULT '[]', input_modalities_json TEXT NOT NULL DEFAULT '[]', output_modalities_json TEXT NOT NULL DEFAULT '[]', limits_json TEXT NOT NULL DEFAULT '{}', adapter_config_json TEXT NOT NULL DEFAULT '{}', catalog_payload_json TEXT NULL, user_selectable SMALLINT NOT NULL DEFAULT 0, enabled SMALLINT NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0, health_status VARCHAR(30) NOT NULL DEFAULT 'unknown', last_error TEXT NULL, last_tested_at TIMESTAMP NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL, UNIQUE (gateway, model_id))",
                'CREATE INDEX IF NOT EXISTS idx_ai_models_enabled ON ai_models(enabled, sort_order, id)',
                "CREATE TABLE IF NOT EXISTS ai_capability_routes (id BIGSERIAL PRIMARY KEY, capability_code VARCHAR(60) NOT NULL UNIQUE, primary_model_id BIGINT NULL, fallback_model_id BIGINT NULL, routing_strategy VARCHAR(30) NOT NULL DEFAULT 'primary_fallback', route_config_json TEXT NOT NULL DEFAULT '{}', enabled SMALLINT NOT NULL DEFAULT 1, updated_by_admin_id BIGINT NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL)",
                "CREATE TABLE IF NOT EXISTS ai_model_prices (id BIGSERIAL PRIMARY KEY, ai_model_id BIGINT NOT NULL, capability_code VARCHAR(60) NOT NULL, unit_type VARCHAR(30) NOT NULL DEFAULT 'request', unit_value NUMERIC(14,4) NOT NULL DEFAULT 1, credits INTEGER NOT NULL DEFAULT 0, config_json TEXT NOT NULL DEFAULT '{}', enabled SMALLINT NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL)",
                'CREATE INDEX IF NOT EXISTS idx_ai_model_prices_lookup ON ai_model_prices(ai_model_id, capability_code, enabled)',
                'CREATE TABLE IF NOT EXISTS ai_model_calls (id BIGSERIAL PRIMARY KEY, merchant_id BIGINT NULL, job_id BIGINT NULL, capability_code VARCHAR(60) NOT NULL, ai_model_id BIGINT NULL, request_id VARCHAR(190) NULL, status VARCHAR(30) NOT NULL, attempt_no INTEGER NULL, route_role VARCHAR(30) NULL, protocol VARCHAR(40) NULL, http_status INTEGER NULL, duration_ms INTEGER NULL, input_units NUMERIC(16,4) NULL, output_units NUMERIC(16,4) NULL, estimated_cost NUMERIC(16,6) NULL, actual_cost NUMERIC(16,6) NULL, error_code VARCHAR(80) NULL, error_message TEXT NULL, prompt_hash CHAR(64) NULL, started_at TIMESTAMP NULL, finished_at TIMESTAMP NULL, created_at TIMESTAMP NOT NULL)',
                'CREATE INDEX IF NOT EXISTS idx_ai_model_calls_capability ON ai_model_calls(capability_code, created_at)',
                'CREATE INDEX IF NOT EXISTS idx_ai_model_calls_model ON ai_model_calls(ai_model_id, status, created_at)',
                'CREATE INDEX IF NOT EXISTS idx_ai_model_calls_job ON ai_model_calls(job_id, id)',
            ];
        }

        return [
            "CREATE TABLE IF NOT EXISTS ai_models (id INTEGER PRIMARY KEY AUTOINCREMENT, gateway TEXT NOT NULL DEFAULT 'sub2api', model_id TEXT NOT NULL, display_name TEXT NOT NULL, provider TEXT NULL, protocol TEXT NOT NULL DEFAULT 'chat', capabilities_json TEXT NOT NULL DEFAULT '[]', input_modalities_json TEXT NOT NULL DEFAULT '[]', output_modalities_json TEXT NOT NULL DEFAULT '[]', limits_json TEXT NOT NULL DEFAULT '{}', adapter_config_json TEXT NOT NULL DEFAULT '{}', catalog_payload_json TEXT NULL, user_selectable INTEGER NOT NULL DEFAULT 0, enabled INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0, health_status TEXT NOT NULL DEFAULT 'unknown', last_error TEXT NULL, last_tested_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (gateway, model_id))",
            'CREATE INDEX IF NOT EXISTS idx_ai_models_enabled ON ai_models(enabled, sort_order, id)',
            "CREATE TABLE IF NOT EXISTS ai_capability_routes (id INTEGER PRIMARY KEY AUTOINCREMENT, capability_code TEXT NOT NULL UNIQUE, primary_model_id INTEGER NULL, fallback_model_id INTEGER NULL, routing_strategy TEXT NOT NULL DEFAULT 'primary_fallback', route_config_json TEXT NOT NULL DEFAULT '{}', enabled INTEGER NOT NULL DEFAULT 1, updated_by_admin_id INTEGER NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS ai_model_prices (id INTEGER PRIMARY KEY AUTOINCREMENT, ai_model_id INTEGER NOT NULL, capability_code TEXT NOT NULL, unit_type TEXT NOT NULL DEFAULT 'request', unit_value NUMERIC NOT NULL DEFAULT 1, credits INTEGER NOT NULL DEFAULT 0, config_json TEXT NOT NULL DEFAULT '{}', enabled INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)",
            'CREATE INDEX IF NOT EXISTS idx_ai_model_prices_lookup ON ai_model_prices(ai_model_id, capability_code, enabled)',
            'CREATE TABLE IF NOT EXISTS ai_model_calls (id INTEGER PRIMARY KEY AUTOINCREMENT, merchant_id INTEGER NULL, job_id INTEGER NULL, capability_code TEXT NOT NULL, ai_model_id INTEGER NULL, request_id TEXT NULL, status TEXT NOT NULL, attempt_no INTEGER NULL, route_role TEXT NULL, protocol TEXT NULL, http_status INTEGER NULL, duration_ms INTEGER NULL, input_units NUMERIC NULL, output_units NUMERIC NULL, estimated_cost NUMERIC NULL, actual_cost NUMERIC NULL, error_code TEXT NULL, error_message TEXT NULL, prompt_hash TEXT NULL, started_at TEXT NULL, finished_at TEXT NULL, created_at TEXT NOT NULL)',
            'CREATE INDEX IF NOT EXISTS idx_ai_model_calls_capability ON ai_model_calls(capability_code, created_at)',
            'CREATE INDEX IF NOT EXISTS idx_ai_model_calls_model ON ai_model_calls(ai_model_id, status, created_at)',
            'CREATE INDEX IF NOT EXISTS idx_ai_model_calls_job ON ai_model_calls(job_id, id)',
        ];
    }

    private function mediaWorkflowTemplateTableSql(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                "CREATE TABLE IF NOT EXISTS media_workflow_templates (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, code VARCHAR(80) NOT NULL, name VARCHAR(120) NOT NULL, engine VARCHAR(30) NOT NULL DEFAULT 'comfyui', media_type VARCHAR(20) NOT NULL DEFAULT 'video', version INT NOT NULL DEFAULT 1, workflow_json LONGTEXT NOT NULL, input_map_json LONGTEXT NOT NULL, output_map_json LONGTEXT NOT NULL, workflow_hash CHAR(64) NOT NULL, enabled TINYINT NOT NULL DEFAULT 0, last_test_status VARCHAR(30) NOT NULL DEFAULT 'untested', last_test_error TEXT NULL, last_tested_at DATETIME NULL, updated_by_admin_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_media_workflow_code (code), KEY idx_media_workflow_enabled (enabled, media_type, code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ];
        }
        if ($driver === 'pgsql') {
            return [
                "CREATE TABLE IF NOT EXISTS media_workflow_templates (id BIGSERIAL PRIMARY KEY, code VARCHAR(80) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL, engine VARCHAR(30) NOT NULL DEFAULT 'comfyui', media_type VARCHAR(20) NOT NULL DEFAULT 'video', version INTEGER NOT NULL DEFAULT 1, workflow_json TEXT NOT NULL, input_map_json TEXT NOT NULL DEFAULT '{}', output_map_json TEXT NOT NULL DEFAULT '{}', workflow_hash CHAR(64) NOT NULL, enabled SMALLINT NOT NULL DEFAULT 0, last_test_status VARCHAR(30) NOT NULL DEFAULT 'untested', last_test_error TEXT NULL, last_tested_at TIMESTAMP NULL, updated_by_admin_id BIGINT NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL)",
                'CREATE INDEX IF NOT EXISTS idx_media_workflow_enabled ON media_workflow_templates(enabled, media_type, code)',
            ];
        }

        return [
            "CREATE TABLE IF NOT EXISTS media_workflow_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, engine TEXT NOT NULL DEFAULT 'comfyui', media_type TEXT NOT NULL DEFAULT 'video', version INTEGER NOT NULL DEFAULT 1, workflow_json TEXT NOT NULL, input_map_json TEXT NOT NULL DEFAULT '{}', output_map_json TEXT NOT NULL DEFAULT '{}', workflow_hash TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 0, last_test_status TEXT NOT NULL DEFAULT 'untested', last_test_error TEXT NULL, last_tested_at TEXT NULL, updated_by_admin_id INTEGER NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)",
            'CREATE INDEX IF NOT EXISTS idx_media_workflow_enabled ON media_workflow_templates(enabled, media_type, code)',
        ];
    }

    private function visionTaskTableSql(string $driver): array
    {
        if ($driver === 'mysql') {
            return [
                "CREATE TABLE IF NOT EXISTS vision_tasks (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, task_no VARCHAR(60) NOT NULL, merchant_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NULL, sku_id BIGINT UNSIGNED NULL, task_type VARCHAR(40) NOT NULL DEFAULT 'product_profile', priority INT NOT NULL DEFAULT 50, status VARCHAR(30) NOT NULL DEFAULT 'queued', progress_percent INT NOT NULL DEFAULT 0, progress_stage VARCHAR(40) NOT NULL DEFAULT 'queued', progress_message VARCHAR(255) NULL, image_url TEXT NOT NULL, image_backup_url TEXT NULL, temporary_input TINYINT NOT NULL DEFAULT 0, image_hash CHAR(64) NOT NULL, request_hash CHAR(64) NOT NULL, context_json LONGTEXT NOT NULL, result_json LONGTEXT NULL, diagnostic_json LONGTEXT NULL, model VARCHAR(190) NULL, fallback_model VARCHAR(190) NULL, pricing_snapshot_json LONGTEXT NOT NULL, credits INT NOT NULL DEFAULT 0, ledger_id BIGINT UNSIGNED NULL, billing_reference VARCHAR(100) NULL, request_id VARCHAR(190) NULL, attempts INT NOT NULL DEFAULT 0, next_retry_at DATETIME NULL, worker_slot INT NULL, error_message TEXT NULL, started_at DATETIME NULL, completed_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_vision_tasks_no (task_no), KEY idx_vision_tasks_queue (status, priority, next_retry_at, id), KEY idx_vision_tasks_merchant (merchant_id, id), KEY idx_vision_tasks_request (request_hash, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS vision_result_cache (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, request_hash CHAR(64) NOT NULL, capability_code VARCHAR(60) NOT NULL, image_hash CHAR(64) NOT NULL, model VARCHAR(190) NOT NULL, result_json LONGTEXT NOT NULL, request_id VARCHAR(190) NULL, hit_count INT NOT NULL DEFAULT 0, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_vision_cache_request (request_hash), KEY idx_vision_cache_expiry (expires_at), KEY idx_vision_cache_image (image_hash, capability_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ];
        }
        if ($driver === 'pgsql') {
            return [
                "CREATE TABLE IF NOT EXISTS vision_tasks (id BIGSERIAL PRIMARY KEY, task_no VARCHAR(60) NOT NULL UNIQUE, merchant_id BIGINT NOT NULL, user_id BIGINT NULL, sku_id BIGINT NULL, task_type VARCHAR(40) NOT NULL DEFAULT 'product_profile', priority INTEGER NOT NULL DEFAULT 50, status VARCHAR(30) NOT NULL DEFAULT 'queued', progress_percent INTEGER NOT NULL DEFAULT 0, progress_stage VARCHAR(40) NOT NULL DEFAULT 'queued', progress_message VARCHAR(255) NULL, image_url TEXT NOT NULL, image_backup_url TEXT NULL, temporary_input SMALLINT NOT NULL DEFAULT 0, image_hash CHAR(64) NOT NULL, request_hash CHAR(64) NOT NULL, context_json TEXT NOT NULL DEFAULT '{}', result_json TEXT NULL, diagnostic_json TEXT NOT NULL DEFAULT '{}', model VARCHAR(190) NULL, fallback_model VARCHAR(190) NULL, pricing_snapshot_json TEXT NOT NULL DEFAULT '{}', credits INTEGER NOT NULL DEFAULT 0, ledger_id BIGINT NULL, billing_reference VARCHAR(100) NULL, request_id VARCHAR(190) NULL, attempts INTEGER NOT NULL DEFAULT 0, next_retry_at TIMESTAMP NULL, worker_slot INTEGER NULL, error_message TEXT NULL, started_at TIMESTAMP NULL, completed_at TIMESTAMP NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL)",
                'CREATE INDEX IF NOT EXISTS idx_vision_tasks_queue ON vision_tasks(status, priority, next_retry_at, id)',
                'CREATE INDEX IF NOT EXISTS idx_vision_tasks_merchant ON vision_tasks(merchant_id, id)',
                'CREATE INDEX IF NOT EXISTS idx_vision_tasks_request ON vision_tasks(request_hash, status)',
                "CREATE TABLE IF NOT EXISTS vision_result_cache (id BIGSERIAL PRIMARY KEY, request_hash CHAR(64) NOT NULL UNIQUE, capability_code VARCHAR(60) NOT NULL, image_hash CHAR(64) NOT NULL, model VARCHAR(190) NOT NULL, result_json TEXT NOT NULL DEFAULT '{}', request_id VARCHAR(190) NULL, hit_count INTEGER NOT NULL DEFAULT 0, expires_at TIMESTAMP NOT NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL)",
                'CREATE INDEX IF NOT EXISTS idx_vision_cache_expiry ON vision_result_cache(expires_at)',
                'CREATE INDEX IF NOT EXISTS idx_vision_cache_image ON vision_result_cache(image_hash, capability_code)',
            ];
        }

        return [
            "CREATE TABLE IF NOT EXISTS vision_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, task_no TEXT NOT NULL UNIQUE, merchant_id INTEGER NOT NULL, user_id INTEGER NULL, sku_id INTEGER NULL, task_type TEXT NOT NULL DEFAULT 'product_profile', priority INTEGER NOT NULL DEFAULT 50, status TEXT NOT NULL DEFAULT 'queued', progress_percent INTEGER NOT NULL DEFAULT 0, progress_stage TEXT NOT NULL DEFAULT 'queued', progress_message TEXT NULL, image_url TEXT NOT NULL, image_backup_url TEXT NULL, temporary_input INTEGER NOT NULL DEFAULT 0, image_hash TEXT NOT NULL, request_hash TEXT NOT NULL, context_json TEXT NOT NULL DEFAULT '{}', result_json TEXT NULL, diagnostic_json TEXT NOT NULL DEFAULT '{}', model TEXT NULL, fallback_model TEXT NULL, pricing_snapshot_json TEXT NOT NULL DEFAULT '{}', credits INTEGER NOT NULL DEFAULT 0, ledger_id INTEGER NULL, billing_reference TEXT NULL, request_id TEXT NULL, attempts INTEGER NOT NULL DEFAULT 0, next_retry_at TEXT NULL, worker_slot INTEGER NULL, error_message TEXT NULL, started_at TEXT NULL, completed_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)",
            'CREATE INDEX IF NOT EXISTS idx_vision_tasks_queue ON vision_tasks(status, priority, next_retry_at, id)',
            'CREATE INDEX IF NOT EXISTS idx_vision_tasks_merchant ON vision_tasks(merchant_id, id)',
            'CREATE INDEX IF NOT EXISTS idx_vision_tasks_request ON vision_tasks(request_hash, status)',
            "CREATE TABLE IF NOT EXISTS vision_result_cache (id INTEGER PRIMARY KEY AUTOINCREMENT, request_hash TEXT NOT NULL UNIQUE, capability_code TEXT NOT NULL, image_hash TEXT NOT NULL, model TEXT NOT NULL, result_json TEXT NOT NULL DEFAULT '{}', request_id TEXT NULL, hit_count INTEGER NOT NULL DEFAULT 0, expires_at TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)",
            'CREATE INDEX IF NOT EXISTS idx_vision_cache_expiry ON vision_result_cache(expires_at)',
            'CREATE INDEX IF NOT EXISTS idx_vision_cache_image ON vision_result_cache(image_hash, capability_code)',
        ];
    }

}
