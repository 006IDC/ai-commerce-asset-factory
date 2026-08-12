<?php require __DIR__ . '/../partials/merchant_header.php'; ?>
<?php
$launchPlatform = $preferredPlatform;
$launchLanguage = $preferredLanguage;
$launchBrief = '';
?>
<section class="product-launch product-launch-focused" data-product-launch>
    <form method="post" action="/workspace/detail-pages" enctype="multipart/form-data"
          data-async-product-launch
          data-prompt-endpoint="/workspace/generate/optimize-prompt"
          data-csrf-endpoint="/workspace/csrf-token"
          data-recognition-credits="<?= (int) $recognitionCredits ?>">
        <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
        <input type="hidden" name="async_launch" value="1">
        <input type="hidden" name="client_submission_id" value="<?= escape_html(bin2hex(random_bytes(16))) ?>" data-launch-client-submission-id>
        <input type="hidden" name="create_product_report" value="1">
        <input type="hidden" name="image_ratio" value="auto">
        <input type="hidden" name="target_audience" value="">
        <input type="hidden" name="visual_preference" value="">
        <input type="hidden" name="highlight_features" value="">

        <div class="product-launch-stage">
            <span class="product-launch-stage-icon"><i data-lucide="package-plus"></i></span>
            <h2>从一张商品图开始</h2>
            <p>上传商品图并选择上新方案。AI 会自动建档和策划，完成后直接带你进入对应的生成清单。</p>
            <div class="product-launch-flow" aria-label="商品上新三步流程">
                <span><b>1</b>添加商品图</span><i data-lucide="chevron-right"></i>
                <span><b>2</b>AI 整理资料</span><i data-lucide="chevron-right"></i>
                <span><b>3</b>先预览或生成整套</span>
            </div>
            <div class="product-launch-examples" aria-label="常用创作需求示例">
                <button type="button" data-launch-example="生成适合抖音的高点击商品主图"><i data-lucide="mouse-pointer-click"></i><span>生成适合抖音的高点击商品主图</span></button>
                <button type="button" data-launch-example="为这款商品制作一套简洁高级的上架图"><i data-lucide="layers-3"></i><span>制作一套简洁高级的上架图</span></button>
                <button type="button" data-launch-example="突出商品材质、细节和核心卖点"><i data-lucide="scan-search"></i><span>突出材质、细节和核心卖点</span></button>
                <button type="button" data-launch-example="生成适合欧美用户的生活方式场景图"><i data-lucide="globe-2"></i><span>生成欧美生活方式场景图</span></button>
            </div>
            <fieldset class="product-launch-plan-options" data-launch-plan-options>
                <legend>选择上新方案</legend>
                <label><input type="radio" name="launch_plan" value="smart" checked><span><i data-lucide="sparkles"></i><strong>智能推荐</strong><small>根据商品和平台自动选择</small></span></label>
                <label><input type="radio" name="launch_plan" value="quick"><span><i data-lucide="zap"></i><strong>快速上新</strong><small>先做核心主图与卖点图</small></span></label>
                <label><input type="radio" name="launch_plan" value="bundle"><span><i data-lucide="layers-3"></i><strong>完整商品套图</strong><small>上架图与详情页一次规划</small></span></label>
                <label><input type="radio" name="launch_plan" value="detail"><span><i data-lucide="panels-top-left"></i><strong>详情页套图</strong><small>直接进入详情页生成清单</small></span></label>
            </fieldset>
            <a href="/workspace/free-create">没有商品图？进入自由创作</a>

            <div class="product-launch-progress" data-launch-progress hidden role="status" aria-live="polite">
                <div class="product-launch-progress-head"><strong data-launch-progress-title>商品分析正在后台处理</strong><b data-launch-progress-percent>0%</b></div>
                <div class="product-launch-progress-track" aria-hidden="true"><span data-launch-progress-bar></span></div>
                <small data-launch-progress-message>图片上传后，安全检查与商品识别会在后台继续进行。</small>
                <button class="button small" type="button" data-launch-retry hidden><i data-lucide="rotate-cw"></i><span>仅重试失败阶段</span></button>
            </div>
        </div>

        <div class="product-launch-composer" data-launch-dropzone>
            <label class="product-launch-image-button" for="launch-source-image">
                <i data-lucide="image-plus"></i>
                <strong>商品图</strong>
                <small>1–6 张</small>
                <input id="launch-source-image" name="source_images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple required>
            </label>
            <div class="product-launch-images" data-launch-images hidden aria-label="已选择的商品参考图"></div>

            <div class="product-launch-composer-input">
                <textarea name="creative_brief" rows="2" maxlength="1200"
                          placeholder="描述这次想生成什么，例如目标用户、重点卖点、使用场景或画面风格。"
                          data-launch-brief><?= escape_html($launchBrief) ?></textarea>
                <button class="product-launch-ai-assist" type="button" data-launch-ai-assist aria-label="让 AI 补充创作需求" data-tooltip="AI 润色或提问引导">
                    <i data-lucide="wand-sparkles"></i>
                    <span>AI 帮我补充<?= (int) $promptOptimizationCredits > 0 ? ' · ' . number_format((int) $promptOptimizationCredits) . ' 点' : '' ?></span>
                </button>
            </div>

            <div class="product-launch-composer-options">
                <span class="product-launch-composer-option"><i data-lucide="sparkles"></i><strong data-launch-plan-summary>智能推荐方案</strong></span>
                <span class="product-launch-composer-option"><i data-lucide="map-pin"></i><strong data-launch-preference-summary><?= escape_html($platforms[$launchPlatform] ?? $launchPlatform) ?> · <?= escape_html($languages[$launchLanguage] ?? $launchLanguage) ?></strong></span>
                <details class="product-launch-preferences" data-dismiss-on-outside>
                    <summary>修改偏好</summary>
                    <div>
                        <label><span>销售平台</span><select class="input" name="platform"><?php foreach ($platforms as $value => $label): ?><option value="<?= escape_html($value) ?>" <?= $value === $launchPlatform ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select></label>
                        <label><span>画面语言</span><select class="input" name="language"><?php foreach ($languages as $value => $label): ?><option value="<?= escape_html($value) ?>" <?= $value === $launchLanguage ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select></label>
                    </div>
                </details>
                <small class="product-launch-submit-reason" id="launch-submit-reason" data-launch-submit-reason role="status" aria-live="polite">请先添加至少一张商品图片</small>
            </div>

            <button class="button primary product-launch-composer-submit" type="submit" data-launch-submit disabled aria-describedby="launch-submit-reason">
                <span>分析商品并继续</span>
                <i data-lucide="arrow-right"></i>
            </button>
            <div class="product-launch-ai-status" data-launch-ai-status hidden></div>
        </div>
    </form>
</section>

<dialog class="sku-advisor-dialog" data-launch-advisor>
    <div class="prompt-helper-header"><div><strong>AI 帮你补充创作需求</strong><small>回答三个简单问题，只影响这次创作，不会改写已确认的商品事实。</small></div><button type="button" data-close-launch-advisor aria-label="关闭" title="关闭"><i data-lucide="x"></i></button></div>
    <div class="sku-advisor-body"><div class="advisor-chat-list" data-launch-advisor-chat aria-live="polite"></div><div class="advisor-options" data-launch-advisor-options></div><label class="advisor-custom-answer" data-launch-advisor-custom hidden><span>补充你的想法</span><input class="input" maxlength="120" placeholder="输入后按确认"></label></div>
    <div class="prompt-helper-footer"><button class="button" type="button" data-launch-advisor-skip>交给 AI 自动规划</button><button class="button primary" type="button" data-launch-advisor-confirm hidden>确认补充</button></div>
</dialog>
<?php require __DIR__ . '/../partials/merchant_footer.php'; ?>
