(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var shell = document.querySelector('[data-image-tools]');
        if (!shell) {
            return;
        }
        var input = shell.querySelector('[data-image-tools-file]');
        var preview = shell.querySelector('[data-image-tools-preview]');
        var previewImage = shell.querySelector('[data-image-tools-preview-image]');
        var fileLabel = shell.querySelector('[data-image-tools-file-label]');
        var status = shell.querySelector('[data-image-tools-local-status]');
        var result = shell.querySelector('[data-image-tools-result]');
        var resultImage = shell.querySelector('[data-image-tools-result-image]');
        var resultTitle = shell.querySelector('[data-image-tools-result-title]');
        var resultMeta = shell.querySelector('[data-image-tools-result-meta]');
        var download = shell.querySelector('[data-image-tools-download]');
        var assetSelect = shell.querySelector('[data-image-tools-asset]');
        var assetPreview = shell.querySelector('[data-image-tools-asset-preview]');
        var promptResult = shell.querySelector('[data-image-tools-prompt-result]');
        var promptOutput = shell.querySelector('[data-image-tools-prompt-output]');
        var promptEmpty = shell.querySelector('[data-image-tools-prompt-empty]');
        var promptStatus = shell.querySelector('[data-image-tools-prompt-status]');
        var promptHistory = shell.querySelector('[data-image-tools-prompt-history]');
        var usePrompt = shell.querySelector('[data-image-tools-use-prompt]');
        var sourceFiles = [];
        var previewUrl = null;
        var resultUrl = null;

        function setStatus(message, error) {
            if (!status) return;
            status.textContent = message;
            status.classList.toggle('error', Boolean(error));
        }

        function setPromptStatus(message, error) {
            if (!promptStatus) return;
            promptStatus.textContent = message;
            promptStatus.classList.toggle('error', Boolean(error));
        }

        function revokeResult() {
            if (resultUrl) {
                URL.revokeObjectURL(resultUrl);
                resultUrl = null;
            }
        }

        function clearFiles() {
            sourceFiles = [];
            input.value = '';
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = null;
            preview.hidden = true;
            previewImage.removeAttribute('src');
            fileLabel.textContent = '支持 JPG、PNG、WebP';
            result.hidden = true;
            revokeResult();
            setStatus('选择图片后开始处理', false);
        }

        function loadImage(file) {
            return new Promise(function (resolve, reject) {
                var url = URL.createObjectURL(file);
                var image = new Image();
                image.onload = function () { URL.revokeObjectURL(url); resolve(image); };
                image.onerror = function () { URL.revokeObjectURL(url); reject(new Error('图片无法读取')); };
                image.src = url;
            });
        }

        function verifyCanvas(width, height) {
            if (width * height > 42000000 || width > 7680 || height > 7680) {
                throw new Error('目标尺寸过大，请使用较小的原图或选择较低放大规格。');
            }
        }

        function drawImage(image, width, height, filter) {
            verifyCanvas(width, height);
            var canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            var context = canvas.getContext('2d');
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, width, height);
            context.imageSmoothingEnabled = true;
            context.imageSmoothingQuality = 'high';
            context.filter = filter || 'none';
            context.drawImage(image, 0, 0, width, height);
            return canvas;
        }

        function canvasBlob(canvas, type, quality) {
            return new Promise(function (resolve, reject) {
                canvas.toBlob(function (blob) { blob ? resolve(blob) : reject(new Error('图片编码失败')); }, type, quality);
            });
        }

        function showResult(blob, title, meta, extension) {
            revokeResult();
            resultUrl = URL.createObjectURL(blob);
            resultImage.src = resultUrl;
            resultTitle.textContent = title;
            resultMeta.textContent = meta;
            download.href = resultUrl;
            download.download = 'AI素材工厂-' + extension;
            result.hidden = false;
            setStatus('处理完成，可直接下载结果', false);
        }

        async function makeCollage(files) {
            var images = await Promise.all(files.slice(0, 6).map(loadImage));
            var columns = images.length <= 2 ? images.length : 3;
            var rows = Math.ceil(images.length / columns);
            var cell = 1200;
            var gap = 16;
            var canvas = document.createElement('canvas');
            canvas.width = columns * cell + (columns - 1) * gap;
            canvas.height = rows * cell + (rows - 1) * gap;
            var context = canvas.getContext('2d');
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, canvas.width, canvas.height);
            images.forEach(function (image, index) {
                var column = index % columns;
                var row = Math.floor(index / columns);
                var scale = Math.max(cell / image.naturalWidth, cell / image.naturalHeight);
                var width = image.naturalWidth * scale;
                var height = image.naturalHeight * scale;
                context.drawImage(image, column * (cell + gap) + (cell - width) / 2, row * (cell + gap) + (cell - height) / 2, width, height);
            });
            return canvas;
        }

        async function runLocalTool(tool) {
            if (!sourceFiles.length) {
                setStatus('请先选择本地图片', true);
                return;
            }
            try {
                setStatus('正在本地处理图片...', false);
                var canvas;
                var image;
                var title;
                var extension = '图片工具结果.jpg';
                var mime = 'image/jpeg';
                var quality = 0.92;
                if (tool === 'collage') {
                    if (sourceFiles.length < 2) throw new Error('多图拼接至少需要选择 2 张图片。');
                    canvas = await makeCollage(sourceFiles);
                    title = '多图拼接完成';
                } else {
                    image = await loadImage(sourceFiles[0]);
                    var width = image.naturalWidth;
                    var height = image.naturalHeight;
                    var filter = 'none';
                    if (tool === 'upscale_2x') { width *= 2; height *= 2; title = '高质量放大 x2 完成'; }
                    if (tool === 'upscale_4k') { var scale4 = 4096 / Math.max(width, height); width = Math.round(width * scale4); height = Math.round(height * scale4); title = '高质量放大 4K 完成'; }
                    if (tool === 'upscale_8k') { var scale8 = 7680 / Math.max(width, height); width = Math.round(width * scale8); height = Math.round(height * scale8); title = '高质量放大 8K 完成'; }
                    if (tool === 'clarity') { filter = 'contrast(1.13) saturate(1.06)'; title = '清晰度增强完成'; }
                    if (tool === 'color') { filter = 'contrast(1.06) saturate(1.24)'; title = '色彩增强完成'; }
                    if (tool === 'compress') { title = '图片压缩完成'; quality = 0.8; }
                    canvas = drawImage(image, Math.round(width), Math.round(height), filter);
                }
                var blob = await canvasBlob(canvas, mime, quality);
                showResult(blob, title, canvas.width + '×' + canvas.height + ' · ' + Math.round(blob.size / 1024) + ' KB', extension);
            } catch (error) {
                setStatus(error.message || '本地处理失败，请更换图片后重试。', true);
            }
        }

        function refreshAssetPreview() {
            if (!assetSelect || !assetPreview) return;
            var option = assetSelect.options[assetSelect.selectedIndex];
            assetPreview.src = option.getAttribute('data-image') || option.getAttribute('data-backup') || '';
        }

        function openAiTool(tool) {
            if (tool === 'reverse') {
                runReversePrompt();
                return;
            }
            if (!assetSelect || !assetSelect.value) {
                return;
            }
            window.location.href = shell.getAttribute('data-canvas-url') + '?asset_id=' + encodeURIComponent(assetSelect.value) + '&tool=' + encodeURIComponent(tool);
        }

        function runReversePrompt() {
            if (!assetSelect || !assetSelect.value || !promptResult || !promptOutput) return;
            var button = shell.querySelector('[data-image-tools-ai="reverse"]');
            button.disabled = true;
            button.classList.add('is-loading');
            setPromptStatus('正在识别画面并整理文字...', false);
            fetch(shell.getAttribute('data-reverse-endpoint'), {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: new URLSearchParams({_token: shell.getAttribute('data-csrf-token'), asset_id: assetSelect.value}).toString(),
            }).then(function (response) { return response.json().then(function (data) { if (!response.ok || !data.ok) throw new Error(data.message || '反推提示词失败'); return data; }); })
                .then(function (data) {
                    var prompt = String(data.prompt || '').trim();
                    if (!prompt) throw new Error('视觉模型没有返回可用文字，请重试。');
                    promptOutput.value = prompt;
                    promptResult.hidden = false;
                    if (promptEmpty) promptEmpty.hidden = true;
                    if (usePrompt) usePrompt.href = data.useUrl || '/workspace/free-create';
                    setPromptStatus('文字已生成并自动保存', false);

                    if (promptHistory) {
                        var option = assetSelect.options[assetSelect.selectedIndex];
                        var historyButton = document.createElement('button');
                        historyButton.type = 'button';
                        historyButton.setAttribute('data-image-tools-history', '');
                        historyButton.setAttribute('data-prompt', prompt);
                        historyButton.setAttribute('data-use-url', data.useUrl || '/workspace/free-create');
                        var historyCopy = document.createElement('span');
                        var historyTitle = document.createElement('strong');
                        var historyTime = document.createElement('small');
                        historyTitle.textContent = option ? option.textContent.trim() : '当前素材';
                        historyTime.textContent = data.createdAt || '刚刚';
                        historyCopy.appendChild(historyTitle);
                        historyCopy.appendChild(historyTime);
                        var historyIcon = document.createElement('i');
                        historyIcon.setAttribute('data-lucide', 'chevron-right');
                        historyButton.appendChild(historyCopy);
                        historyButton.appendChild(historyIcon);
                        promptHistory.prepend(historyButton);
                        while (promptHistory.children.length > 12) {
                            promptHistory.lastElementChild.remove();
                        }
                        if (window.lucide) window.lucide.createIcons({attrs: {'aria-hidden': 'true', 'stroke-width': 1.8}});
                    }
                    promptResult.scrollIntoView({behavior: 'smooth', block: 'nearest'});
                })
                .catch(function (error) {
                    setPromptStatus(error.message || '反推提示词失败，请稍后重试。', true);
                })
                .finally(function () { button.disabled = false; button.classList.remove('is-loading'); });
        }

        input.addEventListener('change', function () {
            sourceFiles = Array.prototype.slice.call(input.files || []).filter(function (file) { return /^image\/(jpeg|png|webp)$/.test(file.type); }).slice(0, 6);
            if (!sourceFiles.length) { clearFiles(); return; }
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = URL.createObjectURL(sourceFiles[0]);
            previewImage.src = previewUrl;
            preview.hidden = false;
            fileLabel.textContent = sourceFiles.length > 1 ? '已选择 ' + sourceFiles.length + ' 张图片' : sourceFiles[0].name;
            result.hidden = true;
            setStatus(sourceFiles.length > 1 ? '可使用多图拼接，其他工具将处理第一张图片' : '已选择图片，选择一个工具开始处理', false);
        });
        shell.querySelector('[data-image-tools-clear]').addEventListener('click', clearFiles);
        shell.querySelectorAll('[data-image-tools-local]').forEach(function (button) { button.addEventListener('click', function () { runLocalTool(button.getAttribute('data-image-tools-local')); }); });
        if (assetSelect) assetSelect.addEventListener('change', refreshAssetPreview);
        shell.querySelectorAll('[data-image-tools-ai]').forEach(function (button) { button.addEventListener('click', function () { openAiTool(button.getAttribute('data-image-tools-ai')); }); });
        var copy = shell.querySelector('[data-image-tools-copy-prompt]');
        if (copy) copy.addEventListener('click', function () {
            navigator.clipboard.writeText(promptOutput.value || '')
                .then(function () { setPromptStatus('文字已复制', false); })
                .catch(function () { setPromptStatus('复制失败，请手动选择文字复制。', true); });
        });
        if (promptHistory) promptHistory.addEventListener('click', function (event) {
            var button = event.target.closest('[data-image-tools-history]');
            if (!button) return;
            promptOutput.value = button.getAttribute('data-prompt') || '';
            promptResult.hidden = false;
            if (promptEmpty) promptEmpty.hidden = true;
            if (usePrompt) usePrompt.href = button.getAttribute('data-use-url') || '/workspace/free-create';
            setPromptStatus('已打开历史文字结果', false);
        });
    });
}());
