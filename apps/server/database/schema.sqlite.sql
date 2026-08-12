PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;
PRAGMA busy_timeout = 15000;

CREATE TABLE IF NOT EXISTS admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'super_admin',
    status TEXT NOT NULL DEFAULT 'active',
    last_login_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS merchants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'active',
    plan_code TEXT NOT NULL DEFAULT 'trial',
    credit_balance INTEGER NOT NULL DEFAULT 0,
    owner_user_id INTEGER NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS subscription_plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT NULL,
    duration_days INTEGER NOT NULL,
    price_credits INTEGER NOT NULL DEFAULT 0,
    tier_level INTEGER NOT NULL DEFAULT 1,
    audience_label TEXT NULL,
    generation_unlimited INTEGER NOT NULL DEFAULT 0,
    recognition_unlimited INTEGER NOT NULL DEFAULT 0,
    daily_generation_limit INTEGER NOT NULL DEFAULT 0,
    sku_limit INTEGER NOT NULL DEFAULT 0,
    batch_image_limit INTEGER NOT NULL DEFAULT 0,
    concurrent_job_limit INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'active',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_by_admin_id INTEGER NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (created_by_admin_id) REFERENCES admin_users(id)
);

CREATE TABLE IF NOT EXISTS merchant_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    merchant_id INTEGER NOT NULL,
    plan_id INTEGER NOT NULL,
    plan_code TEXT NOT NULL,
    plan_name TEXT NOT NULL,
    price_credits INTEGER NOT NULL,
    tier_level INTEGER NOT NULL DEFAULT 1,
    audience_label TEXT NULL,
    starts_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    generation_unlimited INTEGER NOT NULL DEFAULT 0,
    recognition_unlimited INTEGER NOT NULL DEFAULT 0,
    daily_generation_limit INTEGER NOT NULL DEFAULT 0,
    sku_limit INTEGER NOT NULL DEFAULT 0,
    batch_image_limit INTEGER NOT NULL DEFAULT 0,
    concurrent_job_limit INTEGER NOT NULL DEFAULT 0,
    purchased_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (merchant_id) REFERENCES merchants(id),
    FOREIGN KEY (plan_id) REFERENCES subscription_plans(id)
);

CREATE TABLE IF NOT EXISTS merchant_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    merchant_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'owner',
    status TEXT NOT NULL DEFAULT 'active',
    last_login_at TEXT NULL,
    last_login_ip TEXT NULL,
    preferred_generation_platform TEXT NULL,
    preferred_generation_language TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (merchant_id) REFERENCES merchants(id)
);

CREATE TABLE IF NOT EXISTS skus (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    merchant_id INTEGER NOT NULL,
    sku_code TEXT NOT NULL,
    name TEXT NOT NULL,
    category TEXT NOT NULL,
    selling_points TEXT NOT NULL DEFAULT '[]',
    creative_brief TEXT NULL,
    launch_targets_json TEXT NOT NULL DEFAULT '{}',
    launch_direction TEXT NULL,
    product_description TEXT NULL,
    preferred_image_ratio TEXT NULL,
    preferred_image_model TEXT NULL,
    search_terms TEXT NOT NULL DEFAULT '[]',
    report_materials TEXT NULL,
    report_appearance TEXT NULL,
    report_target_audience TEXT NULL,
    report_use_scenarios TEXT NOT NULL DEFAULT '[]',
    report_core_functions TEXT NOT NULL DEFAULT '[]',
    report_uncertain_points TEXT NOT NULL DEFAULT '[]',
    report_prohibited_claims TEXT NOT NULL DEFAULT '[]',
    report_brand_notes TEXT NULL,
    foundation_source TEXT NULL,
    report_market_research_json TEXT NULL,
    report_market_query TEXT NULL,
    report_market_updated_at TEXT NULL,
    report_status TEXT NOT NULL DEFAULT 'draft',
    report_source_project_id INTEGER NULL,
    report_updated_at TEXT NULL,
    source_image_path TEXT NULL,
    source_image_backup_path TEXT NULL,
    reference_images_json TEXT NOT NULL DEFAULT '[]',
    status TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (merchant_id, sku_code),
    FOREIGN KEY (merchant_id) REFERENCES merchants(id)
);

