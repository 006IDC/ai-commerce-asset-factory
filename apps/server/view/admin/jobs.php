<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<?php
$jobQuery = static function (array $changes = []) use ($status, $resultStatus, $merchantId, $keyword, $period, $sort, $attention, $queue): string {
    $query = array_filter(array_merge([
        'status' => $status,
        'result' => $resultStatus,
        'merchant_id' => $merchantId > 0 ? $merchantId : null,
        'q' => $keyword,
        'period' => $period,
        'sort' => $sort !== 'latest' ? $sort : null,
        'attention' => $attention ? 1 : null,
        'queue' => $queue ? 1 : null,
    ], $changes), static fn (mixed $value): bool => $value !== null && $value !== '');
    return '/admin/jobs' . ($query === [] ? '' : '?' . http_build_query($query));
};
?>
<div class="page-actions"><div><h2>生成任务</h2><p>定位受影响商户、失败原因、计费结果与可执行动作，共 <?= number_format($total) ?> 条。</p></div><?php if ($merchantId > 0): ?><a class="button" href="/admin/jobs"><i data-lucide="x"></i><span>清除商户筛选</span></a><?php endif; ?></div>
<?php if ($attention): ?><div class="flash warning" role="status"><i data-lucide="bell-ring"></i><span>当前显示最近 <?= $period === '24h' ? '24 小时' : escape_html($period) ?>内全部需管理员处理的任务，徽标和本列表使用同一查询条件。若实时状态变化，数量会随刷新更新。</span></div><?php endif; ?>
<form class="admin-job-filters" method="get" action="/admin/jobs">
    <?php if ($merchantId > 0): ?><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><?php endif; ?>
    <label><span>搜索</span><div class="input-with-icon"><i data-lucide="search"></i><input class="input" name="q" value="<?= escape_html($keyword) ?>" placeholder="任务号、商户、商品或模型"></div></label>
    <label><span>状态</span><select class="input" name="status" <?= ($attention || $queue) ? 'disabled' : '' ?>><option value="">全部状态</option><?php foreach (['pending_gateway','queued','running','manual_review','completed','partial','failed','result_missing','cancelled'] as $option): ?><option value="<?= escape_html($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= escape_html(job_status_label($option)) ?></option><?php endforeach; ?></select></label>
    <?php if ($attention): ?><input type="hidden" name="attention" value="1"><?php endif; ?>
    <?php if ($queue): ?><input type="hidden" name="queue" value="1"><?php endif; ?>
    <label><span>结果</span><select class="input" name="result"><option value="">全部结果</option><option value="available" <?= $resultStatus === 'available' ? 'selected' : '' ?>>结果可用</option><option value="partial" <?= $resultStatus === 'partial' ? 'selected' : '' ?>>部分可用</option><option value="missing" <?= $resultStatus === 'missing' ? 'selected' : '' ?>>结果缺失</option></select></label>
    <label><span>时间</span><select class="input" name="period"><option value="">全部时间</option><option value="24h" <?= $period === '24h' ? 'selected' : '' ?>>最近 24 小时</option><option value="7d" <?= $period === '7d' ? 'selected' : '' ?>>最近 7 天</option><option value="30d" <?= $period === '30d' ? 'selected' : '' ?>>最近 30 天</option></select></label>
    <label><span>排序</span><select class="input" name="sort"><option value="latest" <?= $sort === 'latest' ? 'selected' : '' ?>>最新优先</option><option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>最早优先</option></select></label>
    <button class="button primary" type="submit"><i data-lucide="list-filter"></i><span>应用筛选</span></button>
