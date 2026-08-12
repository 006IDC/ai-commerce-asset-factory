<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<?php
$statusLabel = static fn (array $model): string => match (true) {
    !empty($model['archived_at']) => '已归档',
    empty($model['source_visible']) => '来源已消失',
    (string) $model['lifecycle_status'] === 'discovered' => '新发现',
    (string) $model['lifecycle_status'] === 'available' => '可用',
    (int) $model['enabled'] === 1 => '已配置',
    default => '已停用',
};
?>
<div class="page-actions">
    <div>
        <p class="eyebrow">通用 AI 能力中心</p>
        <h2>模型目录</h2>
        <p>从 Sub2API 只同步稳定模型别名。同步不会读取账号、密钥、渠道、成本，也不会自动确认能力或开放给商户。</p>
    </div>
    <form method="post" action="/admin/ai-models/sync" data-confirm-message="确认从 Sub2API 读取最新模型目录吗？新模型只会进入“新发现”，不会自动启用或上架。">
        <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
        <button class="button primary" type="submit"><i data-lucide="refresh-cw"></i><span>同步 Sub2API 模型</span></button>
    </form>
</div>

<section class="stats-grid">
    <article class="stat"><small>目录总数</small><strong><?= (int) $data['stats']['total'] ?></strong><span>含历史与归档记录</span></article>
    <article class="stat green"><small>上游可见</small><strong><?= (int) $data['stats']['visible'] ?></strong><span>当前目录仍存在</span></article>
    <article class="stat amber"><small>新发现</small><strong><?= (int) $data['stats']['new'] ?></strong><span>等待确认和测试</span></article>
    <article class="stat blue"><small>可用模型</small><strong><?= (int) $data['stats']['available'] ?></strong><span>至少一项能力已验证</span></article>
</section>

<section class="panel">
    <div class="panel-header"><div><h3>业务模型</h3><p>普通管理员只需关注名称、能力、测试和是否可用于新任务；技术别名默认收起。</p></div></div>
    <div class="table-wrap">
        <table class="data-table ai-model-table">
            <thead><tr><th>模型</th><th>能力</th><th>来源</th><th>测试状态</th><th>新任务</th><th>更新时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($data['models'] as $model): ?>
                <?php
                $relations = (array) $model['capability_relations'];
                $passed = count(array_filter($relations, static fn (array $relation): bool => (string) $relation['test_status'] === 'passed'));
                $confirmed = count(array_filter($relations, static fn (array $relation): bool => (string) $relation['confirmation_status'] === 'confirmed'));
                ?>
                <tr>
                    <td><strong><?= escape_html((string) $model['display_name']) ?></strong><small><?= escape_html((string) ($model['provider'] ?: '提供方待确认')) ?></small><details class="inline-technical"><summary>技术信息</summary><code><?= escape_html((string) $model['model_id']) ?></code></details></td>
                    <td><div class="badge-row"><?php foreach ($relations as $relation): ?><span class="badge <?= (string) $relation['confirmation_status'] === 'confirmed' ? 'active' : ((string) $relation['confirmation_status'] === 'rejected' ? 'disabled' : 'untracked') ?>"><?= escape_html(\app\service\AiCapability::label((string) $relation['capability_code'])) ?></span><?php endforeach; ?><?php if (!$relations): ?><span class="muted">待确认</span><?php endif; ?></div></td>
                    <td><span class="badge <?= !empty($model['source_visible']) ? 'completed' : 'disabled' ?>"><?= !empty($model['source_visible']) ? 'Sub2API 可见' : '来源已消失' ?></span></td>
                    <td><strong><?= $passed ?> / <?= $confirmed ?></strong><small>已通过 / 已确认</small></td>
                    <td><span class="badge <?= (int) $model['enabled'] === 1 && !empty($model['source_visible']) ? 'active' : 'disabled' ?>"><?= escape_html($statusLabel($model)) ?></span></td>
                    <td><?= escape_html((string) $model['updated_at']) ?></td>
                    <td><a class="button small primary" href="/admin/ai-models/<?= (int) $model['id'] ?>">查看与配置</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$data['models']): ?><tr><td colspan="7"><div class="empty-state"><strong>尚未同步模型</strong><span>点击“同步 Sub2API 模型”读取稳定别名。</span></div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<details class="panel prompt-advanced-rules">
    <summary><span><strong>最近同步记录</strong><small>只记录数量、别名变化和执行人，不保存任何上游密钥</small></span><i data-lucide="chevron-down"></i></summary>
    <div class="prompt-advanced-rules-body table-wrap">
        <table class="data-table"><thead><tr><th>时间</th><th>状态</th><th>发现</th><th>新增</th><th>来源消失</th></tr></thead><tbody>
        <?php foreach ($batches as $batch): ?><tr><td><?= escape_html((string) $batch['started_at']) ?></td><td><span class="badge <?= (string) $batch['status'] === 'completed' ? 'completed' : ((string) $batch['status'] === 'failed' ? 'disabled' : 'pending') ?>"><?= (string) $batch['status'] === 'completed' ? '完成' : ((string) $batch['status'] === 'failed' ? '失败' : '执行中') ?></span></td><td><?= (int) $batch['discovered_count'] ?></td><td><?= (int) $batch['new_count'] ?></td><td><?= (int) $batch['missing_count'] ?></td></tr><?php endforeach; ?>
        </tbody></table>
    </div>
</details>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
