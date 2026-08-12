<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<div class="page-actions"><div><h2>注册与内容安全</h2><p>人机验证、违规检测、处罚和警告记录。</p></div><form method="post" action="/admin/security/test"><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><button class="button" type="submit"><i data-lucide="shield-check"></i><span>测试审核服务</span></button></form></div>
<section class="stats-grid">
    <article class="stat <?= $geetestConfiguration['configured'] ? 'green' : 'amber' ?>"><small>注册与登录验证</small><strong><?= $geetestConfiguration['configured'] ? '已启用' : '待配置' ?></strong><span>极验 SenseBot 服务端二次验证</span></article>
    <article class="stat <?= $safetyConfiguration['serviceState'] === 'ready' ? 'green' : 'amber' ?>"><small>内容安全</small><strong><?= escape_html(['ready' => '审核正常', 'degraded' => '审核降级', 'local_only' => '仅本地规则', 'disabled' => '已关闭'][$safetyConfiguration['serviceState']] ?? '待检查') ?></strong><span><?= $safetyConfiguration['serviceState'] === 'degraded' ? '上次模型审核异常，当前按降级策略执行' : (!empty($safetyConfiguration['serviceNotice']) ? '备用视觉审核正在运行' : ($safetyConfiguration['externalEnabled'] ? '本地规则 + 多模态模型' : '仅本地违禁词规则')) ?></span></article>
    <article class="stat blue"><small>违规记录</small><strong><?= number_format((int) $summary['total']) ?></strong><span>累计扣除 <?= number_format((int) $summary['penalties']) ?> 点数</span></article>
    <article class="stat <?= (int) $summary['emailFailures'] > 0 ? 'amber' : '' ?>"><small>警告邮件失败</small><strong><?= number_format((int) $summary['emailFailures']) ?></strong><span>可在下方查看失败原因</span></article>
