(() => {
    'use strict';

    const root = document.querySelector('[data-merchant-studio]');
    if (!root) {
        return;
    }

    const desktopBreakpoint = 768;
    const sidebarStorageKey = 'merchant-studio-sidebar-collapsed';
    const body = document.body;
    const sidebar = root.querySelector('[data-studio-sidebar]');
    const sidebarBackdrop = root.querySelector('[data-studio-sidebar-backdrop]');
    const sidebarToggle = root.querySelector('[data-studio-sidebar-toggle]');
    let lastSidebarFocus = null;

    const syncIcons = () => {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    };

    const setupListingPresets = () => {
        root.querySelectorAll('[data-generation-presets]').forEach((strip) => {
            const panel = strip.closest('.panel');
            const form = panel?.querySelector('[data-generation-form]');
            const buttons = Array.from(strip.querySelectorAll('[data-generation-preset]'));
            if (!form || buttons.length === 0) {
                return;
            }

            const applyPreset = (button) => {
                const selectedTypes = {};
                String(button.dataset.presetTypes || '').split(',').forEach((item) => {
                    const [type, amount] = item.split(':');
                    if (type) {
                        selectedTypes[type] = Math.max(1, Number(amount) || 1);
                    }
                });
                form.querySelectorAll('[data-asset-option]').forEach((option) => {
                    const checkbox = option.querySelector('[data-asset-checkbox]');
                    const quantity = option.querySelector('[data-asset-quantity]');
                    if (!checkbox) {
                        return;
                    }
                    const selected = Object.prototype.hasOwnProperty.call(selectedTypes, checkbox.value);
                    checkbox.checked = selected;
                    checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                    if (quantity && selected) {
                        quantity.value = String(selectedTypes[checkbox.value]);
                        quantity.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
                const detailCount = form.querySelector('[data-bundle-detail-count]');
                const requestedDetailCount = Number(button.dataset.detailCount || 0);
                if (detailCount && requestedDetailCount > 0) {
                    detailCount.value = String(requestedDetailCount);
                    detailCount.dispatchEvent(new Event('change', { bubbles: true }));
                }
                buttons.forEach((candidate) => {
                    const active = candidate === button;
                    candidate.classList.toggle('active', active);
                    candidate.setAttribute('aria-pressed', active ? 'true' : 'false');
                });
            };

            buttons.forEach((button) => button.addEventListener('click', () => applyPreset(button)));
            applyPreset(strip.querySelector('[data-generation-preset].active') || buttons[0]);
        });
    };

    setupListingPresets();

    const setSidebarCollapsed = (collapsed, persist = true) => {
        body.classList.toggle('studio-sidebar-collapsed', collapsed);
        sidebarToggle?.setAttribute('aria-label', collapsed ? '展开项目栏' : '折叠项目栏');
        sidebarToggle?.setAttribute('data-tooltip', collapsed ? '展开项目栏' : '折叠项目栏');
        sidebarToggle?.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        const icon = sidebarToggle?.querySelector('[data-lucide]');
        if (icon) {
            icon.setAttribute('data-lucide', collapsed ? 'panel-left-open' : 'panel-left-close');
        }
        if (persist) {
            localStorage.setItem(sidebarStorageKey, collapsed ? '1' : '0');
        }
        syncIcons();
    };

    const closeMobileSidebar = (restoreFocus = true) => {
        body.classList.remove('studio-sidebar-open');
        sidebarBackdrop?.setAttribute('hidden', '');
        sidebarToggle?.setAttribute('aria-expanded', 'false');
        sidebarToggle?.setAttribute('aria-label', '打开项目栏');
        sidebarToggle?.setAttribute('data-tooltip', '项目栏');
        const icon = sidebarToggle?.querySelector('[data-lucide]');
        if (icon) {
            icon.setAttribute('data-lucide', 'panel-left');
            syncIcons();
        }
        if (restoreFocus && lastSidebarFocus instanceof HTMLElement) {
            lastSidebarFocus.focus();
        }
    };

    const openMobileSidebar = () => {
        lastSidebarFocus = document.activeElement;
        body.classList.add('studio-sidebar-open');
        sidebarBackdrop?.removeAttribute('hidden');
        sidebarToggle?.setAttribute('aria-expanded', 'true');
        sidebarToggle?.setAttribute('aria-label', '关闭项目栏');
        sidebarToggle?.setAttribute('data-tooltip', '关闭项目栏');
        window.setTimeout(() => {
            sidebar?.querySelector('a, button, summary')?.focus();
        }, 20);
    };

    if (window.innerWidth >= desktopBreakpoint) {
        setSidebarCollapsed(localStorage.getItem(sidebarStorageKey) === '1', false);
    }
    sidebarToggle?.addEventListener('click', () => {
        if (window.innerWidth < desktopBreakpoint) {
            if (body.classList.contains('studio-sidebar-open')) {
                closeMobileSidebar();
            } else {
                openMobileSidebar();
            }
            return;
        }
        setSidebarCollapsed(!body.classList.contains('studio-sidebar-collapsed'));
    });
    sidebarBackdrop?.addEventListener('click', () => closeMobileSidebar());

    let resizeTimer = 0;
    window.addEventListener('resize', () => {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(() => {
            if (window.innerWidth >= desktopBreakpoint) {
                closeMobileSidebar(false);
                setSidebarCollapsed(localStorage.getItem(sidebarStorageKey) === '1', false);
            } else {
                body.classList.remove('studio-sidebar-collapsed');
            }
        }, 100);
    });

    const projectSearchToggle = root.querySelector('[data-project-search-toggle]');
    const projectSearch = root.querySelector('[data-project-search]');
    const projectSearchInput = root.querySelector('[data-project-search-input]');
    projectSearchToggle?.addEventListener('click', () => {
        if (!projectSearch) {
            return;
        }
        const opening = projectSearch.hasAttribute('hidden');
        projectSearch.toggleAttribute('hidden', !opening);
        if (opening) {
            projectSearchInput?.focus();
        } else if (projectSearchInput) {
            projectSearchInput.value = '';
            projectSearchInput.dispatchEvent(new Event('input'));
        }
    });
    projectSearchInput?.addEventListener('input', () => {
        const keyword = projectSearchInput.value.trim().toLocaleLowerCase();
        root.querySelectorAll('[data-project-row]').forEach((row) => {
            row.hidden = keyword !== '' && !String(row.dataset.projectSearchText || '').includes(keyword);
        });
        root.querySelectorAll('[data-project-group]').forEach((group) => {
            group.hidden = !Array.from(group.querySelectorAll('[data-project-row]')).some((row) => !row.hidden);
        });
    });

    const renameDialog = root.querySelector('[data-project-rename-dialog]');
    const renameForm = root.querySelector('[data-project-rename-form]');
    const renameInput = root.querySelector('[data-project-rename-input]');
    root.querySelectorAll('[data-project-rename]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!renameDialog || !renameForm || !renameInput) {
                return;
            }
            renameForm.action = String(button.dataset.projectAction || '');
            renameInput.value = String(button.dataset.projectName || '');
            renameDialog.showModal();
            renameInput.focus();
            renameInput.select();
        });
    });
    root.querySelectorAll('[data-project-rename-close]').forEach((button) => {
        button.addEventListener('click', () => renameDialog?.close());
    });
    renameDialog?.addEventListener('click', (event) => {
        if (event.target === renameDialog) {
            renameDialog.close();
        }
    });

    const studioDrawerBackdrop = root.querySelector('[data-studio-drawer-backdrop]');
    let activeStudioDrawer = null;
    let lastStudioDrawerFocus = null;
    const closeStudioDrawer = (restoreFocus = true) => {
        if (!activeStudioDrawer) {
            return;
        }
        activeStudioDrawer.classList.remove('is-open');
        activeStudioDrawer.setAttribute('aria-hidden', 'true');
        studioDrawerBackdrop?.setAttribute('hidden', '');
        body.classList.remove('studio-side-drawer-open');
        if (restoreFocus && lastStudioDrawerFocus instanceof HTMLElement) {
            lastStudioDrawerFocus.focus();
        }
        activeStudioDrawer = null;
    };
    root.querySelectorAll('[data-studio-drawer-open]').forEach((button) => {
        button.addEventListener('click', () => {
            const name = String(button.dataset.studioDrawerOpen || '');
            const drawer = root.querySelector(`[data-studio-drawer="${CSS.escape(name)}"]`);
            if (!drawer) {
                return;
            }
            if (activeStudioDrawer && activeStudioDrawer !== drawer) {
                closeStudioDrawer(false);
            }
            lastStudioDrawerFocus = document.activeElement;
            activeStudioDrawer = drawer;
            drawer.classList.add('is-open');
            drawer.setAttribute('aria-hidden', 'false');
            studioDrawerBackdrop?.removeAttribute('hidden');
            body.classList.add('studio-side-drawer-open');
            window.setTimeout(() => drawer.querySelector('[data-studio-drawer-close]')?.focus(), 20);
        });
    });
    root.querySelectorAll('[data-studio-drawer-close]').forEach((button) => {
        button.addEventListener('click', () => closeStudioDrawer());
    });
    studioDrawerBackdrop?.addEventListener('click', () => closeStudioDrawer());

    document.addEventListener('click', (event) => {
        root.querySelectorAll('.merchant-project-menu[open], .workspace-account-menu[open], .studio-new-menu[open]').forEach((details) => {
            if (!details.contains(event.target)) {
                details.removeAttribute('open');
            }
        });
    });
    root.querySelectorAll('.workspace-account-menu').forEach((details) => {
        const summary = details.querySelector(':scope > summary');
        if (!summary) {
            return;
        }
        details.addEventListener('toggle', () => {
            summary.setAttribute('aria-expanded', details.open ? 'true' : 'false');
        });
    });

    root.querySelectorAll('[data-launch-example]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = root.querySelector('[data-launch-brief]');
            if (!(input instanceof HTMLTextAreaElement)) {
                return;
            }
            input.value = String(button.dataset.launchExample || button.textContent || '').trim();
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.focus();
        });
    });

    const taskDrawer = root.querySelector('[data-workspace-task-drawer]');
    const taskBackdrop = root.querySelector('[data-workspace-task-backdrop]');
    const taskList = root.querySelector('[data-workspace-task-list]');
    const taskOverview = root.querySelector('[data-workspace-task-overview]');
    const taskBadge = root.querySelector('[data-workspace-task-badge]');
    const taskOpeners = root.querySelectorAll('[data-workspace-task-open]');
    const taskTabs = Array.from(root.querySelectorAll('[data-workspace-task-tab]'));
    let taskData = [];
    let selectedTaskTab = 'active';
    let taskTimer = 0;
    let taskLoading = false;
    let lastTaskFocus = null;
    let followNewestTask = false;

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const taskBucket = (job) => {
        if (['running', 'processing', 'retrying'].includes(job.status)) {
            return 'active';
        }
        if (['queued', 'pending_gateway'].includes(job.status)) {
            return 'queued';
        }
        if (['failed', 'partial', 'result_missing'].includes(job.status) || job.resultStatus === 'missing') {
            return 'attention';
        }
        return 'completed';
    };

    const taskPercent = (job) => {
        if (Number.isFinite(Number(job.progressPercent))) {
            return Math.max(0, Math.min(100, Number(job.progressPercent)));
        }
        const total = Math.max(0, Number(job.progressTotal || 0));
        const current = Math.max(0, Number(job.progressCurrent || 0));
        return total > 0 ? Math.max(0, Math.min(100, Math.round((current / total) * 100))) : 0;
    };

    const taskStatusCopy = (job) => {
        const bucket = taskBucket(job);
        const isVideo = job.outputKind === 'video';
        const available = Number(job.availableResults || 0);
        const expected = Number(job.expectedResults || job.progressTotal || 0);
        if (bucket === 'active') {
            return job.progressMessage || `正在生成 ${Math.max(0, Number(job.progressCurrent || 0))}/${Math.max(1, Number(job.progressTotal || 1))} 张`;
        }
        if (bucket === 'queued') {
            return job.progressMessage || '已进入队列，系统会自动开始处理';
        }
        if (bucket === 'attention') {
            if (job.status === 'partial' || available > 0) {
                return isVideo
                    ? '视频任务获得了部分结果，需要继续处理'
                    : `已完成 ${available}/${Math.max(expected, available)} 张，其余结果需要处理`;
            }
            return job.errorMessage || '任务没有获得可用结果，请修改后重试';
        }
        return available > 0
            ? (isVideo ? '视频已保存到素材库' : `${available} 张已保存到素材库`)
            : (job.progressMessage || '任务已完成');
    };

    const taskIdentity = (job) => {
        const project = String(job.projectTitle || '').trim();
        const type = String(job.title || '创作任务').trim();
        return project && project !== type ? `${project} · ${type}` : (project || type);
    };

    const taskTime = (job) => {
        const raw = String(job.createdAt || job.updatedAt || '').trim();
        if (!raw) {
            return '';
        }
        const parsed = new Date(raw.replace(' ', 'T'));
        if (Number.isNaN(parsed.getTime())) {
            return raw;
        }
        return new Intl.DateTimeFormat('zh-CN', {
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        }).format(parsed);
    };

    const taskAction = (job) => {
        const bucket = taskBucket(job);
        if (bucket === 'attention') {
            return { label: job.canRetry ? '修改并重试' : '查看处理建议', icon: 'rotate-cw' };
        }
        if (bucket === 'completed') {
            return { label: '查看创作结果', icon: 'images' };
        }
        return { label: '返回创作项目', icon: 'arrow-up-right' };
    };

    const renderTasks = () => {
        if (!taskList) {
            return;
        }
        const visible = taskData.filter((job) => taskBucket(job) === selectedTaskTab);
        taskTabs.forEach((tab) => {
            const bucket = String(tab.dataset.workspaceTaskTab || '');
            const count = taskData.filter((job) => taskBucket(job) === bucket).length;
            tab.classList.toggle('active', bucket === selectedTaskTab);
            tab.setAttribute('aria-selected', bucket === selectedTaskTab ? 'true' : 'false');
            const baseLabel = String(tab.textContent || '').replace(/\s*\(\d+\)\s*$/, '').trim();
            tab.textContent = count > 0 ? `${baseLabel} (${count})` : baseLabel;
        });

        if (visible.length === 0) {
            const emptyCopy = {
                active: ['当前没有运行中的任务', '新建任务后可以在这里持续查看进度。'],
                queued: ['队列已经处理完毕', '等待中的任务会自动进入生成流程。'],
                attention: ['当前没有需要处理的任务', '失败或部分完成的任务会集中出现在这里。'],
                completed: ['还没有最近完成的任务', '生成结果完成后会自动保存到素材库。'],
            }[selectedTaskTab];
            taskList.innerHTML = `<div class="workspace-task-empty"><i data-lucide="check-circle-2"></i><strong>${emptyCopy[0]}</strong><span>${emptyCopy[1]}</span></div>`;
            syncIcons();
            return;
        }

        taskList.innerHTML = visible.slice(0, 24).map((job) => {
            const bucket = taskBucket(job);
            const percent = taskPercent(job);
            const action = taskAction(job);
            const labels = Array.isArray(job.assetTypeLabels) ? job.assetTypeLabels.slice(0, 2) : [];
            const preview = Array.isArray(job.previewUrls) ? job.previewUrls.find(Boolean) : '';
            const credit = Number(job.actualCredits || 0) > 0
                ? `实际 ${Number(job.actualCredits).toLocaleString('zh-CN')} 点`
                : `预计 ${Number(job.estimatedCredits || 0).toLocaleString('zh-CN')} 点`;
            const progress = bucket === 'active'
                ? `<div class="workspace-task-progress"><span><i style="width:${percent}%"></i></span><b>${percent}%</b></div>`
                : '';
            const identity = taskIdentity(job);
            const createdAt = taskTime(job);
            const error = bucket === 'attention'
                ? `<div class="workspace-task-error">
                    <strong class="workspace-task-error-identity">${escapeHtml(identity)}</strong>
                    <span>${escapeHtml(taskStatusCopy(job))}</span>
                    <small>${createdAt ? `创建于 ${escapeHtml(createdAt)} · ` : ''}${Number(job.actualCredits || 0) > 0 ? '仅按已成功结果结算，失败部分不会重复扣费。' : '未产出可用结果时，预扣点数会按原规则退回。'}</small>
                </div>`
                : `<p>${escapeHtml(taskStatusCopy(job))}</p>`;
            return `<article class="workspace-task-card is-${bucket}">
                <a class="workspace-task-card-main" href="${escapeHtml(job.detailUrl || '/workspace/jobs')}">
                    <span class="workspace-task-preview">${preview ? `<img src="${escapeHtml(preview)}" alt="">` : `<i data-lucide="${bucket === 'attention' ? 'circle-alert' : (job.outputKind === 'video' ? 'clapperboard' : 'image')}"></i>`}</span>
                    <span class="workspace-task-copy">
                        <span class="workspace-task-heading"><strong>${escapeHtml(job.projectTitle || job.title || '创作任务')}</strong><em class="task-state-${bucket}">${escapeHtml(job.statusLabel || '')}</em></span>
                        <small>${escapeHtml(job.title || '')}${labels.length ? ` · ${escapeHtml(labels.join('、'))}` : ''}</small>
                        <span class="workspace-task-model"><i data-lucide="cpu"></i>${escapeHtml(job.modelName || '系统默认模型')}</span>
                    </span>
                </a>
                ${progress}
                ${error}
                <footer><span>${credit}</span>${job.canRetry && job.retryUrl
                    ? `<button type="button" data-vision-task-retry="${escapeHtml(job.retryUrl)}"><i data-lucide="rotate-cw"></i>重新处理</button>`
                    : `<a href="${escapeHtml(job.detailUrl || '/workspace/jobs')}"><i data-lucide="${action.icon}"></i>${action.label}</a>`}</footer>
            </article>`;
        }).join('');
        taskList.querySelectorAll('[data-vision-task-retry]').forEach((button) => {
            button.addEventListener('click', async () => {
                const url = String(button.dataset.visionTaskRetry || '');
                if (!url || button.disabled) {
                    return;
                }
                button.disabled = true;
                const formData = new FormData();
                formData.append('_token', String(root.dataset.csrfToken || ''));
                try {
                    const response = await fetch(url, {method: 'POST', body: formData, credentials: 'same-origin'});
                    const payload = await response.json();
                    if (!response.ok || !payload.ok) {
                        throw new Error(payload.message || '任务重新提交失败');
                    }
                    selectedTaskTab = 'active';
                    await refreshTasks(true);
                } catch (error) {
                    button.disabled = false;
                    button.textContent = error.message || '请稍后重试';
                }
            });
        });
        syncIcons();
    };

    const refreshTasks = async (silent = false) => {
        if (taskLoading) {
            return;
        }
        taskLoading = true;
        if (!silent && taskOverview) {
            taskOverview.textContent = '正在读取真实任务状态';
        }
        try {
            const response = await fetch('/workspace/jobs/status', {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) {
                throw new Error(payload.message || '任务状态读取失败');
            }
            taskData = Array.isArray(payload.jobs) ? payload.jobs : [];
            if (followNewestTask && taskData.length > 0) {
                selectedTaskTab = taskBucket(taskData[0]);
            }
            const activeCount = taskData.filter((job) => taskBucket(job) === 'active').length;
            const queuedCount = taskData.filter((job) => taskBucket(job) === 'queued').length;
            const attentionCount = Math.max(
                taskData.filter((job) => taskBucket(job) === 'attention').length,
                Math.max(0, Number(payload.failedCount || 0)),
            );
            if (taskOverview) {
                taskOverview.textContent = attentionCount > 0
                    ? `${attentionCount} 个任务需要处理`
                    : (activeCount > 0 ? `${activeCount} 个任务正在生成` : (queuedCount > 0 ? `${queuedCount} 个任务等待处理` : '当前任务运行正常'));
            }
            if (taskBadge) {
                const badgeCount = attentionCount > 0 ? attentionCount : activeCount + queuedCount;
                taskBadge.hidden = badgeCount === 0;
                taskBadge.textContent = badgeCount > 99 ? '99+' : String(badgeCount);
                taskBadge.classList.toggle('is-attention', attentionCount > 0);
                taskBadge.classList.toggle('is-active', attentionCount === 0 && activeCount > 0);
                taskBadge.classList.toggle('is-queued', attentionCount === 0 && activeCount === 0 && queuedCount > 0);
                taskBadge.setAttribute('aria-label', attentionCount > 0
                    ? `${attentionCount} 个任务需要处理`
                    : (activeCount > 0 ? `${activeCount} 个任务正在生成` : `${queuedCount} 个任务等待处理`));
            }
            const primaryTaskOpener = root.querySelector('[data-workspace-task-primary]');
            if (primaryTaskOpener) {
                const openerLabel = attentionCount > 0
                    ? `${attentionCount} 个任务需要处理，打开生成进度`
                    : (activeCount > 0
                        ? `${activeCount} 个任务正在生成，打开生成进度`
                        : (queuedCount > 0 ? `${queuedCount} 个任务等待处理，打开生成进度` : '打开生成进度'));
                primaryTaskOpener.setAttribute('aria-label', openerLabel);
            }
            renderTasks();
        } catch (error) {
            if (taskOverview) {
                taskOverview.textContent = '任务状态暂时无法读取';
            }
            if (taskList && taskDrawer?.getAttribute('aria-hidden') === 'false') {
                taskList.innerHTML = `<div class="workspace-task-empty is-error"><i data-lucide="wifi-off"></i><strong>任务状态加载失败</strong><span>${escapeHtml(error.message || '请稍后重试')}</span><button class="button small" type="button" data-task-refresh>重新加载</button></div>`;
                taskList.querySelector('[data-task-refresh]')?.addEventListener('click', () => refreshTasks());
                syncIcons();
            }
        } finally {
            taskLoading = false;
        }
    };

    const closeTaskDrawer = (restoreFocus = true) => {
        if (!taskDrawer) {
            return;
        }
        taskDrawer.setAttribute('aria-hidden', 'true');
        taskBackdrop?.setAttribute('hidden', '');
        body.classList.remove('workspace-task-open');
        taskOpeners.forEach((button) => button.setAttribute('aria-expanded', 'false'));
        followNewestTask = false;
        if (restoreFocus && lastTaskFocus instanceof HTMLElement) {
            lastTaskFocus.focus();
        }
    };

    const openTaskDrawer = (tab = 'active') => {
        if (!taskDrawer) {
            return;
        }
        lastTaskFocus = document.activeElement;
        followNewestTask = tab === 'latest';
        selectedTaskTab = ['active', 'queued', 'attention', 'completed'].includes(tab) ? tab : 'active';
        taskDrawer.setAttribute('aria-hidden', 'false');
        taskBackdrop?.removeAttribute('hidden');
        body.classList.add('workspace-task-open');
        taskOpeners.forEach((button) => button.setAttribute('aria-expanded', 'true'));
        renderTasks();
        refreshTasks();
        window.setTimeout(() => taskDrawer.querySelector('[data-workspace-task-close]')?.focus(), 20);
    };

    taskOpeners.forEach((button) => {
        button.setAttribute('aria-expanded', 'false');
        button.addEventListener('click', () => {
            openTaskDrawer(String(button.dataset.taskInitialTab || (Number(root.dataset.initialFailedJobCount || 0) > 0 ? 'attention' : 'active')));
        });
    });
    root.querySelector('[data-workspace-task-close]')?.addEventListener('click', () => closeTaskDrawer());
    taskBackdrop?.addEventListener('click', () => closeTaskDrawer());
    taskTabs.forEach((tab) => {
        tab.setAttribute('role', 'tab');
        tab.addEventListener('click', () => {
            followNewestTask = false;
            selectedTaskTab = String(tab.dataset.workspaceTaskTab || 'active');
            renderTasks();
        });
    });

    const taskCenterUrl = new URL(window.location.href);
    if (taskCenterUrl.searchParams.get('task_center') === 'open') {
        const requestedTab = taskCenterUrl.searchParams.get('task_tab') || 'active';
        taskCenterUrl.searchParams.delete('task_center');
        taskCenterUrl.searchParams.delete('task_tab');
        window.history.replaceState({}, '', `${taskCenterUrl.pathname}${taskCenterUrl.search}${taskCenterUrl.hash}`);
        window.setTimeout(() => openTaskDrawer(requestedTab), 80);
    }

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        if (taskDrawer?.getAttribute('aria-hidden') === 'false') {
            closeTaskDrawer();
        } else if (activeStudioDrawer) {
            closeStudioDrawer();
        } else if (body.classList.contains('studio-sidebar-open')) {
            closeMobileSidebar();
        }
    });

    document.addEventListener('global-task-refresh', () => refreshTasks(true));
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            refreshTasks(true);
        }
    });

    const scheduleTaskRefresh = () => {
        window.clearTimeout(taskTimer);
        const delay = taskDrawer?.getAttribute('aria-hidden') === 'false' ? 8000 : (document.hidden ? 30000 : 16000);
        taskTimer = window.setTimeout(async () => {
            await refreshTasks(true);
            scheduleTaskRefresh();
        }, delay);
    };
    refreshTasks(true).finally(scheduleTaskRefresh);
})();