CREATE TABLE IF NOT EXISTS detail_page_projects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    merchant_id INTEGER NOT NULL,
    created_by_user_id INTEGER NULL,
    sku_id INTEGER NULL,
    product_name TEXT NOT NULL,
    category TEXT NOT NULL,
    materials TEXT NULL,
    appearance TEXT NULL,
    target_audience TEXT NULL,
    use_scenarios TEXT NOT NULL DEFAULT '[]',
    selling_points TEXT NOT NULL DEFAULT '[]',
    core_functions TEXT NOT NULL DEFAULT '[]',
    uncertain_points TEXT NOT NULL DEFAULT '[]',
    prohibited_claims TEXT NOT NULL DEFAULT '[]',
    product_description TEXT NULL,
    brand_notes TEXT NULL,
    creative_brief TEXT NULL,
    screen_plan TEXT NOT NULL DEFAULT '[]',
    source_image_url TEXT NOT NULL,
    source_image_backup_url TEXT NULL,
    platform TEXT NOT NULL,
    language TEXT NOT NULL DEFAULT 'zh-CN',
    market_research_json TEXT NULL,
    market_research_query TEXT NULL,
    market_research_updated_at TEXT NULL,
    status TEXT NOT NULL DEFAULT 'draft',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (merchant_id) REFERENCES merchants(id),
    FOREIGN KEY (created_by_user_id) REFERENCES merchant_users(id),
    FOREIGN KEY (sku_id) REFERENCES skus(id)
);
CREATE INDEX IF NOT EXISTS idx_detail_projects_merchant ON detail_page_projects(merchant_id, id);
CREATE INDEX IF NOT EXISTS idx_detail_projects_sku ON detail_page_projects(sku_id, id);

CREATE TABLE IF NOT EXISTS market_research_cache (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    merchant_id INTEGER NOT NULL,
    sku_id INTEGER NULL,
    query_hash TEXT NOT NULL,
    query_text TEXT NOT NULL,
    result_json TEXT NOT NULL,
    source_count INTEGER NOT NULL DEFAULT 0,
    provider_credits INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (merchant_id, query_hash),
    FOREIGN KEY (merchant_id) REFERENCES merchants(id),
    FOREIGN KEY (sku_id) REFERENCES skus(id)
);
CREATE INDEX IF NOT EXISTS idx_market_research_expiry ON market_research_cache(expires_at);

CREATE TABLE IF NOT EXISTS detail_page_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    merchant_id INTEGER NOT NULL,
    role TEXT NOT NULL,
    content TEXT NOT NULL,
    request_id TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (project_id) REFERENCES detail_page_projects(id),
    FOREIGN KEY (merchant_id) REFERENCES merchants(id)
);
CREATE INDEX IF NOT EXISTS idx_detail_messages_project ON detail_page_messages(project_id, id);

CREATE TABLE IF NOT EXISTS generation_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    merchant_id INTEGER NOT NULL,
    sku_id INTEGER NULL,
    detail_page_project_id INTEGER NULL,
    bundle_no TEXT NULL,
    bundle_part TEXT NULL,
    job_no TEXT NOT NULL UNIQUE,
    client_submission_id TEXT NULL,
    submission_payload_hash TEXT NULL,
    output_kind TEXT NOT NULL DEFAULT 'image',
    platform TEXT NOT NULL,
    language TEXT NOT NULL DEFAULT 'zh-CN',
    asset_types TEXT NOT NULL DEFAULT '[]',
    custom_material_requests TEXT NULL,
    task_brief TEXT NULL,
    task_context_json TEXT NULL,
    submitted_by_user_id INTEGER NULL,
    submitted_ip TEXT NULL,
    safety_status TEXT NOT NULL DEFAULT 'pending',
    safety_attempts INTEGER NOT NULL DEFAULT 0,
    safety_next_retry_at TEXT NULL,
    safety_checked_at TEXT NULL,
    status TEXT NOT NULL DEFAULT 'pending_gateway',
    estimated_credits INTEGER NOT NULL DEFAULT 0,
    actual_credits INTEGER NOT NULL DEFAULT 0,
    progress_current INTEGER NOT NULL DEFAULT 0,
    progress_total INTEGER NOT NULL DEFAULT 0,
    progress_stage TEXT NOT NULL DEFAULT 'queued',
    progress_message TEXT NULL,
    model TEXT NULL,
    requested_model_id INTEGER NULL,
    resolved_ai_model_id INTEGER NULL,
    route_snapshot_json TEXT NULL,
    pricing_snapshot_json TEXT NULL,
    new_api_request_id TEXT NULL,
    error_message TEXT NULL,
    merchant_hidden_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (merchant_id) REFERENCES merchants(id),
    FOREIGN KEY (sku_id) REFERENCES skus(id)
    ,FOREIGN KEY (detail_page_project_id) REFERENCES detail_page_projects(id)
);
CREATE INDEX IF NOT EXISTS idx_generation_jobs_bundle ON generation_jobs(bundle_no, bundle_part);
CREATE INDEX IF NOT EXISTS idx_generation_jobs_safety ON generation_jobs(status, safety_status, safety_next_retry_at, id);
CREATE UNIQUE INDEX IF NOT EXISTS uk_generation_jobs_submission ON generation_jobs(merchant_id, client_submission_id);

