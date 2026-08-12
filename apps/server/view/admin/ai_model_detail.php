<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<?php
$relationByCode = [];
foreach ((array) ($model['capability_relations'] ?? []) as $relation) {
    $relationByCode[(string) $relation['capability_code']] = $relation;
}
$testLabel = static fn (string $status): string => match ($status) {
    'passed' => '已通过',
    'failed' => '未通过',
    'pending' => '生成中',
    default => '待测试',
};
$connection = (array) ($model['connection_test'] ?? []);
$assignedUses = (array) ($model['assigned_uses'] ?? []);
$section = in_array((string) ($section ?? ''), ['overview', 'validation', 'tests'], true) ? (string) $section : 'overview';
$sourceVisible = !empty($model['source_visible']);
?>
<div class="page-actions ai-model-identity">
    <div>
        <p class="eyebrow">模型池 · <?= escape_html((string) ($model['provider'] ?: '提供方未标注')) ?></p>
        <h2><?= escape_html((string) $model['display_name']) ?></h2>
        <div class="badge-row">
            <span class="badge <?= $sourceVisible ? 'completed' : 'disabled' ?>"><?= $sourceVisible ? '当前目录可见' : '已离开目录' ?></span>
            <span class="badge <?= (int) $model['enabled'] === 1 ? 'active' : 'disabled' ?>"><?= (int) $model['enabled'] === 1 ? '允许分配' : '暂停分配' ?></span>
            <?php foreach ($assignedUses as $use): ?><span class="badge untracked"><?= escape_html((string) $use) ?></span><?php endforeach; ?>
        </div>
        <p>目录信息只用于建议测试；真实输出通过后，系统才会形成已验证能力。</p>
    </div>
    <a class="button" href="/admin/ai-settings?tab=pool"><i data-lucide="arrow-left"></i><span>返回模型池</span></a>
</div>

<nav class="admin-section-tabs ai-model-section-tabs" aria-label="模型管理分区">
    <a class="<?= $section === 'overview' ? 'active' : '' ?>" href="/admin/ai-models/<?= (int) $model['id'] ?>?section=overview">概览</a>
    <a class="<?= $section === 'validation' ? 'active' : '' ?>" href="/admin/ai-models/<?= (int) $model['id'] ?>?section=validation">能力验证</a>
    <a class="<?= $section === 'tests' ? 'active' : '' ?>" href="/admin/ai-models/<?= (int) $model['id'] ?>?section=tests">测试记录</a>
</nav>

<?php if ($section === 'overview'): ?>
<section class="ai-model-detail-grid">
    <article class="panel">
        <div class="panel-header"><div><h3>业务展示信息</h3><p>这里只维护用户看到的名称和业务分配，不改变模型服务配置。</p></div></div>
        <form method="post" action="/admin/ai-models/<?= (int) $model['id'] ?>" class="form-grid">
            <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
            <label class="form-field"><span>模型名称</span><input class="input" name="display_name" maxlength="160" value="<?= escape_html((string) $model['display_name']) ?>" required></label>
            <label class="form-field"><span>提供方</span><input class="input" name="provider" maxlength="80" value="<?= escape_html((string) ($model['provider'] ?? '')) ?>" placeholder="例如：OpenAI、智谱、火山"></label>
            <label class="form-field full"><span>商户说明</span><input class="input" name="merchant_description" maxlength="180" value="<?= escape_html((string) ($model['limits']['merchant_description'] ?? '')) ?>" placeholder="例如：适合中文电商主图和参考图编辑"></label>
            <label class="form-field"><span>最低套餐等级</span><input class="input" type="number" min="0" max="999" name="minimum_tier" value="<?= (int) ($model['limits']['minimum_tier'] ?? 0) ?>"></label>
            <label class="form-field"><span>显示顺序</span><input class="input" type="number" min="0" max="100000" name="sort_order" value="<?= (int) $model['sort_order'] ?>"></label>
            <label class="form-toggle full"><input type="checkbox" name="enabled" value="1" <?= (int) $model['enabled'] === 1 ? 'checked' : '' ?>><span>允许进入功能分配</span></label>
            <div class="button-row full"><button class="button primary" type="submit">保存业务信息</button></div>
        </form>
    </article>

    <article class="panel">
        <div class="panel-header"><div><h3>连接与当前用途</h3><p>连接测试免费，只确认当前模型能从共享网关目录读取。</p></div></div>
        <div class="model-test-summary">
            <div><span>连接状态</span><strong class="badge <?= (string) ($connection['status'] ?? '') === 'passed' ? 'completed' : ((string) ($connection['status'] ?? '') === 'failed' ? 'disabled' : 'pending') ?>"><?= (string) ($connection['status'] ?? '') === 'passed' ? '连接通过' : ((string) ($connection['status'] ?? '') === 'failed' ? '连接失败' : '待连接测试') ?></strong></div>
            <div><span>当前用途</span><div class="badge-row"><?php foreach ($assignedUses as $use): ?><span class="badge active"><?= escape_html((string) $use) ?></span><?php endforeach; ?><?php if (!$assignedUses): ?><span class="muted">尚未分配</span><?php endif; ?></div></div>
        </div>
        <form method="post" action="/admin/ai-models/<?= (int) $model['id'] ?>/protocol-test">
            <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
            <button class="button" type="submit"><i data-lucide="plug-zap"></i><span>执行免费连接测试</span></button>
        </form>
        <?php if (!empty($model['last_error'])): ?><div class="notice warning"><strong>最近提醒</strong><p><?= escape_html((string) $model['last_error']) ?></p></div><?php endif; ?>
    </article>
