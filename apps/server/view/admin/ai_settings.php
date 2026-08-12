<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<?php
$relationReady = static function (array $model, string $capability): bool {
    if (empty($model['enabled']) || empty($model['source_visible']) || !empty($model['archived_at'])) {
        return false;
    }
    foreach ((array) ($model['capability_relations'] ?? []) as $relation) {
        if ((string) ($relation['capability_code'] ?? '') === $capability
            && (string) ($relation['test_status'] ?? '') === 'passed') {
            return true;
        }
    }
    return false;
};
$connectionLabel = static fn (string $status): string => match ($status) {
    'passed' => '连接通过',
    'failed' => '连接失败',
    default => '待连接测试',
};
?>
<div class="page-actions">
    <div>
        <p class="eyebrow">AI 创作</p>
        <h2>模型与价格</h2>
        <p>首页只显示已经投入业务的模型；候选、待测和失败模型统一进入模型池。</p>
        <small>最近成功同步：<?= escape_html((string) ($lastSuccessfulSync['completed_at'] ?? '尚未同步')) ?></small>
    </div>
    <form method="post" action="/admin/ai-models/sync" data-confirm-message="确认读取最新模型目录吗？同步不会自动启用、分配或开放模型。">
        <input type="hidden" name="_token" value="<?= escape_html((string) $csrfToken) ?>">
        <button class="button primary" type="submit"><i data-lucide="refresh-cw"></i><span>同步模型目录</span></button>
    </form>
</div>
<nav class="admin-section-tabs">
    <a class="<?= $tab === 'models' ? 'active' : '' ?>" href="/admin/ai-settings?tab=models">正在使用</a>
    <a class="<?= $tab === 'pool' ? 'active' : '' ?>" href="/admin/ai-settings?tab=pool">模型池</a>
    <a class="<?= $tab === 'functions' ? 'active' : '' ?>" href="/admin/ai-settings?tab=functions">功能分配与价格</a>
</nav>

<?php if ($tab === 'models'): ?>
<section class="panel">
    <div class="panel-header"><div><h3>正在使用</h3><p>只显示已经分配给业务的模型；健康异常时在对应模型行直接提醒。</p></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>使用功能</th><th>当前模型</th><th>默认 / 用户可选</th><th>商户价格</th><th>提醒</th><th>操作</th></tr></thead><tbody>
    <?php foreach ((array) $catalogData['models'] as $model): ?>
        <tr>
            <td><div class="badge-row"><?php foreach ((array) $model['assigned_uses'] as $use): ?><span class="badge completed"><?= escape_html((string) $use) ?></span><?php endforeach; ?></div></td>
            <td><strong><?= escape_html((string) $model['display_name']) ?></strong><small><?= escape_html((string) ($model['provider'] ?: '提供方未标注')) ?></small></td>
            <td><?= !empty($model['user_selectable']) ? '用户可选' : '平台内部' ?></td>
            <td><a href="/admin/ai-settings?tab=functions#model-prices">查看已绑定价格</a></td>
            <td><?php if (empty($model['source_visible']) || (string) ($model['health_status'] ?? '') === 'unavailable' || !empty($model['last_error'])): ?><span class="badge disabled">需要处理</span><?php else: ?><span class="badge completed">正常</span><?php endif; ?></td>
            <td><a class="button small" href="/admin/ai-models/<?= (int) $model['id'] ?>">管理</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($catalogData['models'])): ?><tr><td colspan="6"><div class="empty-state"><strong>当前没有已分配模型</strong><span>已同步模型可在“模型池”完成测试后再分配。</span></div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<?php elseif ($tab === 'pool'): ?>
