<?php require __DIR__ . '/../partials/merchant_header.php'; ?>
<?php
$canvasAssets = array_map(static function (array $asset): array {
    return [
        'id' => (int) $asset['id'],
        'skuId' => (int) ($asset['sku_id'] ?? 0),
        'skuName' => (string) ($asset['sku_name'] ?? ''),
        'skuCode' => (string) ($asset['sku_code'] ?? ''),
        'assetType' => (string) $asset['asset_type'],
        'assetTypeLabel' => asset_type_label((string) $asset['asset_type']),
        'mediaType' => (string) ($asset['media_type'] ?? 'image'),
        'mimeType' => (string) ($asset['mime_type'] ?? ''),
        'width' => (int) ($asset['width'] ?? 0),
        'height' => (int) ($asset['height'] ?? 0),
        'fileUrl' => (string) $asset['file_url'],
        'backupFileUrl' => (string) ($asset['backup_file_url'] ?? ''),
        'createdAt' => (string) $asset['created_at'],
        'parentAssetId' => (int) ($asset['parent_asset_id'] ?? 0),
        'status' => (string) ($asset['status'] ?? 'active'),
    ];
}, $assets);
$activeView = (string) ($projectDocument['activeView'] ?? 'design');
if (!in_array($activeView, ['board', 'design', 'storyboard'], true)) {
    $activeView = 'design';
}
$storyboard = is_array($projectDocument['storyboard'] ?? null) ? $projectDocument['storyboard'] : [];
?>

