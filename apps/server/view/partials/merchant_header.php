<?php
$workspaceReturnTo = (string) ($_SERVER['REQUEST_URI'] ?? $currentPath);
if (!str_starts_with($workspaceReturnTo, '/workspace')) {
    $workspaceReturnTo = '/workspace/generate';
}
$creativePaths = ['/workspace/generate', '/workspace/free-create', '/workspace/canvas'];
$isCreativeWorkspace = in_array($currentPath, $creativePaths, true);
$projectGroups = ['今天' => [], '最近 7 天' => [], '更早' => []];
foreach ($workspaceRecentProjects as $workspaceProject) {
    $projectGroups[$workspaceProject['timeGroup'] ?? '更早'][] = $workspaceProject;
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= escape_html($pageTitle) ?> - AI 电商素材工厂</title>
    <?php if ($currentPath === '/workspace/canvas'): ?><link rel="stylesheet" href="/static/vendor/drawflow/drawflow.min.css?v=0.0.60"><?php endif; ?>
    <link rel="stylesheet" href="/static/app.css?v=20260803-1">
    <link rel="stylesheet" href="/static/experience.css?v=20260808-2">
    <link rel="stylesheet" href="/static/merchant-studio.css?v=20260808-1">
    <link rel="stylesheet" href="/static/product-launch-workbench.css?v=20260808-1">
    <?php if ($currentPath === '/workspace/canvas'): ?><link rel="stylesheet" href="/static/canvas-studio.css?v=20260731-1"><?php endif; ?>
    <link rel="stylesheet" href="/static/design-tokens.css?v=20260808-1">
    <script defer src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
    <?php if ($currentPath === '/workspace/canvas'): ?><script defer src="/static/vendor/drawflow/drawflow.min.js?v=0.0.60"></script><?php endif; ?>
    <script defer src="/static/console.js?v=20260804-1"></script>
    <script defer src="/static/experience.js?v=20260731-1"></script>
    <script defer src="/static/merchant-studio.js?v=20260804-1"></script>
    <script defer src="/static/product-launch-workbench.js?v=20260802-1"></script>
    <?php if ($currentPath === '/workspace/canvas'): ?><script defer src="/static/canvas-project.js?v=20260728-3"></script><script defer src="/static/canvas-workflow.js?v=20260728-2"></script><script type="module" src="/static/canvas-design.js?v=20260728-3"></script><script defer src="/static/canvas-storyboard.js?v=20260728-1"></script><?php endif; ?>
    <?php if ($currentPath === '/workspace/tools'): ?><script defer src="/static/image-tools.js?v=20260726-1"></script><?php endif; ?>
</head>
<body class="console-body merchant-studio-body<?= $isCreativeWorkspace ? ' is-creative-workspace' : '' ?>" data-job-failure-monitor data-merchant-id="<?= (int) $merchant['id'] ?>" data-initial-failed-job-count="<?= (int) $merchantFailedJobCount ?>" data-csrf-token="<?= escape_html((string) $csrfToken) ?>" data-merchant-studio>
<div class="merchant-studio-shell">
    <button class="merchant-sidebar-backdrop" type="button" data-studio-sidebar-backdrop hidden aria-label="关闭项目栏"></button>
    <aside class="merchant-project-sidebar" data-studio-sidebar aria-label="创作项目栏">
        <div class="merchant-sidebar-brand">
            <a href="/workspace/generate" aria-label="返回创作工作台"><span class="brand-mark">AI</span><span><strong>AI 素材工厂</strong><small>电商创作工作台</small></span></a>
        </div>

        <details class="studio-new-menu" data-dismiss-on-outside>
            <summary class="button primary"><i data-lucide="plus"></i><span>新建创作</span><i data-lucide="chevron-down"></i></summary>
            <div class="studio-new-popover">
                <a href="/workspace/generate"><i data-lucide="package-plus"></i><span><strong>商品上新</strong><small>上传商品图并自动整理资料</small></span></a>
                <a href="/workspace/generate?mode=assets"><i data-lucide="image"></i><span><strong>快速商品图</strong><small>主图、卖点图与场景图</small></span></a>
                <a href="/workspace/generate?mode=bundle"><i data-lucide="layers-3"></i><span><strong>完整商品套图</strong><small>主图与详情页一次创建</small></span></a>
                <a href="/workspace/generate?mode=detail"><i data-lucide="panels-top-left"></i><span><strong>详情页创作</strong><small>按商品资料智能编排</small></span></a>
                <a href="/workspace/free-create"><i data-lucide="wand-sparkles"></i><span><strong>自由创作</strong><small>纯文字或临时参考图</small></span></a>
                <a href="/workspace/canvas?new=1"><i data-lucide="square-dashed"></i><span><strong>空白画布</strong><small>从尺寸、背景和文字开始</small></span></a>
                <a href="/workspace/skus"><i data-lucide="package-search"></i><span><strong>从已有商品继续</strong><small>复用商品资料和历史素材</small></span></a>
                <a href="/workspace/assets"><i data-lucide="images"></i><span><strong>从已有素材编辑</strong><small>选择图片进入画布或工具</small></span></a>
            </div>
        </details>

        <nav class="merchant-mode-nav" aria-label="创作模式">
            <a class="<?= str_starts_with($currentPath, '/workspace/generate') ? 'active' : '' ?>" href="/workspace/generate"><i data-lucide="sparkles"></i><span>商品上新</span></a>
            <a class="<?= str_starts_with($currentPath, '/workspace/free-create') ? 'active' : '' ?>" href="/workspace/free-create"><i data-lucide="wand-sparkles"></i><span>自由创作</span></a>
            <a class="<?= str_starts_with($currentPath, '/workspace/jobs') ? 'active' : '' ?>" href="/workspace/jobs"><i data-lucide="list-checks"></i><span>生成进度</span></a>
            <a class="<?= str_starts_with($currentPath, '/workspace/skus') ? 'active' : '' ?>" href="/workspace/skus"><i data-lucide="package-search"></i><span>商品档案</span></a>
            <a class="<?= str_starts_with($currentPath, '/workspace/assets') ? 'active' : '' ?>" href="/workspace/assets"><i data-lucide="images"></i><span>素材管理</span></a>
            <a class="<?= str_starts_with($currentPath, '/workspace/tools') ? 'active' : '' ?>" href="/workspace/tools"><i data-lucide="wand-2"></i><span>图片工具</span></a>
            <a class="<?= str_starts_with($currentPath, '/workspace/canvas') ? 'active' : '' ?>" href="/workspace/canvas"><i data-lucide="panels-top-left"></i><span>画布编辑</span></a>
        </nav>

        <section class="merchant-recent-projects" aria-labelledby="recent-project-heading">
            <header>
                <strong id="recent-project-heading">最近项目</strong>
                <button class="icon-button" type="button" data-project-search-toggle aria-label="搜索最近项目" data-tooltip="搜索项目"><i data-lucide="search"></i></button>
            </header>
            <label class="merchant-project-search" data-project-search hidden>
                <i data-lucide="search"></i>
                <input type="search" placeholder="搜索商品或项目" data-project-search-input>
            </label>
            <div class="merchant-project-list" data-project-list>
                <?php if (!$workspaceRecentProjects): ?>
                    <a class="merchant-project-empty" href="/workspace/generate"><i data-lucide="package-plus"></i><span><strong>还没有创作项目</strong><small>添加商品图开始第一项创作</small></span></a>
                <?php else: ?>
                    <?php foreach ($projectGroups as $groupLabel => $projects): if (!$projects) { continue; } ?>
                        <div class="merchant-project-group" data-project-group>
                            <small><?= escape_html($groupLabel) ?></small>
                            <?php foreach ($projects as $sidebarProject): ?>
                                <div class="merchant-project-row" data-project-row data-project-search-text="<?= escape_html(mb_strtolower($sidebarProject['name'] . ' ' . $sidebarProject['kind'])) ?>">
                                    <a href="<?= escape_html($sidebarProject['url']) ?>" class="merchant-project-link">
                                        <span class="merchant-project-thumb">
                                            <?php if ($sidebarProject['thumbnail']): ?><img src="<?= escape_html($sidebarProject['thumbnail']) ?>" data-fallback="<?= escape_html($sidebarProject['thumbnailFallback']) ?>" alt="" loading="lazy"><?php else: ?><i data-lucide="<?= $sidebarProject['type'] === 'free' ? 'wand-sparkles' : ($sidebarProject['type'] === 'canvas' ? 'panels-top-left' : 'package') ?>"></i><?php endif; ?>
                                        </span>
                                        <span><strong><?= escape_html($sidebarProject['name']) ?></strong><small><?= escape_html($sidebarProject['kind']) ?> · <?= escape_html($sidebarProject['statusLabel']) ?></small></span>
                                        <?php if ($sidebarProject['pinned']): ?><i class="merchant-project-pin" data-lucide="pin"></i><?php endif; ?>
                                    </a>
                                    <details class="merchant-project-menu" data-dismiss-on-outside>
                                        <summary aria-label="项目操作" data-tooltip="项目操作"><i data-lucide="ellipsis"></i></summary>
                                        <div>
                                            <button type="button" data-project-rename data-project-action="/workspace/projects/<?= escape_html($sidebarProject['type']) ?>/<?= (int) $sidebarProject['id'] ?>" data-project-name="<?= escape_html($sidebarProject['name']) ?>"><i data-lucide="pencil"></i><span>重命名</span></button>
                                            <form method="post" action="/workspace/projects/<?= escape_html($sidebarProject['type']) ?>/<?= (int) $sidebarProject['id'] ?>"><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><input type="hidden" name="return_to" value="<?= escape_html($workspaceReturnTo) ?>"><input type="hidden" name="action" value="<?= $sidebarProject['pinned'] ? 'unpin' : 'pin' ?>"><button type="submit"><i data-lucide="<?= $sidebarProject['pinned'] ? 'pin-off' : 'pin' ?>"></i><span><?= $sidebarProject['pinned'] ? '取消置顶' : '置顶项目' ?></span></button></form>
                                            <form method="post" action="/workspace/projects/<?= escape_html($sidebarProject['type']) ?>/<?= (int) $sidebarProject['id'] ?>" data-confirm-message="仅从最近项目中移除，商品、任务和素材仍会保留。"><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><input type="hidden" name="return_to" value="<?= escape_html($workspaceReturnTo) ?>"><input type="hidden" name="action" value="hide"><button type="submit" class="danger"><i data-lucide="panel-left-close"></i><span>从项目栏移除</span></button></form>
                                        </div>
                                    </details>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <div class="merchant-sidebar-account">
            <a href="/workspace/account">
                <span class="user-avatar"><?= escape_html(mb_substr($user['name'], 0, 1)) ?></span>
                <span><strong><?= escape_html($merchant['name']) ?></strong><small><?= escape_html($workspacePlanName) ?> · <?= number_format((int) $merchant['credit_balance']) ?> 点</small></span>
                <i data-lucide="settings"></i>
            </a>
        </div>
    </aside>

    <div class="merchant-workspace-frame">
        <header class="merchant-studio-topbar">
            <div class="merchant-topbar-project">
                <button class="icon-button" type="button" data-studio-sidebar-toggle aria-label="打开项目栏" data-tooltip="项目栏"><i data-lucide="panel-left"></i></button>
                <span><strong><?= escape_html($workspaceProjectTitle) ?></strong><small><i data-lucide="circle-check"></i><?= escape_html($workspaceSaveState) ?></small></span>
            </div>
            <div class="merchant-topbar-actions">
                <a class="merchant-topbar-credit" href="/workspace/usage"><i data-lucide="coins"></i><span><small>可用点数</small><strong><?= number_format((int) $merchant['credit_balance']) ?></strong></span></a>
                <button class="icon-button merchant-task-button" type="button" data-workspace-task-open data-workspace-task-primary aria-label="<?= $merchantFailedJobCount > 0 ? (int) $merchantFailedJobCount . ' 个任务需要处理，打开生成进度' : '打开生成进度' ?>" data-tooltip="生成进度"><i data-lucide="list-checks"></i><span>生成进度</span><b class="is-attention" data-workspace-task-badge <?= $merchantFailedJobCount > 0 ? '' : 'hidden' ?>><?= $merchantFailedJobCount > 99 ? '99+' : (int) $merchantFailedJobCount ?></b></button>
                <button class="icon-button merchant-notification-button" type="button" data-workspace-task-open data-task-initial-tab="attention" aria-label="查看需要处理的任务" data-tooltip="通知"><i data-lucide="bell"></i><?php if ($merchantFailedJobCount > 0): ?><b><?= $merchantFailedJobCount > 9 ? '9+' : (int) $merchantFailedJobCount ?></b><?php endif; ?></button>
                <details class="workspace-account-menu" data-dismiss-on-outside>
                    <summary class="user-menu" aria-label="账户菜单" title="账户菜单" aria-haspopup="menu" aria-expanded="false"><span class="user-avatar"><?= escape_html(mb_substr($user['name'], 0, 1)) ?></span><i data-lucide="chevron-down"></i></summary>
                    <div class="workspace-account-popover">
                        <a href="/workspace/account"><i data-lucide="user-round-cog"></i><span>账户设置</span></a>
                        <a href="/workspace/plans"><i data-lucide="crown"></i><span>会员套餐</span></a>
                        <a href="/workspace/quality-appeals"><i data-lucide="shield-check"></i><span>质量申诉</span></a>
                        <a href="/workspace/feedback"><i data-lucide="message-square-more"></i><span>意见反馈</span></a>
                        <form method="post" action="/logout"><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><button class="logout-button" type="submit"><i data-lucide="log-out"></i><span>退出登录</span></button></form>
                    </div>
                </details>
            </div>
        </header>

        <aside class="job-failure-toast" data-job-failure-toast hidden aria-live="polite">
            <i data-lucide="circle-alert"></i>
            <div><strong>有生成任务需要处理</strong><span data-job-failure-toast-copy></span></div>
            <button type="button" data-workspace-task-open data-task-initial-tab="attention">查看并重试</button>
            <button class="icon-button" type="button" data-job-failure-toast-close aria-label="关闭提醒" data-tooltip="关闭提醒"><i data-lucide="x"></i></button>
        </aside>

        <button class="workspace-task-backdrop" type="button" data-workspace-task-backdrop hidden aria-label="关闭生成进度"></button>
        <aside class="workspace-task-drawer" data-workspace-task-drawer aria-hidden="true" aria-label="生成进度">
            <header>
                <div><strong>生成进度</strong><small data-workspace-task-overview>正在读取真实任务状态</small></div>
                <button class="icon-button" type="button" data-workspace-task-close aria-label="关闭生成进度"><i data-lucide="x"></i></button>
            </header>
            <nav class="workspace-task-tabs" aria-label="任务状态">
                <button class="active" type="button" data-workspace-task-tab="active">进行中</button>
                <button type="button" data-workspace-task-tab="queued">等待中</button>
                <button type="button" data-workspace-task-tab="attention">需要处理</button>
                <button type="button" data-workspace-task-tab="completed">最近完成</button>
            </nav>
            <div class="workspace-task-list" data-workspace-task-list aria-live="polite"></div>
            <footer><a href="/workspace/jobs"><i data-lucide="history"></i><span>查看全部生成记录</span><i data-lucide="arrow-right"></i></a></footer>
        </aside>

        <dialog class="workspace-project-dialog" data-project-rename-dialog>
            <form method="post" data-project-rename-form>
                <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
                <input type="hidden" name="return_to" value="<?= escape_html($workspaceReturnTo) ?>">
                <input type="hidden" name="action" value="rename">
                <header><div><strong>重命名项目</strong><small>只修改项目栏名称，不会改写商品资料。</small></div><button class="icon-button" type="button" data-project-rename-close aria-label="关闭"><i data-lucide="x"></i></button></header>
                <label><span>项目名称</span><input class="input" name="name" maxlength="120" required data-project-rename-input></label>
                <footer><button class="button" type="button" data-project-rename-close>取消</button><button class="button primary" type="submit">保存名称</button></footer>
            </form>
        </dialog>

        <main class="app-main merchant-studio-main">
            <div class="page-content<?= $isCreativeWorkspace ? ' studio-page-content' : '' ?>">
                <?php if ($flashSuccess): ?><div class="flash success" role="status"><i data-lucide="circle-check"></i><span><?= escape_html($flashSuccess) ?></span></div><?php endif; ?>
                <?php if ($flashError): ?><div class="flash error" role="alert"><i data-lucide="circle-alert"></i><span><?= escape_html($flashError) ?></span></div><?php endif; ?>