<section class="panel">
    <div class="panel-header"><div><h3>模型池</h3><p>目录元数据只产生测试建议；真实能力和复杂业务兼容测试通过后才允许分配。</p></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>模型与提供方</th><th>目录可见</th><th>候选测试类型</th><th>验证状态</th><th>当前分配</th><th>最近失败原因</th><th>操作</th></tr></thead><tbody>
    <?php foreach ((array) $catalogData['pool'] as $model): ?>
        <tr>
            <td><strong><?= escape_html((string) $model['display_name']) ?></strong><small><?= escape_html((string) ($model['provider'] ?: '未标注')) ?></small></td>
            <td><span class="badge <?= !empty($model['source_visible']) ? 'completed' : 'disabled' ?>"><?= !empty($model['source_visible']) ? '当前可见' : '已离开目录' ?></span></td>
            <td><div class="badge-row"><?php foreach ((array) $model['capability_relations'] as $relation): ?><span class="badge untracked"><?= escape_html(\app\service\AiCapability::label((string) $relation['capability_code'])) ?></span><?php endforeach; ?><?php if (empty($model['capability_relations'])): ?><span class="muted">等待系统识别</span><?php endif; ?></div></td>
            <td><span class="badge <?= (string) $model['pool_status'] === 'failed' ? 'disabled' : ((string) $model['pool_status'] === 'assigned' || (string) $model['pool_status'] === 'passed' ? 'completed' : 'pending') ?>"><?= escape_html(['pending'=>'待验证','testing'=>'验证中','passed'=>'已通过，待分配','assigned'=>'已分配','failed'=>'未通过'][(string) $model['pool_status']] ?? '待验证') ?></span></td>
            <td><?= empty($model['assigned_uses']) ? '<span class="muted">尚未分配</span>' : escape_html(implode('、', (array) $model['assigned_uses'])) ?></td>
            <td><?= trim((string) ($model['last_error'] ?? '')) !== '' ? escape_html((string) $model['last_error']) : '<span class="muted">无</span>' ?></td>
            <td><a class="button small" href="/admin/ai-models/<?= (int) $model['id'] ?>">查看与验证</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($catalogData['pool'])): ?><tr><td colspan="7"><div class="empty-state"><strong>模型池为空</strong><span>同步模型目录后，候选模型会出现在这里。</span></div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<?php else: ?>
