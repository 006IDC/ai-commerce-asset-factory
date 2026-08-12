<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<div class="page-actions">
    <div><h2>用户反馈</h2><p>按真实使用问题安排产品、提示词和稳定性优化优先级。</p></div>
    <a class="button" href="/admin/feedback/export?<?= escape_html(http_build_query(array_filter(['q' => $filters['keyword'], 'status' => $filters['status'], 'module' => $filters['module'], 'improvement' => $filters['improvement'], 'rating' => $filters['rating']]))) ?>"><i data-lucide="download"></i><span>导出当前结果</span></a>
</div>
<section class="stats-grid">
    <article class="stat blue"><small>反馈总数</small><strong><?= number_format((int) $summary['total']) ?></strong><span>平均评分 <?= number_format((float) $summary['averageRating'], 1) ?> / 5</span></article>
    <article class="stat amber"><small>待查看</small><strong><?= number_format((int) $summary['new']) ?></strong><span>需要运营确认和分类</span></article>
    <article class="stat"><small>已进入计划</small><strong><?= number_format((int) $summary['planned']) ?></strong><span>正在排期的改进项</span></article>
    <article class="stat green"><small>已处理</small><strong><?= number_format((int) $summary['resolved']) ?></strong><span>已完成或已关闭</span></article>
</section>
<?php if ($improvementRanking): ?><section class="feedback-ranking"><strong>用户最希望改进</strong><div><?php foreach ($improvementRanking as $rank => $item): ?><span><b><?= $rank + 1 ?></b><?= escape_html($feedbackService->label($feedbackService->improvementTypes(), $item['improvement_type'])) ?><em><?= (int) $item['total'] ?> 票</em></span><?php endforeach; ?></div></section><?php endif; ?>
<section class="asset-filter-panel">
    <form class="feedback-filter-form" method="get" action="/admin/feedback">
        <div class="form-field"><label for="feedback-search">搜索反馈</label><div class="input-with-icon"><i data-lucide="search"></i><input class="input" id="feedback-search" name="q" value="<?= escape_html($filters['keyword']) ?>" placeholder="商户、邮箱、任务号或反馈内容"></div></div>
        <div class="form-field"><label for="feedback-status">状态</label><select class="input" id="feedback-status" name="status"><option value="">全部状态</option><?php foreach ($feedbackService->statuses() as $value => $label): ?><option value="<?= escape_html($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select></div>
        <div class="form-field"><label for="feedback-module-filter">使用模块</label><select class="input" id="feedback-module-filter" name="module"><option value="">全部模块</option><?php foreach ($feedbackService->modules() as $value => $label): ?><option value="<?= escape_html($value) ?>" <?= $filters['module'] === $value ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select></div>
        <div class="form-field"><label for="feedback-improvement-filter">改进方向</label><select class="input" id="feedback-improvement-filter" name="improvement"><option value="">全部方向</option><?php foreach ($feedbackService->improvementTypes() as $value => $label): ?><option value="<?= escape_html($value) ?>" <?= $filters['improvement'] === $value ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select></div>
        <div class="form-field"><label for="feedback-rating-filter">评分</label><select class="input" id="feedback-rating-filter" name="rating"><option value="0">全部评分</option><?php for ($rating = 1; $rating <= 5; $rating++): ?><option value="<?= $rating ?>" <?= (int) $filters['rating'] === $rating ? 'selected' : '' ?>><?= $rating ?> 分</option><?php endfor; ?></select></div>
        <div class="asset-filter-actions"><button class="button primary" type="submit">筛选</button><a class="button" href="/admin/feedback">重置</a></div>
    </form>
</section>
<section class="panel">
    <div class="panel-header"><div><h3>反馈清单</h3><p>当前显示 <?= count($feedbackRows) ?> 条，最多 300 条</p></div></div>
    <div class="feedback-admin-list">
        <?php foreach ($feedbackRows as $row): ?><article class="feedback-admin-item">
            <div class="feedback-admin-heading"><div><span class="badge <?= escape_html($row['status']) ?>"><?= escape_html($feedbackService->label($feedbackService->statuses(), $row['status'])) ?></span><strong>#<?= (int) $row['id'] ?> · <?= escape_html($feedbackService->label($feedbackService->improvementTypes(), $row['improvement_type'])) ?></strong><span class="feedback-score"><?= (int) $row['rating'] ?> / 5</span></div><small><?= escape_html($row['created_at']) ?></small></div>
            <div class="feedback-admin-meta"><span><i data-lucide="building-2"></i><a href="/admin/merchants/<?= (int) $row['merchant_id'] ?>"><?= escape_html($row['merchant_name'] ?: '-') ?></a></span><span><i data-lucide="user"></i><?= escape_html($row['user_name'] ?: $row['user_email'] ?: '-') ?></span><span><i data-lucide="layout-grid"></i><?= escape_html($feedbackService->label($feedbackService->modules(), $row['module'])) ?></span><span><i data-lucide="triangle-alert"></i><?= escape_html($feedbackService->label($feedbackService->issueTypes(), $row['issue_type'])) ?></span><?php if ($row['related_reference']): ?><span><i data-lucide="link"></i><?= escape_html($row['related_reference']) ?></span><?php endif; ?><?php if ((int) $row['allow_contact'] === 1): ?><span><i data-lucide="mail-check"></i>允许邮箱回访</span><?php endif; ?></div>
            <div class="feedback-admin-content"><div><small>最希望改成</small><p><?= nl2br(escape_html($row['desired_improvement'])) ?></p></div><?php if ($row['usage_context']): ?><div><small>问题与使用过程</small><p><?= nl2br(escape_html($row['usage_context'])) ?></p></div><?php endif; ?></div>
            <form class="feedback-admin-form" method="post" action="/admin/feedback/<?= (int) $row['id'] ?>">
                <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
                <div class="form-field"><label for="feedback-status-<?= (int) $row['id'] ?>">处理状态</label><select class="input" id="feedback-status-<?= (int) $row['id'] ?>" name="status"><?php foreach ($feedbackService->statuses() as $value => $label): ?><option value="<?= escape_html($value) ?>" <?= $row['status'] === $value ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select></div>
                <div class="form-field"><label for="feedback-priority-<?= (int) $row['id'] ?>">优先级</label><select class="input" id="feedback-priority-<?= (int) $row['id'] ?>" name="priority"><?php foreach ($feedbackService->priorities() as $value => $label): ?><option value="<?= escape_html($value) ?>" <?= $row['priority'] === $value ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select></div>
                <div class="form-field feedback-admin-note"><label for="feedback-note-<?= (int) $row['id'] ?>">内部备注</label><input class="input" id="feedback-note-<?= (int) $row['id'] ?>" name="admin_note" maxlength="2000" value="<?= escape_html($row['admin_note'] ?: '') ?>" placeholder="记录判断、计划版本或不采纳原因"></div>
                <button class="button primary" type="submit">保存处理结果</button>
            </form>
        </article><?php endforeach; ?>
        <?php if (!$feedbackRows): ?><div class="empty-state"><strong>没有符合筛选条件的反馈</strong><span>调整筛选条件后再查看。</span></div><?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
