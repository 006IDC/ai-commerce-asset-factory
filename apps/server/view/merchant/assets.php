<?php
require __DIR__ . '/../partials/merchant_header.php';
$currentQuery = [
    'q' => $keyword ?: null,
    'category' => ($category !== '' && $category !== '__free') ? $category : null,
    'sku_id' => $skuId ?: null,
    'detail_project_id' => $detailProjectId ?: null,
    'type' => $assetType ?: null,
    'source' => $source ?: null,
    'platform' => $platform ?: null,
    'usage' => $usage ?: null,
    'favorite' => $favoriteOnly ? 1 : null,
    'sort' => $sort !== 'latest' ? $sort : null,
    'layout' => $layout !== 'grid' ? $layout : null,
    'trash' => $trash ? 1 : null,
];
$assetUrl = static function (array $changes = []) use ($currentQuery): string {
    $query = array_merge($currentQuery, $changes);
    $query = array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '');
    return '/workspace/assets' . ($query === [] ? '' : '?' . http_build_query($query));
};
?>
<div class="asset-explorer">
    <aside class="asset-folder-tree asset-resource-tree" aria-label="素材资源管理器">
        <div class="asset-folder-tree-heading"><i data-lucide="folder-tree"></i><span>素材资源管理器</span></div>
        <nav class="asset-tree-root">
            <a class="<?= $skuId === 0 && !$favoriteOnly && $source === '' && !$trash ? 'active' : '' ?>" href="/workspace/assets"><i data-lucide="hard-drive"></i><span>全部素材</span><b><?= (int) $stats['active'] ?></b></a>
            <a class="<?= $favoriteOnly ? 'active' : '' ?>" href="/workspace/assets?favorite=1"><i data-lucide="star"></i><span>已收藏</span><b><?= (int) $stats['favorites'] ?></b></a>
            <a class="<?= $source === 'free' ? 'active' : '' ?>" href="/workspace/assets?source=free"><i data-lucide="wand-sparkles"></i><span>自由创作</span><b><?= (int) $stats['free'] ?></b></a>
            <a class="<?= $source === 'unclassified' ? 'active' : '' ?>" href="/workspace/assets?source=unclassified"><i data-lucide="inbox"></i><span>未归类</span><b><?= (int) $stats['unclassified'] ?></b></a>
            <a class="<?= $trash ? 'active' : '' ?>" href="/workspace/assets?trash=1"><i data-lucide="trash-2"></i><span>回收站</span><b><?= (int) $stats['trash'] ?></b></a>
        </nav>
        <div class="asset-tree-section-label"><span>全部 SKU</span><small><?= count($assetTree) ?> 个</small></div>
        <div class="asset-tree-search input-with-icon"><i data-lucide="search"></i><input class="input" type="search" placeholder="搜索商品或 SKU" aria-label="搜索 SKU 文件夹" data-asset-tree-search></div>
        <nav class="asset-tree-skus" data-asset-tree-list>
            <?php foreach ($assetTree as $treeSku): $treeSkuId = (int) $treeSku['id']; ?>
                <a class="asset-tree-item <?= $skuId === $treeSkuId ? 'active' : '' ?>" href="/workspace/assets?sku_id=<?= $treeSkuId ?>" data-asset-tree-item data-search-text="<?= escape_html(mb_strtolower((string) ($treeSku['name'] . ' ' . $treeSku['sku_code'] . ' ' . $treeSku['category']))) ?>">
                    <span class="asset-tree-item-thumb"><?php if ((string) $treeSku['source_image_path'] !== ''): ?><img src="<?= escape_html((string) $treeSku['source_image_path']) ?>" data-fallback="<?= escape_html((string) ($treeSku['source_image_backup_path'] ?? '')) ?>" alt=""><?php else: ?><i data-lucide="package"></i><?php endif; ?></span>
                    <span><strong><?= escape_html((string) $treeSku['name']) ?></strong><small><?= escape_html((string) $treeSku['sku_code']) ?></small></span><b><?= (int) $treeSku['total'] ?></b>
                </a>
            <?php endforeach; ?>
            <span class="asset-tree-empty" hidden data-asset-tree-empty>没有匹配的 SKU</span>
        </nav>
    </aside>

    <main class="asset-explorer-content">
        <div class="page-actions">
            <div><h2><?= $selectedSku ? escape_html((string) $selectedSku['name']) : '素材管理' ?></h2><p><?= $selectedSku ? escape_html((string) $selectedSku['sku_code']) . ' · ' . escape_html((string) ($selectedSku['category'] ?: '未设置类目')) : '按商品与生成批次管理 ' . (int) $stats['active'] . ' 张素材 · ' . (int) $stats['batches'] . ' 个批次。' ?></p></div>
            <div class="button-row"><button class="button" type="button" data-open-asset-filter><i data-lucide="list-filter"></i><span>筛选</span></button><a class="button primary" href="/workspace/generate<?= $skuId > 0 ? '?sku_id=' . $skuId : '' ?>"><i data-lucide="sparkles"></i><span>生成素材</span></a></div>
        </div>

        <?php if ($selectedSku): ?>
            <section class="asset-sku-workspace-header" aria-label="SKU 原始参考图">
                <div class="asset-reference-strip">
                    <?php if ((string) $selectedSku['source_image_path'] !== ''): ?>
                        <button type="button" class="asset-reference-preview" aria-label="查看商品原始参考图" data-asset-preview-src="<?= escape_html((string) $selectedSku['source_image_path']) ?>" data-asset-preview-fallback="<?= escape_html((string) ($selectedSku['source_image_backup_path'] ?? '')) ?>" data-asset-preview-title="商品原始参考图" data-asset-preview-group="sku-reference-<?= (int) $selectedSku['id'] ?>" data-asset-preview-id=""><img src="<?= escape_html((string) $selectedSku['source_image_path']) ?>" data-fallback="<?= escape_html((string) ($selectedSku['source_image_backup_path'] ?? '')) ?>" alt=""></button>
                    <?php else: ?>
                        <span class="asset-reference-placeholder"><i data-lucide="image-off"></i></span>
                    <?php endif; ?>
                    <div><span>商品原始参考图</span><strong><?= (string) $selectedSku['source_image_path'] !== '' ? '生成时用于保持商品外观一致' : '当前 SKU 尚未上传参考图' ?></strong><small>原始图固定在 SKU 根目录，不参与删除和批量归档。</small></div>
                    <div class="button-row"><a class="button small" href="/workspace/skus/<?= (int) $selectedSku['id'] ?>?section=profile"><i data-lucide="file-pen-line"></i><span>修改商品资料</span></a><a class="button small primary" href="/workspace/generate?sku_id=<?= (int) $selectedSku['id'] ?>"><i data-lucide="sparkles"></i><span>继续生成</span></a></div>
                </div>
            </section>
        <?php endif; ?>

        <div class="asset-browse-toolbar">
            <?php if (!$trash): ?>
                <nav class="asset-smart-views" aria-label="素材用途视图">
                    <?php foreach ($usageOptions as $value => $option): ?>
                        <a class="<?= $usage === $value ? 'active' : '' ?>" href="<?= escape_html($assetUrl(['usage' => $value ?: null])) ?>"><i data-lucide="<?= escape_html((string) $option['icon']) ?>"></i><span><?= escape_html((string) $option['label']) ?></span></a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
            <div class="view-tabs asset-density-tabs" aria-label="素材管理视图">
                <a class="<?= $layout === 'grid' ? 'active' : '' ?>" href="<?= escape_html($assetUrl(['layout' => null])) ?>" aria-label="大图浏览，适合检查画面细节">
                    <i data-lucide="grid-2x2"></i><span><strong>大图浏览</strong><small>检查画面细节</small></span>
                </a>
                <a class="<?= $layout === 'compact' ? 'active' : '' ?>" href="<?= escape_html($assetUrl(['layout' => 'compact'])) ?>" aria-label="批量整理，适合选择和归档素材">
                    <i data-lucide="list-checks"></i><span><strong>批量整理</strong><small>选择、归档与下载</small></span>
                </a>
            </div>
        </div>

        <form class="asset-filter-panel asset-filter-form" method="get" action="/workspace/assets">
            <?php foreach (['category' => $category !== '__free' ? $category : '', 'sku_id' => $skuId, 'detail_project_id' => $detailProjectId, 'type' => $assetType, 'source' => $source, 'platform' => $platform, 'usage' => $usage, 'favorite' => $favoriteOnly ? 1 : '', 'trash' => $trash ? 1 : '', 'layout' => $layout !== 'grid' ? $layout : ''] as $name => $value): if ($value === '' || $value === 0) continue; ?><input type="hidden" name="<?= escape_html($name) ?>" value="<?= escape_html((string) $value) ?>"><?php endforeach; ?>
            <div class="form-field asset-search-field"><label for="asset-quick-q">搜索素材</label><div class="input-with-icon"><i data-lucide="search"></i><input class="input" id="asset-quick-q" name="q" value="<?= escape_html($keyword) ?>" placeholder="商品名、SKU、素材类型或任务号"></div></div>
            <div class="form-field"><label for="asset-quick-sort">排序</label><select class="input" id="asset-quick-sort" name="sort"><option value="latest" <?= $sort === 'latest' ? 'selected' : '' ?>>最新生成</option><option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>最早生成</option><?php if (!$trash): ?><option value="favorite" <?= $sort === 'favorite' ? 'selected' : '' ?>>收藏优先</option><?php endif; ?></select></div>
            <div class="asset-filter-actions"><button class="button primary" type="submit"><i data-lucide="search"></i><span>搜索</span></button></div>
        </form>

        <?php if ($trash): ?><div class="gateway-state asset-trash-notice"><strong>回收站素材保留 7 天</strong><p>到期后自动清理，也可以手动恢复或彻底删除。</p></div><?php endif; ?>
        <?php if ($hasFilters): ?><div class="asset-filter-summary"><div><i data-lucide="list-filter"></i><span>当前展示已按资源范围或用途筛选，共 <?= count($assets) ?> 张素材。</span></div><a class="button small" href="<?= $trash ? '/workspace/assets?trash=1' : '/workspace/assets' ?>">清除筛选</a></div><?php endif; ?>

        <dialog class="asset-library-drawer" data-asset-filter-drawer>
            <div class="asset-drawer-header"><div><strong>筛选素材</strong><small>组合商品、来源、平台与素材类型快速定位。</small></div><button type="button" aria-label="关闭筛选" data-close-asset-filter><i data-lucide="x"></i></button></div>
            <form class="asset-drawer-form" method="get" action="/workspace/assets">
                <?php if ($trash): ?><input type="hidden" name="trash" value="1"><?php endif; ?>
                <fieldset class="asset-category-selector asset-source-selector"><legend>素材来源</legend>
                    <?php foreach (['' => ['layout-grid', '全部素材'], 'sku' => ['package', 'SKU 素材'], 'free' => ['wand-sparkles', '自由创作'], 'canvas' => ['paintbrush', '画布编辑'], 'unclassified' => ['inbox', '未归类']] as $value => $option): ?><label><input type="radio" name="source" value="<?= escape_html($value) ?>" <?= $source === $value ? 'checked' : '' ?>><span><i data-lucide="<?= $option[0] ?>"></i><?= $option[1] ?></span></label><?php endforeach; ?>
                </fieldset>
                <div class="asset-drawer-fields">
                    <div class="asset-filter-dependent" data-asset-source-fields="sku" <?= $source === 'sku' ? '' : 'hidden' ?>><div class="form-field"><label for="asset-sku">SKU 商品</label><select class="input" id="asset-sku" name="sku_id" <?= $source === 'sku' ? '' : 'disabled' ?>><option value="">全部 SKU</option><?php foreach ($skus as $sku): ?><option value="<?= (int) $sku['id'] ?>" <?= $skuId === (int) $sku['id'] ? 'selected' : '' ?>><?= escape_html((string) ($sku['sku_code'] . ' · ' . $sku['name'])) ?></option><?php endforeach; ?></select></div></div>
                    <div class="form-field"><label for="asset-platform">目标平台</label><select class="input" id="asset-platform" name="platform"><option value="">全部平台</option><?php foreach ($platformOptions as $value => $label): ?><option value="<?= escape_html($value) ?>" <?= $platform === $value ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select></div>
                    <div class="form-field"><label for="asset-type">素材类型</label><select class="input" id="asset-type" name="type"><option value="">全部类型</option><?php foreach (asset_library_type_options() as $value => $option): ?><option value="<?= escape_html($value) ?>" <?= $assetType === $value ? 'selected' : '' ?>><?= escape_html((string) $option['label']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-field"><label for="asset-q">搜索</label><input class="input" id="asset-q" name="q" value="<?= escape_html($keyword) ?>" placeholder="商品名、SKU 或任务号"></div>
                    <?php if (!$trash): ?><label class="asset-drawer-favorite"><input type="checkbox" name="favorite" value="1" <?= $favoriteOnly ? 'checked' : '' ?>><span><i data-lucide="star"></i>只显示已收藏</span></label><?php endif; ?>
                </div>
                <div class="asset-drawer-footer"><a class="button" href="<?= $trash ? '/workspace/assets?trash=1' : '/workspace/assets' ?>">清除条件</a><button class="button primary" type="submit"><i data-lucide="check"></i><span>应用筛选</span></button></div>
            </form>
        </dialog>

        <?php if (!$assets): ?>
            <section class="panel"><div class="empty-state"><strong><?= $trash ? '回收站为空' : ($selectedSku ? '这个 SKU 暂无生成素材' : '没有符合条件的素材') ?></strong><span><?= $trash ? '移入回收站的素材会在这里保留 7 天。' : '可以调整筛选条件，或创建新的生成任务。' ?></span><?php if (!$trash): ?><br><a class="button primary" href="/workspace/generate<?= $skuId > 0 ? '?sku_id=' . $skuId : '' ?>">创建任务</a><?php endif; ?></div></section>
        <?php else: ?>
            <form id="asset-bulk-form" method="post" action="/workspace/assets/bulk-action" data-asset-bulk-form data-submit-loading data-loading-message="正在处理选中的素材..."><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><input type="hidden" name="return_to" value="<?= escape_html($returnTo) ?>"></form>
            <div class="asset-bulk-toolbar" data-asset-bulk-toolbar data-selection-count="0">
                <label><input type="checkbox" data-select-all-assets><span>全选当前结果</span></label><span data-selected-assets>已选择 0 张</span>
                <div class="button-row">
                    <?php if ($trash): ?>
                        <button class="button small" type="submit" name="bulk_action" value="restore" form="asset-bulk-form" disabled data-bulk-action><i data-lucide="undo-2"></i><span>恢复</span></button><button class="button small danger" type="submit" name="bulk_action" value="purge" form="asset-bulk-form" disabled data-bulk-action data-confirm-message="确定彻底删除选中的素材吗？"><i data-lucide="trash-2"></i><span>彻底删除</span></button>
                    <?php else: ?>
                        <div class="asset-bulk-assign"><select class="input" name="target_sku_id" form="asset-bulk-form" aria-label="归档到 SKU" data-asset-assign-target><option value="">归档到 SKU...</option><?php foreach ($skus as $sku): ?><option value="<?= (int) $sku['id'] ?>"><?= escape_html((string) ($sku['sku_code'] . ' · ' . $sku['name'])) ?></option><?php endforeach; ?></select><button class="button small" type="submit" name="bulk_action" value="assign_sku" form="asset-bulk-form" disabled data-bulk-action data-requires-assign-target><i data-lucide="folder-input"></i><span>归档</span></button></div>
                        <button class="button small primary" type="button" disabled data-bulk-action data-download-assets data-download-scope="selected"><i data-lucide="file-archive"></i><span>打包下载</span></button><button class="button small" type="submit" name="bulk_action" value="favorite" form="asset-bulk-form" disabled data-bulk-action><i data-lucide="star"></i><span>收藏</span></button><button class="button small danger" type="submit" name="bulk_action" value="delete" form="asset-bulk-form" disabled data-bulk-action data-confirm-message="确定把选中的素材移入回收站吗？"><i data-lucide="trash-2"></i><span>删除</span></button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="asset-groups <?= $layout === 'compact' ? 'asset-groups-organize' : 'asset-groups-browse' ?>" data-asset-manager data-asset-layout="<?= $layout === 'compact' ? 'organize' : 'browse' ?>">
                <?php foreach ($assetGroups as $group):
                    $groupAssetIds = [];
                    foreach ($group['batches'] as $batchRows) array_push($groupAssetIds, ...array_map(static fn (array $row): int => (int) $row['id'], $batchRows['assets']));
                ?>
                    <section class="asset-group">
                        <div class="asset-group-header"><div><div class="asset-group-title"><i data-lucide="<?= (int) $group['skuId'] > 0 ? 'package' : ($group['sourceLabel'] === '自由创作' ? 'wand-sparkles' : ($group['sourceLabel'] === '未归类' ? 'inbox' : 'paintbrush')) ?>"></i><h3><?= escape_html((string) $group['skuName']) ?></h3><span><?= escape_html((string) $group['skuCode']) ?></span></div><p><?= escape_html((string) $group['sourceLabel']) ?> · <?= (int) $group['assetCount'] ?> 张 · <?= count($group['batches']) ?> 个批次</p></div><div class="button-row"><button class="button small" type="button" data-select-asset-group><i data-lucide="list-checks"></i><span>全选此组</span></button><button class="button small" type="button" data-download-assets data-download-asset-ids="<?= escape_html(implode(',', $groupAssetIds)) ?>" data-download-scope-label="<?= escape_html((string) $group['skuName']) ?> · 全部素材"><i data-lucide="file-archive"></i><span>打包下载</span></button><?php if ((int) $group['skuId'] > 0): ?><a class="button small" href="/workspace/skus/<?= (int) $group['skuId'] ?>">商品档案</a><?php endif; ?></div></div>
                        <div class="asset-batch-list">
                            <?php $batchIndex = 0; foreach ($group['batches'] as $batch):
                                $batchAssetIds = array_map(static fn (array $row): int => (int) $row['id'], $batch['assets']);
                                $assetPreviewGroup = 'batch-' . md5((string) $batch['key']);
                            ?>
                                <details class="asset-batch" <?= $batchIndex < 2 ? 'open' : '' ?> data-asset-batch>
                                    <summary class="asset-batch-summary"><span class="asset-batch-icon"><i data-lucide="folder-open"></i></span><span class="asset-batch-copy"><strong><?= escape_html((string) $batch['displayLabel']) ?></strong><small><?= escape_html(platform_label((string) $batch['platform'])) ?> · <?= count($batch['assets']) ?> 张 · <?= escape_html((string) $batch['createdAt']) ?></small></span><span class="asset-batch-tags"><?php foreach (array_slice(array_values($batch['typeLabels']), 0, 3) as $typeLabel): ?><em><?= escape_html((string) $typeLabel) ?></em><?php endforeach; ?></span><i data-lucide="chevron-down"></i></summary>
                                    <div class="asset-batch-body">
                                        <div class="asset-batch-toolbar"><div><span><?= escape_html((string) $batch['displayLabel']) ?></span><?= (string) $batch['bundleNo'] !== '' ? '<small>整套任务已合并展示</small>' : '' ?><details class="asset-technical-meta"><summary>高级信息</summary><code><?= escape_html((string) $batch['jobNo']) ?><?= (int) $batch['detailProjectId'] > 0 ? ' · DETAIL-' . (int) $batch['detailProjectId'] : '' ?></code></details></div><div class="button-row"><button class="button small" type="button" data-asset-batch-rename data-batch-key="<?= escape_html((string) $batch['key']) ?>" data-batch-label="<?= escape_html((string) $batch['label']) ?>" data-batch-default-label="<?= escape_html((string) $batch['defaultLabel']) ?>"><i data-lucide="pencil"></i><span>重命名</span></button><button class="button small" type="button" data-select-asset-group><i data-lucide="list-checks"></i><span>选择此批</span></button><button class="button small" type="button" data-download-assets data-download-asset-ids="<?= escape_html(implode(',', $batchAssetIds)) ?>" data-download-scope-label="<?= escape_html((string) $batch['displayLabel']) ?>"><i data-lucide="file-archive"></i><span>下载此批</span></button><?php if ((int) $batch['detailProjectId'] > 0): ?><a class="button small" href="/workspace/detail-pages/<?= (int) $batch['detailProjectId'] ?>">查看详情页方案</a><?php endif; ?></div></div>
                                        <div class="asset-grid"><?php foreach ($batch['assets'] as $assetIndex => $asset): $assetVersion = $assetIndex + 1; require __DIR__ . '/../partials/asset_card.php'; endforeach; ?></div>
                                    </div>
                                </details>
                            <?php $batchIndex++; endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
            <aside class="asset-detail-panel" data-asset-detail-panel hidden aria-label="素材详情">
                <header><div><strong>素材详情</strong><small>核对版本、来源与生成时间</small></div><button class="button icon-only" type="button" aria-label="关闭素材详情" title="关闭素材详情" data-asset-detail-close><i data-lucide="x"></i></button></header>
                <img src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=" alt="" data-asset-detail-image>
                <h3 data-asset-detail-title>素材详情</h3>
                <p data-asset-detail-meta></p>
                <div class="button-row"><a class="button" href="#" target="_blank" rel="noopener" data-asset-detail-open><i data-lucide="maximize-2"></i><span>查看原图</span></a><a class="button primary" href="#" data-asset-detail-download><i data-lucide="download"></i><span>下载</span></a></div>
            </aside>
        <?php endif; ?>

        <dialog class="asset-batch-dialog" data-asset-batch-dialog>
            <form method="post" action="/workspace/assets/batch-label" data-submit-loading><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><input type="hidden" name="return_to" value="<?= escape_html($returnTo) ?>"><input type="hidden" name="batch_key" value="" data-asset-batch-key>
                <div class="asset-drawer-header"><div><strong>重命名素材批次</strong><small>只改变素材库中的显示名称，不修改任务记录。</small></div><button type="button" aria-label="关闭" data-close-asset-batch-dialog><i data-lucide="x"></i></button></div>
                <div class="form-field"><label for="asset-batch-label">批次名称</label><input class="input" id="asset-batch-label" name="label" maxlength="120" placeholder="留空恢复默认名称" data-asset-batch-label><small data-asset-batch-default></small></div>
                <div class="asset-drawer-footer"><button class="button" type="button" data-close-asset-batch-dialog>取消</button><button class="button primary" type="submit"><i data-lucide="check"></i><span>保存名称</span></button></div>
            </form>
        </dialog>

        <dialog class="asset-viewer" data-asset-viewer>
            <div class="asset-viewer-heading"><div class="asset-viewer-title"><strong data-asset-viewer-title></strong><span data-asset-viewer-counter></span></div><div><button type="button" aria-label="缩小图片" data-asset-viewer-minus><i data-lucide="zoom-out"></i></button><output data-asset-viewer-zoom>100%</output><button type="button" aria-label="放大图片" data-asset-viewer-plus><i data-lucide="zoom-in"></i></button><a href="#" aria-label="下载当前图片" data-asset-viewer-download><i data-lucide="download"></i></a><button type="button" aria-label="关闭预览" data-asset-viewer-close><i data-lucide="x"></i></button></div></div>
            <div class="asset-viewer-body"><button class="asset-viewer-nav previous" type="button" aria-label="上一张" data-asset-viewer-previous><i data-lucide="chevron-left"></i></button><div class="asset-viewer-stage" data-asset-viewer-stage><div class="asset-viewer-content" data-asset-viewer-content><img src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=" alt="" data-asset-viewer-image></div></div><button class="asset-viewer-nav next" type="button" aria-label="下一张" data-asset-viewer-next><i data-lucide="chevron-right"></i></button></div>
        </dialog>
    </main>
</div>
<?php require __DIR__ . '/../partials/merchant_footer.php'; ?>
