<?php require __DIR__ . '/../partials/merchant_header.php'; ?>
<div class="page-actions image-tools-heading">
    <div><h2>图片工具</h2><p>本地工具不上传、不消耗点数；需要 AI 的画布编辑会保留任务和素材版本。</p></div>
    <a class="button" href="/workspace/assets"><i data-lucide="images"></i><span>素材管理</span></a>
</div>

<section class="image-tools-workbench" data-image-tools data-csrf-token="<?= escape_html($csrfToken) ?>" data-reverse-endpoint="/workspace/tools/reverse-prompt" data-canvas-url="/workspace/canvas">
    <div class="image-tools-local-source">
        <div class="image-tools-source-copy"><span>本地处理</span><h3>上传图片，即刻处理</h3><p>高质量放大、色彩增强、压缩和拼接都在当前浏览器完成。</p></div>
        <label class="image-tools-upload" for="image-tools-file"><input id="image-tools-file" type="file" accept="image/jpeg,image/png,image/webp" multiple data-image-tools-file><i data-lucide="plus"></i><span>选择图片</span><small data-image-tools-file-label>支持 JPG、PNG、WebP</small></label>
        <div class="image-tools-preview" data-image-tools-preview hidden><img alt="待处理图片" data-image-tools-preview-image><button class="icon-button" type="button" data-image-tools-clear aria-label="移除本地图片" data-tooltip="移除图片"><i data-lucide="x"></i></button></div>
    </div>

    <div class="image-tools-local-panel">
        <div class="image-tools-section-heading"><div><span>无需 AI</span><h3>本地图片处理</h3></div><small data-image-tools-local-status>选择图片后开始处理</small></div>
        <div class="image-tools-grid local">
            <button class="image-tool-card" type="button" data-image-tools-local="upscale_2x"><i data-lucide="zoom-in"></i><span>高质量放大 x2</span><small>本地重采样</small></button>
            <button class="image-tool-card" type="button" data-image-tools-local="upscale_4k"><i data-lucide="maximize-2"></i><span>高质量放大 4K</span><small>最长边 4096 px</small></button>
            <button class="image-tool-card" type="button" data-image-tools-local="upscale_8k"><i data-lucide="scan-line"></i><span>高质量放大 8K</span><small>最长边 7680 px</small></button>
            <button class="image-tool-card" type="button" data-image-tools-local="clarity"><i data-lucide="sparkles"></i><span>清晰度增强</span><small>对比和细节优化</small></button>
            <button class="image-tool-card" type="button" data-image-tools-local="color"><i data-lucide="palette"></i><span>图片色彩增强</span><small>饱和度和层次</small></button>
            <button class="image-tool-card" type="button" data-image-tools-local="compress"><i data-lucide="archive"></i><span>压缩图片</span><small>优化 JPG 体积</small></button>
            <button class="image-tool-card" type="button" data-image-tools-local="collage"><i data-lucide="grid-2x2"></i><span>多图拼接</span><small>最多 6 张</small></button>
        </div>
        <div class="image-tools-result" data-image-tools-result hidden><div><img alt="本地处理结果" data-image-tools-result-image><div><strong data-image-tools-result-title>处理完成</strong><small data-image-tools-result-meta></small></div></div><a class="button primary" href="#" download="图片工具结果.jpg" data-image-tools-download><i data-lucide="download"></i><span>下载图片</span></a></div>
    </div>

    <div class="image-tools-ai-panel">
        <div class="image-tools-section-heading"><div><span>需要 AI</span><h3>素材库 AI 工具</h3></div><small>结果会归档到素材库并同步到任务记录</small></div>
        <?php if (!$assets): ?>
            <div class="empty-state"><strong>素材库暂无可用于 AI 处理的图片</strong><a class="button primary" href="/workspace/generate">生成或上传素材</a></div>
        <?php else: ?>
            <?php $currentTextResult = $textResults[0] ?? null; ?>
            <div class="image-tools-ai-layout">
                <div class="image-tools-ai-actions">
                    <div class="image-tools-ai-source"><label for="image-tools-asset">处理素材</label><select class="input" id="image-tools-asset" data-image-tools-asset><?php foreach ($assets as $asset): ?><option value="<?= (int) $asset['id'] ?>" data-image="<?= escape_html($asset['file_url']) ?>" data-backup="<?= escape_html($asset['backup_file_url'] ?? '') ?>"><?= escape_html(($asset['sku_code'] ?: '未归类') . ' · ' . ($asset['sku_name'] ?: '素材') . ' · ' . asset_type_label($asset['asset_type']) . ' · ' . $asset['created_at']) ?></option><?php endforeach; ?></select><div class="image-tools-asset-preview"><img src="<?= escape_html($assets[0]['file_url']) ?>" data-image-tools-asset-preview alt="选中的素材"></div></div>
                    <div class="image-tools-grid ai">
                        <button class="image-tool-card" type="button" data-image-tools-ai="cutout"><i data-lucide="scan-line"></i><span>AI 一键抠图</span><small>保留主体，重做背景</small></button>
                        <button class="image-tool-card" type="button" data-image-tools-ai="portrait_clear"><i data-lucide="scan-face"></i><span>人像变清晰</span><small>修复面部与肤质</small></button>
                        <button class="image-tool-card" type="button" data-image-tools-ai="head_cutout"><i data-lucide="contact-round"></i><span>头部抠图</span><small>适合头像与证件照</small></button>
                        <button class="image-tool-card" type="button" data-image-tools-ai="erase"><i data-lucide="eraser"></i><span>区域涂抹擦除</span><small>涂抹后局部重绘</small></button>
                        <button class="image-tool-card" type="button" data-image-tools-ai="watermark_remove"><i data-lucide="eraser"></i><span>自动去水印</span><small>仅限自有或授权素材</small></button>
                        <button class="image-tool-card" type="button" data-image-tools-ai="translate"><i data-lucide="languages"></i><span>图片翻译</span><small>保留版式替换文字</small></button>
                        <button class="image-tool-card" type="button" data-image-tools-ai="colorize"><i data-lucide="paintbrush"></i><span>黑白照片上色</span><small>智能恢复自然色彩</small></button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/../partials/merchant_footer.php'; ?>
