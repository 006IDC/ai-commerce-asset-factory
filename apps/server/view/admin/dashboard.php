<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<?php
$queueBacklog = (int) $operations['queuedItems'] + (int) $operations['visionBacklog'];
$workerHealthy = (int) $operations['onlineWorkers'] > 0;
$failureDanger = (float) $operations['failureRate24'] >= 10 || (int) $operations['failedJobs24'] >= 5;
?>
<div class="page-actions">
    <div><h2>运营工作台</h2><p>先处理会影响商户创作的异常，再查看业务数据。</p></div>
    <div class="button-row"><a class="button" href="/admin/prompts"><i data-lucide="braces"></i><span>提示词管理</span></a><a class="button primary" href="/admin/jobs?status=failed"><i data-lucide="triangle-alert"></i><span>处理失败任务</span></a></div>
</div>

<nav class="admin-health-strip" aria-label="服务健康状态">
    <a class="<?= $workerHealthy ? 'healthy' : 'danger' ?>" href="/admin/worker"><i></i><span>生成服务 <strong><?= $workerHealthy ? '运行中' : '需要检查' ?></strong></span></a>
    <a class="<?= $queueBacklog > 20 ? 'warning' : 'healthy' ?>" href="/admin/jobs?status=queued"><i></i><span>待处理 <strong><?= number_format($queueBacklog) ?> 项</strong></span></a>
    <a class="<?= $failureDanger ? 'danger' : 'healthy' ?>" href="/admin/jobs?status=failed&period=24h"><i></i><span>24 小时失败率 <strong><?= escape_html((string) $operations['failureRate24']) ?>%</strong></span></a>
    <a class="<?= (int) $operations['safetyEvents24'] > 0 ? 'warning' : 'healthy' ?>" href="/admin/security"><i></i><span>安全事件 <strong><?= (int) $operations['safetyEvents24'] ?></strong></span></a>
    <a class="healthy" href="/admin/system-status"><i></i><span>运行组件 <strong>查看状态</strong></span></a>
</nav>

<section class="panel panel-gap">
    <div class="panel-header"><div><h3>需要处理</h3><p>按影响范围排序，点击可直接进入对应处理页面。</p></div></div>
    <div class="panel-body admin-incident-list">
        <?php if (!$workerHealthy): ?><a class="admin-incident danger" href="/admin/worker"><i data-lucide="server-crash"></i><span><strong>生成服务需要检查</strong><small>排队任务暂时不会继续，请进入运行组件查看详情。</small></span><i data-lucide="chevron-right"></i></a><?php endif; ?>
        <?php if ($queueBacklog > 0): ?><a class="admin-incident <?= $queueBacklog > 20 ? 'danger' : '' ?>" href="/admin/jobs?status=queued"><i data-lucide="list-restart"></i><span><strong><?= number_format($queueBacklog) ?> 项任务等待处理</strong><small>其中图片队列 <?= (int) $operations['queuedItems'] ?> 项，商品识别队列 <?= (int) $operations['visionBacklog'] ?> 项。</small></span><i data-lucide="chevron-right"></i></a><?php endif; ?>
        <?php if ((int) $operations['failedJobs24'] > 0): ?><a class="admin-incident <?= $failureDanger ? 'danger' : '' ?>" href="/admin/jobs?status=failed&period=24h"><i data-lucide="circle-x"></i><span><strong>24 小时内 <?= (int) $operations['failedJobs24'] ?> 个任务失败</strong><small>影响 <?= (int) $operations['affectedMerchants24'] ?> 个商户；先核对任务结果、点数状态和可执行的下一步。</small></span><i data-lucide="chevron-right"></i></a><?php endif; ?>
        <?php if ((int) $operations['safetyEvents24'] > 0): ?><a class="admin-incident" href="/admin/security"><i data-lucide="shield-alert"></i><span><strong>24 小时内新增 <?= (int) $operations['safetyEvents24'] ?> 条安全事件</strong><small>查看场景、内容类型、处罚和通知状态。</small></span><i data-lucide="chevron-right"></i></a><?php endif; ?>
        <?php if ($workerHealthy && $queueBacklog === 0 && (int) $operations['failedJobs24'] === 0 && (int) $operations['safetyEvents24'] === 0): ?><div class="empty-state compact"><strong>当前没有需要立即处理的异常</strong><span>生成服务和任务队列运行正常。</span></div><?php endif; ?>
    </div>
