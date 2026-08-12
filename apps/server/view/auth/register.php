<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>注册商家 - AI 电商素材工厂</title>
    <link rel="stylesheet" href="/static/app.css?v=20260803-1">
    <link rel="stylesheet" href="/static/experience.css?v=20260731-3">
    <link rel="stylesheet" href="/static/design-tokens.css?v=20260808-1">
    <script defer src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
    <script defer src="/static/console.js?v=20260803-1"></script>
    <script defer src="/static/experience.js?v=20260731-1"></script>
    <?php if ($geetestConfiguration['configured']): ?><script defer src="https://static.geetest.com/static/tools/gt.js"></script><?php endif; ?>
</head>
<body>
<div class="auth-page">
    <section class="auth-brand">
        <div class="brand-lockup"><span class="brand-mark">AI</span><div><strong>AI 电商素材工厂</strong><small>商家工作台</small></div></div>
        <div class="auth-signal"><h1>建立商户账户，开始管理素材生产。</h1><p>注册后自动创建独立商户空间，并按平台当前规则发放注册福利。</p></div>
        <div class="auth-meta"><div>注册点数<strong><?= number_format((int) $registrationBenefit['credits']) ?> 点</strong></div><div>赠送套餐<strong><?= escape_html($registrationBenefit['plan']['name'] ?? '无') ?></strong></div></div>
    </section>
    <main class="auth-main">
        <section class="auth-card">
            <div class="eyebrow">商家注册</div>
            <h2><?= $registrationEmail ? '完善商户资料' : '验证注册邮箱' ?></h2>
            <p><?= $registrationEmail ? '验证码有效期为 10 分钟。' : '先验证邮箱，再创建商户账户。' ?></p>
            <?php if ($flashSuccess): ?><div class="flash success"><?= escape_html($flashSuccess) ?></div><?php endif; ?>
            <?php if ($flashError): ?><div class="flash error"><?= escape_html($flashError) ?></div><?php endif; ?>
            <?php if (!$registrationEmail): ?>
            <form method="post" action="/register/email-code" <?= $geetestConfiguration['configured'] ? 'data-geetest-form data-geetest-register-url="/captcha/geetest/register"' : '' ?>>
                <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
                <input type="hidden" name="return_to" value="<?= escape_html($returnTo ?? '/workspace') ?>">
                <div class="form-grid">
                    <div class="form-field full"><label for="email">邮箱</label><input class="input" id="email" name="email" type="email" autocomplete="email" required></div>
                    <?php if ($geetestConfiguration['configured']): ?><div class="form-field full geetest-field"><input type="hidden" name="geetest_challenge" data-geetest-challenge><input type="hidden" name="geetest_validate" data-geetest-validate><input type="hidden" name="geetest_seccode" data-geetest-seccode><small data-geetest-status hidden aria-live="polite"></small></div><?php endif; ?>
                </div>
                <div class="form-footer"><a class="button" href="/login?return_to=<?= rawurlencode($returnTo ?? '/workspace') ?>">返回登录</a><button class="button primary" type="submit">发送验证码</button></div>
            </form>
            <?php else: ?>
            <form method="post" action="/register">
                <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
                <input type="hidden" name="return_to" value="<?= escape_html($returnTo ?? '/workspace') ?>">
                <div class="form-grid">
                    <div class="form-field full"><label for="email">已验证邮箱</label><input class="input" id="email" name="email" type="email" value="<?= escape_html($registrationEmail) ?>" readonly></div>
                    <div class="form-field full"><label for="verification_code">邮箱验证码</label><input class="input code-value" id="verification_code" name="verification_code" inputmode="numeric" minlength="6" maxlength="6" autocomplete="one-time-code" required></div>
                    <div class="form-field full"><label for="merchant_name">商户名称</label><input class="input" id="merchant_name" name="merchant_name" maxlength="120" required></div>
                    <div class="form-field full"><label for="name">用户名</label><input class="input" id="name" name="name" maxlength="80" autocomplete="name" required></div>
                    <div class="form-field"><label for="password">密码</label><input class="input" id="password" name="password" type="password" minlength="10" autocomplete="new-password" required><small>至少 10 位，同时包含字母和数字。</small></div>
                    <div class="form-field"><label for="password_confirmation">确认密码</label><input class="input" id="password_confirmation" name="password_confirmation" type="password" minlength="10" autocomplete="new-password" required></div>
                </div>
                <div class="form-footer"><a class="button" href="/register?reset_email=1&amp;return_to=<?= rawurlencode($returnTo ?? '/workspace') ?>">更换邮箱</a><button class="button primary" type="submit">创建账户</button></div>
            </form>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>
