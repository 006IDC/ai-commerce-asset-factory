<?php require __DIR__ . '/../partials/merchant_header.php'; ?>
<div class="page-actions">
    <div><h2>商品档案</h2><p>每个档案集中保存商品图、商品资料、创作任务和全部素材。</p></div>
    <div class="button-row">
        <div class="sku-view-control" role="group" aria-label="商品档案显示方式"><button type="button" data-sku-view="grid" aria-label="卡片视图" title="卡片视图"><i data-lucide="grid-2x2"></i></button><button type="button" data-sku-view="list" aria-label="紧凑列表" title="紧凑列表"><i data-lucide="list"></i></button></div>
        <a class="button primary" href="/workspace/generate"><i data-lucide="image-plus"></i><span>开始一个新商品</span></a>
    </div>
</div>

<div class="view-tabs" role="tablist" aria-label="商品状态">
    <a class="<?= !$archived ? 'active' : '' ?>" href="/workspace/skus" role="tab" aria-selected="<?= !$archived ? 'true' : 'false' ?>">使用中 <span><?= (int) $stats['active'] ?></span></a>
    <a class="<?= $archived ? 'active' : '' ?>" href="/workspace/skus?view=archived" role="tab" aria-selected="<?= $archived ? 'true' : 'false' ?>">已归档 <span><?= (int) $stats['archived'] ?></span></a>
</div>

<section class="asset-filter-panel sku-filter-panel">
    <form class="asset-filter-form sku-filter-form" method="get" action="/workspace/skus">
        <?php if ($archived): ?><input type="hidden" name="view" value="archived"><?php endif; ?>
        <div class="form-field asset-search-field"><label for="sku-q">搜索商品</label><div class="input-with-icon"><i data-lucide="search"></i><input class="input" id="sku-q" name="q" value="<?= escape_html($keyword) ?>" placeholder="商品名称、SKU、卖点或资料内容"></div></div>
        <div class="form-field"><label for="sku-category">类目</label><select class="input" id="sku-category" name="category"><option value="">全部类目</option><?php foreach ($categories as $option): ?><option value="<?= escape_html($option) ?>" <?= $category === (string) $option ? 'selected' : '' ?>><?= escape_html($option) ?></option><?php endforeach; ?></select></div>
        <div class="form-field"><label for="sku-image">商品图</label><select class="input" id="sku-image" name="image"><option value="">全部状态</option><option value="with" <?= $imageState === 'with' ? 'selected' : '' ?>>已有商品图</option><option value="without" <?= $imageState === 'without' ? 'selected' : '' ?>>待补商品图</option></select></div>
        <div class="form-field"><label for="sku-sort">排序</label><select class="input" id="sku-sort" name="sort"><option value="latest" <?= $sort === 'latest' ? 'selected' : '' ?>>最近更新</option><option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>最早创建</option><option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>商品名称</option><option value="assets" <?= $sort === 'assets' ? 'selected' : '' ?>>素材最多</option><option value="jobs" <?= $sort === 'jobs' ? 'selected' : '' ?>>任务最多</option></select></div>
        <div class="asset-filter-actions"><button class="button primary" type="submit">筛选</button><a class="button" href="<?= $archived ? '/workspace/skus?view=archived' : '/workspace/skus' ?>">重置</a></div>
    </form>
</section>

<?php if (!$skus): ?>
    <section class="panel"><div class="empty-state"><i data-lucide="package-search"></i><strong><?= $archived ? '暂无已归档商品' : '没有符合条件的商品档案' ?></strong><span><?= $archived ? '归档商品会保留历史任务和素材。' : '从上传商品图开始，系统会自动建立档案。' ?></span><?php if (!$archived): ?><a class="button primary" href="/workspace/generate">开始第一个商品</a><?php endif; ?></div></section>
