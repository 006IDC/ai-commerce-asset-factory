<?php

declare(strict_types=1);

$baseUrl = rtrim((string) ($argv[1] ?? 'http://127.0.0.1:8089'), '/');
$root = rtrim((string) ($argv[2] ?? dirname(__DIR__)), DIRECTORY_SEPARATOR);
$cookieJar = sys_get_temp_dir() . '/asset-factory-install-' . getmypid() . '.cookies';

function request(string $method, string $path, array $data = []): array
{
    global $baseUrl, $cookieJar;
    $curl = curl_init($baseUrl . $path);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_POSTFIELDS => $data === [] ? null : http_build_query($data),
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($curl);
    if ($response === false) {
        throw new RuntimeException(curl_error($curl));
    }
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    return [
        'status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
        'headers' => substr($response, 0, $headerSize),
        'body' => substr($response, $headerSize),
    ];
}

function csrf(string $html): string
{
    if (!preg_match('/name="_token" value="([^"]+)"/', $html, $matches)) {
        throw new RuntimeException('CSRF token not found.');
    }
    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function expectStatus(array $response, int $status, string $label): void
{
    if ($response['status'] !== $status) {
        throw new RuntimeException("{$label}: expected {$status}, got {$response['status']}");
    }
    echo "[ok] {$label}\n";
}

try {
    $home = request('GET', '/');
    expectStatus($home, 302, 'fresh package redirects to installer');
    if (!preg_match('/^Location:\s*\/install\s*$/mi', $home['headers'])) {
        throw new RuntimeException('Installer redirect location is incorrect.');
    }

    $page = request('GET', '/install');
    expectStatus($page, 200, 'installer page');
    if (!str_contains($page['body'], '初始化业务系统')) {
        throw new RuntimeException('Installer form did not render.');
    }

    $installed = request('POST', '/install', [
        '_token' => csrf($page['body']),
        'db_driver' => 'sqlite',
        'admin_name' => '安装测试管理员',
        'admin_email' => 'installer@example.com',
        'admin_password' => 'InstallerTest!2026',
        'admin_password_confirmation' => 'InstallerTest!2026',
    ]);
    expectStatus($installed, 302, 'installer submission');
    if (!preg_match('/^Location:\s*\/admin\/login\s*$/mi', $installed['headers'])) {
        throw new RuntimeException('Installer completion redirect is incorrect.');
    }

    foreach (['.env', 'runtime/install.lock', 'database/asset_factory.sqlite'] as $file) {
        if (!is_file($root . DIRECTORY_SEPARATOR . $file)) {
            throw new RuntimeException("Installer did not create {$file}.");
        }
    }
    echo "[ok] installer files created\n";
    $pdo = new PDO('sqlite:' . $root . '/database/asset_factory.sqlite');
    if ((int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() !== 1) {
        throw new RuntimeException('Initial administrator was not created.');
    }
    echo "[ok] initial administrator created\n";
    $skuColumns = array_column($pdo->query('PRAGMA table_info(skus)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['creative_brief', 'launch_targets_json', 'launch_direction', 'product_description',
        'preferred_image_ratio', 'preferred_image_model', 'reference_images_json', 'search_terms',
        'report_materials', 'report_appearance', 'report_target_audience', 'report_use_scenarios',
        'report_core_functions', 'report_uncertain_points', 'report_prohibited_claims', 'report_brand_notes'] as $column) {
        if (!in_array($column, $skuColumns, true)) {
            throw new RuntimeException("Fresh installer is missing skus.{$column}.");
        }
    }
    foreach (['fact_response_state_json', 'foundation_constraints', 'product_profile_json', 'product_profile_revision', 'product_profile_confirmed_at'] as $retiredColumn) {
        if (in_array($retiredColumn, $skuColumns, true)) {
            throw new RuntimeException("Fresh installer retained skus.{$retiredColumn}.");
        }
    }
    echo "[ok] compact SKU report fields installed without product contracts\n";
    $detailProjectColumns = array_column($pdo->query('PRAGMA table_info(detail_page_projects)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['design_system_json', 'design_system_version_id'] as $retiredColumn) {
        if (in_array($retiredColumn, $detailProjectColumns, true)) {
            throw new RuntimeException("Fresh installer retained detail_page_projects.{$retiredColumn}.");
        }
    }
    echo "[ok] retired detail-page style lock fields omitted\n";
    $freeGenerationColumns = array_column($pdo->query('PRAGMA table_info(free_generation_jobs)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['reference_images_json', 'reference_count', 'reference_fingerprints_json', 'outbound_reference_sha256', 'subject_text', 'user_addition'] as $column) {
        if (!in_array($column, $freeGenerationColumns, true)) {
            throw new RuntimeException("Fresh installer is missing free_generation_jobs.{$column}.");
        }
    }
    echo "[ok] free creation multi-reference fields installed\n";
    $visionColumns = array_column($pdo->query('PRAGMA table_info(vision_tasks)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach ([
        'progress_percent',
        'progress_stage',
        'progress_message',
        'diagnostic_json',
        'input_image_count',
        'original_input_bytes',
        'normalized_input_bytes',
        'client_submission_id',
    ] as $column) {
        if (!in_array($column, $visionColumns, true)) {
            throw new RuntimeException("Fresh installer is missing vision_tasks.{$column}.");
        }
    }
    foreach (['vision_route_snapshot_json', 'planner_route_snapshot_json', 'stage_metrics_json',
        'planner_model', 'planner_request_id', 'planner_attempts', 'profile_ready_at', 'creative_state',
        'facts_snapshot_json', 'intent_snapshot_json', 'creative_snapshot_json',
        'readiness_missing_json', 'readiness_blockers_json', 'creative_ready_at'] as $retiredColumn) {
        if (in_array($retiredColumn, $visionColumns, true)) {
            throw new RuntimeException("Fresh installer retained vision_tasks.{$retiredColumn}.");
        }
    }
    $visionSubmissionIndexes = $pdo->query("PRAGMA index_list('vision_tasks')")->fetchAll(PDO::FETCH_ASSOC);
    $visionSubmissionIndex = array_values(array_filter($visionSubmissionIndexes, static fn (array $index): bool => (string) ($index['name'] ?? '') === 'uk_vision_tasks_submission'));
    if ($visionSubmissionIndex === [] || (int) ($visionSubmissionIndex[0]['unique'] ?? 0) !== 1) {
        throw new RuntimeException('Fresh installer is missing the unique product-launch submission index.');
    }
    echo "[ok] direct product analysis queue installed without staged contract snapshots\n";
    $jobColumns = array_column($pdo->query('PRAGMA table_info(generation_jobs)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['foundation_snapshot_json', 'creative_brief_json', 'generation_plan_json', 'creative_output_mode', 'visual_profile_ready', 'visual_profile_readiness_json'] as $retiredColumn) {
        if (in_array($retiredColumn, $jobColumns, true)) {
            throw new RuntimeException("Fresh installer retained generation_jobs.{$retiredColumn}.");
        }
    }
    echo "[ok] retired generation plan and marketing-contract fields omitted\n";
    $itemColumns = array_column($pdo->query('PRAGMA table_info(generation_job_items)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach ([
        'manual_retry_count',
        'next_retry_at',
        'last_retry_reason',
        'charged_credits',
        'consume_ledger_id',
        'technical_refund_ledger_id',
        'quality_refund_ledger_id',
        'stage_timestamps_json',
    ] as $column) {
        if (!in_array($column, $itemColumns, true)) {
            throw new RuntimeException("Fresh installer is missing generation_job_items.{$column}.");
        }
    }
    foreach (['auto_retry_count', 'prompt_snapshot_json', 'workflow_snapshot_json', 'product_code', 'routing_policy_json', 'prompt_snapshot_version', 'compiled_prompt_hash', 'text_render_mode', 'quality_status', 'quality_reason_codes_json', 'quality_evidence_json'] as $retiredColumn) {
        if (in_array($retiredColumn, $itemColumns, true)) {
            throw new RuntimeException("Fresh installer retained generation_job_items.{$retiredColumn}.");
        }
    }
    echo "[ok] manual retry and billing fields installed without retired quality contracts\n";
    $merchantUserColumns = array_column($pdo->query('PRAGMA table_info(merchant_users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['preferred_generation_platform', 'preferred_generation_language'] as $column) {
        if (!in_array($column, $merchantUserColumns, true)) {
            throw new RuntimeException("Fresh installer is missing merchant_users.{$column}.");
        }
    }
    echo "[ok] merchant generation preferences installed\n";
    $planColumns = array_column($pdo->query('PRAGMA table_info(subscription_plans)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    $subscriptionColumns = array_column($pdo->query('PRAGMA table_info(merchant_subscriptions)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['audience_label', 'sku_limit', 'batch_image_limit', 'concurrent_job_limit'] as $column) {
        if (!in_array($column, $planColumns, true) || !in_array($column, $subscriptionColumns, true)) {
            throw new RuntimeException("Fresh installer is missing subscription entitlement column {$column}.");
        }
    }
    $generationJobColumns = array_column($pdo->query('PRAGMA table_info(generation_jobs)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['bundle_no', 'bundle_part', 'custom_material_requests', 'output_kind', 'requested_model_id', 'resolved_ai_model_id', 'route_snapshot_json', 'pricing_snapshot_json', 'client_submission_id', 'submission_payload_hash'] as $column) {
        if (!in_array($column, $generationJobColumns, true)) {
            throw new RuntimeException("Fresh installer is missing generation_jobs.{$column}.");
        }
    }
    $submissionIndexes = $pdo->query("PRAGMA index_list('generation_jobs')")->fetchAll(PDO::FETCH_ASSOC);
    $submissionIndex = array_values(array_filter($submissionIndexes, static fn (array $index): bool => (string) ($index['name'] ?? '') === 'uk_generation_jobs_submission'));
    if ($submissionIndex === [] || (int) ($submissionIndex[0]['unique'] ?? 0) !== 1) {
        throw new RuntimeException('Fresh installer is missing the unique merchant/client submission index.');
    }
    $assetColumns = array_column($pdo->query('PRAGMA table_info(assets)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['media_type', 'mime_type', 'duration_seconds', 'poster_url', 'width', 'height'] as $column) {
        if (!in_array($column, $assetColumns, true)) {
            throw new RuntimeException("Fresh installer is missing assets.{$column}.");
        }
    }
    echo "[ok] subscription entitlements, full listing bundle and supplemental material fields installed\n";
    foreach ([
        'content_moderation_checks',
        'content_violations',
        'login_throttle_events',
        'subscription_plans',
        'merchant_subscriptions',
        'prompt_templates',
        'product_feedback',
        'ai_models',
        'ai_capability_routes',
        'ai_model_prices',
        'ai_model_calls',
        'ai_model_sync_batches',
        'ai_model_capabilities',
        'ai_capability_tests',
        'ai_business_compatibilities',
        'media_workflow_templates',
        'vision_tasks',
        'vision_result_cache',
        'video_generation_requests',
        'workspace_project_preferences',
        'creative_projects',
        'creative_project_revisions',
        'creative_project_assets',
        'creative_project_runs',
        'admin_navigation_preferences',
        'admin_onboarding_progress',
        'creative_products',
        'workflow_template_versions',
        'workflow_release_events',
        'generation_execution_attempts',
        'generation_quality_appeals',
        'generation_quality_appeal_events',
        'quality_improvement_items',
    ] as $table) {
        if ((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table))->fetchColumn() !== 1) {
            throw new RuntimeException("Fresh installer is missing {$table}.");
        }
    }
    foreach (['product_launch_stages', 'prompt_template_revisions', 'prompt_release_sets', 'prompt_quality_cases', 'prompt_quality_runs', 'prompt_assembly_bindings', 'detail_design_system_versions'] as $retiredTable) {
        if ((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($retiredTable))->fetchColumn() !== 0) {
            throw new RuntimeException("Fresh installer retained {$retiredTable}.");
        }
    }
    $modelCallColumns = array_column($pdo->query('PRAGMA table_info(ai_model_calls)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['attempt_no', 'route_role', 'protocol', 'http_status', 'actual_cost', 'started_at', 'finished_at'] as $column) {
        if (!in_array($column, $modelCallColumns, true)) {
            throw new RuntimeException("Fresh installer is missing ai_model_calls.{$column}.");
        }
    }
    foreach (['provider_account_id', 'provider_binding_id', 'provider_channel_id', 'provider_channel_model_id'] as $retiredColumn) {
        if (in_array($retiredColumn, $modelCallColumns, true)) {
            throw new RuntimeException("Fresh installer retained ai_model_calls.{$retiredColumn}.");
        }
    }
    $modelColumns = array_column($pdo->query('PRAGMA table_info(ai_models)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['source_visible', 'source_last_seen_at', 'lifecycle_status', 'archived_at'] as $column) {
        if (!in_array($column, $modelColumns, true)) {
            throw new RuntimeException("Fresh installer is missing ai_models.{$column}.");
        }
    }
    $capabilityTestColumns = array_column($pdo->query('PRAGMA table_info(ai_capability_tests)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('workflow_version_id', $capabilityTestColumns, true)) {
        throw new RuntimeException('Fresh installer is missing ai_capability_tests.workflow_version_id.');
    }
    $compatibilityColumns = array_column($pdo->query('PRAGMA table_info(ai_business_compatibilities)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['workflow_version_id', 'restriction_json'] as $column) {
        if (!in_array($column, $compatibilityColumns, true)) {
            throw new RuntimeException("Fresh installer is missing ai_business_compatibilities.{$column}.");
        }
    }
    foreach (['ai_provider_channels', 'ai_provider_accounts', 'ai_provider_channel_models', 'ai_provider_model_bindings', 'ai_provider_leases'] as $retiredTable) {
        if ((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($retiredTable))->fetchColumn() !== 0) {
            throw new RuntimeException("Retired ThinkPHP provider table {$retiredTable} was created.");
        }
    }
    echo "[ok] security, Sub2API catalog, creative project and image/video queue tables installed\n";
    $moderationColumns = array_column($pdo->query('PRAGMA table_info(content_moderation_checks)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['cache_hit_count', 'last_cache_hit_at'] as $column) {
        if (!in_array($column, $moderationColumns, true)) {
            throw new RuntimeException("Fresh installer is missing content_moderation_checks.{$column}.");
        }
    }
    echo "[ok] content safety cache metrics installed\n";
    if ((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='new_api_sync_logs'")->fetchColumn() !== 0) {
        throw new RuntimeException('Removed New API reconciliation table was created by the fresh installer.');
    }
    echo "[ok] reconciliation table omitted from fresh install\n";
    if ((int) $pdo->query('SELECT COUNT(*) FROM prompt_templates')->fetchColumn() < 1) {
        throw new RuntimeException('Browser installer did not seed complete prompt templates.');
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM creative_products')->fetchColumn() !== 7) {
        throw new RuntimeException('Browser installer did not seed the seven current creative products.');
    }
    $workflowCodes = $pdo->query('SELECT code FROM media_workflow_templates ORDER BY code')->fetchAll(PDO::FETCH_COLUMN);
    if ($workflowCodes !== ['canvas_edit_v1']) {
        throw new RuntimeException('Browser installer did not initialize the canvas-only workflow boundary.');
    }
    echo "[ok] browser installer completed schema upgrades and current seeds without a CLI command\n";

    $landingPage = request('GET', '/');
    expectStatus($landingPage, 200, 'public landing page after installation');
    if (!str_contains($landingPage['body'], 'AI 电商素材工厂')
        || !str_contains($landingPage['body'], '从一张商品图，得到一套能上架的电商素材')
        || !str_contains($landingPage['body'], '注册后继续上传并整理商品资料')) {
        throw new RuntimeException('Landing page did not render after installation.');
    }
    echo "[ok] landing page available after installation\n";

    $releasePage = request('GET', '/releases');
    expectStatus($releasePage, 200, 'public release history after installation');
    $installedVersion = trim((string) file_get_contents($root . '/VERSION'));
    if (!str_contains($releasePage['body'], '公开版本历程')
        || $installedVersion === ''
        || !str_contains($releasePage['body'], preg_replace('/-.+$/', '', $installedVersion) ?: $installedVersion)) {
        throw new RuntimeException('Public release history did not render after installation.');
    }
    echo "[ok] public release history available after installation\n";

    expectStatus(request('GET', '/install'), 403, 'installer locked after completion');
    $adminLogin = request('GET', '/admin/login');
    expectStatus($adminLogin, 200, 'admin login available');
    $adminLoginResult = request('POST', '/admin/login', [
        '_token' => csrf($adminLogin['body']),
        'email' => 'installer@example.com',
        'password' => 'InstallerTest!2026',
    ]);
    expectStatus($adminLoginResult, 302, 'installed administrator login');
    if (!preg_match('/^Location:\s*\/admin\s*$/mi', $adminLoginResult['headers'])) {
        throw new RuntimeException('Fresh administrator was not sent directly to the admin dashboard.');
    }
    $setupPage = request('GET', '/admin/setup');
    expectStatus($setupPage, 302, 'dormant first configuration wizard after installation');
    if (!preg_match('/^Location:\s*\/admin\s*$/mi', $setupPage['headers'])) {
        throw new RuntimeException('Dormant first configuration wizard did not redirect to the admin dashboard.');
    }
    $onboardingRow = $pdo->query('SELECT status,current_step FROM admin_onboarding_progress LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (($onboardingRow['status'] ?? '') !== 'pending' || ($onboardingRow['current_step'] ?? '') !== 'welcome') {
        throw new RuntimeException('Fresh administrator onboarding state is not pending at welcome.');
    }
    echo "[ok] fresh administrator enters the dashboard while restorable onboarding state remains pending\n";
    $workerPage = request('GET', '/admin/worker');
    expectStatus($workerPage, 200, 'worker status page after installation');
    if (!str_contains($workerPage['body'], 'php think jobs:work')) {
        throw new RuntimeException('Worker command is missing after installation.');
    }
    echo "[ok] worker command available after installation\n";
    $storagePage = request('GET', '/admin/storage');
    expectStatus($storagePage, 200, 'storage settings page after installation');
    if (!str_contains($storagePage['body'], '商户素材存储')) {
        throw new RuntimeException('Storage settings page is missing after installation.');
    }
    $promptPage = request('GET', '/admin/prompts');
    expectStatus($promptPage, 200, 'prompt template page after installation');
    foreach (['商品分析与产品报告', '商品主图', '商品套图', '商品详情页', '自由创作', '商品视频'] as $businessName) {
        if (!str_contains($promptPage['body'], $businessName)) {
            throw new RuntimeException("Fresh generation configuration is missing {$businessName}.");
        }
    }
    if (!str_contains($promptPage['body'], '业务提示词模板')
        || str_contains($promptPage['body'], '高级与追溯')
        || str_contains($promptPage['body'], '完整生产版本共')) {
        throw new RuntimeException('Productized prompt management was not initialized.');
    }
    $workflowPromptPage = request('GET', '/admin/prompts?tab=workflows');
    expectStatus($workflowPromptPage, 200, 'ordinary workflow query after installation');
    if (!str_contains($workflowPromptPage['body'], '业务提示词模板')
        || str_contains($workflowPromptPage['body'], '工作流产品')) {
        throw new RuntimeException('Ordinary prompt entry still exposes workflow engineering controls.');
    }
    echo "[ok] six generation businesses are the only ordinary prompt entry after installation\n";
    $feedbackPage = request('GET', '/admin/feedback');
    expectStatus($feedbackPage, 200, 'feedback center after installation');
    if (!str_contains($feedbackPage['body'], '用户反馈')) {
        throw new RuntimeException('Feedback center is missing after installation.');
    }
    echo "[ok] feedback center available after installation\n";
    expectStatus(request('GET', '/admin/security'), 200, 'standalone content security page after installation');
    expectStatus(request('GET', '/admin/pricing'), 200, 'standalone merchant pricing page after installation');
    foreach ([
        '/admin/ai-settings' => 'productized model and function settings',
        '/admin/system-status' => 'human-readable system status',
    ] as $path => $label) {
        expectStatus(request('GET', $path), 200, $label);
    }
    $legacyCreativeFunctions = request('GET', '/admin/creative-functions');
    expectStatus($legacyCreativeFunctions, 302, 'legacy creative functions redirects to generation configuration');
    if (!preg_match('/^Location:\s*\/admin\/prompts\s*$/mi', $legacyCreativeFunctions['headers'])) {
        throw new RuntimeException('Legacy creative functions did not preserve a safe generation-configuration redirect.');
    }
    foreach ([
        '/admin/ai-capabilities',
        '/admin/ai-models',
        '/admin/ai-assignments',
        '/admin/ai-compatibility',
    ] as $legacyPath) {
        $legacyResponse = request('GET', $legacyPath);
        expectStatus($legacyResponse, 302, 'legacy AI page redirects to productized settings');
        if (!preg_match('/^Location:\s*\/admin\/ai-settings(?:\\?tab=[a-z]+)?\s*$/mi', $legacyResponse['headers'])) {
            throw new RuntimeException("Legacy AI route {$legacyPath} did not redirect to productized settings.");
        }
    }
    expectStatus(request('GET', '/admin/new-api'), 404, 'retired New API bookmark');
    expectStatus(request('GET', '/admin/api/ai-gateway/bootstrap'), 404, 'retired ThinkPHP AI gateway API');
    echo "[ok] capability center is installed without duplicate upstream control-plane routes\n";
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/think') . ' app:upgrade 2>&1', $upgradeOutput, $upgradeExitCode);
    if ($upgradeExitCode !== 0) {
        throw new RuntimeException("Installed package upgrade command failed:\n" . implode("\n", $upgradeOutput));
    }
    $secondUpgradeOutput = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/think') . ' app:upgrade 2>&1', $secondUpgradeOutput, $secondUpgradeExitCode);
    if ($secondUpgradeExitCode !== 0) {
        throw new RuntimeException("Installed package second upgrade command failed:\n" . implode("\n", $secondUpgradeOutput));
    }
    echo "[ok] post-install upgrade remains idempotent\n";
    echo "Install smoke test completed successfully.\n";
} finally {
    @unlink($cookieJar);
}
