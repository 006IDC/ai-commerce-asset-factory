<?php require __DIR__ . '/../partials/merchant_header.php'; ?>
<?php
$defaultGenerationModelId = max(0, (int) ($defaultGenerationChoice['id'] ?? 0));
$defaultEditModelId = max(0, (int) ($defaultEditChoice['id'] ?? 0));
foreach ($modelOptions as $modelOption) {
    if ($defaultGenerationModelId === 0 && (string) ($modelOption['modelId'] ?? '') === (string) ($defaultGenerationChoice['modelId'] ?? '')) {
        $defaultGenerationModelId = (int) $modelOption['id'];
    }
    if ($defaultEditModelId === 0 && (string) ($modelOption['modelId'] ?? '') === (string) ($defaultEditChoice['modelId'] ?? '')) {
        $defaultEditModelId = (int) $modelOption['id'];
    }
}
$defaultGenerationModelName = trim((string) ($defaultGenerationChoice['name'] ?? $defaultGenerationChoice['modelId'] ?? ''));
$defaultEditModelName = trim((string) ($defaultEditChoice['name'] ?? $defaultEditChoice['modelId'] ?? ''));
$templateIcons = ['free_product' => 'package-open', 'free_video_cover' => 'square-play', 'free_social' => 'notebook-pen', 'free_food' => 'utensils', 'free_fashion' => 'sparkles', 'free_space' => 'sofa', 'free_campaign' => 'megaphone', 'free_portrait' => 'user-round'];
$selectedOutputPreset = isset($outputPresets[$selectedOutputPreset]) ? $selectedOutputPreset : 'square_1_1';
$selectedPreset = $outputPresets[$selectedOutputPreset];
$quantityLimit = max(1, (int) ($freeQuantityLimit ?? generation_asset_quantity_limit()));
$projectQuantity = max(1, min($quantityLimit, (int) ($freeProject['expected_result_count'] ?? 1)));
?>
<section class="free-studio" data-initial-mode="free">
    <div class="notice free-landing-draft-notice" data-landing-draft-notice hidden>
        <i data-lucide="clipboard-check"></i>
        <div><strong>已恢复公开页创作草稿</strong><p data-landing-draft-message></p></div>
    </div>
    <div class="free-chat-shell" data-free-chat-feed data-focus-job-id="<?= (int) ($freeProject['id'] ?? 0) ?>">
        <?php if ($promptResultId > 0 && $initialPrompt !== ''): ?><div class="free-prompt-source-note"><i data-lucide="file-search"></i><span>已带入图片工具生成的提示词草稿</span><a href="/workspace/tools">查看原始结果</a></div><?php endif; ?>

        <div class="free-chat-scrollport" data-free-chat-scrollport>
        <div class="free-chat-timeline">
            <?php if (!$freeConversation): ?>
                <div class="free-chat-row is-assistant free-chat-welcome">
                    <span class="free-chat-avatar is-model"><i data-lucide="sparkles"></i></span>
                    <div class="free-chat-message">
                        <strong>创作助手</strong>
                        <div class="free-chat-bubble"><p>你好，在下方描述想要的画面，也可以上传 1–6 张参考图。</p><small>系统会自动选择适合本次任务的生成服务。</small></div>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($freeConversation as $turn):
                    $turnId = (int) $turn['id'];
                    $turnStatus = (string) ($turn['status'] ?? '');
                    $turnActive = in_array($turnStatus, ['pending_gateway', 'queued', 'running', 'processing', 'retrying'], true);
                    $turnFailed = in_array($turnStatus, ['failed', 'result_missing'], true)
                        || (string) ($turn['result_status'] ?? '') === 'missing';
                    $turnTotal = max(1, (int) ($turn['expected_result_count'] ?? $turn['progress_total'] ?? 1));
                    $turnCurrent = min($turnTotal, max(0, (int) ($turn['progress_current'] ?? 0)));
                    $turnPercent = $turnTotal > 0 ? (int) floor($turnCurrent * 100 / $turnTotal) : 0;
                    $turnModel = trim((string) ($turn['display_model_name'] ?? $turn['model'] ?? '系统默认模型'));
                    $turnInitial = mb_strtoupper(mb_substr($turnModel, 0, 1));
                    $turnReferences = (array) ($turn['reference_images'] ?? []);
                    $turnAssets = (array) ($turn['assets'] ?? []);
                    $turnRatio = (int) ($turn['output_width'] ?? 0) > 0 && (int) ($turn['output_height'] ?? 0) > 0
                        ? (int) $turn['output_width'] . '×' . (int) $turn['output_height']
                        : (string) ($turn['output_preset'] ?? '');
                    $turnMessage = trim((string) ($turn['progress_message'] ?? ''))
                        ?: ($turnActive ? '正在处理你的生成请求' : (string) ($turn['result_status_label'] ?? '任务已处理'));
                ?>
                <section class="free-chat-turn<?= $turnId === (int) ($freeProject['id'] ?? 0) ? ' is-focused' : '' ?>" id="free-chat-job-<?= $turnId ?>" data-free-chat-job="<?= $turnId ?>" data-free-chat-status="<?= escape_html($turnStatus) ?>">
                    <div class="free-chat-row is-user">
                        <span class="free-chat-avatar is-user">你</span>
                        <div class="free-chat-message">
                            <strong>你</strong>
                            <div class="free-chat-bubble">
                                <?php if ($turnReferences): ?><div class="free-chat-references"><?php foreach ($turnReferences as $index => $reference): $referenceUrl = (string) (($reference['url'] ?? '') ?: ($reference['backup_url'] ?? '')); ?><a href="<?= escape_html($referenceUrl) ?>" target="_blank" rel="noopener"><img src="<?= escape_html($referenceUrl) ?>" data-fallback="<?= escape_html((string) ($reference['backup_url'] ?? '')) ?>" alt="参考图 <?= $index + 1 ?>"></a><?php endforeach; ?></div><?php endif; ?>
                                <p><?= nl2br(escape_html((string) ($turn['prompt'] ?? ''))) ?></p>
                                <small><?= escape_html($turnRatio) ?> · <?= $turnTotal ?> 张 · <?= escape_html(generation_language_label((string) ($turn['language'] ?? 'zh-CN'))) ?> · <?= escape_html((string) ($turn['created_at'] ?? '')) ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="free-chat-row is-assistant">
                        <span class="free-chat-avatar is-model"><?= escape_html($turnInitial) ?></span>
                        <div class="free-chat-message">
                            <div class="free-chat-model-heading"><strong>创作结果</strong><span class="badge <?= escape_html($turnStatus) ?>" data-free-chat-status-label><?= escape_html(job_status_label($turnStatus)) ?></span></div>
                            <div class="free-chat-bubble free-chat-model-bubble">
                                <?php if ($turnActive): ?>
                                    <div class="free-chat-thinking"><span><i></i><i></i><i></i></span><strong data-free-chat-progress-message><?= escape_html($turnMessage) ?></strong></div>
                                    <div class="free-chat-progress"><span><i data-free-chat-progress-bar style="width: <?= $turnPercent ?>%"></i></span><b data-free-chat-progress-count><?= $turnCurrent ?>/<?= $turnTotal ?> · <?= $turnPercent ?>%</b></div>
                                    <small>生成开始后不能取消，你可以稍后回来查看结果。</small>
                                <?php endif; ?>
                                <?php if ($turnAssets): ?>
                                    <div class="free-chat-results">
                                        <?php foreach ($turnAssets as $asset): ?><article><a href="<?= escape_html((string) $asset['file_url']) ?>" target="_blank" rel="noopener"><img src="<?= escape_html((string) $asset['file_url']) ?>" data-fallback="<?= escape_html((string) ($asset['backup_file_url'] ?? '')) ?>" alt="<?= escape_html($turnModel) ?> 生成结果"></a><footer><a class="free-chat-canvas-action" href="/workspace/canvas?asset_id=<?= (int) $asset['id'] ?>"><i data-lucide="scan-line"></i><span>去画布局部修改</span></a><?php if (!empty($asset['quality_appeal_eligible'])): ?><a class="free-chat-quality-action" href="/workspace/quality-appeals?asset_id=<?= (int) $asset['id'] ?>" aria-label="申请质量复核" title="申请质量复核" data-tooltip="申请质量复核"><i data-lucide="shield-alert"></i><span>质量复核</span></a><?php endif; ?><a href="/workspace/assets/<?= (int) $asset['id'] ?>/download" aria-label="下载生成图片" title="下载生成图片" data-tooltip="下载生成图片"><i data-lucide="download"></i></a></footer></article><?php endforeach; ?>
                                    </div>
                                    <p class="free-chat-result-note"><i data-lucide="circle-check"></i><span>已为你生成 <?= count($turnAssets) ?> 张图片。点击图片下方按钮，可进入画布局部修改。</span></p>
                                <?php endif; ?>
                                <?php if ($turnFailed): ?>
                                    <div class="free-chat-failure"><i data-lucide="circle-alert"></i><span><strong>这次生成没有成功</strong><small><?= escape_html((string) (($turn['error_message'] ?? '') ?: '没有获得可用结果，请手动重试。')) ?></small></span></div>
                                    <?php if ((string) ($turn['safety_status'] ?? '') !== 'blocked'): ?><form method="post" action="/workspace/jobs/<?= $turnId ?>/retry" data-submit-loading data-loading-message="正在按原设置重新提交..."><input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>"><button class="button small primary" type="submit"><i data-lucide="rotate-cw"></i><span>按原设置重新生成</span></button></form><?php endif; ?>
                                <?php elseif (!$turnActive && !$turnAssets): ?>
                                    <p data-free-chat-progress-message><?= escape_html($turnMessage) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </section>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        </div>
        <nav class="free-chat-jump-controls" data-free-chat-jump-controls aria-label="聊天记录快速定位" hidden>
            <button type="button" data-free-chat-jump="top" aria-label="跳到今天最上方" data-tooltip="今天最早"><i data-lucide="arrow-up-to-line"></i></button>
            <button type="button" data-free-chat-jump="bottom" aria-label="跳到今天最下方" data-tooltip="最新消息"><i data-lucide="arrow-down-to-line"></i></button>
        </nav>
    </div>

    <form class="free-studio-composer" method="post" action="/workspace/free-create" enctype="multipart/form-data"
          data-free-generation-form data-submit-loading data-background-submit
          data-loading-message="任务正在后台创建"
          data-unit-price="<?= (int) $generationPrice ?>"
          data-prompt-endpoint="/workspace/free-create/optimize-prompt"
          data-image-prompt-endpoint="/workspace/tools/reverse-prompt"
          data-csrf-endpoint="/workspace/csrf-token">
        <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
        <input type="hidden" name="prompt_template_id" value="" data-free-template-id>
        <input type="hidden" name="subject_text" value="" data-free-subject-text>
        <input type="hidden" name="user_addition" value="" data-free-user-addition>
        <input type="hidden" name="reference_asset_id" value="" data-free-reference-asset-id>
        <input type="hidden" name="client_submission_id" value="<?= escape_html(bin2hex(random_bytes(16))) ?>" data-free-client-submission-id>

        <label class="free-studio-reference-button" for="free-reference-image">
            <i data-lucide="image-plus"></i>
            <strong>参考图</strong>
            <small data-free-reference-count>0/6</small>
            <input id="free-reference-image" name="reference_images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple data-free-reference-input>
        </label>
        <div class="free-studio-reference-list" data-free-reference-list hidden aria-label="自由创作参考图"></div>
        <span data-free-upload-empty hidden></span>
        <span data-free-upload-preview hidden><img alt="" data-free-reference-preview><small data-free-reference-name></small></span>
        <button type="button" data-free-reference-remove hidden aria-label="清空参考图"></button>

        <div class="free-studio-prompt">
            <textarea id="free-prompt" name="prompt" rows="2" maxlength="4000" required
                      placeholder="描述这次想生成什么，例如主体、使用场景、构图、光线或画面风格。"><?= escape_html($initialPrompt) ?></textarea>
            <div class="free-studio-prompt-tools">
                <button type="button" data-open-prompt-helper aria-label="让 AI 补充创作需求" data-tooltip="AI 帮你写"><i data-lucide="wand-sparkles"></i><span>AI 帮你写</span></button>
                <button type="button" data-open-image-prompt aria-label="根据图片生成提示词" data-tooltip="看图写提示词"><i data-lucide="scan-search"></i><span>看图写提示词</span></button>
                <?php if ($freePromptTemplates): ?>
                <details class="free-studio-inspiration" data-dismiss-on-outside>
                    <summary aria-label="选择灵感模板" data-tooltip="灵感模板"><i data-lucide="layout-template"></i><span>灵感模板</span></summary>
                    <div class="free-studio-inspiration-popover">
                        <div class="free-studio-inspiration-heading"><span><strong>灵感模板</strong><small>选择后仍可继续修改描述</small></span><label><span>类型</span><select class="input" data-free-template-filter><option value="*">全部模板</option><?php foreach ($freePromptCategories as $value => $label): ?><option value="<?= escape_html($value) ?>"><?= escape_html($label) ?></option><?php endforeach; ?></select></label></div>
                        <div class="free-studio-template-list" data-free-template-grid>
                            <?php foreach ($freePromptTemplates as $template): $category = (string) $template['asset_type']; ?>
                            <button type="button" data-free-template data-template-id="<?= (int) $template['id'] ?>" data-template-category="<?= escape_html($category) ?>" data-template-content="<?= escape_html($template['content']) ?>" aria-pressed="false"><i data-lucide="<?= escape_html($templateIcons[$category] ?? 'image') ?>"></i><span><strong><?= escape_html($template['name']) ?></strong><small><?= escape_html($freePromptCategories[$category] ?? '自由创作') ?></small></span></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </details>
                <?php endif; ?>
            </div>
            <span class="free-studio-prompt-count"><b data-free-prompt-count><?= mb_strlen($initialPrompt) ?></b>/4000</span>
        </div>

        <div class="free-studio-options">
            <details class="ratio-picker ratio-picker-dropdown free-ratio-picker" data-ratio-picker data-dismiss-on-outside>
                <summary><i data-lucide="ratio"></i><span data-ratio-current><?= escape_html((string) ($selectedPreset['ratio'] ?? $selectedPreset['label'])) ?></span><i data-lucide="chevron-down"></i></summary>
                <div class="ratio-picker-popover">
                    <div class="ratio-picker-heading"><strong>图片比例</strong><small>选择画布方向和比例</small></div>
                    <select id="free-output-preset" name="output_preset" data-free-output-preset required hidden><?php foreach ($outputPresets as $value => $preset): ?><option value="<?= escape_html($value) ?>" data-width="<?= (int) $preset['width'] ?>" data-height="<?= (int) $preset['height'] ?>" <?= $value === $selectedOutputPreset ? 'selected' : '' ?>><?= escape_html($preset['label']) ?></option><?php endforeach; ?></select>
                    <div class="ratio-picker-grid" role="group" aria-label="选择图片比例"><?php foreach ($outputPresets as $value => $preset): $parts = explode(':', (string) ($preset['ratio'] ?? '1:1')); ?><button type="button" class="ratio-choice<?= $value === $selectedOutputPreset ? ' selected' : '' ?>" data-ratio-choice data-ratio-value="<?= escape_html($value) ?>" data-ratio-label="<?= escape_html($preset['ratio'] ?? $preset['label']) ?>" aria-pressed="<?= $value === $selectedOutputPreset ? 'true' : 'false' ?>"><span><?= escape_html($preset['ratio'] ?? $preset['label']) ?></span><i class="ratio-shape" style="--ratio-w: <?= (int) ($parts[0] ?? 1) ?>; --ratio-h: <?= (int) ($parts[1] ?? 1) ?>"></i></button><?php endforeach; ?></div>
                </div>
            </details>
            <div class="free-studio-option free-studio-quantity" role="group" aria-label="自由创作生成数量"><i data-lucide="images"></i><span>数量</span><?= generation_quantity_picker('quantity', $projectQuantity, $quantityLimit, 'free-quantity', ['data-free-quantity' => true], '自由创作生成数量') ?></div>
            <div class="free-studio-option" role="group" aria-label="画面语言"><i data-lucide="languages"></i><span>画面语言</span><select name="language" aria-label="画面语言" data-free-web-select data-web-select-label="画面语言"><?php foreach (generation_language_options() as $value => $label): ?><option value="<?= escape_html($value) ?>" <?= $value === $preferredLanguage ? 'selected' : '' ?>><?= escape_html($label) ?></option><?php endforeach; ?></select></div>
            <span class="free-studio-option free-studio-output-hint"><i data-lucide="scan"></i><span data-free-output-hint><?= escape_html((string) ($selectedPreset['ratio'] ?? '1:1')) ?> · <?= (int) $selectedPreset['width'] ?>×<?= (int) $selectedPreset['height'] ?></span></span>
            <div class="free-studio-option free-studio-model generation-model-advanced" role="group" aria-label="选择本次图片生成模型" title="选择本次图片生成模型">
                <i data-lucide="cpu"></i><span>模型</span>
                <select name="generation_model_id" aria-label="图片模型" data-free-web-select data-web-select-label="图片模型" data-creative-model-selector data-default-generation-id="<?= $defaultGenerationModelId ?>" data-default-edit-id="<?= $defaultEditModelId ?>">
                    <?php if ($defaultGenerationModelId === 0 || $defaultEditModelId === 0): ?><option value="0" data-generation-price="<?= (int) $smartGenerationPrice ?>" data-edit-price="<?= (int) $smartEditPrice ?>" data-supports-generation="<?= $defaultGenerationModelId === 0 ? '1' : '0' ?>" data-supports-edit="<?= $defaultEditModelId === 0 ? '1' : '0' ?>" data-generation-label="<?= escape_html($defaultGenerationModelName) ?>" data-edit-label="<?= escape_html($defaultEditModelName) ?>" <?= $defaultGenerationModelId === 0 ? 'selected' : '' ?>><?= escape_html($defaultGenerationModelName) ?></option><?php endif; ?>
                    <?php foreach ($modelOptions as $model): $generationPrice = (int) ($model['prices'][\app\service\AiCapability::IMAGE_GENERATION] ?? -1); $editPrice = (int) ($model['prices'][\app\service\AiCapability::IMAGE_EDIT] ?? -1); ?><option value="<?= (int) $model['id'] ?>" data-generation-price="<?= $generationPrice ?>" data-edit-price="<?= $editPrice ?>" data-supports-generation="<?= $model['supportsGeneration'] ? '1' : '0' ?>" data-supports-edit="<?= $model['supportsEdit'] ? '1' : '0' ?>" data-generation-label="<?= escape_html((string) $model['name'] . ($generationPrice >= 0 ? ' · ' . $generationPrice . '点/张' : '')) ?>" data-edit-label="<?= escape_html((string) $model['name'] . ($editPrice >= 0 ? ' · ' . $editPrice . '点/张' : '')) ?>" <?= $defaultGenerationModelId === (int) $model['id'] ? 'selected' : '' ?>><?= escape_html((string) $model['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <span class="free-studio-model-context" data-creative-model-hint hidden></span>
        </div>

        <button class="button primary free-studio-submit" type="submit">
            <span>发送</span>
        </button>
    </form>
</section>

<dialog class="prompt-helper-dialog" data-prompt-helper-dialog>
    <div class="prompt-helper-header"><div><strong>AI 帮你补充创作需求</strong><small>写下一个初步方向，AI 会整理成可继续修改的画面描述。</small></div><button type="button" aria-label="关闭" title="关闭" data-close-prompt-helper><i data-lucide="x"></i></button></div>
    <div class="prompt-helper-body"><div class="form-field"><label for="prompt-helper-idea">初步想法</label><textarea class="input" id="prompt-helper-idea" rows="5" maxlength="2000" placeholder="例如：做一张适合短视频的夏日饮品封面，清爽、有冲击力，留出标题位置" data-prompt-helper-idea></textarea></div><div class="prompt-helper-status" data-prompt-helper-status hidden></div><div class="form-field prompt-helper-result" data-prompt-helper-result hidden><label for="prompt-helper-output">AI 生成的画面描述</label><textarea class="input" id="prompt-helper-output" rows="9" maxlength="4000" data-prompt-helper-output></textarea><small>可以继续修改，采用后才会写入创作控制台。</small></div></div>
    <div class="prompt-helper-footer"><button class="button" type="button" data-close-prompt-helper>取消</button><button class="button primary" type="button" data-generate-prompt><i data-lucide="wand-sparkles"></i><span>生成草稿</span></button><button class="button primary" type="button" data-use-prompt hidden><i data-lucide="check"></i><span>采用此描述</span></button></div>
</dialog>
<dialog class="prompt-helper-dialog image-prompt-dialog" data-image-prompt-dialog>
    <div class="prompt-helper-header"><div><strong>看图写提示词</strong><small>选择当前参考图或素材库图片，AI 会提取可编辑的画面描述。</small></div><button type="button" aria-label="关闭" title="关闭" data-close-image-prompt><i data-lucide="x"></i></button></div>
    <div class="prompt-helper-body">
        <div class="image-prompt-source-choice" role="radiogroup" aria-label="图片来源">
            <label><input type="radio" name="image_prompt_source" value="current" checked><span>当前参考图</span></label>
            <label><input type="radio" name="image_prompt_source" value="asset"><span>素材库</span></label>
        </div>
        <div class="image-prompt-assets" data-image-prompt-assets hidden><?php foreach ((array) ($imagePromptAssets ?? []) as $asset): ?><label><input type="radio" name="image_prompt_asset" value="<?= (int) $asset['id'] ?>"><img src="<?= escape_html((string) $asset['file_url']) ?>" data-fallback="<?= escape_html((string) ($asset['backup_file_url'] ?? '')) ?>" alt="素材 <?= (int) $asset['id'] ?>"></label><?php endforeach; ?></div>
        <div class="prompt-helper-status" data-image-prompt-status hidden></div>
        <div class="form-field prompt-helper-result" data-image-prompt-result hidden><label for="image-prompt-output">图片提示词</label><textarea class="input" id="image-prompt-output" rows="9" maxlength="4000" data-image-prompt-output></textarea></div>
        <fieldset class="image-prompt-reference-policy"><legend>采用提示词后</legend><label><input type="radio" name="image_prompt_policy" value="prompt_only" checked>只使用提示词，不保留这张图</label><label><input type="radio" name="image_prompt_policy" value="keep_reference">同时保留图片作为生成参考</label></fieldset>
        <fieldset class="image-prompt-merge-policy"><legend>写入方式</legend><label><input type="radio" name="image_prompt_merge" value="replace" checked>替换当前描述</label><label><input type="radio" name="image_prompt_merge" value="append">追加到当前描述</label></fieldset>
    </div>
    <div class="prompt-helper-footer"><button class="button" type="button" data-close-image-prompt>取消</button><button class="button primary" type="button" data-generate-image-prompt><i data-lucide="scan-search"></i><span>开始识别</span></button><button class="button primary" type="button" data-use-image-prompt hidden><i data-lucide="check"></i><span>采用提示词</span></button></div>
</dialog>
<?php require __DIR__ . '/../partials/merchant_footer.php'; ?>
