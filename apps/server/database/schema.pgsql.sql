CREATE TABLE IF NOT EXISTS admin_users (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(40) NOT NULL DEFAULT 'super_admin',
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS merchants (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(80) NOT NULL UNIQUE,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    plan_code VARCHAR(40) NOT NULL DEFAULT 'trial',
    credit_balance INTEGER NOT NULL DEFAULT 0,
    owner_user_id BIGINT NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS subscription_plans (
    id BIGSERIAL PRIMARY KEY,
    code VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    duration_days INTEGER NOT NULL,
    price_credits INTEGER NOT NULL DEFAULT 0,
    tier_level INTEGER NOT NULL DEFAULT 1,
    audience_label VARCHAR(120) NULL,
    generation_unlimited SMALLINT NOT NULL DEFAULT 0,
    recognition_unlimited SMALLINT NOT NULL DEFAULT 0,
    daily_generation_limit INTEGER NOT NULL DEFAULT 0,
    sku_limit INTEGER NOT NULL DEFAULT 0,
    batch_image_limit INTEGER NOT NULL DEFAULT 0,
    concurrent_job_limit INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_by_admin_id BIGINT NULL REFERENCES admin_users(id),
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS merchant_subscriptions (
    id BIGSERIAL PRIMARY KEY,
    merchant_id BIGINT NOT NULL REFERENCES merchants(id),
    plan_id BIGINT NOT NULL REFERENCES subscription_plans(id),
    plan_code VARCHAR(40) NOT NULL,
    plan_name VARCHAR(120) NOT NULL,
    price_credits INTEGER NOT NULL,
    tier_level INTEGER NOT NULL DEFAULT 1,
    audience_label VARCHAR(120) NULL,
    starts_at TIMESTAMP NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    generation_unlimited SMALLINT NOT NULL DEFAULT 0,
    recognition_unlimited SMALLINT NOT NULL DEFAULT 0,
    daily_generation_limit INTEGER NOT NULL DEFAULT 0,
    sku_limit INTEGER NOT NULL DEFAULT 0,
    batch_image_limit INTEGER NOT NULL DEFAULT 0,
    concurrent_job_limit INTEGER NOT NULL DEFAULT 0,
    purchased_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_merchant_subscriptions_active ON merchant_subscriptions(merchant_id, status, expires_at);

CREATE TABLE IF NOT EXISTS merchant_users (
    id BIGSERIAL PRIMARY KEY,
    merchant_id BIGINT NOT NULL REFERENCES merchants(id),
    name VARCHAR(80) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(40) NOT NULL DEFAULT 'owner',
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    last_login_at TIMESTAMP NULL,
    last_login_ip VARCHAR(64) NULL,
    preferred_generation_platform VARCHAR(40) NULL,
    preferred_generation_language VARCHAR(20) NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS skus (
    id BIGSERIAL PRIMARY KEY,
    merchant_id BIGINT NOT NULL REFERENCES merchants(id),
    sku_code VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    category VARCHAR(100) NOT NULL,
    selling_points JSONB NOT NULL DEFAULT '[]'::jsonb,
    creative_brief TEXT NULL,
    launch_targets_json TEXT NOT NULL DEFAULT '{}',
    launch_direction TEXT NULL,
    product_description TEXT NULL,
    preferred_image_ratio VARCHAR(20) NULL,
    preferred_image_model VARCHAR(160) NULL,
    search_terms TEXT NOT NULL DEFAULT '[]',
    report_materials TEXT NULL,
    report_appearance TEXT NULL,
    report_target_audience TEXT NULL,
    report_use_scenarios TEXT NOT NULL DEFAULT '[]',
    report_core_functions TEXT NOT NULL DEFAULT '[]',
    report_uncertain_points TEXT NOT NULL DEFAULT '[]',
    report_prohibited_claims TEXT NOT NULL DEFAULT '[]',
    report_brand_notes TEXT NULL,
    foundation_source VARCHAR(30) NULL,
    report_market_research_json TEXT NULL,
    report_market_query TEXT NULL,
    report_market_updated_at TIMESTAMP NULL,
    report_status VARCHAR(30) NOT NULL DEFAULT 'draft',
    report_source_project_id BIGINT NULL,
    report_updated_at TIMESTAMP NULL,
    source_image_path TEXT NULL,
    source_image_backup_path TEXT NULL,
    reference_images_json TEXT NOT NULL DEFAULT '[]',
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL,
    UNIQUE (merchant_id, sku_code)
);

CREATE TABLE IF NOT EXISTS detail_page_projects (
    id BIGSERIAL PRIMARY KEY,
    merchant_id BIGINT NOT NULL REFERENCES merchants(id),
    created_by_user_id BIGINT NULL REFERENCES merchant_users(id),
    sku_id BIGINT NULL REFERENCES skus(id),
    product_name VARCHAR(160) NOT NULL,
    category VARCHAR(100) NOT NULL,
    materials TEXT NULL,
    appearance TEXT NULL,
    target_audience TEXT NULL,
    use_scenarios JSONB NOT NULL DEFAULT '[]'::jsonb,
    selling_points JSONB NOT NULL DEFAULT '[]'::jsonb,
    core_functions JSONB NOT NULL DEFAULT '[]'::jsonb,
    uncertain_points JSONB NOT NULL DEFAULT '[]'::jsonb,
    prohibited_claims JSONB NOT NULL DEFAULT '[]'::jsonb,
    product_description TEXT NULL,
    brand_notes TEXT NULL,
    creative_brief TEXT NULL,
    screen_plan JSONB NOT NULL DEFAULT '[]'::jsonb,
    source_image_url TEXT NOT NULL,
    source_image_backup_url TEXT NULL,
    platform VARCHAR(40) NOT NULL,
    language VARCHAR(20) NOT NULL DEFAULT 'zh-CN',
    market_research_json TEXT NULL,
    market_research_query TEXT NULL,
    market_research_updated_at TIMESTAMP NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_detail_projects_merchant ON detail_page_projects(merchant_id, id);
CREATE INDEX IF NOT EXISTS idx_detail_projects_sku ON detail_page_projects(sku_id, id);

CREATE TABLE IF NOT EXISTS market_research_cache (
    id BIGSERIAL PRIMARY KEY,
    merchant_id BIGINT NOT NULL REFERENCES merchants(id),
    sku_id BIGINT NULL REFERENCES skus(id),
    query_hash CHAR(64) NOT NULL,
    query_text TEXT NOT NULL,
    result_json TEXT NOT NULL,
    source_count INTEGER NOT NULL DEFAULT 0,
    provider_credits INTEGER NOT NULL DEFAULT 0,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL,
    UNIQUE (merchant_id, query_hash)
);
CREATE INDEX IF NOT EXISTS idx_market_research_expiry ON market_research_cache(expires_at);

CREATE TABLE IF NOT EXISTS detail_page_messages (
    id BIGSERIAL PRIMARY KEY,
    project_id BIGINT NOT NULL REFERENCES detail_page_projects(id),
    merchant_id BIGINT NOT NULL REFERENCES merchants(id),
    role VARCHAR(20) NOT NULL,
    content TEXT NOT NULL,
    request_id VARCHAR(190) NULL,
    created_at TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_detail_messages_project ON detail_page_messages(project_id, id);

CREATE TABLE IF NOT EXISTS generation_jobs (
    id BIGSERIAL PRIMARY KEY,
    merchant_id BIGINT NOT NULL REFERENCES merchants(id),
    sku_id BIGINT NULL REFERENCES skus(id),
    detail_page_project_id BIGINT NULL REFERENCES detail_page_projects(id),
    bundle_no VARCHAR(80) NULL,
    bundle_part VARCHAR(20) NULL,
    job_no VARCHAR(80) NOT NULL UNIQUE,
    client_submission_id VARCHAR(64) NULL,
    submission_payload_hash VARCHAR(64) NULL,
    output_kind VARCHAR(20) NOT NULL DEFAULT 'image',
    platform VARCHAR(40) NOT NULL,
    language VARCHAR(20) NOT NULL DEFAULT 'zh-CN',
    asset_types JSONB NOT NULL DEFAULT '[]'::jsonb,
    custom_material_requests JSONB NULL,
    task_brief TEXT NULL,
    task_context_json TEXT NULL,
    submitted_by_user_id BIGINT NULL REFERENCES merchant_users(id),
    submitted_ip VARCHAR(64) NULL,
    safety_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    safety_attempts INTEGER NOT NULL DEFAULT 0,
    safety_next_retry_at TIMESTAMP NULL,
    safety_checked_at TIMESTAMP NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'pending_gateway',
    estimated_credits INTEGER NOT NULL DEFAULT 0,
    actual_credits INTEGER NOT NULL DEFAULT 0,
    progress_current INTEGER NOT NULL DEFAULT 0,
    progress_total INTEGER NOT NULL DEFAULT 0,
    progress_stage VARCHAR(40) NOT NULL DEFAULT 'queued',
    progress_message TEXT NULL,
    model VARCHAR(120) NULL,
    requested_model_id BIGINT NULL,
    resolved_ai_model_id BIGINT NULL,
    route_snapshot_json TEXT NULL,
    pricing_snapshot_json TEXT NULL,
    new_api_request_id VARCHAR(190) NULL,
    error_message TEXT NULL,
    merchant_hidden_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_generation_jobs_bundle ON generation_jobs(bundle_no, bundle_part);
CREATE INDEX IF NOT EXISTS idx_generation_jobs_safety ON generation_jobs(status, safety_status, safety_next_retry_at, id);
CREATE UNIQUE INDEX IF NOT EXISTS uk_generation_jobs_submission ON generation_jobs(merchant_id, client_submission_id);

CREATE TABLE IF NOT EXISTS generation_job_items (
    id BIGSERIAL PRIMARY KEY,
    job_id BIGINT NOT NULL REFERENCES generation_jobs(id),
    merchant_id BIGINT NOT NULL REFERENCES merchants(id),
    position INTEGER NOT NULL,
    asset_type VARCHAR(60) NOT NULL,
    occurrence INTEGER NOT NULL DEFAULT 1,
    type_total INTEGER NOT NULL DEFAULT 1,
    estimated_credits INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'queued',
    attempts INTEGER NOT NULL DEFAULT 0,
    manual_retry_count INTEGER NOT NULL DEFAULT 0,
    next_retry_at TIMESTAMP NULL,
    last_retry_reason TEXT NULL,
    worker_slot INTEGER NULL,
    reservation_ledger_id BIGINT NULL,
    asset_id BIGINT NULL,
    request_id VARCHAR(190) NULL,
    error_message TEXT NULL,
    stage_timestamps_json TEXT NOT NULL DEFAULT '{}',
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL,
    UNIQUE (job_id, position)
);
CREATE INDEX IF NOT EXISTS idx_generation_items_queue ON generation_job_items(status, id);
CREATE INDEX IF NOT EXISTS idx_generation_items_retry ON generation_job_items(status, next_retry_at, id);
CREATE INDEX IF NOT EXISTS idx_generation_items_job ON generation_job_items(job_id, status, id);
CREATE INDEX IF NOT EXISTS idx_generation_items_merchant ON generation_job_items(merchant_id, status, id);

CREATE TABLE IF NOT EXISTS assets (
    id BIGSERIAL PRIMARY KEY,
    merchant_id BIGINT NOT NULL REFERENCES merchants(id),
    sku_id BIGINT NULL REFERENCES skus(id),
    job_id BIGINT NOT NULL REFERENCES generation_jobs(id),
    generation_item_id BIGINT NULL REFERENCES generation_job_items(id),
    asset_type VARCHAR(60) NOT NULL,
    media_type VARCHAR(20) NOT NULL DEFAULT 'image',
    mime_type VARCHAR(120) NULL,
    duration_seconds INTEGER NULL,
    poster_url TEXT NULL,
    width INTEGER NULL,
    height INTEGER NULL,
    file_url TEXT NOT NULL,
    backup_file_url TEXT NULL,
    score INTEGER NULL,
    is_favorite SMALLINT NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    deleted_at TIMESTAMP NULL,
    purged_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_assets_generation_item ON assets(generation_item_id) WHERE generation_item_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_assets_trash_cleanup ON assets(status, deleted_at, id);

CREATE TABLE IF NOT EXISTS video_generation_requests (
    id BIGSERIAL PRIMARY KEY,
    job_id BIGINT NOT NULL UNIQUE REFERENCES generation_jobs(id),
    merchant_id BIGINT NOT NULL REFERENCES merchants(id),
    sku_id BIGINT NULL REFERENCES skus(id),
    source_asset_id BIGINT NULL REFERENCES assets(id),
    reference_image_url TEXT NULL,
    reference_image_backup_url TEXT NULL,
    prompt TEXT NOT NULL,
    motion_preset VARCHAR(40) NOT NULL DEFAULT 'product_showcase',
    ratio VARCHAR(10) NOT NULL DEFAULT '9:16',
    resolution VARCHAR(20) NOT NULL DEFAULT '720p',
    duration_seconds INTEGER NOT NULL DEFAULT 5,
    generate_audio SMALLINT NOT NULL DEFAULT 0,
    provider_request_id VARCHAR(190) NULL,
    provider_status VARCHAR(40) NOT NULL DEFAULT 'queued',
    provider_progress INTEGER NOT NULL DEFAULT 0,
    submit_attempts INTEGER NOT NULL DEFAULT 0,
    poll_attempts INTEGER NOT NULL DEFAULT 0,
    next_poll_at TIMESTAMP NULL,
    last_polled_at TIMESTAMP NULL,
    video_url TEXT NULL,
    last_frame_url TEXT NULL,
    request_payload_json TEXT NULL,
    result_payload_json TEXT NULL,
    reservation_ledger_id BIGINT NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_video_requests_queue ON video_generation_requests(provider_status, next_poll_at, id);
CREATE INDEX IF NOT EXISTS idx_video_requests_merchant ON video_generation_requests(merchant_id, id);

CREATE TABLE IF NOT EXISTS canvas_edits (
    id BIGSERIAL PRIMARY KEY,
    job_id BIGINT NOT NULL UNIQUE REFERENCES generation_jobs(id),
    source_asset_id BIGINT NOT NULL REFERENCES assets(id),
    mode VARCHAR(20) NOT NULL DEFAULT 'global',
    preset VARCHAR(40) NOT NULL DEFAULT 'custom',
    instruction TEXT NOT NULL,
    mask_file TEXT NULL,
    output_preset VARCHAR(40) NOT NULL DEFAULT 'square_1_1',
    output_width INTEGER NOT NULL DEFAULT 1024,
    output_height INTEGER NOT NULL DEFAULT 1024,
    resize_mode VARCHAR(20) NOT NULL DEFAULT 'extend',
    operation VARCHAR(20) NOT NULL DEFAULT 'ai_edit',
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_canvas_edits_source ON canvas_edits(source_asset_id);

CREATE TABLE IF NOT EXISTS canvas_workspaces (
    merchant_id BIGINT PRIMARY KEY REFERENCES merchants(id),
    graph_json TEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS creative_projects (
    id BIGSERIAL PRIMARY KEY,
    merchant_id BIGINT NOT NULL REFERENCES merchants(id),
    created_by_user_id BIGINT NULL REFERENCES merchant_users(id) ON DELETE SET NULL,
    sku_id BIGINT NULL REFERENCES skus(id) ON DELETE SET NULL,
    title VARCHAR(120) NOT NULL,
    project_type VARCHAR(20) NOT NULL DEFAULT 'image',
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    cover_asset_id BIGINT NULL REFERENCES assets(id) ON DELETE SET NULL,
    document_json TEXT NOT NULL,
    document_hash CHAR(64) NOT NULL,
    document_version INTEGER NOT NULL DEFAULT 1,
    current_revision_no INTEGER NOT NULL DEFAULT 1,
    legacy_canvas_migrated SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_creative_projects_merchant ON creative_projects(merchant_id, status, updated_at, id);
CREATE INDEX IF NOT EXISTS idx_creative_projects_sku ON creative_projects(sku_id, status, id);

CREATE TABLE IF NOT EXISTS creative_project_revisions (
    id BIGSERIAL PRIMARY KEY,
    project_id BIGINT NOT NULL REFERENCES creative_projects(id) ON DELETE CASCADE,
    revision_no INTEGER NOT NULL,
    schema_version INTEGER NOT NULL DEFAULT 2,
    source VARCHAR(20) NOT NULL DEFAULT 'auto',
    document_hash CHAR(64) NOT NULL,
    document_json TEXT NOT NULL,
    created_by_user_id BIGINT NULL REFERENCES merchant_users(id) ON DELETE SET NULL,
    created_at TIMESTAMP NOT NULL,
    UNIQUE(project_id, revision_no)
);
CREATE INDEX IF NOT EXISTS idx_creative_project_revisions_created ON creative_project_revisions(project_id, created_at, id);

CREATE TABLE IF NOT EXISTS creative_project_assets (
    id BIGSERIAL PRIMARY KEY,
    project_id BIGINT NOT NULL REFERENCES creative_projects(id) ON DELETE CASCADE,
    asset_id BIGINT NOT NULL REFERENCES assets(id) ON DELETE CASCADE,
    node_uuid VARCHAR(64) NOT NULL,
    role VARCHAR(30) NOT NULL DEFAULT 'source',
    created_at TIMESTAMP NOT NULL,
    UNIQUE(project_id, node_uuid)
);
CREATE INDEX IF NOT EXISTS idx_creative_project_assets_asset ON creative_project_assets(asset_id, project_id);

CREATE TABLE IF NOT EXISTS creative_project_runs (
    id BIGSERIAL PRIMARY KEY,
    project_id BIGINT NOT NULL REFERENCES creative_projects(id) ON DELETE CASCADE,
    node_uuid VARCHAR(64) NULL,
    job_id BIGINT NOT NULL UNIQUE REFERENCES generation_jobs(id) ON DELETE CASCADE,
    run_type VARCHAR(30) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'queued',
    input_snapshot_json TEXT NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_creative_project_runs_project ON creative_project_runs(project_id, status, id);

CREATE TABLE IF NOT EXISTS workspace_project_preferences (
    id BIGSERIAL PRIMARY KEY,
    merchant_id BIGINT NOT NULL REFERENCES merchants(id),
    project_type VARCHAR(20) NOT NULL,
    project_id BIGINT NOT NULL,
    custom_name VARCHAR(120) NULL,
    pinned SMALLINT NOT NULL DEFAULT 0,
    hidden SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL,
    UNIQUE (merchant_id, project_type, project_id)
);

CREATE INDEX IF NOT EXISTS idx_workspace_project_sidebar ON workspace_project_preferences(merchant_id, hidden, pinned, updated_at);

CREATE TABLE IF NOT EXISTS free_generation_jobs (
    job_id BIGINT PRIMARY KEY REFERENCES generation_jobs(id),
    prompt TEXT NOT NULL,
    prompt_template_id BIGINT NULL,
    prompt_template_code VARCHAR(100) NULL,
    prompt_template_name VARCHAR(160) NULL,
    prompt_template_content TEXT NULL,
    subject_text TEXT NULL,
    user_addition TEXT NULL,
    reference_image_url TEXT NULL,
    reference_image_backup_url TEXT NULL,
    reference_images_json TEXT NOT NULL DEFAULT '[]',
    reference_count INTEGER NOT NULL DEFAULT 0,
    reference_fingerprints_json TEXT NOT NULL DEFAULT '[]',
    outbound_reference_sha256 VARCHAR(64) NULL,
    output_preset VARCHAR(40) NOT NULL DEFAULT 'square_1_1',
    output_width INTEGER NOT NULL DEFAULT 1024,
    output_height INTEGER NOT NULL DEFAULT 1024,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS credit_ledger (
    id BIGSERIAL PRIMARY KEY,
    merchant_id BIGINT NOT NULL REFERENCES merchants(id),
    type VARCHAR(40) NOT NULL,
    amount INTEGER NOT NULL,
    balance_after INTEGER NOT NULL,
    source VARCHAR(60) NOT NULL,
    reference_type VARCHAR(60) NULL,
    reference_id VARCHAR(190) NULL,
    new_api_request_id VARCHAR(190) NULL,
    operator_admin_id BIGINT NULL REFERENCES admin_users(id),
    note TEXT NULL,
    created_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS usage_records (
    id BIGSERIAL PRIMARY KEY,
    merchant_id BIGINT NOT NULL REFERENCES merchants(id),
    job_id BIGINT NULL REFERENCES generation_jobs(id),
    new_api_request_id VARCHAR(190) NULL UNIQUE,
    model VARCHAR(120) NULL,
    provider VARCHAR(80) NULL,
    business_credits INTEGER NOT NULL DEFAULT 0,
    gateway_cost NUMERIC(14, 6) NOT NULL DEFAULT 0,
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    occurred_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    is_secret SMALLINT NOT NULL DEFAULT 0,
    updated_by BIGINT NULL REFERENCES admin_users(id),
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS storage_migrations (
    id BIGSERIAL PRIMARY KEY,
    source_type VARCHAR(30) NOT NULL,
    source_id BIGINT NOT NULL,
    merchant_id BIGINT NOT NULL,
    source_url TEXT NOT NULL,
    target_url TEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL,
    UNIQUE (source_type, source_id)
);

CREATE TABLE IF NOT EXISTS email_verification_codes (
    id BIGSERIAL PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    purpose VARCHAR(40) NOT NULL,
    user_id BIGINT NULL REFERENCES merchant_users(id),
    code_hash VARCHAR(64) NOT NULL,
    attempts SMALLINT NOT NULL DEFAULT 0,
    expires_at TIMESTAMP NOT NULL,
    consumed_at TIMESTAMP NULL,
    requested_ip VARCHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS redemption_card_batches (
    id BIGSERIAL PRIMARY KEY,
    batch_no VARCHAR(80) NOT NULL UNIQUE,
    label VARCHAR(120) NOT NULL,
    credits INTEGER NOT NULL,
    total_count INTEGER NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    expires_at TIMESTAMP NULL,
    created_by_admin_id BIGINT NOT NULL REFERENCES admin_users(id),
    created_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS redemption_cards (
    id BIGSERIAL PRIMARY KEY,
    batch_id BIGINT NOT NULL REFERENCES redemption_card_batches(id),
    code_hash VARCHAR(64) NOT NULL UNIQUE,
    code_ciphertext TEXT NOT NULL,
    code_last4 VARCHAR(4) NOT NULL,
    credits INTEGER NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'unused',
    redeemed_by_merchant_id BIGINT NULL REFERENCES merchants(id),
    redeemed_by_user_id BIGINT NULL REFERENCES merchant_users(id),
    redeemed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS content_moderation_checks (
    id BIGSERIAL PRIMARY KEY,
    merchant_id BIGINT NULL REFERENCES merchants(id),
    user_id BIGINT NULL REFERENCES merchant_users(id),
    content_type VARCHAR(20) NOT NULL,
    content_hash VARCHAR(64) NOT NULL,
    source VARCHAR(80) NOT NULL,
    status VARCHAR(20) NOT NULL,
    categories TEXT NOT NULL,
    reason TEXT NULL,
    model VARCHAR(120) NULL,
    request_id VARCHAR(190) NULL,
    cache_hit_count INTEGER NOT NULL DEFAULT 0,
    last_cache_hit_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_moderation_checks_hash ON content_moderation_checks(content_hash, status);

CREATE TABLE IF NOT EXISTS content_violations (
    id BIGSERIAL PRIMARY KEY,
    merchant_id BIGINT NOT NULL REFERENCES merchants(id),
    user_id BIGINT NULL REFERENCES merchant_users(id),
    content_type VARCHAR(20) NOT NULL,
    content_hash VARCHAR(64) NOT NULL,
    source VARCHAR(80) NOT NULL,
    categories TEXT NOT NULL,
    reason TEXT NULL,
    content_excerpt TEXT NULL,
    penalty_credits INTEGER NOT NULL DEFAULT 0,
    warning_email_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    warning_error TEXT NULL,
    ip_address VARCHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL,
    UNIQUE (merchant_id, content_type, content_hash)
);

CREATE INDEX IF NOT EXISTS idx_content_violations_created ON content_violations(created_at);

CREATE TABLE IF NOT EXISTS login_throttle_events (
    id BIGSERIAL PRIMARY KEY,
    scope VARCHAR(30) NOT NULL,
    key_type VARCHAR(20) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_login_throttle_lookup ON login_throttle_events(scope, key_type, key_hash, created_at);

CREATE TABLE IF NOT EXISTS prompt_templates (
    id BIGSERIAL PRIMARY KEY,
    code VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    scene VARCHAR(40) NOT NULL,
    platform VARCHAR(40) NOT NULL DEFAULT '*',
    asset_type VARCHAR(60) NOT NULL DEFAULT '*',
    content TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    is_default SMALLINT NOT NULL DEFAULT 0,
    is_system SMALLINT NOT NULL DEFAULT 0,
    is_customized SMALLINT NOT NULL DEFAULT 0,
    created_by_admin_id BIGINT NULL,
    updated_by_admin_id BIGINT NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_prompt_templates_resolve ON prompt_templates(scene, platform, asset_type, status, is_default);

CREATE TABLE IF NOT EXISTS product_feedback (
    id BIGSERIAL PRIMARY KEY,
    merchant_id BIGINT NOT NULL REFERENCES merchants(id),
    user_id BIGINT NOT NULL REFERENCES merchant_users(id),
    module VARCHAR(40) NOT NULL,
    rating INTEGER NOT NULL,
    issue_type VARCHAR(40) NOT NULL,
    improvement_type VARCHAR(40) NOT NULL,
    desired_improvement TEXT NOT NULL,
    usage_context TEXT NULL,
    related_reference VARCHAR(190) NULL,
    allow_contact SMALLINT NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'new',
    priority VARCHAR(20) NOT NULL DEFAULT 'normal',
    admin_note TEXT NULL,
    handled_by_admin_id BIGINT NULL REFERENCES admin_users(id),
    handled_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_product_feedback_status ON product_feedback(status, priority, id);
CREATE INDEX IF NOT EXISTS idx_product_feedback_merchant ON product_feedback(merchant_id, id);
CREATE INDEX IF NOT EXISTS idx_product_feedback_improvement ON product_feedback(improvement_type, id);

CREATE TABLE IF NOT EXISTS image_tool_results (
    id BIGSERIAL PRIMARY KEY,
    merchant_id BIGINT NOT NULL REFERENCES merchants(id),
    user_id BIGINT NOT NULL REFERENCES merchant_users(id),
    asset_id BIGINT NOT NULL REFERENCES assets(id),
    result_type VARCHAR(40) NOT NULL,
    content TEXT NOT NULL,
    model VARCHAR(160) NULL,
    request_id VARCHAR(190) NULL,
    created_at TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_image_tool_results_merchant ON image_tool_results(merchant_id, result_type, id DESC);
CREATE INDEX IF NOT EXISTS idx_image_tool_results_asset ON image_tool_results(asset_id, id DESC);

CREATE TABLE IF NOT EXISTS asset_batch_labels (
    id BIGSERIAL PRIMARY KEY,
    merchant_id BIGINT NOT NULL REFERENCES merchants(id),
    batch_key VARCHAR(190) NOT NULL,
    label VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL,
    UNIQUE (merchant_id, batch_key)
);

CREATE TABLE IF NOT EXISTS ai_models (
    id BIGSERIAL PRIMARY KEY,
    gateway VARCHAR(30) NOT NULL DEFAULT 'sub2api',
    model_id VARCHAR(190) NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    provider VARCHAR(80) NULL,
    protocol VARCHAR(40) NOT NULL DEFAULT 'chat',
    capabilities_json TEXT NOT NULL DEFAULT '[]',
    input_modalities_json TEXT NOT NULL DEFAULT '[]',
    output_modalities_json TEXT NOT NULL DEFAULT '[]',
    limits_json TEXT NOT NULL DEFAULT '{}',
    adapter_config_json TEXT NOT NULL DEFAULT '{}',
    catalog_payload_json TEXT NULL,
    user_selectable SMALLINT NOT NULL DEFAULT 0,
    enabled SMALLINT NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    health_status VARCHAR(30) NOT NULL DEFAULT 'unknown',
    last_error TEXT NULL,
    last_tested_at TIMESTAMP NULL,
    source_visible SMALLINT NOT NULL DEFAULT 1,
    source_last_seen_at TIMESTAMP NULL,
    lifecycle_status VARCHAR(30) NOT NULL DEFAULT 'configured',
    archived_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL,
    UNIQUE (gateway, model_id)
);
CREATE INDEX IF NOT EXISTS idx_ai_models_enabled ON ai_models(enabled, sort_order, id);

CREATE TABLE IF NOT EXISTS ai_capability_routes (
    id BIGSERIAL PRIMARY KEY,
    capability_code VARCHAR(60) NOT NULL UNIQUE,
    primary_model_id BIGINT NULL,
    fallback_model_id BIGINT NULL,
    routing_strategy VARCHAR(30) NOT NULL DEFAULT 'primary_fallback',
    route_config_json TEXT NOT NULL DEFAULT '{}',
    enabled SMALLINT NOT NULL DEFAULT 1,
    updated_by_admin_id BIGINT NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS ai_model_prices (
    id BIGSERIAL PRIMARY KEY,
    ai_model_id BIGINT NOT NULL,
    capability_code VARCHAR(60) NOT NULL,
    unit_type VARCHAR(30) NOT NULL DEFAULT 'request',
    unit_value NUMERIC(14,4) NOT NULL DEFAULT 1,
    credits INTEGER NOT NULL DEFAULT 0,
    config_json TEXT NOT NULL DEFAULT '{}',
    enabled SMALLINT NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ai_model_prices_lookup ON ai_model_prices(ai_model_id, capability_code, enabled);

CREATE TABLE IF NOT EXISTS ai_model_calls (
    id BIGSERIAL PRIMARY KEY,
    merchant_id BIGINT NULL,
    job_id BIGINT NULL,
    capability_code VARCHAR(60) NOT NULL,
    ai_model_id BIGINT NULL,
    request_id VARCHAR(190) NULL,
    status VARCHAR(30) NOT NULL,
    attempt_no INTEGER NULL,
    route_role VARCHAR(30) NULL,
    protocol VARCHAR(40) NULL,
    http_status INTEGER NULL,
    duration_ms INTEGER NULL,
    input_units NUMERIC(16,4) NULL,
    output_units NUMERIC(16,4) NULL,
    estimated_cost NUMERIC(16,6) NULL,
    actual_cost NUMERIC(16,6) NULL,
    error_code VARCHAR(80) NULL,
    error_message TEXT NULL,
    prompt_hash CHAR(64) NULL,
    started_at TIMESTAMP NULL,
    finished_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ai_model_calls_capability ON ai_model_calls(capability_code, created_at);
CREATE INDEX IF NOT EXISTS idx_ai_model_calls_model ON ai_model_calls(ai_model_id, status, created_at);
CREATE INDEX IF NOT EXISTS idx_ai_model_calls_job ON ai_model_calls(job_id, id);

CREATE TABLE IF NOT EXISTS ai_model_sync_batches (
    id BIGSERIAL PRIMARY KEY, source VARCHAR(30) NOT NULL DEFAULT 'sub2api', status VARCHAR(30) NOT NULL,
    discovered_count INTEGER NOT NULL DEFAULT 0, new_count INTEGER NOT NULL DEFAULT 0, missing_count INTEGER NOT NULL DEFAULT 0,
    summary_json TEXT NOT NULL DEFAULT '{}', initiated_by_admin_id BIGINT NULL, started_at TIMESTAMP NOT NULL, completed_at TIMESTAMP NULL
);
CREATE INDEX IF NOT EXISTS idx_ai_model_sync_batches_started ON ai_model_sync_batches(started_at, id);

CREATE TABLE IF NOT EXISTS ai_model_capabilities (
    id BIGSERIAL PRIMARY KEY, ai_model_id BIGINT NOT NULL, capability_code VARCHAR(60) NOT NULL,
    confirmation_status VARCHAR(30) NOT NULL DEFAULT 'suggested', test_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    suggested_by VARCHAR(30) NOT NULL DEFAULT 'catalog', confirmed_by_admin_id BIGINT NULL, last_test_id BIGINT NULL,
    notes TEXT NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL, UNIQUE (ai_model_id, capability_code)
);
CREATE INDEX IF NOT EXISTS idx_ai_model_capabilities_status ON ai_model_capabilities(capability_code, confirmation_status, test_status);

CREATE TABLE IF NOT EXISTS ai_capability_tests (
    id BIGSERIAL PRIMARY KEY, ai_model_id BIGINT NOT NULL, capability_code VARCHAR(60) NOT NULL,
    test_scope VARCHAR(30) NOT NULL, product_code VARCHAR(60) NULL, execution_channel VARCHAR(20) NOT NULL DEFAULT 'direct',
    status VARCHAR(30) NOT NULL, request_id VARCHAR(190) NULL, duration_ms INTEGER NOT NULL DEFAULT 0,
    paid_confirmed SMALLINT NOT NULL DEFAULT 0, result_json TEXT NOT NULL DEFAULT '{}',
    tested_by_admin_id BIGINT NULL, created_at TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ai_capability_tests_lookup ON ai_capability_tests(ai_model_id, capability_code, created_at);
CREATE INDEX IF NOT EXISTS idx_ai_capability_tests_business ON ai_capability_tests(product_code, execution_channel, status);

CREATE TABLE IF NOT EXISTS ai_business_compatibilities (
    id BIGSERIAL PRIMARY KEY, ai_model_id BIGINT NOT NULL, capability_code VARCHAR(60) NOT NULL,
    product_code VARCHAR(60) NOT NULL, execution_channel VARCHAR(20) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'pending',
    last_test_id BIGINT NULL, notes TEXT NULL, updated_by_admin_id BIGINT NULL, created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL,
    UNIQUE (ai_model_id, capability_code, product_code, execution_channel)
);
CREATE INDEX IF NOT EXISTS idx_ai_business_compatibilities_ready ON ai_business_compatibilities(product_code, capability_code, status);

CREATE TABLE IF NOT EXISTS media_workflow_templates (
    id BIGSERIAL PRIMARY KEY,
    code VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    engine VARCHAR(30) NOT NULL DEFAULT 'comfyui',
    media_type VARCHAR(20) NOT NULL DEFAULT 'video',
    version INTEGER NOT NULL DEFAULT 1,
    workflow_json TEXT NOT NULL,
    input_map_json TEXT NOT NULL DEFAULT '{}',
    output_map_json TEXT NOT NULL DEFAULT '{}',
    workflow_hash CHAR(64) NOT NULL,
    enabled SMALLINT NOT NULL DEFAULT 0,
    last_test_status VARCHAR(30) NOT NULL DEFAULT 'untested',
    last_test_error TEXT NULL,
    last_tested_at TIMESTAMP NULL,
    updated_by_admin_id BIGINT NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_media_workflow_enabled ON media_workflow_templates(enabled, media_type, code);

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGSERIAL PRIMARY KEY,
    actor_type VARCHAR(40) NOT NULL,
    actor_id BIGINT NOT NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(80) NOT NULL,
    target_id VARCHAR(190) NULL,
    ip_address VARCHAR(64) NOT NULL,
    details JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS admin_navigation_preferences (
    id BIGSERIAL PRIMARY KEY,
    admin_id BIGINT NOT NULL REFERENCES admin_users(id),
    path VARCHAR(255) NOT NULL,
    title VARCHAR(160) NOT NULL,
    is_favorite SMALLINT NOT NULL DEFAULT 0,
    last_visited_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL,
    UNIQUE (admin_id, path)
);
CREATE INDEX IF NOT EXISTS idx_admin_navigation_recent ON admin_navigation_preferences(admin_id, last_visited_at DESC);

CREATE TABLE IF NOT EXISTS admin_onboarding_progress (
    id BIGSERIAL PRIMARY KEY,
    admin_id BIGINT NOT NULL UNIQUE REFERENCES admin_users(id),
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    current_step VARCHAR(40) NOT NULL DEFAULT 'welcome',
    step_state_json TEXT NOT NULL DEFAULT '{}',
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_admin_onboarding_status ON admin_onboarding_progress(status, updated_at DESC);

CREATE INDEX IF NOT EXISTS idx_skus_merchant ON skus(merchant_id);
CREATE INDEX IF NOT EXISTS idx_jobs_merchant_status ON generation_jobs(merchant_id, status);
CREATE INDEX IF NOT EXISTS idx_assets_merchant_status ON assets(merchant_id, status);
CREATE INDEX IF NOT EXISTS idx_ledger_merchant_created ON credit_ledger(merchant_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_usage_merchant_created ON usage_records(merchant_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_system_settings_updated ON system_settings(updated_at DESC);
CREATE INDEX IF NOT EXISTS idx_storage_migration_status ON storage_migrations(status, id);
CREATE INDEX IF NOT EXISTS idx_email_codes_lookup ON email_verification_codes(email, purpose, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_email_codes_ip ON email_verification_codes(requested_ip, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_card_batches_created ON redemption_card_batches(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_cards_batch_status ON redemption_cards(batch_id, status);
CREATE INDEX IF NOT EXISTS idx_cards_redeemed_merchant ON redemption_cards(redeemed_by_merchant_id, redeemed_at DESC);
