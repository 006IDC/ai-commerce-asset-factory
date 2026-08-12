<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>商家登录 - AI 电商素材工厂</title>
    <link rel="stylesheet" href="/static/app.css?v=20260803-1">
    <link rel="stylesheet" href="/static/experience.css?v=20260731-3">
    <link rel="stylesheet" href="/static/design-tokens.css?v=20260808-1">
    <script defer src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
    <script defer src="/static/console.js?v=20260803-1"></script>
    <script defer src="/static/experience.js?v=20260731-1"></script>
    <?php if ($geetestConfiguration['configured']): ?><script defer src="https://static.geetest.com/static/tools/gt.js"></script><?php endif; ?>
</head>
<body>
<div class="auth-page login-auth-page">
    <section class="auth-brand">
        <div class="brand-lockup"><span class="brand-mark">AI</span><div><strong>AI 电商素材工厂</strong><small>商家工作台</small></div></div>
        <div class="auth-signal"><span>面向电商商家与运营团队</span><h1>从一个 SKU 到一套可投放素材</h1><p>围绕商品资料批量生成主图、卖点图、场景图和详情页，并统一管理任务、素材与点数。</p></div>
        <div class="auth-feature-list">
            <div><i data-lucide="package-search"></i><span><strong>商品资料集中管理</strong><small>参考图、商品事实和创作要求随 SKU 持续复用</small></span></div>
            <div><i data-lucide="workflow"></i><span><strong>生成任务全程可追踪</strong><small>任务排队、生成进度和结果状态清晰可见</small></span></div>
            <div><i data-lucide="images"></i><span><strong>素材与点数统一归档</strong><small>按商品筛选素材，随时核对套餐和消耗</small></span></div>
        </div>
        <div class="auth-console-preview" aria-label="商家工作台预览">
            <div class="auth-preview-head"><span><i data-lucide="workflow"></i>创作流程</span><em><b></b>后台持续处理</em></div>
            <div class="auth-preview-stats"><div><small>第一步</small><strong>识别商品</strong></div><div><small>第二步</small><strong>确认方案</strong></div><div><small>第三步</small><strong>生成归档</strong></div></div>
            <div class="auth-preview-table"><div><span>任务离开页面后继续运行</span><small>进度与重试可随时查看</small><b class="running"></b></div><div><span>结果自动进入商品档案</span><small>继续编辑、发布或下载</small><b></b></div></div>
        </div>
    </section>
    <main class="auth-main">
        <section class="auth-card">
            <div class="auth-card-brand"><span class="brand-mark">AI</span><span><strong>AI 电商素材工厂</strong><small>商家工作台</small></span></div>
            <div class="eyebrow">商家入口</div>
            <h2>登录工作台</h2>
            <p>使用商家账户继续。</p>
            <?php if ($flashSuccess): ?><div class="flash success"><?= escape_html($flashSuccess) ?></div><?php endif; ?>
            <?php if ($flashError): ?><div class="flash error"><?= escape_html($flashError) ?></div><?php endif; ?>
            <form method="post" action="/login" <?= $geetestConfiguration['configured'] ? 'data-geetest-form data-geetest-register-url="/captcha/geetest/register"' : '' ?>>
                <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
                <input type="hidden" name="return_to" value="<?= escape_html($returnTo ?? '/workspace') ?>">
                <div class="form-grid">
                    <div class="form-field full"><label for="email">邮箱</label><input class="input" id="email" name="email" type="email" autocomplete="username" required></div>
                    <div class="form-field full"><label for="password">密码</label><input class="input" id="password" name="password" type="password" autocomplete="current-password" required></div>
                    <?php if ($geetestConfiguration['configured']): ?><div class="form-field full geetest-field"><input type="hidden" name="geetest_challenge" data-geetest-challenge><input type="hidden" name="geetest_validate" data-geetest-validate><input type="hidden" name="geetest_seccode" data-geetest-seccode><small data-geetest-status hidden aria-live="polite"></small></div><?php endif; ?>
                </div>
                <div class="form-footer"><a class="button" href="/forgot-password">忘记密码</a><button class="button primary" type="submit"><i data-lucide="log-in"></i><span>登录</span></button></div>
            </form>
            <div class="auth-switch">还没有商家账户？ <a href="/register?return_to=<?= rawurlencode($returnTo ?? '/workspace') ?>">注册商家</a></div>
        </section>
    </main>
</div>
<script>document.addEventListener('DOMContentLoaded', function () { if (window.lucide) window.lucide.createIcons({attrs: {'aria-hidden': 'true', 'stroke-width': 1.8}}); });</script>
</body>
</html>