<?php else: ?>
    <div class="sku-catalog-grid" data-sku-catalog data-view="grid">
        <?php foreach ($skus as $sku):
            $points = array_slice(decode_json_list($sku['selling_points']), 0, 2);
            $hasImage = trim((string) ($sku['source_image_path'] ?? '')) !== '';
            $ready = (string) ($sku['report_status'] ?? '') === 'ready';
            $completeness = (int) ($sku['profile_completeness'] ?? 0);
            $missingItems = array_values((array) ($sku['profile_missing_items'] ?? []));
            $firstMissing = $missingItems[0] ?? ['section' => 'profile', 'anchor' => ''];
            $completionHref = '/workspace/skus/' . (int) $sku['id'] . '?section=' . urlencode((string) ($firstMissing['section'] ?? 'profile'));
            if (trim((string) ($firstMissing['anchor'] ?? '')) !== '') {
                $completionHref .= '#' . rawurlencode((string) $firstMissing['anchor']);
            }
            $primaryHref = !$hasImage
                ? '/workspace/skus/' . (int) $sku['id'] . '?section=profile'
                : '/workspace/generate?mode=bundle&sku_id=' . (int) $sku['id'];
            $primaryLabel = !$hasImage ? '补充商品图' : '继续商品创作';
        ?>
            <article class="sku-catalog-card" data-completeness="<?= $completeness < 60 ? 'low' : 'ready' ?>">
                <a class="sku-catalog-image" href="/workspace/skus/<?= (int) $sku['id'] ?>?section=overview">
                    <?php if ($hasImage): ?><img src="<?= escape_html($sku['source_image_path']) ?>" data-fallback="<?= escape_html($sku['source_image_backup_path'] ?: '') ?>" alt="<?= escape_html($sku['name']) ?>" loading="lazy"><?php else: ?><span><i data-lucide="image-plus"></i><small>待添加商品图</small></span><?php endif; ?>
                    <span class="badge <?= escape_html($sku['status']) ?>"><?= escape_html(record_status_label($sku['status'])) ?></span>
                </a>
                <div class="sku-catalog-body">
                    <div class="sku-catalog-heading"><div><h3><a href="/workspace/skus/<?= (int) $sku['id'] ?>?section=overview"><?= escape_html($sku['name']) ?></a></h3><span class="code-value"><?= escape_html($sku['sku_code']) ?></span></div><span class="sku-category-label"><?= escape_html($sku['category'] ?: '未设置类目') ?></span></div>
                    <div class="sku-completeness">
                        <div><span><i style="width: <?= $completeness ?>%"></i></span><small>资料完整度 <b><?= $completeness ?>%</b></small></div>
                        <?php if ($missingItems): ?>
                            <a href="<?= escape_html($completionHref) ?>"><i data-lucide="circle-alert"></i><span>待补：<?= escape_html(implode('、', array_column(array_slice($missingItems, 0, 2), 'label'))) ?><?= count($missingItems) > 2 ? ' 等 ' . count($missingItems) . ' 项' : '' ?></span><b>去补充</b></a>
                        <?php else: ?>
                            <span class="sku-completeness-ready"><i data-lucide="badge-check"></i>资料已完整，可直接用于创作</span>
                        <?php endif; ?>
                    </div>
                    <div class="sku-point-list"><?php if ($points): ?><?php foreach ($points as $point): ?><span><?= escape_html($point) ?></span><?php endforeach; ?><?php else: ?><span class="muted">AI 识别后会在这里显示商品卖点</span><?php endif; ?></div>
                    <div class="sku-catalog-metrics">
                        <a href="/workspace/assets?sku_id=<?= (int) $sku['id'] ?>"><small>素材</small><strong><?= (int) $sku['asset_count'] ?></strong></a>
                        <a href="/workspace/jobs?sku_id=<?= (int) $sku['id'] ?>"><small>任务</small><strong><?= (int) $sku['job_count'] ?></strong></a>
                        <a href="/workspace/skus/<?= (int) $sku['id'] ?>?section=foundation"><small>商品资料</small><strong><?= $ready ? '可用' : '待完善' ?></strong></a>
                        <span><small>目标平台</small><strong><?= escape_html(platform_label($sku['preferred_platform'] ?? '') ?: '未设置') ?></strong></span>
                    </div>
                    <div class="sku-last-job"><span>最近任务</span><strong><?= escape_html($sku['last_job_at'] ?: '尚未生成') ?></strong></div>
                </div>
                <div class="sku-catalog-actions">
                    <?php if (!$archived): ?>
                        <a class="button primary" href="<?= escape_html($primaryHref) ?>"><i data-lucide="<?= $hasImage ? 'sparkles' : 'image-plus' ?>"></i><span><?= escape_html($primaryLabel) ?></span></a>
                        <a class="button" href="/workspace/skus/<?= (int) $sku['id'] ?>?section=overview"><i data-lucide="layout-dashboard"></i><span>查看档案</span></a>
                        <details class="sku-card-menu" data-dismiss-on-outside>
                            <summary aria-label="更多商品操作" title="更多商品操作"><i data-lucide="ellipsis"></i></summary>
                            <div>
                                <a href="/workspace/skus/<?= (int) $sku['id'] ?>?section=profile"><i data-lucide="file-pen-line"></i><span>编辑商品资料</span></a>
                                <a href="/workspace/generate?mode=assets&sku_id=<?= (int) $sku['id'] ?>"><i data-lucide="image"></i><span>生成商品图片</span></a>
                                <a href="/workspace/generate?mode=detail&sku_id=<?= (int) $sku['id'] ?>"><i data-lucide="panels-top-left"></i><span>生成详情页</span></a>
                                <a href="/workspace/assets?sku_id=<?= (int) $sku['id'] ?>"><i data-lucide="images"></i><span>浏览全部素材</span></a>
                                <form method="post" action="/workspace/skus/<?= (int) $sku['id'] ?>/archive" data-confirm-message="归档后不能创建新任务，但历史素材仍会保留。确定归档吗？"><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><input type="hidden" name="return_to" value="<?= escape_html($returnTo) ?>"><button type="submit"><i data-lucide="archive"></i><span>归档商品</span></button></form>
                            </div>
                        </details>
                    <?php else: ?>
                        <a class="button" href="/workspace/assets?sku_id=<?= (int) $sku['id'] ?>"><i data-lucide="images"></i><span>查看历史素材</span></a>
                        <form method="post" action="/workspace/skus/<?= (int) $sku['id'] ?>/archive"><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="return_to" value="<?= escape_html($returnTo) ?>"><button class="button primary" type="submit"><i data-lucide="undo-2"></i><span>恢复商品</span></button></form>
                        <form method="post" action="/workspace/skus/<?= (int) $sku['id'] ?>/delete" data-confirm-message="确定彻底删除这个商品档案吗？该商品下的生成图片会立即清理，后台审计记录仍会保留。此操作无法恢复。"><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><button class="icon-button danger" type="submit" aria-label="彻底删除商品" title="彻底删除商品"><i data-lucide="trash-2"></i></button></form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../partials/merchant_footer.php'; ?>
