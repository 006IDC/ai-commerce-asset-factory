<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<?php
$relationReady = static function (array $model, string $capability): bool {
    if ((int) ($model['enabled'] ?? 0) !== 1
        || (int) ($model['source_visible'] ?? 1) !== 1
        || !empty($model['archived_at'])) {
        return false;
    }
    foreach ((array) ($model['capability_relations'] ?? []) as $relation) {
        if ((string) ($relation['capability_code'] ?? '') === $capability
            && (string) ($relation['confirmation_status'] ?? '') === 'confirmed'
            && (string) ($relation['test_status'] ?? '') === 'passed') {
            return true;
        }
    }
    return false;
};
?>
<div class="page-actions">
    <div>
        <p class="eyebrow">通用 AI 能力中心</p>
        <h2>功能分配</h2>
        <p>内部能力使用主模型与可选备用模型；商户付费生成使用开放池与一个默认模型。图片和视频失败后由用户重试，不会换成另一个模型。</p>
    </div>
    <div class="button-row"><a class="button" href="/admin/ai-capabilities">能力总览</a><a class="button" href="/admin/ai-compatibility">业务兼容矩阵</a></div>
</div>

<form method="post" action="/admin/ai-assignments">
    <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
    <section class="ai-assignment-grid">
    <?php foreach ($data['definitions'] as $capability => $definition): $route = (array) ($data['routes'][$capability] ?? []); $models = (array) ($data['options'][$capability] ?? []); $billable = \app\service\AiCapability::isMerchantBillable($capability); ?>
        <article class="panel ai-assignment-card" id="capability-<?= escape_html($capability) ?>">
            <div class="panel-header"><div><h3><?= escape_html((string) $definition['label']) ?></h3><p><?= escape_html((string) $definition['description']) ?></p></div><span class="badge <?= $billable ? 'active' : 'completed' ?>"><?= $billable ? '商户付费生成' : '平台内部能力' ?></span></div>
            <?php if ($billable): ?>
                <div class="form-field full"><span>开放模型池</span><div class="ai-model-check-list">
                <?php foreach ($models as $model): $ready = $relationReady($model, $capability); $checked = in_array((int) $model['id'], array_map('intval', (array) ($route['pool_model_ids'] ?? [])), true); ?>
                    <label class="<?= $ready ? '' : 'is-disabled' ?>"><input type="checkbox" name="routes[<?= escape_html($capability) ?>][pool][]" value="<?= (int) $model['id'] ?>" <?= $checked ? 'checked' : '' ?> <?= $ready ? '' : 'disabled' ?>><span><strong><?= escape_html((string) $model['display_name']) ?></strong><small><?= $ready ? '能力已确认并通过真实测试' : ((int) ($model['source_visible'] ?? 1) !== 1 ? 'Sub2API 来源已消失' : '尚未完成能力确认和真实测试') ?></small></span></label>
                <?php endforeach; ?>
                <?php if (!$models): ?><div class="empty-state compact"><strong>没有已标注模型</strong><span>请先同步模型并确认能力。</span></div><?php endif; ?>
                </div></div>
                <label class="form-field full"><span>默认模型</span><select class="input" name="routes[<?= escape_html($capability) ?>][default]"><option value="0">暂不开放</option><?php foreach ($models as $model): if (!$relationReady($model, $capability)) continue; ?><option value="<?= (int) $model['id'] ?>" <?= (int) ($route['default_model_id'] ?? 0) === (int) $model['id'] ? 'selected' : '' ?>><?= escape_html((string) $model['display_name']) ?></option><?php endforeach; ?></select><small>默认模型必须在开放池中；它只用于用户未主动选择时，不是失败备用。</small></label>
            <?php else: ?>
                <label class="form-field full"><span>主模型</span><select class="input" name="routes[<?= escape_html($capability) ?>][primary]"><option value="0">未配置</option><?php foreach ($models as $model): if (!$relationReady($model, $capability)) continue; ?><option value="<?= (int) $model['id'] ?>" <?= (int) ($route['primary_model_id'] ?? 0) === (int) $model['id'] ? 'selected' : '' ?>><?= escape_html((string) $model['display_name']) ?></option><?php endforeach; ?></select></label>
                <label class="form-field full"><span>备用模型（可选）</span><select class="input" name="routes[<?= escape_html($capability) ?>][fallback]"><option value="0">不使用备用</option><?php foreach ($models as $model): if (!$relationReady($model, $capability)) continue; ?><option value="<?= (int) $model['id'] ?>" <?= (int) ($route['fallback_model_id'] ?? 0) === (int) $model['id'] ? 'selected' : '' ?>><?= escape_html((string) $model['display_name']) ?></option><?php endforeach; ?></select><small>只用于平台内部能力；商户指定的图片或视频模型绝不走此备用。</small></label>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    </section>
    <div class="sticky-save-bar"><div><strong>保存功能分配</strong><span>不会修改 Sub2API 账号、Key 或物理渠道。</span></div><button class="button primary" type="submit">保存全部分配</button></div>
</form>

<section class="panel">
    <div class="panel-header"><div><h3>商户侧模型价格</h3><p>只有图片与视频生成向商户计费；内部识别、审核和文案能力始终由平台承担。</p></div></div>
    <form method="post" action="/admin/ai-assignments/prices">
        <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
        <div class="table-wrap"><table class="data-table"><thead><tr><th>模型</th><th>能力</th><th>策略</th><th>单位</th><th>点数</th></tr></thead><tbody>
        <?php foreach ($data['models'] as $model): foreach ((array) $model['capabilities'] as $capability): if (!\app\service\AiCapability::isMerchantBillable($capability)) continue; $price = (array) ($model['prices'][$capability] ?? []); $config = json_decode((string) ($price['config_json'] ?? '{}'), true); $strategy = (string) ($config['strategy'] ?? ($price ? 'custom' : 'inherit')); $defaultUnit = in_array($capability, [\app\service\AiCapability::VIDEO_IMAGE_TO_VIDEO, \app\service\AiCapability::VIDEO_TEXT_TO_VIDEO], true) ? 'second' : 'image'; ?>
            <tr><td><strong><?= escape_html((string) $model['display_name']) ?></strong></td><td><?= escape_html(\app\service\AiCapability::label($capability)) ?></td><td><select class="input compact" name="prices[<?= (int) $model['id'] ?>][<?= escape_html($capability) ?>][strategy]"><option value="inherit" <?= $strategy === 'inherit' ? 'selected' : '' ?>>继承全局价</option><option value="custom" <?= $strategy === 'custom' ? 'selected' : '' ?>>模型专属价</option><option value="platform" <?= $strategy === 'platform' ? 'selected' : '' ?>>平台承担</option></select></td><td><select class="input compact" name="prices[<?= (int) $model['id'] ?>][<?= escape_html($capability) ?>][unit_type]"><option value="<?= $defaultUnit ?>"><?= $defaultUnit === 'second' ? '每秒' : '每张' ?></option></select><input type="hidden" name="prices[<?= (int) $model['id'] ?>][<?= escape_html($capability) ?>][unit_value]" value="1"></td><td><input class="input compact" type="number" min="0" max="1000000" name="prices[<?= (int) $model['id'] ?>][<?= escape_html($capability) ?>][credits]" value="<?= (int) ($price['credits'] ?? 0) ?>"></td></tr>
        <?php endforeach; endforeach; ?>
        </tbody></table></div>
        <div class="button-row panel-actions"><button class="button primary" type="submit">保存模型价格</button></div>
    </form>
</section>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