CREATE TABLE IF NOT EXISTS generation_job_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id INTEGER NOT NULL,
    merchant_id INTEGER NOT NULL,
    position INTEGER NOT NULL,
    asset_type TEXT NOT NULL,
    occurrence INTEGER NOT NULL DEFAULT 1,
    type_total INTEGER NOT NULL DEFAULT 1,
    estimated_credits INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'queued',
    attempts INTEGER NOT NULL DEFAULT 0,
    manual_retry_count INTEGER NOT NULL DEFAULT 0,
    next_retry_at TEXT NULL,
    last_retry_reason TEXT NULL,
    worker_slot INTEGER NULL,
    reservation_ledger_id INTEGER NULL,
    asset_id INTEGER NULL,
    request_id TEXT NULL,
    error_message TEXT NULL,
    stage_timestamps_json TEXT NOT NULL DEFAULT '{}',
    started_at TEXT NULL,
    completed_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (job_id, position),
    FOREIGN KEY (job_id) REFERENCES generation_jobs(id),
    FOREIGN KEY (merchant_id) REFERENCES merchants(id)
);
CREATE INDEX IF NOT EXISTS idx_generation_items_queue ON generation_job_items(status, id);
CREATE INDEX IF NOT EXISTS idx_generation_items_retry ON generation_job_items(status, next_retry_at, id);
CREATE INDEX IF NOT EXISTS idx_generation_items_job ON generation_job_items(job_id, status, id);
CREATE INDEX IF NOT EXISTS idx_generation_items_merchant ON generation_job_items(merchant_id, status, id);

CREATE TABLE IF NOT EXISTS assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    merchant_id INTEGER NOT NULL,
    sku_id INTEGER NULL,
    job_id INTEGER NOT NULL,
    generation_item_id INTEGER NULL,
    asset_type TEXT NOT NULL,
    media_type TEXT NOT NULL DEFAULT 'image',
    mime_type TEXT NULL,
    duration_seconds INTEGER NULL,
    poster_url TEXT NULL,
    width INTEGER NULL,
    height INTEGER NULL,
    file_url TEXT NOT NULL,
    backup_file_url TEXT NULL,
    score INTEGER NULL,
    is_favorite INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'active',
    deleted_at TEXT NULL,
    purged_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (merchant_id) REFERENCES merchants(id),
    FOREIGN KEY (sku_id) REFERENCES skus(id),
    FOREIGN KEY (job_id) REFERENCES generation_jobs(id),
    FOREIGN KEY (generation_item_id) REFERENCES generation_job_items(id)
);
CREATE INDEX IF NOT EXISTS idx_assets_trash_cleanup ON assets(status, deleted_at, id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_assets_generation_item ON assets(generation_item_id) WHERE generation_item_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS video_generation_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id INTEGER NOT NULL UNIQUE,
    merchant_id INTEGER NOT NULL,
    sku_id INTEGER NULL,
    source_asset_id INTEGER NULL,
    reference_image_url TEXT NULL,
    reference_image_backup_url TEXT NULL,
    prompt TEXT NOT NULL,
    motion_preset TEXT NOT NULL DEFAULT 'product_showcase',
    ratio TEXT NOT NULL DEFAULT '9:16',
    resolution TEXT NOT NULL DEFAULT '720p',
    duration_seconds INTEGER NOT NULL DEFAULT 5,
    generate_audio INTEGER NOT NULL DEFAULT 0,
    provider_request_id TEXT NULL,
    provider_status TEXT NOT NULL DEFAULT 'queued',
    provider_progress INTEGER NOT NULL DEFAULT 0,
    submit_attempts INTEGER NOT NULL DEFAULT 0,
    poll_attempts INTEGER NOT NULL DEFAULT 0,
    next_poll_at TEXT NULL,
    last_polled_at TEXT NULL,
    video_url TEXT NULL,
    last_frame_url TEXT NULL,
    request_payload_json TEXT NULL,
    result_payload_json TEXT NULL,
    reservation_ledger_id INTEGER NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (job_id) REFERENCES generation_jobs(id),
    FOREIGN KEY (merchant_id) REFERENCES merchants(id),
    FOREIGN KEY (sku_id) REFERENCES skus(id),
    FOREIGN KEY (source_asset_id) REFERENCES assets(id)
);
CREATE INDEX IF NOT EXISTS idx_video_requests_queue ON video_generation_requests(provider_status, next_poll_at, id);
CREATE INDEX IF NOT EXISTS idx_video_requests_merchant ON video_generation_requests(merchant_id, id);

