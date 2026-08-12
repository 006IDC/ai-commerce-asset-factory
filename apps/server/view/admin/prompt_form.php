<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<div class="page-actions"><div><h2><?= $isEdit ? '编辑灵感模板' : '新建灵感模板' ?></h2><p>灵感模板只帮助用户填写自由创作想法。</p></div><a class="button" href="/admin/prompts?group=inspiration">返回灵感模板</a></div>
<section class="panel prompt-simple-editor">
    <div class="panel-body"><form method="post" action="<?= escape_html($formAction) ?>">
        <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
        <div class="form-grid">
            <div class="form-field full"><label for="prompt-name">模板名称</label><input class="input" id="prompt-name" name="name" maxlength="160" value="<?= escape_html((string) $template['name']) ?>" required></div>
            <div class="form-field"><label for="prompt-asset-type">分类</label><select class="input" id="prompt-asset-type" name="asset_type" <?= $isEdit ? 'disabled' : '' ?>><?php foreach ($prompts->freeCategories() as $value => $label): ?><option value="<?= escape_html($value) ?>" <?= (string) $template['asset_type'] === $value ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select></div>
            <div class="form-field full"><div class="prompt-content-heading"><label for="prompt-content">完整提示词模板</label><span><?= mb_strlen((string) $template['content']) ?> / 20000</span></div><textarea class="input prompt-content-input" id="prompt-content" name="content" maxlength="20000" required><?= escape_html((string) $template['content']) ?></textarea></div>
            <div class="form-field full"><label class="toggle-field"><input type="checkbox" name="publish" value="1" <?= (string) $template['status'] === 'active' ? 'checked' : '' ?>><span><strong>上架给用户</strong><small>关闭后保存为未上架模板。</small></span></label></div>
        </div>
        <div class="form-footer"><a class="button" href="/admin/prompts?group=inspiration">取消</a><button class="button primary" type="submit">保存模板</button></div>
    </form></div>
</section>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
