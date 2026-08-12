<?php

declare(strict_types=1);

use app\service\VisibleCopy;
use think\App;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
(new App($root))->initialize();
require_once $root . '/app/common.php';

$forbidden = [
    'Sub2API',
    'ComfyUI',
    'Worker',
    'prompt_id',
    'request_id',
    'Schema',
    'Cloudflare',
    '上游账号',
    'API Key',
    '物理路由',
    '节点执行',
    '冻结快照',
];

$publicSources = [
    $root . '/view/home.php',
    $root . '/view/releases.php',
    $root . '/view/partials/public_header.php',
    $root . '/view/partials/public_footer.php',
    $root . '/public/static/landing.js',
];
$authSources = [
    $root . '/view/auth/login.php',
    $root . '/view/auth/register.php',
    $root . '/view/auth/forgot_password.php',
    $root . '/view/admin_auth/login.php',
];
$merchantSources = array_merge(
    glob($root . '/view/merchant/*.php') ?: [],
    [$root . '/view/partials/merchant_header.php', $root . '/view/partials/merchant_footer.php']
);

foreach (['public' => $publicSources, 'authentication' => $authSources, 'merchant' => $merchantSources] as $audience => $paths) {
    $source = '';
    foreach ($paths as $path) {
        $source .= "\n" . (string) file_get_contents($path);
    }
    foreach ($forbidden as $term) {
        if (str_contains($source, $term)) {
            throw new RuntimeException("{$audience} visible copy exposes expert-only term {$term}.");
        }
    }
}

$home = (string) file_get_contents($root . '/view/home.php');
$landingJs = (string) file_get_contents($root . '/public/static/landing.js');
foreach (['游客阶段不上传图片', '本地草稿', 'AI 帮你写', '图片只在当前浏览器预览', '保存草稿并继续'] as $retiredCopy) {
    if (str_contains($home . $landingJs, $retiredCopy)) {
        throw new RuntimeException("Public landing retains retired implementation copy: {$retiredCopy}.");
    }
}
foreach (['商品上新', '帮我补充', '注册后继续上传并整理商品资料，当前上新设置会保留', '已补充一份创作示例，你可以继续修改'] as $requiredCopy) {
    if (!str_contains($home . $landingJs, $requiredCopy)) {
        throw new RuntimeException("Public landing misses governed copy: {$requiredCopy}.");
    }
}
if (preg_match('/data-guest-local-helper[^>]*>.*?<span>AI/u', $home) === 1) {
    throw new RuntimeException('Local helper is incorrectly presented as AI.');
}

$consoleJs = (string) file_get_contents($root . '/public/static/console.js');
$geetestStart = strpos($consoleJs, 'function setupGeetest');
$geetestEnd = $geetestStart === false ? false : strpos($consoleJs, 'function setupProductLaunch', $geetestStart);
$verificationCopy = $geetestStart === false || $geetestEnd === false
    ? ''
    : substr($consoleJs, $geetestStart, $geetestEnd - $geetestStart);
if ($verificationCopy === '' || !str_contains($verificationCopy, '安全验证') || str_contains($verificationCopy, '极验')) {
    throw new RuntimeException('Authentication safety verification copy is missing or exposes the vendor.');
}
foreach ($authSources as $path) {
    $source = (string) file_get_contents($path);
    if (str_contains($source, '极验') || str_contains($source, '正在加载')) {
        throw new RuntimeException(basename($path) . ' exposes verification vendor/loading implementation copy.');
    }
}

$mappingCases = [
    'No available channel for model' => ['暂时不可用', '预扣点数', '手动重试'],
    'HTTP 524 error code: 524' => ['处理超时', '预扣点数', '手动重试'],
    'Concurrency limit exceeded for account, please retry later' => ['请求较多', '不是内容违规', '原任务', '手动重试'],
    'Invalid token status code: 401' => ['暂时不可用', '不是内容违规', '原任务', '手动重试'],
    'Sub2API：图片被上游安全策略拦截，请换个描述后重试' => ['安全限制', '不会扣费', '原任务重试', '无需重新建档'],
    '内容安全检查失败：Sub2API：Upstream rate limit exceeded, please retry later' => ['请求较多', '不是内容违规', '原任务', '手动重试'],
    '内容安全服务暂时不可用，本次未进入生成；这不是内容违规，请稍后在原任务手动重试。' => ['审核服务暂时不可用', '不是内容违规', '原任务', '手动重试'],
    '余额不足或无可用资源包，请充值。' => ['上游资源不足', '原任务', '手动重试'],
    '内容安全检测未通过：平台违禁词，请求已驳回。' => ['未通过安全检查', '调整文字或参考图'],
];
foreach ($mappingCases as $raw => $expectedParts) {
    $visible = VisibleCopy::merchantGenerationError($raw);
    foreach ($forbidden as $term) {
        if (str_contains($visible, $term)) {
            throw new RuntimeException("Merchant error mapping leaks {$term}.");
        }
    }
    foreach ($expectedParts as $expected) {
        if (!str_contains($visible, $expected)) {
            throw new RuntimeException("Merchant error mapping misses {$expected}: {$visible}");
        }
    }
}
if (VisibleCopy::merchantProgress('等待 Worker 处理') !== '等待后台处理') {
    throw new RuntimeException('Historical merchant progress copy still exposes the worker implementation.');
}

$guidance = job_failure_guidance('HTTP 503 Service Unavailable', 0, 8);
if (!str_contains((string) $guidance['billing'], '预扣点数会自动退回')
    || (string) $guidance['action'] !== '重新生成') {
    throw new RuntimeException('Merchant failure guidance does not explain points and next action.');
}
$safetyUnavailableGuidance = job_failure_guidance('内容安全服务暂时不可用，本次未进入生成；这不是内容违规，请稍后在原任务手动重试。', 0, 0);
if ((string) $safetyUnavailableGuidance['actionType'] !== 'retry'
    || !str_contains((string) $safetyUnavailableGuidance['solution'], '不是内容违规')) {
    throw new RuntimeException('Safety-service outage is still presented as a content violation.');
}
$safetyViolationGuidance = job_failure_guidance('内容安全检测未通过：平台违禁词，请求已驳回。', 0, 0);
if ((string) $safetyViolationGuidance['actionType'] !== 'edit') {
    throw new RuntimeException('A confirmed content violation lost its edit-content action.');
}

$merchantJobs = (string) file_get_contents($root . '/view/merchant/jobs.php');
$merchantFreeCreate = (string) file_get_contents($root . '/view/merchant/free_create.php');
foreach (['生成已经开始，当前任务不能取消', '生成开始后不能取消'] as $irreversibleCopy) {
    if (!str_contains($merchantJobs . $merchantFreeCreate, $irreversibleCopy)) {
        throw new RuntimeException("Irreversible generation warning is missing {$irreversibleCopy}.");
    }
}

$modelDetail = (string) file_get_contents($root . '/view/admin/ai_model_detail.php');
if (!str_contains($modelDetail, '可能产生上游费用')) {
    throw new RuntimeException('Administrator paid real-output test lost its fee warning.');
}
$diagnostics = (string) file_get_contents($root . '/view/admin/quality_appeal_detail.php');
if (!str_contains($diagnostics, 'ComfyUI') || !str_contains($diagnostics, 'Sub2API')) {
    throw new RuntimeException('Expert diagnostics were incorrectly removed.');
}

echo "Audience-visible copy contract tests completed successfully.\n";