CREATE TABLE IF NOT EXISTS canvas_edits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id INTEGER NOT NULL UNIQUE,
    source_asset_id INTEGER NOT NULL,
    mode TEXT NOT NULL DEFAULT 'global',
    preset TEXT NOT NULL DEFAULT 'custom',
    instruction TEXT NOT NULL,
    mask_file TEXT NULL,
    output_preset TEXT NOT NULL DEFAULT 'square_1_1',
    output_width INTEGER NOT NULL DEFAULT 1024,
    output_height INTEGER NOT NULL DEFAULT 1024,
    resize_mode TEXT NOT NULL DEFAULT 'extend',
    operation TEXT NOT NULL DEFAULT 'ai_edit',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (job_id) REFERENCES generation_jobs(id),
    FOREIGN KEY (source_asset_id) REFERENCES assets(id)
);

CREATE INDEX IF NOT EXISTS idx_canvas_edits_source ON canvas_edits(source_asset_id);

CREATE TABLE IF NOT EXISTS canvas_workspaces (
    merchant_id INTEGER PRIMARY KEY,
    graph_json TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (merchant_id) REFERENCES merchants(id)
);

CREATE TABLE IF NOT EXISTS creative_projects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    merchant_id INTEGER NOT NULL,
    created_by_user_id INTEGER NULL,
    sku_id INTEGER NULL,
    title TEXT NOT NULL,
    project_type TEXT NOT NULL DEFAULT 'image',
    status TEXT NOT NULL DEFAULT 'active',
    cover_asset_id INTEGER NULL,
    document_json TEXT NOT NULL,
    document_hash TEXT NOT NULL,
    document_version INTEGER NOT NULL DEFAULT 1,
    current_revision_no INTEGER NOT NULL DEFAULT 1,
    legacy_canvas_migrated INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (merchant_id) REFERENCES merchants(id),
    FOREIGN KEY (created_by_user_id) REFERENCES merchant_users(id) ON DELETE SET NULL,
    FOREIGN KEY (sku_id) REFERENCES skus(id) ON DELETE SET NULL,
    FOREIGN KEY (cover_asset_id) REFERENCES assets(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_creative_projects_merchant ON creative_projects(merchant_id, status, updated_at, id);
CREATE INDEX IF NOT EXISTS idx_creative_projects_sku ON creative_projects(sku_id, status, id);

CREATE TABLE IF NOT EXISTS creative_project_revisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    revision_no INTEGER NOT NULL,
    schema_version INTEGER NOT NULL DEFAULT 2,
    source TEXT NOT NULL DEFAULT 'auto',
    document_hash TEXT NOT NULL,
    document_json TEXT NOT NULL,
    created_by_user_id INTEGER NULL,
    created_at TEXT NOT NULL,
    UNIQUE(project_id, revision_no),
    FOREIGN KEY (project_id) REFERENCES creative_projects(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_user_id) REFERENCES merchant_users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_creative_project_revisions_created ON creative_project_revisions(project_id, created_at, id);

CREATE TABLE IF NOT EXISTS creative_project_assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    asset_id INTEGER NOT NULL,
    node_uuid TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'source',
    created_at TEXT NOT NULL,
    UNIQUE(project_id, node_uuid),
    FOREIGN KEY (project_id) REFERENCES creative_projects(id) ON DELETE CASCADE,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_creative_project_assets_asset ON creative_project_assets(asset_id, project_id);

CREATE TABLE IF NOT EXISTS creative_project_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    node_uuid TEXT NULL,
    job_id INTEGER NOT NULL UNIQUE,
    run_type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued',
    input_snapshot_json TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (project_id) REFERENCES creative_projects(id) ON DELETE CASCADE,
    FOREIGN KEY (job_id) REFERENCES generation_jobs(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_creative_project_runs_project ON creative_project_runs(project_id, status, id);

CREATE TABLE IF NOT EXISTS workspace_project_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    merchant_id INTEGER NOT NULL,
    project_type TEXT NOT NULL,
    project_id INTEGER NOT NULL,
    custom_name TEXT NULL,
    pinned INTEGER NOT NULL DEFAULT 0,
    hidden INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (merchant_id, project_type, project_id),
    FOREIGN KEY (merchant_id) REFERENCES merchants(id)
);

CREATE INDEX IF NOT EXISTS idx_workspace_project_sidebar ON workspace_project_preferences(merchant_id, hidden, pinned, updated_at);

CREATE TABLE IF NOT EXISTS free_generation_jobs (
    job_id INTEGER PRIMARY KEY,
    prompt TEXT NOT NULL,
    prompt_template_id INTEGER NULL,
    prompt_template_code TEXT NULL,
    prompt_template_name TEXT NULL,
    prompt_template_content TEXT NULL,
    subject_text TEXT NULL,
    user_addition TEXT NULL,
    reference_image_url TEXT NULL,
    reference_image_backup_url TEXT NULL,
    reference_images_json TEXT NOT NULL DEFAULT '[]',
    reference_count INTEGER NOT NULL DEFAULT 0,
    reference_fingerprints_json TEXT NOT NULL DEFAULT '[]',
    outbound_reference_sha256 TEXT NULL,
    output_preset TEXT NOT NULL DEFAULT 'square_1_1',
    output_width INTEGER NOT NULL DEFAULT 1024,
    output_height INTEGER NOT NULL DEFAULT 1024,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (job_id) REFERENCES generation_jobs(id)
);

CREATE TABLE IF NOT EXISTS credit_ledger (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    merchant_id INTEGER NOT NULL,
    type TEXT NOT NULL,
    amount INTEGER NOT NULL,
    balance_after INTEGER NOT NULL,
    source TEXT NOT NULL,
    reference_type TEXT NULL,
    reference_id TEXT NULL,
    new_api_request_id TEXT NULL,
    operator_admin_id INTEGER NULL,
    note TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (merchant_id) REFERENCES merchants(id),
    FOREIGN KEY (operator_admin_id) REFERENCES admin_users(id)
);

CREATE TABLE IF NOT EXISTS usage_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    merchant_id INTEGER NOT NULL,
    job_id INTEGER NULL,
    new_api_request_id TEXT NULL UNIQUE,
    model TEXT NULL,
    provider TEXT NULL,
    business_credits INTEGER NOT NULL DEFAULT 0,
    gateway_cost NUMERIC NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending',
    occurred_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (merchant_id) REFERENCES merchants(id),
    FOREIGN KEY (job_id) REFERENCES generation_jobs(id)
);

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL,
    is_secret INTEGER NOT NULL DEFAULT 0,
    updated_by INTEGER NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (updated_by) REFERENCES admin_users(id)
);

