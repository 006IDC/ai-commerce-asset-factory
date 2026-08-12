<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<div class="page-actions">
    <div>
        <p class="eyebrow">通用 AI 能力中心</p>
        <h2>AI 能力总览</h2>
        <p>按业务能力确认模型、执行真实测试并分配功能。上游账号、密钥和物理路由继续只在 Sub2API 管理。</p>
    </div>
    <div class="button-row">
        <a class="button" href="/admin/ai-models"><i data-lucide="box"></i><span>模型目录</span></a>
        <a class="button primary" href="/admin/ai-assignments"><i data-lucide="route"></i><span>功能分配</span></a>
    </div>
</div>

<section class="stats-grid">
    <article class="stat blue"><small>业务模型</small><strong><?= (int) $catalogData['stats']['total'] ?></strong><span>只保存 Sub2API 稳定别名和业务配置</span></article>
    <article class="stat green"><small>目录可见</small><strong><?= (int) $catalogData['stats']['visible'] ?></strong><span>当前能从 Sub2API 读取</span></article>
    <article class="stat amber"><small>新发现</small><strong><?= (int) $catalogData['stats']['new'] ?></strong><span>默认不启用、不上架</span></article>
    <article class="stat"><small>需要处理</small><strong><?= (int) $catalogData['stats']['attention'] ?></strong><span>来源消失或测试异常</span></article>
</section>

<section class="ai-capability-card-grid">
    <?php foreach ($capabilityCards as $capability): ?>
    <article class="ai-capability-card">
        <header>
            <span class="creative-product-icon"><i data-lucide="<?= $capability['billable'] ? 'wand-sparkles' : 'cpu' ?>"></i></span>
            <div><h3><?= escape_html($capability['label']) ?></h3><p><?= escape_html($capability['description']) ?></p></div>
            <span class="badge <?= $capability['assigned'] ? 'completed' : 'pending' ?>"><?= $capability['assigned'] ? '已分配' : '待分配' ?></span>
        </header>
        <dl>
            <div><dt>已标注模型</dt><dd><?= (int) $capability['model_count'] ?></dd></div>
            <div><dt>真实测试通过</dt><dd><?= (int) $capability['ready_count'] ?></dd></div>
            <div><dt>费用归属</dt><dd><?= $capability['billable'] ? '商户生成' : '平台内部' ?></dd></div>
        </dl>
        <footer>
            <span><?= $capability['billable'] ? '商户主动选择，失败不换模型' : '可配置主模型与备用模型' ?></span>
            <a class="button small" href="/admin/ai-assignments#capability-<?= escape_html($capability['code']) ?>">配置</a>
        </footer>
    </article>
    <?php endforeach; ?>
</section>

<section class="panel ai-boundary-panel">
    <div class="panel-header"><div><h3>控制面边界</h3><p>ThinkPHP 与 Sub2API 各自只管理自己的职责，避免两套后台互相覆盖。</p></div></div>
    <div class="ai-boundary-grid">
        <article><strong>ThinkPHP 管理</strong><p>中文名称、能力确认、真实测试、生成业务兼容、商户开放池、默认模型、价格和套餐限制。</p></article>
        <article><strong>Sub2API 管理</strong><p>上游账号、API Key、物理渠道、账号调度、供应商模型映射和实际请求转发。</p></article>
        <article><strong>ComfyUI 管理</strong><p>复杂媒体工作流节点、输入映射和异步执行；不决定商户价格，也不静默替换模型。</p></article>
    </div>
</section>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