<section class="canvas-project-toolbar" aria-label="画布项目工具栏">
    <div class="canvas-project-switcher">
        <label for="canvas-project-select">切换项目</label>
        <select class="input" id="canvas-project-select" data-canvas-project-switch>
            <option value="" selected disabled>切换项目</option>
            <?php foreach ($canvasProjects as $project): ?>
                <?php $isCurrentProject = (int) $project['id'] === (int) $creativeProject['id']; ?>
                <option value="<?= (int) $project['id'] ?>" <?= $isCurrentProject ? 'disabled' : '' ?>>
                    <?= $isCurrentProject ? '当前 · ' : '' ?><?= escape_html((string) $project['title']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <a class="icon-button" href="/workspace/canvas?new=1" aria-label="新建创作项目" data-tooltip="新建项目"><i data-lucide="plus"></i></a>
    </div>
    <div class="canvas-view-tabs" role="tablist" aria-label="创作视图">
        <button type="button" role="tab" data-canvas-view-tab="board" aria-selected="<?= $activeView === 'board' ? 'true' : 'false' ?>" class="<?= $activeView === 'board' ? 'active' : '' ?>">
            <i data-lucide="workflow"></i><span>素材看板</span>
        </button>
        <button type="button" role="tab" data-canvas-view-tab="design" aria-selected="<?= $activeView === 'design' ? 'true' : 'false' ?>" class="<?= $activeView === 'design' ? 'active' : '' ?>">
            <i data-lucide="pen-tool"></i><span>单图设计</span>
        </button>
        <button type="button" role="tab" data-canvas-view-tab="storyboard" aria-selected="<?= $activeView === 'storyboard' ? 'true' : 'false' ?>" class="<?= $activeView === 'storyboard' ? 'active' : '' ?>">
            <i data-lucide="clapperboard"></i><span>视频故事板</span>
        </button>
    </div>
    <div class="workflow-save-state" data-workflow-save-state>
        <i data-lucide="cloud"></i><span>已自动保存</span>
    </div>
</section>

<section class="workflow-canvas-shell"
         data-canvas-editor
         data-project-id="<?= (int) $creativeProject['id'] ?>"
         data-document-version="<?= (int) $creativeProject['document_version'] ?>"
         data-active-view="<?= escape_html($activeView) ?>"
         data-csrf-endpoint="/workspace/csrf-token"
         data-canvas-endpoint="/workspace/canvas/edit"
         data-canvas-asset-endpoint="/workspace/canvas/assets"
         data-canvas-workspace-endpoint="/workspace/canvas/workspace"
         data-storyboard-plan-endpoint="/workspace/canvas/storyboard/plan"
         data-storyboard-scene-endpoint="/workspace/canvas/storyboard/scenes"
         data-storyboard-status-endpoint="/workspace/canvas/storyboard/status/<?= (int) $creativeProject['id'] ?>"
         data-storyboard-video-enabled="<?= $storyboardVideoEnabled ? '1' : '0' ?>"
         data-storyboard-video-unit-price="<?= (int) $storyboardVideoUnitPrice ?>"
         data-storyboard-planner-credits="<?= (int) $storyboardPlannerCredits ?>"
         data-selected-asset-id="<?= (int) $selectedId ?>"
         data-default-asset-id="<?= (int) ($selectedAsset['id'] ?? 0) ?>"
         data-requested-tool="<?= escape_html($requestedTool) ?>">
    <script type="application/json" data-canvas-assets-json><?= json_encode($canvasAssets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script type="application/json" data-canvas-workspace-json><?= json_encode($workspaceGraph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script type="application/json" data-canvas-project-json><?= json_encode($projectDocument, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script type="application/json" data-storyboard-runtime-json><?= json_encode($storyboardRuntime, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <input type="file" accept="image/jpeg,image/png,image/webp" data-canvas-direct-upload hidden>

    <div class="canvas-workspace-view <?= $activeView === 'board' ? 'active' : '' ?>" data-canvas-view="board" role="tabpanel" <?= $activeView === 'board' ? '' : 'hidden' ?>>
        <div class="workflow-board" data-workflow-board>
            <div class="workflow-grid"></div>
            <div class="workflow-drawflow" data-workflow-drawflow></div>

            <div class="workflow-empty workflow-empty-create" data-workflow-empty hidden>
                <i data-lucide="workflow"></i>
                <strong>把素材放到看板，保留每次创作关系</strong>
                <span>上传图片、选择素材，或从单图设计导出一个新版本。</span>
                <div>
                    <button class="button primary" type="button" data-canvas-upload-trigger><i data-lucide="upload"></i><span>上传本地图片</span></button>
                    <button class="button" type="button" data-workflow-open="assets"><i data-lucide="images"></i><span>从素材库选择</span></button>
                    <button class="button" type="button" data-canvas-switch-design><i data-lucide="square-dashed"></i><span>创建空白画面</span></button>
                </div>
            </div>

            <div class="workflow-asset-float" data-workflow-assets-float>
                <button class="workflow-asset-float-drag" type="button" data-workflow-assets-drag aria-label="拖动素材库入口" data-tooltip="拖动"><i data-lucide="grip-vertical"></i></button>
                <button class="workflow-asset-float-open" type="button" data-workflow-open="assets" aria-label="打开素材库"><i data-lucide="images"></i><span>素材库</span></button>
            </div>

            <div class="workflow-toolbar" role="toolbar" aria-label="看板视图工具">
                <button class="button small" type="button" data-workflow-open="editor" data-workflow-edit-button aria-label="打开图片编辑" disabled><i data-lucide="sliders-horizontal"></i><span>AI 编辑</span></button>
                <span class="workflow-toolbar-divider"></span>
                <button class="icon-button" type="button" data-workflow-zoom-out aria-label="缩小看板" data-tooltip="缩小"><i data-lucide="zoom-out"></i></button>
                <button class="workflow-zoom-value" type="button" data-workflow-zoom-reset aria-label="重置缩放">100%</button>
                <button class="icon-button" type="button" data-workflow-zoom-in aria-label="放大看板" data-tooltip="放大"><i data-lucide="zoom-in"></i></button>
                <button class="icon-button" type="button" data-workflow-fit aria-label="适应全部节点" data-tooltip="适应内容"><i data-lucide="maximize"></i></button>
                <button class="icon-button danger" type="button" data-workflow-clear-board aria-label="清空素材看板" data-tooltip="清空看板"><i data-lucide="trash-2"></i></button>
            </div>

            <aside class="workflow-drawer asset-drawer" data-workflow-drawer="assets" aria-label="素材库抽屉">
                <div class="workflow-drawer-header">
                    <div><strong>选择素材</strong><small data-workflow-asset-count><?= count($assets) ?> 张图片</small></div>
                    <button class="icon-button" type="button" data-workflow-close="assets" aria-label="关闭素材库"><i data-lucide="x"></i></button>
                </div>
                <div class="workflow-drawer-search"><i data-lucide="search"></i><input class="input" type="search" placeholder="搜索商品或素材类型" data-workflow-asset-search></div>
                <div class="workflow-asset-list" data-workflow-asset-list>
                    <?php foreach ($assets as $asset): ?>
                        <button class="workflow-asset-card <?= ($asset['status'] ?? 'active') === 'pending' ? 'is-pending' : '' ?>" type="button" draggable="true" data-workflow-asset-card data-asset-id="<?= (int) $asset['id'] ?>" data-search-text="<?= escape_html(mb_strtolower(($asset['sku_code'] ?: '') . ' ' . ($asset['sku_name'] ?: '') . ' ' . asset_type_label($asset['asset_type']))) ?>">
                            <img src="<?= escape_html($asset['file_url']) ?>" data-fallback="<?= escape_html($asset['backup_file_url'] ?: '') ?>" alt="<?= escape_html(asset_type_label($asset['asset_type'])) ?>">
                            <span><strong><?= escape_html(asset_type_label($asset['asset_type'])) ?></strong><small><?= ($asset['status'] ?? 'active') === 'pending' ? '后台安全检查中' : escape_html(($asset['sku_code'] ?: '未归类') . ' · ' . ($asset['sku_name'] ?: '素材')) ?></small></span>
                            <i data-lucide="plus"></i>
                        </button>
                    <?php endforeach; ?>
                    <div class="workflow-asset-empty" data-workflow-asset-empty <?= $assets ? 'hidden' : '' ?>>
                        <i data-lucide="image-off"></i><strong>素材库还是空的</strong>
                        <span>可以直接上传，或先在单图设计中创建画面。</span>
                        <button class="button small primary" type="button" data-canvas-upload-trigger>上传图片</button>
                        <button class="button small" type="button" data-canvas-switch-design>创建画面</button>
                    </div>
                </div>
            </aside>

            <aside class="workflow-drawer editor-drawer" data-workflow-drawer="editor" aria-label="AI 图片编辑抽屉">
                <div class="workflow-drawer-header">
                    <div><strong>AI 图片编辑</strong><small data-canvas-selected-title>未选择节点</small></div>
                    <button class="icon-button" type="button" data-workflow-close="editor" aria-label="关闭图片编辑"><i data-lucide="x"></i></button>
                </div>
                <div class="workflow-editor-scroll">
                    <div class="workflow-edit-preview" data-canvas-stage>
                        <img src="" data-canvas-image data-fallback="" alt="当前编辑素材">
                        <canvas data-canvas-overlay aria-label="涂抹要修改的区域"></canvas>
                        <button class="workflow-preview-button" type="button" data-canvas-preview aria-label="放大查看图片"><i data-lucide="maximize-2"></i></button>
                        <div class="canvas-mode-hint" data-canvas-mode-hint>调整尺寸</div>
                        <div class="canvas-output-hint" data-canvas-output-hint>1:1 · 1024×1024</div>
                    </div>
                    <div class="canvas-regional-tools" data-canvas-regional-tools hidden>
                        <label for="canvas-brush">画笔</label>
                        <input id="canvas-brush" type="range" min="12" max="180" step="4" value="56" data-canvas-brush>
                        <output data-canvas-brush-value>56 px</output>
                        <button class="icon-button" type="button" data-canvas-undo aria-label="撤销上一笔" data-tooltip="撤销"><i data-lucide="undo-2"></i></button>
                        <button class="icon-button" type="button" data-canvas-redo aria-label="恢复上一笔" data-tooltip="恢复"><i data-lucide="redo-2"></i></button>
                        <button class="icon-button" type="button" data-canvas-clear aria-label="清空涂抹" data-tooltip="清空"><i data-lucide="eraser"></i></button>
                    </div>
                    <form data-canvas-form>
                        <input type="hidden" name="_token" value="<?= escape_html($csrfToken) ?>">
                        <input type="hidden" name="project_id" value="<?= (int) $creativeProject['id'] ?>">
                        <input type="hidden" name="source_node_uuid" value="" data-canvas-source-node>
                        <input type="hidden" name="asset_id" value="<?= (int) ($selectedAsset['id'] ?? 0) ?>" data-canvas-asset-id>
                        <input type="hidden" name="mask_image" value="" data-canvas-mask>
                        <input type="hidden" name="resize_mode" value="crop" data-canvas-resize-mode>
                        <div class="canvas-mode-control canvas-operation-control" role="radiogroup" aria-label="操作类型">
                            <label><input type="radio" name="operation" value="resize_only" checked data-canvas-operation><span><i data-lucide="ratio"></i><strong>调整尺寸</strong></span></label>
                            <label><input type="radio" name="operation" value="ai_edit" data-canvas-operation><span><i data-lucide="sparkles"></i><strong>AI 编辑</strong></span></label>
                        </div>
                        <div class="form-field">
                            <label for="canvas-output-preset">输出尺寸</label>
                            <select class="input" id="canvas-output-preset" name="output_preset" data-canvas-output-preset>
                                <?php foreach ($outputPresets as $value => $option): ?>
                                    <option value="<?= escape_html($value) ?>" data-width="<?= (int) $option['width'] ?>" data-height="<?= (int) $option['height'] ?>"><?= escape_html($option['label']) ?> · <?= (int) $option['width'] ?>×<?= (int) $option['height'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div data-canvas-ai-options hidden>
                            <div class="canvas-mode-control" role="radiogroup" aria-label="编辑范围">
                                <label><input type="radio" name="mode" value="global" checked data-canvas-mode><span><i data-lucide="scan"></i><strong>整图编辑</strong></span></label>
                                <label><input type="radio" name="mode" value="regional" data-canvas-mode><span><i data-lucide="paintbrush"></i><strong>局部重绘</strong></span></label>
                            </div>
                            <div class="form-field canvas-preset-field">
                                <label>编辑功能</label>
                                <div class="canvas-presets">
                                    <?php foreach ([['background', 'image', '更换背景'], ['scene', 'home', '融入场景'], ['cleanup', 'eraser', '清理杂物'], ['composition', 'crop', '优化构图'], ['style', 'palette', '统一风格'], ['lighting', 'sun-medium', '优化光影'], ['cutout', 'scan-line', '抠图白底'], ['enhance', 'wand-sparkles', '高清增强'], ['custom', 'sliders-horizontal', '自定义']] as [$value, $icon, $label]): ?>
                                        <label class="canvas-preset"><input type="radio" name="preset" value="<?= escape_html($value) ?>" <?= $value === 'background' ? 'checked' : '' ?> data-canvas-preset><span><i data-lucide="<?= escape_html($icon) ?>"></i><?= escape_html($label) ?></span></label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="form-field"><label for="canvas-instruction">补充要求 <small>选填</small></label><textarea class="input canvas-instruction" id="canvas-instruction" name="instruction" maxlength="1200" placeholder="例如：背景使用明亮厨房，保留商品颜色、结构和 Logo"></textarea></div>
                        </div>
                        <div class="canvas-selection-state" data-canvas-selection-state><?= $selectedAsset ? '选择输出尺寸后即可编辑' : '请先从看板选择一张图片' ?></div>
                        <div class="canvas-task-progress" data-canvas-task-progress hidden>
                            <div class="canvas-task-progress-head"><span data-canvas-task-label>等待处理</span><span data-canvas-task-percent>0%</span></div>
                            <div class="job-progress-track is-active"><span data-canvas-task-bar></span></div>
                        </div>
                        <button class="button primary block" type="submit" data-canvas-submit data-canvas-ai-credits="<?= (int) $quote['credits'] ?>" <?= $selectedAsset ? '' : 'disabled' ?>><i data-lucide="ratio"></i><span data-canvas-submit-label>调整并保存 · 免费</span></button>
                    </form>
                    <div class="workflow-selected-meta"><div><span>商品</span><strong data-canvas-meta-sku>-</strong></div><div><span>素材类型</span><strong data-canvas-meta-type>-</strong></div><div><span>生成时间</span><strong data-canvas-meta-time>-</strong></div></div>
                    <div class="workflow-result-state" data-canvas-result hidden><img src="" alt="画布编辑结果" data-canvas-result-image><div><strong>新版本已生成</strong><small data-canvas-result-meta></small></div><a class="icon-button" href="#" data-canvas-edit-result aria-label="选择结果节点"><i data-lucide="arrow-right"></i></a></div>
                </div>
            </aside>
        </div>
    </div>

    <div class="canvas-workspace-view canvas-design-workspace <?= $activeView === 'design' ? 'active' : '' ?>" data-canvas-view="design" role="tabpanel" <?= $activeView === 'design' ? '' : 'hidden' ?>>
        <div class="canvas-design-topbar">
            <div class="canvas-design-tabs" data-design-tabs aria-label="画面列表"></div>
            <button class="icon-button" type="button" data-design-add aria-label="新增画面" data-tooltip="新增画面"><i data-lucide="plus"></i></button>
            <span class="canvas-design-divider"></span>
            <button class="icon-button" type="button" data-design-undo aria-label="撤销" data-tooltip="撤销"><i data-lucide="undo-2"></i></button>
            <button class="icon-button" type="button" data-design-redo aria-label="恢复" data-tooltip="恢复"><i data-lucide="redo-2"></i></button>
            <span class="canvas-design-divider"></span>
            <button class="icon-button" type="button" data-design-zoom-out aria-label="缩小画面" data-tooltip="缩小"><i data-lucide="zoom-out"></i></button>
            <output data-design-zoom>100%</output>
            <button class="icon-button" type="button" data-design-zoom-in aria-label="放大画面" data-tooltip="放大"><i data-lucide="zoom-in"></i></button>
            <button class="button primary small" type="button" data-design-export><i data-lucide="download"></i><span>导出到素材库</span></button>
        </div>

        <div class="canvas-design-layout">
            <aside class="canvas-design-tools" aria-label="设计工具">
                <button type="button" data-design-tool="upload" data-tooltip="上传图片"><i data-lucide="image-plus"></i><span>上传</span></button>
                <button type="button" data-design-tool="assets" data-tooltip="添加素材"><i data-lucide="images"></i><span>素材</span></button>
                <button type="button" data-design-tool="text" data-tooltip="添加文字"><i data-lucide="type"></i><span>文字</span></button>
                <button type="button" data-design-tool="rect" data-tooltip="添加矩形"><i data-lucide="square"></i><span>矩形</span></button>
                <button type="button" data-design-tool="circle" data-tooltip="添加圆形"><i data-lucide="circle"></i><span>圆形</span></button>
                <input type="file" accept="image/jpeg,image/png,image/webp" data-design-upload-input hidden>
            </aside>

            <main class="canvas-design-stage">
                <div class="canvas-design-stage-scroll" data-design-stage-scroll>
                    <div class="canvas-design-surface" data-design-surface>
                        <canvas data-design-canvas width="1024" height="1024" aria-label="单图设计画布"></canvas>
                    </div>
                </div>
                <div class="canvas-design-hint" data-design-status>选择元素后可在右侧调整；所有对象会自动保存。</div>
            </main>

            <aside class="canvas-design-inspector">
                <div class="canvas-inspector-section">
                    <div class="canvas-inspector-heading"><strong>画布</strong><small data-design-size-label>1024 × 1024</small></div>
                    <label><span>规格</span><select class="input" data-design-preset>
                        <?php foreach ($outputPresets as $value => $option): ?>
                            <option value="<?= escape_html($value) ?>" data-width="<?= (int) $option['width'] ?>" data-height="<?= (int) $option['height'] ?>"><?= escape_html($option['label']) ?> · <?= (int) $option['width'] ?>×<?= (int) $option['height'] ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <div class="canvas-inspector-row">
                        <label><span>背景</span><input type="color" value="#ffffff" data-design-background></label>
                        <label class="canvas-transparent-toggle"><input type="checkbox" data-design-transparent><span>透明</span></label>
                    </div>
                </div>

                <div class="canvas-inspector-section" data-design-selection-panel>
                    <div class="canvas-inspector-heading"><strong>选中元素</strong><small data-design-selection-label>未选择</small></div>
                    <label data-design-text-field hidden><span>文字内容</span><textarea class="input" rows="3" maxlength="500" data-design-text></textarea></label>
                    <div class="canvas-inspector-row">
                        <label><span>颜色</span><input type="color" value="#172033" data-design-fill></label>
                        <label data-design-font-field hidden><span>字号</span><input class="input" type="number" min="8" max="320" value="64" data-design-font-size></label>
                    </div>
                    <label><span>透明度</span><input type="range" min="10" max="100" value="100" data-design-opacity><output data-design-opacity-value>100%</output></label>
                    <div class="canvas-inspector-actions">
                        <button class="icon-button" type="button" data-design-back aria-label="下移一层" data-tooltip="下移一层"><i data-lucide="arrow-down-to-line"></i></button>
                        <button class="icon-button" type="button" data-design-front aria-label="上移一层" data-tooltip="上移一层"><i data-lucide="arrow-up-to-line"></i></button>
                        <button class="icon-button" type="button" data-design-duplicate aria-label="复制元素" data-tooltip="复制"><i data-lucide="copy"></i></button>
                        <button class="icon-button danger" type="button" data-design-delete aria-label="删除元素" data-tooltip="删除"><i data-lucide="trash-2"></i></button>
                    </div>
                </div>

                <div class="canvas-inspector-section canvas-design-assets-panel" data-design-assets-panel hidden>
                    <div class="canvas-inspector-heading"><strong>最近素材</strong><button class="icon-button" type="button" data-design-assets-close aria-label="关闭素材选择"><i data-lucide="x"></i></button></div>
                    <div class="canvas-design-assets">
                        <?php foreach (array_slice($assets, 0, 40) as $asset): ?>
                            <button type="button" data-design-add-asset="<?= (int) $asset['id'] ?>" <?= ($asset['status'] ?? 'active') === 'pending' ? 'disabled' : '' ?>>
                                <img src="<?= escape_html($asset['file_url']) ?>" data-fallback="<?= escape_html($asset['backup_file_url'] ?: '') ?>" alt="<?= escape_html(asset_type_label($asset['asset_type'])) ?>">
                                <span><?= escape_html(asset_type_label($asset['asset_type'])) ?></span>
                            </button>
                        <?php endforeach; ?>
                        <?php if (!$assets): ?><p>暂无素材，可先上传本地图片。</p><?php endif; ?>
                    </div>
                </div>

                <div class="canvas-inspector-section canvas-design-layers">
                    <div class="canvas-inspector-heading"><strong>图层</strong><small data-design-layer-count>0 个</small></div>
                    <div data-design-layers></div>
                </div>
            </aside>
        </div>
    </div>

    <div class="canvas-workspace-view canvas-storyboard-workspace <?= $activeView === 'storyboard' ? 'active' : '' ?>" data-canvas-view="storyboard" role="tabpanel" <?= $activeView === 'storyboard' ? '' : 'hidden' ?>>
        <header class="storyboard-topbar">
            <div class="storyboard-summary">
                <span class="storyboard-summary-icon"><i data-lucide="clapperboard"></i></span>
                <div>
                    <strong data-storyboard-title><?= escape_html((string) ($storyboard['title'] ?? '商品视频故事板')) ?></strong>
                    <small>
                        <?php if ($storyboardSku): ?>
                            <?= escape_html((string) $storyboardSku['name']) ?> ·
                        <?php endif; ?>
                        <span data-storyboard-summary-text>准备分镜</span>
                    </small>
                </div>
            </div>
            <div class="storyboard-project-progress" aria-label="视频项目进度">
                <div><span>项目进度</span><strong data-storyboard-project-percent><?= (int) ($storyboardRuntime['summary']['progress'] ?? 0) ?>%</strong></div>
                <div class="job-progress-track"><span data-storyboard-project-bar style="width: <?= (int) ($storyboardRuntime['summary']['progress'] ?? 0) ?>%"></span></div>
            </div>
            <div class="storyboard-topbar-actions">
                <button class="button small" type="button" data-storyboard-plan-open><i data-lucide="sparkles"></i><span>AI 建议分镜</span></button>
                <button class="button small" type="button" data-storyboard-add><i data-lucide="plus"></i><span>新增镜头</span></button>
            </div>
        </header>

        <div class="storyboard-layout">
            <aside class="storyboard-scenes-panel" aria-label="视频镜头列表">
                <div class="storyboard-panel-heading">
                    <div><strong>镜头</strong><small data-storyboard-scene-count>0 个</small></div>
                    <button class="icon-button" type="button" data-storyboard-add aria-label="新增镜头" data-tooltip="新增镜头"><i data-lucide="plus"></i></button>
                </div>
                <div class="storyboard-scene-list" data-storyboard-scene-list></div>
                <div class="storyboard-scenes-footer">
                    <span>每个镜头独立生成、重试和计费</span>
                    <strong data-storyboard-credit-summary>预计 0 点</strong>
                </div>
            </aside>

            <main class="storyboard-preview">
                <div class="storyboard-empty" data-storyboard-empty>
                    <i data-lucide="film"></i>
                    <strong>把商品卖点拆成连续镜头</strong>
                    <span>先新增镜头，或让 AI 根据商品与创作目标给出一套可修改的分镜建议。</span>
                    <div>
                        <button class="button primary" type="button" data-storyboard-plan-open><i data-lucide="sparkles"></i><span>AI 建议分镜</span></button>
                        <button class="button" type="button" data-storyboard-add><i data-lucide="plus"></i><span>手动新增</span></button>
                    </div>
                </div>
                <div class="storyboard-preview-content" data-storyboard-preview-content hidden>
                    <div class="storyboard-preview-meta">
                        <span data-storyboard-preview-index>镜头 1</span>
                        <strong data-storyboard-preview-title>未命名镜头</strong>
                        <span data-storyboard-preview-duration>5 秒</span>
                    </div>
                    <div class="storyboard-media-stage" data-storyboard-media-stage>
                        <img src="" alt="当前镜头首帧" data-storyboard-source-preview hidden>
                        <video controls preload="metadata" data-storyboard-video-preview hidden></video>
                        <div class="storyboard-media-placeholder" data-storyboard-media-placeholder>
                            <i data-lucide="image-plus"></i>
                            <strong>选择首帧或直接使用文字生成</strong>
                            <span>有首帧时更容易保持商品外观和镜头连续性。</span>
                        </div>
                        <div class="storyboard-runtime-overlay" data-storyboard-runtime-overlay hidden>
                            <span data-storyboard-runtime-label>等待处理</span>
                            <strong data-storyboard-runtime-percent>0%</strong>
                            <div class="job-progress-track"><span data-storyboard-runtime-bar></span></div>
                        </div>
                    </div>
                    <div class="storyboard-scene-script">
                        <div><span>画面与动作</span><p data-storyboard-preview-prompt>尚未填写镜头要求。</p></div>
                        <div><span>字幕</span><p data-storyboard-preview-subtitle>本镜头不生成字幕。</p></div>
                    </div>
                    <div class="storyboard-notice" data-storyboard-notice hidden></div>
                </div>
            </main>

            <aside class="storyboard-inspector">
                <div class="storyboard-inspector-scroll">
                    <section class="storyboard-settings">
                        <div class="storyboard-panel-heading"><div><strong>视频设置</strong><small>整套分镜共用</small></div></div>
                        <label><span>创作目标</span><textarea class="input" rows="3" maxlength="1200" data-storyboard-brief placeholder="例如：先用真实场景抓住注意力，再展示商品材质、功能和使用结果。"><?= escape_html((string) ($storyboard['brief'] ?? '')) ?></textarea></label>
                        <div class="storyboard-field-grid">
                            <label><span>平台</span><select class="input" data-storyboard-platform>
                                <?php foreach (generation_platform_options() as $value => $label): ?>
                                    <option value="<?= escape_html($value) ?>" <?= (string) ($storyboard['platform'] ?? 'douyin') === $value ? 'selected' : '' ?>><?= escape_html($label) ?></option>
                                <?php endforeach; ?>
                            </select></label>
                            <label><span>画面比例</span><select class="input" data-storyboard-ratio>
                                <?php foreach (['9:16' => '9:16 竖屏', '16:9' => '16:9 横屏', '1:1' => '1:1 方形', '4:3' => '4:3 横向', '3:4' => '3:4 竖向'] as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= (string) ($storyboard['ratio'] ?? '9:16') === $value ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select></label>
                            <label><span>清晰度</span><select class="input" data-storyboard-resolution>
                                <option value="720p" <?= (string) ($storyboard['resolution'] ?? '720p') === '720p' ? 'selected' : '' ?>>720p</option>
                                <option value="1080p" <?= (string) ($storyboard['resolution'] ?? '720p') === '1080p' ? 'selected' : '' ?>>1080p</option>
                            </select></label>
                            <label><span>画面语言</span><select class="input" data-storyboard-language>
                                <?php foreach (generation_language_options() as $value => $label): ?>
                                    <option value="<?= escape_html($value) ?>" <?= (string) ($storyboard['language'] ?? 'zh-CN') === $value ? 'selected' : '' ?>><?= escape_html($label) ?></option>
                                <?php endforeach; ?>
                            </select></label>
                        </div>
                        <label class="storyboard-audio-toggle"><input type="checkbox" data-storyboard-audio <?= !empty($storyboard['generateAudio']) ? 'checked' : '' ?>><span>生成环境音或音效</span></label>
                    </section>

                    <section class="storyboard-scene-editor" data-storyboard-scene-editor hidden>
                        <div class="storyboard-panel-heading">
                            <div><strong>当前镜头</strong><small data-storyboard-editor-status>草稿</small></div>
                            <div class="storyboard-scene-order">
                                <button class="icon-button" type="button" data-storyboard-move="up" aria-label="镜头前移" data-tooltip="前移"><i data-lucide="arrow-up"></i></button>
                                <button class="icon-button" type="button" data-storyboard-move="down" aria-label="镜头后移" data-tooltip="后移"><i data-lucide="arrow-down"></i></button>
                                <button class="icon-button danger" type="button" data-storyboard-delete aria-label="删除镜头" data-tooltip="删除"><i data-lucide="trash-2"></i></button>
                            </div>
                        </div>
                        <label><span>镜头名称</span><input class="input" type="text" maxlength="80" data-storyboard-scene-title></label>
                        <label><span>画面与动作</span><textarea class="input" rows="5" maxlength="1200" data-storyboard-scene-prompt placeholder="描述这一镜头出现什么、主体如何运动、镜头怎样移动。"></textarea></label>
                        <label><span>首帧来源</span><select class="input" data-storyboard-source>
                            <option value="none">仅使用文字生成</option>
                            <?php foreach ($assets as $asset): ?>
                                <?php if ((string) ($asset['status'] ?? '') !== 'active') { continue; } ?>
                                <option value="asset:<?= (int) $asset['id'] ?>"><?= escape_html(asset_type_label((string) $asset['asset_type']) . ' · ' . ((string) ($asset['sku_name'] ?? '') ?: '未归类素材')) ?></option>
                            <?php endforeach; ?>
                        </select><small>选择商品图或上一镜头尾帧，可以提高主体与场景连续性。</small></label>
                        <div class="storyboard-field-grid">
                            <label><span>镜头方式</span><select class="input" data-storyboard-motion>
                                <?php foreach ((new \app\service\PromptTemplateService())->videoMotionPresets() as $value => $label): ?>
                                    <option value="<?= escape_html($value) ?>"><?= escape_html($label) ?></option>
                                <?php endforeach; ?>
                            </select></label>
                            <label><span>时长</span><select class="input" data-storyboard-duration><option value="5">5 秒</option><option value="10">10 秒</option></select></label>
                            <label><span>衔接</span><select class="input" data-storyboard-transition><option value="cut">直接切换</option><option value="fade">淡入淡出</option><option value="dissolve">叠化</option><option value="none">不指定</option></select></label>
                            <label><span>预计点数</span><output data-storyboard-scene-credits>0 点</output></label>
                        </div>
                        <label><span>字幕 <small>可选</small></span><textarea class="input" rows="2" maxlength="200" data-storyboard-subtitle placeholder="只填写希望出现在成片里的消费者文案；留空则不生成字幕。"></textarea></label>
                        <div class="storyboard-generation-summary">
                            <span data-storyboard-generation-state>保存镜头后即可生成</span>
                            <small>系统自动安排视频生成服务；失败自动退款。</small>
                        </div>
                        <button class="button primary block" type="button" data-storyboard-generate <?= $storyboardVideoEnabled ? '' : 'disabled' ?>><i data-lucide="play"></i><span><?= $storyboardVideoEnabled ? '生成当前镜头' : '视频生成暂不可用' ?></span></button>
                    </section>
                </div>
            </aside>
        </div>
    </div>
</section>

<dialog class="storyboard-plan-dialog" data-storyboard-plan-dialog>
    <form method="dialog" data-storyboard-plan-form>
        <header><div><strong>AI 建议分镜</strong><small>生成后仍可逐镜头修改，不会直接创建视频任务。</small></div><button class="icon-button" type="button" data-storyboard-plan-close aria-label="关闭"><i data-lucide="x"></i></button></header>
        <label><span>整条视频想怎么讲</span><textarea class="input" rows="5" maxlength="1200" data-storyboard-plan-brief placeholder="例如：面向通勤人群，先展示使用痛点，再突出便携设计和材质质感，最后用办公室场景收束。"></textarea></label>
        <label><span>建议镜头数</span><select class="input" data-storyboard-plan-count><option value="3">3 个 · 快速短片</option><option value="5" selected>5 个 · 完整叙事</option><option value="7">7 个 · 丰富展示</option><option value="8">8 个 · 深度内容</option></select></label>
        <p>本次策划预计消耗 <strong><?= (int) $storyboardPlannerCredits ?> 点</strong>。已有镜头会保留，新建议追加到列表中。</p>
        <div class="storyboard-dialog-actions"><button class="button" type="button" data-storyboard-plan-close>取消</button><button class="button primary" type="submit"><i data-lucide="sparkles"></i><span>生成分镜建议</span></button></div>
    </form>
</dialog>

<dialog class="workflow-preview-dialog" data-workflow-preview-dialog>
    <div class="workflow-preview-header">
        <strong data-workflow-preview-title>图片预览</strong>
        <div>
            <button class="icon-button" type="button" data-workflow-preview-minus aria-label="缩小图片"><i data-lucide="zoom-out"></i></button>
            <output data-workflow-preview-zoom>100%</output>
            <button class="icon-button" type="button" data-workflow-preview-plus aria-label="放大图片"><i data-lucide="zoom-in"></i></button>
            <button class="icon-button" type="button" data-workflow-preview-close aria-label="关闭预览"><i data-lucide="x"></i></button>
        </div>
    </div>
    <div class="workflow-preview-stage" data-workflow-preview-stage><div class="workflow-preview-content" data-workflow-preview-content><img src="" alt="图片预览" data-workflow-preview-image></div></div>
</dialog>
<?php require __DIR__ . '/../partials/merchant_footer.php'; ?>