CREATE TABLE IF NOT EXISTS storage_migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_type TEXT NOT NULL,
    source_id INTEGER NOT NULL,
    merchant_id INTEGER NOT NULL,
    source_url TEXT NOT NULL,
    target_url TEXT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    completed_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (source_type, source_id)
);

CREATE TABLE IF NOT EXISTS email_verification_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    purpose TEXT NOT NULL,
    user_id INTEGER NULL,
    code_hash TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT NOT NULL,
    consumed_at TEXT NULL,
    requested_ip TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES merchant_users(id)
);

CREATE TABLE IF NOT EXISTS redemption_card_batches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    batch_no TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL,
    credits INTEGER NOT NULL,
    total_count INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    expires_at TEXT NULL,
    created_by_admin_id INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (created_by_admin_id) REFERENCES admin_users(id)
);

CREATE TABLE IF NOT EXISTS redemption_cards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    batch_id INTEGER NOT NULL,
    code_hash TEXT NOT NULL UNIQUE,
    code_ciphertext TEXT NOT NULL,
    code_last4 TEXT NOT NULL,
    credits INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'unused',
    redeemed_by_merchant_id INTEGER NULL,
    redeemed_by_user_id INTEGER NULL,
    redeemed_at TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (batch_id) REFERENCES redemption_card_batches(id),
    FOREIGN KEY (redeemed_by_merchant_id) REFERENCES merchants(id),
    FOREIGN KEY (redeemed_by_user_id) REFERENCES merchant_users(id)
);

CREATE TABLE IF NOT EXISTS content_moderation_checks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    merchant_id INTEGER NULL,
    user_id INTEGER NULL,
    content_type TEXT NOT NULL,
    content_hash TEXT NOT NULL,
    source TEXT NOT NULL,
    status TEXT NOT NULL,
    categories TEXT NOT NULL,
    reason TEXT NULL,
    model TEXT NULL,
    request_id TEXT NULL,
    cache_hit_count INTEGER NOT NULL DEFAULT 0,
    last_cache_hit_at TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (merchant_id) REFERENCES merchants(id),
    FOREIGN KEY (user_id) REFERENCES merchant_users(id)
);

