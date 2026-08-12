<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<?php
$steps = (array) $progress['steps'];
$state = (array) $progress['step_state'];
$currentStep = (string) $progress['current_step'];
$currentDefinition = (array) ($steps[$currentStep] ?? reset($steps));
$modelStats = (array) ($catalog['stats'] ?? []);
?>
<div class="page-actions">
    <div>
        <p class="eyebrow">首次配置 · 进度会自动保存</p>
        <h2>让系统可以安全开始创作</h2>
        <p>按顺序完成网关、模型、功能、价格和提示词检查。向导不会自动发起任何付费图片或视频请求。</p>
    </div>
    <?php if ((string) $progress['status'] === 'completed'): ?><a class="button primary" href="/admin">进入运营工作台</a><?php endif; ?>
</div>

<section class="panel setup-progress-panel">
    <div class="panel-header">
        <div><h3><?= escape_html((string) $currentDefinition['label']) ?></h3><p><?= escape_html((string) $currentDefinition['description']) ?></p></div>
        <strong><?= (int) $progress['percent'] ?>%</strong>
    </div>
    <div class="setup-progress-track"><i style="width: <?= (int) $progress['percent'] ?>%"></i></div>
    <ol class="setup-step-list">
        <?php foreach ($steps as $stepCode => $definition): $done = (string) ($state[$stepCode]['status'] ?? '') === 'completed'; ?>
        <li class="<?= $done ? 'completed' : ($currentStep === $stepCode ? 'current' : '') ?>">
            <span><?= $done ? '<i data-lucide="check"></i>' : (array_search($stepCode, array_keys($steps), true) + 1) ?></span>
            <div><strong><?= escape_html((string) $definition['label']) ?></strong><small><?= escape_html((string) $definition['description']) ?></small></div>
        </li>
        <?php endforeach; ?>
    </ol>
</section>

<?php if ((string) $progress['status'] !== 'completed'): ?>
<section class="panel setup-current-card">
    <div class="panel-header"><div><h3>当前步骤：<?= escape_html((string) $currentDefinition['label']) ?></h3><p><?= escape_html((string) $currentDefinition['description']) ?></p></div></div>
    <div class="panel-body">
        <?php if ($currentStep === 'welcome'): ?>
            <div class="ai-boundary-grid">
                <article><strong>业务后台</strong><p>管理可用模型、功能分配、用户价格、提示词与创作功能。</p></article>
                <article><strong>Sub2API</strong><p>管理上游账号、密钥、渠道和实际请求转发。</p></article>
                <article><strong>ComfyUI</strong><p>只执行画布 AI 编辑工作流。</p></article>
            </div>
        <?php elseif ($currentStep === 'connect_gateway'): ?>
            <div class="gateway-state <?= !empty($gateway['configured']) ? 'success' : '' ?>">
                <strong><?= !empty($gateway['configured']) ? 'AI 网关连接参数已就绪' : 'AI 网关尚未配置完整' ?></strong>
                <p><?= !empty($gateway['configured']) ? '地址：' . escape_html((string) $gateway['baseUrl']) . '；服务密钥已安全保存在服务器端。' : '请在服务器安全配置中设置 Sub2API 地址和服务密钥；浏览器不会看到密钥。' ?></p>
            </div>
        <?php elseif ($currentStep === 'sync_models'): ?>
            <div class="stats-grid">
                <article class="stat"><small>模型记录</small><strong><?= (int) ($modelStats['total'] ?? 0) ?></strong><span>含历史记录</span></article>
                <article class="stat green"><small>网关可见</small><strong><?= (int) ($modelStats['visible'] ?? 0) ?></strong><span>当前仍在目录中</span></article>
                <article class="stat amber"><small>新发现</small><strong><?= (int) ($modelStats['new'] ?? 0) ?></strong><span>不会自动开放</span></article>
            </div>
        <?php elseif ($currentStep === 'recommendations'): ?>
            <div class="empty-state compact"><strong>推荐配置只选择“来源可见、能力已确认、真实测试已通过”的模型</strong><span>新发现模型、停用模型和来源消失模型不会自动加入任何用户功能。</span></div>
        <?php elseif ($currentStep === 'assign_functions'): ?>
            <div class="detail-list spaced">
                <?php foreach ((array) ($assignments['definitions'] ?? []) as $capability => $definition): $route = (array) ($assignments['routes'][$capability] ?? []); ?>
                <div class="detail-row"><span><?= escape_html((string) $definition['label']) ?></span><strong><?= !empty($route['primary_model_id']) || !empty($route['pool_model_ids']) ? '已分配' : '待分配' ?></strong></div>
                <?php endforeach; ?>
            </div>
            <div class="button-row panel-actions"><a class="button" href="/admin/ai-settings?tab=functions">打开模型与功能设置</a></div>
        <?php elseif ($currentStep === 'verify_models'): ?>
            <div class="gateway-state"><strong>这里只检查已有测试结果</strong><p>图片与视频真实测试可能产生上游费用，必须进入模型详情手动勾选“确认费用”后执行；向导不会代替你确认。</p></div>
            <a class="button" href="/admin/ai-settings?tab=models">查看已有检查结果</a>
        <?php elseif ($currentStep === 'set_prices'): ?>
            <p>内部审核、识别和策划由平台承担；图片与视频按用户最终选择的模型计费。</p>
            <a class="button" href="/admin/ai-settings?tab=functions#model-prices">设置模型价格</a>
        <?php elseif ($currentStep === 'review_prompts'): ?>
            <p>每个业务任务只维护一份完整提示词模板正文；不再拆分积木、候选版本或发布集合。</p>
            <a class="button" href="/admin/prompts">打开提示词</a>
        <?php elseif ($currentStep === 'first_creation'): ?>
            <div class="gateway-state"><strong>由你决定何时发起付费测试</strong><p>可以先打开自由创作检查页面与模型选择。只有你在商户端确认提交后，才会真正进入队列和计费链。</p></div>
            <a class="button" href="/workspace/free-create" target="_blank" rel="noopener">打开自由创作</a>
        <?php elseif ($currentStep === 'complete'): ?>
            <div class="empty-state compact"><strong>配置检查已经完成</strong><span>点击完成后进入运营工作台；以后仍可从各设置页继续调整。</span></div>
        <?php endif; ?>
    </div>
    <div class="panel-actions setup-action-row">
        <?php if ($currentStep === 'assign_functions'): ?>
        <form method="post" action="/admin/setup/skip-video"><input type="hidden" name="_token" value="<?= escape_html((string) $csrfToken) ?>"><button class="button" type="submit">暂不启用视频</button></form>
        <?php endif; ?>
        <form method="post" action="/admin/setup/advance">
            <input type="hidden" name="_token" value="<?= escape_html((string) $csrfToken) ?>">
            <input type="hidden" name="step" value="<?= escape_html($currentStep) ?>">
            <?php if ($currentStep === 'sync_models'): ?><label class="inline-check"><input type="checkbox" name="confirm_sync" value="1" required> 确认读取最新模型目录</label><?php endif; ?>
            <button class="button primary" type="submit"><?= $currentStep === 'complete' ? '完成首次配置' : ($currentStep === 'sync_models' ? '同步并继续' : '保存并继续') ?></button>
        </form>
    </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
