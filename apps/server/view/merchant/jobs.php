<?php require __DIR__ . '/../partials/merchant_header.php'; ?>
<?php
$groups = [];
foreach ($jobs as $job) {
    $key = trim((string) ($job['bundle_no'] ?? '')) !== '' ? 'bundle:' . $job['bundle_no'] : 'job:' . $job['id'];
    $groups[$key] ??= ['bundleNo' => (string) ($job['bundle_no'] ?? ''), 'jobs' => []];
    $groups[$key]['jobs'][] = $job;
}
$pageUrl = static function (int $targetPage) use ($skuId, $status, $keyword, $type, $period): string {
    $query = array_filter([
        'sku_id' => $skuId ?: null,
        'status' => $status ?: null,
        'q' => $keyword ?: null,
        'type' => $type ?: null,
        'period' => $period ?: null,
        'page' => $targetPage > 1 ? $targetPage : null,
    ], static fn (mixed $value): bool => $value !== null && $value !== '');
    return '/workspace/jobs' . ($query ? '?' . http_build_query($query) : '');
};
?>
<div class="page-actions">
    <div><h2>任务中心</h2><p><?php if ($filteredSku): ?><?= escape_html($filteredSku['name']) ?> · <?php endif; ?>查看生成进度、实际点数和结果去向</p></div>
    <a class="button primary" href="<?= $filteredSku ? '/workspace/generate?sku_id=' . (int) $filteredSku['id'] : '/workspace/generate' ?>"><i data-lucide="plus"></i><span>创建新任务</span></a>
</div>

<nav class="task-center-tabs" aria-label="任务状态">
    <?php foreach ([
        '' => ['全部', array_sum($statusCounts)],
        'running' => ['进行中', $statusCounts['running']],
        'queued' => ['等待中', $statusCounts['queued']],
        'partial' => ['部分完成', $statusCounts['partial']],
        'failed' => ['失败', $statusCounts['failed']],
        'completed' => ['已完成', $statusCounts['completed']],
        'cancelled' => ['已取消', $statusCounts['cancelled']],
    ] as $value => [$label, $count]): ?>
        <a class="<?= $status === $value ? 'active' : '' ?>" href="/workspace/jobs<?= $value !== '' ? '?status=' . escape_html($value) : '' ?>"><?= escape_html($label) ?><b><?= (int) $count ?></b></a>
    <?php endforeach; ?>
</nav>

<form class="task-center-filters" method="get" action="/workspace/jobs">
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= escape_html($status) ?>"><?php endif; ?>
    <label><span>搜索任务或商品</span><input class="input" type="search" name="q" value="<?= escape_html($keyword) ?>" placeholder="商品名称、SKU 或任务编号"></label>
    <label><span>商品</span><select class="input" name="sku_id"><option value="">全部商品</option><?php foreach ($skuOptions as $sku): ?><option value="<?= (int) $sku['id'] ?>" <?= $skuId === (int) $sku['id'] ? 'selected' : '' ?>><?= escape_html($sku['name'] . ' · ' . $sku['sku_code']) ?></option><?php endforeach; ?></select></label>
    <label><span>创作类型</span><select class="input" name="type"><option value="">全部类型</option><?php foreach (['bundle' => '整套上架', 'product' => '商品图片', 'video' => '商品视频', 'detail' => '详情页', 'free' => '自由创作', 'canvas' => '画布编辑'] as $value => $label): ?><option value="<?= $value ?>" <?= $type === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
    <label><span>创建时间</span><select class="input" name="period"><option value="">全部时间</option><option value="today" <?= $period === 'today' ? 'selected' : '' ?>>今天</option><option value="7d" <?= $period === '7d' ? 'selected' : '' ?>>最近 7 天</option><option value="30d" <?= $period === '30d' ? 'selected' : '' ?>>最近 30 天</option></select></label>
    <div class="button-row"><button class="button primary" type="submit"><i data-lucide="search"></i><span>筛选</span></button><?php if ($skuId || $keyword !== '' || $type !== '' || $period !== ''): ?><a class="button" href="<?= $status !== '' ? '/workspace/jobs?status=' . escape_html($status) : '/workspace/jobs' ?>">重置</a><?php endif; ?></div>
