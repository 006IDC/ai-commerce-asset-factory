<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$templates = array_merge(
    glob($root . '/view/partials/*.php') ?: [],
    glob($root . '/view/auth/*.php') ?: [],
    glob($root . '/view/admin_auth/*.php') ?: [],
    glob($root . '/view/install/*.php') ?: [],
);
$templateSource = '';
foreach ($templates as $template) {
    $templateSource .= "\n" . (string) file_get_contents($template);
}
foreach (['theme-init.js', 'data-theme-toggle', '切换主题', '切换亮暗主题'] as $retiredThemeContract) {
    if (str_contains($templateSource, $retiredThemeContract)) {
        throw new RuntimeException("Active template retains retired theme control {$retiredThemeContract}.");
    }
}
if (is_file($root . '/public/static/theme-init.js')) {
    throw new RuntimeException('Retired theme initializer is still shipped.');
}

$activeStyles = [
    'app.css',
    'experience.css',
    'landing.css',
    'merchant-studio.css',
    'admin-shell.css',
    'canvas-studio.css',
    'design-tokens.css',
];
foreach ($activeStyles as $stylesheet) {
    $source = (string) file_get_contents($root . '/public/static/' . $stylesheet);
    foreach (['data-theme', 'prefers-color-scheme', '.theme-toggle', '.auth-theme-toggle', '.dark '] as $retiredSelector) {
        if (str_contains($source, $retiredSelector)) {
            throw new RuntimeException("{$stylesheet} retains global theme branch {$retiredSelector}.");
        }
    }
}
$appCss = (string) file_get_contents($root . '/public/static/app.css');
foreach (['.user-menu:hover', '.workspace-account-menu[open] .user-menu'] as $retiredGlobalAccountState) {
    if (str_contains($appCss, $retiredGlobalAccountState)) {
        throw new RuntimeException("Shared app.css retains cross-shell account state {$retiredGlobalAccountState}.");
    }
}

$activeScripts = [
    'experience.js',
    'landing.js',
    'admin-shell.js',
    'merchant-studio.js',
];
foreach ($activeScripts as $script) {
    $source = (string) file_get_contents($root . '/public/static/' . $script);
    foreach (['asset-factory-theme', 'data-theme-toggle', 'prefers-color-scheme', 'colorScheme'] as $retiredThemeState) {
        if (str_contains($source, $retiredThemeState)) {
            throw new RuntimeException("{$script} retains theme state {$retiredThemeState}.");
        }
    }
}

$tokens = (string) file_get_contents($root . '/public/static/design-tokens.css');
foreach ([
    'color-scheme: light',
    '--ds-bg: #f4f6fa',
    '--ds-surface: #ffffff',
    '--ds-border: #e2e7ef',
    '--ds-text: #182230',
    '--ds-primary: #3656d4',
    '--tooltip-bg: #1f2329',
    '--preview-stage-bg: #111722',
    '--canvas-stage-bg: #111722',
    '.merchant-studio-topbar',
    '.admin-topnav',
] as $singleThemeContract) {
    if (!str_contains($tokens, $singleThemeContract)) {
        throw new RuntimeException("Single enterprise theme is missing {$singleThemeContract}.");
    }
}

$adminHeader = (string) file_get_contents($root . '/view/partials/admin_header.php');
$adminCss = (string) file_get_contents($root . '/public/static/admin-shell.css');
$adminJs = (string) file_get_contents($root . '/public/static/admin-shell.js');
$shellService = (string) file_get_contents($root . '/app/service/AdminShellService.php');

foreach ([
    'data-admin-task-reminder-toggle',
    'aria-label="任务提醒"',
    'aria-haspopup="dialog"',
    'aria-expanded="false"',
    'aria-controls="admin-task-reminder-panel"',
    '当前没有待处理事项',
    '任务提醒暂时无法读取',
    '查看全部任务',
] as $taskTemplateContract) {
    if (!str_contains($adminHeader, $taskTemplateContract)) {
        throw new RuntimeException("Task reminder template misses {$taskTemplateContract}.");
    }
}
foreach ([
    'setTaskReminderOpen',
    'taskReminderPreviousFocus',
    'taskReminderTitle.focus()',
    "document.addEventListener('pointerdown'",
    "event.key === 'Escape'",
    'closeAccountMenu(true)',
] as $taskInteractionContract) {
    if (!str_contains($adminJs, $taskInteractionContract)) {
        throw new RuntimeException("Task reminder interaction misses {$taskInteractionContract}.");
    }
}
foreach ([
    '.admin-task-reminder-panel',
    '.admin-task-reminder-state.empty',
    '.admin-task-reminder-state.error',
    '.admin-account-menu[open] > summary',
    '.admin-account-menu > summary:hover',
    '.admin-account-menu > summary:focus-visible',
    'line-height: 1',
] as $taskVisualContract) {
    if (!str_contains($adminCss, $taskVisualContract)) {
        throw new RuntimeException("Task/account visual state misses {$taskVisualContract}.");
    }
}
foreach ([
    'public function taskReminder()',
    "'available' => false",
    "'available' => true",
    '质量申诉',
    '需处理任务',
    '排队任务',
    '安全事件',
    "attentionCount('24h')",
    'queueCount()',
] as $taskFactContract) {
    if (!str_contains($shellService, $taskFactContract)) {
        throw new RuntimeException("Task reminder fact source misses {$taskFactContract}.");
    }
}

echo "Single enterprise theme and task reminder contract tests completed successfully.\n";
