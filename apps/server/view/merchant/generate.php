<?php require __DIR__ . '/../partials/merchant_header.php'; ?>
<?php
$defaultImageModelId = max(0, (int) ($defaultImageChoice['id'] ?? 0));
$defaultImageModelName = trim((string) ($defaultImageChoice['name'] ?? $defaultModel ?? ''));
$defaultImageModelValue = trim((string) ($defaultImageChoice['modelId'] ?? $defaultModel ?? ''));
$defaultImageModelPrice = max(0, (int) ($defaultImageChoice['price'] ?? $generationPrice));
$defaultVideoModelId = max(0, (int) ($defaultVideoChoice['id'] ?? 0));
$defaultVideoModelValue = trim((string) ($defaultVideoChoice['modelId'] ?? ''));
$defaultVideoModelName = trim((string) ($defaultVideoChoice['name'] ?? $defaultVideoModelValue));
$defaultVideoModelPrice = max(0, (int) ($defaultVideoChoice['price'] ?? $videoBasePrice ?? 0));
$detailQuantityLimit = max(1, count(detail_page_screen_options()));
if ($defaultImageModelId === 0 && $defaultImageModelValue !== '') {
    foreach ($modelOptions as $modelOption) {
        if ((string) ($modelOption['modelId'] ?? '') === $defaultImageModelValue) {
            $defaultImageModelId = (int) $modelOption['id'];
            break;
        }
    }
}
if ($defaultVideoModelId === 0 && $defaultVideoModelValue !== '') {
    foreach ($videoModelOptions as $modelOption) {
        if ((string) ($modelOption['modelId'] ?? '') === $defaultVideoModelValue) {
            $defaultVideoModelId = (int) $modelOption['id'];
            break;
        }
    }
}
$latestProjectJob = $projectJobs[0] ?? null;
$selectedCreativeReadiness = is_array($selectedSku['creative_readiness'] ?? null) ? $selectedSku['creative_readiness'] : [];
$selectedCreativeReady = !empty($selectedCreativeReadiness['ready']);
$selectedReadinessBlocked = $selectedSku !== null && !$selectedCreativeReady;
$selectedReadinessIssues = array_merge((array) ($selectedCreativeReadiness['missing'] ?? []), (array) ($selectedCreativeReadiness['blockers'] ?? []));
$selectedReadinessLabels = array_values(array_unique(array_filter(array_map(static fn (array $item): string => trim((string) ($item['label'] ?? '')), $selectedReadinessIssues))));
$selectedPrimaryColors = [];
?>
<div class="studio-generation-workspace">
<?php if ($selectedSku): ?>
<section class="studio-project-context">
    <div class="studio-project-context-main">
        <img src="<?= escape_html((string) ($selectedSku['source_image_path'] ?: $selectedSku['source_image_backup_path'])) ?>" data-fallback="<?= escape_html((string) ($selectedSku['source_image_backup_path'] ?? '')) ?>" alt="<?= escape_html((string) $selectedSku['name']) ?>">
        <span>
            <strong><?= escape_html((string) $selectedSku['name']) ?></strong>
            <small><?= escape_html((string) ($selectedSku['category'] ?: '待确认类目')) ?> · 商品资料<?= (string) ($selectedSku['report_status'] ?? '') === 'ready' ? '已就绪' : '正在整理' ?><?php if ($latestProjectJob): ?> · 最近任务<?= escape_html(job_status_label((string) $latestProjectJob['status'])) ?><?php endif; ?></small>
        </span>
    </div>
    <div class="studio-project-context-actions">
        <button class="button small" type="button" data-studio-drawer-open="product-profile"><i data-lucide="file-pen-line"></i><span>修改商品资料</span></button>
        <a class="button small" href="/workspace/assets?sku_id=<?= (int) $selectedSku['id'] ?>"><i data-lucide="images"></i><span><?= count($projectAssets) ?> 张素材</span></a>
    </div>
</section>
<?php endif; ?>
<?php if ($selectedSku && !$selectedCreativeReady): ?>
<section class="notice warning creative-readiness-notice" data-creative-readiness-notice>
    <i data-lucide="hourglass"></i>
    <div><strong>还需完成 <?= count($selectedReadinessLabels) ?> 项商品资料</strong><p><?= $selectedReadinessLabels ? escape_html(implode('、', $selectedReadinessLabels)) . '。' : '' ?>完成前预览与生成按钮保持禁用，不会创建任务、预扣点数或发起生成。</p></div>
    <a class="button small" href="<?= escape_html((string) ($selectedCreativeReadiness['recovery']['url'] ?? ('/workspace/skus/' . (int) $selectedSku['id'] . '?section=foundation'))) ?>">立即处理</a>
</section>
<?php endif; ?>
<nav class="experience-stepper" aria-label="商品上新流程">
    <a class="done" href="/workspace/skus/<?= $selectedSkuId > 0 ? (int) $selectedSkuId . '?section=overview' : '' ?>"><b>✓</b><strong>商品档案</strong></a>
    <span class="done"><b>✓</b><strong>AI 识别</strong></span>
    <?php if ($selectedSku && !$selectedCreativeReady): ?><a class="needs-work" href="<?= escape_html((string) ($selectedCreativeReadiness['recovery']['url'] ?? ('/workspace/skus/' . (int) $selectedSku['id'] . '?section=foundation'))) ?>"><b>!</b><strong>商品资料待补充</strong></a><?php else: ?><span class="done"><b>✓</b><strong>商品资料</strong></span><?php endif; ?>
    <span class="active"><b>4</b><strong>选择生成目标</strong></span>
    <a href="/workspace/jobs<?= $selectedSkuId > 0 ? '?sku_id=' . (int) $selectedSkuId : '' ?>"><b>5</b><strong>查看任务</strong></a>
    <a href="/workspace/assets<?= $selectedSkuId > 0 ? '?sku_id=' . (int) $selectedSkuId : '' ?>"><b>6</b><strong>素材归档</strong></a>
</nav>

<div class="page-actions">
    <div><h2>选择生成目标</h2><p>先选主图、整套上架、详情页或视频，再确认数量与预计点数。</p></div>
    <div class="button-row"><a class="button" href="/workspace/skus"><i data-lucide="package-search"></i><span>商品库</span></a><a class="button" href="/workspace/jobs"><i data-lucide="list-checks"></i><span>查看任务</span></a></div>
</div>

