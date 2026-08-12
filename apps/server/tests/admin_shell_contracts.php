<?php

declare(strict_types=1);

use app\service\AdminConfigurationAudit;
use app\service\AdminShellService;
use think\App;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = (new App(dirname(__DIR__)))->initialize();
$shell = $app->make(AdminShellService::class);
$audit = $app->make(AdminConfigurationAudit::class);

$navigation = $shell->navigation('/admin/prompts');
$links = [];
foreach ($navigation as $group) {
    foreach ($group['items'] as $item) {
        $links[(string) $item['href']] = $item;
    }
}
foreach ([
    '/admin',
    '/admin/merchants',
    '/admin/jobs',
    '/admin/ai-settings',
    '/admin/prompts',
    '/admin/quality-appeals',
    '/admin/plans',
    '/admin/pricing',
    '/admin/system-status',
    '/admin/security',
    '/admin/worker',
    '/admin/configuration-audit',
] as $required) {
    if (!isset($links[$required])) {
        throw new RuntimeException("Unified admin navigation is missing {$required}.");
    }
}
if (empty($links['/admin/prompts']['active'])) {
    throw new RuntimeException('Unified admin navigation did not mark the current prompt page.');
}
foreach ($links as $href => $item) {
    if (str_starts_with($href, '/admin/new-api')
        || in_array($href, ['/admin/ai-capabilities', '/admin/ai-models', '/admin/ai-assignments', '/admin/ai-compatibility'], true)) {
        throw new RuntimeException('Unified admin navigation still exposes a retired engineering AI console.');
    }
}
foreach (['模型与价格', '提示词管理', '质量问题'] as $requiredLabel) {
    $encodedNavigation = json_encode($navigation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!str_contains((string) $encodedNavigation, $requiredLabel)) {
        throw new RuntimeException("Unified admin navigation is missing {$requiredLabel}.");
    }
}
if (isset($links['/admin/creative-functions'])) {
    throw new RuntimeException('Unified admin navigation still exposes the retired creative-functions entry.');
}
foreach (['上游账号', '渠道密钥', 'API Key'] as $forbiddenLabel) {
    foreach ($navigation as $group) {
        if (str_contains(json_encode($group, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $forbiddenLabel)) {
            throw new RuntimeException("Unified admin navigation exposes forbidden upstream control-plane label {$forbiddenLabel}.");
        }
    }
}

$adminShellCss = (string) file_get_contents(dirname(__DIR__) . '/public/static/admin-shell.css');
$adminShellJs = (string) file_get_contents(dirname(__DIR__) . '/public/static/admin-shell.js');
$adminHeader = (string) file_get_contents(dirname(__DIR__) . '/view/partials/admin_header.php');
$aiSettingsPage = (string) file_get_contents(dirname(__DIR__) . '/view/admin/ai_settings.php');
$aiModelPage = (string) file_get_contents(dirname(__DIR__) . '/view/admin/ai_model_detail.php');
$promptBusinessPage = (string) file_get_contents(dirname(__DIR__) . '/view/admin/prompt_business.php');
$adminRoutes = (string) file_get_contents(dirname(__DIR__) . '/route/app.php');
foreach (['.admin-topnav', '.admin-desktop-navigation', '.admin-mobile-drawer', '.admin-shell-main'] as $requiredCss) {
    if (!str_contains($adminShellCss, $requiredCss)) {
        throw new RuntimeException("Top navigation CSS is missing {$requiredCss}.");
    }
}
foreach (['openMobile', 'closeMobile', 'closeNavigationGroups', "event.key === 'Escape'", "event.target.closest('[data-admin-nav-group]')"] as $requiredJs) {
    if (!str_contains($adminShellJs, $requiredJs)) {
        throw new RuntimeException("Top navigation interaction is missing {$requiredJs}.");
    }
}
foreach (['admin-sidebar', 'is-collapsed', 'adminSidebarCollapsed', 'data-admin-sidebar-collapse'] as $retiredSidebarContract) {
    if (str_contains($adminHeader . $adminShellCss . $adminShellJs, $retiredSidebarContract)) {
        throw new RuntimeException("Retired sidebar state remains in the admin shell: {$retiredSidebarContract}.");
    }
}
foreach (['admin-topnav', 'data-admin-nav-group', 'data-admin-mobile-menu', 'data-admin-mobile-drawer', 'admin-account-identity'] as $requiredHeader) {
    if (!str_contains($adminHeader, $requiredHeader)) {
        throw new RuntimeException("Top navigation template is missing {$requiredHeader}.");
    }
}
if (!str_contains($adminHeader, 'admin-shell.css?v=20260808-3') || !str_contains($adminHeader, 'admin-shell.js?v=20260731-2')) {
    throw new RuntimeException('Admin shell asset versions were not bumped after the prompt-management layout repair.');
}
foreach ([
    'data-admin-task-reminder',
    'data-admin-task-reminder-toggle',
    'aria-label="任务提醒"',
    'aria-expanded="false"',
    'aria-controls="admin-task-reminder-panel"',
    '当前没有待处理事项',
    '任务提醒暂时无法读取',
    '查看全部任务',
] as $taskReminderContract) {
    if (!str_contains($adminHeader, $taskReminderContract)) {
        throw new RuntimeException("Admin task reminder template is missing {$taskReminderContract}.");
    }
}
foreach ([
    'setTaskReminderOpen',
    'taskReminderPreviousFocus',
    "setAttribute('aria-expanded', 'true')",
    "setAttribute('aria-expanded', 'false')",
    "document.addEventListener('pointerdown'",
    'taskReminderTitle.focus()',
    'closeAccountMenu(true)',
] as $taskReminderInteraction) {
    if (!str_contains($adminShellJs, $taskReminderInteraction)) {
        throw new RuntimeException("Admin task reminder interaction is missing {$taskReminderInteraction}.");
    }
}
foreach (['mouseenter', 'mouseleave', 'focusin', 'focusout', '100', '200', "event.key === 'Enter'", "event.key === ' '"] as $hoverContract) {
    if (!str_contains($adminShellJs, $hoverContract)) {
        throw new RuntimeException("Desktop navigation hover/keyboard state machine misses {$hoverContract}.");
    }
}
foreach (['ai-function-workspace', 'ai-function-master', 'return_capability', '$selectedCapability === $capability', '用户生成', '平台内部'] as $functionUiContract) {
    if (!str_contains($aiSettingsPage, $functionUiContract)) {
        throw new RuntimeException("Single-capability assignment workspace is missing {$functionUiContract}.");
    }
}
foreach (['section=overview', 'section=validation', 'section=tests', 'data-open-paid-test', 'data-paid-test-dialog', 'data-model-test-drawer', 'data-model-test-template'] as $modelUiContract) {
    if (!str_contains($aiModelPage, $modelUiContract)) {
        throw new RuntimeException("Segmented model detail is missing {$modelUiContract}.");
    }
}
foreach (['完整模板', '查看完整模板', '编辑模板'] as $promptUiContract) {
    if (!str_contains($promptBusinessPage, $promptUiContract)) {
        throw new RuntimeException("Prompt-only management is missing {$promptUiContract}.");
    }
}
foreach (['paidTestDialog', 'modelTestDrawer', 'replaceChildren', 'admin-dialog-open'] as $adminInteractionContract) {
    if (!str_contains($adminShellJs . $adminShellCss, $adminInteractionContract)) {
        throw new RuntimeException("Admin visual interaction is missing {$adminInteractionContract}.");
    }
}
foreach (["paidTestDialog.addEventListener('keydown'", "modelTestDrawer.addEventListener('keydown'", "if (event.key !== 'Escape') return;"] as $dialogKeyboardContract) {
    if (!str_contains($adminShellJs, $dialogKeyboardContract)) {
        throw new RuntimeException("Admin dialog keyboard contract is missing {$dialogKeyboardContract}.");
    }
}
$attentionService = (string) file_get_contents(dirname(__DIR__) . '/app/service/AdminJobAttentionService.php');
$adminController = (string) file_get_contents(dirname(__DIR__) . '/app/controller/Admin.php');
$shellService = (string) file_get_contents(dirname(__DIR__) . '/app/service/AdminShellService.php');
foreach (['ATTENTION_STATUSES', 'applyAttention', 'attentionCount'] as $attentionContract) {
    if (!str_contains($attentionService, $attentionContract)) {
        throw new RuntimeException("Shared job attention query misses {$attentionContract}.");
    }
}
if (!str_contains($shellService, "/admin/jobs?attention=1&period=24h")
    || !str_contains($adminController, '$attentionJobs->applyAttention')) {
    throw new RuntimeException('Job attention badge and destination do not share one query service.');
}
if (str_contains($adminRoutes, 'prompts/workflows/templates/:id/')) {
    throw new RuntimeException('Prompt management still registers workflow mutation routes.');
}

$rows = $audit->rows();
if ($rows === []) {
    throw new RuntimeException('Configuration audit returned no settings.');
}
$classes = array_unique(array_column($rows, 'classification'));
foreach (['自动', '向导', '专家', '废弃'] as $classification) {
    if (!in_array($classification, $classes, true)) {
        throw new RuntimeException("Configuration audit is missing {$classification} settings.");
    }
}
foreach ($rows as $row) {
    if (str_contains((string) $row['key'], 'password') || str_contains((string) $row['key'], 'api_key') || str_contains((string) $row['key'], 'secret_key')) {
        if ((string) $row['default'] !== '••••••') {
            throw new RuntimeException('Configuration audit exposed a secret default.');
        }
    }
}

$results = $shell->search('admin-shell-contract-no-match-' . bin2hex(random_bytes(4)));
if ($results !== []) {
    throw new RuntimeException('Global object search did not scope an impossible query.');
}

echo "Admin shell contract tests completed successfully.\n";