</section>

<?php elseif ($section === 'validation'): ?>
<section class="panel">
    <div class="panel-header"><div><h3>候选能力验证</h3><p>点击后才会显示费用确认。提交会真实调用上游，但通过后仍不会自动开放给用户。</p></div></div>
    <div class="ai-capability-confirm-grid">
    <?php foreach ($relationByCode as $code => $relation): $definition = (array) ($definitions[$code] ?? ['label' => $code, 'description' => '系统建议验证的候选能力']); $testStatus = (string) ($relation['test_status'] ?? 'pending'); ?>
        <article class="ai-capability-confirm-card">
            <header>
                <div><strong><?= escape_html((string) $definition['label']) ?></strong><small><?= escape_html((string) $definition['description']) ?></small></div>
                <span class="badge <?= $testStatus === 'passed' ? 'completed' : ($testStatus === 'failed' ? 'disabled' : 'pending') ?>"><?= $testStatus === 'passed' ? '测试已验证' : ($testStatus === 'failed' ? '测试未通过' : '候选测试建议') ?></span>
            </header>
            <p>真实输出：<strong><?= escape_html($testLabel($testStatus)) ?></strong></p>
            <button class="button small <?= $testStatus === 'passed' ? '' : 'primary' ?>" type="button"
                    data-open-paid-test
                    data-test-capability="<?= escape_html($code) ?>"
                    data-test-label="<?= escape_html((string) $definition['label']) ?>"><?= $testStatus === 'passed' ? '重新验证' : '验证此能力' ?></button>
        </article>
    <?php endforeach; ?>
    <?php if (!$relationByCode): ?><div class="empty-state"><strong>暂无候选测试建议</strong><span>请先同步最新模型目录。</span></div><?php endif; ?>
    </div>
</section>

<dialog class="admin-action-dialog paid-test-dialog" data-paid-test-dialog>
    <form method="post" action="/admin/ai-models/<?= (int) $model['id'] ?>/capability-test" data-paid-test-form>
        <header><div><p class="eyebrow">真实输出验证</p><h3 data-paid-test-title>确认能力测试</h3></div><button type="button" aria-label="关闭费用确认" data-close-paid-test><i data-lucide="x"></i></button></header>
        <div class="admin-action-dialog-body">
            <p>这次操作会调用上游模型并可能产生费用。系统只记录测试结果，不会自动改变模型分配、用户价格或开放范围。</p>
            <div class="notice warning"><strong>费用提醒</strong><p>提交后会发起真实模型请求并可能产生上游费用；请核对测试项目后再确认。</p></div>
            <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
            <input type="hidden" name="capability" value="" data-paid-test-capability>
            <label class="paid-test-cost-confirm"><input type="checkbox" name="confirm_cost" value="1" required data-paid-test-cost> 我已确认本次测试可能产生上游费用</label>
        </div>
        <footer><button class="button" type="button" data-close-paid-test>取消</button><button class="button primary" type="submit">确认并开始测试</button></footer>
    </form>
</dialog>