CREATE TABLE IF NOT EXISTS content_violations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    merchant_id INTEGER NOT NULL,
    user_id INTEGER NULL,
    content_type TEXT NOT NULL,
    content_hash TEXT NOT NULL,
    source TEXT NOT NULL,
    categories TEXT NOT NULL,
    reason TEXT NULL,
    content_excerpt TEXT NULL,
    penalty_credits INTEGER NOT NULL DEFAULT 0,
    warning_email_status TEXT NOT NULL DEFAULT 'pending',
    warning_error TEXT NULL,
    ip_address TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (merchant_id, content_type, content_hash),
    FOREIGN KEY (merchant_id) REFERENCES merchants(id),
    FOREIGN KEY (user_id) REFERENCES merchant_users(id)
);

CREATE TABLE IF NOT EXISTS login_throttle_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    scope TEXT NOT NULL,
    key_type TEXT NOT NULL,
    key_hash TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS prompt_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    scene TEXT NOT NULL,
    platform TEXT NOT NULL DEFAULT '*',
    asset_type TEXT NOT NULL DEFAULT '*',
    content TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    is_default INTEGER NOT NULL DEFAULT 0,
    is_system INTEGER NOT NULL DEFAULT 0,
    is_customized INTEGER NOT NULL DEFAULT 0,
    created_by_admin_id INTEGER NULL,
    updated_by_admin_id INTEGER NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_prompt_templates_resolve ON prompt_templates(scene, platform, asset_type, status, is_default);

CREATE TABLE IF NOT EXISTS product_feedback (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    merchant_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    module TEXT NOT NULL,
    rating INTEGER NOT NULL,
    issue_type TEXT NOT NULL,
    improvement_type TEXT NOT NULL,
    desired_improvement TEXT NOT NULL,
    usage_context TEXT NULL,
    related_reference TEXT NULL,
    allow_contact INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'new',
    priority TEXT NOT NULL DEFAULT 'normal',
    admin_note TEXT NULL,
    handled_by_admin_id INTEGER NULL,
    handled_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (merchant_id) REFERENCES merchants(id),
    FOREIGN KEY (user_id) REFERENCES merchant_users(id),
    FOREIGN KEY (handled_by_admin_id) REFERENCES admin_users(id)
);

CREATE INDEX IF NOT EXISTS idx_product_feedback_status ON product_feedback(status, priority, id);
CREATE INDEX IF NOT EXISTS idx_product_feedback_merchant ON product_feedback(merchant_id, id);
CREATE INDEX IF NOT EXISTS idx_product_feedback_improvement ON product_feedback(improvement_type, id);

CREATE TABLE IF NOT EXISTS image_tool_results (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    merchant_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    asset_id INTEGER NOT NULL,
    result_type TEXT NOT NULL,
    content TEXT NOT NULL,
    model TEXT NULL,
    request_id TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (merchant_id) REFERENCES merchants(id),
    FOREIGN KEY (user_id) REFERENCES merchant_users(id),
    FOREIGN KEY (asset_id) REFERENCES assets(id)
);
CREATE INDEX IF NOT EXISTS idx_image_tool_results_merchant ON image_tool_results(merchant_id, result_type, id DESC);
CREATE INDEX IF NOT EXISTS idx_image_tool_results_asset ON image_tool_results(asset_id, id DESC);

CREATE TABLE IF NOT EXISTS asset_batch_labels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    merchant_id INTEGER NOT NULL,
    batch_key TEXT NOT NULL,
    label TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (merchant_id, batch_key),
    FOREIGN KEY (merchant_id) REFERENCES merchants(id)
);

CREATE TABLE IF NOT EXISTS ai_models (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    gateway TEXT NOT NULL DEFAULT 'sub2api', model_id TEXT NOT NULL, display_name TEXT NOT NULL,
    provider TEXT NULL, protocol TEXT NOT NULL DEFAULT 'chat', capabilities_json TEXT NOT NULL DEFAULT '[]',
    input_modalities_json TEXT NOT NULL DEFAULT '[]', output_modalities_json TEXT NOT NULL DEFAULT '[]',
    limits_json TEXT NOT NULL DEFAULT '{}', adapter_config_json TEXT NOT NULL DEFAULT '{}', catalog_payload_json TEXT NULL,
    user_selectable INTEGER NOT NULL DEFAULT 0, enabled INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0,
    health_status TEXT NOT NULL DEFAULT 'unknown', last_error TEXT NULL, last_tested_at TEXT NULL,
    source_visible INTEGER NOT NULL DEFAULT 1, source_last_seen_at TEXT NULL,
    lifecycle_status TEXT NOT NULL DEFAULT 'configured', archived_at TEXT NULL,
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (gateway, model_id)
);
CREATE INDEX IF NOT EXISTS idx_ai_models_enabled ON ai_models(enabled, sort_order, id);

