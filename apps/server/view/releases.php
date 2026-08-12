<?php require __DIR__ . '/partials/public_header.php'; ?>
<section class="landing-releases-page" aria-labelledby="public-releases-title">
    <div class="landing-container">
        <header class="landing-section-heading">
            <span class="landing-kicker">公开版本历程</span>
            <h1 id="public-releases-title">从创作工作台到可追踪的素材生产</h1>
            <p>这里只记录已经完成且有内部事实来源的产品变化。版本号是主线，日期和内部部署细节不作为宣传内容。</p>
        </header>
        <nav class="landing-release-page-filters" aria-label="版本筛选">
            <?php foreach ($publicReleaseFilters as $value => $label): ?><a class="<?= $activeReleaseFilter === $value ? 'active' : '' ?>" href="/releases?filter=<?= rawurlencode((string) $value) ?>"><?= escape_html((string) $label) ?></a><?php endforeach; ?>
        </nav>
        <div class="landing-release-page-list">
            <?php foreach ($publicReleases as $release): ?>
            <article>
                <div class="landing-release-version"><b>v<?= escape_html((string) $release['version']) ?></b><?php if (!empty($release['milestone'])): ?><span>里程碑</span><?php endif; ?></div>
                <div><h2><?= escape_html((string) $release['public_title']) ?></h2><p><?= escape_html((string) $release['public_summary']) ?></p><ul><?php foreach ($release['highlights'] as $highlight): ?><li><?= escape_html((string) $highlight) ?></li><?php endforeach; ?></ul></div>
            </article>
            <?php endforeach; ?>
            <?php if (!$publicReleases): ?><div class="landing-empty-state"><div><strong>当前筛选没有版本记录</strong><p>请切换到“全部”查看公开版本历程。</p></div></div><?php endif; ?>
        </div>
    </div>
</section>
<?php require __DIR__ . '/partials/public_footer.php'; ?>
