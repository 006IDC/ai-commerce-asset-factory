SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS admin_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(40) NOT NULL DEFAULT 'super_admin',
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_admin_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS merchants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(80) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    plan_code VARCHAR(40) NOT NULL DEFAULT 'trial',
    credit_balance INT NOT NULL DEFAULT 0,
    owner_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_merchants_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscription_plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    duration_days INT NOT NULL,
    price_credits INT NOT NULL DEFAULT 0,
    tier_level INT NOT NULL DEFAULT 1,
    audience_label VARCHAR(120) NULL,
    generation_unlimited TINYINT NOT NULL DEFAULT 0,
    recognition_unlimited TINYINT NOT NULL DEFAULT 0,
    daily_generation_limit INT NOT NULL DEFAULT 0,
    sku_limit INT NOT NULL DEFAULT 0,
    batch_image_limit INT NOT NULL DEFAULT 0,
    concurrent_job_limit INT NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    sort_order INT NOT NULL DEFAULT 0,
    created_by_admin_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_subscription_plans_code (code),
    KEY idx_subscription_plans_status (status, sort_order),
    CONSTRAINT fk_subscription_plans_admin FOREIGN KEY (created_by_admin_id) REFERENCES admin_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS merchant_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    merchant_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    plan_code VARCHAR(40) NOT NULL,
    plan_name VARCHAR(120) NOT NULL,
    price_credits INT NOT NULL,
    tier_level INT NOT NULL DEFAULT 1,
    audience_label VARCHAR(120) NULL,
    starts_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    generation_unlimited TINYINT NOT NULL DEFAULT 0,
    recognition_unlimited TINYINT NOT NULL DEFAULT 0,
    daily_generation_limit INT NOT NULL DEFAULT 0,
    sku_limit INT NOT NULL DEFAULT 0,
    batch_image_limit INT NOT NULL DEFAULT 0,
    concurrent_job_limit INT NOT NULL DEFAULT 0,
    purchased_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_merchant_subscriptions_active (merchant_id, status, expires_at),
    CONSTRAINT fk_merchant_subscriptions_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id),
    CONSTRAINT fk_merchant_subscriptions_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS merchant_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    merchant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(40) NOT NULL DEFAULT 'owner',
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    last_login_ip VARCHAR(64) NULL,
    preferred_generation_platform VARCHAR(40) NULL,
    preferred_generation_language VARCHAR(20) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_merchant_users_email (email),
    KEY idx_merchant_users_merchant (merchant_id),
    CONSTRAINT fk_merchant_users_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS skus (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    merchant_id BIGINT UNSIGNED NOT NULL,
    sku_code VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    category VARCHAR(100) NOT NULL,
    selling_points LONGTEXT NOT NULL,
    creative_brief TEXT NULL,
    launch_targets_json LONGTEXT NULL,
    launch_direction TEXT NULL,
    product_description LONGTEXT NULL,
    preferred_image_ratio VARCHAR(20) NULL,
    preferred_image_model VARCHAR(160) NULL,
    search_terms LONGTEXT NULL,
    report_materials TEXT NULL,
    report_appearance TEXT NULL,
    report_target_audience TEXT NULL,
    report_use_scenarios LONGTEXT NULL,
    report_core_functions LONGTEXT NULL,
    report_uncertain_points LONGTEXT NULL,
    report_prohibited_claims LONGTEXT NULL,
    report_brand_notes TEXT NULL,
    foundation_source VARCHAR(30) NULL,
    report_market_research_json LONGTEXT NULL,
    report_market_query TEXT NULL,
    report_market_updated_at DATETIME NULL,
    report_status VARCHAR(30) NOT NULL DEFAULT 'draft',
    report_source_project_id BIGINT UNSIGNED NULL,
    report_updated_at DATETIME NULL,
    source_image_path TEXT NULL,
    source_image_backup_path TEXT NULL,
    reference_images_json LONGTEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_skus_merchant_code (merchant_id, sku_code),
    KEY idx_skus_merchant (merchant_id),
    CONSTRAINT fk_skus_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS detail_page_projects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    merchant_id BIGINT UNSIGNED NOT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    sku_id BIGINT UNSIGNED NULL,
    product_name VARCHAR(160) NOT NULL,
    category VARCHAR(100) NOT NULL,
    materials TEXT NULL,
    appearance TEXT NULL,
    target_audience TEXT NULL,
    use_scenarios LONGTEXT NOT NULL,
    selling_points LONGTEXT NOT NULL,
    core_functions LONGTEXT NOT NULL,
    uncertain_points LONGTEXT NOT NULL,
    prohibited_claims LONGTEXT NOT NULL,
    product_description LONGTEXT NULL,
    brand_notes TEXT NULL,
    creative_brief TEXT NULL,
    screen_plan LONGTEXT NOT NULL,
    source_image_url TEXT NOT NULL,
    source_image_backup_url TEXT NULL,
    platform VARCHAR(40) NOT NULL,
    language VARCHAR(20) NOT NULL DEFAULT 'zh-CN',
    market_research_json LONGTEXT NULL,
    market_research_query TEXT NULL,
    market_research_updated_at DATETIME NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_detail_projects_merchant (merchant_id, id),
    KEY idx_detail_projects_sku (sku_id, id),
    CONSTRAINT fk_detail_projects_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id),
    CONSTRAINT fk_detail_projects_user FOREIGN KEY (created_by_user_id) REFERENCES merchant_users (id),
    CONSTRAINT fk_detail_projects_sku FOREIGN KEY (sku_id) REFERENCES skus (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS market_research_cache (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    merchant_id BIGINT UNSIGNED NOT NULL,
    sku_id BIGINT UNSIGNED NULL,
    query_hash CHAR(64) NOT NULL,
    query_text TEXT NOT NULL,
    result_json LONGTEXT NOT NULL,
    source_count INT NOT NULL DEFAULT 0,
    provider_credits INT NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_market_research_merchant_query (merchant_id, query_hash),
    KEY idx_market_research_expiry (expires_at),
    CONSTRAINT fk_market_research_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id),
    CONSTRAINT fk_market_research_sku FOREIGN KEY (sku_id) REFERENCES skus (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS detail_page_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    merchant_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(20) NOT NULL,
    content LONGTEXT NOT NULL,
    request_id VARCHAR(190) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_detail_messages_project (project_id, id),
    CONSTRAINT fk_detail_messages_project FOREIGN KEY (project_id) REFERENCES detail_page_projects (id),
    CONSTRAINT fk_detail_messages_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS generation_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    merchant_id BIGINT UNSIGNED NOT NULL,
    sku_id BIGINT UNSIGNED NULL,
    detail_page_project_id BIGINT UNSIGNED NULL,
    bundle_no VARCHAR(80) NULL,
    bundle_part VARCHAR(20) NULL,
    job_no VARCHAR(80) NOT NULL,
    client_submission_id VARCHAR(64) NULL,
    submission_payload_hash VARCHAR(64) NULL,
    output_kind VARCHAR(20) NOT NULL DEFAULT 'image',
    platform VARCHAR(40) NOT NULL,
    language VARCHAR(20) NOT NULL DEFAULT 'zh-CN',
    asset_types LONGTEXT NOT NULL,
    custom_material_requests LONGTEXT NULL,
    task_brief TEXT NULL,
    task_context_json LONGTEXT NULL,
    submitted_by_user_id BIGINT UNSIGNED NULL,
    submitted_ip VARCHAR(64) NULL,
    safety_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    safety_attempts INT NOT NULL DEFAULT 0,
    safety_next_retry_at DATETIME NULL,
    safety_checked_at DATETIME NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'pending_gateway',
    estimated_credits INT NOT NULL DEFAULT 0,
    actual_credits INT NOT NULL DEFAULT 0,
    progress_current INT NOT NULL DEFAULT 0,
    progress_total INT NOT NULL DEFAULT 0,
    progress_stage VARCHAR(40) NOT NULL DEFAULT 'queued',
    progress_message TEXT NULL,
    model VARCHAR(120) NULL,
    requested_model_id BIGINT UNSIGNED NULL,
    resolved_ai_model_id BIGINT UNSIGNED NULL,
    route_snapshot_json LONGTEXT NULL,
    pricing_snapshot_json LONGTEXT NULL,
    new_api_request_id VARCHAR(190) NULL,
    error_message TEXT NULL,
    merchant_hidden_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_generation_jobs_no (job_no),
    UNIQUE KEY uk_generation_jobs_submission (merchant_id, client_submission_id),
    KEY idx_jobs_merchant_status (merchant_id, status),
    KEY idx_generation_jobs_sku (sku_id),
    KEY idx_generation_jobs_detail_project (detail_page_project_id),
    KEY idx_generation_jobs_bundle (bundle_no, bundle_part),
    KEY idx_generation_jobs_safety (status, safety_status, safety_next_retry_at, id),
    CONSTRAINT fk_generation_jobs_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id),
    CONSTRAINT fk_generation_jobs_submitter FOREIGN KEY (submitted_by_user_id) REFERENCES merchant_users (id),
    CONSTRAINT fk_generation_jobs_sku FOREIGN KEY (sku_id) REFERENCES skus (id),
    CONSTRAINT fk_generation_jobs_detail_project FOREIGN KEY (detail_page_project_id) REFERENCES detail_page_projects (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS generation_job_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    merchant_id BIGINT UNSIGNED NOT NULL,
    position INT NOT NULL,
    asset_type VARCHAR(60) NOT NULL,
    occurrence INT NOT NULL DEFAULT 1,
    type_total INT NOT NULL DEFAULT 1,
    estimated_credits INT NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'queued',
    attempts INT NOT NULL DEFAULT 0,
    manual_retry_count INT NOT NULL DEFAULT 0,
    next_retry_at DATETIME NULL,
    last_retry_reason TEXT NULL,
    worker_slot INT NULL,
    reservation_ledger_id BIGINT UNSIGNED NULL,
    asset_id BIGINT UNSIGNED NULL,
    request_id VARCHAR(190) NULL,
    error_message TEXT NULL,
    stage_timestamps_json LONGTEXT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_generation_items_position (job_id, position),
    KEY idx_generation_items_queue (status, id),
    KEY idx_generation_items_retry (status, next_retry_at, id),
    KEY idx_generation_items_job (job_id, status, id),
    KEY idx_generation_items_merchant (merchant_id, status, id),
    CONSTRAINT fk_generation_items_job FOREIGN KEY (job_id) REFERENCES generation_jobs (id),
    CONSTRAINT fk_generation_items_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    merchant_id BIGINT UNSIGNED NOT NULL,
    sku_id BIGINT UNSIGNED NULL,
    job_id BIGINT UNSIGNED NOT NULL,
    generation_item_id BIGINT UNSIGNED NULL,
    asset_type VARCHAR(60) NOT NULL,
    media_type VARCHAR(20) NOT NULL DEFAULT 'image',
    mime_type VARCHAR(120) NULL,
    duration_seconds INT NULL,
    poster_url TEXT NULL,
    width INT NULL,
    height INT NULL,
    file_url TEXT NOT NULL,
    backup_file_url TEXT NULL,
    score INT NULL,
    is_favorite TINYINT NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    deleted_at DATETIME NULL,
    purged_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_assets_merchant_status (merchant_id, status),
    KEY idx_assets_trash_cleanup (status, deleted_at, id),
    KEY idx_assets_sku (sku_id),
    KEY idx_assets_job (job_id),
    UNIQUE KEY uk_assets_generation_item (generation_item_id),
    CONSTRAINT fk_assets_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id),
    CONSTRAINT fk_assets_sku FOREIGN KEY (sku_id) REFERENCES skus (id),
    CONSTRAINT fk_assets_job FOREIGN KEY (job_id) REFERENCES generation_jobs (id),
    CONSTRAINT fk_assets_generation_item FOREIGN KEY (generation_item_id) REFERENCES generation_job_items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS video_generation_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    merchant_id BIGINT UNSIGNED NOT NULL,
    sku_id BIGINT UNSIGNED NULL,
    source_asset_id BIGINT UNSIGNED NULL,
    reference_image_url TEXT NULL,
    reference_image_backup_url TEXT NULL,
    prompt LONGTEXT NOT NULL,
    motion_preset VARCHAR(40) NOT NULL DEFAULT 'product_showcase',
    ratio VARCHAR(10) NOT NULL DEFAULT '9:16',
    resolution VARCHAR(20) NOT NULL DEFAULT '720p',
    duration_seconds INT NOT NULL DEFAULT 5,
    generate_audio TINYINT NOT NULL DEFAULT 0,
    provider_request_id VARCHAR(190) NULL,
    provider_status VARCHAR(40) NOT NULL DEFAULT 'queued',
    provider_progress INT NOT NULL DEFAULT 0,
    submit_attempts INT NOT NULL DEFAULT 0,
    poll_attempts INT NOT NULL DEFAULT 0,
    next_poll_at DATETIME NULL,
    last_polled_at DATETIME NULL,
    video_url TEXT NULL,
    last_frame_url TEXT NULL,
    request_payload_json LONGTEXT NULL,
    result_payload_json LONGTEXT NULL,
    reservation_ledger_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_video_requests_job (job_id),
    KEY idx_video_requests_queue (provider_status, next_poll_at, id),
    KEY idx_video_requests_merchant (merchant_id, id),
    CONSTRAINT fk_video_requests_job FOREIGN KEY (job_id) REFERENCES generation_jobs (id),
    CONSTRAINT fk_video_requests_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id),
    CONSTRAINT fk_video_requests_sku FOREIGN KEY (sku_id) REFERENCES skus (id),
    CONSTRAINT fk_video_requests_source_asset FOREIGN KEY (source_asset_id) REFERENCES assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canvas_edits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    source_asset_id BIGINT UNSIGNED NOT NULL,
    mode VARCHAR(20) NOT NULL DEFAULT 'global',
    preset VARCHAR(40) NOT NULL DEFAULT 'custom',
    instruction LONGTEXT NOT NULL,
    mask_file TEXT NULL,
    output_preset VARCHAR(40) NOT NULL DEFAULT 'square_1_1',
    output_width INT NOT NULL DEFAULT 1024,
    output_height INT NOT NULL DEFAULT 1024,
    resize_mode VARCHAR(20) NOT NULL DEFAULT 'extend',
    operation VARCHAR(20) NOT NULL DEFAULT 'ai_edit',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_canvas_edits_job (job_id),
    KEY idx_canvas_edits_source (source_asset_id),
    CONSTRAINT fk_canvas_edits_job FOREIGN KEY (job_id) REFERENCES generation_jobs (id),
    CONSTRAINT fk_canvas_edits_source FOREIGN KEY (source_asset_id) REFERENCES assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canvas_workspaces (
    merchant_id BIGINT UNSIGNED NOT NULL,
    graph_json LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (merchant_id),
    CONSTRAINT fk_canvas_workspaces_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS creative_projects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    merchant_id BIGINT UNSIGNED NOT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    sku_id BIGINT UNSIGNED NULL,
    title VARCHAR(120) NOT NULL,
    project_type VARCHAR(20) NOT NULL DEFAULT 'image',
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    cover_asset_id BIGINT UNSIGNED NULL,
    document_json LONGTEXT NOT NULL,
    document_hash CHAR(64) NOT NULL,
    document_version INT NOT NULL DEFAULT 1,
    current_revision_no INT NOT NULL DEFAULT 1,
    legacy_canvas_migrated TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_creative_projects_merchant (merchant_id, status, updated_at, id),
    KEY idx_creative_projects_sku (sku_id, status, id),
    CONSTRAINT fk_creative_projects_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id),
    CONSTRAINT fk_creative_projects_user FOREIGN KEY (created_by_user_id) REFERENCES merchant_users(id) ON DELETE SET NULL,
    CONSTRAINT fk_creative_projects_sku FOREIGN KEY (sku_id) REFERENCES skus(id) ON DELETE SET NULL,
    CONSTRAINT fk_creative_projects_cover FOREIGN KEY (cover_asset_id) REFERENCES assets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS creative_project_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    revision_no INT NOT NULL,
    schema_version INT NOT NULL DEFAULT 2,
    source VARCHAR(20) NOT NULL DEFAULT 'auto',
    document_hash CHAR(64) NOT NULL,
    document_json LONGTEXT NOT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_creative_project_revision (project_id, revision_no),
    KEY idx_creative_project_revisions_created (project_id, created_at, id),
    CONSTRAINT fk_creative_revisions_project FOREIGN KEY (project_id) REFERENCES creative_projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_creative_revisions_user FOREIGN KEY (created_by_user_id) REFERENCES merchant_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS creative_project_assets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    asset_id BIGINT UNSIGNED NOT NULL,
    node_uuid VARCHAR(64) NOT NULL,
    role VARCHAR(30) NOT NULL DEFAULT 'source',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_creative_project_node (project_id, node_uuid),
    KEY idx_creative_project_assets_asset (asset_id, project_id),
    CONSTRAINT fk_creative_project_assets_project FOREIGN KEY (project_id) REFERENCES creative_projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_creative_project_assets_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS creative_project_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    node_uuid VARCHAR(64) NULL,
    job_id BIGINT UNSIGNED NOT NULL,
    run_type VARCHAR(30) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'queued',
    input_snapshot_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_creative_project_run_job (job_id),
    KEY idx_creative_project_runs_project (project_id, status, id),
    CONSTRAINT fk_creative_project_runs_project FOREIGN KEY (project_id) REFERENCES creative_projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_creative_project_runs_job FOREIGN KEY (job_id) REFERENCES generation_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_project_preferences (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    merchant_id BIGINT UNSIGNED NOT NULL,
    project_type VARCHAR(20) NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    custom_name VARCHAR(120) NULL,
    pinned TINYINT NOT NULL DEFAULT 0,
    hidden TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_workspace_project_preference (merchant_id, project_type, project_id),
    KEY idx_workspace_project_sidebar (merchant_id, hidden, pinned, updated_at),
    CONSTRAINT fk_workspace_project_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS free_generation_jobs (
    job_id BIGINT UNSIGNED NOT NULL,
    prompt LONGTEXT NOT NULL,
    prompt_template_id BIGINT UNSIGNED NULL,
    prompt_template_code VARCHAR(100) NULL,
    prompt_template_name VARCHAR(160) NULL,
    prompt_template_content LONGTEXT NULL,
    subject_text LONGTEXT NULL,
    user_addition LONGTEXT NULL,
    reference_image_url TEXT NULL,
    reference_image_backup_url TEXT NULL,
    reference_images_json LONGTEXT NULL,
    reference_count INT NOT NULL DEFAULT 0,
    reference_fingerprints_json LONGTEXT NULL,
    outbound_reference_sha256 VARCHAR(64) NULL,
    output_preset VARCHAR(40) NOT NULL DEFAULT 'square_1_1',
    output_width INT NOT NULL DEFAULT 1024,
    output_height INT NOT NULL DEFAULT 1024,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (job_id),
    CONSTRAINT fk_free_generation_job FOREIGN KEY (job_id) REFERENCES generation_jobs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credit_ledger (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    merchant_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(40) NOT NULL,
    amount INT NOT NULL,
    balance_after INT NOT NULL,
    source VARCHAR(60) NOT NULL,
    reference_type VARCHAR(60) NULL,
    reference_id VARCHAR(190) NULL,
    new_api_request_id VARCHAR(190) NULL,
    operator_admin_id BIGINT UNSIGNED NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ledger_merchant_created (merchant_id, created_at),
    KEY idx_credit_ledger_admin (operator_admin_id),
    CONSTRAINT fk_credit_ledger_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id),
    CONSTRAINT fk_credit_ledger_admin FOREIGN KEY (operator_admin_id) REFERENCES admin_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usage_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    merchant_id BIGINT UNSIGNED NOT NULL,
    job_id BIGINT UNSIGNED NULL,
    new_api_request_id VARCHAR(190) NULL,
    model VARCHAR(120) NULL,
    provider VARCHAR(80) NULL,
    business_credits INT NOT NULL DEFAULT 0,
    gateway_cost DECIMAL(14,6) NOT NULL DEFAULT 0,
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    occurred_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_usage_request_id (new_api_request_id),
    KEY idx_usage_merchant_created (merchant_id, created_at),
    KEY idx_usage_job (job_id),
    CONSTRAINT fk_usage_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id),
    CONSTRAINT fk_usage_job FOREIGN KEY (job_id) REFERENCES generation_jobs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NOT NULL,
    is_secret TINYINT NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (setting_key),
    KEY idx_system_settings_updated (updated_at),
    KEY idx_system_settings_admin (updated_by),
    CONSTRAINT fk_system_settings_admin FOREIGN KEY (updated_by) REFERENCES admin_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storage_migrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_type VARCHAR(30) NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    merchant_id BIGINT UNSIGNED NOT NULL,
    source_url TEXT NOT NULL,
    target_url TEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_storage_migration_source (source_type, source_id),
    KEY idx_storage_migration_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_verification_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    purpose VARCHAR(40) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    code_hash VARCHAR(64) NOT NULL,
    attempts SMALLINT NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    requested_ip VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_email_codes_lookup (email, purpose, created_at),
    KEY idx_email_codes_ip (requested_ip, created_at),
    KEY idx_email_codes_user (user_id),
    CONSTRAINT fk_email_codes_user FOREIGN KEY (user_id) REFERENCES merchant_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS redemption_card_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_no VARCHAR(80) NOT NULL,
    label VARCHAR(120) NOT NULL,
    credits INT NOT NULL,
    total_count INT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    expires_at DATETIME NULL,
    created_by_admin_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_card_batches_no (batch_no),
    KEY idx_card_batches_created (created_at),
    KEY idx_card_batches_admin (created_by_admin_id),
    CONSTRAINT fk_card_batches_admin FOREIGN KEY (created_by_admin_id) REFERENCES admin_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS redemption_cards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    code_hash VARCHAR(64) NOT NULL,
    code_ciphertext TEXT NOT NULL,
    code_last4 VARCHAR(4) NOT NULL,
    credits INT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'unused',
    redeemed_by_merchant_id BIGINT UNSIGNED NULL,
    redeemed_by_user_id BIGINT UNSIGNED NULL,
    redeemed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_redemption_cards_hash (code_hash),
    KEY idx_cards_batch_status (batch_id, status),
    KEY idx_cards_redeemed_merchant (redeemed_by_merchant_id, redeemed_at),
    KEY idx_cards_redeemed_user (redeemed_by_user_id),
    CONSTRAINT fk_cards_batch FOREIGN KEY (batch_id) REFERENCES redemption_card_batches (id),
    CONSTRAINT fk_cards_merchant FOREIGN KEY (redeemed_by_merchant_id) REFERENCES merchants (id),
    CONSTRAINT fk_cards_user FOREIGN KEY (redeemed_by_user_id) REFERENCES merchant_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_moderation_checks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    merchant_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    content_type VARCHAR(20) NOT NULL,
    content_hash VARCHAR(64) NOT NULL,
    source VARCHAR(80) NOT NULL,
    status VARCHAR(20) NOT NULL,
    categories TEXT NOT NULL,
    reason TEXT NULL,
    model VARCHAR(120) NULL,
    request_id VARCHAR(190) NULL,
    cache_hit_count INT NOT NULL DEFAULT 0,
    last_cache_hit_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_moderation_checks_hash (content_hash, status),
    CONSTRAINT fk_moderation_checks_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id),
    CONSTRAINT fk_moderation_checks_user FOREIGN KEY (user_id) REFERENCES merchant_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_violations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    merchant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    content_type VARCHAR(20) NOT NULL,
    content_hash VARCHAR(64) NOT NULL,
    source VARCHAR(80) NOT NULL,
    categories TEXT NOT NULL,
    reason TEXT NULL,
    content_excerpt TEXT NULL,
    penalty_credits INT NOT NULL DEFAULT 0,
    warning_email_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    warning_error TEXT NULL,
    ip_address VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_content_violation_unique (merchant_id, content_type, content_hash),
    KEY idx_content_violations_created (created_at),
    CONSTRAINT fk_content_violations_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id),
    CONSTRAINT fk_content_violations_user FOREIGN KEY (user_id) REFERENCES merchant_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_throttle_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope VARCHAR(30) NOT NULL,
    key_type VARCHAR(20) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_login_throttle_lookup (scope, key_type, key_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prompt_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(100) NOT NULL,
    name VARCHAR(160) NOT NULL,
    scene VARCHAR(40) NOT NULL,
    platform VARCHAR(40) NOT NULL DEFAULT '*',
    asset_type VARCHAR(60) NOT NULL DEFAULT '*',
    content LONGTEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    is_default TINYINT NOT NULL DEFAULT 0,
    is_system TINYINT NOT NULL DEFAULT 0,
    is_customized TINYINT NOT NULL DEFAULT 0,
    created_by_admin_id BIGINT UNSIGNED NULL,
    updated_by_admin_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_prompt_templates_code (code),
    KEY idx_prompt_templates_resolve (scene, platform, asset_type, status, is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_feedback (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    merchant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    module VARCHAR(40) NOT NULL,
    rating INT NOT NULL,
    issue_type VARCHAR(40) NOT NULL,
    improvement_type VARCHAR(40) NOT NULL,
    desired_improvement LONGTEXT NOT NULL,
    usage_context LONGTEXT NULL,
    related_reference VARCHAR(190) NULL,
    allow_contact TINYINT NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'new',
    priority VARCHAR(20) NOT NULL DEFAULT 'normal',
    admin_note LONGTEXT NULL,
    handled_by_admin_id BIGINT UNSIGNED NULL,
    handled_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_product_feedback_status (status, priority, id),
    KEY idx_product_feedback_merchant (merchant_id, id),
    KEY idx_product_feedback_improvement (improvement_type, id),
    CONSTRAINT fk_product_feedback_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id),
    CONSTRAINT fk_product_feedback_user FOREIGN KEY (user_id) REFERENCES merchant_users (id),
    CONSTRAINT fk_product_feedback_admin FOREIGN KEY (handled_by_admin_id) REFERENCES admin_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS image_tool_results (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    merchant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    asset_id BIGINT UNSIGNED NOT NULL,
    result_type VARCHAR(40) NOT NULL,
    content LONGTEXT NOT NULL,
    model VARCHAR(160) NULL,
    request_id VARCHAR(190) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_image_tool_results_merchant (merchant_id, result_type, id),
    KEY idx_image_tool_results_asset (asset_id, id),
    CONSTRAINT fk_image_tool_results_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id),
    CONSTRAINT fk_image_tool_results_user FOREIGN KEY (user_id) REFERENCES merchant_users (id),
    CONSTRAINT fk_image_tool_results_asset FOREIGN KEY (asset_id) REFERENCES assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_batch_labels (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    merchant_id BIGINT UNSIGNED NOT NULL,
    batch_key VARCHAR(190) NOT NULL,
    label VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_asset_batch_labels_key (merchant_id, batch_key),
    CONSTRAINT fk_asset_batch_labels_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_models (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    gateway VARCHAR(30) NOT NULL DEFAULT 'sub2api', model_id VARCHAR(190) NOT NULL,
    display_name VARCHAR(160) NOT NULL, provider VARCHAR(80) NULL, protocol VARCHAR(40) NOT NULL DEFAULT 'chat',
    capabilities_json LONGTEXT NOT NULL, input_modalities_json LONGTEXT NOT NULL, output_modalities_json LONGTEXT NOT NULL,
    limits_json LONGTEXT NOT NULL, adapter_config_json LONGTEXT NOT NULL, catalog_payload_json LONGTEXT NULL,
    user_selectable TINYINT NOT NULL DEFAULT 0, enabled TINYINT NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0,
    health_status VARCHAR(30) NOT NULL DEFAULT 'unknown', last_error TEXT NULL, last_tested_at DATETIME NULL,
    source_visible TINYINT NOT NULL DEFAULT 1, source_last_seen_at DATETIME NULL,
    lifecycle_status VARCHAR(30) NOT NULL DEFAULT 'configured', archived_at DATETIME NULL,
    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
    PRIMARY KEY (id), UNIQUE KEY uk_ai_models_gateway_id (gateway, model_id),
    KEY idx_ai_models_enabled (enabled, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_capability_routes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, capability_code VARCHAR(60) NOT NULL,
    primary_model_id BIGINT UNSIGNED NULL, fallback_model_id BIGINT UNSIGNED NULL,
    routing_strategy VARCHAR(30) NOT NULL DEFAULT 'primary_fallback', route_config_json LONGTEXT NOT NULL,
    enabled TINYINT NOT NULL DEFAULT 1, updated_by_admin_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
    PRIMARY KEY (id), UNIQUE KEY uk_ai_routes_capability (capability_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_model_prices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ai_model_id BIGINT UNSIGNED NOT NULL,
    capability_code VARCHAR(60) NOT NULL, unit_type VARCHAR(30) NOT NULL DEFAULT 'request',
    unit_value DECIMAL(14,4) NOT NULL DEFAULT 1, credits INT NOT NULL DEFAULT 0,
    config_json LONGTEXT NOT NULL, enabled TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
    PRIMARY KEY (id), KEY idx_ai_model_prices_lookup (ai_model_id, capability_code, enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_model_calls (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, merchant_id BIGINT UNSIGNED NULL, job_id BIGINT UNSIGNED NULL,
    capability_code VARCHAR(60) NOT NULL, ai_model_id BIGINT UNSIGNED NULL,
    request_id VARCHAR(190) NULL, status VARCHAR(30) NOT NULL, attempt_no INT NULL,
    route_role VARCHAR(30) NULL, protocol VARCHAR(40) NULL, http_status INT NULL,
    duration_ms INT NULL, input_units DECIMAL(16,4) NULL, output_units DECIMAL(16,4) NULL,
    estimated_cost DECIMAL(16,6) NULL, actual_cost DECIMAL(16,6) NULL,
    error_code VARCHAR(80) NULL, error_message TEXT NULL, prompt_hash CHAR(64) NULL,
    started_at DATETIME NULL, finished_at DATETIME NULL, created_at DATETIME NOT NULL,
    PRIMARY KEY (id), KEY idx_ai_model_calls_capability (capability_code, created_at),
    KEY idx_ai_model_calls_model (ai_model_id, status, created_at), KEY idx_ai_model_calls_job (job_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_model_sync_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, source VARCHAR(30) NOT NULL DEFAULT 'sub2api', status VARCHAR(30) NOT NULL,
    discovered_count INT NOT NULL DEFAULT 0, new_count INT NOT NULL DEFAULT 0, missing_count INT NOT NULL DEFAULT 0,
    summary_json LONGTEXT NOT NULL, initiated_by_admin_id BIGINT UNSIGNED NULL, started_at DATETIME NOT NULL, completed_at DATETIME NULL,
    PRIMARY KEY (id), KEY idx_ai_model_sync_batches_started (started_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_model_capabilities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ai_model_id BIGINT UNSIGNED NOT NULL, capability_code VARCHAR(60) NOT NULL,
    confirmation_status VARCHAR(30) NOT NULL DEFAULT 'suggested', test_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    suggested_by VARCHAR(30) NOT NULL DEFAULT 'catalog', confirmed_by_admin_id BIGINT UNSIGNED NULL, last_test_id BIGINT UNSIGNED NULL,
    notes TEXT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
    PRIMARY KEY (id), UNIQUE KEY uk_ai_model_capability (ai_model_id, capability_code),
    KEY idx_ai_model_capabilities_status (capability_code, confirmation_status, test_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_capability_tests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ai_model_id BIGINT UNSIGNED NOT NULL, capability_code VARCHAR(60) NOT NULL,
    test_scope VARCHAR(30) NOT NULL, product_code VARCHAR(60) NULL, execution_channel VARCHAR(20) NOT NULL DEFAULT 'direct',
    status VARCHAR(30) NOT NULL, request_id VARCHAR(190) NULL, duration_ms INT NOT NULL DEFAULT 0,
    paid_confirmed TINYINT NOT NULL DEFAULT 0, result_json LONGTEXT NOT NULL, tested_by_admin_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_ai_capability_tests_lookup (ai_model_id, capability_code, created_at),
    KEY idx_ai_capability_tests_business (product_code, execution_channel, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_business_compatibilities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ai_model_id BIGINT UNSIGNED NOT NULL, capability_code VARCHAR(60) NOT NULL,
    product_code VARCHAR(60) NOT NULL, execution_channel VARCHAR(20) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'pending',
    last_test_id BIGINT UNSIGNED NULL, notes TEXT NULL, updated_by_admin_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id),
    UNIQUE KEY uk_ai_business_compatibility (ai_model_id, capability_code, product_code, execution_channel),
    KEY idx_ai_business_compatibilities_ready (product_code, capability_code, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media_workflow_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(80) NOT NULL,
    name VARCHAR(120) NOT NULL,
    engine VARCHAR(30) NOT NULL DEFAULT 'comfyui',
    media_type VARCHAR(20) NOT NULL DEFAULT 'video',
    version INT NOT NULL DEFAULT 1,
    workflow_json LONGTEXT NOT NULL,
    input_map_json LONGTEXT NOT NULL,
    output_map_json LONGTEXT NOT NULL,
    workflow_hash CHAR(64) NOT NULL,
    enabled TINYINT NOT NULL DEFAULT 0,
    last_test_status VARCHAR(30) NOT NULL DEFAULT 'untested',
    last_test_error TEXT NULL,
    last_tested_at DATETIME NULL,
    updated_by_admin_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_media_workflow_code (code),
    KEY idx_media_workflow_enabled (enabled, media_type, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_type VARCHAR(40) NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(80) NOT NULL,
    target_id VARCHAR(190) NULL,
    ip_address VARCHAR(64) NOT NULL,
    details LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_navigation_preferences (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id BIGINT UNSIGNED NOT NULL,
    path VARCHAR(255) NOT NULL,
    title VARCHAR(160) NOT NULL,
    is_favorite TINYINT NOT NULL DEFAULT 0,
    last_visited_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_admin_navigation_path (admin_id, path),
    KEY idx_admin_navigation_recent (admin_id, last_visited_at),
    CONSTRAINT fk_admin_navigation_admin FOREIGN KEY (admin_id) REFERENCES admin_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_onboarding_progress (
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
    KEY idx_admin_onboarding_status (status, updated_at),
    CONSTRAINT fk_admin_onboarding_admin FOREIGN KEY (admin_id) REFERENCES admin_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
