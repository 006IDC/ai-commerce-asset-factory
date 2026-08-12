<?php require __DIR__ . '/../partials/merchant_header.php'; ?>
<div class="page-actions">
    <div><h2>生成质量申诉</h2><p>只处理已经成功生成并扣费、但结果质量不符合要求的单张图片；技术失败会由系统自动结算。</p></div>
</div>
<div class="feedback-layout">
    <section class="panel">
        <div class="panel-header"><div><h3>申请人工复核</h3><p>提交后会保留本张图片的任务、创作要求和生成记录</p></div><span class="badge active">7 天内可申请</span></div>
        <div class="panel-body">
            <?php if ($eligibleAssets): ?>
            <form method="post" action="/workspace/quality-appeals" data-submit-loading data-loading-message="正在锁定质量证据并提交...">
                <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
                <div class="form-grid">
                    <div class="form-field full"><label for="quality-asset">生成结果</label><select class="input" id="quality-asset" name="asset_id" required><option value="">请选择一张图片</option><?php foreach ($eligibleAssets as $asset): ?><option value="<?= (int) $asset['id'] ?>" <?= $selectedAssetId === (int) $asset['id'] ? 'selected' : '' ?>><?= escape_html((string) $asset['job_no']) ?> · <?= escape_html(asset_type_label((string) $asset['asset_type'])) ?> · <?= (int) $asset['charged_credits'] ?> 点</option><?php endforeach; ?></select><small>列表仅显示最近 7 天、已实际扣费且未退款的成功图片。</small></div>
                    <div class="form-field full"><label for="quality-issue">质量问题</label><select class="input" id="quality-issue" name="issue_type" required><option value="">请选择最主要的问题</option><?php foreach (\app\service\GenerationQualityAppealService::ISSUE_TYPES as $value => $label): ?><option value="<?= escape_html($value) ?>"><?= escape_html($label) ?></option><?php endforeach; ?></select></div>
                    <div class="form-field full"><label for="quality-description">具体说明</label><textarea class="input" id="quality-description" name="description" rows="6" minlength="10" maxlength="2000" required placeholder="请说明哪一处与商品、构图、排版或画面要求不一致。"></textarea><small>审核通过后只退回这一张图片实际消耗的额度，不影响同一任务中的其他结果。</small></div>
                </div>
                <div class="form-footer"><button class="button primary" type="submit"><i data-lucide="shield-check"></i><span>提交人工复核</span></button></div>
            </form>
            <?php else: ?>
            <div class="empty-state"><strong>当前没有可申诉的生成结果</strong><span>可申诉结果必须在 7 天内成功生成、已扣费且尚未退款。</span><a class="button" href="/workspace/assets">查看素材</a></div>
            <?php endif; ?>
        </div>
    </section>
    <aside class="panel feedback-guide-panel">
        <div class="panel-header"><h3>退款边界</h3></div>
        <div class="panel-body notice-list">
            <div class="notice-item"><i data-lucide="server-off"></i><div><strong>生成失败自动处理</strong><span>未返回有效图片或结果缺失时，不需要在这里申诉。</span></div></div>
            <div class="notice-item"><i data-lucide="image-off"></i><div><strong>质量问题人工判断</strong><span>商品变形、构图错误、文字不可读等成功结果由管理员结合证据复核。</span></div></div>
            <div class="notice-item"><i data-lucide="badge-check"></i><div><strong>逐张精确退款</strong><span>同一套图只退审核通过的那一张，幂等账本确保不会重复退款。</span></div></div>
        </div>
    </aside>
</div>
<section class="panel">
    <div class="panel-header"><div><h3>我的质量申诉</h3><p>最近 <?= count($appealRows) ?> 条</p></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>申诉编号</th><th>图片</th><th>问题</th><th>任务</th><th>涉及额度</th><th>处理状态</th><th>回复</th><th>提交时间</th></tr></thead><tbody>
    <?php foreach ($appealRows as $row): ?><tr><td class="code-value"><?= escape_html((string) $row['appeal_no']) ?></td><td><?php $imageUrl = (string) (($row['file_url'] ?? '') ?: ($row['backup_file_url'] ?? '')); if ($imageUrl !== ''): ?><a href="<?= escape_html($imageUrl) ?>" target="_blank" rel="noopener"><img src="<?= escape_html($imageUrl) ?>" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:8px"></a><?php else: ?>-<?php endif; ?></td><td><?= escape_html(\app\service\GenerationQualityAppealService::issueLabel((string) $row['issue_type'])) ?><small><?= escape_html(mb_strimwidth((string) $row['description'], 0, 60, '...')) ?></small></td><td><?= escape_html((string) ($row['job_no'] ?? '-')) ?></td><td><?= (int) $row['charged_credits'] ?> 点</td><td><span class="badge <?= escape_html((string) $row['status']) ?>"><?= escape_html(\app\service\GenerationQualityAppealService::STATUSES[(string) $row['status']] ?? (string) $row['status']) ?></span></td><td><?= escape_html((string) (($row['user_visible_reply'] ?? '') ?: '等待处理')) ?></td><td><?= escape_html((string) $row['submitted_at']) ?></td></tr><?php endforeach; ?>
    <?php if (!$appealRows): ?><tr><td colspan="8"><div class="empty-state"><strong>还没有质量申诉</strong><span>如果成功结果明显不符合创作要求，可以在 7 天内申请人工复核。</span></div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php require __DIR__ . '/../partials/merchant_footer.php'; ?>