<?php else: ?>
<section class="panel">
    <div class="panel-header"><div><h3>测试记录</h3><p>列表只展示结果摘要；点击“查看详情”后在右侧查看脱敏输出、媒体产物和同一视频任务刷新入口。</p></div></div>
    <div class="model-test-records compact">
    <?php foreach ((array) $model['tests'] as $test): $result = (array) ($test['result'] ?? []); $output = (array) ($result['output_summary'] ?? []); $artifactUrl = (string) ($result['artifact_url'] ?? ($output['url'] ?? '')); $testTitle = (string) ($test['test_scope'] === 'protocol' ? '连接测试' : (\app\service\AiCapability::exists((string) $test['capability_code']) ? \app\service\AiCapability::label((string) $test['capability_code']) : '输出测试')); ?>
        <article class="model-test-record model-test-record-summary">
            <header>
                <div><strong><?= escape_html($testTitle) ?></strong><small><?= escape_html((string) $test['created_at']) ?> · <?= number_format((int) $test['duration_ms']) ?> ms</small></div>
                <span class="badge <?= (string) $test['status'] === 'passed' ? 'completed' : ((string) $test['status'] === 'pending' ? 'pending' : 'disabled') ?>"><?= escape_html($testLabel((string) $test['status'])) ?></span>
            </header>
            <p><?= escape_html((string) ($result['message'] ?? ((string) $test['status'] === 'passed' ? '测试通过。' : '测试未通过。'))) ?></p>
            <button class="button small" type="button" data-open-model-test="<?= (int) $test['id'] ?>">查看详情</button>
        </article>
        <template data-model-test-template="<?= (int) $test['id'] ?>">
            <article class="model-test-detail">
                <header><div><p class="eyebrow">测试 #<?= (int) $test['id'] ?></p><h3><?= escape_html($testTitle) ?></h3><small><?= escape_html((string) $test['created_at']) ?> · <?= number_format((int) $test['duration_ms']) ?> ms</small></div><span class="badge <?= (string) $test['status'] === 'passed' ? 'completed' : ((string) $test['status'] === 'pending' ? 'pending' : 'disabled') ?>"><?= escape_html($testLabel((string) $test['status'])) ?></span></header>
                <p><?= escape_html((string) ($result['message'] ?? ((string) $test['status'] === 'passed' ? '测试通过。' : '测试未通过。'))) ?></p>
                <?php if ((string) ($output['type'] ?? '') === 'text' && trim((string) ($output['text'] ?? '')) !== ''): ?><pre class="model-test-text"><?= escape_html((string) $output['text']) ?></pre><?php endif; ?>
                <?php if ($artifactUrl !== '' && str_starts_with($artifactUrl, '/storage/') && str_starts_with((string) ($output['mime'] ?? ''), 'image/')): ?><a class="model-test-media" href="<?= escape_html($artifactUrl) ?>" target="_blank" rel="noopener"><img src="<?= escape_html($artifactUrl) ?>" alt="模型真实输出测试图片"></a><?php endif; ?>
                <?php if ($artifactUrl !== '' && str_starts_with($artifactUrl, '/storage/') && str_starts_with((string) ($output['mime'] ?? ''), 'video/')): ?><video class="model-test-media" src="<?= escape_html($artifactUrl) ?>" controls preload="metadata"></video><?php endif; ?>
                <?php if ((string) ($output['type'] ?? '') === 'video_task' && !empty($output['task_id'])): ?><div class="notice"><strong>视频任务已提交</strong><p><?= escape_html((string) ($output['status'] ?? '等待上游生成')) ?>。任务标识：<?= escape_html((string) $output['task_id']) ?></p><?php if ((string) $test['status'] === 'pending'): ?><form method="post" action="/admin/ai-models/<?= (int) $model['id'] ?>/video-test-refresh"><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><input type="hidden" name="test_id" value="<?= (int) $test['id'] ?>"><button class="button small" type="submit"><i data-lucide="refresh-cw"></i><span>刷新同一测试任务</span></button></form><small>刷新只读取同一上游任务，不会重新创建付费生成。</small><?php endif; ?></div><?php endif; ?>
            </article>
        </template>
    <?php endforeach; ?>
    <?php if (empty($model['tests'])): ?><div class="empty-state"><strong>暂无测试记录</strong><span>先在概览测试连接，再到能力验证按需执行真实输出测试。</span></div><?php endif; ?>
    </div>
</section>

<dialog class="admin-side-drawer model-test-drawer" data-model-test-drawer aria-label="模型测试详情">
    <div class="admin-side-drawer-shell">
        <button class="admin-side-drawer-close" type="button" aria-label="关闭测试详情" data-close-model-test><i data-lucide="x"></i></button>
        <div data-model-test-drawer-content></div>
    </div>
</dialog>
<?php endif; ?>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
