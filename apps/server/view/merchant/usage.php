<?php require __DIR__ . '/../partials/merchant_header.php'; ?>
<div class="page-actions"><div><h2>点数余额与用量</h2><p>订阅决定可用权益和上限，点数用于按次识别、生成与 AI 工具。</p></div><a class="button primary" href="/workspace/plans"><i data-lucide="crown"></i><span>查看会员套餐</span></a></div>
<section class="stats-grid">
    <article class="stat green"><small>可用点数</small><strong><?= number_format((int) $merchant['credit_balance']) ?></strong><span>可购买套餐或按次使用</span></article>
    <article class="stat"><small>点数流水</small><strong><?= count($ledger) ?></strong><span>最近 100 条</span></article>
    <article class="stat blue"><small>累计业务消耗</small><strong><?= number_format((int) $usedCredits) ?></strong><span>生成、识别与处罚</span></article>
</section>
<?php if ($subscription): ?><div class="gateway-state configured"><strong>当前订阅：<?= escape_html($subscription['plan_name']) ?></strong><p>有效期至 <?= escape_html($subscription['expires_at']) ?>。订阅权益与点数余额独立：套餐内免费的能力不会扣点，超出权益或按次能力才使用点数。</p></div><?php else: ?><div class="gateway-state"><strong>当前未开通会员套餐</strong><p>仍可使用点数按次创建商品资料、生成图片和使用 AI 工具。</p></div><?php endif; ?>
<section class="billing-explainer-grid panel-gap" aria-label="任务计费说明">
    <div><strong>1. 预估</strong><small>提交前按任务类型、数量和套餐权益显示预计点数。</small></div>
    <div><strong>2. 冻结</strong><small>任务创建后先预留点数，避免并发任务透支余额。</small></div>
    <div><strong>3. 实际扣除</strong><small>任务完成后只结算实际成功处理的项目。</small></div>
    <div><strong>4. 失败退回</strong><small>未生成成功的部分自动释放或退回，不重复扣费。</small></div>
</section>
<section class="panel">
    <div class="panel-header"><div><h3>兑换点数卡密</h3><p>卡密成功兑换后立即计入点数余额</p></div></div>
    <div class="panel-body"><form method="post" action="/workspace/usage/redeem"><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><div class="form-grid"><div class="form-field full"><label for="card_code">卡密</label><input class="input code-value" id="card_code" name="code" autocomplete="off" placeholder="AF-XXXX-XXXX-XXXX-XXXX" required></div></div><div class="form-footer"><button class="button primary" type="submit">兑换点数</button></div></form></div>
</section>
<section class="panel">
    <div class="panel-header"><div><h3>点数流水</h3><p>充值、发放、预留、扣除和退回</p></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>类型</th><th>变动</th><th>变动后余额</th><th>来源</th><th>备注</th><th>时间</th></tr></thead><tbody>
    <?php foreach ($ledger as $row): ?><tr><td><?= escape_html(ledger_type_label($row['type'])) ?></td><td class="<?= (int) $row['amount'] >= 0 ? 'amount-positive' : 'amount-negative' ?>"><?= (int) $row['amount'] > 0 ? '+' : '' ?><?= (int) $row['amount'] ?></td><td><?= (int) $row['balance_after'] ?></td><td><?= escape_html(ledger_source_label($row['source'])) ?></td><td><?= escape_html($row['note'] ?: '-') ?></td><td><?= escape_html($row['created_at']) ?></td></tr><?php endforeach; ?>
    <?php if (!$ledger): ?><tr><td colspan="6"><div class="empty-state">暂无点数流水</div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php require __DIR__ . '/../partials/merchant_footer.php'; ?>
