<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<div class="page-actions">
    <div><h2>质量问题</h2><p>集中查看用户要求、参考图、模型和请求记录；所有退款仍需人工确认。</p></div>
</div>
<section class="stats-grid">
    <article class="stat amber"><small>待受理</small><strong><?= number_format((int) ($summary['pending'] ?? 0)) ?></strong><span>尚未开始人工复核</span></article>
    <article class="stat blue"><small>审核中</small><strong><?= number_format((int) ($summary['reviewing'] ?? 0)) ?></strong><span>正在核对证据</span></article>
    <article class="stat"><small>待补充</small><strong><?= number_format((int) ($summary['need_info'] ?? 0)) ?></strong><span>等待用户说明</span></article>
    <article class="stat green"><small>已退款</small><strong><?= number_format((int) ($summary['refunded'] ?? 0)) ?></strong><span>逐张幂等退款完成</span></article>
</section>
<?php
$qualityQuery = static function (array $changes = []) use ($status, $productCode, $issueType, $keyword): string {
    $params = array_filter(array_merge([
        'status' => $status ?: null,
        'product_code' => $productCode ?: null,
        'issue_type' => $issueType ?: null,
        'q' => $keyword ?: null,
    ], $changes), static fn (mixed $value): bool => $value !== null && $value !== '');
    return '/admin/quality-appeals' . ($params ? '?' . http_build_query($params) : '');
};
?>
<div class="tabs"><?php foreach (['' => '全部'] + \app\service\GenerationQualityAppealService::STATUSES as $value => $label): ?><a class="<?= $status === $value ? 'active' : '' ?>" href="<?= escape_html($qualityQuery(['status' => $value, 'page' => null])) ?>"><?= escape_html($label) ?></a><?php endforeach; ?></div>
<form class="filter-form" method="get" action="/admin/quality-appeals">
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= escape_html($status) ?>"><?php endif; ?>
    <label><span>搜索</span><input class="input" type="search" name="q" value="<?= escape_html($keyword) ?>" placeholder="申诉编号、商户或邮箱"></label>
    <label><span>创作产品</span><select class="input" name="product_code"><option value="">全部产品</option><?php foreach (\app\service\CreativeProductService::DEFINITIONS as $code => $definition): ?><option value="<?= escape_html($code) ?>" <?= $productCode === $code ? 'selected' : '' ?>><?= escape_html((string) $definition['name']) ?></option><?php endforeach; ?></select></label>
    <label><span>问题类型</span><select class="input" name="issue_type"><option value="">全部问题</option><?php foreach (\app\service\GenerationQualityAppealService::ISSUE_TYPES as $code => $label): ?><option value="<?= escape_html($code) ?>" <?= $issueType === $code ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select></label>
    <div class="button-row"><button class="button primary" type="submit"><i data-lucide="search"></i><span>筛选</span></button><a class="button" href="/admin/quality-appeals">清空</a></div>
</form>
<section class="panel">
    <div class="panel-header"><div><h3>质量问题队列</h3><p>共 <?= number_format($total) ?> 条</p></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>申诉编号</th><th>结果</th><th>商户</th><th>产品 / 问题</th><th>额度</th><th>状态</th><th>提交时间</th><th>操作</th></tr></thead><tbody>
    <?php foreach ($appealRows as $row): $imageUrl = (string) (($row['file_url'] ?? '') ?: ($row['backup_file_url'] ?? '')); ?><tr><td class="code-value"><?= escape_html((string) $row['appeal_no']) ?><small>子任务 #<?= (int) $row['job_item_id'] ?></small></td><td><?php if ($imageUrl !== ''): ?><a href="<?= escape_html($imageUrl) ?>" target="_blank" rel="noopener"><img src="<?= escape_html($imageUrl) ?>" alt="" style="width:58px;height:58px;object-fit:cover;border-radius:8px"></a><?php else: ?>-<?php endif; ?></td><td><a href="/admin/merchants/<?= (int) $row['merchant_id'] ?>"><?= escape_html((string) ($row['merchant_name'] ?: '-')) ?></a><small><?= escape_html((string) ($row['user_email'] ?: '-')) ?></small></td><td><?= escape_html((string) $row['product_code']) ?><small><?= escape_html(\app\service\GenerationQualityAppealService::issueLabel((string) $row['issue_type'])) ?></small></td><td><?= (int) $row['charged_credits'] ?> 点</td><td><span class="badge <?= escape_html((string) $row['status']) ?>"><?= escape_html(\app\service\GenerationQualityAppealService::STATUSES[(string) $row['status']] ?? (string) $row['status']) ?></span></td><td><?= escape_html((string) $row['submitted_at']) ?></td><td><a class="button small primary" href="/admin/quality-appeals/<?= (int) $row['id'] ?>">查看证据</a></td></tr><?php endforeach; ?>
    <?php if (!$appealRows): ?><tr><td colspan="8"><div class="empty-state"><strong>当前没有申诉</strong><span>新的质量申诉会出现在这里。</span></div></td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if ($totalPages > 1): ?><nav class="pagination" aria-label="质量申诉分页"><?php if ($page > 1): ?><a class="button small" href="<?= escape_html($qualityQuery(['page' => $page - 1])) ?>"><i data-lucide="chevron-left"></i><span>上一页</span></a><?php endif; ?><span>第 <?= $page ?> / <?= $totalPages ?> 页</span><?php if ($page < $totalPages): ?><a class="button small" href="<?= escape_html($qualityQuery(['page' => $page + 1])) ?>"><span>下一页</span><i data-lucide="chevron-right"></i></a><?php endif; ?></nav><?php endif; ?>
</section>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
