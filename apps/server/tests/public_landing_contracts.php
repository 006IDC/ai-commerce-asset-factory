<?php

declare(strict_types=1);

use app\service\PublicReleaseService;
use think\App;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = (new App(dirname(__DIR__)))->initialize();
$service = $app->make(PublicReleaseService::class);
$releases = $service->all();

if ($service->validationErrors() !== []) {
    throw new RuntimeException('Public release manifest did not pass canonical validation.');
}
if ($releases === [] || (string) $releases[0]['version'] !== $service->publicVersion()) {
    throw new RuntimeException('Public release manifest latest version does not match VERSION.');
}
$versions = array_column($releases, 'version');
if (count($versions) !== count(array_unique($versions))) {
    throw new RuntimeException('Public release manifest contains duplicate versions.');
}
for ($index = 1; $index < count($versions); $index++) {
    if (version_compare((string) $versions[$index - 1], (string) $versions[$index], '<=')) {
        throw new RuntimeException('Public release manifest is not strictly descending.');
    }
}
foreach ($releases as $release) {
    foreach (['source_reference', 'visible'] as $internalField) {
        if (array_key_exists($internalField, $release)) {
            throw new RuntimeException("Public release output exposed {$internalField}.");
        }
    }
}

$root = dirname(__DIR__);
$home = (string) file_get_contents($root . '/view/home.php');
$header = (string) file_get_contents($root . '/view/partials/public_header.php');
$footer = (string) file_get_contents($root . '/view/partials/public_footer.php');
$releasePage = (string) file_get_contents($root . '/view/releases.php');
$landingJs = (string) file_get_contents($root . '/public/static/landing.js');
$landingCss = (string) file_get_contents($root . '/public/static/landing.css');
$consoleJs = (string) file_get_contents($root . '/public/static/console.js');
$routes = (string) file_get_contents($root . '/route/app.php');

foreach ([
    'data-guest-creation-console',
    'data-guest-image-input',
    'data-guest-brief',
    'data-guest-plan',
    'data-guest-platform',
    'data-guest-local-helper',
    'data-release-drawer',
] as $contract) {
    if (!str_contains($home, $contract)) {
        throw new RuntimeException("Landing page is missing {$contract}.");
    }
}
foreach (['/static/landing.css?v=20260804-1', '/static/landing.js?v=20260804-1', 'href="/releases"'] as $assetContract) {
    if (!str_contains($header . $footer, $assetContract)) {
        throw new RuntimeException("Dedicated public shell is missing {$assetContract}.");
    }
}
if (str_contains($header, '/static/console.js') || str_contains($header, '/static/app.css')) {
    throw new RuntimeException('Public landing still loads the merchant console bundle.');
}
if (!str_contains($routes, "Route::get('releases', 'PublicSite/releases')") || !str_contains($releasePage, '$publicReleases')) {
    throw new RuntimeException('Canonical public release route/page is missing.');
}
foreach ([
    'asset-factory-product-launch-v1',
    'schemaVersion',
    'creativeBrief',
    'launchPlan',
    'platform',
    'referenceNames',
    'source: \'landing_product_launch\'',
    '/register?return_to=%2Fworkspace%2Fgenerate&source=landing_product_launch',
] as $draftContract) {
    if (!str_contains($landingJs, $draftContract)) {
        throw new RuntimeException("Landing draft flow is missing {$draftContract}.");
    }
}
if (!str_contains($landingJs, 'submit.disabled = selectedFiles.length === 0')) {
    throw new RuntimeException('Guest product launch is not gated by at least one product image.');
}
$submitStart = strpos($landingJs, "form.addEventListener('submit'");
$submitEnd = $submitStart === false ? false : strpos($landingJs, 'window.addEventListener(\'pagehide\'', $submitStart);
$submitFlow = $submitStart === false || $submitEnd === false ? '' : substr($landingJs, $submitStart, $submitEnd - $submitStart);
foreach (['fetch(', 'FormData', 'URL.createObjectURL', 'referencePath', 'binary'] as $forbidden) {
    if (str_contains($submitFlow, $forbidden)) {
        throw new RuntimeException("Guest submit flow persists or transmits forbidden data: {$forbidden}.");
    }
}
foreach (['showModal', "event.key !== 'Tab'", 'previousFocus', 'data-release-filter'] as $drawerContract) {
    if (!str_contains($landingJs . $home, $drawerContract)) {
        throw new RuntimeException("Release drawer is missing {$drawerContract}.");
    }
}
foreach (['asset-factory-product-launch-v1', 'landing_product_launch', '已恢复首页选择的上新方案', 'input[name="launch_plan"]'] as $restoreContract) {
    if (!str_contains($consoleJs . (string) file_get_contents($root . '/view/merchant/product_launch.php'), $restoreContract)) {
        throw new RuntimeException("Merchant draft restore is missing {$restoreContract}.");
    }
}
foreach (['从一张商品图，得到一套能上架的电商素材', '智能推荐', '快速上新', '完整商品套图', '详情页套图'] as $productFirstCopy) {
    if (!str_contains($home, $productFirstCopy)) {
        throw new RuntimeException("Product-first landing is missing {$productFirstCopy}.");
    }
}
if (str_contains($home, '选择真实可用的模型') || str_contains($home, '>自由创作</strong>')) {
    throw new RuntimeException('Public hero still leads with model selection or free creation.');
}
foreach (['.landing-body', '.landing-create-console', '.landing-release-drawer', '@media (max-width: 720px)'] as $cssContract) {
    if (!str_contains($landingCss, $cssContract)) {
        throw new RuntimeException("Landing CSS is missing {$cssContract}.");
    }
}

echo "Public landing contract tests completed successfully.\n";
