<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tokens = (string) file_get_contents($root . '/public/static/design-tokens.css');

$requiredTokens = [
    '--ds-nav: #101828',
    '--ds-bg: #f4f6fa',
    '--ds-surface: #ffffff',
    '--ds-surface-2: #f8fafc',
    '--ds-text: #182230',
    '--ds-text-secondary: #5d6675',
    '--ds-text-weak: #87909f',
    '--ds-border: #e2e7ef',
    '--ds-border-strong: #cfd6e1',
    '--ds-primary: #3656d4',
    '--ds-primary-hover: #2846b8',
    '--ds-primary-soft: #eef2ff',
    '--ds-success: #2ba471',
    '--ds-warning: #d46b08',
    '--ds-danger: #d54941',
    '--tooltip-bg: #1f2329',
    '--overlay-bg: rgba(17, 24, 39, .62)',
    '--preview-stage-bg: #111722',
    '--canvas-stage-bg: #111722',
    '--diagnostic-bg: #111722',
    '--ds-radius-control: 8px',
    '--ds-radius-card: 12px',
    '--ds-radius-dialog: 16px',
    '--ds-radius-pill: 999px',
    '--ds-font-sans: Inter, "SF Pro Text", "SF Pro Display", -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Noto Sans CJK SC", "Microsoft YaHei UI", sans-serif',
];
foreach ($requiredTokens as $contract) {
    if (!str_contains($tokens, $contract)) {
        throw new RuntimeException("Canonical design token is missing {$contract}.");
    }
}

foreach (['--page: var(--ds-bg)', '--brand: var(--ds-primary)', '--landing-accent: var(--ds-primary)', '--admin-teal: var(--ds-primary)'] as $alias) {
    if (!str_contains($tokens, $alias)) {
        throw new RuntimeException("Legacy alias is not routed through canonical tokens: {$alias}.");
    }
}

foreach (['#14b8a6', '#06b6d4', '#087c8f', '#055b69', '#2dd4bf', '#22d3ee', 'linear-gradient(', 'radial-gradient('] as $retired) {
    if (str_contains(strtolower($tokens), strtolower($retired))) {
        throw new RuntimeException("Canonical design system contains retired visual language: {$retired}.");
    }
}

foreach (['650', '750', '800'] as $unsupportedWeight) {
    if (preg_match('/font(?:-weight)?:\s*' . preg_quote($unsupportedWeight, '/') . '\b/', $tokens) === 1) {
        throw new RuntimeException("Canonical design system contains unsupported font weight {$unsupportedWeight}.");
    }
}

$shells = [
    $root . '/view/partials/public_header.php' => 'landing.css',
    $root . '/view/partials/merchant_header.php' => 'merchant-studio.css',
    $root . '/view/partials/admin_header.php' => 'admin-shell.css',
    $root . '/view/auth/login.php' => 'experience.css',
    $root . '/view/auth/register.php' => 'experience.css',
    $root . '/view/auth/forgot_password.php' => 'experience.css',
    $root . '/view/admin_auth/login.php' => 'experience.css',
];
foreach ($shells as $path => $legacyAsset) {
    $source = (string) file_get_contents($path);
    $tokenPosition = strpos($source, '/static/design-tokens.css?v=20260808-1');
    $legacyPosition = strpos($source, $legacyAsset);
    if ($tokenPosition === false || $legacyPosition === false || $tokenPosition < $legacyPosition) {
        throw new RuntimeException(basename($path) . ' does not load canonical design tokens after legacy components.');
    }
}

foreach (['app.css', 'experience.css', 'landing.css', 'admin-shell.css', 'merchant-studio.css', 'canvas-studio.css'] as $stylesheet) {
    $source = (string) file_get_contents($root . '/public/static/' . $stylesheet);
    if (preg_match('/font-weight:\s*(?:540|550|560|580|650|680|750|800)\b/', $source) === 1
        || preg_match('/font:\s*(?:750|800)\b/', $source) === 1) {
        throw new RuntimeException("{$stylesheet} still contains a non-system font weight.");
    }
}

$experience = (string) file_get_contents($root . '/public/static/experience.css');
if (preg_match('/@media \(max-width: 620px\)\s*\{[^}]*\.generation-advanced-body\s*\{[^}]*grid-template-columns:\s*minmax\(0, 1fr\)/s', $experience) !== 1) {
    throw new RuntimeException('Mobile product-generation advanced settings can regress into nested narrow columns.');
}
$adminShell = (string) file_get_contents($root . '/public/static/admin-shell.css');
if (!str_contains($adminShell, '.prompt-template-list') || !str_contains($adminShell, 'max-width: 1120px')) {
    throw new RuntimeException('Prompt template management lost its readable desktop line length.');
}
if (!str_contains($adminShell, '.prompt-simple-editor') || !str_contains($adminShell, 'field-sizing: content')) {
    throw new RuntimeException('Short complete prompt templates regress into an unnecessarily oversized editor.');
}

foreach (['.landing-header', '.auth-page', '.merchant-studio-topbar', '.admin-topnav', '@media (prefers-reduced-motion: reduce)'] as $surfaceContract) {
    if (!str_contains($tokens, $surfaceContract)) {
        throw new RuntimeException("Enterprise convergence layer is missing {$surfaceContract}.");
    }
}

if (preg_match(
    '/\\.auth-page \\.auth-brand,\\s*\\.login-auth-page \\.auth-brand\\s*\\{[^}]*justify-content:\\s*center;/s',
    $tokens
) !== 1) {
    throw new RuntimeException('Merchant and administrator login brand lockups are not centered in the top navigation.');
}

function tokenContrast(string $foreground, string $background): float
{
    $luminance = static function (string $hex): float {
        $hex = ltrim($hex, '#');
        $channels = [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
        $channels = array_map(
            static fn (float $value): float => $value <= 0.03928
                ? $value / 12.92
                : (($value + 0.055) / 1.055) ** 2.4,
            $channels
        );
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    };
    $first = $luminance($foreground);
    $second = $luminance($background);
    return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
}

foreach ([['#182230', '#ffffff'], ['#f5f7fb', '#101828'], ['#ffffff', '#3656d4']] as [$foreground, $background]) {
    if (tokenContrast($foreground, $background) < 4.5) {
        throw new RuntimeException("Primary text contrast fails WCAG AA: {$foreground} on {$background}.");
    }
}

echo "Enterprise design token contract tests completed successfully.\n";
