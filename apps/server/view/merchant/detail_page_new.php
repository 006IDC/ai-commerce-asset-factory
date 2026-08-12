<?php require __DIR__ . '/../partials/merchant_header.php'; ?>
<div class="page-actions"><div><h2>建立独立详情页方案</h2><p>适合暂时不归档到商品库的素材。上传清晰实拍图后，AI 会整理商品资料快照与详情页脚本。</p></div><a class="button" href="/workspace/generate?mode=detail"><i data-lucide="arrow-left"></i><span>返回详情页生成</span></a></div>
<section class="panel detail-create-panel">
    <div class="panel-header"><div><h3>商品与市场信息</h3><p>先做识别和策划，不会立即生成十张图。</p></div><span class="generation-price-chip"><?= (int) $recognitionCredits > 0 ? number_format((int) $recognitionCredits) . ' 点 / 次分析' : '本次分析免费' ?></span></div>
    <div class="panel-body">
        <form method="post" action="/workspace/detail-pages" enctype="multipart/form-data" data-detail-create-form data-market-suggest-url="/workspace/skus/analyze-image" data-submit-loading data-loading-message="正在检查商品实拍图安全性..." data-loading-stages="正在检查商品实拍图安全性...|安全检查通过后自动识别并整理商品资料...">
            <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
            <input type="hidden" name="analysis_draft_token" value="" data-analysis-draft-token>
            <div class="detail-create-layout">
                <div class="form-field detail-source-upload"><label for="detail-source-image">商品实拍图</label><label class="free-upload-zone" for="detail-source-image" data-detail-upload-zone><input id="detail-source-image" name="source_image" type="file" accept="image/jpeg,image/png,image/webp" required data-detail-source-input><span class="free-upload-empty" data-detail-upload-empty><i data-lucide="image-plus"></i><strong>选择一张商品实拍图</strong><small>JPG、PNG、WebP，最大 24MB</small></span><span class="free-upload-preview" data-detail-upload-preview hidden><img alt="商品实拍图预览" data-detail-source-preview><small data-detail-source-name></small></span></label><small>尽量保证商品完整、光线清楚，避免严重遮挡和低清截图。</small></div>
                <div class="detail-create-fields">
                    <div class="form-grid"><div class="form-field"><label for="detail-platform">目标平台</label><select class="input" id="detail-platform" name="platform" required><?php foreach ($platforms as $value => $label): ?><option value="<?= escape_html($value) ?>" <?= $value === $preferredPlatform ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select><small>默认使用你上次选择的平台</small></div><div class="form-field"><label for="detail-language">画面文案语言</label><select class="input" id="detail-language" name="language" required><?php foreach ($languages as $value => $label): ?><option value="<?= escape_html($value) ?>" <?= $value === $preferredLanguage ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select><small>默认使用你上次选择的语言</small></div></div>
                    <div class="form-field"><label for="detail-creative-brief">补充想法 <span>可选</span></label><textarea class="input" id="detail-creative-brief" name="creative_brief" rows="8" maxlength="2000" placeholder="例如：面向北美通勤女性，重点强调轻便、防泼水和内部收纳；品牌风格简洁克制。没有想法可以留空。"></textarea><small>图片看不出的真实卖点、目标人群、市场定位和品牌要求可以写在这里。</small></div>
                </div>
            </div>
            <div class="form-footer"><button class="button primary" type="submit"><i data-lucide="scan-search"></i><span>分析商品并建立详情页方案</span></button></div>
        </form>
    </div>
</section>
<?php require __DIR__ . '/../partials/merchant_footer.php'; ?>
