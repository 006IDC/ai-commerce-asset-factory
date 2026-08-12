<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<?php
$evidence = (array) ($appeal['evidence'] ?? []);
$itemEvidence = (array) ($evidence['item'] ?? []);
$jobEvidence = (array) ($evidence['job'] ?? []);
$imageUrl = (string) (($appeal['file_url'] ?? '') ?: ($appeal['backup_file_url'] ?? ''));
$referenceImages = (array) ($jobEvidence['reference_images'] ?? []);
$primaryReference = (array) ($referenceImages[0] ?? []);
$referenceUrl = (string) (($primaryReference['url'] ?? '') ?: ($primaryReference['backup_url'] ?? ''));
$attempts = (array) ($evidence['execution_attempts'] ?? []);
$latestAttempt = (array) ($attempts ? $attempts[array_key_last($attempts)] : []);
?>
<div class="page-actions"><div><h2><?= escape_html((string) $appeal['appeal_no']) ?></h2><p><?= escape_html((string) $appeal['merchant_name']) ?> · 子任务 #<?= (int) $appeal['job_item_id'] ?> · <?= (int) $appeal['charged_credits'] ?> 点</p></div><a class="button" href="/admin/quality-appeals">返回质量问题</a></div>
<div class="feedback-layout">
    <section class="panel">
        <div class="panel-header"><div><h3>申诉证据</h3><p>证据在用户提交时一次写入。</p></div><span class="badge <?= escape_html((string) $appeal['status']) ?>"><?= escape_html(\app\service\GenerationQualityAppealService::STATUSES[(string) $appeal['status']] ?? (string) $appeal['status']) ?></span></div>
        <div class="panel-body">
            <div class="quality-evidence-compare">
                <article><strong>参考图</strong><?php if ($referenceUrl !== ''): ?><a href="<?= escape_html($referenceUrl) ?>" target="_blank" rel="noopener"><img src="<?= escape_html($referenceUrl) ?>" data-fallback="<?= escape_html((string) ($primaryReference['backup_url'] ?? '')) ?>" alt="原始参考图"></a><?php else: ?><div class="empty-state"><span>该任务没有参考图</span></div><?php endif; ?></article>
                <article><strong>实际生成结果</strong><?php if ($imageUrl !== ''): ?><a href="<?= escape_html($imageUrl) ?>" target="_blank" rel="noopener"><img src="<?= escape_html($imageUrl) ?>" alt="申诉结果"></a><?php endif; ?></article>
            </div>
            <div class="notice-list">
                <div class="notice-item"><i data-lucide="triangle-alert"></i><div><strong><?= escape_html(\app\service\GenerationQualityAppealService::issueLabel((string) $appeal['issue_type'])) ?></strong><span><?= nl2br(escape_html((string) $appeal['description'])) ?></span></div></div>
                <div class="notice-item"><i data-lucide="message-square-text"></i><div><strong>用户原始要求</strong><span><?= nl2br(escape_html((string) (($jobEvidence['original_idea'] ?? '') ?: '未记录'))) ?></span></div></div>
                <div class="notice-item"><i data-lucide="box"></i><div><strong><?= escape_html((string) $appeal['product_code']) ?> · <?= escape_html((string) ($itemEvidence['asset_type'] ?? '')) ?></strong><span>模型 <?= escape_html((string) ($jobEvidence['model'] ?? '-')) ?> · 请求 <?= escape_html((string) ($itemEvidence['request_id'] ?? '-')) ?></span></div></div>
                <div class="notice-item"><i data-lucide="workflow"></i><div><strong>请求链</strong><span>ComfyUI <?= escape_html((string) (($latestAttempt['comfyui_prompt_id'] ?? '') ?: '-')) ?> · Sub2API <?= escape_html((string) (($latestAttempt['sub2api_request_id'] ?? '') ?: '-')) ?> · 供应商 <?= escape_html((string) (($latestAttempt['provider_task_id'] ?? '') ?: '-')) ?></span></div></div>
            </div>
            <details><summary>查看完整证据 JSON</summary><pre class="code-block"><?= escape_html(json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details>
        </div>
    </section>
    <aside class="panel">
        <div class="panel-header"><h3>仲裁操作</h3></div>
        <div class="panel-body">
            <?php if (!in_array((string) $appeal['status'], ['rejected', 'refunded', 'closed'], true)): ?>
            <form method="post" action="/admin/quality-appeals/<?= (int) $appeal['id'] ?>/review" data-submit-loading data-loading-message="正在提交仲裁...">
                <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
                <div class="form-field"><label for="quality-action">处理动作</label><select class="input" id="quality-action" name="action" required><option value="begin">开始审核</option><option value="request_info">要求补充信息</option><option value="reject">驳回申诉</option><option value="approve_refund">审核通过并立即退款</option><option value="close">关闭申诉</option></select></div>
                <div class="form-field"><label for="quality-reason">内部审核依据</label><textarea class="input" id="quality-reason" name="reason" rows="5" maxlength="2000" placeholder="退款、驳回、补充信息或关闭时至少填写 4 个字符"></textarea></div>
                <div class="form-field"><label for="quality-reply">用户可见回复</label><textarea class="input" id="quality-reply" name="reply" rows="4" maxlength="2000" placeholder="说明判断结论和下一步"></textarea></div>
                <button class="button primary" type="submit" data-confirm-message="若选择审核通过，系统会立即执行逐张额度退款。确定提交吗？">提交仲裁</button>
            </form>
            <?php else: ?><div class="empty-state"><strong>申诉已结束</strong><span><?= escape_html((string) (($appeal['user_visible_reply'] ?? '') ?: ($appeal['admin_reason'] ?? ''))) ?></span></div><?php endif; ?>
        </div>
    </aside>
</div>
<section class="panel"><div class="panel-header"><h3>不可变事件记录</h3></div><div class="table-wrap"><table class="data-table"><thead><tr><th>时间</th><th>事件</th><th>操作者</th><th>说明</th></tr></thead><tbody><?php foreach ((array) $appeal['events'] as $event): ?><tr><td><?= escape_html((string) $event['created_at']) ?></td><td class="code-value"><?= escape_html((string) $event['event_type']) ?></td><td><?= escape_html((string) $event['actor_type']) ?> #<?= (int) ($event['actor_id'] ?? 0) ?></td><td><?= escape_html((string) ($event['message'] ?? '')) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
