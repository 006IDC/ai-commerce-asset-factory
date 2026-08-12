<?php require __DIR__ . '/../partials/merchant_header.php'; ?>
<div class="page-actions">
    <div><h2>意见反馈</h2><p>告诉我们实际使用感受，反馈会直接进入产品改进清单。</p></div>
</div>
<div class="feedback-layout">
    <section class="panel">
        <div class="panel-header"><div><h3>提交一次使用反馈</h3><p>约 2 分钟，不消耗点数</p></div><span class="badge active">直接送达运营团队</span></div>
        <div class="panel-body">
            <form method="post" action="/workspace/feedback" data-submit-loading data-loading-message="正在提交反馈...">
                <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
                <div class="form-grid">
                    <div class="form-field"><label for="feedback-module">1. 这次主要使用了哪个功能？</label><select class="input" id="feedback-module" name="module" required><option value="">请选择</option><?php foreach ($feedbackService->modules() as $value => $label): ?><option value="<?= escape_html($value) ?>"><?= escape_html($label) ?></option><?php endforeach; ?></select></div>
                    <div class="form-field"><label for="feedback-issue">2. 最大的问题是什么？</label><select class="input" id="feedback-issue" name="issue_type" required><option value="">请选择</option><?php foreach ($feedbackService->issueTypes() as $value => $label): ?><option value="<?= escape_html($value) ?>"><?= escape_html($label) ?></option><?php endforeach; ?></select></div>
                    <fieldset class="form-field full feedback-rating-field"><legend>3. 你对这次体验满意吗？</legend><div class="feedback-rating-options"><?php foreach ([1 => '很不满意', 2 => '不满意', 3 => '一般', 4 => '满意', 5 => '很满意'] as $value => $label): ?><label><input type="radio" name="rating" value="<?= $value ?>" required><span><strong><?= $value ?></strong><small><?= escape_html($label) ?></small></span></label><?php endforeach; ?></div></fieldset>
                    <div class="form-field full"><label for="feedback-improvement">4. 最希望我们优先改进什么？</label><select class="input" id="feedback-improvement" name="improvement_type" required><option value="">请选择一个最重要的方向</option><?php foreach ($feedbackService->improvementTypes() as $value => $label): ?><option value="<?= escape_html($value) ?>"><?= escape_html($label) ?></option><?php endforeach; ?></select></div>
                    <div class="form-field full"><label for="desired-improvement">5. 你希望具体改成什么样？</label><textarea class="input" id="desired-improvement" name="desired_improvement" rows="5" minlength="10" maxlength="2000" required placeholder="例如：卖点图不要只复制原图，希望自动放大商品局部，并用准确的中文标注材质和结构"></textarea><small>越具体越容易进入开发计划，建议写清当前结果和期望结果。</small></div>
                    <div class="form-field full"><label for="usage-context">补充说明 <span class="optional-label">选填</span></label><textarea class="input" id="usage-context" name="usage_context" rows="3" maxlength="2000" placeholder="可以描述操作步骤、报错内容，或哪些生成结果没有达到预期"></textarea></div>
                    <div class="form-field"><label for="related-reference">关联任务编号或 SKU <span class="optional-label">选填</span></label><input class="input" id="related-reference" name="related_reference" maxlength="190" placeholder="例如：任务 #128 或 SKU DRESS-001"></div>
                    <div class="form-field feedback-contact-field"><label class="toggle-field"><input type="checkbox" name="allow_contact" value="1"><span><strong>允许运营人员通过绑定邮箱回访</strong><small><?= escape_html($user['email']) ?></small></span></label></div>
                </div>
                <div class="form-footer"><button class="button primary" type="submit"><i data-lucide="send"></i><span>提交反馈</span></button></div>
            </form>
        </div>
    </section>
    <aside class="panel feedback-guide-panel">
        <div class="panel-header"><h3>什么样的反馈最有帮助</h3></div>
        <div class="panel-body notice-list">
            <div class="notice-item"><i data-lucide="route"></i><div><strong>说明操作入口</strong><span>从哪个页面开始，选择了什么平台、素材类型或图片数量。</span></div></div>
            <div class="notice-item"><i data-lucide="image"></i><div><strong>描述结果差异</strong><span>当前生成结果哪里不对，以及你真正想看到的画面效果。</span></div></div>
            <div class="notice-item"><i data-lucide="badge-check"></i><div><strong>只选最优先的一项</strong><span>系统会统计大家最希望改进的方向，用于安排提示词和功能优化顺序。</span></div></div>
        </div>
    </aside>
</div>
<section class="panel">
    <div class="panel-header"><div><h3>我的反馈</h3><p>最近 <?= count($feedbackRows) ?> 条及处理进度</p></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>编号</th><th>功能</th><th>评分</th><th>最希望改进</th><th>建议摘要</th><th>处理状态</th><th>提交时间</th></tr></thead><tbody>
    <?php foreach ($feedbackRows as $row): ?><tr><td class="code-value">#<?= (int) $row['id'] ?></td><td><?= escape_html($feedbackService->label($feedbackService->modules(), $row['module'])) ?><?php if ($row['related_reference']): ?><small><?= escape_html($row['related_reference']) ?></small><?php endif; ?></td><td><span class="feedback-score"><?= (int) $row['rating'] ?> / 5</span></td><td><?= escape_html($feedbackService->label($feedbackService->improvementTypes(), $row['improvement_type'])) ?></td><td title="<?= escape_html($row['desired_improvement']) ?>"><?= escape_html(mb_strimwidth((string) $row['desired_improvement'], 0, 58, '...')) ?></td><td><span class="badge <?= escape_html($row['status']) ?>"><?= escape_html($feedbackService->label($feedbackService->statuses(), $row['status'])) ?></span></td><td><?= escape_html($row['created_at']) ?></td></tr><?php endforeach; ?>
    <?php if (!$feedbackRows): ?><tr><td colspan="7"><div class="empty-state"><strong>还没有提交过反馈</strong><span>你提出的实际问题会直接进入产品改进清单。</span></div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php require __DIR__ . '/../partials/merchant_footer.php'; ?>
