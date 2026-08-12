<?php require __DIR__ . '/../partials/merchant_header.php'; ?>
<div class="page-actions"><div><h2>新建商品</h2><p>先上传一张实拍图，AI 可直接完成商品建档。</p></div><a class="button" href="/workspace/skus">返回商品列表</a></div>
<div class="sku-onboarding-steps" aria-label="商品创作流程"><div class="active"><span>1</span><strong>上传商品图</strong></div><div><span>2</span><strong>AI 整理商品资料</strong></div><div><span>3</span><strong>选择生成目标</strong></div></div>
<section class="panel sku-create-panel">
    <form method="post" action="/workspace/skus" enctype="multipart/form-data" data-sku-analyzer data-submit-loading data-loading-message="正在检查提交内容安全性..." data-loading-stages="正在检查商品图与文字安全性...|安全检查通过，正在保存商品资料..." data-analyze-url="/workspace/skus/analyze-image">
        <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
        <input type="hidden" name="foundation_payload" data-foundation-payload>
        <div class="sku-create-layout">
            <div class="sku-create-image-column">
                <div class="section-heading"><div><h3>上传商品实拍图</h3><p>清晰展示商品主体，一张图即可开始。</p></div><span class="badge active">第一步</span></div>
                <label class="sku-create-upload" for="source_image">
                    <input id="source_image" name="source_image" type="file" accept="image/jpeg,image/png,image/webp">
                    <span data-sku-upload-empty><i data-lucide="image-plus"></i><strong>选择或拖入商品图片</strong><small>JPG、PNG、WebP，最大 24MB</small></span>
                    <img data-sku-upload-preview alt="商品图预览" hidden>
                </label>
                <div class="analyze-action primary-analyze-action"><button class="button primary block" type="button" data-analyze-product><i data-lucide="sparkles"></i><span>AI 一键建立商品资料<?= (int) $recognitionPrice > 0 ? ' · ' . number_format((int) $recognitionPrice) . ' 点' : '' ?></span></button><span data-analyze-status>上传图片后点击即可自动填写右侧资料</span></div>
            </div>
            <div class="sku-create-fields">
                <div class="section-heading"><div><h3>商品资料</h3><p>AI 填写后仍可人工修改。</p></div><button class="button small" type="button" data-open-sku-advisor><i data-lucide="messages-square"></i><span>创作顾问</span></button></div>
                <div class="form-grid compact-form-grid">
                    <div class="form-field full"><label for="name">商品名称</label><input class="input" id="name" name="name" maxlength="160" required placeholder="可由 AI 自动识别"></div>
                    <div class="form-field"><label for="category">商品类目</label><input class="input" id="category" name="category" maxlength="100" required placeholder="可由 AI 自动识别"></div>
                    <div class="form-field"><label for="sku_code">SKU 编码 <span>可选</span></label><input class="input" id="sku_code" name="sku_code" maxlength="80" placeholder="留空自动生成"></div>
                    <div class="form-field full"><label for="creative_brief">商品备注 <span>可选</span></label><textarea class="input" id="creative_brief" name="creative_brief" rows="4" maxlength="1000" placeholder="只填写长期商品事实；本次创作想法请在商品上新时填写"></textarea></div>
                </div>
                <details class="sku-advanced-fields">
                    <summary><span><i data-lucide="sliders-horizontal"></i>查看并编辑 AI 生成的详细资料</span><i data-lucide="chevron-down"></i></summary>
                    <div class="form-grid compact-form-grid">
                        <div class="form-field full"><label for="search_terms">商品搜索关键词 <span>每行一个</span></label><textarea class="input" id="search_terms" name="search_terms" rows="3" placeholder="用于商品管理和资料检索"></textarea></div>
                        <div class="form-field full"><label for="selling_points">商品卖点</label><textarea class="input" id="selling_points" name="selling_points" rows="4" placeholder="每行一个卖点"></textarea></div>
                        <div class="form-field full"><label for="product_description">商品详情文案</label><textarea class="input product-description-input" id="product_description" name="product_description" rows="5" maxlength="4000"></textarea></div>
                    </div>
                </details>
                <div class="form-footer sku-create-footer"><a class="button" href="/workspace/skus">取消</a><button class="button primary" type="submit"><span>保存并进入商品工作台</span><i data-lucide="arrow-right"></i></button></div>
            </div>
        </div>
    </form>
</section>
<?php require __DIR__ . '/../partials/sku_advisor_dialog.php'; ?>
<?php require __DIR__ . '/../partials/merchant_footer.php'; ?>
