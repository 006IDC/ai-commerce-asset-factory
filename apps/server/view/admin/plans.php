<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<div class="page-actions">
    <div><h2>会员套餐</h2><p>统一管理功能权益、生成计费和店群经营规模。</p></div>
    <a class="button primary" href="/admin/plans/new"><i data-lucide="plus"></i><span>新建套餐</span></a>
</div>
<section class="stats-grid">
    <article class="stat blue"><small>套餐数量</small><strong><?= count($plans) ?></strong><span>包含已下架套餐</span></article>
    <article class="stat green"><small>在售套餐</small><strong><?= count(array_filter($plans, static fn (array $plan): bool => $plan['status'] === 'active')) ?></strong><span>商户端当前可购买</span></article>
    <article class="stat"><small>生效订阅</small><strong><?= array_sum(array_column($plans, 'active_subscription_count')) ?></strong><span>当前未到期</span></article>
</section>
<section class="panel">
    <div class="panel-header"><div><h3>套餐列表</h3><p>修改套餐不会改变商户已购买的权益快照</p></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>套餐定位</th><th>周期 / 价格</th><th>核心权益</th><th>经营限额</th><th>订阅</th><th>状态</th><th>操作</th></tr></thead><tbody>
    <?php foreach ($plans as $plan): ?><tr>
        <td><strong><?= escape_html($plan['name']) ?></strong><small><?= escape_html($plan['audience_label'] ?: '未设置适用人群') ?></small><small class="code-value">L<?= (int) ($plan['tier_level'] ?? 1) ?> · <?= escape_html($plan['code']) ?></small></td>
        <td><?= (int) $plan['duration_days'] ?> 天<small><?= number_format((int) $plan['price_credits']) ?> 点数</small></td>
        <td><small><?= (int) $plan['generation_unlimited'] === 1 ? '生图免点数' : '生图按次扣费' ?> · <?= (int) $plan['recognition_unlimited'] === 1 ? '识别免点数' : '识别按次扣费' ?></small></td>
        <td><small>日生成 <?= (int) $plan['daily_generation_limit'] > 0 ? number_format((int) $plan['daily_generation_limit']) . ' 张' : '跟随系统' ?></small><small>SKU <?= (int) ($plan['sku_limit'] ?? 0) > 0 ? number_format((int) $plan['sku_limit']) : '不额外限制' ?> · 单次 <?= (int) ($plan['batch_image_limit'] ?? 0) > 0 ? (int) $plan['batch_image_limit'] . ' 张' : '跟随系统' ?></small></td>
        <td><?= (int) $plan['active_subscription_count'] ?> 生效<small>累计 <?= (int) $plan['subscription_count'] ?></small></td>
        <td><span class="badge <?= escape_html($plan['status']) ?>"><?= $plan['status'] === 'active' ? '在售' : '已下架' ?></span></td>
        <td><div class="button-row"><a class="button small" href="/admin/plans/<?= (int) $plan['id'] ?>/edit">编辑</a><form method="post" action="/admin/plans/<?= (int) $plan['id'] ?>/status"><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><button class="button small <?= $plan['status'] === 'active' ? 'danger' : 'primary' ?>" type="submit"><?= $plan['status'] === 'active' ? '下架' : '上架' ?></button></form><?php if ((int) $plan['subscription_count'] === 0): ?><form method="post" action="/admin/plans/<?= (int) $plan['id'] ?>/delete" data-confirm-message="确定永久删除该套餐吗？此操作不可恢复。"><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><button class="button small danger" type="submit">删除</button></form><?php else: ?><button class="button small" type="button" disabled title="已有订阅记录的套餐只能下架，不能删除">已使用</button><?php endif; ?></div></td>
    </tr><?php endforeach; ?>
    <?php if (!$plans): ?><tr><td colspan="7"><div class="empty-state"><strong>还没有套餐</strong><span>先创建一个日套餐或月套餐。</span></div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
