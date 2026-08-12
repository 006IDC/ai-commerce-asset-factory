<?php require __DIR__ . '/partials/public_header.php'; ?>
<section class="landing-hero" id="create" aria-labelledby="landing-hero-title">
    <div class="landing-container landing-hero-layout">
        <div class="landing-hero-copy">
            <span class="landing-kicker"><i data-lucide="sparkles" aria-hidden="true"></i> AI 商品上新</span>
            <h1 id="landing-hero-title">从一张商品图，得到一套能上架的电商素材</h1>
            <p>上传商品图片，AI 自动识别商品、整理档案、策划整套内容。你只需校对关键信息，就能继续预览或生成主图、卖点图、场景图和详情页。</p>
            <div class="landing-hero-points" aria-label="产品特点">
                <span><i data-lucide="check" aria-hidden="true"></i>自动填写名称、类目、卖点、材质候选、场景和人群</span>
                <span><i data-lucide="check" aria-hidden="true"></i>同一商品持续复用，不必反复上传和识别</span>
                <span><i data-lucide="check" aria-hidden="true"></i>每张图承担不同购买决策任务，整套视觉统一</span>
            </div>
        </div>

        <form class="landing-create-console" data-guest-creation-console novalidate>
            <div class="landing-console-heading">
                <div><span class="landing-console-status"><i></i>商品上新</span><strong>添加商品图片</strong><small>第一张作为商品主参考，其他图片帮助识别侧面、细节与包装。</small></div>
                <span class="landing-console-limit">最多 6 张商品图</span>
            </div>

            <div class="landing-reference-zone" data-guest-upload-zone>
                <input id="guest-product-images" type="file" accept="image/jpeg,image/png,image/webp" multiple data-guest-image-input required>
                <label for="guest-product-images" data-guest-upload-empty>
                    <i data-lucide="image-plus" aria-hidden="true"></i>
                    <span><strong>上传商品图</strong><small>点击、拖放或粘贴 JPEG / PNG / WebP，建议包含正面与细节</small></span>
                </label>
                <div class="landing-reference-list" data-guest-upload-previews hidden aria-label="商品图片预览"></div>
            </div>

            <div class="landing-prompt-field">
                <label for="guest-brief">这次想怎么卖 <span>可选</span></label>
                <textarea id="guest-brief" rows="3" maxlength="1200" data-guest-brief placeholder="例如：面向通勤新人，重点突出轻便和收纳，整体简洁高级。也可以留空，交给 AI 自动规划。"></textarea>
                <div>
                    <button type="button" data-guest-local-helper><i data-lucide="wand-sparkles" aria-hidden="true"></i><span>帮我补充</span></button>
                    <span><b data-guest-brief-count>0</b>/1200</span>
                </div>
            </div>

            <div class="landing-create-settings">
                <label><span>上新方案</span><select data-guest-plan><option value="smart">智能推荐</option><option value="quick">快速上新</option><option value="bundle">完整商品套图</option><option value="detail">详情页套图</option></select></label>
                <label><span>销售平台</span><select data-guest-platform><option value="douyin">抖音</option><option value="taobao">淘宝 / 天猫</option><option value="amazon">Amazon</option><option value="shopee">Shopee</option><option value="general">通用电商</option></select></label>
                <label><span>画面语言</span><select data-guest-language><option value="zh-CN">简体中文</option><option value="zh-TW">繁体中文</option><option value="en-US">English</option></select></label>
            </div>

            <div class="landing-console-message" data-guest-message aria-live="polite">请先添加至少一张清晰的商品图片。</div>
            <div class="landing-console-submit">
                <small>注册后继续上传并整理商品资料，当前上新设置会保留。</small>
                <button class="landing-button primary" type="submit" disabled data-guest-submit><span>开始商品上新</span><i data-lucide="arrow-right" aria-hidden="true"></i></button>
            </div>
        </form>
    </div>
    <div class="landing-container landing-example-row" aria-label="上新需求示例">
        <span>常用上新方向</span>
        <?php foreach ([
            '生成适合抖音的高点击商品主图',
            '制作一套简洁高级的上架图',
            '突出商品材质、细节和核心卖点',
            '生成适合欧美用户的生活方式场景图',
        ] as $example): ?><button type="button" data-guest-example="<?= escape_html($example) ?>"><?= escape_html($example) ?></button><?php endforeach; ?>
    </div>
</section>

<section class="landing-workflow" id="workflow" aria-labelledby="landing-workflow-title">
    <div class="landing-container">
        <header class="landing-section-heading">
            <span class="landing-kicker">真实工作流程</span>
            <h2 id="landing-workflow-title">上传一次，持续生产同一件商品的视觉资产</h2>
            <p>普通商家只处理商品和上新目标；识别、商品报告整理和素材生成由系统后台完成。</p>
        </header>
        <ol class="landing-workflow-list">
            <li><b>01</b><span><strong>自动认识商品</strong><small>从多张商品图整理名称、类目、卖点、外观、候选材质、场景与人群。</small></span></li>
            <li><b>02</b><span><strong>校对关键资料</strong><small>AI 推测会明确标注；未经确认的参数、功效和认证不会进入正式文案。</small></span></li>
            <li><b>03</b><span><strong>策划并生成整套</strong><small>主图、卖点图、场景图、细节图和详情页各自解决不同购买问题。</small></span></li>
            <li><b>04</b><span><strong>持续复用商品档案</strong><small>切换平台、语言和用途时直接再次创作，不必重复上传和识别。</small></span></li>
        </ol>
    </div>
