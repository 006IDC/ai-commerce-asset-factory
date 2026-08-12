<?php require __DIR__ . '/../partials/merchant_header.php'; ?>
<div class="page-actions">
    <div><h2>详情页方案</h2><p>方案会复用商品资料，保存内容脚本、平台和画面语言；生成图片时可选择 1-10 张。</p></div>
    <div class="button-row"><a class="button" href="/workspace/detail-pages/new"><i data-lucide="file-plus-2"></i><span>建立独立方案</span></a><a class="button primary" href="/workspace/generate?mode=detail"><i data-lucide="panels-top-left"></i><span>生成详情页图片</span></a></div>
</div>
<?php if (!$projects): ?>
<section class="panel"><div class="empty-state"><strong>还没有详情页方案</strong><span>从商品上新进入时，系统会复用商品资料自动建立方案；也可以用一张实拍图建立独立方案。</span><br><div class="button-row"><a class="button primary" href="/workspace/generate?mode=detail">选择商品并生成</a><a class="button" href="/workspace/detail-pages/new">建立独立方案</a></div></div></section>
<?php else: ?>
<div class="detail-project-grid">
    <?php foreach ($projects as $project): ?>
    <article class="detail-project-card">
        <a class="detail-project-cover" href="/workspace/detail-pages/<?= (int) $project['id'] ?>"><img src="<?= escape_html($project['source_image_url']) ?>" data-fallback="<?= escape_html($project['source_image_backup_url'] ?: '') ?>" alt="<?= escape_html($project['product_name']) ?>" loading="lazy"></a>
        <div class="detail-project-copy"><div><span class="badge <?= escape_html($project['status']) ?>"><?= escape_html(job_status_label($project['status'])) ?></span><small><?= escape_html(platform_label($project['platform']) . ' · ' . generation_language_label($project['language'])) ?></small></div><h3><a href="/workspace/detail-pages/<?= (int) $project['id'] ?>"><?= escape_html($project['product_name']) ?></a></h3><p><?= escape_html($project['category']) ?></p><div class="detail-project-meta"><span><?= (int) ($project['sku_id'] ?? 0) > 0 ? '已归档到商品档案' : '独立方案' ?></span><span><?= (int) $project['job_count'] ?> 次生成</span><span><?= escape_html($project['updated_at']) ?></span></div></div>
    </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../partials/merchant_footer.php'; ?>
