<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<?php
$statusLabel = static fn (string $status): string => match ($status) {
    'passed' => '已通过',
    'failed' => '未通过',
    'blocked' => '暂不可用',
    default => '待测试',
};
?>
<div class="page-actions">
    <div>
        <p class="eyebrow">通用 AI 能力中心</p>
        <h2>业务兼容矩阵</h2>
        <p>“模型支持图片”不等于兼容所有图片业务。每个模型必须分别通过能力 × 生成业务 × 直接调用/ComfyUI 的真实结果测试。</p>
    </div>
    <div class="button-row"><a class="button" href="/admin/ai-models">模型目录</a><a class="button primary" href="/admin/ai-assignments">功能分配</a></div>
</div>

<section class="panel ai-compatibility-legend">
    <div><span class="badge completed">已通过</span><p>可进入对应生成业务的新任务候选。</p></div>
    <div><span class="badge pending">待测试</span><p>不会向商户展示，也不会自动开放。</p></div>
    <div><span class="badge disabled">未通过 / 来源消失</span><p>保留历史证据，修复后重新测试。</p></div>
</section>

<?php foreach ($data['models'] as $model): ?>
<section class="panel ai-compatibility-model">
    <div class="panel-header">
        <div><h3><?= escape_html((string) $model['display_name']) ?></h3><p><?= (int) ($model['source_visible'] ?? 1) === 1 ? 'Sub2API 目录可见' : 'Sub2API 来源已消失，只保留历史记录' ?></p></div>
        <a class="button small" href="/admin/ai-models/<?= (int) $model['id'] ?>">模型详情</a>
    </div>
    <?php if ($model['matrix']): ?><div class="table-wrap"><table class="data-table"><thead><tr><th>生成业务</th><th>能力</th><th>执行方式</th><th>Workflow Release</th><th>状态</th><th>说明</th><th>真实测试</th></tr></thead><tbody>
    <?php foreach ($model['matrix'] as $cell): ?><tr>
        <td><strong><?= escape_html((string) $cell['product_label']) ?></strong></td>
        <td><?= escape_html((string) $cell['capability_label']) ?></td>
        <td><span class="badge <?= (string) $cell['channel'] === 'comfyui' ? 'active' : 'completed' ?>"><?= (string) $cell['channel'] === 'comfyui' ? 'ComfyUI 工作流' : '直接调用' ?></span></td>
        <td><?= (string) $cell['channel'] === 'comfyui' ? ((int) ($cell['workflow_version_id'] ?? 0) > 0 ? '#' . (int) $cell['workflow_version_id'] : '待绑定') : '不适用' ?></td>
        <td><span class="badge <?= (string) $cell['status'] === 'passed' ? 'completed' : ((string) $cell['status'] === 'pending' ? 'pending' : 'disabled') ?>"><?= escape_html($statusLabel((string) $cell['status'])) ?></span></td>
        <td><?= escape_html((string) ($cell['notes'] ?: ((string) $cell['status'] === 'pending' ? '尚未执行该组合的真实结果测试。' : '—'))) ?></td>
        <td>
            <?php if ((int) ($model['source_visible'] ?? 1) === 1): ?>
            <form class="paid-test-form inline" method="post" action="/admin/ai-compatibility/<?= (int) $model['id'] ?>/test" data-confirm-message="确认执行此业务组合的真实测试吗？可能产生上游费用，测试通过后仍需在“功能分配”中主动加入开放池。">
                <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
                <input type="hidden" name="product_code" value="<?= escape_html((string) $cell['product_code']) ?>">
                <input type="hidden" name="capability" value="<?= escape_html((string) $cell['capability']) ?>">
                <label><input type="checkbox" name="confirm_cost" value="1" required> 确认费用</label>
                <button class="button small" type="submit"><?= (string) $cell['status'] === 'passed' ? '重新测试' : '执行测试' ?></button>
            </form>
            <?php else: ?><span class="muted">来源不可用</span><?php endif; ?>
        </td>
    </tr><?php endforeach; ?>
    </tbody></table></div><?php else: ?><div class="empty-state"><strong>尚无已确认能力</strong><span>先进入模型详情确认能力并完成模型能力真实测试。</span></div><?php endif; ?>
</section>
<?php endforeach; ?>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
