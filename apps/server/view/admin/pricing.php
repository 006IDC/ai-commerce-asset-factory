<?php require __DIR__ . '/../partials/admin_header.php'; ?>
<div class="page-actions"><div><h2>商户侧定价</h2><p>这里只设置业务向商户扣除的点数；上游账号、渠道、额度和成本统一由 Sub2API 管理。</p></div></div>
<section class="stats-grid">
    <article class="stat blue"><small>单张图片生成</small><strong><?= number_format((int) $configuration['imageGenerationCredits']) ?></strong><span>点数 / 张</span></article>
    <article class="stat canvas"><small>单次 AI 画布编辑</small><strong><?= number_format((int) $configuration['canvasEditCredits']) ?></strong><span>点数 / 次</span></article>
    <article class="stat green"><small>单次视频生成</small><strong><?= number_format((int) $configuration['videoGenerationCreditsPerSecond']) ?></strong><span>点数 / 秒</span></article>
    <article class="stat amber"><small>平台辅助能力</small><strong>免费</strong><span>商品识别与提示词润色</span></article>
</section>
<section class="panel">
    <div class="panel-header"><div><h3>全局基础售价</h3><p>新任务在创建时锁定价格，Sub2API 的上游成本变化不会自动改动这里的商户售价。</p></div></div>
    <div class="panel-body">
        <form method="post" action="/admin/pricing/settings">
            <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
            <div class="form-grid">
                <div class="form-field">
                    <label for="image_generation_credits">每张图片生成费用</label>
                    <input class="input" id="image_generation_credits" name="image_generation_credits" type="number" min="0" max="1000000" step="1" value="<?= (int) $configuration['imageGenerationCredits'] ?>" required>
                    <small>一个任务选择多种素材时，按实际图片张数乘以此价格。</small>
                </div>
                <div class="form-field">
                    <label for="video_generation_credits_per_second">每秒视频生成费用</label>
                    <input class="input" id="video_generation_credits_per_second" name="video_generation_credits_per_second" type="number" min="0" max="1000000" step="1" value="<?= (int) $configuration['videoGenerationCreditsPerSecond'] ?>" required>
                    <small>按任务实际计费时长计算，创建任务时锁定单价。</small>
                </div>
                <div class="form-field">
                    <label for="canvas_edit_credits">每次 AI 画布编辑费用</label>
                    <input class="input" id="canvas_edit_credits" name="canvas_edit_credits" type="number" min="0" max="1000000" step="1" value="<?= (int) $configuration['canvasEditCredits'] ?>" required>
                    <small>整图编辑和局部重绘按次计费；仅调整图片比例为本地处理，永久免费。</small>
                </div>
            </div>
            <div class="form-footer"><button class="button primary" type="submit">保存计费设置</button></div>
        </form>
    </div>
</section>
<section class="panel">
    <div class="panel-header"><div><h3>计费规则</h3><p>任务创建、余额校验和点数流水使用同一价格口径。</p></div></div>
    <div class="panel-body"><div class="notice-list">
        <div class="notice-item"><i data-lucide="lock-keyhole"></i><div><strong>生图价格在任务创建时锁定</strong><span>后续修改价格不会影响已经提交或正在排队的任务。</span></div></div>
        <div class="notice-item"><i data-lucide="paintbrush"></i><div><strong>AI 编辑独立计费，尺寸调整免费</strong><span>AI 画布价格不跟随普通生图价格；仅转换图片比例不调用模型、不扣点数。</span></div></div>
        <div class="notice-item"><i data-lucide="sparkles"></i><div><strong>平台辅助能力免费</strong><span>商品识别和提示词润色由平台承担，不向商户扣除点数。</span></div></div>
        <div class="notice-item"><i data-lucide="network"></i><div><strong>上游成本由 Sub2API 管理</strong><span>ThinkPHP 只负责商户点数售价、扣费、结算和退款审计。</span></div></div>
    </div></div>
</section>
<?php require __DIR__ . '/../partials/admin_footer.php'; ?>