</section>

<section class="landing-proof">
    <div class="landing-container landing-proof-grid">
        <article><i data-lucide="scan-search" aria-hidden="true"></i><strong>商品档案自动完成</strong><p>用户主要负责校对，不必从空白表单开始录入商品资料。</p></article>
        <article><i data-lucide="layers-3" aria-hidden="true"></i><strong>整套内容直接生成</strong><p>每张图使用同一份商品资料和用户要求，分别完成主图、卖点、场景与细节表达。</p></article>
        <article><i data-lucide="shield-check" aria-hidden="true"></i><strong>失败及时结束</strong><p>上游繁忙最多额外尝试一次；没有获得结果就明确结束，并按现有规则退回点数。</p></article>
    </div>
</section>

<section class="landing-pricing" id="pricing" aria-labelledby="landing-pricing-title">
    <div class="landing-container">
        <header class="landing-section-heading">
            <span class="landing-kicker">套餐与点数</span>
            <h2 id="landing-pricing-title">权益和实际生成消耗分开说明</h2>
            <p>提交前展示预计点数，按实际成功结果结算；失败或未完成部分遵循现有退款规则。</p>
        </header>
        <?php if (!empty($publicPlans)): ?>
        <div class="landing-plan-grid">
            <?php foreach ($publicPlans as $plan): ?>
            <article class="landing-plan-card">
                <div><strong><?= escape_html((string) $plan['name']) ?></strong><p><?= escape_html((string) ($plan['audience_label'] ?: $plan['description'] ?: '适合持续商品创作')) ?></p></div>
                <div class="landing-plan-price"><b><?= number_format((int) $plan['price_credits']) ?></b><span>点 / <?= max(1, (int) $plan['duration_days']) ?> 天</span></div>
                <a class="landing-button secondary" href="/register?return_to=<?= rawurlencode('/workspace/plans') ?>">注册后查看</a>
            </article>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="landing-empty-state"><i data-lucide="badge-info" aria-hidden="true"></i><div><strong>公开套餐正在配置</strong><p>注册后可查看账户当前可用的点数、套餐与真实生成价格。</p></div><a class="landing-button secondary" href="/register">创建账户</a></div>
        <?php endif; ?>
    </div>
</section>

<section class="landing-release-band" aria-labelledby="landing-release-title">
    <div class="landing-container">
        <div>
            <span class="landing-kicker">持续发布</span>
            <h2 id="landing-release-title">当前版本 v<?= escape_html((string) $publicVersion) ?></h2>
            <p>版本说明只保留已经完成并可核验的用户能力，不展示内部路径、凭据或模型供应链细节。</p>
        </div>
        <a class="landing-button secondary" href="/releases" data-release-drawer-open>查看版本历程</a>
    </div>
</section>

<dialog class="landing-release-drawer" data-release-drawer aria-labelledby="release-drawer-title">
    <div class="landing-release-drawer-header">
        <div><span class="landing-kicker">版本历程</span><h2 id="release-drawer-title">产品持续演进记录</h2><p>版本号优先，按创作工作流与管理可靠性筛选。</p></div>
        <button type="button" data-release-drawer-close aria-label="关闭版本历程"><i data-lucide="x" aria-hidden="true"></i></button>
    </div>
    <div class="landing-release-filters" role="group" aria-label="版本筛选">
        <?php foreach ($publicReleaseFilters as $value => $label): ?><button type="button" data-release-filter="<?= escape_html((string) $value) ?>" aria-pressed="<?= $value === 'all' ? 'true' : 'false' ?>"><?= escape_html((string) $label) ?></button><?php endforeach; ?>
    </div>
    <div class="landing-release-list" data-release-list>
        <?php foreach ($publicReleases as $release): ?>
        <article data-release-category="<?= escape_html((string) $release['category']) ?>" data-release-milestone="<?= !empty($release['milestone']) ? '1' : '0' ?>">
            <div><b>v<?= escape_html((string) $release['version']) ?></b><?php if (!empty($release['milestone'])): ?><span>里程碑</span><?php endif; ?></div>
            <h3><?= escape_html((string) $release['public_title']) ?></h3>
            <p><?= escape_html((string) $release['public_summary']) ?></p>
            <ul><?php foreach ($release['highlights'] as $highlight): ?><li><?= escape_html((string) $highlight) ?></li><?php endforeach; ?></ul>
        </article>
        <?php endforeach; ?>
    </div>
    <div class="landing-release-drawer-footer"><a href="/releases">打开完整版本页</a><button class="landing-button secondary" type="button" data-release-drawer-close>关闭</button></div>
</dialog>
<?php require __DIR__ . '/partials/public_footer.php'; ?>
