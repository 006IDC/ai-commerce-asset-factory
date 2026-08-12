<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<div class="page-actions">
    <div><h2>全局搜索</h2><p>在商户、任务、商品和 AI 模型中定位对象。</p></div>
</div>
<form class="admin-object-search-page" method="get" action="/admin/object-search">
    <i data-lucide="search"></i>
    <input class="input" name="q" value="<?= escape_html($query) ?>" placeholder="输入商户、任务编号、商品、模型…" autofocus>
    <button class="button primary" type="submit">搜索</button>
</form>
<section class="panel panel-gap">
    <div class="panel-header"><div><h3>搜索结果</h3><p><?= $query === '' ? '输入关键词开始搜索。' : '“' . escape_html($query) . '”共找到 ' . count($results) . ' 项' ?></p></div></div>
    <div class="panel-body admin-global-results">
        <?php foreach ($results as $result): ?>
            <a href="<?= escape_html((string) $result['href']) ?>"><span class="badge"><?= escape_html((string) $result['type']) ?></span><span><strong><?= escape_html((string) $result['title']) ?></strong><small><?= escape_html((string) $result['meta']) ?></small></span><i data-lucide="arrow-up-right"></i></a>
        <?php endforeach; ?>
        <?php if ($query !== '' && !$results): ?><div class="empty-state"><i data-lucide="search-x"></i><strong>没有匹配对象</strong><span>可尝试任务编号、商户标识、商品名或模型 ID。</span></div><?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
