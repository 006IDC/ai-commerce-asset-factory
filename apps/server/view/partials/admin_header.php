<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="<?= escape_html((string) $csrfToken) ?>">
    <title><?= escape_html($pageTitle) ?> - AI 素材工厂管理后台</title>
    <link rel="stylesheet" href="/static/app.css?v=20260803-1">
    <link rel="stylesheet" href="/static/experience.css?v=20260808-2">
    <link rel="stylesheet" href="/static/admin-shell.css?v=20260808-3">
    <link rel="stylesheet" href="/static/design-tokens.css?v=20260808-1">
    <?php foreach ((array) ($pageStyles ?? []) as $pageStyle): ?><link rel="stylesheet" href="<?= escape_html((string) $pageStyle) ?>"><?php endforeach; ?>
    <script defer src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
    <script defer src="/static/console.js?v=20260803-1"></script>
    <script defer src="/static/experience.js?v=20260731-1"></script>
    <script defer src="/static/admin-shell.js?v=20260731-2"></script>
    <?php foreach ((array) ($pageScripts ?? []) as $pageScript): ?><script defer src="<?= escape_html((string) $pageScript) ?>"></script><?php endforeach; ?>
</head>
<body class="console-body admin-console-body admin-shell-body<?= !empty($bodyClass) ? ' ' . escape_html((string) $bodyClass) : '' ?>">
<div class="admin-shell-backdrop" data-admin-shell-backdrop hidden></div>
<div class="admin-shell">
    <header class="admin-topnav">
        <div class="admin-topnav-primary">
            <button class="admin-mobile-menu" type="button" data-admin-mobile-menu aria-label="打开导航" aria-expanded="false"><i data-lucide="menu"></i></button>
            <a class="admin-topnav-brand" href="/admin">
                <span class="brand-mark">AI</span>
                <span><strong>AI 素材工厂</strong><small>管理控制台</small></span>
            </a>
            <nav class="admin-desktop-navigation" aria-label="管理后台导航">
                <?php foreach ((array) ($adminNavigation ?? []) as $group): $groupActive = count(array_filter((array) $group['items'], static fn (array $item): bool => !empty($item['active']))) > 0; ?>
                <details class="admin-nav-group<?= $groupActive ? ' active' : '' ?>" data-admin-nav-group>
                    <summary><?= escape_html((string) $group['label']) ?><i data-lucide="chevron-down"></i></summary>
                    <div class="admin-nav-popover">
                        <?php foreach ((array) $group['items'] as $item): ?>
                        <a class="<?= !empty($item['active']) ? 'active' : '' ?>" href="<?= escape_html((string) $item['href']) ?>">
                            <i data-lucide="<?= escape_html((string) $item['icon']) ?>"></i>
                            <span><?= escape_html((string) $item['label']) ?></span>
                            <?php if (!empty($item['expert'])): ?><em>诊断</em><?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </details>
                <?php endforeach; ?>
            </nav>
            <div class="admin-topbar-actions">
                <a class="admin-command-search" href="/admin/object-search" aria-label="打开全局搜索"><i data-lucide="search"></i><span>搜索商户、任务、商品</span><kbd>⌘K</kbd></a>
                <?php
                $taskReminder = (array) ($adminTaskReminder ?? ['available' => false, 'total' => 0, 'items' => []]);
                $taskReminderAvailable = !empty($taskReminder['available']);
                $taskReminderItems = (array) ($taskReminder['items'] ?? []);
                $taskReminderCount = max(0, (int) ($taskReminder['total'] ?? 0));
                ?>
                <div class="admin-task-reminder" data-admin-task-reminder>
                    <button class="icon-button admin-task-reminder-toggle" type="button"
                        data-admin-task-reminder-toggle
                        aria-label="任务提醒"
                        title="任务提醒"
                        aria-haspopup="dialog"
                        aria-expanded="false"
                        aria-controls="admin-task-reminder-panel">
                        <i data-lucide="bell"></i>
                        <?php if ($taskReminderCount > 0): ?><b><?= $taskReminderCount > 99 ? '99+' : $taskReminderCount ?></b><?php endif; ?>
                    </button>
                    <section class="admin-task-reminder-panel" id="admin-task-reminder-panel"
                        data-admin-task-reminder-panel
                        role="dialog"
                        aria-modal="false"
                        aria-labelledby="admin-task-reminder-title"
                        hidden>
                        <header>
                            <div><strong id="admin-task-reminder-title" tabindex="-1">任务提醒</strong><small>需要管理员关注的业务事项</small></div>
                            <button class="icon-button" type="button" data-admin-task-reminder-close aria-label="关闭任务提醒" title="关闭任务提醒"><i data-lucide="x"></i></button>
                        </header>
                        <div class="admin-task-reminder-list">
                            <?php if (!$taskReminderAvailable): ?>
                                <div class="admin-task-reminder-state error"><i data-lucide="circle-alert"></i><span><strong>任务提醒暂时无法读取</strong><small>你仍可以进入任务列表查看当前状态。</small></span></div>
                            <?php elseif (!$taskReminderItems): ?>
                                <div class="admin-task-reminder-state empty"><i data-lucide="circle-check"></i><span><strong>当前没有待处理事项</strong><small>新的提醒会显示在这里。</small></span></div>
                            <?php else: ?>
                                <?php foreach ($taskReminderItems as $todo): ?>
                                    <a href="<?= escape_html((string) $todo['href']) ?>">
                                        <span><strong><?= escape_html((string) $todo['label']) ?></strong><small><?= escape_html((string) ($todo['detail'] ?? '')) ?></small></span>
                                        <b><?= (int) $todo['count'] ?></b>
                                        <i data-lucide="chevron-right"></i>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <footer><a href="/admin/jobs"><span>查看全部任务</span><i data-lucide="arrow-right"></i></a></footer>
                    </section>
                </div>
                <details class="workspace-account-menu admin-account-menu" data-dismiss-on-outside>
                    <summary class="user-menu" aria-label="系统管理员账户菜单" title="系统管理员账户菜单" aria-haspopup="menu" aria-expanded="false">
                        <span class="user-avatar"><?= escape_html(mb_substr((string) $admin['name'], 0, 1)) ?></span>
                        <span class="admin-account-identity"><strong><?= escape_html((string) $admin['name']) ?></strong><small><?= escape_html(member_role_label((string) $admin['role'])) ?></small></span>
                        <i data-lucide="chevron-down"></i>
                    </summary>
                    <div class="workspace-account-popover">
                        <a href="/admin/account">管理员账户</a>
                        <?php if (!empty($adminFavorites)): ?><div class="admin-account-shortcuts"><strong>收藏</strong><?php foreach ($adminFavorites as $item): ?><a href="<?= escape_html((string) $item['path']) ?>"><?= escape_html((string) $item['title']) ?></a><?php endforeach; ?></div><?php endif; ?>
                        <?php if (!empty($adminRecent)): ?><div class="admin-account-shortcuts"><strong>最近访问</strong><?php foreach ($adminRecent as $item): ?><a href="<?= escape_html((string) $item['path']) ?>"><?= escape_html((string) $item['title']) ?></a><?php endforeach; ?></div><?php endif; ?>
                        <form method="post" action="/admin/logout"><input type="hidden" name="_token" value="<?= escape_html((string) $csrfToken) ?>"><button class="logout-button" type="submit"><i data-lucide="log-out"></i><span>退出登录</span></button></form>
                    </div>
                </details>
            </div>
        </div>
        <div class="admin-contextbar">
            <div class="admin-breadcrumbs"><?php foreach ((array) ($adminBreadcrumbs ?? []) as $index => $crumb): ?><?php if ($index > 0): ?><i data-lucide="chevron-right"></i><?php endif; ?><span><?= escape_html((string) $crumb) ?></span><?php endforeach; ?></div>
            <form method="post" action="/admin/navigation/favorite"><input type="hidden" name="_token" value="<?= escape_html((string) $csrfToken) ?>"><input type="hidden" name="path" value="<?= escape_html((string) $currentPath) ?>"><input type="hidden" name="title" value="<?= escape_html((string) $pageTitle) ?>"><button class="admin-favorite-button" type="submit"><i data-lucide="<?= !empty($adminCurrentFavorite) ? 'star-off' : 'star' ?>"></i><span><?= !empty($adminCurrentFavorite) ? '取消收藏' : '收藏此页' ?></span></button></form>
        </div>
    </header>
    <aside class="admin-mobile-drawer" data-admin-mobile-drawer aria-hidden="true">
        <div class="admin-mobile-drawer-header">
            <a class="admin-topnav-brand" href="/admin"><span class="brand-mark">AI</span><span><strong>AI 素材工厂</strong><small>管理控制台</small></span></a>
            <button type="button" data-admin-mobile-close aria-label="关闭导航"><i data-lucide="x"></i></button>
        </div>
        <nav class="admin-mobile-navigation" aria-label="移动端管理后台导航">
            <?php foreach ((array) ($adminNavigation ?? []) as $group): ?>
                <section><p><?= escape_html((string) $group['label']) ?></p>
                    <?php foreach ((array) $group['items'] as $item): ?><a class="<?= !empty($item['active']) ? 'active' : '' ?>" href="<?= escape_html((string) $item['href']) ?>" title="<?= escape_html((string) $item['label']) ?>"><i data-lucide="<?= escape_html((string) $item['icon']) ?>"></i><span><?= escape_html((string) $item['label']) ?></span><?php if (!empty($item['expert'])): ?><em>诊断</em><?php endif; ?></a><?php endforeach; ?>
                </section>
            <?php endforeach; ?>
        </nav>
        <div class="admin-mobile-shortcuts">
            <?php if (!empty($adminFavorites)): ?><details open><summary>收藏</summary><?php foreach ($adminFavorites as $item): ?><a href="<?= escape_html((string) $item['path']) ?>"><i data-lucide="star"></i><span><?= escape_html((string) $item['title']) ?></span></a><?php endforeach; ?></details><?php endif; ?>
            <?php if (!empty($adminRecent)): ?><details><summary>最近访问</summary><?php foreach ($adminRecent as $item): ?><a href="<?= escape_html((string) $item['path']) ?>"><i data-lucide="history"></i><span><?= escape_html((string) $item['title']) ?></span></a><?php endforeach; ?></details><?php endif; ?>
        </div>
        <div class="admin-mobile-account">
            <a href="/admin/account"><span class="user-avatar"><?= escape_html(mb_substr((string) $admin['name'], 0, 1)) ?></span><span><strong><?= escape_html((string) $admin['name']) ?></strong><small><?= escape_html(member_role_label((string) $admin['role'])) ?></small></span></a>
        </div>
    </aside>
    <div class="admin-shell-main">
        <main class="admin-shell-content">
            <?php if ($flashSuccess): ?><div class="flash success" role="status"><i data-lucide="circle-check"></i><span><?= escape_html($flashSuccess) ?></span></div><?php endif; ?>
            <?php if ($flashError): ?><div class="flash error" role="alert"><i data-lucide="circle-alert"></i><span><?= escape_html($flashError) ?></span></div><?php endif; ?>
