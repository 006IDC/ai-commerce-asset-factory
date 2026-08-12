import {
    Canvas,
    Circle,
    FabricImage,
    FabricObject,
    Rect,
    Textbox,
} from '/static/vendor/fabric/index.min.js';

FabricObject.customProperties = ['assetId', 'projectNodeId', 'layerName'];

function setupCanvasDesign() {
    const shell = document.querySelector('[data-canvas-editor]');
    const canvasElement = shell?.querySelector('[data-design-canvas]');
    const store = window.CanvasProjectStore;
    if (!shell || !canvasElement || !store) {
        return;
    }

    const assets = parseJson(shell.querySelector('[data-canvas-assets-json]')?.textContent, []);
    const assetsById = new Map(assets.map((asset) => [Number(asset.id), asset]));
    const canvas = new Canvas(canvasElement, {
        preserveObjectStacking: true,
        selection: true,
        stopContextMenu: true,
    });
    const surface = shell.querySelector('[data-design-surface]');
    const stageScroll = shell.querySelector('[data-design-stage-scroll]');
    const tabs = shell.querySelector('[data-design-tabs]');
    const status = shell.querySelector('[data-design-status]');
    const layers = shell.querySelector('[data-design-layers]');
    const layerCount = shell.querySelector('[data-design-layer-count]');
    const selectionLabel = shell.querySelector('[data-design-selection-label]');
    const textField = shell.querySelector('[data-design-text-field]');
    const textInput = shell.querySelector('[data-design-text]');
    const fontField = shell.querySelector('[data-design-font-field]');
    const fontSizeInput = shell.querySelector('[data-design-font-size]');
    const fillInput = shell.querySelector('[data-design-fill]');
    const opacityInput = shell.querySelector('[data-design-opacity]');
    const opacityValue = shell.querySelector('[data-design-opacity-value]');
    const presetInput = shell.querySelector('[data-design-preset]');
    const backgroundInput = shell.querySelector('[data-design-background]');
    const transparentInput = shell.querySelector('[data-design-transparent]');
    const sizeLabel = shell.querySelector('[data-design-size-label]');
    const zoomOutput = shell.querySelector('[data-design-zoom]');
    const uploadInput = shell.querySelector('[data-design-upload-input]');
    const assetsPanel = shell.querySelector('[data-design-assets-panel]');
    const exportButton = shell.querySelector('[data-design-export]');
    const selectionControls = [
        textInput,
        fontSizeInput,
        fillInput,
        opacityInput,
        shell.querySelector('[data-design-back]'),
        shell.querySelector('[data-design-front]'),
        shell.querySelector('[data-design-duplicate]'),
        shell.querySelector('[data-design-delete]'),
    ].filter(Boolean);

    let documentState = store.getDocument();
    let activeDesignId = String(documentState.activeDesignId || documentState.designs?.[0]?.id || '');
    let loading = false;
    let changeTimer = null;
    let zoom = 1;
    let history = [];
    let historyIndex = -1;
    let uploadBusy = false;

    function parseJson(value, fallback) {
        try {
            const parsed = JSON.parse(value || '');
            return parsed && typeof parsed === 'object' ? parsed : fallback;
        } catch (error) {
            return fallback;
        }
    }

    function uuid(prefix) {
        const bytes = new Uint8Array(8);
        crypto.getRandomValues(bytes);
        return `${prefix}-${Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('')}`;
    }

    function activeDesign() {
        return documentState.designs.find((design) => String(design.id) === activeDesignId) || documentState.designs[0];
    }

    function setStatus(message, state = '') {
        status.textContent = message;
        status.classList.toggle('is-error', state === 'error');
        status.classList.toggle('is-busy', state === 'busy');
    }

    function setBusy(button, busy, busyLabel) {
        button.disabled = busy;
        button.classList.toggle('is-loading', busy);
        const label = button.querySelector('span');
        if (!label) {
            return;
        }
        if (busy) {
            button.dataset.originalLabel = label.textContent;
            label.textContent = busyLabel;
        } else if (button.dataset.originalLabel) {
            label.textContent = button.dataset.originalLabel;
            delete button.dataset.originalLabel;
        }
    }

    function currentToken() {
        return shell.querySelector('input[name="_token"]')?.value || '';
    }

    async function refreshCsrf() {
        const response = await fetch(shell.getAttribute('data-csrf-endpoint'), {credentials: 'same-origin', cache: 'no-store'});
        const data = await response.json();
        if (!response.ok || !data.ok || !data.token) {
            throw new Error(data.message || '安全令牌刷新失败');
        }
        shell.querySelectorAll('input[name="_token"]').forEach((input) => { input.value = data.token; });
        return data.token;
    }

    async function sendAsset(payload, retry = true) {
        const response = await fetch(shell.getAttribute('data-canvas-asset-endpoint'), {
            method: 'POST',
            body: payload,
            credentials: 'same-origin',
        });
        if (response.status === 419 && retry) {
            payload.set('_token', await refreshCsrf());
            return sendAsset(payload, false);
        }
        const body = await response.text();
        const data = parseJson(body, {});
        if (!response.ok || !data.ok) {
            throw new Error(data.message || `素材保存失败（HTTP ${response.status}）`);
        }
        return data;
    }

    function canvasJson() {
        return canvas.toJSON(['assetId', 'projectNodeId', 'layerName']);
    }

    function designSnapshot() {
        const design = activeDesign();
        return JSON.stringify({
            width: canvas.width,
            height: canvas.height,
            background: design.background,
            transparent: design.transparent,
            canvasJson: canvasJson(),
        });
    }

    function recordHistory() {
        if (loading) {
            return;
        }
        const snapshot = designSnapshot();
        if (history[historyIndex] === snapshot) {
            return;
        }
        history = history.slice(0, historyIndex + 1);
        history.push(snapshot);
        if (history.length > 50) {
            history.shift();
        }
        historyIndex = history.length - 1;
        syncHistoryButtons();
    }

    function syncHistoryButtons() {
        shell.querySelector('[data-design-undo]').disabled = historyIndex <= 0;
        shell.querySelector('[data-design-redo]').disabled = historyIndex < 0 || historyIndex >= history.length - 1;
    }

    function commitDesign(delay = 500) {
        if (loading) {
            return;
        }
        const design = activeDesign();
        const serialized = canvasJson();
        const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
        store.update((documentValue) => {
            const target = documentValue.designs.find((item) => String(item.id) === activeDesignId);
            if (!target) {
                return;
            }
            target.width = Number(canvas.width);
            target.height = Number(canvas.height);
            target.background = design.background;
            target.transparent = Boolean(design.transparent);
            target.canvasJson = serialized;
            target.updatedAt = now;
            documentValue.activeDesignId = activeDesignId;
            documentValue.activeView = 'design';
        }, {source: 'design', delay});
        documentState = store.getDocument();
    }

    function scheduleChange() {
        if (loading) {
            return;
        }
        window.clearTimeout(changeTimer);
        changeTimer = window.setTimeout(() => {
            recordHistory();
            renderLayers();
            commitDesign();
        }, 80);
    }

    function hydrateCanvasJson(json) {
        const hydrated = JSON.parse(JSON.stringify(json || {version: '7.4.0', objects: []}));
        hydrated.objects = Array.isArray(hydrated.objects) ? hydrated.objects : [];
        hydrated.objects.forEach((object) => {
            if (!['image', 'Image', 'FabricImage'].includes(String(object.type || ''))) {
                return;
            }
            const asset = assetsById.get(Number(object.assetId || 0));
            if (asset) {
                object.src = asset.fileUrl || asset.backupFileUrl || '';
                object.crossOrigin = 'anonymous';
            }
        });
        return hydrated;
    }

    async function loadDesign(designId, resetHistory = true) {
        const next = documentState.designs.find((design) => String(design.id) === String(designId));
        if (!next) {
            return;
        }
        activeDesignId = String(next.id);
        loading = true;
        window.clearTimeout(changeTimer);
        canvas.discardActiveObject();
        canvas.clear();
        canvas.setDimensions({width: Number(next.width), height: Number(next.height)});
        try {
            await canvas.loadFromJSON(hydrateCanvasJson(next.canvasJson));
        } catch (error) {
            setStatus('部分图片未能载入，其他图层仍可继续编辑。', 'error');
        }
        canvas.backgroundColor = next.transparent ? '' : next.background;
        canvas.requestRenderAll();
        backgroundInput.value = next.background || '#ffffff';
        transparentInput.checked = Boolean(next.transparent);
        syncPreset(next.width, next.height);
        sizeLabel.textContent = `${next.width} × ${next.height}`;
        loading = false;
        if (resetHistory) {
            history = [];
            historyIndex = -1;
            recordHistory();
        }
        renderTabs();
        renderLayers();
        syncSelection();
        fitCanvas();
        setStatus('画面已载入，修改会自动保存。');
    }

    function syncPreset(width, height) {
        const matching = Array.from(presetInput.options).find((option) => (
            Number(option.dataset.width) === Number(width)
            && Number(option.dataset.height) === Number(height)
        ));
        if (matching) {
            presetInput.value = matching.value;
        }
    }

    function renderTabs() {
        tabs.replaceChildren();
        documentState.designs.forEach((design, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = String(design.id) === activeDesignId ? 'active' : '';
            button.setAttribute('aria-pressed', String(design.id) === activeDesignId ? 'true' : 'false');
            button.innerHTML = `<span>${escapeHtml(design.title || `画面 ${index + 1}`)}</span><small>${design.width}×${design.height}</small>`;
            button.addEventListener('click', async () => {
                if (String(design.id) === activeDesignId) {
                    return;
                }
                commitDesign(0);
                await store.saveNow('auto').catch(() => {});
                documentState = store.getDocument();
                await loadDesign(String(design.id));
                store.update((documentValue) => {
                    documentValue.activeDesignId = String(design.id);
                    documentValue.activeView = 'design';
                }, {source: 'navigation', delay: 200});
            });
            tabs.append(button);
        });
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function objectLabel(object, index) {
        if (object.layerName) {
            return object.layerName;
        }
        const type = String(object.type || '').toLowerCase();
        if (type.includes('text')) {
            return String(object.text || '文字').slice(0, 18);
        }
        if (type.includes('image')) {
            return '图片';
        }
        if (type === 'circle') {
            return '圆形';
        }
        if (type === 'rect') {
            return '矩形';
        }
        return `图层 ${index + 1}`;
    }

    function renderLayers() {
        const objects = canvas.getObjects();
        layerCount.textContent = `${objects.length} 个`;
        layers.replaceChildren();
        [...objects].reverse().forEach((object, reverseIndex) => {
            const originalIndex = objects.length - reverseIndex - 1;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = canvas.getActiveObject() === object ? 'active' : '';
            button.innerHTML = `<i data-lucide="${String(object.type || '').toLowerCase().includes('text') ? 'type' : String(object.type || '').toLowerCase().includes('image') ? 'image' : 'shapes'}"></i><span>${escapeHtml(objectLabel(object, originalIndex))}</span>`;
            button.addEventListener('click', () => {
                canvas.setActiveObject(object);
                canvas.requestRenderAll();
                syncSelection();
            });
            layers.append(button);
        });
        window.lucide?.createIcons();
    }

    function normalizeHex(value, fallback = '#172033') {
        return /^#[0-9a-f]{6}$/i.test(String(value || '')) ? String(value).toLowerCase() : fallback;
    }

    function syncSelection() {
        const object = canvas.getActiveObject();
        selectionControls.forEach((control) => { control.disabled = !object; });
        if (!object) {
            selectionLabel.textContent = '未选择';
            textField.hidden = true;
            fontField.hidden = true;
            renderLayers();
            return;
        }
        const type = String(object.type || '').toLowerCase();
        const isText = type.includes('text');
        selectionLabel.textContent = objectLabel(object, canvas.getObjects().indexOf(object));
        textField.hidden = !isText;
        fontField.hidden = !isText;
        if (isText) {
            textInput.value = String(object.text || '');
            fontSizeInput.value = String(Math.round(Number(object.fontSize || 64)));
        }
        fillInput.value = normalizeHex(object.fill, '#172033');
        opacityInput.value = String(Math.round(Number(object.opacity ?? 1) * 100));
        opacityValue.textContent = `${opacityInput.value}%`;
        renderLayers();
    }

    function centerObject(object) {
        object.set({
            left: Math.max(24, (canvas.width - Number(object.width || 300) * Number(object.scaleX || 1)) / 2),
            top: Math.max(24, (canvas.height - Number(object.height || 120) * Number(object.scaleY || 1)) / 2),
        });
        object.setCoords();
    }

    function addText() {
        const object = new Textbox('输入商品卖点', {
            width: Math.min(680, canvas.width * 0.72),
            fontSize: Math.max(34, Math.round(canvas.width / 16)),
            fontFamily: 'Arial, "PingFang SC", sans-serif',
            fontWeight: 700,
            fill: '#172033',
            textAlign: 'center',
            projectNodeId: uuid('text'),
            layerName: '商品卖点',
        });
        centerObject(object);
        canvas.add(object);
        canvas.setActiveObject(object);
        canvas.requestRenderAll();
    }

    function addShape(type) {
        const common = {
            left: canvas.width * 0.32,
            top: canvas.height * 0.32,
            fill: '#18a7b5',
            opacity: 0.92,
            projectNodeId: uuid(type),
            layerName: type === 'circle' ? '圆形' : '矩形',
        };
        const object = type === 'circle'
            ? new Circle({...common, radius: Math.max(60, canvas.width * 0.1)})
            : new Rect({...common, width: Math.max(180, canvas.width * 0.25), height: Math.max(120, canvas.height * 0.16), rx: 8, ry: 8});
        canvas.add(object);
        canvas.setActiveObject(object);
        canvas.requestRenderAll();
    }

    async function addAssetImage(asset) {
        if (!asset?.fileUrl) {
            return;
        }
        setStatus('正在载入图片…', 'busy');
        try {
            let object;
            try {
                object = await FabricImage.fromURL(asset.fileUrl, {crossOrigin: 'anonymous'});
            } catch (error) {
                if (!asset.backupFileUrl) {
                    throw error;
                }
                object = await FabricImage.fromURL(asset.backupFileUrl, {crossOrigin: 'anonymous'});
            }
            const maxWidth = canvas.width * 0.78;
            const maxHeight = canvas.height * 0.78;
            const scale = Math.min(maxWidth / object.width, maxHeight / object.height, 1);
            object.set({
                scaleX: scale,
                scaleY: scale,
                assetId: Number(asset.id),
                projectNodeId: uuid('image'),
                layerName: asset.assetTypeLabel || '图片',
            });
            centerObject(object);
            canvas.add(object);
            canvas.setActiveObject(object);
            canvas.requestRenderAll();
            setStatus('图片已加入画面。');
        } catch (error) {
            setStatus('图片载入失败，请确认素材仍然可用。', 'error');
        }
    }

    async function uploadLocalImage(file) {
        if (!file || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
            setStatus('图片仅支持 JPG、PNG 和 WebP。', 'error');
            return;
        }
        if (file.size <= 0 || file.size > 24 * 1024 * 1024) {
            setStatus('图片超过允许大小，单张不能超过 24MB。', 'error');
            return;
        }
        if (uploadBusy) {
            return;
        }
        uploadBusy = true;
        setStatus('正在保存图片，完成后会立即加入画面…', 'busy');
        const payload = new FormData();
        payload.append('_token', currentToken());
        payload.append('source_type', 'upload');
        payload.append('project_id', String(store.projectId));
        payload.append('design_id', activeDesignId);
        payload.append('node_uuid', uuid('design-image'));
        payload.append('image', file, file.name);
        try {
            const data = await sendAsset(payload);
            const asset = data.asset;
            assetsById.set(Number(asset.id), asset);
            document.dispatchEvent(new CustomEvent('canvas-asset-created', {detail: {asset, addToBoard: false}}));
            await addAssetImage(asset);
            setStatus(data.pendingReview ? '图片已加入画面，内容安全检查正在后台进行。' : '图片已加入画面并保存到素材库。');
        } catch (error) {
            setStatus(error.message || '图片上传失败。', 'error');
        } finally {
            uploadBusy = false;
            uploadInput.value = '';
        }
    }

    function changeCanvasSize(width, height) {
        const oldWidth = Number(canvas.width);
        const oldHeight = Number(canvas.height);
        const positionScaleX = width / oldWidth;
        const positionScaleY = height / oldHeight;
        const objectScale = Math.min(positionScaleX, positionScaleY);
        canvas.getObjects().forEach((object) => {
            object.set({
                left: Number(object.left || 0) * positionScaleX,
                top: Number(object.top || 0) * positionScaleY,
                scaleX: Number(object.scaleX || 1) * objectScale,
                scaleY: Number(object.scaleY || 1) * objectScale,
            });
            object.setCoords();
        });
        canvas.setDimensions({width, height});
        const design = activeDesign();
        design.width = width;
        design.height = height;
        sizeLabel.textContent = `${width} × ${height}`;
        fitCanvas();
        canvas.requestRenderAll();
        scheduleChange();
    }

    function setZoom(nextZoom) {
        zoom = Math.max(0.2, Math.min(1.5, nextZoom));
        const width = Number(canvas.width);
        const height = Number(canvas.height);
        surface.style.width = `${Math.round(width * zoom)}px`;
        surface.style.height = `${Math.round(height * zoom)}px`;
        const wrapper = canvas.wrapperEl;
        wrapper.style.transformOrigin = 'top left';
        wrapper.style.transform = `scale(${zoom})`;
        zoomOutput.textContent = `${Math.round(zoom * 100)}%`;
    }

    function fitCanvas() {
        if (shell.getAttribute('data-active-view') !== 'design') {
            return;
        }
        const availableWidth = Math.max(280, stageScroll.clientWidth - 72);
        const availableHeight = Math.max(280, stageScroll.clientHeight - 72);
        setZoom(Math.min(1, availableWidth / canvas.width, availableHeight / canvas.height));
    }

    async function restoreHistory(index) {
        if (index < 0 || index >= history.length) {
            return;
        }
        const snapshot = parseJson(history[index], null);
        if (!snapshot) {
            return;
        }
        loading = true;
        canvas.setDimensions({width: snapshot.width, height: snapshot.height});
        await canvas.loadFromJSON(hydrateCanvasJson(snapshot.canvasJson));
        const design = activeDesign();
        design.background = snapshot.background;
        design.transparent = Boolean(snapshot.transparent);
        canvas.backgroundColor = design.transparent ? '' : design.background;
        canvas.requestRenderAll();
        loading = false;
        historyIndex = index;
        backgroundInput.value = design.background;
        transparentInput.checked = design.transparent;
        sizeLabel.textContent = `${snapshot.width} × ${snapshot.height}`;
        syncPreset(snapshot.width, snapshot.height);
        syncHistoryButtons();
        renderLayers();
        syncSelection();
        fitCanvas();
        commitDesign(120);
    }

    async function exportDesign() {
        if (exportButton.disabled) {
            return;
        }
        setBusy(exportButton, true, '正在导出');
        setStatus('正在导出图片并保存到素材库…', 'busy');
        commitDesign(0);
        await store.saveNow('export').catch(() => {});
        try {
            const type = activeDesign().transparent ? 'image/png' : 'image/webp';
            const blob = await new Promise((resolve, reject) => {
                canvas.getElement().toBlob((value) => value ? resolve(value) : reject(new Error('浏览器无法导出当前画面。')), type, type === 'image/webp' ? 0.92 : undefined);
            });
            const payload = new FormData();
            payload.append('_token', currentToken());
            payload.append('source_type', 'design');
            payload.append('project_id', String(store.projectId));
            payload.append('design_id', activeDesignId);
            payload.append('node_uuid', `${activeDesignId}-output`);
            payload.append('image', blob, type === 'image/png' ? 'canvas-design.png' : 'canvas-design.webp');
            const data = await sendAsset(payload);
            const asset = data.asset;
            assetsById.set(Number(asset.id), asset);
            store.update((documentValue) => {
                const design = documentValue.designs.find((item) => String(item.id) === activeDesignId);
                if (design) {
                    design.outputAssetId = Number(asset.id);
                }
                const board = documentValue.board || {nodes: [], connections: [], viewport: {x: 0, y: 0, zoom: 1}};
                if (!board.nodes.some((node) => Number(node.assetId) === Number(asset.id))) {
                    board.nodes.push({
                        id: uuid('asset'),
                        type: 'asset',
                        assetId: Number(asset.id),
                        designId: activeDesignId,
                        x: 280 + (board.nodes.length % 4) * 340,
                        y: 160 + Math.floor(board.nodes.length / 4) * 300,
                    });
                    board.cleared = false;
                }
                documentValue.board = board;
            }, {source: 'export', delay: 80});
            document.dispatchEvent(new CustomEvent('canvas-asset-created', {detail: {asset, addToBoard: true}}));
            document.dispatchEvent(new CustomEvent('global-task-refresh'));
            setStatus(data.pendingReview ? '已导出，内容安全检查在后台进行；通过后自动进入素材库。' : '已导出到素材库，并保留可编辑图层。');
        } catch (error) {
            setStatus(error.message || '画面导出失败。', 'error');
        } finally {
            setBusy(exportButton, false, '');
        }
    }

    canvas.on('object:added', scheduleChange);
    canvas.on('object:modified', scheduleChange);
    canvas.on('object:removed', scheduleChange);
    canvas.on('text:changed', scheduleChange);
    canvas.on('selection:created', syncSelection);
    canvas.on('selection:updated', syncSelection);
    canvas.on('selection:cleared', syncSelection);

    shell.querySelectorAll('[data-design-tool]').forEach((button) => {
        button.addEventListener('click', () => {
            const tool = button.getAttribute('data-design-tool');
            if (tool === 'upload') {
                uploadInput.click();
            } else if (tool === 'assets') {
                assetsPanel.hidden = false;
            } else if (tool === 'text') {
                addText();
            } else if (tool === 'rect' || tool === 'circle') {
                addShape(tool);
            }
        });
    });
    uploadInput.addEventListener('change', () => {
        if (uploadInput.files?.[0]) {
            uploadLocalImage(uploadInput.files[0]);
        }
    });
    shell.querySelectorAll('[data-design-add-asset]').forEach((button) => {
        button.addEventListener('click', () => addAssetImage(assetsById.get(Number(button.getAttribute('data-design-add-asset')))));
    });
    shell.querySelector('[data-design-assets-close]').addEventListener('click', () => { assetsPanel.hidden = true; });
    shell.querySelector('[data-design-add]').addEventListener('click', async () => {
        if (documentState.designs.length >= 30) {
            setStatus('单个项目最多可以创建 30 个画面。', 'error');
            return;
        }
        commitDesign(0);
        const current = activeDesign();
        const designId = uuid('design');
        store.update((documentValue) => {
            documentValue.designs.push({
                id: designId,
                title: `画面 ${documentValue.designs.length + 1}`,
                width: current.width,
                height: current.height,
                background: '#ffffff',
                transparent: false,
                canvasJson: {version: '7.4.0', objects: []},
                outputAssetId: 0,
                updatedAt: new Date().toISOString().slice(0, 19).replace('T', ' '),
            });
            documentValue.activeDesignId = designId;
            documentValue.activeView = 'design';
        }, {source: 'design', delay: 120});
        documentState = store.getDocument();
        await loadDesign(designId);
    });
    shell.querySelector('[data-design-undo]').addEventListener('click', () => restoreHistory(historyIndex - 1));
    shell.querySelector('[data-design-redo]').addEventListener('click', () => restoreHistory(historyIndex + 1));
    shell.querySelector('[data-design-zoom-out]').addEventListener('click', () => setZoom(zoom - 0.1));
    shell.querySelector('[data-design-zoom-in]').addEventListener('click', () => setZoom(zoom + 0.1));
    exportButton.addEventListener('click', exportDesign);

    presetInput.addEventListener('change', () => {
        const option = presetInput.selectedOptions[0];
        changeCanvasSize(Number(option.dataset.width), Number(option.dataset.height));
    });
    backgroundInput.addEventListener('input', () => {
        const design = activeDesign();
        design.background = backgroundInput.value;
        canvas.backgroundColor = design.transparent ? '' : design.background;
        canvas.requestRenderAll();
        scheduleChange();
    });
    transparentInput.addEventListener('change', () => {
        const design = activeDesign();
        design.transparent = transparentInput.checked;
        canvas.backgroundColor = design.transparent ? '' : design.background;
        canvas.requestRenderAll();
        scheduleChange();
    });
    textInput.addEventListener('input', () => {
        const object = canvas.getActiveObject();
        if (object && String(object.type || '').toLowerCase().includes('text')) {
            object.set('text', textInput.value);
            object.setCoords();
            canvas.requestRenderAll();
            scheduleChange();
        }
    });
    fontSizeInput.addEventListener('input', () => {
        const object = canvas.getActiveObject();
        if (object && String(object.type || '').toLowerCase().includes('text')) {
            object.set('fontSize', Math.max(8, Math.min(320, Number(fontSizeInput.value || 64))));
            object.setCoords();
            canvas.requestRenderAll();
            scheduleChange();
        }
    });
    fillInput.addEventListener('input', () => {
        const object = canvas.getActiveObject();
        if (object && !String(object.type || '').toLowerCase().includes('image')) {
            object.set('fill', fillInput.value);
            canvas.requestRenderAll();
            scheduleChange();
        }
    });
    opacityInput.addEventListener('input', () => {
        const object = canvas.getActiveObject();
        opacityValue.textContent = `${opacityInput.value}%`;
        if (object) {
            object.set('opacity', Number(opacityInput.value) / 100);
            canvas.requestRenderAll();
            scheduleChange();
        }
    });
    shell.querySelector('[data-design-delete]').addEventListener('click', () => {
        const object = canvas.getActiveObject();
        if (object) {
            canvas.remove(object);
            canvas.discardActiveObject();
            canvas.requestRenderAll();
            syncSelection();
        }
    });
    shell.querySelector('[data-design-duplicate]').addEventListener('click', async () => {
        const object = canvas.getActiveObject();
        if (!object) {
            return;
        }
        const copy = await object.clone(['assetId', 'projectNodeId', 'layerName']);
        copy.set({
            left: Number(object.left || 0) + 28,
            top: Number(object.top || 0) + 28,
            projectNodeId: uuid('object'),
        });
        canvas.add(copy);
        canvas.setActiveObject(copy);
        canvas.requestRenderAll();
    });
    shell.querySelector('[data-design-front]').addEventListener('click', () => {
        const object = canvas.getActiveObject();
        const objects = canvas.getObjects();
        const index = objects.indexOf(object);
        if (object && index >= 0 && index < objects.length - 1) {
            canvas.moveObjectTo(object, index + 1);
            canvas.requestRenderAll();
            scheduleChange();
        }
    });
    shell.querySelector('[data-design-back]').addEventListener('click', () => {
        const object = canvas.getActiveObject();
        const objects = canvas.getObjects();
        const index = objects.indexOf(object);
        if (object && index > 0) {
            canvas.moveObjectTo(object, index - 1);
            canvas.requestRenderAll();
            scheduleChange();
        }
    });

    document.addEventListener('paste', (event) => {
        if (shell.getAttribute('data-active-view') !== 'design') {
            return;
        }
        const imageItem = Array.from(event.clipboardData?.items || []).find((item) => item.type.startsWith('image/'));
        if (imageItem) {
            event.preventDefault();
            uploadLocalImage(imageItem.getAsFile());
        }
    });
    window.addEventListener('canvas-view-changed', (event) => {
        if (event.detail?.view === 'design') {
            documentState = store.getDocument();
            fitCanvas();
            canvas.calcOffset();
        }
    });
    window.addEventListener('resize', () => window.setTimeout(fitCanvas, 80));

    documentState = store.getDocument();
    if (!Array.isArray(documentState.designs) || documentState.designs.length === 0) {
        return;
    }
    loadDesign(activeDesignId);
    syncSelection();
    syncHistoryButtons();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupCanvasDesign);
} else {
    setupCanvasDesign();
}