<nav class="creation-mode-tabs" aria-label="商品素材类型">
    <a class="<?= $generationMode === 'bundle' ? 'active' : '' ?>" href="/workspace/generate?mode=bundle<?= $selectedSkuId > 0 ? '&sku_id=' . (int) $selectedSkuId : '' ?>"><i data-lucide="package-check"></i><span><strong>整套上架素材</strong><small>主图与详情页一次创建</small></span></a>
    <a class="<?= $generationMode === 'assets' ? 'active' : '' ?>" href="/workspace/generate?mode=assets<?= $selectedSkuId > 0 ? '&sku_id=' . (int) $selectedSkuId : '' ?>"><i data-lucide="images"></i><span><strong>商品主图</strong><small>主图、卖点图、场景图等</small></span></a>
    <a class="<?= $generationMode === 'detail' ? 'active' : '' ?>" href="/workspace/generate?mode=detail<?= $selectedSkuId > 0 ? '&sku_id=' . (int) $selectedSkuId : '' ?>"><i data-lucide="panels-top-left"></i><span><strong>详情页生成</strong><small>按方案生成 1-10 张详情页图片</small></span></a>
    <a class="<?= $generationMode === 'video' ? 'active' : '' ?>" href="/workspace/generate?mode=video<?= $selectedSkuId > 0 ? '&sku_id=' . (int) $selectedSkuId : '' ?>"><i data-lucide="clapperboard"></i><span><strong>商品视频</strong><small>参考商品图生成 5–10 秒视频</small></span></a>
</nav>

<?php if ($generationMode === 'video' && $skus): ?>
<section class="panel video-generation-panel">
    <div class="panel-header"><div><h3>商品短视频</h3><p>从商品原图或已有商品素材生成稳定的展示视频，任务会在后台持续完成。</p><small>提交后可以离开页面，稍后回来查看进度和结果。</small></div><span class="badge active">异步生成</span></div>
    <div class="panel-body">
        <form method="post" action="/workspace/generate/video" data-video-generation-form data-submit-loading data-background-submit data-loading-message="正在检查商品资料、视频服务与可用点数..." data-video-unit-price="<?= $defaultVideoModelPrice ?>">
            <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
            <div class="generation-task-layout">
                <div class="generation-task-main">
                    <div class="form-grid">
                        <div class="form-field full"><label for="video-sku-id">选择商品</label><select class="input" id="video-sku-id" name="sku_id" required data-creative-ready-select><option value="">选择商品</option><?php foreach ($skus as $sku): ?><option value="<?= (int) $sku['id'] ?>" data-creative-ready="<?= !empty($sku['creative_readiness']['ready']) ? '1' : '0' ?>" data-creative-state="<?= escape_html((string) ($sku['creative_readiness']['state'] ?? 'processing')) ?>" <?= $selectedSkuId === (int) $sku['id'] ? 'selected' : '' ?>><?= escape_html($sku['sku_code'] . ' · ' . $sku['name']) ?></option><?php endforeach; ?></select><small>默认使用商品原始参考图，确保外观、包装和关键细节稳定。</small></div>
                        <?php if ($selectedSku): ?>
                        <div class="form-field full"><label for="video-source-asset">视频起始图</label><select class="input" id="video-source-asset" name="source_asset_id"><option value="0">商品原始参考图</option><?php foreach ($projectAssets as $asset): ?><option value="<?= (int) $asset['id'] ?>"><?= escape_html(asset_type_label((string) $asset['asset_type']) . ' · ' . (string) $asset['created_at']) ?></option><?php endforeach; ?></select><small>也可从当前商品已生成的图片中选择一张作为视频首帧。</small></div>
                        <?php endif; ?>
                        <div class="form-field"><label for="video-motion-preset">镜头方式</label><select class="input" id="video-motion-preset" name="motion_preset"><?php foreach ((new \app\service\PromptTemplateService())->videoMotionPresets() as $value => $label): ?><option value="<?= escape_html($value) ?>"><?= escape_html($label) ?></option><?php endforeach; ?></select></div>
                        <div class="form-field"><label for="video-platform">目标平台</label><select class="input" id="video-platform" name="platform"><?php foreach (generation_platform_options() as $value => $label): ?><option value="<?= escape_html($value) ?>" <?= $value === $preferredPlatform ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select></div>
                        <div class="form-field"><label for="video-ratio">视频比例</label><select class="input" id="video-ratio" name="ratio"><option value="9:16">9:16 · 竖屏短视频</option><option value="1:1">1:1 · 方形商品视频</option><option value="16:9">16:9 · 横屏展示</option><option value="4:3">4:3 · 横向商品展示</option><option value="3:4">3:4 · 竖向商品展示</option></select></div>
                        <div class="form-field"><label for="video-duration">视频时长</label><select class="input" id="video-duration" name="duration_seconds" data-video-duration><option value="5">5 秒</option><option value="10">10 秒</option></select></div>
                        <div class="form-field"><label for="video-resolution">清晰度</label><select class="input" id="video-resolution" name="resolution"><option value="720p">720p · 快速预览</option><option value="1080p">1080p · 高清成片</option></select></div>
                        <div class="form-field"><label for="video-language">画面语言</label><select class="input" id="video-language" name="language"><?php foreach (generation_language_options() as $value => $label): ?><option value="<?= escape_html($value) ?>" <?= $value === $preferredLanguage ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select><small>视频默认不主动生成营销文字，用于约束用户明确要求的文字。</small></div>
                        <div class="form-field generation-model-advanced"><label for="video-generation-model">视频模型</label><select class="input" id="video-generation-model" name="generation_model_id" data-video-model-selector><?php if ($defaultVideoModelId === 0): ?><option value="0" selected data-unit-price="<?= $defaultVideoModelPrice ?>"><?= escape_html($defaultVideoModelName) ?> · <?= $defaultVideoModelPrice ?> 点/秒</option><?php endif; ?><?php foreach ($videoModelOptions as $model): ?><option value="<?= (int) $model['id'] ?>" data-unit-price="<?= (int) ($model['prices'][\app\service\AiCapability::VIDEO_IMAGE_TO_VIDEO] ?? $defaultVideoModelPrice) ?>" <?= $defaultVideoModelId === (int) $model['id'] ? 'selected' : '' ?>><?= escape_html((string) $model['name']) ?> · <?= (int) ($model['prices'][\app\service\AiCapability::VIDEO_IMAGE_TO_VIDEO] ?? $defaultVideoModelPrice) ?> 点/秒<?= !empty($model['isGray']) ? ' · 灰度' : '' ?></option><?php endforeach; ?></select><small>只显示管理员已验证并开放的图生视频模型。</small></div>
                        <div class="form-field full"><label for="video-task-brief">本次视频要求 <span>可选</span></label><textarea class="input" id="video-task-brief" name="task_brief" rows="4" maxlength="1200" placeholder="例如：镜头缓慢推进，突出金属质感；商品包装和 Logo 必须保持不变。"></textarea><small>系统会自动注入商品资料和一致性约束，不需要重复描述商品外观。</small></div>
                        <label class="toggle-field full"><input type="checkbox" name="generate_audio" value="1"><span><strong>同步生成环境音</strong><small>仅在当前服务支持时生效，不生成旁白和未经确认的口播内容。</small></span></label>
                    </div>
                </div>
                <aside class="generation-task-aside">
                    <section class="generation-side-section"><div class="generation-side-heading"><i data-lucide="image-play"></i><strong>视频参考图</strong></div><?php if ($selectedSku): ?><div class="generation-reference"><div class="generation-reference-image"><img src="<?= escape_html((string) ($selectedSku['source_image_path'] ?: $selectedSku['source_image_backup_path'])) ?>" alt="<?= escape_html((string) $selectedSku['name']) ?>"></div><div class="generation-reference-copy"><strong><?= escape_html((string) $selectedSku['name']) ?></strong><span>生成时锁定主体、包装和关键部件</span></div></div><?php else: ?><div class="generation-reference-placeholder"><i data-lucide="image-up"></i><span>选择商品后使用其参考图</span></div><?php endif; ?></section>
                    <section class="generation-side-section"><div class="generation-side-heading"><i data-lucide="receipt-text"></i><strong>提交摘要</strong></div><div class="generation-summary-list"><div><span>任务类型</span><strong>商品图生视频</strong></div><div><span>预计消耗</span><strong><b data-video-estimated-credits><?= 5 * $defaultVideoModelPrice ?></b> 点</strong></div><div><span>保存位置</span><strong>商品档案与素材库</strong></div></div><p class="generation-selection-summary">按实际视频秒数计费；失败会自动退回预扣点数。</p></section>
                    <button class="button primary block" type="submit" <?= (!$videoEnabled || $selectedReadinessBlocked) ? 'disabled' : '' ?> <?= !$videoEnabled ? 'data-model-disabled' : '' ?> <?= $selectedReadinessBlocked ? 'data-readiness-disabled="1"' : '' ?>><i data-lucide="clapperboard"></i><span>创建商品视频任务</span></button>
                </aside>
            </div>
        </form>
        <?php if (!$videoEnabled): ?><div class="gateway-state"><strong>视频生成暂不可用</strong><p>请稍后再试，当前不会创建失败任务或预扣点数。</p></div><?php endif; ?>
    </div>
