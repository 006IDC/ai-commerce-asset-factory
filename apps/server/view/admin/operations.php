<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<div class="page-actions"><div><h2>注册福利与商户公告</h2><p>统一配置新商户首次到账权益和工作台运营信息。</p></div></div>
<section class="stats-grid">
    <article class="stat green"><small>注册送点数</small><strong><?= number_format((int) $registrationBenefit['credits']) ?></strong><span>0 表示不赠送点数</span></article>
    <article class="stat blue"><small>注册赠送套餐</small><strong><?= escape_html($registrationBenefit['plan']['name'] ?? '不赠送') ?></strong><span><?= $registrationBenefit['plan'] ? (int) $registrationBenefit['plan']['duration_days'] . ' 天有效期' : '商户按次使用或自行购买' ?></span></article>
    <article class="stat amber"><small>商户公告</small><strong><?= $announcement['enabled'] ? '展示中' : '已关闭' ?></strong><span>显示在商户工作台首页</span></article>
</section>
<form method="post" action="/admin/operations/settings">
    <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
    <section class="panel">
        <div class="panel-header"><div><h3>新商户注册福利</h3><p>点数和套餐会在账户注册事务中一次性发放</p></div></div>
        <div class="panel-body"><div class="form-grid">
            <div class="form-field"><label for="registration_gift_credits">注册送点数</label><input class="input" id="registration_gift_credits" name="registration_gift_credits" type="number" min="0" max="1000000" step="1" value="<?= (int) $registrationBenefit['credits'] ?>" required><small>设置为 0 时不生成注册赠送点数流水。</small></div>
            <div class="form-field"><label for="registration_gift_plan_id">注册赠送套餐</label><select class="input" id="registration_gift_plan_id" name="registration_gift_plan_id"><option value="0">不赠送套餐</option><?php foreach ($plans as $plan): ?><option value="<?= (int) $plan['id'] ?>" <?= (int) $registrationBenefit['planId'] === (int) $plan['id'] ? 'selected' : '' ?>><?= escape_html($plan['name']) ?>（<?= (int) $plan['duration_days'] ?> 天<?= $plan['status'] === 'active' ? '' : '，已下架' ?>）</option><?php endforeach; ?></select><small>允许选择已下架套餐，适合只用于注册体验、不在商户端销售的套餐。</small></div>
        </div></div>
    </section>
    <section class="panel">
        <div class="panel-header"><div><h3>商户工作台公告</h3><p>用于套餐咨询、卡密购买、客服通知或临时运营信息</p></div><label class="toggle"><input type="checkbox" name="merchant_announcement_enabled" value="1" <?= $announcement['enabled'] ? 'checked' : '' ?>><span></span><strong>启用公告</strong></label></div>
        <div class="panel-body"><div class="form-grid">
            <div class="form-field"><label for="merchant_announcement_title">公告标题</label><input class="input" id="merchant_announcement_title" name="merchant_announcement_title" maxlength="120" value="<?= escape_html($announcement['title']) ?>"></div>
            <div class="form-field"><label for="merchant_announcement_contact_label">联系按钮文字</label><input class="input" id="merchant_announcement_contact_label" name="merchant_announcement_contact_label" maxlength="80" value="<?= escape_html($announcement['contactLabel']) ?>" placeholder="例如：联系微信客服"></div>
            <div class="form-field full"><label for="merchant_announcement_content">公告内容</label><textarea class="input" id="merchant_announcement_content" name="merchant_announcement_content" maxlength="1000" rows="4"><?= escape_html($announcement['content']) ?></textarea></div>
            <div class="form-field full"><label for="merchant_announcement_contact_url">联系跳转链接</label><input class="input code-value" id="merchant_announcement_contact_url" name="merchant_announcement_contact_url" maxlength="500" value="<?= escape_html($announcement['contactUrl']) ?>" placeholder="https://、mailto:、tel: 或 /workspace/usage"><small>留空时公告只显示文字；填写后显示联系按钮。</small></div>
        </div></div>
    </section>
    <div class="form-footer"><button class="button primary" type="submit">保存运营设置</button></div>
</form>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