</form>
<section class="panel admin-job-results"><div class="table-wrap"><table class="data-table admin-job-table"><thead><tr><th>商户 / 商品</th><th>任务类型</th><th>处理状态</th><th>结果状态</th><th>计费</th><th>生成耗时</th><th>创建时间</th><th>操作</th></tr></thead><tbody>
<?php foreach ($jobs as $job): $guidance = !empty($job['error_message']) ? job_failure_guidance((string) $job['error_message'], (int) $job['actual_credits'], (int) $job['estimated_credits']) : null; ?><tr>
    <td data-label="商户 / 商品"><a href="/admin/merchants/<?= (int) $job['merchant_id'] ?>"><strong><?= escape_html((string) $job['merchant_name']) ?></strong><small><?= escape_html((string) ($job['sku_name'] ?: '未关联商品')) ?></small></a><?php if ((string) $job['platform'] === 'free'): ?><div class="admin-job-content-source"><strong>当前内容来源</strong><span><?= trim((string) ($job['free_template_name'] ?? '')) !== '' ? '灵感模板：' . escape_html((string) $job['free_template_name']) : '用户直接描述' ?></span><small>具体主体：<?= escape_html((string) (($job['free_subject_text'] ?? '') ?: '历史任务未单独冻结')) ?></small><small>参考图：<?= max(0, (int) ($job['free_reference_count'] ?? 0)) ?> 张 · <?= escape_html((string) ($job['execution_method_label'] ?? 'Direct / Sub2API')) ?></small></div><?php endif; ?><details class="asset-technical-meta"><summary>高级技术详情</summary><code><?= escape_html((string) $job['job_no']) ?></code><small>模型：<?= escape_html((string) ($job['model'] ?: '未记录')) ?></small><?php if (!empty($job['client_submission_id'])): ?><small>提交幂等键：<?= escape_html((string) $job['client_submission_id']) ?></small><?php endif; ?><?php if (!empty($job['submission_payload_hash'])): ?><small>提交内容指纹：<?= escape_html((string) $job['submission_payload_hash']) ?></small><?php endif; ?><?php if (!empty($job['free_template_code'])): ?><small>模板代码：<?= escape_html((string) $job['free_template_code']) ?></small><?php endif; ?><?php if (!empty($job['free_reference_fingerprints_json'])): ?><code><?= escape_html((string) $job['free_reference_fingerprints_json']) ?></code><?php endif; ?><?php if (!empty($job['new_api_request_id'])): ?><small>主请求：<?= escape_html((string) $job['new_api_request_id']) ?></small><?php endif; ?><?php foreach ((array) ($job['request_ids'] ?? []) as $requestId): ?><small>子请求：<?= escape_html((string) $requestId) ?></small><?php endforeach; ?></details></td>
    <td data-label="任务类型"><?= escape_html(platform_label((string) $job['platform'])) ?><small><?= (string) ($job['output_kind'] ?? 'image') === 'video' ? '商品视频' : (!empty($job['detail_page_project_id']) ? '详情页图片' : (!empty($job['bundle_no']) ? '整套上架' : '商品图片')) ?></small></td>
    <td data-label="处理状态"><span class="badge <?= escape_html((string) $job['status']) ?>"><?= escape_html(job_status_label((string) $job['status'])) ?></span><?php if ($guidance): ?><small class="admin-job-error"><?= escape_html((string) $guidance['what']) ?></small><?php endif; ?><details class="asset-technical-meta"><summary>处理阶段</summary><?php foreach ((array) ($job['generation_items'] ?? []) as $jobItem): ?><small>#<?= (int) ($jobItem['position'] ?? 0) ?> <?= escape_html(asset_type_label((string) ($jobItem['asset_type'] ?? ''))) ?> · <?= escape_html(job_status_label((string) ($jobItem['status'] ?? 'queued'))) ?></small><?php if (!empty($jobItem['stage_timestamps'])): ?><code><?= escape_html(json_encode($jobItem['stage_timestamps'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') ?></code><?php endif; ?><?php endforeach; ?></details></td>
    <td data-label="结果状态"><span class="result-state result-<?= escape_html((string) $job['result_status']) ?>"><?= escape_html((string) $job['result_status_label']) ?></span><small><?= (int) $job['available_result_count'] ?>/<?= (int) $job['expected_result_count'] ?> 个可用结果</small></td>
    <td data-label="计费"><strong><?= (int) $job['actual_credits'] ?> / <?= (int) $job['estimated_credits'] ?> 点</strong><small><?php if ((string) $job['result_status'] === 'missing' && (string) $job['status'] === 'completed'): ?>结果缺失，需核对结算<?php elseif (in_array((string) $job['status'], ['failed', 'partial', 'cancelled'], true)): ?>未使用部分已释放或退回<?php else: ?>实际 / 预估<?php endif; ?></small></td>
    <td data-label="生成耗时"><?= escape_html((string) $job['duration_label']) ?></td><td data-label="创建时间"><?= escape_html((string) $job['created_at']) ?></td>
    <td data-label="下一步"><?php if (in_array((string) $job['status'], ['failed','partial','pending_gateway'], true) || (string) $job['result_status'] === 'missing'): ?><form method="post" action="/admin/jobs/<?= (int) $job['id'] ?>/retry" data-submit-loading data-loading-message="正在重新提交缺失或失败的结果..."><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><button class="button small primary" type="submit"><i data-lucide="rotate-cw"></i><span>重新排队</span></button></form><?php else: ?><a class="button small" href="/admin/jobs?q=<?= urlencode((string) $job['job_no']) ?>"><i data-lucide="search"></i><span>查看</span></a><?php endif; ?></td>
