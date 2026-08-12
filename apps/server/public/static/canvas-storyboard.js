(function () {
    'use strict';

    function setupStoryboard() {
        var shell = document.querySelector('[data-canvas-editor]');
        var panel = shell && shell.querySelector('[data-canvas-view="storyboard"]');
        if (!shell || !panel || !window.CanvasProjectStore) {
            return;
        }

        var assets = parseJson((shell.querySelector('[data-canvas-assets-json]') || {}).textContent, []);
        var runtime = parseJson((shell.querySelector('[data-storyboard-runtime-json]') || {}).textContent, {});
        var runtimeByScene = runtime.scenes && typeof runtime.scenes === 'object' ? runtime.scenes : {};
        var unitPrice = Math.max(0, Number(shell.getAttribute('data-storyboard-video-unit-price') || 0));
        var videoEnabled = shell.getAttribute('data-storyboard-video-enabled') === '1';
        var planEndpoint = shell.getAttribute('data-storyboard-plan-endpoint') || '';
        var sceneEndpoint = shell.getAttribute('data-storyboard-scene-endpoint') || '';
        var statusEndpoint = shell.getAttribute('data-storyboard-status-endpoint') || '';
        var projectId = Number(shell.getAttribute('data-project-id') || 0);
        var pollTimer = null;
        var statusBusy = false;

        var list = panel.querySelector('[data-storyboard-scene-list]');
        var empty = panel.querySelector('[data-storyboard-empty]');
        var previewContent = panel.querySelector('[data-storyboard-preview-content]');
        var editor = panel.querySelector('[data-storyboard-scene-editor]');
        var sourceSelect = panel.querySelector('[data-storyboard-source]');
        var generateButton = panel.querySelector('[data-storyboard-generate]');
        var notice = panel.querySelector('[data-storyboard-notice]');
        var planDialog = document.querySelector('[data-storyboard-plan-dialog]');

        function parseJson(value, fallback) {
            try {
                var parsed = JSON.parse(value || '');
                return parsed && typeof parsed === 'object' ? parsed : fallback;
            } catch (error) {
                return fallback;
            }
        }

        function clone(value) {
            return JSON.parse(JSON.stringify(value));
        }

        function escapeHtml(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function uuid() {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                return 'scene-' + window.crypto.randomUUID();
            }
            return 'scene-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
        }

        function now() {
            return new Date().toISOString();
        }

        function documentValue() {
            return window.CanvasProjectStore.getDocument();
        }

        function normalizedStoryboard() {
            var documentState = documentValue();
            var storyboard = documentState.storyboard && typeof documentState.storyboard === 'object'
                ? documentState.storyboard
                : {};
            storyboard.title = storyboard.title || '商品视频故事板';
            storyboard.brief = storyboard.brief || '';
            storyboard.platform = storyboard.platform || 'douyin';
            storyboard.language = storyboard.language || 'zh-CN';
            storyboard.ratio = storyboard.ratio || '9:16';
            storyboard.resolution = storyboard.resolution || '720p';
            storyboard.generateAudio = Boolean(storyboard.generateAudio);
            storyboard.scenes = Array.isArray(storyboard.scenes) ? storyboard.scenes : [];
            storyboard.activeSceneId = storyboard.activeSceneId || (storyboard.scenes[0] ? storyboard.scenes[0].id : '');
            return storyboard;
        }

        function mutateStoryboard(mutator, options) {
            window.CanvasProjectStore.update(function (documentState) {
                if (!documentState.storyboard || typeof documentState.storyboard !== 'object') {
                    documentState.storyboard = normalizedStoryboard();
                }
                if (!Array.isArray(documentState.storyboard.scenes)) {
                    documentState.storyboard.scenes = [];
                }
                mutator(documentState.storyboard);
            }, {
                source: (options && options.source) || 'storyboard',
                delay: options && typeof options.delay === 'number' ? options.delay : 550,
            });
        }

        function activeScene(storyboard) {
            var activeId = String(storyboard.activeSceneId || '');
            return storyboard.scenes.find(function (scene) { return String(scene.id) === activeId; }) || storyboard.scenes[0] || null;
        }

        function sceneRuntime(scene) {
            return scene && runtimeByScene[String(scene.id)] ? runtimeByScene[String(scene.id)] : null;
        }

        function statusLabel(status) {
            return {
                draft: '草稿',
                queued: '等待中',
                pending_gateway: '待配置',
                running: '生成中',
                completed: '已完成',
                partial: '部分完成',
                failed: '需要处理',
                result_missing: '结果缺失',
                cancelled: '已取消',
            }[status] || '草稿';
        }

        function statusClass(status) {
            if (status === 'completed') {
                return 'is-success';
            }
            if (['failed', 'partial', 'result_missing', 'cancelled'].indexOf(status) >= 0) {
                return 'is-error';
            }
            if (['queued', 'running'].indexOf(status) >= 0) {
                return 'is-running';
            }
            if (status === 'pending_gateway') {
                return 'is-warning';
            }
            return 'is-draft';
        }

        function sourceAsset(scene) {
            var assetId = Number(scene && scene.sourceAssetId || 0);
            return assets.find(function (asset) { return Number(asset.id) === assetId; }) || null;
        }

        function previousRuntime(scene) {
            var previousId = String(scene && scene.previousSceneId || '');
            return previousId && runtimeByScene[previousId] ? runtimeByScene[previousId] : null;
        }

        function effectiveStatus(scene) {
            var sceneStatus = String(scene && scene.status || 'draft');
            var currentRuntime = sceneRuntime(scene);
            return currentRuntime ? String(currentRuntime.status || sceneStatus) : sceneStatus;
        }

        function addScene(seed) {
            var storyboard = normalizedStoryboard();
            if (storyboard.scenes.length >= 20) {
                showNotice('单个故事板最多保留 20 个镜头。', 'error');
                return null;
            }
            var previous = storyboard.scenes[storyboard.scenes.length - 1] || null;
            var defaultAsset = assets.find(function (asset) { return String(asset.status || 'active') === 'active'; }) || null;
            var scene = {
                id: uuid(),
                title: seed && seed.title ? String(seed.title) : '镜头 ' + (storyboard.scenes.length + 1),
                prompt: seed && seed.prompt ? String(seed.prompt) : '',
                motionPreset: seed && seed.motionPreset ? String(seed.motionPreset) : 'product_showcase',
                durationSeconds: seed && [5, 10].indexOf(Number(seed.durationSeconds)) >= 0 ? Number(seed.durationSeconds) : 5,
                subtitle: seed && seed.subtitle ? String(seed.subtitle) : '',
                transition: seed && seed.transition ? String(seed.transition) : 'cut',
                sourceType: seed && seed.usePreviousTail && previous ? 'previous_tail' : (defaultAsset && !previous ? 'asset' : 'none'),
                sourceAssetId: defaultAsset && !previous ? Number(defaultAsset.id) : 0,
                previousSceneId: seed && seed.usePreviousTail && previous ? String(previous.id) : '',
                jobId: 0,
                outputAssetId: 0,
                status: 'draft',
                createdAt: now(),
                updatedAt: now(),
            };
            mutateStoryboard(function (value) {
                value.scenes.push(scene);
                value.activeSceneId = scene.id;
            }, {delay: 250});
            render();
            return scene;
        }

        function removeActiveScene() {
            var storyboard = normalizedStoryboard();
            var scene = activeScene(storyboard);
            if (!scene) {
                return;
            }
            var status = effectiveStatus(scene);
            if (['queued', 'running'].indexOf(status) >= 0) {
                showNotice('当前镜头仍在生成，完成或取消任务后才能从故事板移除。', 'error');
                return;
            }
            if (Number(scene.jobId || 0) > 0 && !window.confirm('移除镜头不会删除历史任务和已生成素材，确定继续吗？')) {
                return;
            }
            mutateStoryboard(function (value) {
                var index = value.scenes.findIndex(function (candidate) { return candidate.id === scene.id; });
                value.scenes = value.scenes.filter(function (candidate) { return candidate.id !== scene.id; });
                value.scenes.forEach(function (candidate) {
                    if (candidate.previousSceneId === scene.id) {
                        candidate.previousSceneId = '';
                        candidate.sourceType = 'none';
                    }
                });
                var next = value.scenes[Math.max(0, index - 1)] || value.scenes[0] || null;
                value.activeSceneId = next ? next.id : '';
            }, {delay: 250});
            delete runtimeByScene[String(scene.id)];
            render();
        }

        function moveActiveScene(direction) {
            var storyboard = normalizedStoryboard();
            var scene = activeScene(storyboard);
            if (!scene) {
                return;
            }
            var index = storyboard.scenes.findIndex(function (candidate) { return candidate.id === scene.id; });
            var target = direction === 'up' ? index - 1 : index + 1;
            if (index < 0 || target < 0 || target >= storyboard.scenes.length) {
                return;
            }
            mutateStoryboard(function (value) {
                var moved = value.scenes.splice(index, 1)[0];
                value.scenes.splice(target, 0, moved);
            }, {delay: 250});
            render();
        }

        function renderSceneList(storyboard) {
            if (!list) {
                return;
            }
            list.innerHTML = storyboard.scenes.map(function (scene, index) {
                var sceneState = sceneRuntime(scene);
                var status = effectiveStatus(scene);
                var progress = sceneState ? Number(sceneState.progress || 0) : 0;
                var asset = sourceAsset(scene);
                var output = sceneState && sceneState.asset ? sceneState.asset : null;
                var thumb = output && output.posterUrl ? output.posterUrl : (asset ? asset.fileUrl : '');
                return '<button type="button" class="storyboard-scene-item ' +
                    (String(storyboard.activeSceneId) === String(scene.id) ? 'active ' : '') +
                    statusClass(status) + '" data-storyboard-scene-select="' + escapeHtml(scene.id) + '">' +
                    '<span class="storyboard-scene-number">' + (index + 1) + '</span>' +
                    (thumb
                        ? '<img src="' + escapeHtml(thumb) + '" alt="">'
                        : '<span class="storyboard-scene-thumb"><i data-lucide="film"></i></span>') +
                    '<span class="storyboard-scene-copy"><strong>' + escapeHtml(scene.title || ('镜头 ' + (index + 1))) + '</strong>' +
                    '<small>' + Number(scene.durationSeconds || 5) + ' 秒 · ' + escapeHtml(statusLabel(status)) + '</small></span>' +
                    (['queued', 'running'].indexOf(status) >= 0
                        ? '<span class="storyboard-scene-progress">' + progress + '%</span>'
                        : '<i data-lucide="chevron-right"></i>') +
                    '</button>';
            }).join('');
        }

        function rebuildSourceOptions(storyboard, scene) {
            if (!sourceSelect || !scene) {
                return;
            }
            Array.prototype.slice.call(sourceSelect.querySelectorAll('[data-storyboard-previous-option]')).forEach(function (option) {
                option.remove();
            });
            var index = storyboard.scenes.findIndex(function (candidate) { return candidate.id === scene.id; });
            if (index > 0) {
                var previous = storyboard.scenes[index - 1];
                var option = document.createElement('option');
                option.value = 'previous:' + previous.id;
                option.textContent = '承接上一镜头尾帧 · ' + (previous.title || ('镜头 ' + index));
                option.setAttribute('data-storyboard-previous-option', '1');
                sourceSelect.insertBefore(option, sourceSelect.children[1] || null);
            }
            var sourceValue = 'none';
            if (scene.sourceType === 'asset' && Number(scene.sourceAssetId || 0) > 0) {
                sourceValue = 'asset:' + Number(scene.sourceAssetId);
            } else if (scene.sourceType === 'previous_tail' && scene.previousSceneId) {
                sourceValue = 'previous:' + scene.previousSceneId;
            }
            sourceSelect.value = sourceValue;
            if (sourceSelect.value !== sourceValue) {
                sourceSelect.value = 'none';
            }
        }

        function renderEditor(storyboard, scene) {
            if (!editor) {
                return;
            }
            editor.hidden = !scene;
            if (!scene) {
                return;
            }
            var runtimeValue = sceneRuntime(scene);
            var status = effectiveStatus(scene);
            setValue('[data-storyboard-scene-title]', scene.title || '');
            setValue('[data-storyboard-scene-prompt]', scene.prompt || '');
            setValue('[data-storyboard-motion]', scene.motionPreset || 'product_showcase');
            setValue('[data-storyboard-duration]', String(scene.durationSeconds || 5));
            setValue('[data-storyboard-transition]', scene.transition || 'cut');
            setValue('[data-storyboard-subtitle]', scene.subtitle || '');
            rebuildSourceOptions(storyboard, scene);
            setText('[data-storyboard-editor-status]', statusLabel(status));
            setText('[data-storyboard-scene-credits]', unitPrice * Number(scene.durationSeconds || 5) + ' 点');
            var index = storyboard.scenes.findIndex(function (candidate) { return candidate.id === scene.id; });
            setDisabled('[data-storyboard-move="up"]', index <= 0);
            setDisabled('[data-storyboard-move="down"]', index < 0 || index >= storyboard.scenes.length - 1);

            var active = ['queued', 'running'].indexOf(status) >= 0;
            var canGenerate = videoEnabled && !active && String(scene.prompt || '').trim().length >= 4;
            if (generateButton) {
                generateButton.disabled = !canGenerate;
                var label = generateButton.querySelector('span');
                if (label) {
                    label.textContent = !videoEnabled
                        ? '视频模型尚未可用'
                        : active
                            ? '当前镜头正在后台生成'
                            : status === 'completed'
                                ? '重新生成此镜头'
                                : ['failed', 'partial', 'result_missing'].indexOf(status) >= 0
                                    ? '修改后重新生成'
                                    : '生成当前镜头 · ' + (unitPrice * Number(scene.durationSeconds || 5)) + ' 点';
                }
            }
            var generationState = panel.querySelector('[data-storyboard-generation-state]');
            if (generationState) {
                generationState.textContent = runtimeValue
                    ? (runtimeValue.message || statusLabel(status))
                    : (String(scene.prompt || '').trim().length >= 4 ? '镜头草稿已就绪' : '请先填写画面与动作要求');
            }
        }

        function renderPreview(storyboard, scene) {
            if (empty) {
                empty.hidden = Boolean(scene);
            }
            if (previewContent) {
                previewContent.hidden = !scene;
            }
            if (!scene) {
                return;
            }
            var index = storyboard.scenes.findIndex(function (candidate) { return candidate.id === scene.id; });
            var runtimeValue = sceneRuntime(scene);
            var status = effectiveStatus(scene);
            setText('[data-storyboard-preview-index]', '镜头 ' + (index + 1));
            setText('[data-storyboard-preview-title]', scene.title || ('镜头 ' + (index + 1)));
            setText('[data-storyboard-preview-duration]', Number(scene.durationSeconds || 5) + ' 秒');
            setText('[data-storyboard-preview-prompt]', scene.prompt || '尚未填写镜头要求。');
            setText('[data-storyboard-preview-subtitle]', scene.subtitle || '本镜头不生成字幕。');

            var video = panel.querySelector('[data-storyboard-video-preview]');
            var image = panel.querySelector('[data-storyboard-source-preview]');
            var placeholder = panel.querySelector('[data-storyboard-media-placeholder]');
            var output = runtimeValue && runtimeValue.asset ? runtimeValue.asset : null;
            var asset = sourceAsset(scene);
            var previous = previousRuntime(scene);
            var imageUrl = asset ? (asset.fileUrl || asset.backupFileUrl || '') : '';
            if (!imageUrl && previous) {
                imageUrl = previous.lastFrameUrl || (previous.asset && previous.asset.posterUrl) || '';
            }
            if (output && output.fileUrl) {
                if (video.getAttribute('src') !== output.fileUrl) {
                    video.setAttribute('src', output.fileUrl);
                }
                video.poster = output.posterUrl || imageUrl || '';
                video.hidden = false;
                image.hidden = true;
                placeholder.hidden = true;
            } else if (imageUrl) {
                image.src = imageUrl;
                image.hidden = false;
                video.hidden = true;
                placeholder.hidden = true;
            } else {
                video.hidden = true;
                image.hidden = true;
                placeholder.hidden = false;
            }

            var overlay = panel.querySelector('[data-storyboard-runtime-overlay]');
            var isActive = runtimeValue && ['queued', 'running'].indexOf(status) >= 0;
            if (overlay) {
                overlay.hidden = !isActive;
            }
            if (isActive) {
                var progress = Math.max(0, Math.min(99, Number(runtimeValue.progress || 0)));
                setText('[data-storyboard-runtime-label]', runtimeValue.message || statusLabel(status));
                setText('[data-storyboard-runtime-percent]', progress + '%');
                var bar = panel.querySelector('[data-storyboard-runtime-bar]');
                if (bar) {
                    bar.style.width = progress + '%';
                }
            }
            if (runtimeValue && ['failed', 'partial', 'result_missing'].indexOf(status) >= 0) {
                showNotice((runtimeValue.error || runtimeValue.message || '当前镜头没有获得可用结果。') + ' 未完成的任务不会扣费，可修改镜头后重新生成。', 'error');
            } else if (status === 'completed') {
                showNotice('镜头已完成并保存到素材库，可以继续生成下一镜头或重新生成一个版本。', 'success');
            } else if (!isActive) {
                hideNotice();
            }
        }

        function renderSummary(storyboard) {
            var counts = {draft: 0, queued: 0, running: 0, completed: 0, failed: 0};
            var progressTotal = 0;
            var estimated = 0;
            var actual = 0;
            storyboard.scenes.forEach(function (scene) {
                var runtimeValue = sceneRuntime(scene);
                var status = effectiveStatus(scene);
                if (status === 'completed') {
                    counts.completed++;
                } else if (['failed', 'partial', 'result_missing', 'cancelled'].indexOf(status) >= 0) {
                    counts.failed++;
                } else if (status === 'running') {
                    counts.running++;
                } else if (['queued', 'pending_gateway'].indexOf(status) >= 0) {
                    counts.queued++;
                } else {
                    counts.draft++;
                }
                progressTotal += runtimeValue ? Number(runtimeValue.progress || 0) : 0;
                estimated += runtimeValue ? Number(runtimeValue.estimatedCredits || 0) : unitPrice * Number(scene.durationSeconds || 5);
                actual += runtimeValue ? Number(runtimeValue.actualCredits || 0) : 0;
            });
            var progress = storyboard.scenes.length ? Math.round(progressTotal / storyboard.scenes.length) : 0;
            setText('[data-storyboard-scene-count]', storyboard.scenes.length + ' 个');
            setText('[data-storyboard-project-percent]', progress + '%');
            var projectBar = panel.querySelector('[data-storyboard-project-bar]');
            if (projectBar) {
                projectBar.style.width = progress + '%';
            }
            var summaryParts = [];
            if (counts.running) {
                summaryParts.push(counts.running + ' 个生成中');
            }
            if (counts.queued) {
                summaryParts.push(counts.queued + ' 个等待');
            }
            if (counts.completed) {
                summaryParts.push(counts.completed + ' 个已完成');
            }
            if (counts.failed) {
                summaryParts.push(counts.failed + ' 个需处理');
            }
            if (!summaryParts.length) {
                summaryParts.push(storyboard.scenes.length ? counts.draft + ' 个草稿' : '准备分镜');
            }
            setText('[data-storyboard-summary-text]', summaryParts.join(' · '));
            setText('[data-storyboard-credit-summary]', '预计 ' + estimated + ' 点' + (actual > 0 ? ' · 已用 ' + actual + ' 点' : ''));
        }

        function render() {
            var storyboard = normalizedStoryboard();
            var scene = activeScene(storyboard);
            renderSceneList(storyboard);
            renderSummary(storyboard);
            renderEditor(storyboard, scene);
            renderPreview(storyboard, scene);
            if (window.lucide && typeof window.lucide.createIcons === 'function') {
                window.lucide.createIcons();
            }
            schedulePolling();
        }

        function setValue(selector, value) {
            var element = panel.querySelector(selector);
            if (element && element.value !== String(value)) {
                element.value = String(value);
            }
        }

        function setText(selector, value) {
            var element = panel.querySelector(selector);
            if (element) {
                element.textContent = String(value);
            }
        }

        function setDisabled(selector, disabled) {
            var element = panel.querySelector(selector);
            if (element) {
                element.disabled = Boolean(disabled);
            }
        }

        function showNotice(message, type) {
            if (!notice) {
                return;
            }
            notice.hidden = false;
            notice.className = 'storyboard-notice ' + (type === 'error' ? 'is-error' : type === 'success' ? 'is-success' : '');
            notice.textContent = message;
        }

        function hideNotice() {
            if (notice) {
                notice.hidden = true;
                notice.textContent = '';
            }
        }

        function currentToken() {
            var input = shell.querySelector('input[name="_token"]');
            return input ? input.value : '';
        }

        function refreshCsrf() {
            return fetch(shell.getAttribute('data-csrf-endpoint'), {credentials: 'same-origin', cache: 'no-store'})
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data || !data.ok || !data.token) {
                        throw new Error('安全令牌刷新失败');
                    }
                    shell.querySelectorAll('input[name="_token"]').forEach(function (input) {
                        input.value = data.token;
                    });
                    return data.token;
                });
        }

        function postForm(endpoint, fields, retry) {
            var payload = new FormData();
            payload.append('_token', currentToken());
            Object.keys(fields).forEach(function (key) {
                payload.append(key, String(fields[key]));
            });
            return fetch(endpoint, {method: 'POST', body: payload, credentials: 'same-origin'})
                .then(function (response) {
                    if (response.status === 419 && retry !== false) {
                        return refreshCsrf().then(function () { return postForm(endpoint, fields, false); });
                    }
                    return response.text().then(function (body) {
                        var data = parseJson(body, {});
                        if (!response.ok || !data.ok) {
                            throw new Error(data.message || '请求处理失败');
                        }
                        return data;
                    });
                });
        }

        function generateActiveScene() {
            var storyboard = normalizedStoryboard();
            var scene = activeScene(storyboard);
            if (!scene || !generateButton || generateButton.disabled) {
                return;
            }
            generateButton.disabled = true;
            showNotice('正在保存镜头并创建后台视频任务...', 'info');
            window.CanvasProjectStore.saveNow('manual')
                .then(function () {
                    return postForm(sceneEndpoint, {project_id: projectId, scene_id: scene.id}, true);
                })
                .then(function (data) {
                    runtimeByScene[String(scene.id)] = {
                        sceneId: scene.id,
                        jobId: Number(data.jobId || 0),
                        status: data.status || 'queued',
                        statusLabel: statusLabel(data.status || 'queued'),
                        progress: 0,
                        message: data.message || '镜头已进入队列',
                        error: '',
                        estimatedCredits: Number(data.estimatedCredits || 0),
                        actualCredits: 0,
                        asset: null,
                    };
                    mutateStoryboard(function (value) {
                        var target = value.scenes.find(function (candidate) { return candidate.id === scene.id; });
                        if (target) {
                            target.jobId = Number(data.jobId || 0);
                            target.status = data.status || 'queued';
                            target.updatedAt = now();
                        }
                    }, {source: 'storyboard-runtime', delay: 250});
                    showNotice(data.message || '镜头已进入后台队列。', 'success');
                    render();
                    pollStatus();
                })
                .catch(function (error) {
                    showNotice(error.message || '镜头任务创建失败，请检查设置后重试。', 'error');
                    render();
                });
        }

        function openPlanDialog() {
            if (!planDialog) {
                return;
            }
            var brief = planDialog.querySelector('[data-storyboard-plan-brief]');
            if (brief) {
                brief.value = normalizedStoryboard().brief || '';
            }
            if (typeof planDialog.showModal === 'function') {
                planDialog.showModal();
            } else {
                planDialog.setAttribute('open', 'open');
            }
        }

        function closePlanDialog() {
            if (!planDialog) {
                return;
            }
            if (typeof planDialog.close === 'function') {
                planDialog.close();
            } else {
                planDialog.removeAttribute('open');
            }
        }

        function submitPlan(event) {
            event.preventDefault();
            if (!planDialog) {
                return;
            }
            var briefInput = planDialog.querySelector('[data-storyboard-plan-brief]');
            var countInput = planDialog.querySelector('[data-storyboard-plan-count]');
            var submit = planDialog.querySelector('button[type="submit"]');
            var brief = briefInput ? briefInput.value.trim() : '';
            if (brief.length < 4) {
                briefInput.focus();
                showNotice('请先写至少 4 个字的视频创作目标。', 'error');
                return;
            }
            if (submit) {
                submit.disabled = true;
            }
            mutateStoryboard(function (storyboard) {
                storyboard.brief = brief;
            }, {delay: 0});
            window.CanvasProjectStore.saveNow('manual')
                .then(function () {
                    return postForm(planEndpoint, {
                        project_id: projectId,
                        brief: brief,
                        scene_count: countInput ? countInput.value : 5,
                    }, true);
                })
                .then(function (data) {
                    var seeds = Array.isArray(data.scenes) ? data.scenes : [];
                    closePlanDialog();
                    seeds.forEach(function (seed) { addScene(seed); });
                    window.CanvasProjectStore.saveNow('manual');
                    showNotice(data.message || 'AI 分镜建议已加入故事板。', 'success');
                    render();
                })
                .catch(function (error) {
                    showNotice(error.message || 'AI 分镜策划失败，请稍后重试。', 'error');
                })
                .finally(function () {
                    if (submit) {
                        submit.disabled = false;
                    }
                });
        }

        function pollStatus() {
            if (statusBusy || !statusEndpoint) {
                return;
            }
            statusBusy = true;
            fetch(statusEndpoint, {credentials: 'same-origin', cache: 'no-store'})
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data || !data.ok || !data.scenes) {
                        return;
                    }
                    runtimeByScene = data.scenes;
                    var storyboard = normalizedStoryboard();
                    var changed = false;
                    storyboard.scenes.forEach(function (scene) {
                        var runtimeValue = runtimeByScene[String(scene.id)];
                        if (!runtimeValue) {
                            return;
                        }
                        var assetId = runtimeValue.asset ? Number(runtimeValue.asset.id || 0) : 0;
                        if (Number(scene.jobId || 0) !== Number(runtimeValue.jobId || 0)
                            || String(scene.status || '') !== String(runtimeValue.status || '')
                            || Number(scene.outputAssetId || 0) !== assetId) {
                            changed = true;
                        }
                    });
                    if (changed) {
                        mutateStoryboard(function (value) {
                            value.scenes.forEach(function (scene) {
                                var runtimeValue = runtimeByScene[String(scene.id)];
                                if (!runtimeValue) {
                                    return;
                                }
                                scene.jobId = Number(runtimeValue.jobId || 0);
                                scene.status = String(runtimeValue.status || 'draft');
                                scene.outputAssetId = runtimeValue.asset ? Number(runtimeValue.asset.id || 0) : 0;
                                scene.updatedAt = now();
                            });
                        }, {source: 'storyboard-runtime', delay: 900});
                    }
                    render();
                })
                .catch(function () {
                    // The global task center remains the fallback when a transient status request fails.
                })
                .finally(function () {
                    statusBusy = false;
                });
        }

        function schedulePolling() {
            window.clearInterval(pollTimer);
            var storyboard = normalizedStoryboard();
            var hasActive = storyboard.scenes.some(function (scene) {
                return ['queued', 'running'].indexOf(effectiveStatus(scene)) >= 0;
            });
            if (hasActive) {
                pollTimer = window.setInterval(pollStatus, 4000);
            }
        }

        panel.querySelectorAll('[data-storyboard-add]').forEach(function (button) {
            button.addEventListener('click', function () { addScene(null); });
        });
        list.addEventListener('click', function (event) {
            var button = event.target.closest('[data-storyboard-scene-select]');
            if (!button) {
                return;
            }
            var sceneId = button.getAttribute('data-storyboard-scene-select');
            mutateStoryboard(function (storyboard) {
                storyboard.activeSceneId = sceneId;
            }, {delay: 250});
            render();
        });
        panel.querySelector('[data-storyboard-delete]').addEventListener('click', removeActiveScene);
        panel.querySelectorAll('[data-storyboard-move]').forEach(function (button) {
            button.addEventListener('click', function () {
                moveActiveScene(button.getAttribute('data-storyboard-move'));
            });
        });
        panel.querySelectorAll('[data-storyboard-plan-open]').forEach(function (button) {
            button.addEventListener('click', openPlanDialog);
        });
        if (planDialog) {
            planDialog.querySelectorAll('[data-storyboard-plan-close]').forEach(function (button) {
                button.addEventListener('click', closePlanDialog);
            });
            planDialog.querySelector('[data-storyboard-plan-form]').addEventListener('submit', submitPlan);
        }
        generateButton.addEventListener('click', generateActiveScene);

        bindGlobalField('[data-storyboard-brief]', 'brief', 'input');
        bindGlobalField('[data-storyboard-platform]', 'platform', 'change');
        bindGlobalField('[data-storyboard-ratio]', 'ratio', 'change');
        bindGlobalField('[data-storyboard-resolution]', 'resolution', 'change');
        bindGlobalField('[data-storyboard-language]', 'language', 'change');
        var audio = panel.querySelector('[data-storyboard-audio]');
        audio.addEventListener('change', function () {
            mutateStoryboard(function (storyboard) {
                storyboard.generateAudio = audio.checked;
            });
        });

        bindSceneField('[data-storyboard-scene-title]', 'title', 'input', true);
        bindSceneField('[data-storyboard-scene-prompt]', 'prompt', 'input', false);
        bindSceneField('[data-storyboard-motion]', 'motionPreset', 'change', false);
        bindSceneField('[data-storyboard-duration]', 'durationSeconds', 'change', true, Number);
        bindSceneField('[data-storyboard-transition]', 'transition', 'change', false);
        bindSceneField('[data-storyboard-subtitle]', 'subtitle', 'input', false);
        sourceSelect.addEventListener('change', function () {
            var value = sourceSelect.value;
            mutateActiveScene(function (scene) {
                if (value.indexOf('asset:') === 0) {
                    scene.sourceType = 'asset';
                    scene.sourceAssetId = Number(value.split(':')[1] || 0);
                    scene.previousSceneId = '';
                } else if (value.indexOf('previous:') === 0) {
                    scene.sourceType = 'previous_tail';
                    scene.sourceAssetId = 0;
                    scene.previousSceneId = value.slice('previous:'.length);
                } else {
                    scene.sourceType = 'none';
                    scene.sourceAssetId = 0;
                    scene.previousSceneId = '';
                }
            });
            render();
        });

        function bindGlobalField(selector, key, eventName) {
            var element = panel.querySelector(selector);
            element.addEventListener(eventName, function () {
                mutateStoryboard(function (storyboard) {
                    storyboard[key] = element.value;
                });
            });
        }

        function mutateActiveScene(mutator) {
            mutateStoryboard(function (storyboard) {
                var scene = storyboard.scenes.find(function (candidate) {
                    return candidate.id === storyboard.activeSceneId;
                });
                if (scene) {
                    mutator(scene);
                    scene.updatedAt = now();
                }
            });
        }

        function bindSceneField(selector, key, eventName, rerender, transform) {
            var element = panel.querySelector(selector);
            element.addEventListener(eventName, function () {
                mutateActiveScene(function (scene) {
                    scene[key] = transform ? transform(element.value) : element.value;
                });
                if (rerender) {
                    render();
                } else {
                    var storyboard = normalizedStoryboard();
                    renderSummary(storyboard);
                    renderPreview(storyboard, activeScene(storyboard));
                    renderEditor(storyboard, activeScene(storyboard));
                }
            });
        }

        document.addEventListener('canvas-view-changed', function (event) {
            if (event.detail && event.detail.view === 'storyboard') {
                render();
                pollStatus();
            }
        });
        window.addEventListener('beforeunload', function () {
            window.clearInterval(pollTimer);
        });

        render();
        if (Object.keys(runtimeByScene).length) {
            pollStatus();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupStoryboard);
    } else {
        setupStoryboard();
    }
}());