</form>

<?php if (!$groups): ?>
    <section class="panel"><div class="empty-state"><i data-lucide="list-checks"></i><strong>没有符合条件的任务</strong><span>调整筛选条件，或开始一个新的商品创作。</span><a class="button primary" href="/workspace/generate">开始商品上新</a></div></section>
<?php else: ?>
    <section class="task-group-list" aria-label="任务列表">
        <?php foreach ($groups as $group):
            $groupJobs = $group['jobs'];
            $isBundle = $group['bundleNo'] !== '';
            $first = $groupJobs[0];
            $groupTotal = 0;
            $groupCurrent = 0;
            $groupCredits = 0;
            $hasRunning = false;
            $hasWaiting = false;
            $hasFailed = false;
            $hasPartial = false;
            $allCompleted = true;
            foreach ($groupJobs as $row) {
                $rowTotal = max(1, (int) ($row['expected_result_count'] ?? 0));
                $groupTotal += $rowTotal;
                $groupCurrent += min($rowTotal, max(0, (int) ($row['available_result_count'] ?? 0)));
                $groupCredits += max(0, (int) ($row['actual_credits'] ?? 0));
                $hasRunning = $hasRunning || $row['status'] === 'running';
                $hasWaiting = $hasWaiting || in_array($row['status'], ['queued', 'pending_gateway'], true);
                $hasFailed = $hasFailed || $row['status'] === 'failed' || (string) ($row['result_status'] ?? '') === 'missing';
                $hasPartial = $hasPartial || $row['status'] === 'partial' || (string) ($row['result_status'] ?? '') === 'partial';
                $allCompleted = $allCompleted && $row['status'] === 'completed' && (string) ($row['result_status'] ?? '') === 'available';
            }
            $groupStatus = $hasRunning ? 'running' : ($hasWaiting ? 'queued' : (($hasPartial || ($hasFailed && $groupCurrent > 0)) ? 'partial' : ($hasFailed ? 'failed' : ($allCompleted ? 'completed' : (string) $first['status']))));
            $groupPercent = $groupTotal > 0 ? (int) floor($groupCurrent * 100 / $groupTotal) : 0;
            $groupTitle = $isBundle ? '整套上架素材' : (string) ($first['sku_name'] ?: ($first['detail_project_name'] ?: ($first['platform'] === 'free' ? '自由创作' : '商品创作')));
            $isVideoGroup = (string) ($first['output_kind'] ?? 'image') === 'video';
            $groupDisplayPercent = $isVideoGroup
                ? max(0, min(100, (int) ($first['progress_current'] ?? 0)))
                : $groupPercent;
            $groupType = $isBundle ? '主图与详情页 · ' . count($groupJobs) . ' 个子任务' : asset_type_list_label($first['asset_types']);
            $open = in_array($groupStatus, ['running', 'queued', 'failed', 'partial'], true);
        ?>
        <details class="task-group task-group-<?= escape_html($groupStatus) ?>" <?= $open ? 'open' : '' ?>>
            <summary>
                <span class="task-group-copy"><strong><?= escape_html($groupTitle) ?></strong><small><?= escape_html($groupType) ?> · <?= escape_html((string) $first['created_at']) ?></small></span>
                <span class="task-group-status"><span class="badge <?= escape_html($groupStatus) ?>"><?= escape_html(job_status_label($groupStatus)) ?></span></span>
                <span class="task-group-result">
                    <?php if (in_array($groupStatus, ['running', 'queued'], true)): ?>
                        <span><b><?= $isVideoGroup ? escape_html((string) ($first['progress_message'] ?: '视频正在后台处理')) : $groupCurrent . '/' . $groupTotal . ' 张' ?></b><small><?= $groupDisplayPercent ?>%</small></span>
                        <span class="job-progress-track <?= $groupStatus === 'running' ? 'is-active' : '' ?>"><i style="width: <?= $groupDisplayPercent ?>%"></i></span>
                    <?php elseif ($groupStatus === 'completed'): ?>
                        <strong><?= $isVideoGroup ? '视频已保存到素材库' : $groupCurrent . ' 张已保存到素材库' ?></strong>
                    <?php elseif ($groupStatus === 'partial'): ?>
                        <strong><?= $groupCurrent ?>/<?= $groupTotal ?> 张可用</strong><small>可补齐未完成图片</small>
                    <?php elseif ($groupStatus === 'failed'): ?>
                        <strong>需要处理</strong><small>未产出可用结果</small>
                    <?php else: ?>
                        <strong><?= escape_html(job_status_label($groupStatus)) ?></strong>
                    <?php endif; ?>
                </span>
                <span class="task-group-cost"><strong><?= number_format($groupCredits) ?> 点</strong><i data-lucide="chevron-down"></i></span>
            </summary>

            <div class="task-group-body">
                <?php foreach ($groupJobs as $job):
                    $isVideo = (string) ($job['output_kind'] ?? 'image') === 'video';
                    $progressTotal = max(1, (int) ($job['expected_result_count'] ?? 0));
                    $progressCurrent = min($progressTotal, max(0, (int) ($job['available_result_count'] ?? 0)));
                    $progressPercent = $isVideo
                        ? max(0, min(100, (int) ($job['progress_current'] ?? 0)))
                        : (int) floor($progressCurrent * 100 / $progressTotal);
                    $resultStatus = (string) ($job['result_status'] ?? 'none');
                    $needsAttention = in_array((string) $job['status'], ['failed', 'partial'], true) || $resultStatus === 'missing';
                    $canRetry = $needsAttention && (string) ($job['safety_status'] ?? '') !== 'blocked';
                    $canCancel = !empty($job['can_cancel']);
                    $usesReference = (int) ($job['detail_page_project_id'] ?? 0) > 0 ? !empty($job['detail_source_image']) : ($job['platform'] === 'free'
                        ? !empty($job['free_reference_image'])
                        : (!empty($job['source_image_path']) && $imageEditEnabled));
                    $sourceModeLabel = !$usesReference ? '文字生成' : ($job['platform'] === 'free' ? '临时参考图' : ((int) ($job['detail_page_project_id'] ?? 0) > 0 ? '详情页参考图' : '商品参考图'));
                    if ((int) ($job['detail_page_project_id'] ?? 0) > 0) {
                        $sourceUrl = '/workspace/detail-pages/' . (int) $job['detail_page_project_id'];
                        $sourceLabel = '修改详情页方案';
                    } elseif ($job['platform'] === 'free') {
                        $sourceUrl = '/workspace/free-create';
                        $sourceLabel = '修改创作内容';
                    } elseif ($job['platform'] === 'canvas') {
                        $sourceUrl = '/workspace/canvas' . ((int) ($job['canvas_source_asset_id'] ?? 0) > 0 ? '?asset_id=' . (int) $job['canvas_source_asset_id'] : '');
                        $sourceLabel = '返回画布调整';
                    } elseif ((int) ($job['sku_id'] ?? 0) > 0) {
                        $sourceUrl = '/workspace/generate?' . ($isVideo ? 'mode=video&' : '') . 'sku_id=' . (int) $job['sku_id'];
                        $sourceLabel = $isVideo ? '修改视频参数' : '修改生成参数';
                    } else {
                        $sourceUrl = '/workspace/generate';
                        $sourceLabel = '返回商品上新';
                    }
                    $resultUrl = (int) ($job['sku_id'] ?? 0) > 0 ? '/workspace/assets?sku_id=' . (int) $job['sku_id'] : ($job['platform'] === 'free' ? '/workspace/assets?source=free' : '/workspace/assets');
                    $failureMessage = trim((string) ($job['error_message'] ?? ''));
                    if ($failureMessage === '' && $resultStatus === 'missing') {
                        $failureMessage = '任务处理已结束，但没有找到可用的素材结果。';
                    }
                    $failure = $needsAttention ? job_failure_guidance($failureMessage, (int) $job['actual_credits'], (int) $job['estimated_credits']) : null;
                    if ($failure && $progressCurrent > 0 && $progressCurrent < $progressTotal) {
                        $missingCount = $progressTotal - $progressCurrent;
                        $failure['what'] = "本次 {$progressCurrent}/{$progressTotal} 张已成功保存，另有 {$missingCount} 张因生成通道暂时不可用而未产出。";
                        $failure['solution'] = '直接点击“补齐缺失图片”即可沿用原任务参数，只重试未完成部分，不需要重新创建商品。';
                    }
                ?>
                <article class="task-record task-record-<?= escape_html((string) $job['status']) ?>" data-job-id="<?= (int) $job['id'] ?>" data-job-status-value="<?= escape_html((string) $job['status']) ?>">
                    <div class="task-record-main">
                        <span class="task-record-type"><?= escape_html(asset_type_list_label($job['asset_types'])) ?></span>
                        <strong><?= escape_html((string) ($job['sku_name'] ?: ($job['detail_project_name'] ?: ($job['platform'] === 'free' ? '自由创作' : '未关联商品')))) ?></strong>
                        <small><?= escape_html(platform_label($job['platform'])) ?> · <?= escape_html(generation_language_label($job['language'] ?? 'zh-CN')) ?> · <?= escape_html($sourceModeLabel) ?></small>
                        <span class="task-record-model"><i data-lucide="shield-check"></i><span>系统已自动选择适合本次任务的生成服务</span></span>
                    </div>

                    <div class="task-record-state">
                        <?php if (in_array((string) $job['status'], ['queued', 'pending_gateway', 'running'], true)): ?>
                            <div class="task-record-state-head"><span class="badge <?= escape_html((string) $job['status']) ?>"><?= escape_html(job_status_label($job['status'])) ?></span><b><?= $isVideo ? $progressPercent . '%' : $progressCurrent . '/' . $progressTotal . ' 张 · ' . $progressPercent . '%' ?></b></div>
                            <div class="job-progress-track <?= $job['status'] === 'running' ? 'is-active' : '' ?>" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $progressPercent ?>"><span style="width: <?= $progressPercent ?>%"></span></div>
                            <small><?= escape_html((string) ($job['progress_message'] ?: '任务正在后台处理')) ?></small>
                            <?php if (!$canCancel && (string) $job['status'] === 'running'): ?><small>生成已经开始，当前任务不能取消。</small><?php endif; ?>
                        <?php elseif ($failure): ?>
                            <div class="task-error-box">
                                <strong><?= escape_html((string) $failure['what']) ?></strong>
                                <span><b>是否扣费：</b><?= escape_html((string) $failure['billing']) ?></span>
                                <span><b>如何解决：</b><?= escape_html((string) $failure['solution']) ?></span>
                            </div>
                            <?php if ($progressCurrent > 0): ?><small class="task-partial-result"><?= $progressCurrent ?>/<?= $progressTotal ?> 张已保存，可先查看已有素材。</small><?php endif; ?>
                        <?php else: ?>
                            <div class="task-complete-result"><i data-lucide="circle-check"></i><span><strong><?= $isVideo ? '视频已保存到素材库' : $progressCurrent . ' 张已保存到素材库' ?></strong><small><?= $isVideo ? '结果可直接播放、下载或继续归档' : '结果可继续编辑、下载或用于图片工具' ?></small></span></div>
                            <?php if (!empty($job['result_preview_urls'])): ?><div class="task-result-previews"><?php foreach ($job['result_preview_urls'] as $previewUrl): ?><img src="<?= escape_html((string) $previewUrl) ?>" alt="已生成素材缩略图" loading="lazy"><?php endforeach; ?></div><?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <div class="task-record-side">
                        <div class="task-credit-breakdown"><span>实际点数<strong><?= number_format((int) $job['actual_credits']) ?></strong></span><span>生成耗时<strong><?= escape_html((string) $job['duration_label']) ?></strong></span></div>
                        <div class="task-record-primary">
                            <?php if ($canRetry): ?>
                                <form method="post" action="/workspace/jobs/<?= (int) $job['id'] ?>/retry" data-job-retry-form data-submit-loading data-loading-message="正在重新提交未完成图片..."><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><button class="button small primary" type="submit"><i data-lucide="rotate-cw"></i><span><?= $progressCurrent > 0 ? '补齐缺失图片' : '重新生成' ?></span></button></form>
                            <?php elseif ($progressCurrent > 0): ?>
                                <a class="button small primary" href="<?= escape_html($resultUrl) ?>"><i data-lucide="images"></i><span>查看素材</span></a>
                            <?php elseif ($canCancel): ?>
                                <form method="post" action="/workspace/jobs/<?= (int) $job['id'] ?>/cancel" data-job-cancel-form data-confirm-message="确定取消这个任务吗？已完成图片会保留，未使用点数会退回。"><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><button class="button small danger" type="submit"><i data-lucide="square"></i><span>取消任务</span></button></form>
                            <?php else: ?>
                                <a class="button small" href="<?= escape_html($sourceUrl) ?>"><i data-lucide="sliders-horizontal"></i><span><?= escape_html($sourceLabel) ?></span></a>
                            <?php endif; ?>
                        </div>
                        <details class="task-record-more" data-dismiss-on-outside>
                            <summary aria-label="更多任务操作"><i data-lucide="ellipsis"></i><span>更多</span></summary>
                            <div>
                                <?php if ($progressCurrent > 0 && $canRetry): ?><a href="<?= escape_html($resultUrl) ?>"><i data-lucide="images"></i><span>查看已有素材</span></a><?php endif; ?>
                                <a href="<?= escape_html($sourceUrl) ?>"><i data-lucide="sliders-horizontal"></i><span><?= escape_html($sourceLabel) ?></span></a>
                                <?php if ($canCancel && $progressCurrent > 0): ?><form method="post" action="/workspace/jobs/<?= (int) $job['id'] ?>/cancel" data-job-cancel-form data-confirm-message="确定取消这个任务吗？"><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><button type="submit"><i data-lucide="square"></i><span>取消任务</span></button></form><?php endif; ?>
                                <details class="task-record-tech"><summary>任务信息</summary><code><?= escape_html((string) $job['job_no']) ?></code><?php foreach ((array) ($job['generation_items'] ?? []) as $jobItem): ?><small>#<?= (int) ($jobItem['position'] ?? 0) ?> · <?= escape_html(asset_type_label((string) ($jobItem['asset_type'] ?? ''))) ?> · <?= escape_html(job_status_label((string) ($jobItem['status'] ?? 'queued'))) ?></small><?php endforeach; ?></details>
                            </div>
                        </details>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        </details>
        <?php endforeach; ?>
    </section>
    <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="任务分页">
            <a class="button small" href="<?= escape_html($pageUrl(max(1, $page - 1))) ?>" <?= $page <= 1 ? 'aria-disabled="true"' : '' ?>>上一页</a>
            <span>第 <?= $page ?> / <?= $totalPages ?> 页</span>
            <a class="button small" href="<?= escape_html($pageUrl(min($totalPages, $page + 1))) ?>" <?= $page >= $totalPages ? 'aria-disabled="true"' : '' ?>>下一页</a>
        </nav>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../partials/merchant_footer.php'; ?>