</section>
<form method="post" action="/admin/security/settings">
    <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
    <div class="content-grid settings-layout">
        <section class="panel">
            <div class="panel-header"><div><h3>极验人机验证</h3><p>覆盖注册邮件、商户登录和管理员登录</p></div><span class="badge <?= $geetestConfiguration['configured'] ? 'active' : 'pending' ?>"><?= $geetestConfiguration['configured'] ? '运行中' : '未启用' ?></span></div>
            <div class="panel-body"><div class="form-grid">
                <div class="form-field full"><label for="geetest_captcha_id">Captcha ID</label><input class="input code-value" id="geetest_captcha_id" name="geetest_captcha_id" value="<?= escape_html($geetestConfiguration['captchaId']) ?>" maxlength="200" placeholder="极验后台创建应用后获取"></div>
                <div class="form-field full"><label for="geetest_private_key">Private Key</label><input class="input code-value" id="geetest_private_key" name="geetest_private_key" type="password" maxlength="300" autocomplete="new-password" placeholder="<?= $geetestConfiguration['privateKeyConfigured'] ? '已安全保存，留空保持不变' : '填写极验 Private Key' ?>"><small>保存后加密存储，不会再次明文显示。</small></div>
            </div></div>
        </section>
        <section class="panel">
            <div class="panel-header"><div><h3>处罚规则</h3><p>同一商户的同一违规内容只扣除一次</p></div></div>
            <div class="panel-body"><div class="form-grid">
                <div class="form-field full"><label class="toggle-field"><input type="checkbox" name="content_safety_enabled" value="1" <?= $safetyConfiguration['enabled'] ? 'checked' : '' ?>><span><strong>启用内容安全审核</strong><small>关闭后不会检查 SKU 文本、上传图片和生图请求。</small></span></label></div>
                <div class="form-field full"><label class="toggle-field"><input type="checkbox" name="content_safety_external_enabled" value="1" <?= $safetyConfiguration['externalEnabled'] ? 'checked' : '' ?>><span><strong>启用多模态模型审核</strong><small>图片检测必须开启；文本会同时使用本地违禁词和模型审核。</small></span></label></div>
                <div class="form-field full"><label class="toggle-field"><input type="checkbox" name="content_safety_fail_closed" value="1" <?= $safetyConfiguration['failClosed'] ? 'checked' : '' ?>><span><strong>审核服务异常时拒绝请求</strong><small>开启后模型不可用时不允许上传或生成；关闭后仍保留本地违禁词拦截，并记录模型审核降级。</small></span></label></div>
                <div class="form-field full"><label for="content_safety_penalty_credits">每次违规处罚点数</label><input class="input" id="content_safety_penalty_credits" name="content_safety_penalty_credits" type="number" min="0" max="1000000" value="<?= (int) $safetyConfiguration['penaltyCredits'] ?>" required></div>
            </div></div>
        </section>
    </div>
    <section class="panel">
        <div class="panel-header"><div><h3>审核模型与本地规则</h3><p>模型必须先在 Sub2API 发布；ThinkPHP 只保存内容安全场景使用的模型 ID 和本地规则。</p></div></div>
        <div class="panel-body"><div class="form-grid">
            <div class="form-field"><label for="content_safety_model">兼容模式文字审核模型</label><input class="input code-value" id="content_safety_model" name="content_safety_model" value="<?= escape_html($safetyConfiguration['model']) ?>" maxlength="120"><small>当前生产主模型使用 deepseek-v4-flash。</small></div>
            <div class="form-field"><label for="content_safety_fallback_model">兼容模式图片审核模型</label><input class="input code-value" id="content_safety_fallback_model" name="content_safety_fallback_model" value="<?= escape_html($safetyConfiguration['fallbackModel']) ?>" maxlength="120" required><small>图片需要多模态模型，当前使用 glm-4.6v-flash。</small></div>
            <div class="form-field full"><label for="content_safety_terms">本地违禁词</label><textarea class="input security-terms-input" id="content_safety_terms" name="content_safety_terms" maxlength="20000"><?= escape_html($safetyConfiguration['terms']) ?></textarea><small>每行一个词组。系统会忽略空格、标点和全半角差异后匹配。</small></div>
        <?php if ($safetyConfiguration['serviceState'] === 'degraded'): ?><div class="notice warning"><i data-lucide="triangle-alert"></i><div><strong>最近一次模型审核不可用</strong><span><?= escape_html($safetyConfiguration['lastServiceError'] ?: '请点击“测试审核服务”检查 Sub2API 内容审核模型和服务密钥。') ?><?= $safetyConfiguration['lastCheckedAt'] ? '（' . escape_html($safetyConfiguration['lastCheckedAt']) . '）' : '' ?></span></div></div><?php endif; ?>
        <?php if (!empty($safetyConfiguration['serviceNotice'])): ?><div class="notice info"><i data-lucide="shield-check"></i><div><strong>审核已自动降级并保持可用</strong><span><?= escape_html($safetyConfiguration['serviceNotice']) ?><?= $safetyConfiguration['lastCheckedAt'] ? '（' . escape_html($safetyConfiguration['lastCheckedAt']) . '）' : '' ?></span></div></div><?php endif; ?>
        </div><div class="form-footer"><button class="button primary" type="submit">保存安全设置</button></div></div>
    </section>
</form>
<section class="panel">
    <div class="panel-header"><div><h3>违规记录</h3><p>最近 200 条，原始违规图片不会保存</p></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>商户</th><th>账号</th><th>场景</th><th>类型</th><th>违规分类</th><th>文本摘要</th><th>处罚</th><th>警告邮件</th><th>时间</th></tr></thead><tbody>
    <?php foreach ($violations as $row): ?><tr><td><a href="/admin/merchants/<?= (int) $row['merchant_id'] ?>"><?= escape_html($row['merchant_name'] ?: '-') ?></a></td><td><?= escape_html($row['user_email'] ?: '-') ?></td><td><?= escape_html($contentSafety->sourceLabel((string) $row['source'])) ?></td><td><?= $row['content_type'] === 'image' ? '图片' : '文本' ?></td><td><?= escape_html(implode('、', $contentSafety->categoryLabels(decode_json_list($row['categories']))) ?: '其他违规内容') ?></td><td><?= escape_html($row['content_excerpt'] ?: '-') ?></td><td><?= (int) $row['penalty_credits'] ?> 点</td><td><span class="badge <?= escape_html($row['warning_email_status']) ?>"><?= escape_html(['sent' => '已发送', 'failed' => '发送失败', 'pending' => '待发送'][$row['warning_email_status']] ?? $row['warning_email_status']) ?></span><?php if ($row['warning_error']): ?><small class="table-note"><?= escape_html($row['warning_error']) ?></small><?php endif; ?></td><td><?= escape_html($row['created_at']) ?></td></tr><?php endforeach; ?>
    <?php if (!$violations): ?><tr><td colspan="9"><div class="empty-state">暂无违规记录</div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