<?php
$definitions = (array) ($assignmentData['definitions'] ?? []);
$selectedCapability = isset($definitions[$selectedCapability]) ? $selectedCapability : (string) (array_key_first($definitions) ?? '');
$selectedDefinition = (array) ($definitions[$selectedCapability] ?? []);
$selectedBillable = \app\service\AiCapability::isMerchantBillable($selectedCapability);
?>
<div class="ai-function-workspace">
    <aside class="panel ai-function-master">
        <div class="panel-header"><div><h3>业务能力</h3><p>一次只管理一个能力，减少误操作。</p></div></div>
        <?php foreach (['平台内部' => false, '用户生成' => true] as $groupLabel => $groupBillable): ?>
        <section>
            <strong><?= escape_html($groupLabel) ?></strong>
            <nav aria-label="<?= escape_html($groupLabel) ?>能力">
                <?php foreach ($definitions as $capability => $definition): if (\app\service\AiCapability::isMerchantBillable($capability) !== $groupBillable) continue; ?>
                <a class="<?= $selectedCapability === $capability ? 'active' : '' ?>" href="/admin/ai-settings?tab=functions&amp;capability=<?= rawurlencode($capability) ?>">
                    <span><?= escape_html((string) $definition['label']) ?></span><i data-lucide="chevron-right"></i>
                </a>
                <?php endforeach; ?>
            </nav>
        </section>
        <?php endforeach; ?>
    </aside>

    <div class="ai-function-detail">
        <form method="post" action="/admin/ai-assignments">
            <input type="hidden" name="_token" value="<?= escape_html((string) $csrfToken) ?>">
            <input type="hidden" name="return_capability" value="<?= escape_html($selectedCapability) ?>">
            <?php foreach ($definitions as $capability => $definition): $route = (array) ($assignmentData['routes'][$capability] ?? []); $models = (array) ($assignmentData['options'][$capability] ?? []); $billable = \app\service\AiCapability::isMerchantBillable($capability); ?>
            <article class="panel ai-assignment-card" id="capability-<?= escape_html($capability) ?>" <?= $selectedCapability === $capability ? '' : 'hidden' ?>>
                <div class="panel-header"><div><p class="eyebrow"><?= $billable ? '用户生成能力' : '平台内部能力' ?></p><h3><?= escape_html((string) $definition['label']) ?></h3><p><?= escape_html((string) $definition['description']) ?></p></div><span class="badge <?= $billable ? 'active' : 'completed' ?>"><?= $billable ? '用户可用' : '平台内部' ?></span></div>
                <?php if ($billable): ?>
                    <div class="form-field full"><span>开放模型</span><div class="ai-model-check-list">
                    <?php foreach ($models as $model): $ready = $relationReady($model, $capability); $checked = in_array((int) $model['id'], array_map('intval', (array) ($route['pool_model_ids'] ?? [])), true); ?>
                        <label class="<?= $ready ? '' : 'is-disabled' ?>"><input type="checkbox" name="routes[<?= escape_html($capability) ?>][pool][]" value="<?= (int) $model['id'] ?>" <?= $checked ? 'checked' : '' ?> <?= $ready ? '' : 'disabled' ?>><span><strong><?= escape_html((string) $model['display_name']) ?></strong><small><?= $ready ? '当前可见且真实输出通过' : '尚未达到开放条件' ?></small></span></label>
                    <?php endforeach; ?>
                    <?php if (!$models): ?><div class="empty-state compact">当前没有可分配模型</div><?php endif; ?>
                    </div></div>
                    <label class="form-field full"><span>默认模型</span><select class="input" name="routes[<?= escape_html($capability) ?>][default]"><option value="0">暂不开放</option><?php foreach ($models as $model): if (!$relationReady($model, $capability)) continue; ?><option value="<?= (int) $model['id'] ?>" <?= (int) ($route['default_model_id'] ?? 0) === (int) $model['id'] ? 'selected' : '' ?>><?= escape_html((string) $model['display_name']) ?></option><?php endforeach; ?></select><small>用户选择哪个模型就只调用该模型；失败后由用户手动重试。</small></label>
                <?php else: ?>
                    <label class="form-field full"><span>主要模型</span><select class="input" name="routes[<?= escape_html($capability) ?>][primary]"><option value="0">未配置</option><?php foreach ($models as $model): if (!$relationReady($model, $capability)) continue; ?><option value="<?= (int) $model['id'] ?>" <?= (int) ($route['primary_model_id'] ?? 0) === (int) $model['id'] ? 'selected' : '' ?>><?= escape_html((string) $model['display_name']) ?></option><?php endforeach; ?></select><small>失败后由原操作明确重试，不自动切换备用模型。</small></label>
                <?php endif; ?>
                <div class="panel-actions"><button class="button primary" type="submit"><i data-lucide="save"></i><span>保存<?= escape_html((string) $definition['label']) ?></span></button></div>
            </article>
            <?php endforeach; ?>
        </form>

        <?php if ($selectedBillable): ?>
        <section class="panel" id="model-prices">
            <div class="panel-header"><div><h3><?= escape_html((string) ($selectedDefinition['label'] ?? '当前能力')) ?>价格</h3><p>只显示已经分配到当前收费业务的模型；售价由 ThinkPHP 管理。</p></div></div>
            <form method="post" action="/admin/ai-assignments/prices">
                <input type="hidden" name="_token" value="<?= escape_html((string) $csrfToken) ?>">
                <input type="hidden" name="return_capability" value="<?= escape_html($selectedCapability) ?>">
                <div class="table-wrap"><table class="data-table"><thead><tr><th>模型</th><th>计价方式</th><th>点数</th></tr></thead><tbody>
                <?php $visiblePriceRows = 0; foreach ($assignmentData['models'] as $model): foreach ((array) $model['capabilities'] as $capability): if ($capability !== $selectedCapability) continue; $visiblePriceRows++; $price = (array) ($model['prices'][$capability] ?? []); $config = json_decode((string) ($price['config_json'] ?? '{}'), true); $strategy = (string) ($config['strategy'] ?? ($price ? 'custom' : 'inherit')); $unit = in_array($capability, [\app\service\AiCapability::VIDEO_IMAGE_TO_VIDEO, \app\service\AiCapability::VIDEO_TEXT_TO_VIDEO], true) ? 'second' : 'image'; ?>
                    <tr><td><strong><?= escape_html((string) $model['display_name']) ?></strong></td><td><select class="input compact" name="prices[<?= (int) $model['id'] ?>][<?= escape_html($capability) ?>][strategy]"><option value="inherit" <?= $strategy === 'inherit' ? 'selected' : '' ?>>使用统一价格</option><option value="custom" <?= $strategy === 'custom' ? 'selected' : '' ?>>单独定价</option><option value="platform" <?= $strategy === 'platform' ? 'selected' : '' ?>>平台承担</option></select><input type="hidden" name="prices[<?= (int) $model['id'] ?>][<?= escape_html($capability) ?>][unit_type]" value="<?= $unit ?>"><input type="hidden" name="prices[<?= (int) $model['id'] ?>][<?= escape_html($capability) ?>][unit_value]" value="1"></td><td><input class="input compact" type="number" min="0" max="1000000" name="prices[<?= (int) $model['id'] ?>][<?= escape_html($capability) ?>][credits]" value="<?= (int) ($price['credits'] ?? 0) ?>"></td></tr>
                <?php endforeach; endforeach; ?>
                <?php if ($visiblePriceRows === 0): ?><tr><td colspan="3"><div class="empty-state compact">当前能力尚未分配收费模型，因此没有价格项。</div></td></tr><?php endif; ?>
                </tbody></table></div>
                <?php if ($visiblePriceRows > 0): ?><div class="panel-actions"><button class="button primary" type="submit">保存当前能力价格</button></div><?php endif; ?>
            </form>
        </section>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
