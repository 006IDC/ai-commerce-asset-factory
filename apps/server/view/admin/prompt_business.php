<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<?php $product = (array) $business['product']; $visiblePrompts = (array) ($business['visible_prompts'] ?? []); ?>
<div class="page-actions">
    <div><p class="eyebrow">提示词模板</p><h2><?= escape_html((string) $product['display_name']) ?></h2><p>这里维护生成请求直接使用的完整提示词模板。</p></div>
    <a class="button" href="/admin/prompts">返回提示词管理</a>
</div>
<section class="panel">
    <div class="panel-header"><div><h3>模板列表</h3><p>每个任务类型对应一份完整模板，不再经过组件拼装或候选发布。</p></div><span class="badge completed"><?= count($visiblePrompts) ?> 个模板</span></div>
    <div class="prompt-template-list">
        <?php foreach ($visiblePrompts as $prompt): $content = trim((string) ($prompt['content'] ?? '')); ?>
        <article class="prompt-template-card">
            <header>
                <div><strong><?= escape_html((string) $prompt['name']) ?></strong><small><?= escape_html(chinese_sentence((string) $prompt['display_description'])) ?></small></div>
                <span class="badge completed"><?= escape_html((string) $prompt['display_status']) ?></span>
                <a class="button small primary" href="/admin/prompts/core/<?= rawurlencode((string) $product['code']) ?>/<?= (int) $prompt['id'] ?>/edit">编辑模板</a>
            </header>
            <p><b>影响任务</b><?= escape_html(chinese_sentence((string) $prompt['impact_scope'])) ?></p>
            <details><summary>查看完整模板</summary><pre><?= escape_html($content) ?></pre></details>
        </article>
        <?php endforeach; ?>
        <?php if (!$visiblePrompts): ?><div class="empty-state">当前业务没有可编辑的提示词模板。</div><?php endif; ?>
    </div>
</section>
<?php if ((string) $product['code'] === 'free_create'): ?><section class="panel"><div class="panel-header"><div><h3>自由创作灵感模板</h3><p>灵感模板只辅助用户填写创作想法。</p></div><a class="button small" href="/admin/prompts?group=inspiration">管理灵感模板</a></div></section><?php endif; ?>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