</tr><?php endforeach; ?>
<?php if (!$jobs): ?><tr><td colspan="8"><div class="empty-state"><strong>没有符合条件的任务</strong><span>调整处理状态、结果状态、时间或搜索词后再试。</span></div></td></tr><?php endif; ?>
</tbody></table></div></section>
<section class="panel admin-job-results panel-gap">
    <div class="panel-header"><div><h3>商品上新任务</h3><p>商品图经一次识别直接生成可编辑产品报告和详情页方案。</p></div><span class="badge"><?= number_format(count($launchTasks ?? [])) ?> 条</span></div>
    <div class="table-wrap"><table class="data-table admin-job-table"><thead><tr><th>商户 / 商品</th><th>状态</th><th>模型请求</th><th>异常诊断</th><th>操作</th></tr></thead><tbody>
    <?php foreach (($launchTasks ?? []) as $launchTask):
        $taskStatus = (string) ($launchTask['status'] ?? 'queued');
        $taskStatusLabel = match ($taskStatus) { 'running' => '处理中', 'retrying' => '自动重试', 'failed' => '失败', 'completed' => '已完成', default => job_status_label($taskStatus) };
        $taskDiagnostic = (array) ($launchTask['diagnostic'] ?? []);
    ?>
        <tr>
            <td data-label="商户 / 商品"><a href="/admin/merchants/<?= (int) $launchTask['merchant_id'] ?>"><strong><?= escape_html((string) ($launchTask['merchant_name'] ?? '未知商户')) ?></strong><small><?= escape_html((string) (($launchTask['sku_name'] ?? '') ?: '尚未建立商品档案')) ?></small></a><code><?= escape_html((string) $launchTask['task_no']) ?></code></td>
            <td data-label="状态"><span class="badge <?= escape_html($taskStatus) ?>"><?= escape_html($taskStatusLabel) ?></span><small><?= escape_html((string) ($launchTask['progress_message'] ?? '')) ?></small></td>
            <td data-label="模型请求"><strong><?= escape_html((string) (($launchTask['model'] ?? '') ?: '系统默认模型')) ?></strong><small><?= !empty($launchTask['request_id']) ? escape_html((string) $launchTask['request_id']) : '尚无请求编号' ?></small></td>
            <td data-label="异常诊断"><?php if ($taskDiagnostic): ?><strong><?= escape_html((string) ($taskDiagnostic['internal_error_code'] ?? 'request_failed')) ?></strong><?php if (!empty($taskDiagnostic['missing_fields'])): ?><small>缺失：<?= escape_html(implode('、', (array) $taskDiagnostic['missing_fields'])) ?></small><?php endif; ?><details class="asset-technical-meta"><summary>诊断详情</summary><code><?= escape_html(json_encode($taskDiagnostic, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}') ?></code></details><?php else: ?><span>暂无异常诊断</span><?php endif; ?></td>
            <td data-label="操作"><?php if ($taskStatus === 'failed'): ?><form method="post" action="/admin/vision-tasks/<?= (int) $launchTask['id'] ?>/retry"><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><button class="button small primary" type="submit"><i data-lucide="rotate-cw"></i><span>重新处理</span></button></form><?php else: ?><span>无需操作</span><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($launchTasks)): ?><tr><td colspan="5"><div class="empty-state"><strong>没有符合条件的商品上新任务</strong><span>商品上新链路的任务会单独显示在这里。</span></div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php if ($totalPages > 1): ?><nav class="pagination" aria-label="任务分页"><?php if ($page > 1): ?><a class="button small" href="<?= escape_html($jobQuery(['page' => $page - 1])) ?>"><i data-lucide="chevron-left"></i><span>上一页</span></a><?php endif; ?><span>第 <?= $page ?> / <?= $totalPages ?> 页</span><?php if ($page < $totalPages): ?><a class="button small" href="<?= escape_html($jobQuery(['page' => $page + 1])) ?>"><span>下一页</span><i data-lucide="chevron-right"></i></a><?php endif; ?></nav><?php endif; ?>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
