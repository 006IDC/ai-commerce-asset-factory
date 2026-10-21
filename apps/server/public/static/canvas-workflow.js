(function () {
    'use strict';

    function safeImageUrl(value) {
        var text = String(value || '').trim();
        if (!text) return '';
        try {
            var url = new URL(text, window.location.href);
            if (url.protocol === 'http:' || url.protocol === 'https:') return url.href;
            // Local previews stay images; these URLs must never be reused as navigation links.
            if (url.protocol === 'blob:' && url.origin === window.location.origin) return url.href;
            if (/^data:image\/[a-z0-9.+-]+(?:;[^,]*)?,/i.test(text)) return url.href;
        } catch (error) {
            // Invalid image locations are left empty rather than interpreted as another URL type.
        }
        return '';
    }

    function setupCanvasWorkflow() {
        var shell = document.querySelector('[data-canvas-editor]');
        if (!shell || typeof window.Drawflow !== 'function') {
            return;
        }

        var board = shell.querySelector('[data-workflow-board]');
        var drawflowRoot = shell.querySelector('[data-workflow-drawflow]');
        var assetsScript = shell.querySelector('[data-canvas-assets-json]');
        var workspaceScript = shell.querySelector('[data-canvas-workspace-json]');
        var form = shell.querySelector('[data-canvas-form]');
        var image = shell.querySelector('[data-canvas-image]');
        var overlay = shell.querySelector('[data-canvas-overlay]');
        var assetInput = shell.querySelector('[data-canvas-asset-id]');
        var sourceNodeInput = shell.querySelector('[data-canvas-source-node]');
        var maskInput = shell.querySelector('[data-canvas-mask]');
        var resizeMode = shell.querySelector('[data-canvas-resize-mode]');
        var operationInputs = form.querySelectorAll('[data-canvas-operation]');
        var modeInputs = form.querySelectorAll('[data-canvas-mode]');
        var presetInputs = form.querySelectorAll('[data-canvas-preset]');
        var outputPreset = form.querySelector('[data-canvas-output-preset]');
        var instruction = form.querySelector('#canvas-instruction');
        var aiOptions = form.querySelector('[data-canvas-ai-options]');
        var submit = form.querySelector('[data-canvas-submit]');
        var submitLabel = form.querySelector('[data-canvas-submit-label]');
        var selectionState = form.querySelector('[data-canvas-selection-state]');
        var regionalTools = shell.querySelector('[data-canvas-regional-tools]');
        var brush = shell.querySelector('[data-canvas-brush]');
        var brushOutput = shell.querySelector('[data-canvas-brush-value]');
        var undo = shell.querySelector('[data-canvas-undo]');
        var redo = shell.querySelector('[data-canvas-redo]');
        var clear = shell.querySelector('[data-canvas-clear]');
        var modeHint = shell.querySelector('[data-canvas-mode-hint]');
        var outputHint = shell.querySelector('[data-canvas-output-hint]');
        var taskProgress = shell.querySelector('[data-canvas-task-progress]');
        var taskLabel = shell.querySelector('[data-canvas-task-label]');
        var taskPercent = shell.querySelector('[data-canvas-task-percent]');
        var taskBar = shell.querySelector('[data-canvas-task-bar]');
        var result = shell.querySelector('[data-canvas-result]');
        var resultImage = shell.querySelector('[data-canvas-result-image]');
        var resultMeta = shell.querySelector('[data-canvas-result-meta]');
        var editResult = shell.querySelector('[data-canvas-edit-result]');
        var selectedTitle = shell.querySelector('[data-canvas-selected-title]');
        var metaSku = shell.querySelector('[data-canvas-meta-sku]');
        var metaType = shell.querySelector('[data-canvas-meta-type]');
        var metaTime = shell.querySelector('[data-canvas-meta-time]');
        var editButton = shell.querySelector('[data-workflow-edit-button]');
        var emptyState = shell.querySelector('[data-workflow-empty]');
        var saveState = shell.querySelector('[data-workflow-save-state]');
        var zoomValue = shell.querySelector('[data-workflow-zoom-reset]');
        var assetDrawer = shell.querySelector('[data-workflow-drawer="assets"]');
        var assetFloat = shell.querySelector('[data-workflow-assets-float]');
        var assetFloatDrag = shell.querySelector('[data-workflow-assets-drag]');
        var previewDialog = document.querySelector('[data-workflow-preview-dialog]');
        var previewImage = previewDialog.querySelector('[data-workflow-preview-image]');
        var previewStage = previewDialog.querySelector('[data-workflow-preview-stage]');
        var previewContent = previewDialog.querySelector('[data-workflow-preview-content]');
        var previewTitle = previewDialog.querySelector('[data-workflow-preview-title]');
        var previewZoomOutput = previewDialog.querySelector('[data-workflow-preview-zoom]');
        var context = overlay.getContext('2d');
        var assets = parseJson(assetsScript.textContent, []);
        var projectDocument = window.CanvasProjectStore ? window.CanvasProjectStore.getDocument() : null;
        var workspace = projectDocument && projectDocument.board ? projectDocument.board : parseJson(workspaceScript.textContent, {});
        var assetsById = {};
        var nodeByAssetId = {};
        var assetByNodeId = {};
        var uuidByNodeId = {};
        var nodeByUuid = {};
        var selectedAssetId = 0;
        var strokes = [];
        var redoStrokes = [];
        var activeStroke = null;
        var pendingJobs = new Map();
        var latestJobId = 0;
        var restoring = true;
        var hasUnsavedChanges = false;
        var previewScale = 1;
        var previewBaseWidth = 0;
        var previewBaseHeight = 0;
        var canvasPan = null;
        var nodeDrag = null;
        var assetFloatDragState = null;
        var drawerCloseTimer = null;
        var assetFloatPosition = {x: 12, y: 72};

        if (window.innerWidth <= 520) {
            if ('scrollRestoration' in window.history) {
                window.history.scrollRestoration = 'manual';
            }
            window.scrollTo(0, 0);
        }
        document.body.classList.add('workflow-canvas-open');
        var studioMain = shell.closest('.merchant-studio-main');
        if (studioMain) {
            studioMain.scrollTop = 0;
        }

        assets.forEach(function (asset) {
            asset.id = Number(asset.id);
            asset.parentAssetId = Number(asset.parentAssetId || 0);
            assetsById[asset.id] = asset;
        });

        var workflow = new window.Drawflow(drawflowRoot);
        workflow.reroute = true;
        workflow.reroute_fix_curvature = true;
        workflow.force_first_input = false;
        workflow.zoom_min = 0.25;
        workflow.zoom_max = 2;
        workflow.zoom_value = 0.1;
        workflow.start();
        workflow.editor_mode = 'edit';
        workflow.draggable_inputs = true;

        function parseJson(value, fallback) {
            try {
                var parsed = JSON.parse(value || '');
                return parsed && typeof parsed === 'object' ? parsed : fallback;
            } catch (error) {
                return fallback;
            }
        }

        function escapeHtml(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function makeUuid(prefix) {
            var bytes = new Uint8Array(8);
            if (window.crypto && window.crypto.getRandomValues) {
                window.crypto.getRandomValues(bytes);
            } else {
                for (var index = 0; index < bytes.length; index++) {
                    bytes[index] = Math.floor(Math.random() * 256);
                }
            }
            return prefix + '-' + Array.prototype.map.call(bytes, function (value) {
                return value.toString(16).padStart(2, '0');
            }).join('');
        }

        function refreshIcons(scope) {
            if (window.lucide) {
                window.lucide.createIcons();
            }
        }

        function nodeHtml(asset) {
            var sku = (asset.skuCode || '未归类') + ' · ' + (asset.skuName || '素材');
            var pending = asset.status === 'pending';
            return '<article class="workflow-image-node" data-node-asset-id="' + asset.id + '">' +
                '<div class="workflow-node-image"><img src="' + escapeHtml(safeImageUrl(asset.fileUrl)) + '" data-fallback="' + escapeHtml(safeImageUrl(asset.backupFileUrl || '')) + '" alt="' + escapeHtml(asset.assetTypeLabel) + '">' +
                '<button type="button" data-node-preview aria-label="放大查看"><i data-lucide="maximize-2"></i></button></div>' +
                '<div class="workflow-node-copy"><strong>' + escapeHtml(asset.assetTypeLabel) + '</strong><small>' + (pending ? '后台安全检查中' : escapeHtml(sku)) + '</small></div>' +
                '<div class="workflow-node-actions"><button type="button" data-node-edit aria-label="编辑图片" title="' + (pending ? '安全检查通过后可编辑' : '编辑图片') + '"' + (pending ? ' disabled' : '') + '><i data-lucide="sliders-horizontal"></i></button><button type="button" data-node-remove aria-label="移出画布" title="移出画布"><i data-lucide="x"></i></button></div>' +
                '</article>';
        }

        function addAssetNode(asset, x, y, selectAfter, requestedUuid) {
            if (!asset || nodeByAssetId[asset.id]) {
                if (selectAfter && asset) {
                    selectAsset(asset.id, false);
                }
                return nodeByAssetId[asset ? asset.id : 0] || 0;
            }
            var nodeUuid = requestedUuid || makeUuid('asset');
            var nodeId = workflow.addNode('asset', 1, 1, x, y, 'workflow-node', {assetId: asset.id, nodeUuid: nodeUuid}, nodeHtml(asset));
            nodeByAssetId[asset.id] = Number(nodeId);
            assetByNodeId[Number(nodeId)] = asset.id;
            uuidByNodeId[Number(nodeId)] = nodeUuid;
            nodeByUuid[nodeUuid] = Number(nodeId);
            var node = drawflowRoot.querySelector('#node-' + nodeId);
            if (node) {
                refreshIcons(node);
                setupImageFallbacks(node);
            }
            connectKnownRelations(asset);
            updateEmptyState();
            if (selectAfter) {
                selectAsset(asset.id, false);
            }
            return Number(nodeId);
        }

        function connectKnownRelations(asset) {
            if (asset.parentAssetId > 0 && nodeByAssetId[asset.parentAssetId]) {
                connectAssets(asset.parentAssetId, asset.id);
            }
            Object.keys(nodeByAssetId).forEach(function (candidateId) {
                var candidate = assetsById[Number(candidateId)];
                if (candidate && candidate.parentAssetId === asset.id) {
                    connectAssets(asset.id, candidate.id);
                }
            });
        }

        function connectionExists(sourceNodeId, targetNodeId) {
            var node = workflow.getNodeFromId(sourceNodeId);
            var connections = node && node.outputs && node.outputs.output_1 ? node.outputs.output_1.connections : [];
            return connections.some(function (connection) { return Number(connection.node) === Number(targetNodeId); });
        }

        function connectAssets(sourceAssetId, targetAssetId) {
            var sourceNodeId = nodeByAssetId[sourceAssetId];
            var targetNodeId = nodeByAssetId[targetAssetId];
            if (!sourceNodeId || !targetNodeId || sourceNodeId === targetNodeId || connectionExists(sourceNodeId, targetNodeId)) {
                return;
            }
            workflow.addConnection(sourceNodeId, targetNodeId, 'output_1', 'input_1');
        }

        function positionOverlaps(x, y, width, height, margin, ignoredNodeIds) {
            var ignored = ignoredNodeIds || {};
            return Object.keys(assetByNodeId).some(function (nodeId) {
                if (ignored[nodeId]) {
                    return false;
                }
                var node = workflow.getNodeFromId(Number(nodeId));
                var element = drawflowRoot.querySelector('#node-' + nodeId);
                var nodeWidth = element ? element.offsetWidth : 286;
                var nodeHeight = element ? element.offsetHeight : 286;
                return x < Number(node.pos_x) + nodeWidth + margin && x + width + margin > Number(node.pos_x)
                    && y < Number(node.pos_y) + nodeHeight + margin && y + height + margin > Number(node.pos_y);
            });
        }

        function defaultPosition() {
            var rect = board.getBoundingClientRect();
            var zoom = workflow.zoom || 1;
            var originX = Math.round((rect.width / 2 - workflow.canvas_x) / zoom - 150);
            var originY = Math.round((rect.height / 2 - workflow.canvas_y) / zoom - 120);
            for (var attempt = 0; attempt < 200; attempt++) {
                var x = originX + (attempt % 4) * 360;
                var y = originY + Math.floor(attempt / 4) * 334;
                if (!positionOverlaps(x, y, 286, 286, 48)) {
                    return {x: x, y: y};
                }
            }
            return {x: originX, y: originY};
        }

        function childPosition(parentAssetId) {
            var parentNodeId = nodeByAssetId[Number(parentAssetId)];
            var parentNode = parentNodeId ? workflow.getNodeFromId(parentNodeId) : null;
            if (!parentNode) {
                return defaultPosition();
            }
            var parentElement = drawflowRoot.querySelector('#node-' + parentNodeId);
            var candidateWidth = 286;
            var candidateHeight = 286;
            var margin = 48;
            var x = Number(parentNode.pos_x) + (parentElement ? parentElement.offsetWidth : candidateWidth) + 110;
            var y = Number(parentNode.pos_y);
            for (var attempt = 0; attempt < 200; attempt++) {
                if (!positionOverlaps(x, y, candidateWidth, candidateHeight, margin)) {
                    return {x: x, y: y};
                }
                y += candidateHeight + margin;
            }
            return {x: x, y: y};
        }

        function repairOverlappingNodes() {
            var placed = [];
            var repaired = false;
            Object.keys(assetByNodeId).forEach(function (nodeId) {
                var numericId = Number(nodeId);
                var node = workflow.getNodeFromId(numericId);
                var element = drawflowRoot.querySelector('#node-' + nodeId);
                if (!node || !element) {
                    return;
                }
                var width = element.offsetWidth || 286;
                var height = element.offsetHeight || 286;
                var x = Number(node.pos_x);
                var y = Number(node.pos_y);
                var intersects = function () {
                    return placed.some(function (other) {
                        return x < other.x + other.width + 24 && x + width + 24 > other.x
                            && y < other.y + other.height + 24 && y + height + 24 > other.y;
                    });
                };
                for (var attempt = 0; intersects() && attempt < 200; attempt++) {
                    y += height + 48;
                    repaired = true;
                }
                if (y !== Number(node.pos_y)) {
                    workflow.drawflow.drawflow.Home.data[numericId].pos_y = y;
                    element.style.top = y + 'px';
                }
                placed.push({x: x, y: y, width: width, height: height});
            });
            if (repaired) {
                Object.keys(assetByNodeId).forEach(function (nodeId) {
                    workflow.updateConnectionNodes('node-' + nodeId);
                });
            }
            return repaired;
        }

        function addAssetAt(assetId, position, selectAfter) {
            var asset = assetsById[Number(assetId)];
            if (!asset) {
                return;
            }
            var point = position;
            if (!point && asset.parentAssetId > 0 && nodeByAssetId[asset.parentAssetId]) {
                point = childPosition(asset.parentAssetId);
            }
            point = point || defaultPosition();
            addAssetNode(asset, point.x, point.y, selectAfter !== false);
            scheduleSave();
        }

        function setupImageFallbacks(scope) {
            scope.querySelectorAll('img[data-fallback]').forEach(function (target) {
                if (target.getAttribute('data-fallback-bound') === '1') {
                    return;
                }
                target.setAttribute('data-fallback-bound', '1');
                target.addEventListener('error', function () {
                    var fallback = target.getAttribute('data-fallback');
                    if (fallback && target.getAttribute('data-fallback-used') !== '1') {
                        target.setAttribute('data-fallback-used', '1');
                        target.src = safeImageUrl(fallback);
                    }
                });
            });
        }

        function selectAsset(assetId, openEditor) {
            var asset = assetsById[Number(assetId)];
            var nodeId = nodeByAssetId[Number(assetId)];
            if (!asset || !nodeId) {
                return;
            }
            if (asset.status === 'pending') {
                selectedAssetId = 0;
                assetInput.value = '0';
                if (sourceNodeInput) {
                    sourceNodeInput.value = '';
                }
                editButton.disabled = true;
                submit.disabled = true;
                selectionState.textContent = '素材正在后台安全检查，通过后即可编辑';
                selectionState.classList.remove('error');
                if (openEditor) {
                    openDrawer('editor');
                }
                return;
            }
            selectedAssetId = asset.id;
            assetInput.value = String(asset.id);
            if (sourceNodeInput) {
                sourceNodeInput.value = uuidByNodeId[Number(nodeId)] || '';
            }
            image.src = safeImageUrl(asset.fileUrl);
            image.setAttribute('data-fallback', asset.backupFileUrl || '');
            image.removeAttribute('data-fallback-used');
            image.alt = asset.assetTypeLabel;
            selectedTitle.textContent = asset.assetTypeLabel;
            metaSku.textContent = (asset.skuCode || '-') + ' · ' + (asset.skuName || '未归类');
            metaType.textContent = asset.assetTypeLabel;
            metaTime.textContent = asset.createdAt || '-';
            editButton.disabled = false;
            submit.disabled = false;
            drawflowRoot.querySelectorAll('.drawflow-node.selected').forEach(function (node) { node.classList.remove('selected'); });
            var nodeElement = drawflowRoot.querySelector('#node-' + nodeId);
            if (nodeElement) {
                nodeElement.classList.add('selected');
            }
            strokes = [];
            redoStrokes = [];
            redraw();
            if (openEditor) {
                openDrawer('editor');
            }
        }

        function selectedValue(inputs) {
            var selected = Array.prototype.find.call(inputs, function (input) { return input.checked; });
            return selected ? selected.value : '';
        }

        function currentOperation() {
            return selectedValue(operationInputs) || 'resize_only';
        }

        function currentMode() {
            return selectedValue(modeInputs) || 'global';
        }

        function drawStroke(target, stroke, color) {
            if (!stroke.points.length) {
                return;
            }
            target.save();
            target.strokeStyle = color;
            target.fillStyle = color;
            target.lineCap = 'round';
            target.lineJoin = 'round';
            target.lineWidth = stroke.size;
            target.beginPath();
            target.moveTo(stroke.points[0].x, stroke.points[0].y);
            for (var index = 1; index < stroke.points.length; index++) {
                target.lineTo(stroke.points[index].x, stroke.points[index].y);
            }
            target.stroke();
            if (stroke.points.length === 1) {
                target.beginPath();
                target.arc(stroke.points[0].x, stroke.points[0].y, stroke.size / 2, 0, Math.PI * 2);
                target.fill();
            }
            target.restore();
        }

        function redraw() {
            context.clearRect(0, 0, overlay.width, overlay.height);
            strokes.forEach(function (stroke) { drawStroke(context, stroke, 'rgba(0, 110, 255, .38)'); });
            undo.disabled = strokes.length === 0;
            redo.disabled = redoStrokes.length === 0;
            clear.disabled = strokes.length === 0;
            if (currentMode() === 'regional') {
                selectionState.textContent = strokes.length ? '已标记 ' + strokes.length + ' 处修改区域' : '请在图片上涂抹需要重绘的区域';
                selectionState.classList.toggle('active', strokes.length > 0);
            }
        }

        function prepareCanvas() {
            if (!image.naturalWidth || !image.naturalHeight) {
                return;
            }
            var stage = image.parentElement;
            var renderedScale = Math.min(stage.clientWidth / image.naturalWidth, stage.clientHeight / image.naturalHeight);
            var renderedWidth = image.naturalWidth * renderedScale;
            var renderedHeight = image.naturalHeight * renderedScale;
            overlay.style.inset = 'auto';
            overlay.style.left = ((stage.clientWidth - renderedWidth) / 2) + 'px';
            overlay.style.top = ((stage.clientHeight - renderedHeight) / 2) + 'px';
            overlay.style.width = renderedWidth + 'px';
            overlay.style.height = renderedHeight + 'px';
            overlay.width = image.naturalWidth;
            overlay.height = image.naturalHeight;
            strokes = [];
            redoStrokes = [];
            activeStroke = null;
            redraw();
        }

        function pointFromEvent(event) {
            var bounds = overlay.getBoundingClientRect();
            return {x: (event.clientX - bounds.left) * overlay.width / bounds.width, y: (event.clientY - bounds.top) * overlay.height / bounds.height};
        }

        function brushSize() {
            var bounds = overlay.getBoundingClientRect();
            return Math.max(2, Number(brush.value) * overlay.width / Math.max(1, bounds.width));
        }

        function buildMask() {
            var mask = document.createElement('canvas');
            mask.width = overlay.width;
            mask.height = overlay.height;
            var maskContext = mask.getContext('2d');
            maskContext.fillStyle = '#fff';
            maskContext.fillRect(0, 0, mask.width, mask.height);
            maskContext.globalCompositeOperation = 'destination-out';
            strokes.forEach(function (stroke) { drawStroke(maskContext, stroke, '#000'); });
            return mask.toDataURL('image/png');
        }

        function updateMode() {
            var aiEditing = currentOperation() === 'ai_edit';
            var regional = currentMode() === 'regional';
            regionalTools.hidden = !aiEditing || !regional;
            overlay.classList.toggle('is-disabled', !aiEditing || !regional);
            modeHint.textContent = !aiEditing ? '调整尺寸' : regional ? '局部重绘' : 'AI 整图编辑';
            modeHint.classList.toggle('regional', aiEditing && regional);
            selectionState.classList.remove('error');
            selectionState.textContent = !aiEditing ? '选择图片比例后即可免费调整尺寸' : regional ? (strokes.length ? '已标记 ' + strokes.length + ' 处修改区域' : '请在图片上涂抹需要重绘的区域') : '整图编辑将保留商品主体，并按指令调整画面';
            selectionState.classList.toggle('active', aiEditing && regional && strokes.length > 0);
        }

        function updateOperation() {
            var aiEditing = currentOperation() === 'ai_edit';
            aiOptions.hidden = !aiEditing;
            resizeMode.value = aiEditing ? 'extend' : 'crop';
            var credits = Number(submit.getAttribute('data-canvas-ai-credits') || 0);
            submitLabel.textContent = aiEditing ? '提交 AI 编辑' + (credits > 0 ? ' · ' + credits + ' 点' : '') : '调整并保存 · 免费';
            updateMode();
        }

        function updateOutputHint() {
            var option = outputPreset.options[outputPreset.selectedIndex];
            var width = Number(option ? option.getAttribute('data-width') : 1024);
            var height = Number(option ? option.getAttribute('data-height') : 1024);
            var a = width;
            var b = height;
            while (b) {
                var remainder = a % b;
                a = b;
                b = remainder;
            }
            var divisor = a || 1;
            outputHint.textContent = (width / divisor) + ':' + (height / divisor) + ' · ' + width + '×' + height;
        }

        function setTaskProgress(label, percent, active) {
            var normalized = Math.max(0, Math.min(100, percent));
            taskProgress.hidden = false;
            taskLabel.textContent = label;
            taskPercent.textContent = normalized + '%';
            taskBar.style.width = normalized + '%';
            taskProgress.querySelector('.job-progress-track').classList.toggle('is-active', active);
        }

        function setBusy(busy) {
            submit.disabled = busy;
            submit.classList.toggle('is-loading', busy);
            modeInputs.forEach(function (input) { input.disabled = busy; });
            operationInputs.forEach(function (input) { input.disabled = busy; });
            presetInputs.forEach(function (input) { input.disabled = busy; });
            outputPreset.disabled = busy;
            resizeMode.disabled = busy;
        }

        function setNodeProcessing(assetId, processing) {
            var node = drawflowRoot.querySelector('#node-' + nodeByAssetId[assetId]);
            if (node) {
                node.classList.toggle('is-processing', processing);
            }
        }

        function responseJson(response) {
            return response.text().then(function (body) {
                var data = null;
                try {
                    data = body ? JSON.parse(body) : null;
                } catch (error) {
                    if (response.status === 419) {
                        throw new Error('页面验证状态已失效，请刷新页面后重试。');
                    }
                    throw new Error(response.status === 504 ? '图片处理时间较长，任务可能仍在后台继续。' : '服务器返回异常（HTTP ' + response.status + '）。');
                }
                if (!response.ok || !data || !data.ok) {
                    throw new Error(data && data.message ? data.message : '画布编辑请求失败');
                }
                return data;
            });
        }

        function updateCsrfToken(token) {
            shell.querySelectorAll('input[name="_token"]').forEach(function (input) {
                input.value = token;
            });
        }

        function refreshCsrfToken() {
            return fetch(shell.getAttribute('data-csrf-endpoint'), {credentials: 'same-origin', cache: 'no-store'})
                .then(responseJson)
                .then(function (data) {
                    if (!data.token) {
                        throw new Error('无法更新页面验证状态，请刷新页面后重试。');
                    }
                    updateCsrfToken(data.token);
                    return data.token;
                });
        }

        function requestJson(url, options, retryOnExpiredToken) {
            return fetch(url, options).then(function (response) {
                if (response.status !== 419 || retryOnExpiredToken === false) {
                    return responseJson(response);
                }
                return refreshCsrfToken().then(function (token) {
                    if (options.body instanceof FormData) {
                        options.body.set('_token', token);
                    }
                    return requestJson(url, options, false);
                });
            });
        }

        function addResultAsset(asset, sourceAssetId, selectAfter) {
            if (!asset || !asset.id) {
                return;
            }
            asset.id = Number(asset.id);
            asset.parentAssetId = Number(asset.parentAssetId || sourceAssetId);
            assetsById[asset.id] = asset;
            var point = childPosition(asset.parentAssetId);
            addAssetNode(asset, point.x, point.y, Boolean(selectAfter));
            connectAssets(asset.parentAssetId, asset.id);
            prependAssetCard(asset);
            scheduleSave();
        }

        function sourceStillProcessing(sourceAssetId) {
            return Array.from(pendingJobs.values()).some(function (pending) {
                return Number(pending.sourceAssetId) === Number(sourceAssetId);
            });
        }

        function finishPendingJob(jobId) {
            var pending = pendingJobs.get(jobId);
            pendingJobs.delete(jobId);
            if (pending && !sourceStillProcessing(pending.sourceAssetId)) {
                setNodeProcessing(pending.sourceAssetId, false);
            }
            return pending;
        }

        function pollJob(statusUrl, attempts, jobId) {
            var pending = pendingJobs.get(jobId);
            if (!pending) {
                return;
            }
            fetch(statusUrl, {credentials: 'same-origin', cache: 'no-store'})
                .then(responseJson)
                .then(function (data) {
                    var job = data.job || {};
                    var percent = job.status === 'completed' ? 100 : job.status === 'running' ? (job.stage === 'saving' ? 90 : 55) : 15;
                    if (jobId === latestJobId) {
                        setTaskProgress(job.message || job.statusLabel || '正在处理', percent, job.status !== 'completed' && job.status !== 'failed');
                        selectionState.textContent = job.message || job.statusLabel || '正在处理';
                        selectionState.classList.remove('error');
                    }
                    if (job.status === 'completed') {
                        var completed = finishPendingJob(jobId) || pending;
                        var keepCurrentSelection = Number(selectedAssetId) === Number(completed.sourceAssetId) && jobId === latestJobId;
                        addResultAsset(job.asset, completed.sourceAssetId, keepCurrentSelection);
                        if (keepCurrentSelection) {
                            resultImage.src = safeImageUrl(job.imageUrl);
                            resultImage.setAttribute('data-result-asset-id', String(job.assetId || ''));
                            resultMeta.textContent = '已输出 ' + job.outputWidth + '×' + job.outputHeight;
                            editResult.href = '#node-' + nodeByAssetId[Number(job.assetId)];
                            result.hidden = false;
                            selectionState.textContent = '编辑完成，新节点已连接到原图';
                            selectionState.classList.add('active');
                        }
                        return;
                    }
                    if (job.status === 'failed') {
                        finishPendingJob(jobId);
                        if (jobId === latestJobId) {
                            selectionState.textContent = job.error || job.message || '画布编辑失败，未使用点数已退回';
                            selectionState.classList.add('error');
                        }
                        return;
                    }
                    if (attempts >= 240) {
                        finishPendingJob(jobId);
                        if (jobId === latestJobId) {
                            selectionState.textContent = '任务仍在后台处理，可通过任务进度胶囊继续查看';
                        }
                        return;
                    }
                    window.setTimeout(function () { pollJob(statusUrl, attempts + 1, jobId); }, 2500);
                })
                .catch(function (error) {
                    if (attempts >= 3) {
                        finishPendingJob(jobId);
                        if (jobId === latestJobId) {
                            selectionState.textContent = error.message || '无法获取任务进度，请通过任务进度胶囊继续查看';
                            selectionState.classList.add('error');
                        }
                        return;
                    }
                    window.setTimeout(function () { pollJob(statusUrl, attempts + 1, jobId); }, 2500);
                });
        }

        function graphPayload() {
            var exported = workflow.export();
            var data = exported.drawflow && exported.drawflow.Home ? exported.drawflow.Home.data : {};
            var nodes = [];
            var connections = [];
            Object.keys(data).forEach(function (nodeId) {
                var node = data[nodeId];
                var assetId = Number(node.data && node.data.assetId);
                if (!assetId || !assetsById[assetId]) {
                    return;
                }
                var nodeUuid = String(node.data && node.data.nodeUuid || uuidByNodeId[Number(nodeId)] || makeUuid('asset'));
                uuidByNodeId[Number(nodeId)] = nodeUuid;
                nodeByUuid[nodeUuid] = Number(nodeId);
                nodes.push({
                    id: nodeUuid,
                    type: 'asset',
                    assetId: assetId,
                    x: Number(node.pos_x || 0),
                    y: Number(node.pos_y || 0),
                });
                var outputs = node.outputs && node.outputs.output_1 ? node.outputs.output_1.connections : [];
                outputs.forEach(function (connection) {
                    var targetNodeId = Number(connection.node);
                    var targetUuid = uuidByNodeId[targetNodeId];
                    if (targetUuid) {
                        connections.push({
                            id: makeUuid('edge'),
                            sourceNodeId: nodeUuid,
                            targetNodeId: targetUuid,
                            relation: 'derived',
                        });
                    }
                });
            });
            return {
                nodes: nodes,
                connections: connections,
                cleared: nodes.length === 0,
                viewport: {x: Number(workflow.canvas_x || 0), y: Number(workflow.canvas_y || 0), zoom: Number(workflow.zoom || 1)},
            };
        }

        function scheduleSave() {
            if (restoring) {
                return;
            }
            hasUnsavedChanges = true;
            if (window.CanvasProjectStore) {
                window.CanvasProjectStore.updateBoard(graphPayload());
            }
        }

        function applyViewport(viewport) {
            var zoom = Math.max(0.25, Math.min(2, Number(viewport.zoom || 1)));
            workflow.zoom = zoom;
            workflow.zoom_last_value = zoom;
            workflow.canvas_x = Number(viewport.x || 0);
            workflow.canvas_y = Number(viewport.y || 0);
            workflow.precanvas.style.transform = 'translate(' + workflow.canvas_x + 'px, ' + workflow.canvas_y + 'px) scale(' + zoom + ')';
            updateZoomValue();
        }

        function updateZoomValue() {
            zoomValue.textContent = Math.round((workflow.zoom || 1) * 100) + '%';
        }

        function fitNodes(persist) {
            var nodes = Array.prototype.slice.call(drawflowRoot.querySelectorAll('.drawflow-node'));
            if (!nodes.length) {
                workflow.zoom_reset();
                return;
            }
            var minX = Infinity;
            var minY = Infinity;
            var maxX = -Infinity;
            var maxY = -Infinity;
            nodes.forEach(function (element) {
                var id = Number(element.id.replace('node-', ''));
                var node = workflow.getNodeFromId(id);
                minX = Math.min(minX, Number(node.pos_x));
                minY = Math.min(minY, Number(node.pos_y));
                maxX = Math.max(maxX, Number(node.pos_x) + element.offsetWidth);
                maxY = Math.max(maxY, Number(node.pos_y) + element.offsetHeight);
            });
            var rect = board.getBoundingClientRect();
            var scale = Math.max(0.35, Math.min(1.15, Math.min((rect.width - 120) / Math.max(1, maxX - minX), (rect.height - 120) / Math.max(1, maxY - minY))));
            applyViewport({zoom: scale, x: (rect.width - (maxX - minX) * scale) / 2 - minX * scale, y: (rect.height - (maxY - minY) * scale) / 2 - minY * scale});
            if (persist !== false) {
                scheduleSave();
            }
        }

        function clamp(value, min, max) {
            return Math.max(min, Math.min(max, value));
        }

        function positionAssetFloat(x, y, persist) {
            if (!assetFloat) {
                return;
            }
            var maxX = Math.max(8, board.clientWidth - assetFloat.offsetWidth - 8);
            var maxY = Math.max(8, board.clientHeight - assetFloat.offsetHeight - 8);
            assetFloatPosition.x = Math.round(clamp(Number(x) || 12, 8, maxX));
            assetFloatPosition.y = Math.round(clamp(Number(y) || 72, 8, maxY));
            assetFloat.style.left = assetFloatPosition.x + 'px';
            assetFloat.style.top = assetFloatPosition.y + 'px';
            if (persist) {
                try { window.localStorage.setItem('canvas-asset-float-position', JSON.stringify(assetFloatPosition)); } catch (error) {}
            }
        }

        function positionAssetDrawer() {
            if (!assetDrawer) {
                return;
            }
            if (window.innerWidth <= 520) {
                assetDrawer.style.left = '';
                assetDrawer.style.top = '';
                return;
            }
            var maxX = Math.max(8, board.clientWidth - assetDrawer.offsetWidth - 8);
            var maxY = Math.max(8, board.clientHeight - assetDrawer.offsetHeight - 8);
            assetDrawer.style.left = clamp(assetFloatPosition.x, 8, maxX) + 'px';
            assetDrawer.style.top = clamp(assetFloatPosition.y, 8, maxY) + 'px';
        }

        function clearDrawerCloseTimer() {
            window.clearTimeout(drawerCloseTimer);
            drawerCloseTimer = null;
        }

        function scheduleAssetDrawerCollapse() {
            clearDrawerCloseTimer();
            drawerCloseTimer = window.setTimeout(function () {
                if (assetDrawer && !assetDrawer.matches(':focus-within')) {
                    closeDrawer('assets');
                }
            }, 700);
        }

        function openDrawer(name) {
            var target = shell.querySelector('[data-workflow-drawer="' + name + '"]');
            if (!target) {
                return;
            }
            if (window.innerWidth <= 720) {
                shell.querySelectorAll('[data-workflow-drawer]').forEach(function (drawer) { drawer.classList.remove('is-open'); });
            }
            if (name === 'assets') {
                clearDrawerCloseTimer();
                positionAssetDrawer();
                if (assetFloat) {
                    assetFloat.classList.add('is-hidden');
                }
            }
            target.classList.add('is-open');
        }

        function closeDrawer(name) {
            var target = shell.querySelector('[data-workflow-drawer="' + name + '"]');
            if (target) {
                target.classList.remove('is-open');
            }
            if (name === 'assets' && assetFloat) {
                assetFloat.classList.remove('is-hidden');
            }
        }

        function clearCanvas() {
            if (Object.keys(nodeByAssetId).length === 0) {
                return;
            }
            if (!window.confirm('清空画布只会移除当前画布中的节点和连线，不会删除素材。确定清空吗？')) {
                return;
            }
            workflow.clear();
            Object.keys(nodeByAssetId).forEach(function (assetId) { delete nodeByAssetId[assetId]; });
            Object.keys(assetByNodeId).forEach(function (nodeId) { delete assetByNodeId[nodeId]; });
            selectedAssetId = 0;
            assetInput.value = '0';
            editButton.disabled = true;
            submit.disabled = true;
            closeDrawer('editor');
            result.hidden = true;
            taskProgress.hidden = true;
            applyViewport({x: 0, y: 0, zoom: 1});
            updateEmptyState();
            scheduleSave();
        }

        function updateEmptyState() {
            emptyState.hidden = Object.keys(nodeByAssetId).length > 0;
        }

        function openPreview(asset) {
            if (!asset) {
                return;
            }
            previewScale = 1;
            previewImage.src = safeImageUrl(asset.fileUrl);
            previewImage.setAttribute('data-fallback', asset.backupFileUrl || '');
            previewImage.removeAttribute('data-fallback-used');
            previewImage.alt = asset.assetTypeLabel;
            previewTitle.textContent = asset.assetTypeLabel + ' · ' + (asset.skuCode || '未归类');
            previewZoomOutput.textContent = '100%';
            previewDialog.showModal();
            document.body.classList.add('asset-viewer-open');
            if (previewImage.complete && previewImage.naturalWidth) {
                preparePreview();
            }
        }

        function preparePreview() {
            if (!previewImage.naturalWidth || !previewImage.naturalHeight) {
                return;
            }
            var style = window.getComputedStyle(previewStage);
            var availableWidth = Math.max(1, previewStage.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight));
            var availableHeight = Math.max(1, previewStage.clientHeight - parseFloat(style.paddingTop) - parseFloat(style.paddingBottom));
            var fit = Math.min(availableWidth / previewImage.naturalWidth, availableHeight / previewImage.naturalHeight);
            previewBaseWidth = Math.max(1, Math.round(previewImage.naturalWidth * fit));
            previewBaseHeight = Math.max(1, Math.round(previewImage.naturalHeight * fit));
            setPreviewScale(1, false);
        }

        function setPreviewScale(next, preserveCenter) {
            var oldWidth = Math.max(1, previewContent.offsetWidth);
            var oldHeight = Math.max(1, previewContent.offsetHeight);
            var centerX = (previewStage.scrollLeft + previewStage.clientWidth / 2) / oldWidth;
            var centerY = (previewStage.scrollTop + previewStage.clientHeight / 2) / oldHeight;
            previewScale = Math.max(0.5, Math.min(4, next));
            previewContent.style.width = Math.round(previewBaseWidth * previewScale) + 'px';
            previewContent.style.height = Math.round(previewBaseHeight * previewScale) + 'px';
            previewZoomOutput.textContent = Math.round(previewScale * 100) + '%';
            window.requestAnimationFrame(function () {
                if (preserveCenter === false) {
                    previewStage.scrollLeft = Math.max(0, (previewContent.offsetWidth - previewStage.clientWidth) / 2);
                    previewStage.scrollTop = Math.max(0, (previewContent.offsetHeight - previewStage.clientHeight) / 2);
                    return;
                }
                previewStage.scrollLeft = Math.max(0, centerX * previewContent.offsetWidth - previewStage.clientWidth / 2);
                previewStage.scrollTop = Math.max(0, centerY * previewContent.offsetHeight - previewStage.clientHeight / 2);
            });
        }

        function closePreview() {
            previewDialog.close();
            document.body.classList.remove('asset-viewer-open');
        }

        function prependAssetCard(asset) {
            var list = shell.querySelector('[data-workflow-asset-list]');
            if (!list || list.querySelector('[data-asset-id="' + asset.id + '"]')) {
                return;
            }
            var button = document.createElement('button');
            button.type = 'button';
            button.draggable = true;
            button.className = 'workflow-asset-card' + (asset.status === 'pending' ? ' is-pending' : '');
            button.setAttribute('data-workflow-asset-card', '');
            button.setAttribute('data-asset-id', String(asset.id));
            button.setAttribute('data-search-text', ((asset.skuCode || '') + ' ' + (asset.skuName || '') + ' ' + asset.assetTypeLabel).toLowerCase());
            button.innerHTML = '<img src="' + escapeHtml(safeImageUrl(asset.fileUrl)) + '" data-fallback="' + escapeHtml(safeImageUrl(asset.backupFileUrl || '')) + '" alt="' + escapeHtml(asset.assetTypeLabel) + '"><span><strong>' + escapeHtml(asset.assetTypeLabel) + '</strong><small>' + (asset.status === 'pending' ? '后台安全检查中' : escapeHtml((asset.skuCode || '未归类') + ' · ' + (asset.skuName || '素材'))) + '</small></span><i data-lucide="plus"></i>';
            list.prepend(button);
            bindAssetCard(button);
            setupImageFallbacks(button);
            refreshIcons(button);
        }

        function bindAssetCard(card) {
            card.addEventListener('click', function () {
                addAssetAt(Number(card.getAttribute('data-asset-id')));
                card.blur();
            });
            card.addEventListener('dragstart', function (event) {
                event.dataTransfer.setData('text/plain', card.getAttribute('data-asset-id'));
                event.dataTransfer.effectAllowed = 'copy';
            });
        }

        var restoredNodes = Array.isArray(workspace.nodes) ? workspace.nodes : [];
        restoredNodes.forEach(function (node) {
            var asset = assetsById[Number(node.assetId)];
            if (asset) {
                addAssetNode(asset, Number(node.x || 0), Number(node.y || 0), false, String(node.id || ''));
            }
        });
        var selectedFromUrl = Number(shell.getAttribute('data-selected-asset-id') || 0);
        var defaultAssetId = Number(shell.getAttribute('data-default-asset-id') || 0);
        if (selectedFromUrl && !nodeByAssetId[selectedFromUrl] && assetsById[selectedFromUrl]) {
            addAssetNode(assetsById[selectedFromUrl], 360, 180, false);
        } else if (!Object.keys(nodeByAssetId).length && !workspace.cleared && assetsById[defaultAssetId]) {
            addAssetNode(assetsById[defaultAssetId], 360, 180, false);
        }
        (Array.isArray(workspace.connections) ? workspace.connections : []).forEach(function (connection) {
            if (connection.sourceNodeId && connection.targetNodeId && nodeByUuid[connection.sourceNodeId] && nodeByUuid[connection.targetNodeId]) {
                var sourceAsset = assetByNodeId[nodeByUuid[connection.sourceNodeId]];
                var targetAsset = assetByNodeId[nodeByUuid[connection.targetNodeId]];
                connectAssets(Number(sourceAsset), Number(targetAsset));
            } else {
                connectAssets(Number(connection.sourceAssetId), Number(connection.targetAssetId));
            }
        });
        var repairedWorkspace = repairOverlappingNodes();
        applyViewport(workspace.viewport || {x: 0, y: 0, zoom: 1});
        var compactCanvasLayout = window.matchMedia('(max-width: 700px)').matches;
        var lastBoardWidth = board.clientWidth;
        if (compactCanvasLayout && Object.keys(nodeByAssetId).length) {
            window.requestAnimationFrame(function () { fitNodes(false); });
        }
        restoring = false;
        if (repairedWorkspace) {
            scheduleSave();
        }
        updateEmptyState();
        if (selectedFromUrl && nodeByAssetId[selectedFromUrl]) {
            selectAsset(selectedFromUrl, false);
        } else {
            var firstAssetId = Number(Object.keys(nodeByAssetId)[0] || 0);
            if (firstAssetId) {
                selectAsset(firstAssetId, false);
            }
        }

        var requestedTool = shell.getAttribute('data-requested-tool') || '';
        var toolConfig = {
            cutout: {preset: 'cutout', instruction: '移除杂乱背景，保留主体边缘、材质、Logo 和结构，输出干净的白色电商背景。'},
            portrait_clear: {preset: 'enhance', instruction: '修复人像面部清晰度、皮肤纹理和自然光影，保留人物真实五官与身份。'},
            head_cutout: {preset: 'cutout', instruction: '仅保留人物头部和肩部轮廓，去除原背景，保留真实五官、发型和肤色，输出干净白底头像。'},
            erase: {preset: 'cleanup', mode: 'regional', instruction: '仅擦除涂抹区域中的杂物或干扰元素，其余画面必须保持不变。'},
            watermark_remove: {preset: 'cleanup', mode: 'regional', instruction: '仅处理涂抹区域中属于自有或已获授权素材的覆盖标记，其他内容保持不变。'},
            translate: {preset: 'custom', instruction: '将画面中可编辑文字翻译为简体中文，保持原有版式、层级、字体风格和主体内容，不虚构品牌或参数。'},
            colorize: {preset: 'enhance', instruction: '为黑白照片自然上色，保持人物和场景结构不变，颜色真实克制，不增加新物体。'},
        }[requestedTool];
        if (toolConfig && selectedAssetId) {
            operationInputs.forEach(function (item) { item.checked = item.value === 'ai_edit'; });
            modeInputs.forEach(function (item) { item.checked = item.value === (toolConfig.mode || 'global'); });
            presetInputs.forEach(function (item) { item.checked = item.value === toolConfig.preset; });
            instruction.value = toolConfig.instruction;
            updateOperation();
            updateMode();
            openDrawer('editor');
        }

        workflow.on('nodeSelected', function (nodeId) {
            var assetId = assetByNodeId[Number(nodeId)];
            if (assetId) {
                selectAsset(assetId, false);
            }
        });
        workflow.on('nodeMoved', scheduleSave);
        workflow.on('connectionCreated', scheduleSave);
        workflow.on('connectionRemoved', scheduleSave);
        workflow.on('translate', function () { updateZoomValue(); scheduleSave(); });
        workflow.on('zoom', function () { updateZoomValue(); scheduleSave(); });
        workflow.on('nodeRemoved', function (nodeId) {
            var assetId = assetByNodeId[Number(nodeId)];
            var nodeUuid = uuidByNodeId[Number(nodeId)];
            delete assetByNodeId[Number(nodeId)];
            delete uuidByNodeId[Number(nodeId)];
            if (nodeUuid) {
                delete nodeByUuid[nodeUuid];
            }
            if (assetId) {
                delete nodeByAssetId[assetId];
                if (selectedAssetId === assetId) {
                    selectedAssetId = 0;
                    editButton.disabled = true;
                    closeDrawer('editor');
                }
            }
            updateEmptyState();
            scheduleSave();
        });

        function isNodeInteractiveTarget(target) {
            return Boolean(target.closest('[data-node-remove], [data-node-preview], [data-node-edit], .input, .output, input, select, textarea, button, a'));
        }

        drawflowRoot.addEventListener('pointerdown', function (event) {
            var nodeElement = event.target.closest('.drawflow-node.workflow-node');
            if (!nodeElement || event.button !== 0 || isNodeInteractiveTarget(event.target)) {
                return;
            }
            var nodeId = Number(nodeElement.id.replace('node-', ''));
            var assetId = assetByNodeId[nodeId];
            var node = workflow.getNodeFromId(nodeId);
            if (!assetId || !node) {
                return;
            }
            event.preventDefault();
            event.stopImmediatePropagation();
            selectAsset(assetId, false);
            nodeDrag = {
                pointerId: event.pointerId,
                nodeId: nodeId,
                startClientX: event.clientX,
                startClientY: event.clientY,
                startX: Number(node.pos_x),
                startY: Number(node.pos_y),
                moved: false,
            };
            drawflowRoot.setPointerCapture(event.pointerId);
            nodeElement.classList.add('is-dragging');
        }, true);
        drawflowRoot.addEventListener('pointermove', function (event) {
            if (!nodeDrag || event.pointerId !== nodeDrag.pointerId) {
                return;
            }
            var node = workflow.getNodeFromId(nodeDrag.nodeId);
            var element = drawflowRoot.querySelector('#node-' + nodeDrag.nodeId);
            if (!node || !element) {
                return;
            }
            var x = nodeDrag.startX + (event.clientX - nodeDrag.startClientX) / Math.max(.25, workflow.zoom || 1);
            var y = nodeDrag.startY + (event.clientY - nodeDrag.startClientY) / Math.max(.25, workflow.zoom || 1);
            node.pos_x = Math.round(x);
            node.pos_y = Math.round(y);
            element.style.left = node.pos_x + 'px';
            element.style.top = node.pos_y + 'px';
            workflow.updateConnectionNodes('node-' + nodeDrag.nodeId);
            nodeDrag.moved = true;
        }, true);
        drawflowRoot.addEventListener('pointerup', function (event) {
            if (!nodeDrag || event.pointerId !== nodeDrag.pointerId) {
                return;
            }
            var element = drawflowRoot.querySelector('#node-' + nodeDrag.nodeId);
            if (element) {
                element.classList.remove('is-dragging');
            }
            if (drawflowRoot.hasPointerCapture(event.pointerId)) {
                drawflowRoot.releasePointerCapture(event.pointerId);
            }
            if (nodeDrag.moved) {
                scheduleSave();
            }
            nodeDrag = null;
        }, true);
        drawflowRoot.addEventListener('pointercancel', function () { nodeDrag = null; }, true);
        drawflowRoot.addEventListener('mousedown', function (event) {
            if (nodeDrag) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        }, true);

        board.addEventListener('pointerdown', function (event) {
            if (event.button !== 0 || event.target.closest('.drawflow-node, .workflow-drawer, .workflow-toolbar, .workflow-asset-float, .workflow-save-state, .workflow-empty')) {
                return;
            }
            event.preventDefault();
            event.stopImmediatePropagation();
            canvasPan = {pointerId: event.pointerId, startClientX: event.clientX, startClientY: event.clientY, startX: Number(workflow.canvas_x || 0), startY: Number(workflow.canvas_y || 0), moved: false};
            board.setPointerCapture(event.pointerId);
            board.classList.add('is-panning');
        }, true);
        board.addEventListener('pointermove', function (event) {
            if (!canvasPan || event.pointerId !== canvasPan.pointerId) {
                if (assetDrawer && assetDrawer.classList.contains('is-open') && !assetDrawer.contains(event.target) && !assetDrawer.matches(':focus-within')) {
                    scheduleAssetDrawerCollapse();
                }
                return;
            }
            workflow.canvas_x = canvasPan.startX + event.clientX - canvasPan.startClientX;
            workflow.canvas_y = canvasPan.startY + event.clientY - canvasPan.startClientY;
            workflow.precanvas.style.transform = 'translate(' + workflow.canvas_x + 'px, ' + workflow.canvas_y + 'px) scale(' + (workflow.zoom || 1) + ')';
            canvasPan.moved = true;
        }, true);
        board.addEventListener('pointerup', function (event) {
            if (!canvasPan || event.pointerId !== canvasPan.pointerId) {
                return;
            }
            if (board.hasPointerCapture(event.pointerId)) {
                board.releasePointerCapture(event.pointerId);
            }
            board.classList.remove('is-panning');
            if (canvasPan.moved) {
                scheduleSave();
            }
            canvasPan = null;
        }, true);
        board.addEventListener('pointercancel', function () { canvasPan = null; board.classList.remove('is-panning'); }, true);
        board.addEventListener('mousedown', function (event) {
            if (canvasPan) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        }, true);

        drawflowRoot.addEventListener('click', function (event) {
            var nodeElement = event.target.closest('.drawflow-node');
            if (!nodeElement) {
                return;
            }
            var nodeId = Number(nodeElement.id.replace('node-', ''));
            var assetId = assetByNodeId[nodeId];
            if (event.target.closest('[data-node-remove]')) {
                event.stopPropagation();
                workflow.removeNodeId('node-' + nodeId);
                return;
            }
            if (event.target.closest('[data-node-preview]')) {
                event.stopPropagation();
                openPreview(assetsById[assetId]);
                return;
            }
            if (event.target.closest('[data-node-edit]')) {
                event.stopPropagation();
                selectAsset(assetId, true);
            }
        });
        drawflowRoot.addEventListener('dblclick', function (event) {
            var node = event.target.closest('.drawflow-node');
            if (node && event.target.closest('.workflow-node-image')) {
                openPreview(assetsById[assetByNodeId[Number(node.id.replace('node-', ''))]]);
            }
        });

        shell.querySelectorAll('[data-workflow-open]').forEach(function (button) { button.addEventListener('click', function () { openDrawer(button.getAttribute('data-workflow-open')); }); });
        shell.querySelectorAll('[data-workflow-close]').forEach(function (button) { button.addEventListener('click', function () { closeDrawer(button.getAttribute('data-workflow-close')); }); });
        shell.querySelectorAll('[data-workflow-asset-card]').forEach(bindAssetCard);
        document.addEventListener('canvas-asset-created', function (event) {
            var asset = event.detail && event.detail.asset ? event.detail.asset : null;
            if (!asset || !asset.id) {
                return;
            }
            assets.push(asset);
            if (!event.detail || event.detail.addToBoard !== false) {
                addResultAsset(asset, 0, true);
            } else {
                asset.id = Number(asset.id);
                assetsById[asset.id] = asset;
                prependAssetCard(asset);
            }
            var assetEmpty = shell.querySelector('[data-workflow-asset-empty]');
            var assetCount = shell.querySelector('[data-workflow-asset-count]');
            if (assetEmpty) {
                assetEmpty.hidden = true;
            }
            if (assetCount) {
                assetCount.textContent = Object.keys(assetsById).length + ' 张图片';
            }
            closeDrawer('assets');
        });
        document.addEventListener('canvas-asset-review-settled', function (event) {
            var detail = event.detail || {};
            var assetId = Number(detail.assetId || 0);
            var asset = assetsById[assetId];
            if (!asset) {
                return;
            }
            if (detail.status === 'completed') {
                Object.assign(asset, detail.asset || {}, {status: 'active'});
                var card = shell.querySelector('[data-workflow-asset-card][data-asset-id="' + assetId + '"]');
                if (card) {
                    card.classList.remove('is-pending');
                    var cardMeta = card.querySelector('small');
                    if (cardMeta) {
                        cardMeta.textContent = (asset.skuCode || '未归类') + ' · ' + (asset.skuName || '素材');
                    }
                }
                var node = drawflowRoot.querySelector('[data-node-asset-id="' + assetId + '"]');
                if (node) {
                    var nodeMeta = node.querySelector('.workflow-node-copy small');
                    var editAction = node.querySelector('[data-node-edit]');
                    if (nodeMeta) {
                        nodeMeta.textContent = (asset.skuCode || '未归类') + ' · ' + (asset.skuName || '素材');
                    }
                    if (editAction) {
                        editAction.disabled = false;
                        editAction.title = '编辑图片';
                    }
                }
                selectionState.textContent = '素材已通过安全检查，可以继续编辑';
                selectionState.classList.remove('error');
                return;
            }
            var nodeId = nodeByAssetId[assetId];
            if (nodeId) {
                workflow.removeNodeId('node-' + nodeId);
            }
            var failedCard = shell.querySelector('[data-workflow-asset-card][data-asset-id="' + assetId + '"]');
            if (failedCard) {
                failedCard.remove();
            }
            delete assetsById[assetId];
            selectionState.textContent = detail.message || '画布素材未通过安全检查，已从工作区移除';
            selectionState.classList.add('error');
        });
        setupImageFallbacks(shell);

        try {
            var savedFloatPosition = JSON.parse(window.localStorage.getItem('canvas-asset-float-position') || 'null');
            if (savedFloatPosition && typeof savedFloatPosition === 'object') {
                assetFloatPosition = {x: Number(savedFloatPosition.x) || 12, y: Number(savedFloatPosition.y) || 72};
            }
        } catch (error) {}
        window.requestAnimationFrame(function () { positionAssetFloat(assetFloatPosition.x, assetFloatPosition.y, false); });
        if (assetFloat && assetFloatDrag) {
            assetFloatDrag.addEventListener('pointerdown', function (event) {
                event.preventDefault();
                event.stopPropagation();
                assetFloatDragState = {pointerId: event.pointerId, startClientX: event.clientX, startClientY: event.clientY, startX: assetFloatPosition.x, startY: assetFloatPosition.y, moved: false};
                assetFloat.setPointerCapture(event.pointerId);
                assetFloat.classList.add('is-dragging');
            });
            assetFloat.addEventListener('pointermove', function (event) {
                if (!assetFloatDragState || event.pointerId !== assetFloatDragState.pointerId) {
                    return;
                }
                positionAssetFloat(assetFloatDragState.startX + event.clientX - assetFloatDragState.startClientX, assetFloatDragState.startY + event.clientY - assetFloatDragState.startClientY, false);
                assetFloatDragState.moved = true;
            });
            assetFloat.addEventListener('pointerup', function (event) {
                if (!assetFloatDragState || event.pointerId !== assetFloatDragState.pointerId) {
                    return;
                }
                if (assetFloat.hasPointerCapture(event.pointerId)) {
                    assetFloat.releasePointerCapture(event.pointerId);
                }
                assetFloat.classList.remove('is-dragging');
                if (assetFloatDragState.moved) {
                    positionAssetFloat(assetFloatPosition.x, assetFloatPosition.y, true);
                }
                assetFloatDragState = null;
            });
        }
        if (assetDrawer) {
            assetDrawer.addEventListener('pointerenter', clearDrawerCloseTimer);
            assetDrawer.addEventListener('pointerleave', scheduleAssetDrawerCollapse);
            assetDrawer.addEventListener('focusin', clearDrawerCloseTimer);
            assetDrawer.addEventListener('focusout', function () { window.setTimeout(scheduleAssetDrawerCollapse, 0); });
        }
        shell.querySelector('[data-workflow-clear-board]').addEventListener('click', clearCanvas);

        var search = shell.querySelector('[data-workflow-asset-search]');
        search.addEventListener('input', function () {
            var keyword = search.value.trim().toLowerCase();
            shell.querySelectorAll('[data-workflow-asset-card]').forEach(function (card) {
                card.hidden = keyword !== '' && (card.getAttribute('data-search-text') || '').indexOf(keyword) === -1;
            });
        });
        board.addEventListener('dragover', function (event) { event.preventDefault(); event.dataTransfer.dropEffect = 'copy'; });
        board.addEventListener('drop', function (event) {
            var assetId = Number(event.dataTransfer.getData('text/plain') || 0);
            if (!assetsById[assetId]) {
                return;
            }
            event.preventDefault();
            var rect = drawflowRoot.getBoundingClientRect();
            addAssetAt(assetId, {x: (event.clientX - rect.left - workflow.canvas_x) / workflow.zoom, y: (event.clientY - rect.top - workflow.canvas_y) / workflow.zoom});
        });

        shell.querySelector('[data-workflow-zoom-in]').addEventListener('click', function () { workflow.zoom_in(); updateZoomValue(); });
        shell.querySelector('[data-workflow-zoom-out]').addEventListener('click', function () { workflow.zoom_out(); updateZoomValue(); });
        zoomValue.addEventListener('click', function () { applyViewport({x: 0, y: 0, zoom: 1}); scheduleSave(); });
        shell.querySelector('[data-workflow-fit]').addEventListener('click', fitNodes);

        image.addEventListener('load', prepareCanvas);
        image.addEventListener('error', function () {
            var fallback = image.getAttribute('data-fallback');
            if (fallback && image.getAttribute('data-fallback-used') !== '1') {
                image.setAttribute('data-fallback-used', '1');
                image.src = safeImageUrl(fallback);
            }
        });
        shell.querySelector('[data-canvas-preview]').addEventListener('click', function () { openPreview(assetsById[selectedAssetId]); });
        brush.addEventListener('input', function () { brushOutput.textContent = brush.value + ' px'; });
        overlay.addEventListener('pointerdown', function (event) {
            if (currentMode() !== 'regional' || !overlay.width || !overlay.height) {
                return;
            }
            event.preventDefault();
            activeStroke = {size: brushSize(), points: [pointFromEvent(event)]};
            redoStrokes = [];
            strokes.push(activeStroke);
            overlay.setPointerCapture(event.pointerId);
            redraw();
        });
        overlay.addEventListener('pointermove', function (event) { if (activeStroke) { activeStroke.points.push(pointFromEvent(event)); redraw(); } });
        overlay.addEventListener('pointerup', function () { activeStroke = null; });
        overlay.addEventListener('pointercancel', function () { activeStroke = null; });
        undo.addEventListener('click', function () {
            var stroke = strokes.pop();
            if (stroke) {
                redoStrokes.push(stroke);
            }
            redraw();
        });
        redo.addEventListener('click', function () {
            var stroke = redoStrokes.pop();
            if (stroke) {
                strokes.push(stroke);
            }
            redraw();
        });
        clear.addEventListener('click', function () {
            redoStrokes = strokes.slice().reverse();
            strokes = [];
            redraw();
        });
        modeInputs.forEach(function (input) { input.addEventListener('change', updateMode); });
        operationInputs.forEach(function (input) { input.addEventListener('change', updateOperation); });
        presetInputs.forEach(function (input) { input.addEventListener('change', function () { if (input.checked && input.value === 'custom') { instruction.focus(); } }); });
        outputPreset.addEventListener('change', updateOutputHint);

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!selectedAssetId) {
                selectionState.textContent = '请先选择一个图片节点';
                selectionState.classList.add('error');
                return;
            }
            var operation = currentOperation();
            var mode = currentMode();
            var preset = selectedValue(presetInputs) || 'custom';
            if (operation === 'ai_edit' && mode === 'regional' && !strokes.length) {
                selectionState.textContent = '请先涂抹需要修改的区域';
                selectionState.classList.add('error');
                return;
            }
            if (operation === 'ai_edit' && preset === 'custom' && instruction.value.trim().length < 4) {
                selectionState.textContent = '自定义编辑请填写至少 4 个字的指令';
                selectionState.classList.add('error');
                instruction.focus();
                return;
            }
            var sourceAssetId = selectedAssetId;
            if (sourceNodeInput) {
                sourceNodeInput.value = uuidByNodeId[Number(nodeByAssetId[sourceAssetId])] || '';
            }
            maskInput.value = operation === 'ai_edit' && mode === 'regional' ? buildMask() : '';
            selectionState.classList.remove('error');
            selectionState.textContent = operation === 'ai_edit' ? '正在创建 AI 编辑任务...' : '正在创建尺寸调整任务...';
            setTaskProgress('正在提交', 5, true);
            result.hidden = true;
            var requestBody = new FormData(form);
            setBusy(true);
            requestJson(shell.getAttribute('data-canvas-endpoint'), {method: 'POST', body: requestBody, credentials: 'same-origin'}, true)
                .then(function (data) {
                    var jobId = Number(data.jobId || Date.now());
                    pendingJobs.set(jobId, {sourceAssetId: sourceAssetId, statusUrl: data.statusUrl});
                    latestJobId = jobId;
                    setNodeProcessing(sourceAssetId, true);
                    setBusy(false);
                    selectionState.textContent = (data.message || '编辑任务已进入队列') + '，现在可以切换其他图片继续处理。';
                    setTaskProgress('已进入处理队列', 12, true);
                    pollJob(data.statusUrl, 0, jobId);
                })
                .catch(function (error) {
                    selectionState.textContent = error.message || '画布编辑失败';
                    selectionState.classList.add('error');
                    setTaskProgress('提交失败', 0, false);
                    setBusy(false);
                });
        });

        editResult.addEventListener('click', function (event) {
            event.preventDefault();
            var assetId = Number(resultImage.getAttribute('data-result-asset-id') || selectedAssetId);
            if (assetId) {
                selectAsset(assetId, true);
            }
        });
        previewDialog.querySelector('[data-workflow-preview-minus]').addEventListener('click', function () { setPreviewScale(previewScale - 0.25); });
        previewDialog.querySelector('[data-workflow-preview-plus]').addEventListener('click', function () { setPreviewScale(previewScale + 0.25); });
        previewDialog.querySelector('[data-workflow-preview-close]').addEventListener('click', closePreview);
        previewDialog.addEventListener('click', function (event) { if (event.target === previewDialog) { closePreview(); } });
        previewDialog.addEventListener('close', function () { document.body.classList.remove('asset-viewer-open'); });
        previewDialog.querySelector('[data-workflow-preview-stage]').addEventListener('wheel', function (event) { event.preventDefault(); setPreviewScale(previewScale + (event.deltaY < 0 ? 0.25 : -0.25)); }, {passive: false});
        previewImage.addEventListener('error', function () {
            var fallback = previewImage.getAttribute('data-fallback');
            if (fallback && previewImage.getAttribute('data-fallback-used') !== '1') {
                previewImage.setAttribute('data-fallback-used', '1');
                previewImage.src = safeImageUrl(fallback);
            }
        });
        previewImage.addEventListener('load', preparePreview);
        window.addEventListener('resize', function () {
            if (previewDialog.open) {
                preparePreview();
            }
            positionAssetFloat(assetFloatPosition.x, assetFloatPosition.y, false);
            if (assetDrawer && assetDrawer.classList.contains('is-open')) {
                positionAssetDrawer();
            }
            var nextCompactLayout = window.matchMedia('(max-width: 700px)').matches;
            var nextBoardWidth = board.clientWidth;
            if (Object.keys(nodeByAssetId).length && (nextCompactLayout !== compactCanvasLayout
                || (nextCompactLayout && Math.abs(nextBoardWidth - lastBoardWidth) > 80))) {
                window.requestAnimationFrame(function () { fitNodes(false); });
            }
            compactCanvasLayout = nextCompactLayout;
            lastBoardWidth = nextBoardWidth;
        });
        window.addEventListener('beforeunload', function (event) {
            if (!hasUnsavedChanges || (window.CanvasProjectStore && !window.CanvasProjectStore.isDirty())) {
                return;
            }
            event.preventDefault();
            event.returnValue = '';
        });
        if (window.CanvasProjectStore) {
            window.CanvasProjectStore.subscribe(function (type) {
                if (type === 'saved') {
                    hasUnsavedChanges = false;
                }
            });
        }

        brushOutput.textContent = brush.value + ' px';
        updateOperation();
        updateOutputHint();
        updateZoomValue();
        refreshIcons(shell);
        if (nodeByAssetId[selectedAssetId]) {
            window.setTimeout(function () { workflow.updateConnectionNodes('node-' + nodeByAssetId[selectedAssetId]); }, 0);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupCanvasWorkflow);
    } else {
        setupCanvasWorkflow();
    }
})();
