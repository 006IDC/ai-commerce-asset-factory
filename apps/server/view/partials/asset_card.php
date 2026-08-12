<?php
$assetId = (int) $asset['id'];
$assetTitle = asset_type_label((string) $asset['asset_type']);
$assetVersionLabel = 'V' . max(1, (int) ($assetVersion ?? 1));
$assetProductLabel = trim((string) ($group['skuName'] ?? $asset['sku_name'] ?? '未归类素材'));
$assetSourceLabel = trim((string) ($group['sourceLabel'] ?? '生成素材'));
$assetBatchLabel = trim((string) ($batch['displayLabel'] ?? '生成批次'));
$isVideo = (string) ($asset['media_type'] ?? 'image') === 'video';
$assetMeta = implode(' · ', array_filter([
    $assetProductLabel,
    $assetTitle,
    $assetVersionLabel,
    $assetSourceLabel,
    (string) ($asset['created_at'] ?? ''),
]));
?>
<article class="asset-card <?= (int) $asset['is_favorite'] === 1 ? 'is-favorite' : '' ?>" data-asset-card-id="<?= $assetId ?>">
    <label class="asset-select" title="选择素材">
        <input type="checkbox" name="asset_ids[]" value="<?= $assetId ?>" form="asset-bulk-form" data-asset-checkbox>
        <span></span>
    </label>
    <?php if ((int) $asset['is_favorite'] === 1): ?><span class="asset-favorite-mark" title="已收藏"><i data-lucide="star"></i></span><?php endif; ?>
    <?php if ($isVideo): ?>
        <div class="asset-image asset-video">
            <video controls preload="metadata" src="<?= escape_html($asset['file_url']) ?>"<?= trim((string) ($asset['poster_url'] ?? '')) !== '' ? ' poster="' . escape_html((string) $asset['poster_url']) . '"' : '' ?> aria-label="播放 <?= escape_html($assetTitle) ?>"></video>
            <span class="asset-video-duration"><i data-lucide="clapperboard"></i><?= max(1, (int) ($asset['duration_seconds'] ?? 0)) ?> 秒</span>
        </div>
    <?php else: ?>
        <button class="asset-image" type="button" data-asset-preview-src="<?= escape_html($asset['file_url']) ?>" data-asset-preview-fallback="<?= escape_html($asset['backup_file_url'] ?: '') ?>" data-asset-preview-title="<?= escape_html($assetTitle . ' · ' . $assetVersionLabel) ?>" data-asset-preview-group="<?= escape_html($assetPreviewGroup ?? 'all-assets') ?>" data-asset-preview-id="<?= $assetId ?>" aria-label="查看 <?= escape_html($assetTitle) ?> 原图">
            <img src="<?= escape_html($asset['file_url']) ?>" data-fallback="<?= escape_html($asset['backup_file_url'] ?: '') ?>" alt="<?= escape_html($assetTitle) ?>" loading="lazy">
        </button>
    <?php endif; ?>
    <div class="asset-info">
        <strong><?= escape_html($assetTitle) ?></strong>
        <small><?= escape_html($assetVersionLabel . ' · ' . $assetBatchLabel) ?></small>
        <span><?= $trash ? '自动清理：' . escape_html($asset['purge_deadline']) : escape_html($asset['created_at']) ?></span>
    </div>
    <div class="asset-controls">
        <?php if ($trash): ?>
            <form method="post" action="/workspace/assets/<?= $assetId ?>/restore">
                <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
                <input type="hidden" name="return_to" value="<?= escape_html($returnTo) ?>">
                <button class="button small" type="submit"><i data-lucide="undo-2"></i><span>恢复</span></button>
            </form>
            <details class="asset-card-menu" data-dismiss-on-outside>
                <summary aria-label="更多操作" title="更多操作"><i data-lucide="ellipsis"></i></summary>
                <div>
                    <form method="post" action="/workspace/assets/<?= $assetId ?>/purge" data-confirm-message="确定彻底删除这张素材吗？图片文件将被清理，且无法恢复。">
                        <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
                        <input type="hidden" name="return_to" value="<?= escape_html($returnTo) ?>">
                        <button class="danger-text" type="submit"><i data-lucide="trash-2"></i><span>彻底删除</span></button>
                    </form>
                </div>
            </details>
        <?php else: ?>
            <a class="button small" href="/workspace/assets/<?= $assetId ?>/download"><i data-lucide="download"></i><span>下载</span></a>
            <?php if (!$isVideo): ?><button class="button small icon-only" type="button" aria-label="查看素材详情" title="查看素材详情"
                data-asset-detail
                data-asset-detail-src="<?= escape_html($asset['file_url']) ?>"
                data-asset-detail-fallback="<?= escape_html($asset['backup_file_url'] ?: '') ?>"
                data-asset-detail-title="<?= escape_html($assetTitle . ' · ' . $assetVersionLabel) ?>"
                data-asset-detail-meta="<?= escape_html($assetMeta) ?>"
                data-asset-detail-download-url="/workspace/assets/<?= $assetId ?>/download">
                <i data-lucide="info"></i>
            </button><?php endif; ?>
            <details class="asset-card-menu" data-dismiss-on-outside>
                <summary aria-label="更多操作" title="更多操作"><i data-lucide="ellipsis"></i></summary>
                <div>
                    <?php if (!$isVideo): ?><a href="/workspace/canvas?asset_id=<?= $assetId ?>"><i data-lucide="paintbrush"></i><span>画布编辑</span></a><?php endif; ?>
                    <form method="post" action="/workspace/assets/<?= $assetId ?>/favorite">
                        <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
                        <input type="hidden" name="return_to" value="<?= escape_html($returnTo) ?>">
                        <button type="submit"><i data-lucide="<?= (int) $asset['is_favorite'] === 1 ? 'star-off' : 'star' ?>"></i><span><?= (int) $asset['is_favorite'] === 1 ? '取消收藏' : '收藏' ?></span></button>
                    </form>
                    <form method="post" action="/workspace/assets/<?= $assetId ?>/delete" data-confirm-message="确定把这张素材移入回收站吗？">
                        <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
                        <input type="hidden" name="return_to" value="<?= escape_html($returnTo) ?>">
                        <button class="danger-text" type="submit"><i data-lucide="trash-2"></i><span>移入回收站</span></button>
                    </form>
                </div>
            </details>
        <?php endif; ?>
    </div>
</article>
