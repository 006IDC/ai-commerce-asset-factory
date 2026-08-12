<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<?php if ($promptGroup === 'inspiration'): ?>
<div class="page-actions">
    <div><p class="eyebrow">自由创作辅助</p><h2>灵感模板</h2><p>仅用于帮助用户填写自由创作想法。</p></div>
    <div class="button-row"><a class="button" href="/admin/prompts?product=free_create">返回自由创作模板</a><a class="button primary" href="/admin/prompts/new">新增灵感模板</a></div>
</div>
<section class="panel">
    <div class="table-wrap"><table class="data-table"><thead><tr><th>模板</th><th>分类</th><th>状态</th><th>来源</th><th>操作</th></tr></thead><tbody>
    <?php foreach ($inspirationTemplates as $template): ?><tr><td><strong><?= escape_html((string) $template['name']) ?></strong></td><td><?= escape_html((string) ($inspirationCategoryLabels[(string) $template['asset_type']] ?? $template['asset_type'])) ?></td><td><span class="badge <?= (string) $template['status'] === 'active' ? 'completed' : 'disabled' ?>"><?= (string) $template['status'] === 'active' ? '已上架' : '未上架' ?></span></td><td><?= !empty($template['is_system']) ? '内置' : '自定义' ?></td><td><a class="button small" href="/admin/prompts/<?= (int) $template['id'] ?>/edit">编辑</a></td></tr><?php endforeach; ?>
    <?php if (!$inspirationTemplates): ?><tr><td colspan="5"><div class="empty-state">暂无灵感模板</div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php else: ?>
<div class="page-actions">
    <div><p class="eyebrow">AI 创作</p><h2>提示词管理</h2><p>按业务和任务类型维护生成请求直接使用的完整提示词模板。</p></div>
</div>
<section class="panel">
    <div class="panel-header"><div><h3>业务提示词模板</h3><p>模板保存后直接用于后续新任务。</p></div></div>
    <div class="table-wrap"><table class="data-table generation-config-table">
        <thead><tr><th>业务</th><th>提示词模板</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ((array) ($data['businesses'] ?? []) as $business): ?>
        <tr>
            <td><strong><?= escape_html((string) $business['name']) ?></strong></td>
            <td><?= (int) $business['prompt_count'] ?> 个</td>
            <td><span class="badge <?= (string) $business['prompt_status'] === '使用中' ? 'completed' : 'pending' ?>"><?= escape_html((string) $business['prompt_status']) ?></span></td>
            <td><a class="button small primary" href="/admin/prompts?product=<?= rawurlencode((string) $business['code']) ?>">管理提示词</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