</section>

<section class="stats-grid panel-gap" aria-label="业务概况">
    <article class="stat green"><small>正常商户</small><strong><?= number_format((int) $stats['activeMerchantCount']) ?></strong><span>总计 <?= number_format((int) $stats['merchantCount']) ?> 个</span></article>
    <article class="stat blue"><small>已完成任务</small><strong><?= number_format((int) $operations['completedJobs24']) ?></strong><span>最近 24 小时</span></article>
    <article class="stat amber"><small>累计业务消耗</small><strong><?= number_format((int) $stats['businessCredits']) ?></strong><span>点数流水口径</span></article>
    <article class="stat"><small>有效素材</small><strong><?= number_format((int) $stats['assetCount']) ?></strong><span>全部商户素材库</span></article>
</section>

<div class="content-grid">
    <section class="panel full"><div class="panel-header"><div><h3>最近异常任务</h3><p>优先定位受影响商户与可执行的下一步。</p></div><a class="button small" href="/admin/jobs?status=failed">查看全部失败任务</a></div>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>商户 / 商品</th><th>状态</th><th>发生了什么</th><th>更新时间</th><th>处理</th></tr></thead><tbody>
        <?php foreach ($recentFailedJobs as $job): ?><tr><td><a href="/admin/merchants/<?= (int) $job['merchant_id'] ?>"><strong><?= escape_html((string) $job['merchant_name']) ?></strong><small><?= escape_html((string) ($job['sku_name'] ?: '未关联商品')) ?></small></a></td><td><span class="badge <?= escape_html((string) $job['status']) ?>"><?= escape_html(job_status_label((string) $job['status'])) ?></span></td><td><?= escape_html(localized_job_error((string) ($job['error_message'] ?? ''))) ?><details class="asset-technical-meta"><summary>任务编号</summary><code><?= escape_html((string) $job['job_no']) ?></code></details></td><td><?= escape_html((string) $job['updated_at']) ?></td><td><a class="button small" href="/admin/jobs?q=<?= urlencode((string) $job['job_no']) ?>">诊断任务</a></td></tr><?php endforeach; ?>
        <?php if (!$recentFailedJobs): ?><tr><td colspan="5"><div class="empty-state compact">暂无异常任务</div></td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
    <section class="panel"><div class="panel-header"><div><h3>最近商户</h3><p>快速进入受影响账户</p></div><a class="button small" href="/admin/merchants">全部商户</a></div>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>商户</th><th>套餐</th><th>点数</th><th>状态</th></tr></thead><tbody>
        <?php foreach ($recentMerchants as $merchant): ?><tr><td><a href="/admin/merchants/<?= (int) $merchant['id'] ?>"><strong><?= escape_html($merchant['name']) ?></strong><small><?= escape_html($merchant['slug']) ?></small></a></td><td><?= escape_html(plan_code_label($merchant['plan_code'])) ?></td><td><?= (int) $merchant['credit_balance'] ?></td><td><span class="badge <?= escape_html($merchant['status']) ?>"><?= escape_html(record_status_label($merchant['status'])) ?></span></td></tr><?php endforeach; ?>
        <?php if (!$recentMerchants): ?><tr><td colspan="4"><div class="empty-state">暂无商户</div></td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
    <section class="panel"><div class="panel-header"><div><h3>运行摘要</h3><p>查看任务处理、结算与创作配置状态</p></div></div><div class="panel-body">
        <div class="detail-list spaced"><div class="detail-row"><span>图片队列处理中</span><strong><?= (int) $operations['processingItems'] ?> 项</strong></div><div class="detail-row"><span>等待处理</span><strong><?= number_format($queueBacklog) ?> 项</strong></div><div class="detail-row"><span>生成服务</span><strong><a href="/admin/worker"><?= $workerHealthy ? '运行中' : '需要检查' ?></a></strong></div><div class="detail-row"><span>提示词模板</span><strong><a href="/admin/prompts">统一管理</a></strong></div></div>
    </div></section>
</div>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
