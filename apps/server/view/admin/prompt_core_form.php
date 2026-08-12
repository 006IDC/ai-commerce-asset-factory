<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<div class="page-actions">
    <div>
        <p class="eyebrow"><?= escape_html((string) $prompt['product_name']) ?></p>
        <h2><?= escape_html((string) $prompt['name']) ?></h2>
        <p><?= escape_html(chinese_sentence((string) $prompt['display_description'])) ?> 影响范围：<?= escape_html(chinese_sentence((string) $prompt['impact_scope'])) ?></p>
    </div>
    <a class="button" href="/admin/prompts?product=<?= rawurlencode((string) $prompt['product_code']) ?>">返回当前业务</a>
</div>
<section class="panel prompt-simple-editor">
    <div class="panel-header"><div><h3>完整提示词模板</h3><p>保存后，后续新任务直接读取这份模板。</p></div></div>
    <div class="panel-body">
        <form method="post" action="/admin/prompts/core/<?= rawurlencode((string) $prompt['product_code']) ?>/<?= (int) $prompt['id'] ?>">
            <input type="hidden" name="_token" value="<?= escape_html((string) $csrfToken) ?>">
            <div class="form-field full">
                <div class="prompt-content-heading"><label for="core-prompt-content">提示词内容</label><span><?= mb_strlen((string) $prompt['content']) ?> / 20000</span></div>
                <textarea class="input prompt-content-input" id="core-prompt-content" name="content" maxlength="20000" required><?= escape_html((string) $prompt['content']) ?></textarea>
            </div>
            <div class="form-footer">
                <?php if (!empty($prompt['is_system']) && !empty($prompt['is_customized'])): ?><button class="button" type="submit" formaction="/admin/prompts/<?= (int) $prompt['id'] ?>/restore" data-confirm-message="确认恢复系统默认提示词吗？">恢复默认</button><?php endif; ?>
                <button class="button primary" type="submit">保存模板</button>
            </div>
        </form>
    </div>
</section>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
