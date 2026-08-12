<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>管理后台登录 - AI 电商素材工厂</title>
    <link rel="stylesheet" href="/static/app.css?v=20260803-1">
    <link rel="stylesheet" href="/static/experience.css?v=20260731-3">
    <link rel="stylesheet" href="/static/design-tokens.css?v=20260808-1">
    <script defer src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
    <script defer src="/static/console.js?v=20260803-1"></script>
    <script defer src="/static/experience.js?v=20260731-1"></script>
    <?php if ($geetestConfiguration['configured']): ?><script defer src="https://static.geetest.com/static/tools/gt.js"></script><?php endif; ?>
</head>
<body>
<div class="auth-page login-auth-page admin-login-page">
    <section class="auth-brand">
        <div class="brand-lockup"><span class="brand-mark">AI</span><div><strong>AI 电商素材工厂</strong><small>运营管理后台</small></div></div>
        <div class="auth-signal"><span>平台运营与系统管理</span><h1>在一个控制台掌握业务运行状态</h1><p>集中管理商户、套餐、额度、任务、模型服务和内容安全，关键操作全程留痕。</p></div>
        <div class="auth-feature-list">
            <div><i data-lucide="building-2"></i><span><strong>商户与套餐管理</strong><small>账号状态、订阅权益和额度流水统一维护</small></span></div>
            <div><i data-lucide="activity"></i><span><strong>任务与服务监控</strong><small>查看生成耗时、处理状态和失败原因</small></span></div>
            <div><i data-lucide="shield-check"></i><span><strong>安全与审计闭环</strong><small>登录防护、内容检查和管理员操作可追溯</small></span></div>
        </div>
        <div class="auth-console-preview" aria-label="运营管理后台预览">
            <div class="auth-preview-head"><span><i data-lucide="monitor-cog"></i>运营处理顺序</span><em><b></b>登录后读取实时状态</em></div>
            <div class="auth-preview-stats"><div><small>优先发现</small><strong>服务异常</strong></div><div><small>快速定位</small><strong>受影响商户</strong></div><div><small>闭环处理</small><strong>可执行动作</strong></div></div>
            <div class="auth-preview-table"><div><span>任务与服务</span><small>实时读取队列和运行状态</small><b class="running"></b></div><div><span>模型与内容安全</span><small>统一定位、处置和审计</small><b></b></div></div>
        </div>
    </section>
    <main class="auth-main">
        <section class="auth-card">
            <div class="auth-card-brand"><span class="brand-mark">AI</span><span><strong>AI 电商素材工厂</strong><small>运营管理后台</small></span></div>
            <div class="eyebrow">内部管理入口</div>
            <h2>登录管理后台</h2>
            <p>仅限已授权管理员。</p>
            <?php if ($flashSuccess): ?><div class="flash success"><?= escape_html($flashSuccess) ?></div><?php endif; ?>
            <?php if ($flashError): ?><div class="flash error"><?= escape_html($flashError) ?></div><?php endif; ?>
            <form method="post" action="/admin/login" <?= $geetestConfiguration['configured'] ? 'data-geetest-form data-geetest-register-url="/captcha/geetest/register"' : '' ?>>
                <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
                <div class="form-grid">
                    <div class="form-field full"><label for="email">管理员邮箱</label><input class="input" id="email" name="email" type="email" autocomplete="username" required></div>
                    <div class="form-field full"><label for="password">密码</label><input class="input" id="password" name="password" type="password" autocomplete="current-password" required></div>
                    <?php if ($geetestConfiguration['configured']): ?><div class="form-field full geetest-field"><input type="hidden" name="geetest_challenge" data-geetest-challenge><input type="hidden" name="geetest_validate" data-geetest-validate><input type="hidden" name="geetest_seccode" data-geetest-seccode><small data-geetest-status hidden aria-live="polite"></small></div><?php endif; ?>
                </div>
                <div class="form-footer"><button class="button primary" type="submit"><i data-lucide="log-in"></i><span>登录管理后台</span></button></div>
            </form>
            <div class="auth-switch"><a href="/login">返回商家登录</a></div>
        </section>
    </main>
</div>
<script>document.addEventListener('DOMContentLoaded', function () { if (window.lucide) window.lucide.createIcons({attrs: {'aria-hidden': 'true', 'stroke-width': 1.8}}); });</script>
</body>
</html>
