<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$route = $read('route/app.php');
$merchant = $read('app/controller/Merchant.php');
$workspace = $read('app/controller/MerchantProductWorkspace.php');
$vision = $read('app/service/VisionTaskQueue.php');
$detail = $read('app/controller/MerchantDetailPage.php');
$worker = $read('app/command/WorkGenerationJobs.php');
$console = $read('public/static/console.js');
$merchantStudio = $read('public/static/merchant-studio.js');
$workbenchJs = $read('public/static/product-launch-workbench.js');
$header = $read('view/partials/merchant_header.php');
$appCss = $read('public/static/app.css');

$assert(str_contains($route, "Route::get('skus/:id', 'MerchantProductWorkspace/detail')"), 'The SKU workbench route must use the focused controller.');
$assert(!str_contains($merchant, 'function skuDetail('), 'Merchant must not regain the extracted SKU detail action.');
$assert(!str_contains($merchant, 'function updateSkuFoundation('), 'Merchant must not regain the extracted foundation action.');
foreach (['detail', 'updateFoundation', 'updateTargets'] as $action) {
    $assert(str_contains($workspace, 'function ' . $action . '('), "Focused SKU controller is missing {$action}.");
}
$assert(!str_contains($workspace, 'function respondFact('), 'The retired fact-by-fact confirmation action must not return.');
$assert(!str_contains($route, '/facts/'), 'The retired fact confirmation route must not return.');

$assert(str_contains($worker, 'GenerationWorkerLaneService'), 'Generation worker must delegate lane selection to the focused service.');
$assert(str_contains($vision, 'ProductLaunchTargetService'), 'Vision launch flow must own reusable delivery targets.');
$assert(substr_count($vision, 'persistFromLaunch(') === 1, 'The single direct vision onboarding path must persist launch targets exactly once.');
$assert(!str_contains($vision, 'product_launch_stages') && !str_contains($vision, 'processNextProductLaunchStage'), 'The retired staged product-launch pipeline must not return.');
$assert(str_contains($vision, 'catch (ContentSafetyViolationException $exception)')
    && str_contains($vision, "'failure_type' => 'content_safety_violation'")
    && preg_match("/content_safety_violation'[,]?\\s*'retryable' => false/s", $vision) === 1
    && str_contains($vision, 'catch (ContentSafetyUnavailableException $exception)')
    && str_contains($vision, "'failure_type' => 'content_safety_unavailable'")
    && preg_match("/content_safety_unavailable'[,]?[^]]*'retryable' => true/s", $vision) === 1,
    'Product launch must stop on a confirmed violation but retry one safety-service outage from cache.');
$assert(substr_count($detail, 'persistFromLaunch(') >= 2, 'Legacy and SKU-only launch paths must persist launch targets.');

$assert(!str_contains($console, 'setupGenerationPresets'), 'The legacy duplicate generation preset initializer must stay removed.');
$assert(str_contains($merchantStudio, 'setupListingPresets'), 'The restored listing presets must stay scoped to the merchant studio shell.');
$assert(str_contains($workbenchJs, "document.querySelector('[data-one-flow-workbench]')"), 'Workbench JS must be explicitly scoped to its page root.');
$assert(str_contains($header, '/static/product-launch-workbench.css'), 'Merchant shell must load the split workbench stylesheet.');
$assert(str_contains($header, '/static/product-launch-workbench.js'), 'Merchant shell must load the split workbench script.');
$assert(!str_contains($appCss, '.one-flow-workbench'), 'Workbench-only CSS must not leak back into the global stylesheet.');

$focusedFiles = [
    'app/controller/MerchantProductWorkspace.php',
    'app/service/DetailPageScreenType.php',
    'app/service/ProductLaunchTargetService.php',
    'app/service/SkuWorkbenchService.php',
    'app/service/GenerationWorkerLaneService.php',
    'public/static/product-launch-workbench.js',
    'view/merchant/sku_workbench.php',
];
foreach ($focusedFiles as $file) {
    $lines = count(file($root . '/' . $file));
    $assert($lines <= 260, "Focused module {$file} is too large ({$lines} lines).");
}

echo "Product launch code boundary contract passed.\n";
