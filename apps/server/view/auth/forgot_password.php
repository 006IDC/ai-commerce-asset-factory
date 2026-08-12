<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>找回密码 - AI 电商素材工厂</title>
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
        <div class="auth-signal"><span>账户安全</span><h1>通过注册邮箱重设登录密码</h1><p>验证码仅发送到已绑定的邮箱，10 分钟内有效。重置后请使用新密码重新登录工作台。</p></div>
        <div class="auth-feature-list">
            <div><i data-lucide="mail-check"></i><span><strong>邮箱验证</strong><small>通过注册邮箱确认账户归属</small></span></div>
            <div><i data-lucide="shield-check"></i><span><strong>安全校验</strong><small>发送验证码前需要完成人机验证</small></span></div>
            <div><i data-lucide="key-round"></i><span><strong>设置新密码</strong><small>新密码立即用于下一次登录</small></span></div>
        </div>
    </section>
    <main class="auth-main">
        <section class="auth-card">
            <div class="auth-card-brand"><span class="brand-mark">AI</span><span><strong>AI 电商素材工厂</strong><small>商家工作台</small></span></div>
            <div class="eyebrow">账户恢复</div>
            <h2><?= $passwordResetEmail ? '设置新密码' : '找回密码' ?></h2>
            <p><?= $passwordResetEmail ? '验证码有效期为 10 分钟。' : '输入注册邮箱，我们会发送验证码。' ?></p>
            <?php if ($flashSuccess): ?><div class="flash success"><?= escape_html($flashSuccess) ?></div><?php endif; ?>
            <?php if ($flashError): ?><div class="flash error"><?= escape_html($flashError) ?></div><?php endif; ?>
            <?php if (!$passwordResetEmail): ?>
            <form method="post" action="/forgot-password/email-code" <?= $geetestConfiguration['configured'] ? 'data-geetest-form data-geetest-register-url="/captcha/geetest/password-reset"' : '' ?>>
                <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
                <div class="form-grid">
                    <div class="form-field full"><label for="email">注册邮箱</label><input class="input" id="email" name="email" type="email" autocomplete="email" required></div>
                    <?php if ($geetestConfiguration['configured']): ?><div class="form-field full geetest-field"><input type="hidden" name="geetest_challenge" data-geetest-challenge><input type="hidden" name="geetest_validate" data-geetest-validate><input type="hidden" name="geetest_seccode" data-geetest-seccode><small data-geetest-status hidden aria-live="polite"></small></div><?php endif; ?>
                </div>
                <div class="form-footer"><a class="button" href="/login">返回登录</a><button class="button primary" type="submit">发送验证码</button></div>
            </form>
            <?php else: ?>
            <form method="post" action="/forgot-password">
                <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
                <div class="form-grid">
                    <div class="form-field full"><label for="email">注册邮箱</label><input class="input" id="email" name="email" type="email" value="<?= escape_html($passwordResetEmail) ?>" readonly></div>
                    <div class="form-field full"><label for="verification_code">邮箱验证码</label><input class="input code-value" id="verification_code" name="verification_code" inputmode="numeric" minlength="6" maxlength="6" autocomplete="one-time-code" required></div>
                    <div class="form-field"><label for="password">新密码</label><input class="input" id="password" name="password" type="password" minlength="10" autocomplete="new-password" required><small>至少 10 位，同时包含字母和数字。</small></div>
                    <div class="form-field"><label for="password_confirmation">确认新密码</label><input class="input" id="password_confirmation" name="password_confirmation" type="password" minlength="10" autocomplete="new-password" required></div>
                </div>
                <div class="form-footer"><a class="button" href="/forgot-password?reset_email=1">更换邮箱</a><button class="button primary" type="submit">确认重置密码</button></div>
            </form>
            <?php endif; ?>
        </section>
    </main>
</div>
<script>document.addEventListener('DOMContentLoaded', function () { if (window.lucide) window.lucide.createIcons({attrs: {'aria-hidden': 'true', 'stroke-width': 1.8}}); });</script>
</body>
</html>
