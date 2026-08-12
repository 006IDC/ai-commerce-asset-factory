<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<div class="page-actions">
    <form class="search-bar" method="get" action="/admin/merchants">
        <input class="input" name="q" value="<?= escape_html($keyword) ?>" placeholder="商户、标识或邮箱">
        <select class="input" name="status"><option value="">全部状态</option><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>正常</option><option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>>停用</option></select>
        <button class="button" type="submit">筛选</button>
    </form>
</div>
<section class="panel"><div class="panel-header"><div><h3>商户账号</h3><p>共 <?= count($merchants) ?> 条</p></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>商户</th><th>负责人</th><th>最近登录</th><th>套餐</th><th>余额</th><th>状态</th><th>注册时间</th><th>操作</th></tr></thead><tbody>
    <?php foreach ($merchants as $merchant): ?><tr>
        <td><strong><?= escape_html($merchant['name']) ?></strong><small class="code-value"><?= escape_html($merchant['slug']) ?></small></td>
        <td><strong><?= escape_html($merchant['owner_name'] ?: '-') ?></strong><small><?= escape_html($merchant['owner_email'] ?: '-') ?></small></td>
        <td><?= escape_html($merchant['owner_last_login_at'] ?: '尚未登录') ?><small class="code-value"><?= escape_html($merchant['owner_last_login_ip'] ?: '-') ?></small></td>
        <td><?php if ($merchant['current_subscription']): ?><strong><?= escape_html($merchant['current_subscription']['plan_name']) ?></strong><small>至 <?= escape_html($merchant['current_subscription']['expires_at']) ?></small><?php else: ?>按次扣费<?php endif; ?></td><td><?= (int) $merchant['credit_balance'] ?></td>
        <td><span class="badge <?= escape_html($merchant['status']) ?>"><?= escape_html(record_status_label($merchant['status'])) ?></span></td>
        <td><?= escape_html($merchant['created_at']) ?></td><td><a class="button small" href="/admin/merchants/<?= (int) $merchant['id'] ?>">详情</a></td>
    </tr><?php endforeach; ?>
    <?php if (!$merchants): ?><tr><td colspan="8"><div class="empty-state">没有匹配的商户</div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