</section>
<?php elseif (!$skus): ?>
<section class="panel"><div class="empty-state"><strong>还没有可创作的商品</strong><span>先新建商品并上传参考图，或前往自由创作直接生图。</span><br><div class="button-row"><a class="button primary" href="/workspace/skus/new">新建商品</a><a class="button" href="/workspace/free-create">自由创作</a></div></div></section>
<?php elseif ($generationMode === 'bundle'): ?>
<section class="panel">
    <div class="panel-header"><div><h3>一次生成整套上架素材</h3><p>选择商品和详情页方案，再分别建立主图与详情页子任务。</p><small>方案会自动带入平台、画面文案语言和已审核文案；主图与详情页保持同一投放语言。</small></div><span class="badge active">推荐</span></div>
    <div class="panel-body">
        <div class="generation-preset-strip" data-generation-presets aria-label="上新方案">
            <button type="button" data-generation-preset data-preset-types="main:2,secondary:1,scene:1" data-detail-count="4" aria-pressed="false"><i data-lucide="zap"></i><span><strong>快速上新</strong><small>4 张商品图片 + 4 张详情页</small></span></button>
            <button class="active" type="button" data-generation-preset data-preset-types="main:2,secondary:2,scene:1,feature:1" data-detail-count="8" aria-pressed="true"><i data-lucide="sparkles"></i><span><strong>平台推荐方案</strong><small>6 张上架图片 + 8 张详情页</small></span></button>
            <button type="button" data-generation-preset data-preset-types="main:3,secondary:2,scene:2,lifestyle:1,detail:1,feature:1,package:1" data-detail-count="10" aria-pressed="false"><i data-lucide="layers-3"></i><span><strong>完整商品套图</strong><small>11 张上架图片 + 10 张详情页</small></span></button>
        </div>
        <div class="generation-delivery-callout"><i data-lucide="clipboard-check"></i><span><strong>整套上新素材 · 默认推荐</strong><small>6 张上架图片 + 完整十屏详情页；创建前会先检查商品事实、图片职责和交付规格。</small></span></div>
        <form method="post" action="/workspace/generate/bundle" data-generation-form data-listing-bundle-form data-submit-loading data-background-submit data-loading-message="正在检查整套商品资料..." data-image-edit-enabled="<?= $imageEditEnabled ? '1' : '0' ?>" data-generation-price="<?= (int) $generationPrice ?>" data-max-images="<?= (int) $batchImageLimit ?>">
            <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
            <input type="hidden" name="client_submission_id" value="<?= escape_html(bin2hex(random_bytes(16))) ?>">
            <input type="hidden" name="launch_action" value="full" data-launch-action>
            <div class="generation-task-layout">
                <div class="generation-task-main"><div class="form-grid">
                    <?php if ($selectedSku): ?><div class="selected-context-chip full"><i data-lucide="package-check"></i><span><small>当前商品</small><strong><?= escape_html((string) $selectedSku['name']) ?></strong></span><a href="/workspace/skus/<?= (int) $selectedSku['id'] ?>">返回商品工作区</a></div><?php endif; ?><div class="form-field full" <?= $selectedSku ? 'hidden' : '' ?>><label for="sku_id">选择商品</label><select class="input" id="sku_id" name="sku_id" required data-creative-ready-select><option value="">选择商品</option><?php foreach ($skus as $sku): ?><option value="<?= (int) $sku['id'] ?>" data-creative-ready="<?= !empty($sku['creative_readiness']['ready']) ? '1' : '0' ?>" data-creative-state="<?= escape_html((string) ($sku['creative_readiness']['state'] ?? 'processing')) ?>" data-image="<?= escape_html($sku['source_image_path'] ?: '') ?>" data-backup="<?= escape_html($sku['source_image_backup_path'] ?: '') ?>" data-preferred-model="<?= escape_html((string) ($sku['preferred_image_model'] ?? '')) ?>" data-report-id="<?= (int) $sku['detail_report_id'] ?>" data-report-updated="<?= escape_html($sku['detail_report_updated_at']) ?>" <?= $selectedSkuId === (int) $sku['id'] ? 'selected' : '' ?>><?= escape_html($sku['sku_code'] . ' · ' . $sku['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-field full"><label for="bundle-detail-project">详情页方案</label><select class="input" id="bundle-detail-project" name="detail_project_id" required><option value="">请先选择商品</option><?php foreach ($detailProjects as $project): ?><option value="<?= (int) $project['id'] ?>" data-sku-id="<?= (int) $project['sku_id'] ?>" data-platform="<?= escape_html($project['platform']) ?>" data-language="<?= escape_html($project['language']) ?>" <?= $selectedDetailProjectId === (int) $project['id'] ? 'selected' : '' ?>><?= escape_html(platform_label($project['platform']) . ' · ' . generation_language_label($project['language']) . ' · ' . $project['product_name']) ?></option><?php endforeach; ?></select><small data-bundle-project-hint>选择商品后显示全部可用方案，并自动选择最近更新的一项</small></div>
                    <div class="form-field full generation-model-advanced"><label for="bundle-generation-model">图片模型</label><select class="input" id="bundle-generation-model" name="generation_model_id" data-creative-model-selector data-default-model-id="<?= $defaultImageModelId ?>"><?php if ($defaultImageModelId === 0): ?><option value="0" selected data-model-id="<?= escape_html($defaultImageModelValue) ?>" data-unit-price="<?= $defaultImageModelPrice ?>"><?= escape_html($defaultImageModelName ?: $defaultImageModelValue) ?> · <?= $defaultImageModelPrice ?> 点/张</option><?php endif; ?><?php foreach ($modelOptions as $model): ?><option value="<?= (int) $model['id'] ?>" data-model-id="<?= escape_html((string) $model['modelId']) ?>" data-unit-price="<?= (int) ($model['prices'][\app\service\AiCapability::IMAGE_EDIT] ?? $generationPrice) ?>" <?= $defaultImageModelId === (int) $model['id'] ? 'selected' : '' ?>><?= escape_html((string) $model['name']) ?> · <?= (int) ($model['prices'][\app\service\AiCapability::IMAGE_EDIT] ?? $generationPrice) ?> 点/张<?= $model['description'] !== '' ? ' · ' . escape_html((string) $model['description']) : '' ?><?= !empty($model['isGray']) ? ' · 灰度' : '' ?></option><?php endforeach; ?></select></div>
                    <details class="generation-advanced"><summary><span><strong>高级设置</strong><small>自定义图片类型、逐项数量和补充素材</small></span><i data-lucide="chevron-down"></i></summary><div class="generation-advanced-body"><div class="form-field full listing-bundle-section"><div class="asset-type-heading"><span class="field-label">01 · 商品主图</span><label class="asset-select-all"><input type="checkbox" data-asset-select-all><span>全选</span></label></div><div class="choice-grid asset-type-choice-grid">
                        <?php foreach (asset_type_options() as $value => $option): $selected = in_array($value, ['main', 'secondary', 'scene', 'feature'], true); $defaultQuantity = in_array($value, ['main', 'secondary'], true) ? 2 : 1; ?>
                        <div class="asset-type-option<?= $selected ? ' selected' : '' ?>" data-asset-option><label class="choice" for="bundle-asset-type-<?= escape_html($value) ?>"><input id="bundle-asset-type-<?= escape_html($value) ?>" type="checkbox" name="asset_types[]" value="<?= escape_html($value) ?>" data-asset-checkbox <?= $selected ? 'checked' : '' ?>><span><strong><?= escape_html($option['label']) ?></strong><small><?= escape_html($option['hint']) ?></small></span></label><div class="asset-quantity-control" data-asset-quantity-control <?= $selected ? '' : 'hidden' ?>><label for="bundle-asset-quantity-<?= escape_html($value) ?>">生成张数</label><?= generation_quantity_picker('asset_quantities[' . $value . ']', $defaultQuantity, generation_asset_quantity_limit(), 'bundle-asset-quantity-' . $value, ['data-asset-quantity' => true], '生成' . $option['label'] . '数量', !$selected) ?></div></div>
                        <?php endforeach; ?>
                    </div></div>
                    <div class="form-field full custom-material-field"><label for="bundle-custom-material-requests">补充上新素材</label><textarea class="input" id="bundle-custom-material-requests" name="custom_material_requests" rows="3" maxlength="1200" data-custom-material-requests placeholder="每行填写一张想额外加入的图片，例如：展示包装盒打开后的全部配件&#10;展示产品放在书桌上的真实使用场景"></textarea><small>每行增加 1 张独立素材，系统会复用同一份商品资料并避免表达重复。</small></div></div></details>
                    <div class="form-field full"><label for="bundle-task-brief">本次创作方向 <span>可选</span></label><textarea class="input" id="bundle-task-brief" name="task_brief" rows="3" maxlength="1200" placeholder="只影响本次套图，不会写入商品长期事实。"><?= escape_html((string) ($launchTaskBrief ?? '')) ?></textarea></div>
                    <div class="form-field full listing-bundle-section"><span class="field-label">02 · 详情页图片</span><div class="listing-bundle-detail-control"><div><strong>完整十屏详情页</strong><small>系统按商品事实选择十种互补的信息职责，并保持叙事顺序。</small></div><div class="form-field quantity-form-field"><label for="bundle-detail-count">生成数量</label><?= generation_quantity_picker('detail_screen_count', $detailQuantityLimit, $detailQuantityLimit, 'bundle-detail-count', ['data-bundle-detail-count' => true], '详情页生成数量') ?></div></div><div class="detail-output-controls" data-detail-output-controls><div class="form-field"><label for="bundle-detail-output-preset">详情页规格</label><select class="input" id="bundle-detail-output-preset" name="detail_output_preset" data-detail-output-preset><?php foreach ($detailOutputPresets as $value => $preset): ?><option value="<?= escape_html($value) ?>" data-width="<?= (int) ($preset['width'] ?? 0) ?>" data-height="<?= (int) ($preset['height'] ?? 0) ?>"><?= escape_html($preset['label']) ?><?= !empty($preset['width']) ? ' · ' . (int) $preset['width'] . '×' . (int) $preset['height'] : '' ?></option><?php endforeach; ?></select><small data-detail-output-hint>通用详情页 · 1024×1536</small></div><div class="detail-output-custom" data-detail-output-custom hidden><div class="form-field"><label for="bundle-detail-output-width">宽度</label><input class="input" id="bundle-detail-output-width" name="detail_output_width" type="number" min="512" max="2048" step="1" value="1200" inputmode="numeric"></div><span aria-hidden="true">×</span><div class="form-field"><label for="bundle-detail-output-height">高度</label><input class="input" id="bundle-detail-output-height" name="detail_output_height" type="number" min="512" max="2048" step="1" value="1200" inputmode="numeric"></div></div></div></div>
                </div></div>
                <aside class="generation-task-aside">
                    <section class="generation-side-section"><div class="generation-side-heading"><i data-lucide="image"></i><strong>商品参考图</strong></div><div class="generation-reference-placeholder" data-reference-placeholder><i data-lucide="image-off"></i><span>选择商品后显示参考图</span></div><div class="generation-reference" data-generation-reference hidden><div class="generation-reference-image"><img data-reference-image alt="商品参考图"></div><div class="generation-reference-copy"><strong data-reference-title></strong><span>主图和详情页共享商品事实</span></div></div></section>
                    <section class="generation-side-section"><div class="generation-side-heading"><i data-lucide="clipboard-list"></i><strong>整套摘要</strong></div><div class="generation-summary-list"><div><span>任务结构</span><strong>主图 + 详情页</strong></div><div><span>预计消耗</span><strong><b data-estimated-credits>0</b> 点</strong></div><div><span>保存位置</span><strong>商品档案与素材库</strong></div></div><p class="generation-selection-summary" data-selection-summary></p></section>
                    <div class="generation-submit-actions">
                        <button class="button primary block" type="submit" data-launch-action-value="preview" <?= (!$imageEditEnabled || $selectedReadinessBlocked) ? 'disabled' : '' ?> <?= !$imageEditEnabled ? 'data-model-disabled' : '' ?> <?= $selectedReadinessBlocked ? 'data-readiness-disabled="1"' : '' ?>><i data-lucide="image"></i><span><strong>先预览 1 张</strong><small>推荐先检查</small></span></button>
                        <button class="button block" type="submit" data-launch-action-value="full" <?= (!$imageEditEnabled || $selectedReadinessBlocked) ? 'disabled' : '' ?> <?= !$imageEditEnabled ? 'data-model-disabled' : '' ?> <?= $selectedReadinessBlocked ? 'data-readiness-disabled="1"' : '' ?>><i data-lucide="package-check"></i><span><strong>生成整套</strong><small>按当前清单结算</small></span></button>
                    </div>
                </aside>
            </div>
        </form>
        <form class="detail-plan-create" method="post" action="" data-listing-plan-create-form data-action-template="/workspace/skus/__SKU__/detail-report" data-submit-loading data-loading-message="正在建立详情页方案..." hidden>
            <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><input type="hidden" name="return_to_generation" value="1"><input type="hidden" name="return_generation_mode" value="bundle">
            <div><strong>还差一个详情页方案</strong><p>一键使用现有商品资料生成内容脚本，完成后自动回到整套生成，不需要重新选择商品。</p></div>
            <div class="detail-plan-create-fields"><div class="form-field"><label for="bundle-plan-platform">目标平台</label><select class="input" id="bundle-plan-platform" name="platform" required><?php foreach (generation_platform_options() as $value => $label): ?><option value="<?= escape_html($value) ?>" <?= $value === $preferredPlatform ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select></div><div class="form-field"><label for="bundle-plan-language">画面文案语言</label><select class="input" id="bundle-plan-language" name="language" required><?php foreach (generation_language_options() as $value => $label): ?><option value="<?= escape_html($value) ?>" <?= $value === $preferredLanguage ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select></div><button class="button primary" type="submit"><i data-lucide="file-plus-2"></i><span>建立资料与详情页方案</span></button></div>
        </form>
        <?php if (!$imageEditEnabled): ?><div class="gateway-state"><strong>整套生成暂不可用</strong><p>当前没有满足商品图保真要求的生成服务，因此不会创建任务或预扣点数。</p></div><?php endif; ?>
    </div>
</section>
<?php elseif ($generationMode === 'assets'): ?>
<section class="panel">
    <div class="panel-header"><div><h3>生成商品图片</h3><p><?php if ((int) $generationPrice === 0 && !empty($generationQuote['subscription'])): ?>当前套餐生图免点数<?= (int) $generationQuote['dailyLimit'] > 0 ? '，今日已使用 ' . (int) $generationQuote['usedToday'] . '/' . (int) $generationQuote['dailyLimit'] . ' 张' : '' ?><?php else: ?>每张图片消耗 <?= number_format((int) $generationPrice) ?> 点<?php endif; ?></p><small>商品资料会自动注入本次生成；仅在缺少商品图或识别信息严重不足时才需要补充。</small></div></div>
    <div class="panel-body">
        <div class="generation-delivery-callout"><i data-lucide="images"></i><span><strong>只做上架图片</strong><small>默认 6 张，分别承担商品身份、差异化、卖点、结构、场景和细节证明职责。</small></span></div>
        <form method="post" action="/workspace/generate" data-generation-form data-submit-loading data-background-submit data-loading-message="正在检查商品内容安全性..." data-loading-stages="正在检查商品资料和参考图安全性...|安全检查通过后自动创建生成任务..." data-image-edit-enabled="<?= $imageEditEnabled ? '1' : '0' ?>" data-generation-price="<?= (int) $generationPrice ?>" data-max-images="<?= (int) $batchImageLimit ?>">
            <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
            <input type="hidden" name="client_submission_id" value="<?= escape_html(bin2hex(random_bytes(16))) ?>">
            <input type="hidden" name="launch_action" value="full" data-launch-action>
            <div class="generation-task-layout">
                <div class="generation-task-main"><div class="form-grid">
                    <?php if ($selectedSku): ?><div class="selected-context-chip full"><i data-lucide="package-check"></i><span><small>当前商品</small><strong><?= escape_html((string) $selectedSku['name']) ?></strong></span><a href="/workspace/skus/<?= (int) $selectedSku['id'] ?>">返回商品工作区</a></div><?php endif; ?><div class="form-field full" <?= $selectedSku ? 'hidden' : '' ?>><label for="sku_id">选择商品</label><select class="input" id="sku_id" name="sku_id" required data-creative-ready-select><option value="">选择商品</option><?php foreach ($skus as $sku): ?><option value="<?= (int) $sku['id'] ?>" data-creative-ready="<?= !empty($sku['creative_readiness']['ready']) ? '1' : '0' ?>" data-catalog-ready="<?= !empty($sku['catalog_readiness']['ready']) ? '1' : '0' ?>" data-creative-state="<?= escape_html((string) ($sku['creative_readiness']['state'] ?? 'processing')) ?>" data-image="<?= escape_html($sku['source_image_path'] ?: '') ?>" data-backup="<?= escape_html($sku['source_image_backup_path'] ?: '') ?>" data-preferred-model="<?= escape_html((string) ($sku['preferred_image_model'] ?? '')) ?>" data-report-id="<?= (int) $sku['detail_report_id'] ?>" data-report-updated="<?= escape_html($sku['detail_report_updated_at']) ?>" <?= $selectedSkuId === (int) $sku['id'] ? 'selected' : '' ?>><?= escape_html($sku['sku_code'] . ' · ' . $sku['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-field"><label for="generation-platform">目标平台</label><select class="input" id="generation-platform" name="platform" required><?php foreach (generation_platform_options() as $value => $label): ?><option value="<?= escape_html($value) ?>" <?= $value === $preferredPlatform ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select><small>已从商品工作区的主投放目标带入</small></div>
                    <div class="form-field"><label for="generation-language">画面文案语言</label><select class="input" id="generation-language" name="language" required><?php foreach (generation_language_options() as $value => $label): ?><option value="<?= escape_html($value) ?>" <?= $value === $preferredLanguage ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select><small>已从商品工作区的主投放目标带入</small></div>
                    <div class="form-field full generation-model-advanced"><label for="asset-generation-model">图片模型</label><select class="input" id="asset-generation-model" name="generation_model_id" data-creative-model-selector data-default-model-id="<?= $defaultImageModelId ?>"><?php if ($defaultImageModelId === 0): ?><option value="0" selected data-model-id="<?= escape_html($defaultImageModelValue) ?>" data-unit-price="<?= $defaultImageModelPrice ?>"><?= escape_html($defaultImageModelName ?: $defaultImageModelValue) ?> · <?= $defaultImageModelPrice ?> 点/张</option><?php endif; ?><?php foreach ($modelOptions as $model): ?><option value="<?= (int) $model['id'] ?>" data-model-id="<?= escape_html((string) $model['modelId']) ?>" data-unit-price="<?= (int) ($model['prices'][\app\service\AiCapability::IMAGE_EDIT] ?? $generationPrice) ?>" <?= $defaultImageModelId === (int) $model['id'] ? 'selected' : '' ?>><?= escape_html((string) $model['name']) ?> · <?= (int) ($model['prices'][\app\service\AiCapability::IMAGE_EDIT] ?? $generationPrice) ?> 点/张<?= $model['description'] !== '' ? ' · ' . escape_html((string) $model['description']) : '' ?><?= !empty($model['isGray']) ? ' · 灰度' : '' ?></option><?php endforeach; ?></select><small>商品参考图任务只使用支持图片编辑的模型</small></div>
                    <details class="generation-advanced"><summary><span><strong>高级设置</strong><small>自定义图片类型、逐项数量与本次创作方向</small></span><i data-lucide="chevron-down"></i></summary><div class="generation-advanced-body"><div class="form-field full"><div class="asset-type-heading"><span class="field-label">素材类型</span><label class="asset-select-all"><input type="checkbox" data-asset-select-all><span>全选</span></label></div><div class="choice-grid asset-type-choice-grid">
                        <?php foreach (asset_type_options() as $value => $option): $selected = in_array($value, ['main', 'secondary', 'scene', 'feature'], true); $defaultQuantity = in_array($value, ['main', 'secondary'], true) ? 2 : 1; ?>
                        <div class="asset-type-option<?= $selected ? ' selected' : '' ?>" data-asset-option><label class="choice" for="asset-type-<?= escape_html($value) ?>"><input id="asset-type-<?= escape_html($value) ?>" type="checkbox" name="asset_types[]" value="<?= escape_html($value) ?>" data-asset-checkbox <?= $selected ? 'checked' : '' ?>><span><strong><?= escape_html($option['label']) ?></strong><small><?= escape_html($option['hint']) ?></small></span></label><div class="asset-quantity-control" data-asset-quantity-control <?= $selected ? '' : 'hidden' ?>><label for="asset-quantity-<?= escape_html($value) ?>">生成张数</label><?= generation_quantity_picker('asset_quantities[' . $value . ']', $defaultQuantity, generation_asset_quantity_limit(), 'asset-quantity-' . $value, ['data-asset-quantity' => true], '生成' . $option['label'] . '数量', !$selected) ?></div></div>
                        <?php endforeach; ?>
                    </div></div>
                    <div class="form-field full"><label for="task-brief">本次创作方向 <span>可选</span></label><textarea class="input" id="task-brief" name="task_brief" rows="3" maxlength="1200" placeholder="例如：面向通勤新人，画面更轻盈，优先突出包装和送礼感。此内容只作用于本次任务，不会修改商品资料。"><?= escape_html((string) ($launchTaskBrief ?? '')) ?></textarea></div><div class="form-field full custom-material-field"><label for="custom-material-requests">补充上新素材</label><textarea class="input" id="custom-material-requests" name="custom_material_requests" rows="3" maxlength="1200" data-custom-material-requests placeholder="每行填写一张想额外加入的图片，例如：展示包装盒打开后的全部配件&#10;展示某个关键结构的近景"></textarea><small>每行增加 1 张独立素材，最多 4 张。会使用同一商品资料，并自动避免重复已选素材的表达重点。</small></div></div></details>
                </div></div>
                <aside class="generation-task-aside">
                    <section class="generation-side-section"><div class="generation-side-heading"><i data-lucide="image"></i><strong>商品参考图</strong></div><div class="generation-reference-placeholder" data-reference-placeholder><i data-lucide="image-off"></i><span>选择商品后显示参考图</span></div><div class="generation-reference" data-generation-reference hidden><div class="generation-reference-image"><img data-reference-image alt="商品参考图"></div><div class="generation-reference-copy"><strong data-reference-title></strong><span>图片将随任务提交</span></div></div></section>
                    <section class="generation-side-section"><div class="generation-side-heading"><i data-lucide="clipboard-list"></i><strong>提交摘要</strong></div><div class="generation-summary-list"><div><span>生成服务</span><strong class="generation-service-status <?= $newApiConfigured ? 'ready' : 'waiting' ?>"><?= $newApiConfigured ? '已就绪' : '待配置' ?></strong></div><div><span>预计消耗</span><strong><b data-estimated-credits>0</b> 点</strong></div><div><span>保存位置</span><strong>商品档案与素材库</strong></div></div><p class="generation-selection-summary" data-selection-summary></p></section>
                    <div class="generation-submit-actions">
                        <button class="button primary block" type="submit" data-launch-action-value="preview" <?= $selectedReadinessBlocked ? 'disabled data-readiness-disabled="1"' : '' ?>><i data-lucide="image"></i><span><strong>先预览 1 张</strong><small>推荐先检查</small></span></button>
                        <button class="button block" type="submit" data-launch-action-value="full" <?= $selectedReadinessBlocked ? 'disabled data-readiness-disabled="1"' : '' ?>><i data-lucide="sparkles"></i><span><strong>生成商品套图</strong><small>按当前清单结算</small></span></button>
                    </div>
                </aside>
            </div>
        </form>
    </div>
</section>
<?php else: ?>
<section class="panel">
    <div class="panel-header"><div><h3>详情页生成</h3><p>先选择商品，系统会自动带入最近的详情页方案；再决定本次生成几张。</p><small>平台和语言是详情页方案的版本信息，不是进入详情页生成的前置条件。</small></div></div>
    <div class="panel-body detail-generation-workspace" data-detail-generation-workspace data-unit-price="<?= (int) $generationPrice ?>">
        <div class="detail-generation-layout">
            <form method="post" action="" data-detail-generation-form data-submit-loading data-background-submit data-loading-message="正在检查商品资料与可用点数...">
                <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
                <div class="form-grid">
                    <?php if ($selectedSku): ?><div class="selected-context-chip full"><i data-lucide="package-check"></i><span><small>当前商品</small><strong><?= escape_html((string) $selectedSku['name']) ?></strong></span><a href="/workspace/skus/<?= (int) $selectedSku['id'] ?>">返回商品工作区</a></div><?php endif; ?><div class="form-field full" <?= $selectedSku ? 'hidden' : '' ?>><label for="detail-sku-id">选择商品</label><select class="input" id="detail-sku-id" required data-creative-ready-select><option value="">选择商品</option><?php foreach ($skus as $sku): ?><option value="<?= (int) $sku['id'] ?>" data-creative-ready="<?= !empty($sku['creative_readiness']['ready']) ? '1' : '0' ?>" data-creative-state="<?= escape_html((string) ($sku['creative_readiness']['state'] ?? 'processing')) ?>" data-image="<?= escape_html($sku['source_image_path'] ?: '') ?>" data-report-ready="<?= (string) ($sku['report_status'] ?? '') === 'ready' ? '1' : '0' ?>" <?= $selectedSkuId === (int) $sku['id'] ? 'selected' : '' ?>><?= escape_html($sku['sku_code'] . ' · ' . $sku['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-field full"><label for="detail-project-id">详情页方案</label><select class="input" id="detail-project-id" required><option value="">选择详情页方案</option><?php foreach ($detailProjects as $project): ?><option value="<?= (int) $project['id'] ?>" data-sku-id="<?= (int) $project['sku_id'] ?>" data-platform="<?= escape_html(platform_label($project['platform'])) ?>" data-language="<?= escape_html(generation_language_label($project['language'])) ?>" <?= $selectedDetailProjectId === (int) $project['id'] ? 'selected' : '' ?>><?= escape_html(platform_label($project['platform']) . ' · ' . generation_language_label($project['language']) . ' · ' . $project['product_name']) ?></option><?php endforeach; ?></select><small data-detail-project-hint>选择商品后自动带入最近方案</small></div>
                    <div class="form-field quantity-form-field"><label for="detail-screen-count">生成数量</label><?= generation_quantity_picker('screen_count', $detailQuantityLimit, $detailQuantityLimit, 'detail-screen-count', ['data-detail-screen-count' => true], '详情页生成数量') ?><small>1–4 张可快捷选择，也可自定义；系统自动选择最适合商品的模块</small></div>
                    <div class="form-field generation-model-advanced"><label for="detail-generation-model">图片模型</label><select class="input" id="detail-generation-model" name="generation_model_id" data-creative-model-selector data-default-model-id="<?= $defaultImageModelId ?>"><?php if ($defaultImageModelId === 0): ?><option value="0" selected data-unit-price="<?= $defaultImageModelPrice ?>"><?= escape_html($defaultImageModelName ?: $defaultImageModelValue) ?> · <?= $defaultImageModelPrice ?> 点/张</option><?php endif; ?><?php foreach ($modelOptions as $model): ?><option value="<?= (int) $model['id'] ?>" data-unit-price="<?= (int) ($model['prices'][\app\service\AiCapability::IMAGE_EDIT] ?? $generationPrice) ?>" <?= $defaultImageModelId === (int) $model['id'] ? 'selected' : '' ?>><?= escape_html((string) $model['name']) ?> · <?= (int) ($model['prices'][\app\service\AiCapability::IMAGE_EDIT] ?? $generationPrice) ?> 点/张<?= !empty($model['isGray']) ? ' · 灰度' : '' ?></option><?php endforeach; ?></select></div>
                    <div class="form-field" data-detail-output-controls><label for="detail-output-preset">详情页规格</label><select class="input" id="detail-output-preset" name="detail_output_preset" data-detail-output-preset><?php foreach ($detailOutputPresets as $value => $preset): ?><option value="<?= escape_html($value) ?>" data-width="<?= (int) ($preset['width'] ?? 0) ?>" data-height="<?= (int) ($preset['height'] ?? 0) ?>"><?= escape_html($preset['label']) ?><?= !empty($preset['width']) ? ' · ' . (int) $preset['width'] . '×' . (int) $preset['height'] : '' ?></option><?php endforeach; ?></select><small data-detail-output-hint>通用详情页 · 1024×1536</small><div class="detail-output-custom" data-detail-output-custom hidden><div class="form-field"><label for="detail-output-width">宽度</label><input class="input" id="detail-output-width" name="detail_output_width" type="number" min="512" max="2048" step="1" value="1200" inputmode="numeric"></div><span aria-hidden="true">×</span><div class="form-field"><label for="detail-output-height">高度</label><input class="input" id="detail-output-height" name="detail_output_height" type="number" min="512" max="2048" step="1" value="1200" inputmode="numeric"></div></div></div>
                    <div class="detail-generation-cost"><span>预计消耗</span><strong><b data-detail-estimated-credits><?= 10 * (int) $generationPrice ?></b> 点</strong><small>生成后自动归档到当前商品与素材库</small></div>
                </div>
                <div class="form-footer"><a class="button" href="#" data-detail-project-link hidden><i data-lucide="file-pen-line"></i><span>查看方案</span></a><button class="button primary" type="submit" <?= (!$imageEditEnabled || $selectedReadinessBlocked) ? 'disabled' : '' ?> <?= !$imageEditEnabled ? 'data-model-disabled' : '' ?> <?= $selectedReadinessBlocked ? 'data-readiness-disabled="1"' : '' ?>><i data-lucide="panels-top-left"></i><span>创建详情页图片任务</span></button></div>
            </form>
            <aside class="detail-generation-state"><i data-lucide="panels-top-left"></i><div><strong data-detail-state-title>选择商品开始</strong><p data-detail-state-copy>已有方案可直接生成；没有方案时可在这里建立。</p></div></aside>
        </div>
        <form class="detail-plan-create" method="post" action="" data-detail-plan-create-form data-action-template="/workspace/skus/__SKU__/detail-report" data-submit-loading data-loading-message="正在建立详情页方案..." hidden>
            <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><input type="hidden" name="return_to_generation" value="1">
            <div><strong>还差商品资料与详情页方案</strong><p>系统会复用参考图整理稳定商品事实，并生成可继续修改的详情页内容脚本。</p></div>
            <div class="detail-plan-create-fields"><div class="form-field"><label for="detail-plan-platform">目标平台</label><select class="input" id="detail-plan-platform" name="platform" required><?php foreach (generation_platform_options() as $value => $label): ?><option value="<?= escape_html($value) ?>" <?= $value === $preferredPlatform ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select></div><div class="form-field"><label for="detail-plan-language">画面文案语言</label><select class="input" id="detail-plan-language" name="language" required><?php foreach (generation_language_options() as $value => $label): ?><option value="<?= escape_html($value) ?>" <?= $value === $preferredLanguage ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select></div><button class="button primary" type="submit"><i data-lucide="plus"></i><span>建立资料与详情页方案</span></button></div>
        </form>
        <?php if (!$imageEditEnabled): ?><div class="gateway-state"><strong>详情页生成暂不可用</strong><p>当前没有满足商品图保真要求的生成服务，因此不会创建任务或预扣点数。</p></div><?php endif; ?>
    </div>
</section>
<?php endif; ?>
<?php if ($selectedSku && $projectAssets): ?>
<section class="studio-result-section">
    <div class="studio-section-heading"><div><h3>当前商品的创作结果</h3><p>新结果会直接出现在这里，并自动归档到商品档案与素材库。</p></div><a href="/workspace/assets?sku_id=<?= (int) $selectedSku['id'] ?>">查看全部 <?= count($projectAssets) > 17 ? '18+' : count($projectAssets) ?> 张</a></div>
    <div class="studio-result-grid">
        <?php foreach ($projectAssets as $asset): ?>
        <article class="studio-result-card">
            <a href="<?= escape_html((string) $asset['file_url']) ?>" target="_blank" rel="noopener"><img src="<?= escape_html((string) $asset['file_url']) ?>" data-fallback="<?= escape_html((string) ($asset['backup_file_url'] ?? '')) ?>" alt="<?= escape_html(asset_type_label((string) $asset['asset_type'])) ?>" loading="lazy"></a>
            <footer><span><?= escape_html(asset_type_label((string) $asset['asset_type'])) ?></span><div><a class="icon-button" href="/workspace/canvas?asset_id=<?= (int) $asset['id'] ?>" aria-label="加入画布" data-tooltip="加入画布"><i data-lucide="panels-top-left"></i></a><a class="icon-button" href="/workspace/assets/<?= (int) $asset['id'] ?>/download" aria-label="下载图片" data-tooltip="下载"><i data-lucide="download"></i></a></div></footer>
        </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
</div>
<?php if ($selectedSku): ?>
<button class="studio-drawer-backdrop" type="button" data-studio-drawer-backdrop hidden aria-label="关闭商品资料"></button>
<aside class="studio-side-drawer studio-product-profile-drawer" data-studio-drawer="product-profile" aria-hidden="true">
    <header><div><strong>商品资料</strong><small>产品报告会自动带入这件商品的每次创作。</small></div><button class="icon-button" type="button" data-studio-drawer-close aria-label="关闭商品资料"><i data-lucide="x"></i></button></header>
    <div class="studio-profile-summary">
        <img src="<?= escape_html((string) ($selectedSku['source_image_path'] ?: $selectedSku['source_image_backup_path'])) ?>" data-fallback="<?= escape_html((string) ($selectedSku['source_image_backup_path'] ?? '')) ?>" alt="">
        <span><strong><?= escape_html((string) $selectedSku['name']) ?></strong><small><?= escape_html((string) $selectedSku['category']) ?> · <?= (string) ($selectedSku['foundation_source'] ?? '') === 'manual' ? '已人工修正' : 'AI 识别' ?></small></span>
    </div>
    <form method="post" action="/workspace/skus/<?= (int) $selectedSku['id'] ?>/foundation">
        <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
        <input type="hidden" name="return_to" value="/workspace/generate?sku_id=<?= (int) $selectedSku['id'] ?>&mode=<?= escape_html($generationMode) ?>">
        <label><span>商品主色（每行一个）</span><textarea class="input" name="primary_colors" rows="3"><?= escape_html(implode("\n", $selectedPrimaryColors)) ?></textarea></label>
        <label><span>材质与工艺</span><textarea class="input" name="materials" rows="3"><?= escape_html((string) ($selectedSku['report_materials'] ?? '')) ?></textarea></label>
        <label><span>主体外观、颜色、结构、包装</span><textarea class="input" name="appearance" rows="4"><?= escape_html((string) ($selectedSku['report_appearance'] ?? '')) ?></textarea></label>
        <label><span>包装与品牌标识</span><textarea class="input" name="brand_notes" rows="3"><?= escape_html((string) ($selectedSku['report_brand_notes'] ?? '')) ?></textarea></label>
        <label><span>适用场景（每行一个）</span><textarea class="input" name="use_scenarios" rows="3"><?= escape_html(implode("\n", decode_json_list($selectedSku['report_use_scenarios'] ?? '[]'))) ?></textarea></label>
        <label><span>核心功能（每行一个）</span><textarea class="input" name="core_functions" rows="3"><?= escape_html(implode("\n", decode_json_list($selectedSku['report_core_functions'] ?? '[]'))) ?></textarea></label>
        <label><span>待确认信息（每行一个）</span><textarea class="input" name="uncertain_points" rows="3"><?= escape_html(implode("\n", decode_json_list($selectedSku['report_uncertain_points'] ?? '[]'))) ?></textarea></label>
        <label><span>风险表述（每行一个）</span><textarea class="input" name="prohibited_claims" rows="3"><?= escape_html(implode("\n", decode_json_list($selectedSku['report_prohibited_claims'] ?? '[]'))) ?></textarea></label>
        <footer><a href="/workspace/skus/<?= (int) $selectedSku['id'] ?>?section=foundation">打开产品报告</a><button class="button primary" type="submit"><i data-lucide="save"></i><span>保存产品报告</span></button></footer>
    </form>
</aside>
<?php endif; ?>
<?php require __DIR__ . '/../partials/merchant_footer.php'; ?>
