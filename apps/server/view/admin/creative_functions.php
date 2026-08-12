<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<div class="page-actions"><div><p class="eyebrow">AI 设置</p><h2>创作功能</h2><p>按用户实际看到的功能管理开放状态、默认模型、核心提示词、价格与最近效果。</p></div></div>
<section class="creative-product-grid">
<?php foreach ($data['products'] as $product): $metrics=(array)($product['metrics_7d']??[]); ?>
<article class="creative-product-card"><header><span class="creative-product-icon"><i data-lucide="<?= (string)$product['execution_mode']==='comfyui'?'workflow':'wand-sparkles' ?>"></i></span><div><h3><?= escape_html((string)$product['name']) ?></h3><p><?= escape_html((string)$product['description']) ?></p></div><span class="badge <?= (string)$product['status']==='active'?'completed':'disabled' ?>"><?= (string)$product['status']==='active'?'已开放':'已关闭' ?></span></header>
<dl><div><dt>默认模型</dt><dd><?= escape_html((string)($product['default_model']['display_name']??'未设置')) ?></dd></div><div><dt>开放模型</dt><dd><?= count((array)$product['available_models']) ?> 个</dd></div><div><dt>核心提示词</dt><dd><?= count((array)$product['core_prompts']) ?> 项</dd></div><div><dt>近 7 天成功率</dt><dd><?= number_format((float)$product['success_rate'],1) ?>%</dd></div></dl>
<?php if (!empty($product['issue'])): ?><div class="creative-product-warning"><i data-lucide="triangle-alert"></i><span><?= escape_html((string)$product['issue']) ?></span></div><?php else: ?><div class="creative-product-ok"><i data-lucide="circle-check"></i><span>当前没有需要处理的问题</span></div><?php endif; ?>
<footer><span><?= escape_html(implode('、',(array)$product['capability_labels'])) ?></span><a class="button small primary" href="/admin/creative-functions/<?= rawurlencode((string)$product['code']) ?>/edit">设置</a></footer></article>
<?php endforeach; ?>
</section>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
