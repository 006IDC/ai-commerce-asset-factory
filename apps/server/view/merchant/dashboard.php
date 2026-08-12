<?php require __DIR__ . '/../partials/merchant_header.php'; ?>
<?php
$focusJobs = $activeJobs ?: ($failedJobs ?: $recentJobs);
$focusTitle = $activeJobs ? '继续进行中的任务' : ($failedJobs ? '需要处理的失败任务' : '最近完成的任务');
$focusCopy = $activeJobs
    ? '任务在后台继续运行，你可以离开本页；完成后素材会自动归档。'
    : ($failedJobs ? '失败任务不会要求你从头开始，先查看原因，再修改或重试。' : '从最近结果继续查看、编辑或下载素材。');
?>
<div class="workspace-action-center">
    <section class="workspace-action-hero">
        <div>
            <small>今天要做什么</small>
            <h2><?= escape_html($user['name']) ?>，继续你的商品创作</h2>
            <p>从添加商品图开始，系统会在后台完成识别、生成和归档。每次提交前都能看到数量与预计点数。</p>
        </div>
        <div class="button-row">
            <a class="button" href="/workspace/assets"><i data-lucide="images"></i><span>查看素材</span></a>
            <a class="button primary" href="/workspace/generate"><i data-lucide="image-plus"></i><span>开始一个新商品</span></a>
        </div>
    </section>

    <nav class="workspace-action-grid" aria-label="工作台主要操作">
        <a class="workspace-action-item" href="/workspace/generate">
            <span><i data-lucide="image-plus"></i></span>
            <span><strong>开始一个新商品</strong><small>上传商品图，建立资料并选择生成内容</small></span>
            <i data-lucide="arrow-right"></i>
        </a>
        <a class="workspace-action-item" href="/workspace/jobs<?= (int) $actionCounts['active'] > 0 ? '?status=' . (count(array_filter($activeJobs, static fn (array $job): bool => $job['status'] === 'running')) > 0 ? 'running' : 'queued') : '' ?>">
            <span><i data-lucide="loader-circle"></i></span>
            <span><strong>继续进行中的任务</strong><small><?= (int) $actionCounts['active'] ?> 个任务正在生成或等待处理</small></span>
            <i data-lucide="arrow-right"></i>
        </a>
        <a class="workspace-action-item <?= (int) $actionCounts['failed'] > 0 ? 'danger' : '' ?>" href="/workspace/jobs?status=failed">
            <span><i data-lucide="<?= (int) $actionCounts['failed'] > 0 ? 'circle-alert' : 'circle-check' ?>"></i></span>
            <span><strong>处理失败任务</strong><small><?= (int) $actionCounts['failed'] > 0 ? (int) $actionCounts['failed'] . ' 个任务需要查看原因' : '当前没有待处理失败任务' ?></small></span>
            <i data-lucide="arrow-right"></i>
        </a>
    </nav>

    <?php if ($announcement['enabled']): ?>
    <section class="workspace-announcement">
        <i data-lucide="megaphone"></i>
        <div><strong><?= escape_html($announcement['title']) ?></strong><p><?= nl2br(escape_html($announcement['content'])) ?></p></div>
        <?php if ($announcement['contactLabel'] !== '' && $announcement['contactUrl'] !== ''): ?><a class="button primary small" href="<?= escape_html($announcement['contactUrl']) ?>" <?= preg_match('/^https?:\/\//i', $announcement['contactUrl']) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>><?= escape_html($announcement['contactLabel']) ?></a><?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="workspace-action-section">
        <header>
            <div><h3><?= escape_html($focusTitle) ?></h3><p><?= escape_html($focusCopy) ?></p></div>
            <a class="button small" href="/workspace/jobs">查看任务中心</a>
        </header>
        <?php if (!$focusJobs): ?>
            <div class="empty-state">
                <i data-lucide="sparkles"></i>
                <strong>还没有创作任务</strong>
                <span>直接从商品上新添加商品图，不需要预先创建 SKU。</span>
                <a class="button primary" href="/workspace/generate">开始第一个商品</a>
            </div>
        <?php else: ?>
            <div class="workspace-task-list">
                <?php foreach ($focusJobs as $job):
                    $total = max((int) ($job['progress_total'] ?? 0), count(decode_json_list($job['asset_types'] ?? '[]')));
                    $current = min(max(0, (int) ($job['progress_current'] ?? 0)), $total);
                    $percent = $total > 0 ? (int) floor($current * 100 / $total) : 0;
                    $title = (string) ($job['sku_name'] ?: ($job['detail_project_name'] ?: ($job['platform'] === 'free' ? '自由创作' : '商品创作')));
                    $targetUrl = $job['status'] === 'failed'
                        ? '/workspace/jobs?status=failed'
                        : ((int) ($job['sku_id'] ?? 0) > 0 ? '/workspace/assets?sku_id=' . (int) $job['sku_id'] : '/workspace/jobs');
                ?>
                <div class="workspace-task-row">
                    <div><strong><?= escape_html($title) ?></strong><small><?= escape_html(asset_type_list_label($job['asset_types'])) ?></small></div>
                    <div><span class="badge <?= escape_html((string) $job['status']) ?>"><?= escape_html($current > 0 && $job['status'] === 'failed' ? '部分完成' : job_status_label($job['status'])) ?></span><small><?= escape_html((string) ($job['duration_label'] ?? '')) ?></small></div>
                    <div>
                        <div class="job-progress-meta"><span><?= $current ?>/<?= $total ?> 张</span><span><?= $percent ?>%</span></div>
                        <div class="job-progress-track <?= in_array($job['status'], ['queued', 'running'], true) ? 'is-active' : '' ?>"><span style="width: <?= $percent ?>%"></span></div>
                        <small><?= escape_html($job['status'] === 'failed' ? localized_job_error($job['error_message'] ?? '') : (string) ($job['progress_message'] ?? '')) ?></small>
                    </div>
                    <a class="button small <?= $job['status'] === 'failed' ? 'danger' : '' ?>" href="<?= escape_html($targetUrl) ?>"><?= $job['status'] === 'failed' ? '查看解决办法' : ($job['status'] === 'completed' ? '查看素材' : '查看进度') ?></a>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="workspace-action-section">
        <header>
            <div><h3>账户与资产概况</h3><p>订阅权益与点数余额分别管理，生成任务按实际完成图片结算。</p></div>
            <div class="button-row"><a class="button small" href="/workspace/plans">套餐权益</a><a class="button small" href="/workspace/usage">点数与流水</a></div>
        </header>
        <div class="billing-explainer-grid">
            <div><strong><?= number_format((int) $merchant['credit_balance']) ?> 点</strong><small>当前可用点数，可用于按次任务或购买套餐</small></div>
            <div><strong><?= escape_html($subscription['plan_name'] ?? '按次计费账户') ?></strong><small><?= $subscription ? '有效期至 ' . escape_html($subscription['expires_at']) : '没有生效中的订阅套餐' ?></small></div>
            <div><strong><?= number_format((int) $stats['skuCount']) ?> 个商品档案</strong><small><?= number_format((int) $stats['assetCount']) ?> 张可用素材已自动归档</small></div>
            <div><strong><?= number_format((int) $stats['jobCount']) ?> 次创作任务</strong><small>累计实际业务消耗 <?= number_format((int) $stats['usedCredits']) ?> 点</small></div>
        </div>
    </section>
</div>
<?php require __DIR__ . '/../partials/merchant_footer.php'; ?>
