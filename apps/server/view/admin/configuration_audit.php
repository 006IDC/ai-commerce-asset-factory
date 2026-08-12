<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<div class="page-actions">
    <div><h2>配置审计</h2><p>只读清单：识别自动项、向导项、专家项和应退出日常维护的废弃项。</p></div>
</div>
<section class="stats-grid panel-gap" aria-label="配置分类">
    <?php foreach ($configurationSummary as $label => $count): ?><article class="stat <?= $label === '废弃' ? 'amber' : ($label === '专家' ? 'blue' : 'green') ?>"><small><?= escape_html($label) ?></small><strong><?= (int) $count ?></strong><span>配置项</span></article><?php endforeach; ?>
</section>
<section class="panel panel-gap">
    <div class="panel-header"><div><h3>配置读取契约</h3><p>密钥只显示掩码；此页面不提供直接修改入口。</p></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>配置键</th><th>读取者</th><th>分类</th><th>默认值</th><th>人工必填</th><th>修改频率</th><th>风险</th></tr></thead><tbody>
    <?php foreach ($rows as $row): ?><tr><td><code><?= escape_html((string) $row['key']) ?></code></td><td><?= escape_html((string) $row['reader']) ?></td><td><span class="badge"><?= escape_html((string) $row['classification']) ?></span></td><td><code><?= escape_html(mb_strimwidth((string) $row['default'], 0, 60, '…')) ?></code></td><td><?= $row['required'] ? '是' : '否' ?></td><td><?= escape_html((string) $row['change_frequency']) ?></td><td><span class="badge <?= $row['risk'] === '高' ? 'failed' : '' ?>"><?= escape_html((string) $row['risk']) ?></span></td></tr><?php endforeach; ?>
    </tbody></table></div>
</section>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
