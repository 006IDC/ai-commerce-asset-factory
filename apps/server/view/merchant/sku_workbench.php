<?php
$additionalReferences = json_decode((string) ($sku['reference_images_json'] ?? '[]'), true);
$additionalReferences = is_array($additionalReferences) ? array_values(array_filter($additionalReferences, 'is_array')) : [];
$primaryColors = [];
if (preg_match_all('/(?:米白|奶白|象牙白|藏青|墨绿|玫瑰金|香槟金|(?:深|浅|亮|暗)?[红橙黄绿青蓝紫粉棕褐黑白灰金银]色)/u', (string) ($sku['report_appearance'] ?? ''), $colorMatches) > 0) {
    $primaryColors = array_values(array_unique(array_slice($colorMatches[0], 0, 8)));
}
$targets = array_values((array) ($workbench['targets'] ?? []));
$latestProject = $detailProjects[0] ?? null;
$workbenchReady = !empty($workbench['ready']);
$useLinks = [
    'full_package' => '/workspace/generate?mode=bundle&sku_id=' . (int) $sku['id'] . ($latestProject ? '&project_id=' . (int) $latestProject['id'] : ''),
    'listing_images' => '/workspace/generate?mode=assets&sku_id=' . (int) $sku['id'],
    'detail_page' => '/workspace/generate?mode=detail&sku_id=' . (int) $sku['id'] . ($latestProject ? '&project_id=' . (int) $latestProject['id'] : ''),
];
require __DIR__ . '/../partials/merchant_header.php';
?>
<div class="one-flow-workbench" data-one-flow-workbench data-legacy-anchor="<?= escape_html((string) ($legacyAnchor ?? '')) ?>">
    <header class="one-flow-hero" id="workbench-overview">
        <div>
            <a href="/workspace/skus" class="back-link"><i data-lucide="arrow-left"></i>商品档案</a>
            <span class="eyebrow">PRODUCT LAUNCH</span>
            <h1><?= escape_html($sku['name']) ?></h1>
            <p><span class="code-value"><?= escape_html($sku['sku_code']) ?></span> · 商品资料、产品报告和上新创作都在这里。</p>
        </div>
        <div class="one-flow-hero-actions">
            <span class="badge <?= $workbenchReady ? 'active' : 'warning' ?>"><?= $workbenchReady ? '可直接创作' : '请先补全基础资料' ?></span>
            <a class="button" href="/workspace/assets?sku_id=<?= (int) $sku['id'] ?>"><i data-lucide="images"></i>查看素材</a>
            <a class="button primary" href="<?= $workbenchReady ? '/workspace/generate?mode=assets&sku_id=' . (int) $sku['id'] : '#product-identity' ?>"><i data-lucide="sparkles"></i><?= $workbenchReady ? '创建上架图' : '完善资料' ?></a>
        </div>
    </header>

    <nav class="one-flow-anchor-nav" aria-label="商品工作区导航">
        <a href="#product-identity">商品与参考图</a>
        <a href="#product-report">产品报告</a>
        <a href="#delivery-targets">投放目标</a>
        <a href="#creation-use">开始创作</a>
    </nav>

    <section class="one-flow-readiness" aria-label="创作准备状态">
        <?php foreach ((array) $workbench['readiness'] as $readiness): ?>
        <article class="readiness-card <?= !empty($readiness['ready']) ? 'is-ready' : 'needs-work' ?>">
            <span><i data-lucide="<?= !empty($readiness['ready']) ? 'circle-check-big' : 'circle-dashed' ?>"></i></span>
            <div><small><?= !empty($readiness['ready']) ? '已准备' : '待补充' ?></small><strong><?= escape_html($readiness['label']) ?></strong><p><?= escape_html($readiness['impact']) ?></p><?php if (!empty($readiness['missing'])): ?><em>缺少：<?= escape_html(implode('、', array_slice((array) $readiness['missing'], 0, 3))) ?></em><?php endif; ?></div>
        </article>
        <?php endforeach; ?>
    </section>

    <section class="one-flow-section" id="product-identity">
        <header><div><span class="section-kicker">01 · 基础资料</span><h2>商品与参考图</h2><p>只有商品名称、类目和一张参考图是必填项。</p></div><span class="badge active"><?= (!empty($sku['source_image_path']) ? 1 : 0) + count($additionalReferences) ?> 张参考图</span></header>
        <div class="one-flow-identity-grid">
            <div class="one-flow-reference-card">
                <?php if (!empty($sku['source_image_path'])): ?><img src="<?= escape_html($sku['source_image_path']) ?>" data-fallback="<?= escape_html((string) ($sku['source_image_backup_path'] ?? '')) ?>" data-sku-upload-preview alt="<?= escape_html($sku['name']) ?>"><span>主参考图</span><?php else: ?><div class="empty-state"><i data-lucide="image-plus"></i><strong>尚未上传商品图</strong></div><?php endif; ?>
                <?php if ($additionalReferences): ?><div class="one-flow-reference-thumbs"><?php foreach ($additionalReferences as $index => $reference): ?><img src="<?= escape_html((string) ($reference['url'] ?? '')) ?>" data-fallback="<?= escape_html((string) ($reference['backup_url'] ?? '')) ?>" alt="补充参考图 <?= $index + 2 ?>"><?php endforeach; ?></div><?php endif; ?>
            </div>
            <form class="panel one-flow-basic-form" method="post" action="/workspace/skus/<?= (int) $sku['id'] ?>" enctype="multipart/form-data" data-sku-analyzer data-analyze-url="/workspace/skus/analyze-image" data-sku-id="<?= (int) $sku['id'] ?>">
                <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
                <div class="form-grid">
                    <div class="form-field full"><label for="name">商品名称</label><input class="input" id="name" name="name" value="<?= escape_html($sku['name']) ?>" maxlength="160" required></div>
                    <div class="form-field"><label for="category">商品类目</label><input class="input" id="category" name="category" value="<?= escape_html($sku['category']) ?>" maxlength="100" required></div>
                    <div class="form-field"><label for="source_image">补充或替换主参考图</label><input class="input" id="source_image" name="source_image" type="file" accept="image/jpeg,image/png,image/webp"><div class="analyze-action"><button class="button small" type="button" data-analyze-product><i data-lucide="sparkles"></i>AI 重新识别<?= (int) $recognitionPrice > 0 ? ' · ' . number_format((int) $recognitionPrice) . ' 点' : '' ?></button><small data-analyze-status>识别结果会直接更新下方产品报告</small></div></div>
                    <div class="form-field full"><label for="selling_points">核心卖点 <span>每行一个</span></label><textarea class="input" id="selling_points" name="selling_points" rows="3"><?= escape_html(implode("\n", decode_json_list($sku['selling_points'] ?? '[]'))) ?></textarea></div>
                    <div class="form-field full"><label for="product_description">商品摘要</label><textarea class="input" id="product_description" name="product_description" rows="4" maxlength="4000"><?= escape_html((string) ($sku['product_description'] ?? '')) ?></textarea></div>
                    <input type="hidden" id="search_terms" name="search_terms" value="<?= escape_html(implode("\n", decode_json_list($sku['search_terms'] ?? '[]'))) ?>"><input type="hidden" id="creative_brief" name="creative_brief" value="">
                </div>
                <div class="form-footer"><span>保存后即可创建上架图，不需要逐条审批。</span><button class="button primary" type="submit"><i data-lucide="save"></i>保存商品资料</button></div>
            </form>
        </div>
    </section>

    <section class="one-flow-section" id="product-report">
        <header><div><span class="section-kicker">02 · 产品报告</span><h2>可编辑的商品认知</h2><p>用于复用商品信息、详情页和未来抖店上新；这些字段不是出图审批门槛。</p></div><span class="badge completed">按需校对</span></header>
        <form class="product-report-form" method="post" action="/workspace/skus/<?= (int) $sku['id'] ?>/foundation">
            <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
            <div class="form-grid">
                <div class="form-field"><label>主色 <span>每行一个</span></label><textarea class="input" name="primary_colors" rows="3"><?= escape_html(implode("\n", $primaryColors)) ?></textarea></div>
                <div class="form-field"><label>材质与工艺</label><textarea class="input" name="materials" rows="3"><?= escape_html((string) ($sku['report_materials'] ?? '')) ?></textarea></div>
                <div class="form-field full"><label>外观、结构与包装</label><textarea class="input" name="appearance" rows="4"><?= escape_html((string) ($sku['report_appearance'] ?? '')) ?></textarea></div>
                <div class="form-field"><label>可见品牌信息</label><textarea class="input" name="brand_notes" rows="3"><?= escape_html((string) ($sku['report_brand_notes'] ?? '')) ?></textarea></div>
                <div class="form-field"><label>目标人群</label><textarea class="input" name="target_audience" rows="3"><?= escape_html((string) ($sku['report_target_audience'] ?? '')) ?></textarea></div>
                <div class="form-field"><label>核心功能 <span>每行一个</span></label><textarea class="input" name="core_functions" rows="3"><?= escape_html(implode("\n", decode_json_list($sku['report_core_functions'] ?? '[]'))) ?></textarea></div>
                <div class="form-field"><label>使用场景 <span>每行一个</span></label><textarea class="input" name="use_scenarios" rows="3"><?= escape_html(implode("\n", decode_json_list($sku['report_use_scenarios'] ?? '[]'))) ?></textarea></div>
                <div class="form-field"><label>待核对信息 <span>每行一个</span></label><textarea class="input" name="uncertain_points" rows="3"><?= escape_html(implode("\n", decode_json_list($sku['report_uncertain_points'] ?? '[]'))) ?></textarea></div>
                <div class="form-field"><label>风险表述 <span>每行一个</span></label><textarea class="input" name="prohibited_claims" rows="3"><?= escape_html(implode("\n", decode_json_list($sku['report_prohibited_claims'] ?? '[]'))) ?></textarea></div>
            </div>
            <div class="form-footer"><span>只保留能被后续上新和详情页复用的商品信息。</span><button class="button" type="submit"><i data-lucide="save"></i>保存产品报告</button></div>
        </form>
    </section>

    <section class="one-flow-section" id="delivery-targets">
        <header><div><span class="section-kicker">03 · 投放配置</span><h2>平台、语言与上新方向</h2><p>为同一商品保存多个投放版本，无需重新识别。</p></div><span class="badge active"><?= count($targets) ?> 个目标</span></header>
        <form class="delivery-target-form" method="post" action="/workspace/skus/<?= (int) $sku['id'] ?>/delivery-targets" data-delivery-target-form>
            <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><div data-delivery-target-rows>
            <?php foreach ($targets ?: [['platform' => $preferredPlatform, 'language' => $preferredLanguage]] as $index => $target): ?><div class="delivery-target-row"><span><?= $index === 0 ? '主投放目标' : '追加版本' ?></span><select class="input" name="target_platform[]"><?php foreach ($platforms as $value => $label): ?><option value="<?= escape_html($value) ?>" <?= (string) ($target['platform'] ?? '') === (string) $value ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select><select class="input" name="target_language[]"><?php foreach ($languages as $value => $label): ?><option value="<?= escape_html($value) ?>" <?= (string) ($target['language'] ?? '') === (string) $value ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select><button class="icon-button" type="button" data-remove-delivery-target aria-label="移除投放目标"><i data-lucide="x"></i></button></div><?php endforeach; ?>
            </div><button class="button small" type="button" data-add-delivery-target><i data-lucide="plus"></i>增加平台或语言版本</button>
            <label class="form-field"><span>可选：本次上新方向</span><textarea class="input" name="launch_direction" rows="3" maxlength="1200" placeholder="例如：突出夏季通勤搭配；不要写折扣信息。"><?= escape_html((string) ($workbench['launch_direction'] ?? '')) ?></textarea><small>只影响本次创作，不会改写产品报告。</small></label>
            <div class="form-footer"><span>创建时会自动带入。</span><button class="button primary" type="submit"><i data-lucide="save"></i>保存投放配置</button></div>
        </form>
    </section>

    <section class="one-flow-section" id="creation-use">
        <header><div><span class="section-kicker">04 · 开始创作</span><h2>这次要做什么</h2><p>上架图只需基础商品资料；详情页才需要方案。</p></div><?php if (!$latestProject): ?><a class="button small" href="/workspace/detail-pages/new?sku_id=<?= (int) $sku['id'] ?>"><i data-lucide="plus"></i>新建详情页方案</a><?php endif; ?></header>
        <div class="creation-use-grid">
            <?php foreach ((array) $workbench['uses'] as $use): $manifest = $deliveryManifests[$use['code']] ?? []; ?>
            <article class="creation-use-card <?= !empty($use['recommended']) ? 'is-recommended' : '' ?> <?= empty($use['enabled']) ? 'is-disabled' : '' ?>">
                <?php if (!empty($use['recommended'])): ?><span class="recommended-ribbon">默认推荐</span><?php endif; ?><i data-lucide="<?= $use['code'] === 'full_package' ? 'layers-3' : ($use['code'] === 'listing_images' ? 'images' : 'panels-top-left') ?>"></i><h3><?= escape_html($use['label']) ?></h3><p><?= escape_html($use['description']) ?></p><dl><div><dt>上架图片</dt><dd><?= (int) $use['listing_count'] ?> 张</dd></div><div><dt>详情页</dt><dd><?= (int) $use['detail_count'] ?> 张</dd></div><div><dt>预计点数</dt><dd><?= number_format((int) ($manifest['credits'] ?? 0)) ?> 点</dd></div></dl>
                <?php if (!empty($use['enabled'])): ?><a class="button <?= !empty($use['recommended']) ? 'primary' : '' ?> block" href="<?= escape_html($useLinks[$use['code']]) ?>">选择并预览<i data-lucide="arrow-right"></i></a><?php else: ?><a class="button block" href="<?= !$workbenchReady ? '#product-identity' : '/workspace/detail-pages/new?sku_id=' . (int) $sku['id'] ?>"><i data-lucide="arrow-right"></i><?= escape_html((string) ($use['blocked_reason'] ?? '先完善资料')) ?></a><?php endif; ?>
            </article>
            <?php endforeach; ?>
        </div>
        <?php if ($detailProjects): ?><div class="existing-plan-list"><h3>已有详情页方案 · <?= count($detailProjects) ?> 个</h3><?php foreach ($detailProjects as $project): ?><a href="/workspace/detail-pages/<?= (int) $project['id'] ?>"><span><strong><?= escape_html(platform_label($project['platform'])) ?></strong><small><?= escape_html(generation_language_label($project['language'])) ?> · <?= escape_html((string) $project['updated_at']) ?></small></span><i data-lucide="chevron-right"></i></a><?php endforeach; ?></div><?php endif; ?>
    </section>
</div>
<template data-delivery-target-template><div class="delivery-target-row"><span>追加版本</span><select class="input" name="target_platform[]"><?php foreach ($platforms as $value => $label): ?><option value="<?= escape_html($value) ?>"><?= escape_html($label) ?></option><?php endforeach; ?></select><select class="input" name="target_language[]"><?php foreach ($languages as $value => $label): ?><option value="<?= escape_html($value) ?>"><?= escape_html($label) ?></option><?php endforeach; ?></select><button class="icon-button" type="button" data-remove-delivery-target aria-label="移除投放目标"><i data-lucide="x"></i></button></div></template>
<?php require __DIR__ . '/../partials/merchant_footer.php'; ?>
