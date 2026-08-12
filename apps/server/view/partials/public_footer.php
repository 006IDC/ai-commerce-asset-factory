</main>
<section class="landing-cta" aria-labelledby="landing-cta-title">
    <div class="landing-container">
        <div>
            <span class="landing-kicker">准备开始</span>
            <h2 id="landing-cta-title">把下一件商品交给清晰、可追踪的创作流程</h2>
            <p>上传商品图片，AI 自动整理商品资料并策划主图、卖点图、场景图和详情页套图。</p>
        </div>
        <div class="landing-cta-actions">
            <a class="landing-button primary" href="/register">注册并开始</a>
            <a class="landing-button secondary" href="/login">已有账户登录</a>
        </div>
    </div>
</section>
<footer class="landing-footer">
    <div class="landing-container landing-footer-grid">
        <div class="landing-footer-intro">
            <a class="landing-brand" href="/"><span class="landing-brand-mark" aria-hidden="true">AI</span><span><strong>AI 电商素材工厂</strong><small>商品素材生产工作台</small></span></a>
            <p>把一组商品图片变成可持续复用、可直接用于上新的电商视觉资产。</p>
        </div>
        <div><strong>产品</strong><a href="/#create">商品上新</a><a href="/#workflow">工作流程</a><a href="/#pricing">套餐</a></div>
        <div><strong>资源</strong><a href="/releases">版本历程</a><a href="/workspace/generate">商家工作台</a></div>
        <div><strong>账户</strong><a href="/login">商家登录</a><a href="/register">创建账户</a><a href="/forgot-password">找回密码</a></div>
    </div>
    <div class="landing-container landing-footer-bottom">
        <span>© <?= (int) date('Y') ?> AI 电商素材工厂</span>
        <a href="/releases">当前版本 v<?= escape_html((string) ($publicVersion ?? '')) ?> · 查看版本历程</a>
    </div>
</footer>
</body>
</html>
