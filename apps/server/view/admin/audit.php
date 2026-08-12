<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<div class="page-actions"><div><h2>审计日志</h2><p>登录、商户状态、额度和任务操作。</p></div></div>
<section class="panel"><div class="panel-header"><div><h3>操作记录</h3><p>最近 <?= count($logs) ?> 条</p></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>操作者</th><th>动作</th><th>对象</th><th>IP</th><th>详情</th><th>时间</th></tr></thead><tbody>
<?php foreach ($logs as $log): ?><tr><td><?= escape_html($log['actor_type']) ?> #<?= (int) $log['actor_id'] ?></td><td class="code-value"><?= escape_html($log['action']) ?></td><td><?= escape_html($log['target_type']) ?> <?= escape_html($log['target_id'] ?: '') ?></td><td class="code-value"><?= escape_html($log['ip_address']) ?></td><td class="code-value"><?= escape_html(mb_strimwidth((string) $log['details'], 0, 90, '...')) ?></td><td><?= escape_html($log['created_at']) ?></td></tr><?php endforeach; ?>
<?php if (!$logs): ?><tr><td colspan="6"><div class="empty-state">暂无审计记录</div></td></tr><?php endif; ?>
</tbody></table></div></section>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
