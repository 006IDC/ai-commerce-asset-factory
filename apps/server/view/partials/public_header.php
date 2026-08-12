<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="description" content="围绕商品资料完成图片、套图、详情页、视频与素材管理的 AI 电商创作工作台。">
    <title><?= escape_html($pageTitle ?? 'AI 电商素材工厂') ?></title>
    <link rel="stylesheet" href="/static/landing.css?v=20260804-1">
    <link rel="stylesheet" href="/static/design-tokens.css?v=20260808-1">
    <script defer src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
    <script defer src="/static/landing.js?v=20260804-1"></script>
</head>
<body class="landing-body">
<a class="landing-skip-link" href="#main-content">跳到主要内容</a>
<header class="landing-header">
    <div class="landing-container landing-header-inner">
        <a class="landing-brand" href="/" aria-label="AI 电商素材工厂首页">
            <span class="landing-brand-mark" aria-hidden="true">AI</span>
            <span><strong>AI 电商素材工厂</strong><small>商品素材生产工作台</small></span>
        </a>
        <nav class="landing-nav" aria-label="公开导航">
            <a href="/#create">商品创作</a>
            <a href="/#workflow">工作流程</a>
            <a href="/#pricing">套餐</a>
            <a href="/releases" data-release-drawer-open>版本历程</a>
        </nav>
        <div class="landing-header-actions">
            <?php if (!empty($signedIn)): ?>
                <a class="landing-button secondary" href="/workspace/generate">开始商品上新</a>
            <?php else: ?>
                <a class="landing-button text" href="/login">登录</a>
                <a class="landing-button primary" href="/register">注册并开始</a>
            <?php endif; ?>
        </div>
        <details class="landing-mobile-menu" data-public-mobile-menu>
            <summary aria-label="打开导航"><i data-lucide="menu" aria-hidden="true"></i></summary>
            <div>
                <nav aria-label="移动端公开导航">
                    <a href="/#create">商品创作</a>
                    <a href="/#workflow">工作流程</a>
                    <a href="/#pricing">套餐</a>
                    <a href="/releases" data-release-drawer-open>版本历程</a>
                </nav>
                <div>
                    <?php if (!empty($signedIn)): ?>
                        <a href="/workspace/generate">开始商品上新</a>
                    <?php else: ?>
                        <a href="/login">登录</a>
                        <a class="primary" href="/register">注册并开始</a>
                    <?php endif; ?>
                </div>
            </div>
        </details>
    </div>
</header>
<main id="main-content" class="landing-main">
    <?php if ($flashSuccess): ?><div class="landing-container"><div class="landing-flash success" role="status"><?= escape_html($flashSuccess) ?></div></div><?php endif; ?>
    <?php if ($flashError): ?><div class="landing-container"><div class="landing-flash error" role="alert"><?= escape_html($flashError) ?></div></div><?php endif; ?>