CREATE TABLE IF NOT EXISTS ai_capability_routes (
    id INTEGER PRIMARY KEY AUTOINCREMENT, capability_code TEXT NOT NULL UNIQUE,
    primary_model_id INTEGER NULL, fallback_model_id INTEGER NULL,
    routing_strategy TEXT NOT NULL DEFAULT 'primary_fallback', route_config_json TEXT NOT NULL DEFAULT '{}',
    enabled INTEGER NOT NULL DEFAULT 1, updated_by_admin_id INTEGER NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS ai_model_prices (
    id INTEGER PRIMARY KEY AUTOINCREMENT, ai_model_id INTEGER NOT NULL, capability_code TEXT NOT NULL,
    unit_type TEXT NOT NULL DEFAULT 'request', unit_value NUMERIC NOT NULL DEFAULT 1, credits INTEGER NOT NULL DEFAULT 0,
    config_json TEXT NOT NULL DEFAULT '{}', enabled INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ai_model_prices_lookup ON ai_model_prices(ai_model_id, capability_code, enabled);

CREATE TABLE IF NOT EXISTS ai_model_calls (
    id INTEGER PRIMARY KEY AUTOINCREMENT, merchant_id INTEGER NULL, job_id INTEGER NULL,
    capability_code TEXT NOT NULL, ai_model_id INTEGER NULL,
    request_id TEXT NULL, status TEXT NOT NULL, attempt_no INTEGER NULL,
    route_role TEXT NULL, protocol TEXT NULL, http_status INTEGER NULL, duration_ms INTEGER NULL,
    input_units NUMERIC NULL, output_units NUMERIC NULL, estimated_cost NUMERIC NULL, actual_cost NUMERIC NULL,
    error_code TEXT NULL, error_message TEXT NULL, prompt_hash TEXT NULL,
    started_at TEXT NULL, finished_at TEXT NULL, created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ai_model_calls_capability ON ai_model_calls(capability_code, created_at);
CREATE INDEX IF NOT EXISTS idx_ai_model_calls_model ON ai_model_calls(ai_model_id, status, created_at);
CREATE INDEX IF NOT EXISTS idx_ai_model_calls_job ON ai_model_calls(job_id, id);

CREATE TABLE IF NOT EXISTS ai_model_sync_batches (
    id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT NOT NULL DEFAULT 'sub2api', status TEXT NOT NULL,
    discovered_count INTEGER NOT NULL DEFAULT 0, new_count INTEGER NOT NULL DEFAULT 0, missing_count INTEGER NOT NULL DEFAULT 0,
    summary_json TEXT NOT NULL DEFAULT '{}', initiated_by_admin_id INTEGER NULL, started_at TEXT NOT NULL, completed_at TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_ai_model_sync_batches_started ON ai_model_sync_batches(started_at, id);

CREATE TABLE IF NOT EXISTS ai_model_capabilities (
    id INTEGER PRIMARY KEY AUTOINCREMENT, ai_model_id INTEGER NOT NULL, capability_code TEXT NOT NULL,
    confirmation_status TEXT NOT NULL DEFAULT 'suggested', test_status TEXT NOT NULL DEFAULT 'pending',
    suggested_by TEXT NOT NULL DEFAULT 'catalog', confirmed_by_admin_id INTEGER NULL, last_test_id INTEGER NULL,
    notes TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (ai_model_id, capability_code)
);
CREATE INDEX IF NOT EXISTS idx_ai_model_capabilities_status ON ai_model_capabilities(capability_code, confirmation_status, test_status);

CREATE TABLE IF NOT EXISTS ai_capability_tests (
    id INTEGER PRIMARY KEY AUTOINCREMENT, ai_model_id INTEGER NOT NULL, capability_code TEXT NOT NULL,
    test_scope TEXT NOT NULL, product_code TEXT NULL, execution_channel TEXT NOT NULL DEFAULT 'direct',
    status TEXT NOT NULL, request_id TEXT NULL, duration_ms INTEGER NOT NULL DEFAULT 0, paid_confirmed INTEGER NOT NULL DEFAULT 0,
    result_json TEXT NOT NULL DEFAULT '{}', tested_by_admin_id INTEGER NULL, created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ai_capability_tests_lookup ON ai_capability_tests(ai_model_id, capability_code, created_at);
CREATE INDEX IF NOT EXISTS idx_ai_capability_tests_business ON ai_capability_tests(product_code, execution_channel, status);

CREATE TABLE IF NOT EXISTS ai_business_compatibilities (
    id INTEGER PRIMARY KEY AUTOINCREMENT, ai_model_id INTEGER NOT NULL, capability_code TEXT NOT NULL,
    product_code TEXT NOT NULL, execution_channel TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending',
    last_test_id INTEGER NULL, notes TEXT NULL, updated_by_admin_id INTEGER NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
    UNIQUE (ai_model_id, capability_code, product_code, execution_channel)
);
CREATE INDEX IF NOT EXISTS idx_ai_business_compatibilities_ready ON ai_business_compatibilities(product_code, capability_code, status);

CREATE TABLE IF NOT EXISTS media_workflow_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    engine TEXT NOT NULL DEFAULT 'comfyui',
    media_type TEXT NOT NULL DEFAULT 'video',
    version INTEGER NOT NULL DEFAULT 1,
    workflow_json TEXT NOT NULL,
    input_map_json TEXT NOT NULL DEFAULT '{}',
    output_map_json TEXT NOT NULL DEFAULT '{}',
    workflow_hash TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 0,
    last_test_status TEXT NOT NULL DEFAULT 'untested',
    last_test_error TEXT NULL,
    last_tested_at TEXT NULL,
    updated_by_admin_id INTEGER NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_media_workflow_enabled ON media_workflow_templates(enabled, media_type, code);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_type TEXT NOT NULL,
    actor_id INTEGER NOT NULL,
    action TEXT NOT NULL,
    target_type TEXT NOT NULL,
    target_id TEXT NULL,
    ip_address TEXT NOT NULL,
    details TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS admin_navigation_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id INTEGER NOT NULL,
    path TEXT NOT NULL,
    title TEXT NOT NULL,
    is_favorite INTEGER NOT NULL DEFAULT 0,
    last_visited_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (admin_id, path),
    FOREIGN KEY (admin_id) REFERENCES admin_users(id)
);
CREATE INDEX IF NOT EXISTS idx_admin_navigation_recent ON admin_navigation_preferences(admin_id, last_visited_at);

CREATE TABLE IF NOT EXISTS admin_onboarding_progress (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id INTEGER NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'pending',
    current_step TEXT NOT NULL DEFAULT 'welcome',
    step_state_json TEXT NOT NULL DEFAULT '{}',
    started_at TEXT NULL,
    completed_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (admin_id) REFERENCES admin_users(id)
);
CREATE INDEX IF NOT EXISTS idx_admin_onboarding_status ON admin_onboarding_progress(status, updated_at);

CREATE INDEX IF NOT EXISTS idx_skus_merchant ON skus(merchant_id);
CREATE INDEX IF NOT EXISTS idx_jobs_merchant_status ON generation_jobs(merchant_id, status);
CREATE INDEX IF NOT EXISTS idx_assets_merchant_status ON assets(merchant_id, status);
CREATE INDEX IF NOT EXISTS idx_ledger_merchant_created ON credit_ledger(merchant_id, created_at);
CREATE INDEX IF NOT EXISTS idx_usage_merchant_created ON usage_records(merchant_id, created_at);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_system_settings_updated ON system_settings(updated_at);
CREATE INDEX IF NOT EXISTS idx_storage_migration_status ON storage_migrations(status, id);
CREATE INDEX IF NOT EXISTS idx_email_codes_lookup ON email_verification_codes(email, purpose, created_at);
CREATE INDEX IF NOT EXISTS idx_email_codes_ip ON email_verification_codes(requested_ip, created_at);
CREATE INDEX IF NOT EXISTS idx_card_batches_created ON redemption_card_batches(created_at);
CREATE INDEX IF NOT EXISTS idx_cards_batch_status ON redemption_cards(batch_id, status);
CREATE INDEX IF NOT EXISTS idx_cards_redeemed_merchant ON redemption_cards(redeemed_by_merchant_id, redeemed_at);
CREATE INDEX IF NOT EXISTS idx_moderation_checks_hash ON content_moderation_checks(content_hash, status);
CREATE INDEX IF NOT EXISTS idx_content_violations_created ON content_violations(created_at);
CREATE INDEX IF NOT EXISTS idx_login_throttle_lookup ON login_throttle_events(scope, key_type, key_hash, created_at);
