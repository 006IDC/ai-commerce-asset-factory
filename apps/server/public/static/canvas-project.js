(function () {
    'use strict';

    function setupCanvasProjectStore() {
        var shell = document.querySelector('[data-canvas-editor]');
        var documentScript = shell && shell.querySelector('[data-canvas-project-json]');
        if (!shell || !documentScript) {
            return;
        }

        var projectId = Number(shell.getAttribute('data-project-id') || 0);
        var version = Number(shell.getAttribute('data-document-version') || 1);
        var saveEndpoint = shell.getAttribute('data-canvas-workspace-endpoint');
        var csrfEndpoint = shell.getAttribute('data-csrf-endpoint');
        var statusElement = shell.querySelector('[data-workflow-save-state]');
        var documentState = parseJson(documentScript.textContent, {});
        var draftKey = 'creative-project-draft-' + projectId;
        var saveTimer = null;
        var saving = false;
        var saveAgain = false;
        var dirty = false;
        var listeners = [];

        var currentUrl = new URL(window.location.href);
        if (currentUrl.searchParams.get('new') === '1' && projectId > 0) {
            window.history.replaceState(
                {},
                '',
                '/workspace/canvas?project_id=' + encodeURIComponent(String(projectId))
            );
        }

        restoreLocalDraft();

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

        function currentToken() {
            var input = shell.querySelector('input[name="_token"]');
            return input ? input.value : '';
        }

        function setStatus(message, state) {
            if (!statusElement) {
                return;
            }
            statusElement.classList.toggle('is-saving', state === 'saving');
            statusElement.classList.toggle('is-error', state === 'error');
            var text = statusElement.querySelector('span');
            if (text) {
                text.textContent = message;
            }
        }

        function notify(type, detail) {
            listeners.slice().forEach(function (listener) {
                try {
                    listener(type, detail || {});
                } catch (error) {
                    window.setTimeout(function () { throw error; }, 0);
                }
            });
            document.dispatchEvent(new CustomEvent('canvas-project-' + type, {detail: detail || {}}));
        }

        function persistLocalDraft() {
            try {
                window.localStorage.setItem(draftKey, JSON.stringify({
                    version: version,
                    document: documentState,
                    savedAt: new Date().toISOString(),
                }));
            } catch (error) {
                // Browser storage can be unavailable in private mode; server save still works.
            }
        }

        function restoreLocalDraft() {
            try {
                var draft = parseJson(window.localStorage.getItem(draftKey), null);
                if (draft && Number(draft.version) === version && draft.document && typeof draft.document === 'object') {
                    documentState = draft.document;
                    dirty = true;
                    setStatus('已恢复未保存内容', 'saving');
                }
            } catch (error) {
                // Ignore malformed or inaccessible local drafts.
            }
        }

        function clearLocalDraft() {
            try {
                window.localStorage.removeItem(draftKey);
            } catch (error) {
                // Nothing else is required after a successful server save.
            }
        }

        function refreshCsrf() {
            return fetch(csrfEndpoint, {credentials: 'same-origin', cache: 'no-store'})
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data || !data.ok || !data.token) {
                        throw new Error('安全令牌刷新失败');
                    }
                    var input = shell.querySelector('input[name="_token"]');
                    if (input) {
                        input.value = data.token;
                    }
                    return data.token;
                });
        }

        function parseResponse(response) {
            return response.text().then(function (body) {
                var data = parseJson(body, {});
                if (!response.ok || !data.ok) {
                    var error = new Error(data.message || '项目保存失败');
                    error.status = response.status;
                    throw error;
                }
                return data;
            });
        }

        function requestSave(payload, retry) {
            return fetch(saveEndpoint, {
                method: 'POST',
                body: payload,
                credentials: 'same-origin',
            }).then(function (response) {
                if (response.status !== 419 || retry === false) {
                    return parseResponse(response);
                }
                return refreshCsrf().then(function (token) {
                    payload.set('_token', token);
                    return requestSave(payload, false);
                });
            });
        }

        function scheduleSave(delay) {
            window.clearTimeout(saveTimer);
            setStatus('等待自动保存', 'saving');
            saveTimer = window.setTimeout(function () {
                saveNow('auto');
            }, typeof delay === 'number' ? delay : 700);
        }

        function update(mutator, options) {
            var before = JSON.stringify(documentState);
            mutator(documentState);
            if (before === JSON.stringify(documentState)) {
                return;
            }
            dirty = true;
            persistLocalDraft();
            notify('changed', {document: clone(documentState), source: (options && options.source) || 'editor'});
            scheduleSave(options && typeof options.delay === 'number' ? options.delay : 700);
        }

        function saveNow(source) {
            window.clearTimeout(saveTimer);
            if (!dirty) {
                setStatus('已自动保存', 'saved');
                return Promise.resolve({ok: true, unchanged: true});
            }
            if (saving) {
                saveAgain = true;
                return Promise.resolve({ok: true, queued: true});
            }
            saving = true;
            saveAgain = false;
            setStatus('正在保存', 'saving');
            var sentDocument = clone(documentState);
            var payload = new FormData();
            payload.append('_token', currentToken());
            payload.append('project_id', String(projectId));
            payload.append('document_version', String(version));
            payload.append('document_json', JSON.stringify(sentDocument));
            payload.append('save_source', source || 'auto');

            return requestSave(payload, true)
                .then(function (data) {
                    version = Number(data.documentVersion || version);
                    shell.setAttribute('data-document-version', String(version));
                    if (JSON.stringify(sentDocument) === JSON.stringify(documentState)) {
                        dirty = false;
                        clearLocalDraft();
                    } else {
                        saveAgain = true;
                    }
                    setStatus(dirty ? '有新的修改待保存' : '已自动保存', dirty ? 'saving' : 'saved');
                    notify('saved', {
                        document: clone(documentState),
                        version: version,
                        revisionNo: Number(data.revisionNo || 0),
                        unchanged: Boolean(data.unchanged),
                    });
                    return data;
                })
                .catch(function (error) {
                    dirty = true;
                    persistLocalDraft();
                    setStatus(error.status === 409 ? '项目有更新，请刷新后合并' : '保存失败，内容已暂存在本机', 'error');
                    notify('save-error', {message: error.message, status: Number(error.status || 0)});
                    throw error;
                })
                .finally(function () {
                    saving = false;
                    if (saveAgain) {
                        saveAgain = false;
                        scheduleSave(100);
                    }
                });
        }

        window.CanvasProjectStore = {
            projectId: projectId,
            getDocument: function () { return clone(documentState); },
            getVersion: function () { return version; },
            isDirty: function () { return dirty || saving; },
            update: update,
            updateBoard: function (board) {
                update(function (documentValue) {
                    documentValue.board = clone(board);
                    documentValue.activeView = 'board';
                }, {source: 'board'});
            },
            setActiveView: function (view) {
                if (['board', 'design', 'storyboard'].indexOf(view) < 0) {
                    return;
                }
                update(function (documentValue) {
                    documentValue.activeView = view;
                }, {source: 'navigation', delay: 300});
            },
            saveNow: saveNow,
            subscribe: function (listener) {
                listeners.push(listener);
                return function () {
                    listeners = listeners.filter(function (candidate) { return candidate !== listener; });
                };
            },
        };

        function showView(view, persist) {
            if (['board', 'design', 'storyboard'].indexOf(view) < 0) {
                return;
            }
            shell.setAttribute('data-active-view', view);
            document.querySelectorAll('[data-canvas-view-tab]').forEach(function (tab) {
                var active = tab.getAttribute('data-canvas-view-tab') === view;
                tab.classList.toggle('active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            shell.querySelectorAll('[data-canvas-view]').forEach(function (panel) {
                var active = panel.getAttribute('data-canvas-view') === view;
                panel.hidden = !active;
                panel.classList.toggle('active', active);
            });
            if (persist) {
                window.CanvasProjectStore.setActiveView(view);
            }
            window.setTimeout(function () {
                window.dispatchEvent(new CustomEvent('canvas-view-changed', {detail: {view: view}}));
                window.dispatchEvent(new Event('resize'));
            }, 0);
        }

        document.querySelectorAll('[data-canvas-view-tab]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                if (!tab.disabled) {
                    showView(tab.getAttribute('data-canvas-view-tab'), true);
                }
            });
        });
        document.querySelectorAll('[data-canvas-switch-design]').forEach(function (button) {
            button.addEventListener('click', function () { showView('design', true); });
        });
        var projectSwitch = document.querySelector('[data-canvas-project-switch]');
        if (projectSwitch) {
            projectSwitch.addEventListener('change', function () {
                var nextId = Number(projectSwitch.value || 0);
                if (nextId > 0 && nextId !== projectId) {
                    window.location.href = '/workspace/canvas?project_id=' + encodeURIComponent(String(nextId));
                }
            });
        }
        showView(['board', 'design', 'storyboard'].indexOf(documentState.activeView) >= 0 ? documentState.activeView : 'design', false);

        window.addEventListener('beforeunload', function (event) {
            if (!dirty && !saving) {
                return;
            }
            event.preventDefault();
            event.returnValue = '';
        });

        if (dirty) {
            notify('changed', {document: clone(documentState), source: 'recovery'});
            scheduleSave(900);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupCanvasProjectStore);
    } else {
        setupCanvasProjectStore();
    }
}());
