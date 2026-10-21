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

function safeWorkspaceUrl(value, fallback) {
    if (!String(value || '').trim()) return fallback;
    try {
        var url = new URL(String(value || ''), window.location.href);
        if ((url.protocol === 'http:' || url.protocol === 'https:')
            && url.origin === window.location.origin && !url.username && !url.password
            && url.pathname.indexOf('/workspace/') === 0) {
            return url.href;
        }
    } catch (error) {
        // Internal actions must remain in this workspace even if a DOM attribute is malformed.
    }
    return fallback;
}

document.addEventListener('DOMContentLoaded', function () {
    setupTopNavigationMenus();
    setupDismissibleDetails();
    setupTaskRecordMenus();
    setupAiModelsCenter();
    setupAiModelRelease();
    setupProviderAccountForm();
    setupUpstreamCostConfig();
    setupHomeAnchorNavigation();
    setupJobFailureNotice();
    if (window.lucide) {
        window.lucide.createIcons({
            attrs: {
                'aria-hidden': 'true',
                'stroke-width': 1.8,
            },
        });
    }

    setupModeForm();
    setupStorageForm();
    setupGenerationReference();
    setupCreativeReadinessGate();
    setupSkuReportQuickForm();
    setupQuantityPickers();
    setupGenerationPricing();
    setupVideoGenerationPricing();
    setupPodCarousel();
    setupListingBundle();
    setupDetailGeneration();
    setupDetailCountPricing();
    setupDetailOutputSize();
    setupFreeGeneration();
    setupImageFallbacks();
    setupAssetPreview();
    setupCanvasEditor();
    setupSkuAnalyzer();
    setupAssetManager();
    setupAssetLibrary();
    setupConfirmForms();
    setupGeetestForms();
    setupSubmitLoading();
    setupJobProgress();
    setupDetailPageCreate();
    setupDetailPageChat();
    setupSkuAdvisor();
    setupSkuImagePreview();
    setupProductLaunch();
    setupRatioPickers();
});

function setupCreativeReadinessGate() {
    var selects = Array.prototype.slice.call(document.querySelectorAll('[data-creative-ready-select]'));
    selects.forEach(function (select) {
        var form = select.closest('form');
        if (!form) {
            return;
        }
        var note = document.createElement('small');
        note.className = 'creative-readiness-inline';
        note.setAttribute('role', 'status');
        note.hidden = true;
        select.insertAdjacentElement('afterend', note);
        var outputMode = form.querySelector('[name="creative_output_mode"]');

        function sync() {
            var option = select.options[select.selectedIndex] || null;
            var hasSelection = !!(option && option.value);
            var readinessAttribute = outputMode && outputMode.value === 'catalog_base'
                ? 'data-catalog-ready'
                : 'data-creative-ready';
            var ready = !hasSelection || option.getAttribute(readinessAttribute) === '1';
            var state = option ? (option.getAttribute('data-creative-state') || 'processing') : 'processing';
            Array.prototype.forEach.call(form.querySelectorAll('button[type="submit"]'), function (button) {
                if (!ready) {
                    if (!button.disabled) {
                        button.setAttribute('data-readiness-disabled', '1');
                    }
                    button.disabled = true;
                } else if (button.getAttribute('data-readiness-disabled') === '1') {
                    button.removeAttribute('data-readiness-disabled');
                    if (!button.hasAttribute('data-model-disabled')) {
                        button.disabled = false;
                    }
                }
            });
            note.hidden = ready;
            note.textContent = ready ? '' : '商品尚未达到创作就绪（' + state + '），本次不会创建任务或预扣点数。';
            form.setAttribute('data-creative-ready', ready ? '1' : '0');
            form.dispatchEvent(new CustomEvent('creative-readiness-change', {detail: {ready: ready}}));
        }

        select.addEventListener('change', sync);
        if (outputMode) {
            outputMode.addEventListener('change', sync);
        }
        form.addEventListener('submit', function (event) {
            if (form.getAttribute('data-creative-ready') !== '0') {
                return;
            }
            event.preventDefault();
            event.stopImmediatePropagation();
            var notice = document.querySelector('[data-creative-readiness-notice]');
            if (notice) {
                notice.scrollIntoView({behavior: 'smooth', block: 'center'});
            }
        });
        sync();
    });
}

function setupDismissibleDetails() {
    var selector = 'details[data-dismiss-on-outside]';
    var details = Array.prototype.slice.call(document.querySelectorAll(selector));
    if (!details.length) {
        return;
    }
    var lastOpenedSummary = null;

    function close(detail) {
        if (detail && detail.open) {
            detail.open = false;
        }
    }

    details.forEach(function (detail) {
        detail.addEventListener('toggle', function () {
            if (!detail.open) {
                return;
            }
            lastOpenedSummary = detail.querySelector(':scope > summary');
            details.forEach(function (candidate) {
                if (candidate !== detail && candidate.open) {
                    close(candidate);
                }
            });
        });
    });

    document.addEventListener('pointerdown', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        if (target && target.closest(selector)) {
            return;
        }
        details.forEach(close);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }
        var openDetails = details.filter(function (detail) { return detail.open; });
        if (!openDetails.length) {
            return;
        }
        event.preventDefault();
        openDetails.forEach(close);
        if (lastOpenedSummary && document.contains(lastOpenedSummary)) {
            lastOpenedSummary.focus();
        }
    });
}

function setupTaskRecordMenus() {
    var menus = Array.prototype.slice.call(document.querySelectorAll('.task-record-more'));
    if (!menus.length) {
        return;
    }
    var safeEdge = 12;
    var scheduled = 0;

    function reset(menu) {
        var panel = menu.querySelector(':scope > div');
        if (!panel) {
            return;
        }
        panel.classList.remove('is-viewport-floating', 'opens-up');
        panel.style.removeProperty('left');
        panel.style.removeProperty('top');
        panel.style.removeProperty('max-height');
    }

    function topSafeEdge() {
        var topbar = document.querySelector('.merchant-studio-topbar');
        if (!topbar) {
            return safeEdge;
        }
        return Math.max(safeEdge, Math.ceil(topbar.getBoundingClientRect().bottom) + 8);
    }

    function place(menu) {
        if (!menu.open) {
            reset(menu);
            return;
        }
        var summary = menu.querySelector(':scope > summary');
        var panel = menu.querySelector(':scope > div');
        if (!summary || !panel) {
            return;
        }
        var trigger = summary.getBoundingClientRect();
        var safeTop = topSafeEdge();
        var viewportWidth = document.documentElement.clientWidth;
        var viewportHeight = document.documentElement.clientHeight;
        if (trigger.bottom < safeTop || trigger.top > viewportHeight - safeEdge) {
            menu.open = false;
            return;
        }

        panel.classList.add('is-viewport-floating');
        panel.style.left = safeEdge + 'px';
        panel.style.top = safeTop + 'px';
        panel.style.maxHeight = Math.max(120, viewportHeight - safeTop - safeEdge) + 'px';

        var panelRect = panel.getBoundingClientRect();
        var panelWidth = Math.min(panelRect.width, viewportWidth - safeEdge * 2);
        var panelHeight = Math.min(panel.scrollHeight, viewportHeight - safeTop - safeEdge);
        var spaceBelow = viewportHeight - safeEdge - trigger.bottom;
        var spaceAbove = trigger.top - safeTop;
        var opensUp = spaceBelow < Math.min(panelHeight, 180) && spaceAbove > spaceBelow;
        var available = Math.max(120, opensUp ? spaceAbove - 6 : spaceBelow - 6);
        var left = Math.min(
            Math.max(safeEdge, trigger.right - panelWidth),
            viewportWidth - safeEdge - panelWidth
        );
        var top = opensUp
            ? Math.max(safeTop, trigger.top - Math.min(panelHeight, available) - 6)
            : Math.min(trigger.bottom + 6, viewportHeight - safeEdge - Math.min(panelHeight, available));

        panel.classList.toggle('opens-up', opensUp);
        panel.style.left = Math.round(left) + 'px';
        panel.style.top = Math.round(top) + 'px';
        panel.style.maxHeight = Math.floor(available) + 'px';
    }

    function placeOpenMenus() {
        window.cancelAnimationFrame(scheduled);
        scheduled = window.requestAnimationFrame(function () {
            menus.forEach(place);
        });
    }

    menus.forEach(function (menu) {
        menu.addEventListener('toggle', function () {
            if (!menu.open) {
                reset(menu);
                return;
            }
            window.requestAnimationFrame(function () { place(menu); });
        });
    });
    window.addEventListener('resize', placeOpenMenus);
    window.addEventListener('orientationchange', placeOpenMenus);
    document.addEventListener('scroll', placeOpenMenus, true);
}

function setupQuantityPickers() {
    document.querySelectorAll('[data-quantity-picker]').forEach(function (picker) {
        var quick = picker.querySelector('[data-quantity-quick]');
        var custom = picker.querySelector('[data-quantity-custom]');
        var customUnit = picker.querySelector('[data-quantity-custom-unit]');
        var valueInput = picker.querySelector('[data-quantity-value]');
        if (!quick || !valueInput) {
            return;
        }
        var max = Math.max(1, parseInt(picker.getAttribute('data-quantity-max') || '1', 10));
        var customMin = custom ? Math.max(1, parseInt(custom.min || '5', 10)) : max;

        function normalized(value, fallback) {
            var parsed = parseInt(value, 10);
            if (!Number.isFinite(parsed)) {
                parsed = fallback;
            }
            return Math.max(1, Math.min(max, parsed));
        }
        var lastValid = normalized(valueInput.value, 1);
        var customStart = Math.max(customMin, lastValid);

        function syncControls() {
            var current = normalized(valueInput.value, 1);
            valueInput.value = String(current);
            var quickOption = quick.querySelector('option[value="' + current + '"]');
            var usesCustom = !quickOption && Boolean(custom);
            quick.value = usesCustom ? 'custom' : String(current);
            quick.disabled = valueInput.disabled;
            if (custom) {
                custom.hidden = !usesCustom;
                if (customUnit) { customUnit.hidden = !usesCustom; }
                custom.disabled = valueInput.disabled || !usesCustom;
                if (usesCustom) {
                    custom.value = String(Math.max(customMin, current));
                }
            }
        }

        function commit(value) {
            valueInput.value = String(normalized(value, customMin));
            lastValid = normalized(valueInput.value, lastValid);
            valueInput.dispatchEvent(new Event('change', {bubbles: true}));
        }

        quick.addEventListener('change', function () {
            if (quick.value !== 'custom' || !custom) {
                commit(quick.value);
                return;
            }
            custom.hidden = false;
            if (customUnit) { customUnit.hidden = false; }
            custom.disabled = valueInput.disabled;
            customStart = Math.max(customMin, lastValid);
            custom.value = String(customStart);
            commit(customStart);
            window.requestAnimationFrame(function () {
                if (!custom.disabled) {
                    custom.focus();
                    custom.select();
                }
            });
        });

        if (custom) {
            custom.addEventListener('input', function () {
                if (custom.value === '') {
                    return;
                }
                var parsed = parseInt(custom.value, 10);
                if (Number.isFinite(parsed) && parsed >= customMin && parsed <= max) {
                    commit(parsed);
                }
            });
            function finishCustom() {
                if (custom.value === '') {
                    custom.value = String(Math.max(customMin, lastValid));
                }
                var count = Math.max(customMin, normalized(custom.value, customMin));
                custom.value = String(count);
                commit(count);
            }
            custom.addEventListener('change', finishCustom);
            custom.addEventListener('blur', finishCustom);
            custom.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    finishCustom();
                    custom.blur();
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    custom.value = String(customStart);
                    commit(customStart);
                    custom.blur();
                }
            });
        }

        valueInput.addEventListener('change', syncControls);
        syncControls();
    });
}

function setupJobFailureNotice() {
    var monitor = document.querySelector('[data-job-failure-monitor]');
    if (!monitor) {
        return;
    }
    var badge = document.querySelector('[data-failed-job-badge]');
    var recordLink = document.querySelector('[data-job-record-link]');
    var toast = document.querySelector('[data-job-failure-toast]');
    var toastCopy = document.querySelector('[data-job-failure-toast-copy]');
    var close = document.querySelector('[data-job-failure-toast-close]');
    var merchantId = monitor.getAttribute('data-merchant-id') || 'default';
    var initialCount = Math.max(0, parseInt(monitor.getAttribute('data-initial-failed-job-count') || '0', 10));
    var storageKey = 'asset-factory-failed-job-count-' + merchantId;
    var knownCount = initialCount;
    var timer = null;

    try {
        var storedCount = parseInt(window.localStorage.getItem(storageKey) || '', 10);
        if (!Number.isNaN(storedCount)) {
            knownCount = storedCount;
        }
    } catch (error) {
        // A blocked storage API should not prevent task monitoring.
    }

    function persist(count) {
        try { window.localStorage.setItem(storageKey, String(count)); } catch (error) { /* Ignore storage failures. */ }
    }

    function updateBadge(count) {
        if (badge) {
            badge.hidden = count <= 0;
            badge.textContent = count > 99 ? '99+' : String(count);
        }
        if (recordLink) {
            recordLink.href = count > 0 ? '/workspace/jobs?status=failed' : '/workspace/jobs';
            recordLink.setAttribute('aria-label', count > 0 ? '任务记录，' + count + ' 条生成失败' : '任务记录');
        }
    }

    function showToast(count) {
        if (!toast || !toastCopy) {
            return;
        }
        toastCopy.textContent = count + ' 条任务生成失败，失败原因已保留，未使用点数会自动退回。';
        toast.hidden = false;
    }

    function refresh() {
        if (document.visibilityState === 'hidden') {
            schedule();
            return;
        }
        fetch('/workspace/jobs/status', {credentials: 'same-origin', cache: 'no-store'})
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('任务提醒刷新失败');
                }
                return response.json();
            })
            .then(function (result) {
                var count = Math.max(0, parseInt(result.failedCount || 0, 10));
                updateBadge(count);
                if (count > knownCount) {
                    showToast(count);
                }
                knownCount = count;
                persist(count);
            })
            .catch(function () {
                // Keep the server-rendered indicator visible when polling is unavailable.
            })
            .finally(schedule);
    }

    function schedule() {
        window.clearTimeout(timer);
        timer = window.setTimeout(refresh, 20000);
    }

    updateBadge(initialCount);
    persist(Math.max(knownCount, initialCount));
    if (close) {
        close.addEventListener('click', function () { toast.hidden = true; });
    }
    timer = window.setTimeout(refresh, 5000);
}

function setupTopNavigationMenus() {
    if (!window.matchMedia || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
        return;
    }

    document.querySelectorAll('.top-nav-more').forEach(function (menu) {
        var summary = menu.querySelector('summary');
        if (!summary) {
            return;
        }

        menu.addEventListener('mouseenter', function () {
            menu.open = true;
        });
        menu.addEventListener('mouseleave', function () {
            if (!menu.contains(document.activeElement)) {
                menu.open = false;
            }
        });
        menu.addEventListener('focusin', function () {
            menu.open = true;
        });
        menu.addEventListener('focusout', function () {
            window.setTimeout(function () {
                if (!menu.contains(document.activeElement)) {
                    menu.open = false;
                }
            }, 0);
        });
        summary.addEventListener('click', function (event) {
            event.preventDefault();
            menu.open = true;
        });
        summary.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                menu.open = false;
                summary.focus();
            }
        });
    });
}

function setupHomeAnchorNavigation() {
    if (!document.body.classList.contains('home-body')) {
        return;
    }

    var links = Array.prototype.slice.call(document.querySelectorAll('.workspace-primary-nav a[href^="#"]'));
    if (!links.length) {
        return;
    }

    function setActive(hash) {
        var targetHash = hash || '#product-launch';
        links.forEach(function (link) {
            var active = link.getAttribute('href') === targetHash;
            link.classList.toggle('active', active);
            if (active) {
                link.setAttribute('aria-current', 'location');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    }

    links.forEach(function (link) {
        link.addEventListener('click', function () {
            setActive(link.getAttribute('href'));
        });
    });

    window.addEventListener('hashchange', function () {
        setActive(window.location.hash);
    });

    setActive(window.location.hash);
}

function setupGeetestForms() {
    var forms = document.querySelectorAll('[data-geetest-form]');
    if (!forms.length) {
        return;
    }
    forms.forEach(function (form) {
        var status = form.querySelector('[data-geetest-status]');
        var challenge = form.querySelector('[data-geetest-challenge]');
        var validate = form.querySelector('[data-geetest-validate]');
        var seccode = form.querySelector('[data-geetest-seccode]');
        var captcha = null;
        var verified = false;

        function setStatus(message, error) {
            if (!status) {
                return;
            }
            status.textContent = message;
            status.hidden = message === '';
            status.classList.toggle('error', Boolean(error));
        }

        function submitVerifiedForm() {
            verified = true;
            setStatus('验证通过，正在提交...', false);
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }
            form.submit();
        }

        form.addEventListener('submit', function (event) {
            if (verified) {
                return;
            }
            event.preventDefault();
            if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
                return;
            }
            if (!captcha) {
                setStatus('安全验证尚未准备好，请稍后重试。', true);
                return;
            }
            captcha.verify();
        });

        if (typeof window.initGeetest !== 'function') {
            setStatus('安全验证加载失败，请刷新页面后重试。', true);
            return;
        }
        fetch(form.getAttribute('data-geetest-register-url'), {credentials: 'same-origin', cache: 'no-store'})
            .then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (result) {
                    if (!response.ok || !result.ok || !result.config) {
                        throw new Error('安全验证暂时不可用，请稍后重试。');
                    }
                    return result.config;
                });
            })
            .then(function (config) {
                window.initGeetest({
                    gt: config.gt,
                    challenge: config.challenge,
                    new_captcha: config.new_captcha === true,
                    success: config.success === 1 ? 1 : 0,
                    product: 'bind',
                    lang: 'zh-cn',
                    https: true,
                }, function (instance) {
                    captcha = instance;
                    captcha.onReady(function () {
                        setStatus('', false);
                    }).onSuccess(function () {
                        var result = captcha.getValidate();
                        if (!result || !result.geetest_challenge || !result.geetest_validate || !result.geetest_seccode) {
                            setStatus('安全验证结果不完整，请重新验证。', true);
                            captcha.reset();
                            return;
                        }
                        challenge.value = result.geetest_challenge;
                        validate.value = result.geetest_validate;
                        seccode.value = result.geetest_seccode;
                        submitVerifiedForm();
                    }).onError(function () {
                        setStatus('安全验证出现异常，请刷新页面后重试。', true);
                    }).onClose(function () {
                        setStatus('请完成安全验证后再提交。', true);
                    });
                });
            })
            .catch(function (error) {
                setStatus('安全验证暂时不可用，请稍后重试。', true);
            });
    });
}

function setupProductLaunch() {
    var landingDraftKey = 'asset-factory-product-launch-v1';
    var launch = document.querySelector('[data-product-launch]');
    if (!launch) {
        return;
    }
    var form = launch.querySelector('form');
    var file = launch.querySelector('#launch-source-image');
    var previews = launch.querySelector('[data-launch-images]');
    var submit = launch.querySelector('[data-launch-submit]');
    var brief = launch.querySelector('[data-launch-brief]');
    var aiButton = launch.querySelector('[data-launch-ai-assist]');
    var dropzone = launch.querySelector('[data-launch-dropzone]');
    var preferenceSummary = launch.querySelector('[data-launch-preference-summary]');
    var planSummary = launch.querySelector('[data-launch-plan-summary]');
    var aiStatus = launch.querySelector('[data-launch-ai-status]');
    var progress = launch.querySelector('[data-launch-progress]');
    var progressTitle = launch.querySelector('[data-launch-progress-title]');
    var progressPercent = launch.querySelector('[data-launch-progress-percent]');
    var progressBar = launch.querySelector('[data-launch-progress-bar]');
    var progressMessage = launch.querySelector('[data-launch-progress-message]');
    var submitReason = launch.querySelector('[data-launch-submit-reason]');
    var retryButton = launch.querySelector('[data-launch-retry]');
    var submissionInput = launch.querySelector('[data-launch-client-submission-id]');
    var selectedFiles = [];
    var previewUrls = [];
    var replaceIndex = -1;
    var launchLocked = false;
    if (!form || !submit || !brief || !file || !previews || !aiButton) {
        return;
    }
    function syncLaunchComposerClearance() {
        if (!dropzone) { return; }
        launch.style.setProperty('--product-launch-composer-height', Math.ceil(dropzone.getBoundingClientRect().height) + 'px');
    }
    var launchComposerObserver = typeof window.ResizeObserver === 'function'
        ? new ResizeObserver(syncLaunchComposerClearance)
        : null;
    if (launchComposerObserver && dropzone) { launchComposerObserver.observe(dropzone); }
    window.addEventListener('resize', syncLaunchComposerClearance);
    window.requestAnimationFrame(syncLaunchComposerClearance);
    function syncLaunchFiles() {
        var transfer = new DataTransfer();
        selectedFiles.forEach(function (selected) {
            transfer.items.add(selected);
        });
        file.files = transfer.files;
    }
    function releasePreviewUrls() {
        previewUrls.forEach(function (url) {
            URL.revokeObjectURL(url);
        });
        previewUrls = [];
    }
    function renderLaunchImages() {
        releasePreviewUrls();
        previews.innerHTML = '';
        selectedFiles.forEach(function (selected, index) {
            var item = document.createElement('div');
            item.className = 'product-launch-image';
            var image = document.createElement('img');
            var url = URL.createObjectURL(selected);
            previewUrls.push(url);
            image.src = safeImageUrl(url);
            image.alt = index === 0 ? '商品身份主参考图' : '商品补充参考图';
            var copy = document.createElement('span');
            var role = document.createElement('b');
            role.textContent = index === 0 ? '主参考' : '补充 ' + (index + 1);
            var name = document.createElement('small');
            name.textContent = selected.name;
            copy.appendChild(role);
            copy.appendChild(name);
            var replace = document.createElement('button');
            replace.className = 'product-launch-image-replace';
            replace.type = 'button';
            replace.textContent = '替换';
            replace.addEventListener('click', function () {
                if (launchLocked) { return; }
                replaceIndex = index;
                file.click();
            });
            var remove = document.createElement('button');
            remove.className = 'icon-button danger';
            remove.type = 'button';
            remove.title = '移除这张参考图';
            remove.setAttribute('aria-label', '移除参考图 ' + selected.name);
            remove.innerHTML = '<i data-lucide="x"></i>';
            remove.addEventListener('click', function () {
                if (launchLocked) { return; }
                selectedFiles.splice(index, 1);
                syncLaunchFiles();
                renderLaunchImages();
            });
            item.appendChild(image);
            item.appendChild(copy);
            item.appendChild(replace);
            item.appendChild(remove);
            previews.appendChild(item);
        });
        previews.hidden = selectedFiles.length === 0;
        syncSubmitState();
        window.requestAnimationFrame(syncLaunchComposerClearance);
        if (window.lucide) {
            window.lucide.createIcons();
        }
    }
    function lockLaunchRequest() {
        launchLocked = true;
        launch.classList.add('is-launch-locked');
        file.disabled = true;
        brief.readOnly = true;
        aiButton.disabled = true;
        form.querySelectorAll('select, details input, input[name="launch_plan"], .product-launch-image button').forEach(function (control) {
            control.disabled = true;
        });
        submit.disabled = true;
        var submitLabel = submit.querySelector('span');
        if (submitLabel) { submitLabel.textContent = '已提交，正在分析'; }
        if (submitReason) { submitReason.textContent = '已提交，商品图和创作要求保持可见且只读'; }
        window.requestAnimationFrame(syncLaunchComposerClearance);
    }
    function unlockLaunchRequest() {
        launchLocked = false;
        launch.classList.remove('is-launch-locked');
        file.disabled = false;
        brief.readOnly = false;
        aiButton.disabled = false;
        form.querySelectorAll('select, details input, input[name="launch_plan"], .product-launch-image button').forEach(function (control) {
            control.disabled = false;
        });
        var submitLabel = submit.querySelector('span');
        if (submitLabel) { submitLabel.textContent = '分析商品并继续'; }
        if (retryButton) { retryButton.hidden = true; retryButton.removeAttribute('data-retry-url'); }
        syncSubmitState();
        window.requestAnimationFrame(syncLaunchComposerClearance);
    }
    function syncSubmitState() {
        var ready = selectedFiles.length > 0;
        submit.disabled = !ready;
        submit.setAttribute('aria-disabled', ready ? 'false' : 'true');
        if (submitReason) {
            submitReason.textContent = ready
                ? '已添加 ' + selectedFiles.length + ' 张商品图'
                : '请先添加至少一张商品图';
        }
    }
    function addLaunchFiles(incoming) {
        if (!incoming.length) {
            return;
        }
        var allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (incoming.some(function (selected) { return allowedTypes.indexOf(selected.type) < 0; })) {
            syncLaunchFiles();
            setAiStatus('商品图仅支持 JPG、PNG、WebP。未添加不支持的文件。', true);
            return;
        }
        if (incoming.some(function (selected) { return selected.size > 24 * 1024 * 1024; })) {
            syncLaunchFiles();
            setAiStatus('单张商品图不能超过 24MB，请压缩后重新添加。', true);
            return;
        }
        if (replaceIndex >= 0 && selectedFiles[replaceIndex] && incoming[0]) {
            selectedFiles[replaceIndex] = incoming[0];
            replaceIndex = -1;
            syncLaunchFiles();
            renderLaunchImages();
            return;
        }
        replaceIndex = -1;
        var previousCount = selectedFiles.length;
        incoming.forEach(function (selected) {
            var signature = [selected.name, selected.size, selected.lastModified].join(':');
            var exists = selectedFiles.some(function (current) {
                return [current.name, current.size, current.lastModified].join(':') === signature;
            });
            if (!exists && selectedFiles.length < 6) {
                selectedFiles.push(selected);
            }
        });
        if (previousCount + incoming.length > 6) {
            setAiStatus('单次最多保留 6 张同一商品的参考图。', true);
        }
        syncLaunchFiles();
        renderLaunchImages();
    }
    file.addEventListener('change', function () {
        addLaunchFiles(Array.prototype.slice.call(file.files || []));
    });
    if (dropzone) {
        ['dragenter', 'dragover'].forEach(function (eventName) {
            dropzone.addEventListener(eventName, function (event) {
                event.preventDefault();
                dropzone.classList.add('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function (eventName) {
            dropzone.addEventListener(eventName, function () {
                dropzone.classList.remove('is-dragover');
            });
        });
        dropzone.addEventListener('drop', function (event) {
            event.preventDefault();
            addLaunchFiles(Array.prototype.slice.call((event.dataTransfer && event.dataTransfer.files) || []));
        });
    }
    launch.addEventListener('paste', function (event) {
        var pasted = Array.prototype.slice.call((event.clipboardData && event.clipboardData.files) || [])
            .filter(function (candidate) { return String(candidate.type || '').indexOf('image/') === 0; });
        if (!pasted.length) {
            return;
        }
        event.preventDefault();
        addLaunchFiles(pasted);
        setAiStatus('已从剪贴板添加商品图片。', false);
    });
    function resizeBrief() {
        brief.style.height = '42px';
        var height = Math.max(42, Math.min(168, brief.scrollHeight));
        brief.style.height = height + 'px';
        brief.style.overflowY = brief.scrollHeight > 168 ? 'auto' : 'hidden';
        window.requestAnimationFrame(syncLaunchComposerClearance);
    }
    brief.addEventListener('input', resizeBrief);
    function restoreLandingDraft() {
        var draft = null;
        try {
            draft = JSON.parse(window.sessionStorage.getItem(landingDraftKey) || 'null');
        } catch (error) {
            draft = null;
        }
        if (!draft || draft.schemaVersion !== 1 || draft.source !== 'landing_product_launch') {
            return;
        }
        brief.value = String(draft.creativeBrief || '').slice(0, 1200);
        var platformSelect = form.querySelector('select[name="platform"]');
        var languageSelect = form.querySelector('select[name="language"]');
        if (platformSelect && Array.prototype.some.call(platformSelect.options, function (option) { return option.value === draft.platform; })) {
            platformSelect.value = draft.platform;
        }
        if (languageSelect && Array.prototype.some.call(languageSelect.options, function (option) { return option.value === draft.language; })) {
            languageSelect.value = draft.language;
        }
        var requestedPlan = ['smart', 'quick', 'bundle', 'detail'].indexOf(draft.launchPlan) >= 0 ? draft.launchPlan : 'smart';
        var planInput = form.querySelector('input[name="launch_plan"][value="' + requestedPlan + '"]');
        if (planInput) {
            planInput.checked = true;
        }
        setAiStatus('已恢复首页选择的上新方案。请重新选择商品图片后继续。', false);
    }
    restoreLandingDraft();
    resizeBrief();
    function updatePreferenceSummary() {
        if (!preferenceSummary) {
            return;
        }
        var platform = form.querySelector('select[name="platform"]');
        var language = form.querySelector('select[name="language"]');
        var platformLabel = platform && platform.options[platform.selectedIndex] ? platform.options[platform.selectedIndex].text : '';
        var languageLabel = language && language.options[language.selectedIndex] ? language.options[language.selectedIndex].text : '';
        preferenceSummary.textContent = [platformLabel, languageLabel].filter(Boolean).join(' · ');
    }
    form.querySelectorAll('select[name="platform"], select[name="language"]').forEach(function (select) {
        select.addEventListener('change', updatePreferenceSummary);
    });
    updatePreferenceSummary();
    function updatePlanSummary() {
        if (!planSummary) { return; }
        var selected = form.querySelector('input[name="launch_plan"]:checked');
        var label = selected ? selected.closest('label') : null;
        var title = label ? label.querySelector('strong') : null;
        planSummary.textContent = title ? title.textContent + '方案' : '智能推荐方案';
    }
    form.querySelectorAll('input[name="launch_plan"]').forEach(function (input) {
        input.addEventListener('change', updatePlanSummary);
    });
    updatePlanSummary();
    function setAiStatus(message, error) {
        if (!aiStatus) {
            return;
        }
        aiStatus.textContent = message;
        aiStatus.classList.toggle('error', Boolean(error));
        aiStatus.hidden = message === '';
    }
    var launchPollTimer = null;
    function setLaunchProgress(value, message, state) {
        if (!progress || !progressPercent || !progressBar || !progressMessage) {
            return;
        }
        var safeValue = Math.max(0, Math.min(100, Math.round(Number(value) || 0)));
        progress.hidden = false;
        progress.classList.toggle('is-failed', state === 'failed');
        progress.classList.toggle('is-complete', state === 'completed');
        progressPercent.textContent = safeValue + '%';
        progressBar.style.width = safeValue + '%';
        progressMessage.textContent = message || '任务正在后台处理。';
        if (progressTitle) {
            var stageTitle = {
                received: '请求已接收', queued: '等待后台处理', safety: '正在检查商品图',
                recognizing: '正在整理商品报告和详情页方案', saving: '正在保存商品资料',
                completed: '商品资料与方案已创建', confirming: '正在确认提交结果'
            }[state];
            progressTitle.textContent = state === 'failed'
                ? '商品上新未完成'
                : stageTitle || (state === 'completed'
                    ? '商品资料已创建'
                    : '商品分析正在后台处理');
        }
    }
    function resetLaunchSubmit() {
        submit.classList.remove('is-loading');
        form.removeAttribute('aria-busy');
        syncSubmitState();
    }
    function parseLaunchResponse(response) {
        return response.text().then(function (body) {
            var result;
            try {
                result = JSON.parse(body);
            } catch (error) {
                if (response.status === 413) {
                    throw new Error('图片上传总量超过服务器允许大小。本次没有创建任务，也不会扣除点数。请减少图片数量，并确保单张不超过 24MB 后重试。');
                }
                throw new Error('服务器返回异常（HTTP ' + response.status + '）');
            }
            var terminalStatus = result && result.status === 'failed';
            if (!response.ok || !result || (!result.ok && !terminalStatus)) {
                var responseError = new Error(result && result.message ? result.message : '商品上新提交失败');
                responseError.result = result || {};
                responseError.definitive = response.status >= 400 && response.status < 500 && response.status !== 408 && response.status !== 429;
                throw responseError;
            }
            return result;
        });
    }
    function pollLaunchTask(url, networkAttempt) {
        networkAttempt = Math.max(0, Number(networkAttempt || 0));
        window.clearTimeout(launchPollTimer);
        fetch(url, {credentials: 'same-origin', cache: 'no-store'})
            .then(parseLaunchResponse)
            .then(function (result) {
                var state = result.status || (result.queued ? 'queued' : '');
                var progressState = result.progressStage || state;
                setLaunchProgress(result.progressPercent || (state === 'running' ? 42 : 8), result.message, progressState);
                document.dispatchEvent(new CustomEvent('global-task-refresh'));
                if (state === 'completed' && result.redirectUrl) {
                    window.setTimeout(function () { window.location.assign(result.redirectUrl); }, 650);
                    return;
                }
                if (state === 'failed') {
                    form.removeAttribute('data-submitting');
                    if (retryButton && result.recovery && result.recovery.url) {
                        retryButton.hidden = false;
                        retryButton.setAttribute('data-retry-url', result.recovery.url);
                        var retryLabel = retryButton.querySelector('span');
                        if (retryLabel) {
                            retryLabel.textContent = '重新处理';
                        }
                    }
                    return;
                }
                launchPollTimer = window.setTimeout(function () { pollLaunchTask(url, 0); }, 2200);
            })
            .catch(function (error) {
                var retryDelay = Math.min(30000, 1500 * Math.pow(2, Math.min(networkAttempt, 4)));
                if (progressMessage) {
                    progressMessage.textContent = (error.message || '任务状态暂时不可读') + '，正在自动重新连接。';
                }
                document.dispatchEvent(new CustomEvent('global-task-refresh'));
                launchPollTimer = window.setTimeout(function () { pollLaunchTask(url, networkAttempt + 1); }, retryDelay);
            });
    }
    function confirmLaunchSubmission(attempt) {
        var submissionId = submissionInput ? submissionInput.value : '';
        if (!submissionId) {
            unlockLaunchRequest();
            form.removeAttribute('data-submitting');
            return;
        }
        lockLaunchRequest();
        setLaunchProgress(8, '正在按提交标识确认后台是否已经接收，请勿重复提交。', 'confirming');
        fetch('/workspace/vision-tasks/confirm?client_submission_id=' + encodeURIComponent(submissionId), {
            credentials: 'same-origin', cache: 'no-store'
        }).then(parseLaunchResponse).then(function (result) {
            if (!result.statusUrl) { throw new Error('任务已确认，但缺少进度地址。'); }
            pollLaunchTask(result.statusUrl);
        }).catch(function () {
            if (attempt < 4) {
                launchPollTimer = window.setTimeout(function () { confirmLaunchSubmission(attempt + 1); }, 1200 * attempt);
                return;
            }
            form.removeAttribute('data-submitting');
            unlockLaunchRequest();
            setLaunchProgress(100, '未确认服务器已接收，本次输入和参考图已保留，请重新提交。', 'failed');
        });
    }
    if (retryButton) {
        retryButton.addEventListener('click', function () {
            var url = retryButton.getAttribute('data-retry-url');
            var token = form.querySelector('input[name="_token"]');
            if (!url || !token) { return; }
            retryButton.disabled = true;
            var body = new FormData();
            body.append('_token', token.value);
            fetch(url, {method: 'POST', body: body, credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
                .then(parseLaunchResponse)
                .then(function (result) {
                    retryButton.hidden = true;
                    retryButton.disabled = false;
                    setLaunchProgress(result.progressPercent || 15, result.message, 'queued');
                    pollLaunchTask(result.statusUrl || ('/workspace/vision-tasks/' + result.taskId));
                })
                .catch(function (error) {
                    retryButton.disabled = false;
                    setLaunchProgress(100, error.message || '任务重新排队失败。', 'failed');
                });
        });
    }
    form.addEventListener('submit', function (event) {
        if (!form.hasAttribute('data-async-product-launch')) {
            return;
        }
        event.preventDefault();
        if (!selectedFiles.length) {
            syncSubmitState();
            file.focus();
            return;
        }
        if (form.getAttribute('data-submitting') === '1') {
            return;
        }
        form.setAttribute('data-submitting', '1');
        form.setAttribute('aria-busy', 'true');
        submit.disabled = true;
        submit.classList.add('is-loading');
        setLaunchProgress(4, '正在上传商品图，上传完成后即可由后台接管。', 'queued');
        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
        })
            .then(parseLaunchResponse)
            .then(function (result) {
                lockLaunchRequest();
                try { window.sessionStorage.removeItem(landingDraftKey); } catch (error) {}
                setLaunchProgress(result.progressPercent || 8, result.message, result.status || 'queued');
                document.dispatchEvent(new CustomEvent('global-task-refresh'));
                if (!result.statusUrl) {
                    throw new Error('任务已提交，但没有返回可追踪的进度地址。');
                }
                pollLaunchTask(result.statusUrl);
            })
            .catch(function (error) {
                if (error && error.definitive) {
                    form.removeAttribute('data-submitting');
                    unlockLaunchRequest();
                    setLaunchProgress(100, error.message || '商品上新提交失败，请修正后重试。', 'failed');
                    return;
                }
                confirmLaunchSubmission(1);
            });
    });
    function parseAiResponse(response) {
        return response.text().then(function (body) {
            var result;
            try {
                result = JSON.parse(body);
            } catch (error) {
                throw new Error('服务器返回异常（HTTP ' + response.status + '）');
            }
            if (!response.ok || !result || !result.ok) {
                throw new Error(result && result.message ? result.message : 'AI 帮写失败');
            }
            return result;
        });
    }
    function optimizeLaunchBrief(token, outputPreset, retry) {
        var payload = new FormData();
        payload.append('_token', token);
        payload.append('idea', brief.value.trim());
        payload.append('output_preset', outputPreset);
        payload.append('has_reference', file.files && file.files.length ? '1' : '0');
        return fetch(form.getAttribute('data-prompt-endpoint'), {method: 'POST', body: payload, credentials: 'same-origin'})
            .then(function (response) {
                if (response.status === 419 && retry) {
                    return fetch(form.getAttribute('data-csrf-endpoint'), {credentials: 'same-origin', cache: 'no-store'})
                        .then(parseAiResponse)
                        .then(function (result) {
                            var nextToken = result.token;
                            form.querySelector('input[name="_token"]').value = nextToken;
                            return optimizeLaunchBrief(nextToken, outputPreset, false);
                        });
                }
                return parseAiResponse(response);
            });
    }
    aiButton.addEventListener('click', function () {
        if (brief.value.trim().length < 4) {
            startGuidedFlow();
            return;
        }
        var label = aiButton.querySelector('span');
        var originalLabel = label.textContent;
        aiButton.disabled = true;
        aiButton.classList.add('is-loading');
        label.textContent = '正在帮你写';
        setAiStatus('AI 正在整理商品主体、卖点、场景与画面方向...', false);
        optimizeLaunchBrief(form.querySelector('input[name="_token"]').value, 'square_1_1', true)
            .then(function (result) {
                brief.value = result.prompt || brief.value;
                resizeBrief();
                brief.focus();
                setAiStatus(result.message || 'AI 草稿已回填，可继续修改后再提交商品创作。', false);
            })
            .catch(function (error) {
                setAiStatus(error.message || 'AI 帮写失败，请稍后重试。', true);
            })
            .finally(function () {
                aiButton.disabled = false;
                aiButton.classList.remove('is-loading');
                label.textContent = originalLabel;
            });
    });

    var advisor = document.querySelector('[data-launch-advisor]');
    if (!advisor) {
        return;
    }
    var chat = advisor.querySelector('[data-launch-advisor-chat]');
    var options = advisor.querySelector('[data-launch-advisor-options]');
    var custom = advisor.querySelector('[data-launch-advisor-custom]');
    var customInput = custom ? custom.querySelector('input') : null;
    var confirm = advisor.querySelector('[data-launch-advisor-confirm]');
    var close = advisor.querySelector('[data-close-launch-advisor]');
    var skip = advisor.querySelector('[data-launch-advisor-skip]');
    var audience = form.querySelector('input[name="target_audience"]');
    var visual = form.querySelector('input[name="visual_preference"]');
    var highlight = form.querySelector('input[name="highlight_features"]');
    var answers = [];
    var step = 0;
    var questions = [
        {label: '目标用户：', field: audience, question: '这次主要想卖给哪类人？', choices: ['学生与年轻用户', '职场通勤人群', '家庭用户', '品质消费人群']},
        {label: '画面感觉：', field: visual, question: '希望画面和文案是什么感觉？', choices: ['简洁专业', '生活真实', '年轻有活力', '高级有质感']},
        {label: '重点突出：', field: highlight, question: '这次最想优先突出什么？', choices: ['外观设计', '材质与工艺', '使用便利', '性价比']}
    ];
    function addAdvisorMessage(role, value) {
        var item = document.createElement('div');
        item.className = 'advisor-message ' + role;
        item.textContent = value;
        chat.appendChild(item);
        chat.scrollTop = chat.scrollHeight;
    }
    function completeGuide(autoPlan) {
        if (autoPlan) {
            brief.value = '请综合全部商品参考图识别同一商品主体，并按平台上新逻辑自动规划真实、清晰、有转化重点的商品素材。';
        } else {
            brief.value = '请综合全部商品参考图完成本次商品上新。' + answers.join('；');
        }
        resizeBrief();
        addAdvisorMessage('assistant', '本次创作方向已补齐。AI 会自动建立商品资料，并在后续创作中持续复用。');
        window.setTimeout(function () { advisor.close(); brief.focus(); }, 500);
    }
    function askGuide() {
        options.innerHTML = '';
        custom.hidden = true;
        confirm.hidden = true;
        if (step >= questions.length) { completeGuide(false); return; }
        var question = questions[step];
        addAdvisorMessage('assistant', question.question);
        question.choices.concat(['自己填写']).forEach(function (value) {
            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = value;
            button.addEventListener('click', function () {
                if (value === '自己填写') {
                    custom.hidden = false;
                    confirm.hidden = false;
                    customInput.value = '';
                    customInput.focus();
                    return;
                }
                chooseGuide(value);
            });
            options.appendChild(button);
        });
    }
    function chooseGuide(value) {
        var question = questions[step];
        if (question.field) { question.field.value = value; }
        answers.push(question.label + value);
        addAdvisorMessage('user', value);
        step++;
        askGuide();
    }
    function startGuidedFlow() {
        if (!file.files || !file.files.length) {
            file.focus();
            setAiStatus('请先添加至少一张商品图片，AI 才能结合图片补充上新方向。', true);
            return;
        }
        answers = [];
        step = 0;
        chat.innerHTML = '';
        addAdvisorMessage('assistant', file.files.length + ' 张商品参考图已收到。我会先用三个选择了解这一次怎么卖，不需要你先写商品描述。');
        askGuide();
        if (typeof advisor.showModal === 'function') { advisor.showModal(); }
    }
    if (close) { close.addEventListener('click', function () { advisor.close(); }); }
    if (skip) { skip.addEventListener('click', function () { completeGuide(true); }); }
    if (confirm) { confirm.addEventListener('click', function () { if (customInput && customInput.value.trim()) { chooseGuide(customInput.value.trim()); } }); }
    if (customInput) { customInput.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); confirm.click(); } }); }
    syncSubmitState();
}

function setupRatioPickers() {
    document.querySelectorAll('[data-ratio-picker]').forEach(function (picker) {
        var select = picker.querySelector('select');
        var choices = picker.querySelectorAll('[data-ratio-choice]');
        var current = picker.querySelector('[data-ratio-current]');
        var dropdown = picker.tagName === 'DETAILS' ? picker : picker.querySelector('details');
        if (!select || !choices.length) { return; }
        function choose(value, notify) {
            select.value = value;
            choices.forEach(function (choice) {
                var selected = choice.getAttribute('data-ratio-value') === value;
                choice.classList.toggle('selected', selected);
                choice.setAttribute('aria-pressed', selected ? 'true' : 'false');
                if (selected && current) { current.textContent = choice.getAttribute('data-ratio-label') || value; }
            });
            if (notify) { select.dispatchEvent(new Event('change', {bubbles: true})); }
        }
        choices.forEach(function (choice) { choice.addEventListener('click', function () {
            choose(choice.getAttribute('data-ratio-value') || '', true);
            if (dropdown) { dropdown.open = false; }
        }); });
        select.addEventListener('change', function () { choose(select.value, false); });
        choose(select.value, false);
    });
}

function setupSkuImagePreview() {
    var input = document.querySelector('[data-sku-analyzer] #source_image');
    var preview = document.querySelector('[data-sku-upload-preview]');
    var empty = document.querySelector('[data-sku-upload-empty]');
    if (!input || !preview || !empty) { return; }
    input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (!file) { return; }
        preview.src = safeImageUrl(URL.createObjectURL(file));
        preview.hidden = false;
        empty.hidden = true;
    });
}

function setupSkuAdvisor() {
    var dialog = document.querySelector('[data-sku-advisor]');
    var form = document.querySelector('[data-sku-analyzer]');
    if (!dialog || !form) { return; }
    var chat = dialog.querySelector('[data-advisor-chat]');
    var options = dialog.querySelector('[data-advisor-options]');
    var custom = dialog.querySelector('[data-advisor-custom]');
    var customInput = custom.querySelector('input');
    var confirm = dialog.querySelector('[data-advisor-confirm]');
    var brief = form.querySelector('#creative_brief');
    var answers = [];
    var step = 0;
    var questions = [
        {question: '这件商品主要想卖给哪类人？', choices: ['学生与年轻用户', '职场通勤人群', '家庭用户', '品质消费人群']},
        {question: '你希望画面和文案是什么感觉？', choices: ['简洁专业', '生活真实', '年轻有活力', '高级有质感']},
        {question: '这次最想优先突出什么？', choices: ['外观设计', '材质与工艺', '使用便利', '性价比']},
    ];

    function addMessage(role, value) {
        var item = document.createElement('div');
        item.className = 'advisor-message ' + role;
        item.textContent = value;
        chat.appendChild(item);
        chat.scrollTop = chat.scrollHeight;
    }
    function finish() {
        var addition = answers.join('；');
        if (addition) {
            brief.value = [brief.value.trim(), addition].filter(Boolean).join('\n');
        }
        options.innerHTML = '';
        custom.hidden = true;
        confirm.hidden = true;
        addMessage('assistant', '已把你的选择补充到“商品定位与创作想法”，保存前还可以继续修改。');
        window.setTimeout(function () { dialog.close(); brief.focus(); }, 650);
    }
    function ask() {
        options.innerHTML = '';
        custom.hidden = true;
        confirm.hidden = true;
        if (step >= questions.length) { finish(); return; }
        addMessage('assistant', questions[step].question);
        questions[step].choices.concat(['自己填写']).forEach(function (value) {
            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = value;
            button.addEventListener('click', function () {
                if (value === '自己填写') {
                    custom.hidden = false;
                    confirm.hidden = false;
                    customInput.value = '';
                    customInput.focus();
                    return;
                }
                choose(value);
            });
            options.appendChild(button);
        });
    }
    function choose(value) {
        var labels = ['目标人群：', '表达风格：', '主推方向：'];
        answers.push(labels[step] + value);
        addMessage('user', value);
        step++;
        ask();
    }
    function openAdvisor(fromAnalysis) {
        answers = [];
        step = 0;
        chat.innerHTML = '';
        addMessage('assistant', fromAnalysis ? '商品初稿已经生成。我再问三个简单问题，让后续素材更贴合你的目标。' : '我会用三个问题帮你补充创作方向，不影响现有商品资料。');
        ask();
        if (typeof dialog.showModal === 'function') { dialog.showModal(); }
    }
    document.querySelectorAll('[data-open-sku-advisor]').forEach(function (button) { button.addEventListener('click', function () { openAdvisor(false); }); });
    dialog.querySelector('[data-close-sku-advisor]').addEventListener('click', function () { dialog.close(); });
    dialog.querySelector('[data-advisor-skip]').addEventListener('click', function () { dialog.close(); });
    confirm.addEventListener('click', function () { if (customInput.value.trim()) { choose(customInput.value.trim()); } });
    customInput.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); confirm.click(); } });
    form.addEventListener('sku:analyzed', function () { openAdvisor(true); });
}

function setupSkuMarketResearch() {
    var form = document.querySelector('[data-sku-report-form]');
    if (!form) { return; }
    var button = form.querySelector('[data-preview-market-research]');
    var list = form.querySelector('[data-market-source-list]');
    var status = form.querySelector('[data-market-preview-status]');
    var prepared = form.querySelector('[data-market-prepared]');
    var useToggle = form.querySelector('[data-use-market-research]');
    if (!button || !list || !status || !prepared || !useToggle) { return; }

    function updateCount() {
        var count = list.querySelectorAll('input[type="checkbox"]:checked').length;
        var badge = form.querySelector('.market-source-picker-heading .badge');
        if (badge) { badge.textContent = count + ' 条已选'; }
        useToggle.checked = count > 0;
    }
    function renderSources(sources) {
        list.innerHTML = '';
        sources.forEach(function (source) {
            var label = document.createElement('label');
            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox'; checkbox.name = 'selected_market_sources[]'; checkbox.value = source.id || ''; checkbox.checked = source.selected !== false;
            var copy = document.createElement('span');
            var title = document.createElement('strong'); title.textContent = source.title || '公开来源';
            var summary = document.createElement('small'); summary.textContent = (source.content || '').slice(0, 180);
            copy.appendChild(title); copy.appendChild(summary); label.appendChild(checkbox); label.appendChild(copy);
            if (source.url) { var link = document.createElement('a'); link.href = source.url; link.target = '_blank'; link.rel = 'noopener noreferrer'; link.textContent = '核对'; label.appendChild(link); }
            checkbox.addEventListener('change', updateCount); list.appendChild(label);
        });
        updateCount();
    }
    list.querySelectorAll('input[type="checkbox"]').forEach(function (input) { input.addEventListener('change', updateCount); });
    button.addEventListener('click', function () {
        var payload = new FormData();
        payload.append('_token', form.querySelector('input[name="_token"]').value);
        payload.append('market_query', form.querySelector('input[name="market_query"]').value || '');
        payload.append('platform', form.querySelector('select[name="platform"]').value);
        button.disabled = true; button.classList.add('is-loading'); status.textContent = '正在检索公开资料，请稍候...'; status.className = 'market-preview-status';
        fetch(form.getAttribute('data-market-preview-url'), {method: 'POST', body: payload, credentials: 'same-origin'})
            .then(function (response) { return response.json().then(function (data) { if (!response.ok || !data.ok) { throw new Error(data.message || '联网检索失败'); } return data; }); })
            .then(function (data) { renderSources(data.research.sources || []); prepared.value = '1'; status.textContent = data.message + ' 已找到 ' + (data.research.sources || []).length + ' 条候选来源，请取消不适合的来源。'; status.className = 'market-preview-status success'; })
            .catch(function (error) { status.textContent = error.message || '联网检索失败'; status.className = 'market-preview-status error'; })
            .finally(function () { button.disabled = false; button.classList.remove('is-loading'); });
    });
    form.addEventListener('submit', function (event) {
        if (useToggle.checked && (prepared.value !== '1' || list.querySelectorAll('input:checked').length === 0)) {
            event.preventDefault();
            status.textContent = '请先搜索并至少选择一条来源，或关闭“用于本次报告”。';
            status.className = 'market-preview-status error';
        }
    });
    updateCount();
}

function setupModeForm() {
    var form = document.querySelector('[data-mode-form]');
    if (!form) {
        return;
    }
    var choices = form.querySelectorAll('input[name="usage_mode"]');
    var defaultPanel = form.querySelector('[data-default-panel]');
    var customPanel = form.querySelector('[data-custom-panel]');
    var customRequired = form.querySelectorAll('[data-custom-required]');
    if (!choices.length || !defaultPanel || !customPanel) {
        return;
    }

    function update() {
        var selected = form.querySelector('input[name="usage_mode"]:checked');
        var custom = selected && selected.value === 'custom';
        defaultPanel.hidden = custom;
        customPanel.hidden = !custom;
        customRequired.forEach(function (input) {
            input.required = custom;
            input.disabled = !custom;
        });
    }
    choices.forEach(function (choice) {
        choice.addEventListener('change', update);
    });
    update();
}

function setupStorageForm() {
    var form = document.querySelector('[data-storage-form]');
    if (!form) {
        return;
    }
    var choices = form.querySelectorAll('input[name="driver"]');
    var remotePanel = form.querySelector('[data-remote-storage]');
    var remoteRequired = form.querySelectorAll('[data-remote-required]');
    var note = form.querySelector('[data-storage-note]');
    var region = form.querySelector('#region');
    var endpoint = form.querySelector('#endpoint');
    var notes = {
        cos: 'COS 使用 Bucket-APPID 名称，例如 assets-1250000000；地域示例 ap-guangzhou。',
        oss: 'OSS 使用标准 Bucket 名称；地域示例 cn-hangzhou。',
        r2: 'R2 地域填写 auto，服务端点填写 Cloudflare 控制台提供的 S3 API 地址。',
    };

    function update() {
        var selected = form.querySelector('input[name="driver"]:checked');
        var driver = selected ? selected.value : 'local';
        var remote = driver !== 'local';
        remotePanel.hidden = !remote;
        remoteRequired.forEach(function (input) {
            input.required = remote;
            input.disabled = !remote;
        });
        note.textContent = notes[driver] || '';
        if (driver === 'r2' && region.value === '') {
            region.value = 'auto';
        }
        endpoint.required = driver === 'r2';
        endpoint.disabled = !remote;
    }
    choices.forEach(function (choice) {
        choice.addEventListener('change', update);
    });
    update();
}

function setupGenerationReference() {
    var form = document.querySelector('[data-generation-form]');
    if (!form) {
        return;
    }
    var select = form.querySelector('#sku_id');
    var panel = form.querySelector('[data-generation-reference]');
    var image = form.querySelector('[data-reference-image]');
    var title = form.querySelector('[data-reference-title]');
    var placeholder = form.querySelector('[data-reference-placeholder]');
    var editEnabled = form.getAttribute('data-image-edit-enabled') === '1';
    var modelSelector = form.querySelector('[data-creative-model-selector]');

    function update() {
        var option = select.options[select.selectedIndex];
        var imageUrl = option ? option.getAttribute('data-image') : '';
        var backupUrl = option ? option.getAttribute('data-backup') : '';
        var preferredModel = option ? option.getAttribute('data-preferred-model') || '' : '';
        if (modelSelector) {
            var preferredOption = Array.prototype.find.call(modelSelector.options, function (candidate) {
                return candidate.getAttribute('data-model-id') === preferredModel;
            });
            var defaultModelId = modelSelector.getAttribute('data-default-model-id')
                || (modelSelector.options[0] ? modelSelector.options[0].value : '');
            modelSelector.value = preferredOption ? preferredOption.value : defaultModelId;
            modelSelector.dispatchEvent(new Event('change'));
        }
        if (!imageUrl) {
            panel.hidden = true;
            if (placeholder) {
                placeholder.hidden = false;
            }
            image.removeAttribute('src');
            return;
        }
        image.src = safeImageUrl(imageUrl);
        image.setAttribute('data-fallback', backupUrl || '');
        image.removeAttribute('data-fallback-used');
        title.textContent = editEnabled ? '将使用商品参考图生成' : '当前模型将使用纯文字生成';
        panel.classList.toggle('warning', !editEnabled);
        if (placeholder) {
            placeholder.hidden = true;
        }
        panel.hidden = false;
    }
    select.addEventListener('change', update);
    update();
}

function setupSkuReportQuickForm() {
    var generationForm = document.querySelector('[data-generation-form]');
    var panel = document.querySelector('[data-sku-generation-report]');
    var reportForm = document.querySelector('[data-sku-report-quick-form]');
    if (!generationForm || !panel || !reportForm) {
        return;
    }
    var skuSelect = generationForm.querySelector('#sku_id');
    var platform = generationForm.querySelector('#generation-platform');
    var language = generationForm.querySelector('#generation-language');
    var link = panel.querySelector('[data-sku-report-link]');
    var title = panel.querySelector('[data-sku-report-title]');
    var description = panel.querySelector('[data-sku-report-description]');
    var actionLabel = panel.querySelector('[data-sku-report-action]');
    var submit = reportForm.querySelector('button[type="submit"]');

    function update() {
        var option = skuSelect.options[skuSelect.selectedIndex];
        var skuId = option ? option.value : '';
        if (!skuId) {
            panel.hidden = true;
            return;
        }
        var reportId = option.getAttribute('data-report-id') || '';
        var updatedAt = option.getAttribute('data-report-updated') || '';
        var hasImage = Boolean(option.getAttribute('data-image'));
        reportForm.action = safeWorkspaceUrl((reportForm.getAttribute('data-action-template') || '').replace('__SKU__', encodeURIComponent(skuId)), '');
        reportForm.querySelector('input[name="platform"]').value = platform.value;
        reportForm.querySelector('input[name="language"]').value = language.value;
        title.textContent = reportId ? '商品资料已关联' : '该商品尚无商品资料';
        description.textContent = reportId ? '最近更新 ' + updatedAt + '，普通素材会自动读取报告。' : '先生成报告，可提升卖点、场景和商品细节的一致性。';
        actionLabel.textContent = reportId ? '更新报告' : '生成报告';
        link.hidden = !reportId;
        link.href = reportId ? '/workspace/detail-pages/' + encodeURIComponent(reportId) : '#';
        submit.disabled = !hasImage;
        submit.title = hasImage ? '' : '请先在 SKU 详情中上传商品参考图';
        panel.hidden = false;
    }
    skuSelect.addEventListener('change', update);
    platform.addEventListener('change', update);
    language.addEventListener('change', update);
    update();
}

function setupGenerationPricing() {
    var form = document.querySelector('[data-generation-form]');
    var output = document.querySelector('[data-estimated-credits]');
    var summary = document.querySelector('[data-selection-summary]');
    if (!form || !output) {
        return;
    }
    var unitPrice = parseInt(form.getAttribute('data-generation-price') || '0', 10);
    var modelSelector = form.querySelector('[data-creative-model-selector]');
    var options = form.querySelectorAll('[data-asset-option]');
    var selectAll = form.querySelector('[data-asset-select-all]');
    var maxImages = parseInt(form.getAttribute('data-max-images') || '30', 10);
    var submitters = form.querySelectorAll('button[type="submit"]');
    var bundleDetailCount = form.querySelector('[data-bundle-detail-count]');
    var customMaterialRequests = form.querySelector('[data-custom-material-requests]');
    var previewSubmit = form.querySelector('[data-launch-action-value="preview"]');
    var fullSubmit = form.querySelector('[data-launch-action-value="full"]');

    function updateActionLabel(button, title, detail) {
        if (!button) { return; }
        var titleOutput = button.querySelector('strong');
        var detailOutput = button.querySelector('small');
        if (titleOutput) { titleOutput.textContent = title; }
        if (detailOutput) { detailOutput.textContent = detail; }
    }

    function update() {
        if (modelSelector) {
            var modelOption = modelSelector.options[modelSelector.selectedIndex];
            unitPrice = Math.max(0, parseInt(modelOption ? modelOption.getAttribute('data-unit-price') || '0' : '0', 10));
        }
        var typeCount = 0;
        var imageCount = 0;
        options.forEach(function (option) {
            var choice = option.querySelector('[data-asset-checkbox]');
            var quantity = option.querySelector('[data-asset-quantity]');
            var control = option.querySelector('[data-asset-quantity-control]');
            var selected = choice.checked;
            option.classList.toggle('selected', selected);
            quantity.disabled = !selected;
            var quantityPicker = quantity.closest('[data-quantity-picker]');
            if (quantityPicker) {
                var quickQuantity = quantityPicker.querySelector('[data-quantity-quick]');
                var customQuantity = quantityPicker.querySelector('[data-quantity-custom]');
                if (quickQuantity) {
                    quickQuantity.disabled = !selected;
                }
                if (customQuantity) {
                    customQuantity.disabled = !selected || customQuantity.hidden;
                }
            }
            control.hidden = !selected;
            if (selected) {
                typeCount++;
                imageCount += Math.max(1, parseInt(quantity.value || '1', 10));
            }
        });
        var mainImageCount = imageCount;
        var customMaterialCount = customMaterialRequests
            ? customMaterialRequests.value.split(/\r\n|\r|\n/).map(function (value) { return value.trim(); }).filter(Boolean).slice(0, 4).length
            : 0;
        imageCount += customMaterialCount;
        var detailImageCount = bundleDetailCount ? Math.max(1, parseInt(bundleDetailCount.value || '1', 10)) : 0;
        imageCount += detailImageCount;
        output.textContent = String(imageCount * unitPrice);
        updateActionLabel(previewSubmit, '先预览 1 张', '推荐先检查 · ' + unitPrice + ' 点');
        updateActionLabel(
            fullSubmit,
            detailImageCount > 0 ? '生成整套 ' + imageCount + ' 张' : '生成全部 ' + imageCount + ' 张',
            '预计 ' + (imageCount * unitPrice) + ' 点'
        );
        if (selectAll) {
            selectAll.checked = typeCount === options.length;
            selectAll.indeterminate = typeCount > 0 && typeCount < options.length;
        }
        if (summary) {
            summary.textContent = imageCount > maxImages
                ? '当前共 ' + imageCount + ' 张，单个任务最多生成 ' + maxImages + ' 张，请减少数量。'
                : detailImageCount > 0 && mainImageCount > 0
                ? '商品主图 ' + mainImageCount + ' 张' + (customMaterialCount ? ' + 补充素材 ' + customMaterialCount + ' 张' : '') + ' + 详情页 ' + detailImageCount + ' 张，整套共 ' + imageCount + ' 张。'
                : imageCount > 0
                ? '已选择 ' + typeCount + ' 种素材' + (customMaterialCount ? '，另加 ' + customMaterialCount + ' 张补充素材' : '') + '，共生成 ' + imageCount + ' 张；系统将逐张处理并展示进度。'
                : '请至少选择一种素材。';
            summary.classList.toggle('warning-text', imageCount > maxImages);
        }
        var bundleProjectReady = !form.hasAttribute('data-listing-bundle-form') || form.getAttribute('data-bundle-project-ready') === '1';
        submitters.forEach(function (submit) {
            submit.disabled = imageCount === 0 || imageCount > maxImages || !bundleProjectReady
                || form.getAttribute('data-creative-ready') === '0' || submit.hasAttribute('data-model-disabled');
        });
    }
    options.forEach(function (option) {
        option.querySelector('[data-asset-checkbox]').addEventListener('change', update);
        option.querySelector('[data-asset-quantity]').addEventListener('change', update);
    });
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            var checked = selectAll.checked;
            options.forEach(function (option) {
                option.querySelector('[data-asset-checkbox]').checked = checked;
            });
            update();
        });
    }
    if (bundleDetailCount) {
        bundleDetailCount.addEventListener('change', update);
        form.addEventListener('bundle-project-change', update);
    }
    if (customMaterialRequests) {
        customMaterialRequests.addEventListener('input', update);
    }
    if (modelSelector) {
        modelSelector.addEventListener('change', update);
    }
    form.addEventListener('creative-readiness-change', update);
    update();
}

function setupVideoGenerationPricing() {
    var form = document.querySelector('[data-video-generation-form]');
    if (!form) {
        return;
    }
    var duration = form.querySelector('[data-video-duration]');
    var model = form.querySelector('[data-video-model-selector]');
    var output = form.querySelector('[data-video-estimated-credits]');
    var basePrice = Math.max(0, parseInt(form.getAttribute('data-video-unit-price') || '0', 10));

    function update() {
        var seconds = Math.max(5, parseInt(duration ? duration.value || '5' : '5', 10));
        var option = model && model.options ? model.options[model.selectedIndex] : null;
        var unitPrice = option
            ? Math.max(0, parseInt(option.getAttribute('data-unit-price') || String(basePrice), 10))
            : basePrice;
        if (output) {
            output.textContent = String(seconds * unitPrice);
        }
    }

    if (duration) {
        duration.addEventListener('change', update);
    }
    if (model) {
        model.addEventListener('change', update);
    }
    update();
}

function setupPodCarousel() {
    var form = document.querySelector('[data-pod-carousel-form]');
    if (!form) {
        return;
    }
    var input = form.querySelector('[data-pod-design-input]');
    var preview = form.querySelector('[data-pod-design-preview]');
    var placeholder = form.querySelector('[data-pod-design-placeholder]');
    var image = form.querySelector('[data-pod-design-image]');
    var filename = form.querySelector('[data-pod-design-file]');
    var count = form.querySelector('[data-pod-carousel-count]');
    var credits = form.querySelector('[data-pod-estimated-credits]');
    var summary = form.querySelector('[data-pod-selection-summary]');
    var roleTitle = form.querySelector('[data-pod-role-title]');
    var roleList = form.querySelector('[data-pod-role-list]');
    var submit = form.querySelector('button[type="submit"]');
    var unitPrice = parseInt(form.getAttribute('data-unit-price') || '0', 10);
    var modelSelector = form.querySelector('[data-creative-model-selector]');
    var maxImages = parseInt(form.getAttribute('data-max-images') || '30', 10);
    var objectUrl = '';
    var roles = {
        4: '模特主视觉 · 平铺展示 · 印花细节 · 穿着场景',
        6: '模特主视觉 · 平铺展示 · 印花细节 · 穿着场景 · 颜色变体 · 版型搭配'
    };

    function updateSummary() {
        if (modelSelector) {
            var modelOption = modelSelector.options[modelSelector.selectedIndex];
            unitPrice = Math.max(0, parseInt(modelOption ? modelOption.getAttribute('data-unit-price') || '0' : '0', 10));
        }
        var imageCount = parseInt(count.value || '4', 10);
        credits.textContent = String(imageCount * unitPrice);
        roleTitle.textContent = imageCount + ' 张' + (imageCount === 4 ? '核心上新包' : '完整上新包');
        roleList.textContent = roles[imageCount] || roles[4];
        summary.textContent = imageCount > maxImages
            ? '当前套餐单次最多生成 ' + maxImages + ' 张，请升级套餐或选择更少图片。'
            : '将建立 1 个 POD 商品档案和 ' + imageCount + ' 个并行图片子任务，全部输出为 4:5 上新图。';
        summary.classList.toggle('warning-text', imageCount > maxImages);
        if (submit && !submit.hasAttribute('data-model-disabled')) {
            submit.disabled = imageCount > maxImages;
        }
    }

    input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
            objectUrl = '';
        }
        if (!file) {
            preview.hidden = true;
            placeholder.hidden = false;
            image.removeAttribute('src');
            return;
        }
        objectUrl = URL.createObjectURL(file);
        image.src = safeImageUrl(objectUrl);
        filename.textContent = file.name;
        preview.hidden = false;
        placeholder.hidden = true;
    });
    count.addEventListener('change', updateSummary);
    if (modelSelector) {
        modelSelector.addEventListener('change', updateSummary);
    }
    updateSummary();
}

function setupListingBundle() {
    var form = document.querySelector('[data-listing-bundle-form]');
    var createForm = document.querySelector('[data-listing-plan-create-form]');
    if (!form || !createForm) {
        return;
    }
    var skuSelect = form.querySelector('#sku_id');
    var projectSelect = form.querySelector('#bundle-detail-project');
    var hint = form.querySelector('[data-bundle-project-hint]');
    var projectOptions = Array.prototype.slice.call(projectSelect.querySelectorAll('option[data-sku-id]'));
    var lastSkuId = skuSelect.value;

    function update() {
        var skuOption = skuSelect.options[skuSelect.selectedIndex];
        var skuId = skuOption ? skuOption.value : '';
        var hasImage = Boolean(skuOption && skuOption.getAttribute('data-image'));
        var matching = [];
        projectOptions.forEach(function (option) {
            var visible = Boolean(skuId) && option.getAttribute('data-sku-id') === skuId;
            option.hidden = !visible;
            option.disabled = !visible;
            if (visible) {
                matching.push(option);
            }
        });
        var selected = projectSelect.options[projectSelect.selectedIndex];
        if (skuId !== lastSkuId || !selected || selected.disabled || !selected.value) {
            projectSelect.value = matching.length > 0 ? matching[0].value : '';
            selected = projectSelect.options[projectSelect.selectedIndex];
        }
        lastSkuId = skuId;
        var hasProject = Boolean(selected && selected.value && !selected.disabled);
        projectSelect.disabled = !skuId || matching.length === 0;
        form.setAttribute('data-bundle-project-ready', hasProject && hasImage ? '1' : '0');
        hint.textContent = !skuId
            ? '先选择商品'
            : !hasImage
            ? '请先在商品资料中上传参考图'
            : matching.length > 0
            ? '该商品有 ' + matching.length + ' 个方案，已自动选择最近更新的一项'
            : '该商品还没有详情页方案，请在下方建立';
        createForm.hidden = !skuId || matching.length > 0;
        createForm.action = skuId ? safeWorkspaceUrl((createForm.getAttribute('data-action-template') || '').replace('__SKU__', encodeURIComponent(skuId)), '') : '';
        createForm.querySelector('button[type="submit"]').disabled = !skuId || !hasImage;
        form.dispatchEvent(new Event('bundle-project-change'));
    }

    skuSelect.addEventListener('change', update);
    projectSelect.addEventListener('change', update);
    update();
}

function setupDetailGeneration() {
    var workspace = document.querySelector('[data-detail-generation-workspace]');
    if (!workspace) {
        return;
    }
    var form = workspace.querySelector('[data-detail-generation-form]');
    var skuSelect = workspace.querySelector('#detail-sku-id');
    var projectSelect = workspace.querySelector('#detail-project-id');
    var createForm = workspace.querySelector('[data-detail-plan-create-form]');
    var projectLink = workspace.querySelector('[data-detail-project-link]');
    var hint = workspace.querySelector('[data-detail-project-hint]');
    var stateTitle = workspace.querySelector('[data-detail-state-title]');
    var stateCopy = workspace.querySelector('[data-detail-state-copy]');
    var submit = form.querySelector('button[type="submit"]');
    var projectOptions = Array.prototype.slice.call(projectSelect.querySelectorAll('option[data-sku-id]'));
    var lastSkuId = skuSelect.value;

    function update() {
        var skuOption = skuSelect.options[skuSelect.selectedIndex];
        var skuId = skuOption ? skuOption.value : '';
        var hasImage = skuOption && Boolean(skuOption.getAttribute('data-image'));
        var reportReady = skuOption && skuOption.getAttribute('data-report-ready') === '1';
        var matching = [];
        projectOptions.forEach(function (option) {
            var visible = Boolean(skuId) && option.getAttribute('data-sku-id') === skuId;
            option.hidden = !visible;
            option.disabled = !visible;
            if (visible) {
                matching.push(option);
            } else if (option.selected) {
                option.selected = false;
            }
        });
        var selectedProject = projectSelect.options[projectSelect.selectedIndex];
        if (skuId !== lastSkuId || !selectedProject || selectedProject.disabled || !selectedProject.value) {
            projectSelect.value = matching.length > 0 ? matching[0].value : '';
            selectedProject = projectSelect.options[0];
        }
        lastSkuId = skuId;
        selectedProject = projectSelect.options[projectSelect.selectedIndex];
        projectSelect.disabled = !skuId || matching.length === 0;
        var hasSelectedProject = Boolean(selectedProject && selectedProject.value);
        form.setAttribute('action', hasSelectedProject ? '/workspace/detail-pages/' + encodeURIComponent(selectedProject.value) + '/generate' : '');
        projectLink.hidden = !hasSelectedProject;
        projectLink.href = hasSelectedProject ? '/workspace/detail-pages/' + encodeURIComponent(selectedProject.value) : '#';
        submit.disabled = !hasSelectedProject || form.getAttribute('data-creative-ready') === '0'
            || submit.hasAttribute('data-model-disabled');
        hint.textContent = !skuId ? '先选择商品' : matching.length > 0 ? '该商品有 ' + matching.length + ' 个方案，已自动带入最近更新的一项' : '该商品还没有详情页方案，可在下方建立首个方案';

        createForm.hidden = !skuId || matching.length > 0;
        createForm.action = skuId ? safeWorkspaceUrl((createForm.getAttribute('data-action-template') || '').replace('__SKU__', encodeURIComponent(skuId)), '') : '';
        var createButton = createForm.querySelector('button[type="submit"]');
        createButton.disabled = !hasImage;
        if (!skuId) {
            stateTitle.textContent = '选择商品开始';
            stateCopy.textContent = '已有方案可直接生成；没有方案时可在这里建立。';
        } else if (matching.length === 0 && !hasImage) {
            stateTitle.textContent = '请先上传商品参考图';
            stateCopy.textContent = '详情页需要参考图来保持商品主体一致。';
        } else if (matching.length === 0) {
            stateTitle.textContent = reportReady ? '商品资料已就绪' : '需要建立商品资料与方案';
            stateCopy.textContent = reportReady ? '建立方案时会复用商品资料，不会重复识别稳定事实。' : '系统会同步整理商品资料，供后续主图和详情页复用。';
        } else if (selectedProject && selectedProject.value) {
            stateTitle.textContent = selectedProject.getAttribute('data-platform') + '详情页方案';
            stateCopy.textContent = selectedProject.getAttribute('data-language') + '，选择数量后即可生成。';
        } else {
            stateTitle.textContent = '选择详情页方案';
            stateCopy.textContent = '同一商品可以保留多个平台和语言版本。';
        }
    }

    skuSelect.addEventListener('change', update);
    projectSelect.addEventListener('change', update);
    update();
}

function setupDetailCountPricing() {
    document.querySelectorAll('[data-detail-count-pricing], [data-detail-generation-workspace]').forEach(function (container) {
        var select = container.querySelector('[data-detail-screen-count]');
        var output = container.querySelector('[data-detail-estimated-credits]');
        var countOutputs = container.querySelectorAll('[data-detail-selected-count]');
        if (!select || !output) {
            return;
        }
        var unitPrice = parseInt(container.getAttribute('data-unit-price') || '0', 10);
        var modelSelector = container.querySelector('[data-creative-model-selector]');
        function update() {
            if (modelSelector) {
                var modelOption = modelSelector.options[modelSelector.selectedIndex];
                unitPrice = Math.max(0, parseInt(modelOption ? modelOption.getAttribute('data-unit-price') || '0' : '0', 10));
            }
            var count = Math.max(1, parseInt(select.value || '1', 10));
            output.textContent = String(count * unitPrice);
            countOutputs.forEach(function (countOutput) {
                countOutput.textContent = String(count);
            });
        }
        select.addEventListener('change', update);
        if (modelSelector) {
            modelSelector.addEventListener('change', update);
        }
        update();
    });
}

function setupDetailOutputSize() {
    document.querySelectorAll('[data-detail-output-controls]').forEach(function (container) {
        var preset = container.querySelector('[data-detail-output-preset]');
        var custom = container.querySelector('[data-detail-output-custom]');
        var hint = container.querySelector('[data-detail-output-hint]');
        if (!preset || !custom) {
            return;
        }
        var inputs = custom.querySelectorAll('input');
        function update() {
            var option = preset.options[preset.selectedIndex];
            var isCustom = preset.value === 'custom';
            custom.hidden = !isCustom;
            inputs.forEach(function (input) {
                input.required = isCustom;
                input.disabled = !isCustom;
            });
            if (hint) {
                var width = option ? option.getAttribute('data-width') : '';
                var height = option ? option.getAttribute('data-height') : '';
                hint.textContent = isCustom ? '请输入 512-2048 像素的宽高' : (option.textContent.split('·')[0].trim() + ' · ' + width + '×' + height);
            }
        }
        preset.addEventListener('change', update);
        update();
    });
}

function setupMarketResearchOptions() {
    document.querySelectorAll('[data-market-research-toggle]').forEach(function (toggle) {
        var container = toggle.closest('.market-research-option');
        var panel = container ? container.querySelector('[data-market-query-panel]') : null;
        var input = panel ? panel.querySelector('input[name="market_query"]') : null;
        if (!panel) {
            return;
        }
        function update() {
            panel.hidden = !toggle.checked;
            if (input) {
                input.required = toggle.checked && input.hasAttribute('data-market-query-required');
            }
        }
        toggle.addEventListener('change', update);
        update();
    });
}

function setupFreeGeneration() {
    var tabs = document.querySelector('[data-generation-mode-tabs]');
    var form = document.querySelector('[data-free-generation-form]');
    if (!form) {
        return;
    }
    var modeButtons = tabs ? tabs.querySelectorAll('[data-generation-mode-button]') : [];
    var modePanels = document.querySelectorAll('[data-generation-mode-panel]');
    var prompt = form.querySelector('#free-prompt');
    var promptCount = form.querySelector('[data-free-prompt-count]');
    var templateId = form.querySelector('[data-free-template-id]');
    var subjectText = form.querySelector('[data-free-subject-text]');
    var userAddition = form.querySelector('[data-free-user-addition]');
    var clientSubmissionId = form.querySelector('[data-free-client-submission-id]');
    var referenceAssetId = form.querySelector('[data-free-reference-asset-id]');
    var submitButton = form.querySelector('.free-studio-submit');
    var templateButtons = document.querySelectorAll('[data-free-template]');
    var templateFilter = document.querySelector('[data-free-template-filter]');
    var outputPreset = form.querySelector('[data-free-output-preset]');
    var outputHint = form.querySelector('[data-free-output-hint]');
    var quantity = form.querySelector('[data-free-quantity]');
    var estimatedCredits = form.querySelector('[data-free-estimated-credits]');
    var referenceInput = form.querySelector('[data-free-reference-input]');
    var referenceList = form.querySelector('[data-free-reference-list]');
    var referenceCount = form.querySelector('[data-free-reference-count]');
    var modelSelector = form.querySelector('[data-creative-model-selector]');
    var modelHint = form.querySelector('[data-creative-model-hint]');
    var selectedModelName = form.querySelector('[data-selected-model-name]');
    var referencePreview = form.querySelector('[data-free-reference-preview]');
    var referenceName = form.querySelector('[data-free-reference-name]');
    var uploadEmpty = form.querySelector('[data-free-upload-empty]');
    var uploadPreview = form.querySelector('[data-free-upload-preview]');
    var removeReference = form.querySelector('[data-free-reference-remove]');
    var dialog = document.querySelector('[data-prompt-helper-dialog]');
    var idea = dialog.querySelector('[data-prompt-helper-idea]');
    var helperOutput = dialog.querySelector('[data-prompt-helper-output]');
    var helperResult = dialog.querySelector('[data-prompt-helper-result]');
    var helperStatus = dialog.querySelector('[data-prompt-helper-status]');
    var generatePrompt = dialog.querySelector('[data-generate-prompt]');
    var usePrompt = dialog.querySelector('[data-use-prompt]');
    var imagePromptDialog = document.querySelector('[data-image-prompt-dialog]');
    var imagePromptStatus = imagePromptDialog ? imagePromptDialog.querySelector('[data-image-prompt-status]') : null;
    var imagePromptResult = imagePromptDialog ? imagePromptDialog.querySelector('[data-image-prompt-result]') : null;
    var imagePromptOutput = imagePromptDialog ? imagePromptDialog.querySelector('[data-image-prompt-output]') : null;
    var generateImagePrompt = imagePromptDialog ? imagePromptDialog.querySelector('[data-generate-image-prompt]') : null;
    var useImagePrompt = imagePromptDialog ? imagePromptDialog.querySelector('[data-use-image-prompt]') : null;
    var landingDraftNotice = document.querySelector('[data-landing-draft-notice]');
    var landingDraftMessage = document.querySelector('[data-landing-draft-message]');
    var selectedReferenceFiles = [];
    var referencePreviewUrls = [];
    var LANDING_DRAFT_KEY = 'asset-factory-landing-free-create-v1';
    var freeStudio = form.closest('.free-studio');
    var submitting = false;

    function hasActiveReference() {
        return selectedReferenceFiles.length > 0 || Boolean(referenceAssetId && referenceAssetId.value);
    }

    function nextSubmissionId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return 'free_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 18);
    }

    function autoGrowPrompt() {
        prompt.style.height = 'auto';
        prompt.style.height = Math.min(145, Math.max(70, prompt.scrollHeight)) + 'px';
        prompt.style.overflowY = prompt.scrollHeight > 145 ? 'auto' : 'hidden';
        window.requestAnimationFrame(syncComposerClearance);
    }

    var webSelectSequence = 0;
    function enhanceWebSelect(select) {
        if (!select || select.getAttribute('data-web-select-ready') === '1') {
            return;
        }
        select.setAttribute('data-web-select-ready', '1');
        select.classList.add('web-select-native');
        var shell = document.createElement('span');
        shell.className = 'web-select';
        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'web-select-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        var label = document.createElement('span');
        var chevron = document.createElement('i');
        chevron.setAttribute('data-lucide', 'chevron-down');
        trigger.appendChild(label);
        trigger.appendChild(chevron);
        var list = document.createElement('span');
        var listId = 'free-web-select-' + (++webSelectSequence);
        list.id = listId;
        list.className = 'web-select-list';
        list.setAttribute('role', 'listbox');
        list.setAttribute('aria-label', select.getAttribute('data-web-select-label') || select.getAttribute('aria-label') || '选择');
        trigger.setAttribute('aria-controls', listId);
        list.hidden = true;
        shell.appendChild(trigger);
        shell.appendChild(list);
        select.insertAdjacentElement('afterend', shell);

        function close(restoreFocus) {
            list.hidden = true;
            list.style.maxHeight = '';
            trigger.setAttribute('aria-expanded', 'false');
            shell.classList.remove('is-open');
            if (restoreFocus) { trigger.focus(); }
        }
        function availableOptions() {
            return Array.prototype.filter.call(select.options, function (option) {
                return !option.disabled && !option.hidden;
            });
        }
        function choices() {
            return Array.prototype.slice.call(list.querySelectorAll('[role="option"]'));
        }
        function focusChoice(position) {
            var items = choices();
            if (!items.length) { return; }
            var safe = Math.max(0, Math.min(items.length - 1, position));
            items.forEach(function (item, index) { item.tabIndex = index === safe ? 0 : -1; });
            items[safe].focus();
        }
        function open(focusSelected) {
            if (select.disabled) { return; }
            document.querySelectorAll('.web-select.is-open').forEach(function (current) {
                if (current !== shell) {
                    var currentList = current.querySelector('.web-select-list');
                    var currentTrigger = current.querySelector('.web-select-trigger');
                    if (currentList) { currentList.hidden = true; }
                    if (currentTrigger) { currentTrigger.setAttribute('aria-expanded', 'false'); }
                    current.classList.remove('is-open');
                }
            });
            list.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            shell.classList.add('is-open');
            var availableAbove = Math.max(120, trigger.getBoundingClientRect().top - 18);
            list.style.maxHeight = Math.min(280, availableAbove) + 'px';
            if (focusSelected) {
                var items = choices();
                var selectedIndex = Math.max(0, items.findIndex(function (item) { return item.getAttribute('aria-selected') === 'true'; }));
                focusChoice(selectedIndex);
            }
        }
        function rebuild() {
            list.innerHTML = '';
            availableOptions().forEach(function (option) {
                var choice = document.createElement('button');
                choice.type = 'button';
                choice.tabIndex = -1;
                choice.setAttribute('role', 'option');
                choice.setAttribute('aria-selected', option.selected ? 'true' : 'false');
                choice.textContent = option.textContent.trim();
                choice.title = choice.textContent;
                choice.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    select.value = option.value;
                    select.dispatchEvent(new Event('change', {bubbles: true}));
                    close(true);
                });
                list.appendChild(choice);
            });
            var selected = select.options[select.selectedIndex];
            label.textContent = selected ? selected.textContent.trim() : '请选择';
            trigger.disabled = select.disabled;
            trigger.title = label.textContent;
            trigger.setAttribute('aria-label', (select.getAttribute('data-web-select-label') || select.getAttribute('aria-label') || '选择') + '：' + label.textContent);
        }
        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            if (list.hidden) { open(true); } else { close(false); }
        });
        trigger.addEventListener('keydown', function (event) {
            if (['ArrowDown', 'ArrowUp', 'Home', 'End'].indexOf(event.key) === -1) { return; }
            event.preventDefault();
            event.stopPropagation();
            open(false);
            var items = choices();
            var selectedIndex = Math.max(0, items.findIndex(function (item) { return item.getAttribute('aria-selected') === 'true'; }));
            focusChoice(event.key === 'End' ? items.length - 1 : (event.key === 'ArrowUp' ? Math.max(0, selectedIndex - 1) : (event.key === 'ArrowDown' ? Math.min(items.length - 1, selectedIndex + 1) : 0)));
        });
        shell.addEventListener('keydown', function (event) {
            var items = choices();
            var index = items.indexOf(document.activeElement);
            if (event.key === 'Escape') {
                event.preventDefault();
                close(true);
            } else if (!list.hidden && ['ArrowDown', 'ArrowUp', 'Home', 'End'].indexOf(event.key) !== -1) {
                event.preventDefault();
                if (event.key === 'Home') { focusChoice(0); return; }
                if (event.key === 'End') { focusChoice(items.length - 1); return; }
                focusChoice((index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length);
            } else if (!list.hidden && index >= 0 && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault();
                items[index].click();
            } else if (event.key === 'Tab') {
                close(false);
            }
        });
        shell.addEventListener('focusout', function () {
            window.setTimeout(function () {
                if (!shell.contains(document.activeElement)) { close(false); }
            }, 0);
        });
        document.addEventListener('click', function (event) {
            if (!shell.contains(event.target)) { close(false); }
        });
        select.addEventListener('change', rebuild);
        select.addEventListener('web-select-sync', rebuild);
        if (typeof MutationObserver === 'function') {
            new MutationObserver(rebuild).observe(select, {attributes: true, childList: true, subtree: true});
        }
        rebuild();
    }

    form.querySelectorAll('select[data-free-web-select], .quantity-picker-select').forEach(enhanceWebSelect);

    function syncComposerClearance() {
        if (!freeStudio) {
            return;
        }
        freeStudio.style.setProperty('--free-studio-composer-height', Math.ceil(form.getBoundingClientRect().height) + 'px');
    }

    var composerResizeObserver = typeof window.ResizeObserver === 'function'
        ? new ResizeObserver(syncComposerClearance)
        : null;
    if (composerResizeObserver) {
        composerResizeObserver.observe(form);
    }
    window.addEventListener('resize', syncComposerClearance);
    window.requestAnimationFrame(syncComposerClearance);

    function selectMode(mode, updateUrl) {
        var selected = mode === 'free' ? 'free' : 'sku';
        modeButtons.forEach(function (button) {
            var active = button.getAttribute('data-generation-mode-button') === selected;
            button.classList.toggle('active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        modePanels.forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-generation-mode-panel') !== selected;
        });
        if (updateUrl && window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            if (selected === 'free') {
                url.searchParams.set('mode', 'free');
                url.searchParams.delete('sku_id');
            } else {
                url.searchParams.delete('mode');
            }
            window.history.replaceState({}, '', url.pathname + url.search);
        }
    }

    function updatePromptCount() {
        promptCount.textContent = String(prompt.value.length);
        autoGrowPrompt();
    }

    function clearTemplateSelection() {
        if (templateId) {
            templateId.value = '';
        }
        templateButtons.forEach(function (button) {
            button.classList.remove('selected');
            button.setAttribute('aria-pressed', 'false');
        });
    }

    function updateQuote() {
        var hasReference = hasActiveReference();
        var option = modelSelector ? modelSelector.options[modelSelector.selectedIndex] : null;
        var priceAttribute = hasReference ? 'data-edit-price' : 'data-generation-price';
        var unitPrice = option
            ? Math.max(0, parseInt(option.getAttribute(priceAttribute) || '0', 10))
            : Math.max(0, parseInt(form.getAttribute('data-unit-price') || '0', 10));
        var count = Math.max(1, parseInt(quantity.value || '1', 10));
        if (estimatedCredits) {
            estimatedCredits.textContent = String(unitPrice * count);
        }
        if (selectedModelName && option) {
            selectedModelName.textContent = option.textContent.trim();
        }
        if (modelHint) {
            modelHint.textContent = hasReference ? '已上传参考图，只显示支持图片编辑的模型' : '未上传参考图，使用文生图能力';
        }
    }

    function filterModels() {
        if (!modelSelector) {
            updateQuote();
            return;
        }
        var hasReference = hasActiveReference();
        var supportAttribute = hasReference ? 'data-supports-edit' : 'data-supports-generation';
        var defaultValue = modelSelector.getAttribute(hasReference ? 'data-default-edit-id' : 'data-default-generation-id') || '0';
        Array.prototype.forEach.call(modelSelector.options, function (option) {
            var supported = option.getAttribute(supportAttribute) === '1';
            option.hidden = !supported;
            option.disabled = !supported;
            var label = option.getAttribute(hasReference ? 'data-edit-label' : 'data-generation-label');
            if (label) {
                option.textContent = label;
            }
        });
        var selected = modelSelector.options[modelSelector.selectedIndex];
        if (!selected || selected.disabled) {
            var preferred = Array.prototype.find.call(modelSelector.options, function (option) {
                return option.value === defaultValue && !option.disabled;
            });
            var firstAvailable = Array.prototype.find.call(modelSelector.options, function (option) {
                return !option.disabled;
            });
            modelSelector.value = preferred ? preferred.value : (firstAvailable ? firstAvailable.value : '');
        }
        modelSelector.dispatchEvent(new Event('web-select-sync'));
        updateQuote();
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

    function restoreLandingDraft() {
        var draft = null;
        try {
            draft = JSON.parse(window.sessionStorage.getItem(LANDING_DRAFT_KEY) || 'null');
        } catch (error) {
            draft = null;
        }
        if (!draft || draft.schemaVersion !== 1 || draft.source !== 'landing_free_create') {
            return;
        }
        if (!prompt.value.trim() && typeof draft.prompt === 'string') {
            prompt.value = draft.prompt.slice(0, 4000);
        }
        var hasOutputPreset = typeof draft.outputPreset === 'string'
            && Array.prototype.some.call(outputPreset.options, function (option) { return option.value === draft.outputPreset; });
        if (hasOutputPreset) {
            outputPreset.value = draft.outputPreset;
            outputPreset.dispatchEvent(new Event('change', {bubbles: true}));
        }
        var restoredQuantity = Math.max(1, Math.min(4, parseInt(draft.quantity || '1', 10)));
        quantity.value = String(restoredQuantity);
        quantity.dispatchEvent(new Event('change', {bubbles: true}));
        var language = form.querySelector('select[name="language"]');
        var hasLanguage = language && typeof draft.language === 'string'
            && Array.prototype.some.call(language.options, function (option) { return option.value === draft.language; });
        if (hasLanguage) {
            language.value = draft.language;
        }
        var referenceNames = Array.isArray(draft.referenceNames)
            ? draft.referenceNames.filter(function (name) { return typeof name === 'string' && name.trim() !== ''; }).slice(0, 6)
            : [];
        if (landingDraftNotice && landingDraftMessage) {
            landingDraftMessage.textContent = draft.hasReferences
                ? '文字、比例、数量和语言已恢复；公开页选择的 ' + Math.max(1, referenceNames.length) + ' 张参考图没有上传，请在下方重新选择。'
                : '文字、比例、数量和语言已恢复，可以继续调整后发送。';
            landingDraftNotice.hidden = false;
        }
        try {
            window.sessionStorage.removeItem(LANDING_DRAFT_KEY);
        } catch (error) {
            // The draft was already restored; storage cleanup can safely wait for the browser.
        }
        clearTemplateSelection();
        updatePromptCount();
        filterModels();
        updateOutputHint();
        updateQuote();
    }

    function clearReference() {
        referencePreviewUrls.forEach(function (url) { URL.revokeObjectURL(url); });
        referencePreviewUrls = [];
        selectedReferenceFiles = [];
        if (referenceAssetId) { referenceAssetId.value = ''; }
        referenceInput.value = '';
        if (referencePreview) { referencePreview.removeAttribute('src'); }
        uploadPreview.hidden = true;
        uploadEmpty.hidden = false;
        removeReference.hidden = true;
        if (referenceList) {
            referenceList.innerHTML = '';
            referenceList.hidden = true;
        }
        if (referenceCount) { referenceCount.textContent = '0/6'; }
        filterModels();
    }

    function syncReferenceInput() {
        var transfer = new DataTransfer();
        selectedReferenceFiles.forEach(function (file) { transfer.items.add(file); });
        referenceInput.files = transfer.files;
    }

    function renderReferenceFiles() {
        referencePreviewUrls.forEach(function (url) { URL.revokeObjectURL(url); });
        referencePreviewUrls = [];
        if (referenceList) {
            referenceList.innerHTML = '';
            selectedReferenceFiles.forEach(function (file, index) {
                var item = document.createElement('div');
                item.className = 'free-studio-reference-item';
                var image = document.createElement('img');
                var url = URL.createObjectURL(file);
                referencePreviewUrls.push(url);
                image.src = safeImageUrl(url);
                image.alt = '临时参考图 ' + (index + 1);
                var label = document.createElement('span');
                label.textContent = index === 0 ? '主参考' : String(index + 1);
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.setAttribute('aria-label', '移除参考图 ' + (index + 1));
                remove.innerHTML = '<i data-lucide="x"></i>';
                remove.addEventListener('click', function () {
                    selectedReferenceFiles.splice(index, 1);
                    syncReferenceInput();
                    renderReferenceFiles();
                });
                item.appendChild(image);
                item.appendChild(label);
                item.appendChild(remove);
                referenceList.appendChild(item);
            });
            referenceList.hidden = selectedReferenceFiles.length === 0;
        }
        uploadEmpty.hidden = selectedReferenceFiles.length > 0;
        uploadPreview.hidden = true;
        removeReference.hidden = selectedReferenceFiles.length === 0;
        if (referenceCount) { referenceCount.textContent = selectedReferenceFiles.length + '/6'; }
        if (window.lucide) { window.lucide.createIcons(); }
        filterModels();
    }

    function addReferenceFiles(files) {
        var incoming = Array.prototype.slice.call(files || []);
        var beforeCount = selectedReferenceFiles.length;
        var allowed = ['image/jpeg', 'image/png', 'image/webp'];
        var rejected = incoming.some(function (file) {
            return allowed.indexOf(file.type) < 0 || file.size > 24 * 1024 * 1024;
        });
        if (rejected) {
            window.alert('参考图仅支持 JPG、PNG、WebP，且单张不能超过 24MB。');
        }
        incoming.forEach(function (file) {
            if (allowed.indexOf(file.type) < 0 || file.size > 24 * 1024 * 1024 || selectedReferenceFiles.length >= 6) {
                return;
            }
            var signature = [file.name, file.size, file.lastModified].join(':');
            var exists = selectedReferenceFiles.some(function (selected) {
                return [selected.name, selected.size, selected.lastModified].join(':') === signature;
            });
            if (!exists) { selectedReferenceFiles.push(file); }
        });
        if (beforeCount + incoming.length > 6) {
            window.alert('自由创作单次最多保留 6 张参考图。');
        }
        syncReferenceInput();
        renderReferenceFiles();
    }

    function showHelperStatus(message, error) {
        helperStatus.textContent = message;
        helperStatus.classList.toggle('error', Boolean(error));
        helperStatus.hidden = message === '';
    }

    function parseJsonResponse(response) {
        return response.text().then(function (body) {
            var data;
            try {
                data = JSON.parse(body);
            } catch (error) {
                throw new Error('服务器返回异常（HTTP ' + response.status + '）');
            }
            if (!response.ok || !data || !data.ok) {
                var requestError = new Error(data && data.message ? data.message : '请求提交失败');
                if (data && data.errorCode) { requestError.code = data.errorCode; }
                if (data) { requestError.operation = data; }
                throw requestError;
            }
            return data;
        });
    }

    function refreshCsrf() {
        return fetch(form.getAttribute('data-csrf-endpoint'), {credentials: 'same-origin', cache: 'no-store'})
            .then(parseJsonResponse)
            .then(function (data) {
                form.querySelector('input[name="_token"]').value = data.token;
                return data.token;
            });
    }

    function optimizePrompt(token, retry) {
        var payload = new FormData();
        payload.append('_token', token);
        payload.append('idea', idea.value.trim());
        payload.append('output_preset', outputPreset.value);
        payload.append('has_reference', hasActiveReference() ? '1' : '0');
        return fetch(form.getAttribute('data-prompt-endpoint'), {method: 'POST', body: payload, credentials: 'same-origin'})
            .then(function (response) {
                if (response.status === 419 && retry) {
                    return refreshCsrf().then(function (nextToken) { return optimizePrompt(nextToken, false); });
                }
                return parseJsonResponse(response);
            });
    }

    function freezeIntentFields() {
        var value = prompt.value.trim();
        var selectedTemplate = document.querySelector('[data-free-template].selected');
        var subject = value;
        var addition = '';
        if (selectedTemplate && templateId && templateId.value) {
            // A published inspiration template is already a complete request.
            // Freeze the exact visible editor content; there is no hidden subject.
            subject = value;
        }
        subjectText.value = subject;
        userAddition.value = addition;
    }

    function escapeChat(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function optimisticTurn(submissionId) {
        if (!chatFeed) {
            return null;
        }
        var priorTurn = chatFeed.querySelector('[data-free-chat-submission="' + submissionId + '"]');
        if (priorTurn) { priorTurn.remove(); }
        var timeline = chatFeed.querySelector('.free-chat-timeline');
        if (!timeline) {
            return null;
        }
        var welcome = timeline.querySelector('.free-chat-welcome');
        if (welcome) { welcome.remove(); }
        var modelOption = modelSelector ? modelSelector.options[modelSelector.selectedIndex] : null;
        var modelName = modelOption
            ? (modelOption.getAttribute(hasActiveReference() ? 'data-edit-label' : 'data-generation-label') || modelOption.textContent).split(' · ')[0].trim()
            : '所选模型';
        var total = Math.max(1, parseInt(quantity.value || '1', 10));
        var presetOption = outputPreset.options[outputPreset.selectedIndex];
        var size = presetOption
            ? (presetOption.getAttribute('data-width') || '1024') + '×' + (presetOption.getAttribute('data-height') || '1024')
            : '1024×1024';
        var languageSelect = form.querySelector('select[name="language"]');
        var languageOption = languageSelect ? languageSelect.options[languageSelect.selectedIndex] : null;
        var turn = document.createElement('section');
        turn.className = 'free-chat-turn is-optimistic';
        turn.id = 'free-chat-pending-' + submissionId;
        turn.setAttribute('data-free-chat-submission', submissionId);
        turn.setAttribute('data-free-chat-status', 'queued');
        turn.innerHTML = '<div class="free-chat-row is-user">'
            + '<span class="free-chat-avatar is-user">你</span><div class="free-chat-message"><strong>你</strong>'
            + '<div class="free-chat-bubble"><div class="free-chat-references" data-optimistic-references></div>'
            + '<p>' + escapeChat(prompt.value.trim()).replace(/\n/g, '<br>') + '</p>'
            + '<small>' + escapeChat(size) + ' · ' + total + ' 张 · ' + escapeChat(languageOption ? languageOption.textContent.trim() : '') + ' · 刚刚</small></div></div></div>'
            + '<div class="free-chat-row is-assistant"><span class="free-chat-avatar is-model">' + escapeChat(modelName.slice(0, 1).toUpperCase()) + '</span>'
            + '<div class="free-chat-message"><div class="free-chat-model-heading"><strong data-optimistic-model>' + escapeChat(modelName) + '</strong><span class="badge queued" data-free-chat-status-label>提交中</span></div>'
            + '<div class="free-chat-bubble free-chat-model-bubble"><div class="free-chat-thinking"><span><i></i><i></i><i></i></span><strong data-free-chat-progress-message>正在安全发送本次创作需求</strong></div>'
            + '<div class="free-chat-progress"><span><i data-free-chat-progress-bar style="width:0%"></i></span><b data-free-chat-progress-count>0/' + total + ' · 0%</b></div><small>提交成功后会在后台继续生成，你无需等待或关闭弹窗。</small></div></div></div>';
        var references = turn.querySelector('[data-optimistic-references]');
        selectedReferenceFiles.forEach(function (file, index) {
            var image = document.createElement('img');
            image.src = safeImageUrl(URL.createObjectURL(file));
            image.alt = '本次参考图 ' + (index + 1);
            image.addEventListener('load', function () { URL.revokeObjectURL(image.src); }, {once: true});
            references.appendChild(image);
        });
        if (!selectedReferenceFiles.length) { references.remove(); }
        timeline.appendChild(turn);
        var scrollport = chatFeed.querySelector('[data-free-chat-scrollport]');
        if (scrollport) {
            window.requestAnimationFrame(function () {
                scrollport.scrollTo({top: scrollport.scrollHeight, behavior: 'smooth'});
            });
        }
        if (window.lucide) { window.lucide.createIcons(); }
        return turn;
    }

    function markOptimisticFailure(turn, message) {
        if (!turn) { return; }
        turn.classList.remove('is-optimistic');
        turn.classList.add('is-submit-failed');
        turn.setAttribute('data-free-chat-status', 'failed');
        var status = turn.querySelector('[data-free-chat-status-label]');
        if (status) { status.className = 'badge failed'; status.textContent = '发送失败'; }
        var bubble = turn.querySelector('.free-chat-model-bubble');
        if (bubble) {
            bubble.innerHTML = '<div class="free-chat-failure"><i data-lucide="circle-alert"></i><span><strong>这条需求没有发送成功</strong><small>' + escapeChat(message || '请检查后重新发送。') + '</small></span></div>';
        }
        if (window.lucide) { window.lucide.createIcons(); }
    }

    function markOptimisticUnconfirmed(turn) {
        if (!turn) { return; }
        turn.classList.remove('is-optimistic');
        turn.classList.add('is-submit-unconfirmed');
        turn.setAttribute('data-free-chat-status', 'unconfirmed');
        var status = turn.querySelector('[data-free-chat-status-label]');
        if (status) { status.className = 'badge queued'; status.textContent = '待确认'; }
        var bubble = turn.querySelector('.free-chat-model-bubble');
        if (bubble) {
            bubble.innerHTML = '<div class="free-chat-failure is-unconfirmed"><i data-lucide="wifi-off"></i><span><strong>暂未确认服务器是否已接收</strong><small>你的输入和参考图已保留。再次点击发送会使用同一发送标识恢复原任务，不会重复建任务或重复预扣。</small></span></div>';
        }
        if (window.lucide) { window.lucide.createIcons(); }
    }

    function sendFreeRequest(payload, retry) {
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var timeout = window.setTimeout(function () {
            if (controller) { controller.abort(); }
        }, 30000);
        return fetch(form.action, {
            method: 'POST',
            body: payload,
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller ? controller.signal : undefined,
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
        }).then(function (response) {
            if (response.status === 419 && retry) {
                return refreshCsrf().then(function (token) {
                    payload.set('_token', token);
                    return sendFreeRequest(payload, false);
                });
            }
            return parseJsonResponse(response);
        }).catch(function (error) {
            if (error && error.name === 'AbortError') {
                var uncertain = new Error('服务器响应超时，结果暂未确认。');
                uncertain.code = 'submission_unconfirmed';
                throw uncertain;
            }
            throw error;
        }).finally(function () {
            window.clearTimeout(timeout);
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (submitting || !form.reportValidity()) {
            return;
        }
        freezeIntentFields();
        var submissionId = clientSubmissionId.value || nextSubmissionId();
        clientSubmissionId.value = submissionId;
        var turn = optimisticTurn(submissionId);
        var payload = new FormData(form);
        submitting = true;
        if (submitButton) { submitButton.disabled = true; submitButton.setAttribute('aria-busy', 'true'); }
        sendFreeRequest(payload, true)
            .then(function (data) {
                var jobId = String(data.jobId || '');
                if (!jobId) { throw new Error('任务已接收，但没有返回任务编号。'); }
                if (turn) {
                    turn.classList.remove('is-optimistic');
                    turn.id = 'free-chat-job-' + jobId;
                    turn.setAttribute('data-free-chat-job', jobId);
                    turn.setAttribute('data-free-chat-status', data.status || 'queued');
                    var model = turn.querySelector('[data-optimistic-model]');
                    if (model && data.modelName) { model.textContent = data.modelName; }
                    var status = turn.querySelector('[data-free-chat-status-label]');
                    if (status) { status.className = 'badge ' + (data.status || 'queued'); status.textContent = data.duplicate ? '已恢复' : '已提交'; }
                    var progressMessage = turn.querySelector('[data-free-chat-progress-message]');
                    if (progressMessage) { progressMessage.textContent = data.progressMessage || '已进入生成队列'; }
                }
                prompt.value = '';
                subjectText.value = '';
                userAddition.value = '';
                clientSubmissionId.value = nextSubmissionId();
                clearTemplateSelection();
                clearReference();
                updatePromptCount();
                document.dispatchEvent(new CustomEvent('global-task-refresh'));
                pollChatTurns();
            })
            .catch(function (error) {
                if (error && error.code === 'submission_unconfirmed') {
                    markOptimisticUnconfirmed(turn);
                    document.dispatchEvent(new CustomEvent('global-task-refresh'));
                } else {
                    markOptimisticFailure(turn, error.message || '发送失败，请稍后重试。');
                }
            })
            .finally(function () {
                submitting = false;
                if (submitButton) { submitButton.disabled = false; submitButton.removeAttribute('aria-busy'); }
                window.requestAnimationFrame(syncComposerClearance);
            });
    });

    modeButtons.forEach(function (button) {
        button.addEventListener('click', function () { selectMode(button.getAttribute('data-generation-mode-button'), true); });
    });
    templateButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            clearTemplateSelection();
            button.classList.add('selected');
            button.setAttribute('aria-pressed', 'true');
            templateId.value = button.getAttribute('data-template-id') || '';
            prompt.value = button.getAttribute('data-template-content') || '';
            updatePromptCount();
            var templateMenu = button.closest('details[data-dismiss-on-outside]');
            if (templateMenu) {
                templateMenu.open = false;
            }
            prompt.focus();
        });
    });
    if (templateFilter) {
        templateFilter.addEventListener('change', function () {
            templateButtons.forEach(function (button) {
                button.hidden = templateFilter.value !== '*' && button.getAttribute('data-template-category') !== templateFilter.value;
            });
        });
    }
    prompt.addEventListener('input', updatePromptCount);
    quantity.addEventListener('change', updateQuote);
    if (modelSelector) {
        modelSelector.addEventListener('change', updateQuote);
    }
    outputPreset.addEventListener('change', updateOutputHint);
    referenceInput.addEventListener('change', function () {
        addReferenceFiles(referenceInput.files || []);
    });
    removeReference.addEventListener('click', clearReference);
    form.addEventListener('paste', function (event) {
        var files = Array.prototype.slice.call((event.clipboardData && event.clipboardData.files) || [])
            .filter(function (file) { return String(file.type || '').indexOf('image/') === 0; });
        if (!files.length) { return; }
        event.preventDefault();
        addReferenceFiles(files);
    });
    ['dragenter', 'dragover'].forEach(function (name) {
        form.addEventListener(name, function (event) {
            if (!event.dataTransfer || !event.dataTransfer.types || Array.prototype.indexOf.call(event.dataTransfer.types, 'Files') === -1) { return; }
            event.preventDefault();
            form.classList.add('is-dragover');
        });
    });
    ['dragleave', 'drop'].forEach(function (name) {
        form.addEventListener(name, function (event) {
            form.classList.remove('is-dragover');
            if (name === 'drop' && event.dataTransfer && event.dataTransfer.files.length) {
                event.preventDefault();
                addReferenceFiles(event.dataTransfer.files);
            }
        });
    });

    form.querySelector('[data-open-prompt-helper]').addEventListener('click', function () {
        if (!idea.value.trim() && prompt.value.trim()) {
            idea.value = prompt.value.trim();
        }
        showHelperStatus('', false);
        dialog.showModal();
        window.setTimeout(function () { idea.focus(); }, 0);
    });
    if (imagePromptDialog) {
        function showImagePromptStatus(message, error) {
            imagePromptStatus.textContent = message || '';
            imagePromptStatus.classList.toggle('error', Boolean(error));
            imagePromptStatus.hidden = !message;
        }
        function syncImagePromptSource() {
            var source = imagePromptDialog.querySelector('input[name="image_prompt_source"]:checked');
            var assets = imagePromptDialog.querySelector('[data-image-prompt-assets]');
            if (assets) { assets.hidden = !source || source.value !== 'asset'; }
        }
        imagePromptDialog.querySelectorAll('input[name="image_prompt_source"]').forEach(function (control) {
            control.addEventListener('change', syncImagePromptSource);
        });
        var openImagePrompt = form.querySelector('[data-open-image-prompt]');
        if (openImagePrompt) {
            openImagePrompt.addEventListener('click', function () {
                showImagePromptStatus('', false);
                imagePromptResult.hidden = true;
                useImagePrompt.hidden = true;
                syncImagePromptSource();
                imagePromptDialog.showModal();
            });
        }
        imagePromptDialog.querySelectorAll('[data-close-image-prompt]').forEach(function (button) {
            button.addEventListener('click', function () { imagePromptDialog.close(); });
        });
        imagePromptDialog.addEventListener('click', function (event) {
            if (event.target === imagePromptDialog) { imagePromptDialog.close(); }
        });
        generateImagePrompt.addEventListener('click', function () {
            var source = imagePromptDialog.querySelector('input[name="image_prompt_source"]:checked');
            var asset = imagePromptDialog.querySelector('input[name="image_prompt_asset"]:checked');
            var payload = new FormData();
            payload.append('_token', form.querySelector('input[name="_token"]').value);
            if (source && source.value === 'asset') {
                if (!asset) { showImagePromptStatus('请先选择一张素材库图片。', true); return; }
                payload.append('asset_id', asset.value);
            } else {
                if (!selectedReferenceFiles.length) { showImagePromptStatus('请先在输入框左侧添加参考图。', true); return; }
                payload.append('source_image', selectedReferenceFiles[0]);
            }
            generateImagePrompt.disabled = true;
            showImagePromptStatus('正在识别画面并整理提示词…', false);
            fetch(form.getAttribute('data-image-prompt-endpoint'), {
                method: 'POST', body: payload, credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
            }).then(parseJsonResponse).then(function (data) {
                imagePromptOutput.value = data.prompt || '';
                imagePromptResult.hidden = false;
                useImagePrompt.hidden = false;
                showImagePromptStatus(data.message || '提示词已生成，可继续修改。', false);
            }).catch(function (error) {
                showImagePromptStatus(error.message || '看图写提示词失败，请稍后重试。', true);
            }).finally(function () { generateImagePrompt.disabled = false; });
        });
        useImagePrompt.addEventListener('click', function () {
            var generated = imagePromptOutput.value.trim();
            if (!generated) { return; }
            var merge = imagePromptDialog.querySelector('input[name="image_prompt_merge"]:checked');
            prompt.value = merge && merge.value === 'append' && prompt.value.trim()
                ? prompt.value.trim() + '\n\n' + generated
                : generated;
            var policy = imagePromptDialog.querySelector('input[name="image_prompt_policy"]:checked');
            var source = imagePromptDialog.querySelector('input[name="image_prompt_source"]:checked');
            if (policy && policy.value === 'prompt_only' && (!source || source.value === 'current')) {
                clearReference();
            } else if (policy && policy.value === 'keep_reference' && source && source.value === 'asset') {
                var selectedAsset = imagePromptDialog.querySelector('input[name="image_prompt_asset"]:checked');
                if (referenceAssetId && selectedAsset) { referenceAssetId.value = selectedAsset.value; }
                filterModels();
            }
            clearTemplateSelection();
            updatePromptCount();
            imagePromptDialog.close();
            prompt.focus();
        });
    }
    dialog.querySelectorAll('[data-close-prompt-helper]').forEach(function (button) {
        button.addEventListener('click', function () { dialog.close(); });
    });
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            dialog.close();
        }
    });
    generatePrompt.addEventListener('click', function () {
        if (idea.value.trim().length < 4) {
            showHelperStatus('请先填写至少 4 个字的初步想法。', true);
            idea.focus();
            return;
        }
        generatePrompt.disabled = true;
        generatePrompt.classList.add('is-loading');
        helperResult.hidden = true;
        usePrompt.hidden = true;
        showHelperStatus('正在理解你的想法并整理画面描述...', false);
        optimizePrompt(form.querySelector('input[name="_token"]').value, true)
            .then(function (data) {
                helperOutput.value = data.prompt || '';
                helperResult.hidden = false;
                usePrompt.hidden = false;
                generatePrompt.querySelector('span').textContent = '重新生成';
                showHelperStatus((data.message || '提示词草稿已生成。') + (Number(data.creditsCharged || 0) > 0 ? ' 本次消耗 ' + Number(data.creditsCharged) + ' 点。' : ''), false);
            })
            .catch(function (error) {
                showHelperStatus(error.message || '提示词生成失败，请稍后重试。', true);
            })
            .finally(function () {
                generatePrompt.disabled = false;
                generatePrompt.classList.remove('is-loading');
            });
    });
    usePrompt.addEventListener('click', function () {
        if (!helperOutput.value.trim()) {
            return;
        }
        prompt.value = helperOutput.value.trim();
        clearTemplateSelection();
        updatePromptCount();
        dialog.close();
        prompt.focus();
    });

    if (tabs) {
        selectMode(tabs.getAttribute('data-initial-mode'), false);
    }
    restoreLandingDraft();
    updatePromptCount();
    filterModels();
    updateOutputHint();

    var chatFeed = document.querySelector('[data-free-chat-feed]');
    var chatActiveStatuses = ['pending_gateway', 'queued', 'running', 'processing', 'retrying'];
    var chatPollTimer = null;
    var chatPollInFlight = false;
    var chatTurnRefreshes = {};

    function refreshCompletedChatTurn(jobId) {
        if (!chatFeed || chatTurnRefreshes[jobId]) {
            return chatTurnRefreshes[jobId] || Promise.resolve();
        }
        var chatScrollport = chatFeed.querySelector('[data-free-chat-scrollport]');
        var previousScrollTop = chatScrollport ? chatScrollport.scrollTop : 0;
        var wasNearBottom = chatScrollport
            ? chatScrollport.scrollHeight - chatScrollport.scrollTop - chatScrollport.clientHeight < 72
            : false;
        var url = new URL(window.location.href);
        url.searchParams.set('project_id', String(jobId));
        chatTurnRefreshes[jobId] = fetch(url.toString(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('自由创作结果读取失败');
                }
                return response.text();
            })
            .then(function (html) {
                var page = new DOMParser().parseFromString(html, 'text/html');
                var replacement = page.querySelector('[data-free-chat-job="' + jobId + '"]');
                var current = document.querySelector('[data-free-chat-job="' + jobId + '"]');
                if (!replacement || !current) {
                    throw new Error('自由创作结果尚未就绪');
                }
                current.replaceWith(replacement);
                if (chatScrollport) {
                    chatScrollport.scrollTop = wasNearBottom
                        ? chatScrollport.scrollHeight
                        : Math.min(previousScrollTop, Math.max(0, chatScrollport.scrollHeight - chatScrollport.clientHeight));
                }
                if (window.lucide) {
                    window.lucide.createIcons();
                }
                document.dispatchEvent(new CustomEvent('global-task-refresh'));
            })
            .finally(function () {
                delete chatTurnRefreshes[jobId];
            });
        return chatTurnRefreshes[jobId];
    }

    function updateChatTurn(job) {
        var turn = document.querySelector('[data-free-chat-job="' + job.id + '"]');
        if (!turn) {
            return;
        }
        var previousStatus = turn.getAttribute('data-free-chat-status') || '';
        turn.setAttribute('data-free-chat-status', job.status || '');
        var status = turn.querySelector('[data-free-chat-status-label]');
        if (status) {
            status.className = 'badge ' + (job.status || '');
            status.textContent = job.statusLabel || '';
        }
        var total = Math.max(1, parseInt(job.progressTotal || job.expectedResults || '1', 10));
        var current = Math.min(total, Math.max(0, parseInt(job.progressCurrent || '0', 10)));
        var percent = Math.max(0, Math.min(100, Math.floor(current * 100 / total)));
        var bar = turn.querySelector('[data-free-chat-progress-bar]');
        var count = turn.querySelector('[data-free-chat-progress-count]');
        var message = turn.querySelector('[data-free-chat-progress-message]');
        if (bar) { bar.style.width = percent + '%'; }
        if (count) { count.textContent = current + '/' + total + ' · ' + percent + '%'; }
        if (message) { message.textContent = job.progressMessage || job.resultStatusLabel || '正在处理你的生成请求'; }
        if (chatActiveStatuses.indexOf(previousStatus) !== -1 && chatActiveStatuses.indexOf(job.status) === -1) {
            refreshCompletedChatTurn(job.id).catch(function () {
                // Keep the current message visible. A manual revisit will render the persisted result.
            });
        }
    }

    function hasActiveChatTurns() {
        return Array.prototype.some.call(document.querySelectorAll('[data-free-chat-status]'), function (turn) {
            return chatActiveStatuses.indexOf(turn.getAttribute('data-free-chat-status') || '') !== -1;
        });
    }

    function pollChatTurns() {
        if (chatPollInFlight) { return; }
        chatPollInFlight = true;
        fetch('/workspace/jobs/status', {credentials: 'same-origin', cache: 'no-store'})
            .then(function (response) {
                if (!response.ok) { throw new Error('自由创作状态刷新失败'); }
                return response.json();
            })
            .then(function (payload) {
                (payload.jobs || []).forEach(updateChatTurn);
            })
            .catch(function () {
                // Keep the persisted conversation visible and retry later.
            })
            .finally(function () {
                chatPollInFlight = false;
                window.clearTimeout(chatPollTimer);
                if (hasActiveChatTurns()) {
                    chatPollTimer = window.setTimeout(pollChatTurns, 2500);
                }
            });
    }

    if (chatFeed) {
        var chatScrollport = chatFeed.querySelector('[data-free-chat-scrollport]');
        var chatJumpControls = chatFeed.querySelector('[data-free-chat-jump-controls]');
        var chatJumpButtons = chatFeed.querySelectorAll('[data-free-chat-jump]');

        function updateChatJumpControls() {
            if (!chatScrollport || !chatJumpControls) {
                return;
            }
            chatJumpControls.hidden = chatScrollport.scrollHeight <= chatScrollport.clientHeight + 8;
        }

        chatJumpButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (!chatScrollport) {
                    return;
                }
                chatScrollport.scrollTo({
                    top: button.getAttribute('data-free-chat-jump') === 'top' ? 0 : chatScrollport.scrollHeight,
                    behavior: 'smooth'
                });
            });
        });

        var storedFocusJobId = '';
        try {
            storedFocusJobId = window.sessionStorage.getItem('free-chat-focus-job') || '';
            window.sessionStorage.removeItem('free-chat-focus-job');
        } catch (error) {
            storedFocusJobId = '';
        }
        var focusJobId = storedFocusJobId || chatFeed.getAttribute('data-focus-job-id') || '';
        var focusedTurn = focusJobId ? document.querySelector('[data-free-chat-job="' + focusJobId + '"]') : null;
        if (chatScrollport && focusedTurn) {
            chatScrollport.scrollTo({
                top: Math.max(0, focusedTurn.offsetTop - Math.max(16, (chatScrollport.clientHeight - focusedTurn.offsetHeight) / 2)),
                behavior: 'auto'
            });
        } else if (chatScrollport) {
            chatScrollport.scrollTo({top: chatScrollport.scrollHeight, behavior: 'auto'});
        }
        updateChatJumpControls();
        window.addEventListener('resize', updateChatJumpControls);
        if (hasActiveChatTurns()) {
            chatPollTimer = window.setTimeout(pollChatTurns, 1200);
        }
    }
}

function setupImageFallbacks() {
    document.querySelectorAll('img[data-fallback]').forEach(function (image) {
        function useFallback() {
            var fallback = image.getAttribute('data-fallback');
            if (!fallback || image.getAttribute('data-fallback-used') === '1') {
                return;
            }
            image.setAttribute('data-fallback-used', '1');
            image.src = safeImageUrl(fallback);
        }
        image.addEventListener('error', useFallback);
        if (image.complete && image.naturalWidth === 0) {
            useFallback();
        }
    });
}

function setupAssetPreview() {
    var viewer = document.querySelector('[data-asset-viewer]');
    var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-asset-preview-src]'));
    if (!viewer || !buttons.length) {
        return;
    }
    var image = viewer.querySelector('[data-asset-viewer-image]');
    var title = viewer.querySelector('[data-asset-viewer-title]');
    var close = viewer.querySelector('[data-asset-viewer-close]');
    var stage = viewer.querySelector('[data-asset-viewer-stage]');
    var content = viewer.querySelector('[data-asset-viewer-content]');
    var minus = viewer.querySelector('[data-asset-viewer-minus]');
    var plus = viewer.querySelector('[data-asset-viewer-plus]');
    var zoomOutput = viewer.querySelector('[data-asset-viewer-zoom]');
    var previous = viewer.querySelector('[data-asset-viewer-previous]');
    var next = viewer.querySelector('[data-asset-viewer-next]');
    var counter = viewer.querySelector('[data-asset-viewer-counter]');
    var download = viewer.querySelector('[data-asset-viewer-download]');
    if (!image || !title || !close || !stage || !content || !minus || !plus || !zoomOutput || !previous || !next || !counter || !download) {
        return;
    }
    var scale = 1;
    var baseWidth = 0;
    var baseHeight = 0;
    var currentItems = [];
    var currentIndex = -1;

    function setScale(next, preserveCenter) {
        var oldWidth = Math.max(1, content.offsetWidth);
        var oldHeight = Math.max(1, content.offsetHeight);
        var centerX = (stage.scrollLeft + stage.clientWidth / 2) / oldWidth;
        var centerY = (stage.scrollTop + stage.clientHeight / 2) / oldHeight;
        scale = Math.max(0.5, Math.min(4, next));
        content.style.width = Math.round(baseWidth * scale) + 'px';
        content.style.height = Math.round(baseHeight * scale) + 'px';
        zoomOutput.textContent = Math.round(scale * 100) + '%';
        window.requestAnimationFrame(function () {
            if (preserveCenter === false) {
                stage.scrollLeft = Math.max(0, (content.offsetWidth - stage.clientWidth) / 2);
                stage.scrollTop = Math.max(0, (content.offsetHeight - stage.clientHeight) / 2);
                return;
            }
            stage.scrollLeft = Math.max(0, centerX * content.offsetWidth - stage.clientWidth / 2);
            stage.scrollTop = Math.max(0, centerY * content.offsetHeight - stage.clientHeight / 2);
        });
    }

    function prepareViewer() {
        if (!image.naturalWidth || !image.naturalHeight) {
            return;
        }
        var style = window.getComputedStyle(stage);
        var availableWidth = Math.max(1, stage.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight));
        var availableHeight = Math.max(1, stage.clientHeight - parseFloat(style.paddingTop) - parseFloat(style.paddingBottom));
        var fit = Math.min(availableWidth / image.naturalWidth, availableHeight / image.naturalHeight);
        baseWidth = Math.max(1, Math.round(image.naturalWidth * fit));
        baseHeight = Math.max(1, Math.round(image.naturalHeight * fit));
        setScale(1, false);
    }
    function closeViewer() {
        if (viewer.open) {
            viewer.close();
        }
        document.body.classList.remove('asset-viewer-open');
    }
    function updateNavigation() {
        var total = currentItems.length;
        counter.textContent = total > 0 ? (currentIndex + 1) + ' / ' + total : '';
        previous.disabled = currentIndex <= 0;
        next.disabled = currentIndex < 0 || currentIndex >= total - 1;
        previous.hidden = total <= 1;
        next.hidden = total <= 1;
    }
    function showItem(button, openViewer) {
        if (!button) {
            return;
        }
        scale = 1;
        baseWidth = 0;
        baseHeight = 0;
        content.style.width = '1px';
        content.style.height = '1px';
        image.alt = button.getAttribute('data-asset-preview-title') || '素材预览';
        image.setAttribute('data-fallback', button.getAttribute('data-asset-preview-fallback') || '');
        image.removeAttribute('data-fallback-used');
        image.src = safeImageUrl(button.getAttribute('data-asset-preview-src') || '');
        title.textContent = image.alt;
        var assetId = button.getAttribute('data-asset-preview-id') || '';
        download.hidden = assetId === '';
        download.href = assetId === '' ? '#' : '/workspace/assets/' + encodeURIComponent(assetId) + '/download';
        updateNavigation();
        if (openViewer && !viewer.open) {
            viewer.showModal();
            document.body.classList.add('asset-viewer-open');
        }
        if (image.complete && image.naturalWidth) {
            prepareViewer();
        }
    }
    function move(offset) {
        var target = currentIndex + offset;
        if (target < 0 || target >= currentItems.length) {
            return;
        }
        currentIndex = target;
        showItem(currentItems[currentIndex], false);
    }
    buttons.forEach(function (button) {
        button.addEventListener('pointermove', function (event) {
            var bounds = button.getBoundingClientRect();
            var x = Math.max(0, Math.min(100, (event.clientX - bounds.left) * 100 / bounds.width));
            var y = Math.max(0, Math.min(100, (event.clientY - bounds.top) * 100 / bounds.height));
            button.style.setProperty('--zoom-x', x + '%');
            button.style.setProperty('--zoom-y', y + '%');
        });
        button.addEventListener('click', function () {
            var group = button.getAttribute('data-asset-preview-group') || 'all-assets';
            currentItems = buttons.filter(function (item) {
                return (item.getAttribute('data-asset-preview-group') || 'all-assets') === group;
            });
            currentIndex = currentItems.indexOf(button);
            showItem(button, true);
        });
    });
    image.addEventListener('load', prepareViewer);
    minus.addEventListener('click', function () { setScale(scale - 0.25); });
    plus.addEventListener('click', function () { setScale(scale + 0.25); });
    previous.addEventListener('click', function () { move(-1); });
    next.addEventListener('click', function () { move(1); });
    stage.addEventListener('wheel', function (event) {
        event.preventDefault();
        setScale(scale + (event.deltaY < 0 ? 0.25 : -0.25));
    }, {passive: false});
    window.addEventListener('resize', function () {
        if (viewer.open) {
            prepareViewer();
        }
    });
    image.addEventListener('error', function () {
        var fallback = image.getAttribute('data-fallback');
        if (!fallback || image.getAttribute('data-fallback-used') === '1') {
            return;
        }
        image.setAttribute('data-fallback-used', '1');
        image.src = safeImageUrl(fallback);
    });
    close.addEventListener('click', closeViewer);
    viewer.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            move(-1);
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            move(1);
        }
    });
    viewer.addEventListener('click', function (event) {
        if (event.target === viewer) {
            closeViewer();
        }
    });
    viewer.addEventListener('close', function () {
        document.body.classList.remove('asset-viewer-open');
        scale = 1;
        baseWidth = 0;
        baseHeight = 0;
        content.style.width = '1px';
        content.style.height = '1px';
        image.src = safeImageUrl('data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=');
        image.alt = '';
        title.textContent = '';
        counter.textContent = '';
        download.href = '#';
        zoomOutput.textContent = '100%';
        currentItems = [];
        currentIndex = -1;
        updateNavigation();
    });
}

function setupCanvasEditor() {
    var editor = document.querySelector('[data-canvas-editor]');
    if (!editor) {
        return;
    }
    var image = editor.querySelector('[data-canvas-image]');
    var overlay = editor.querySelector('[data-canvas-overlay]');
    var form = editor.querySelector('[data-canvas-form]');
    var assetSelect = editor.querySelector('[data-canvas-asset]');
    var assetInput = editor.querySelector('[data-canvas-asset-id]');
    var brush = editor.querySelector('[data-canvas-brush]');
    var brushOutput = editor.querySelector('[data-canvas-brush-value]');
    var undo = editor.querySelector('[data-canvas-undo]');
    var clear = editor.querySelector('[data-canvas-clear]');
    var selectionState = editor.querySelector('[data-canvas-selection-state]');
    var maskInput = editor.querySelector('[data-canvas-mask]');
    var submit = editor.querySelector('[data-canvas-submit]');
    var result = editor.querySelector('[data-canvas-result]');
    var resultImage = editor.querySelector('[data-canvas-result-image]');
    var resultMeta = editor.querySelector('[data-canvas-result-meta]');
    var editResult = editor.querySelector('[data-canvas-edit-result]');
    var operationInputs = form ? form.querySelectorAll('[data-canvas-operation]') : [];
    var modeInputs = form ? form.querySelectorAll('[data-canvas-mode]') : [];
    var presetInputs = form ? form.querySelectorAll('[data-canvas-preset]') : [];
    var instruction = form ? form.querySelector('#canvas-instruction') : null;
    var regionalTools = editor.querySelector('[data-canvas-regional-tools]');
    var modeHint = editor.querySelector('[data-canvas-mode-hint]');
    var outputHint = editor.querySelector('[data-canvas-output-hint]');
    var outputPreset = form ? form.querySelector('[data-canvas-output-preset]') : null;
    var resizeMode = form ? form.querySelector('[data-canvas-resize-mode]') : null;
    var aiOptions = form ? form.querySelector('[data-canvas-ai-options]') : null;
    var submitLabel = form ? form.querySelector('[data-canvas-submit-label]') : null;
    var taskProgress = editor.querySelector('[data-canvas-task-progress]');
    var taskLabel = editor.querySelector('[data-canvas-task-label]');
    var taskPercent = editor.querySelector('[data-canvas-task-percent]');
    var taskBar = editor.querySelector('[data-canvas-task-bar]');
    if (!image || !overlay || !form || !assetSelect || !assetInput || !brush || !brushOutput || !undo || !clear || !selectionState || !maskInput || !submit || !submitLabel || !result || !resultImage || !resultMeta || !editResult || !instruction || !regionalTools || !modeHint || !outputHint || !outputPreset || !resizeMode || !aiOptions || !taskProgress || !taskLabel || !taskPercent || !taskBar || !operationInputs.length || !modeInputs.length || !presetInputs.length) {
        return;
    }
    var context = overlay.getContext('2d');
    var strokes = [];
    var activeStroke = null;
    var pendingTasks = new Map();
    var latestTaskId = 0;

    function selectedValue(inputs) {
        var selected = Array.prototype.find.call(inputs, function (input) { return input.checked; });
        return selected ? selected.value : '';
    }

    function currentMode() {
        return selectedValue(modeInputs) || 'global';
    }

    function currentOperation() {
        return selectedValue(operationInputs) || 'resize_only';
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
        clear.disabled = strokes.length === 0;
        if (currentMode() === 'regional') {
            selectionState.textContent = strokes.length ? '已标记 ' + strokes.length + ' 处修改区域' : '请在图片上涂抹需要重绘的区域';
            selectionState.classList.toggle('active', strokes.length > 0);
        }
    }
    function pointFromEvent(event) {
        var bounds = overlay.getBoundingClientRect();
        return {
            x: (event.clientX - bounds.left) * overlay.width / bounds.width,
            y: (event.clientY - bounds.top) * overlay.height / bounds.height,
        };
    }
    function brushSize() {
        var bounds = overlay.getBoundingClientRect();
        return Math.max(2, Number(brush.value) * overlay.width / Math.max(1, bounds.width));
    }
    function prepareCanvas() {
        if (!image.naturalWidth || !image.naturalHeight) {
            return;
        }
        overlay.width = image.naturalWidth;
        overlay.height = image.naturalHeight;
        strokes = [];
        activeStroke = null;
        redraw();
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
        selectionState.textContent = !aiEditing
            ? '选择图片比例后即可免费调整尺寸'
            : regional
            ? (strokes.length ? '已标记 ' + strokes.length + ' 处修改区域' : '请在图片上涂抹需要重绘的区域')
            : '整图编辑将保留商品主体，并按指令调整画面';
        selectionState.classList.toggle('active', aiEditing && regional && strokes.length > 0);
    }

    function updateOperation() {
        var aiEditing = currentOperation() === 'ai_edit';
        aiOptions.hidden = !aiEditing;
        resizeMode.value = aiEditing ? 'extend' : 'crop';
        var credits = Number(submit.getAttribute('data-canvas-ai-credits') || 0);
        submitLabel.textContent = aiEditing
            ? '提交 AI 编辑' + (credits > 0 ? ' · ' + credits + ' 点' : '')
            : '调整并保存 · 免费';
        updateMode();
    }

    function updateOutputHint() {
        var option = outputPreset.options[outputPreset.selectedIndex];
        var width = Number(option ? option.getAttribute('data-width') : 1024);
        var height = Number(option ? option.getAttribute('data-height') : 1024);
        function greatestCommonDivisor(a, b) {
            while (b) {
                var remainder = a % b;
                a = b;
                b = remainder;
            }
            return a || 1;
        }
        var divisor = greatestCommonDivisor(width, height);
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
        assetSelect.disabled = busy;
        submit.classList.toggle('is-loading', busy);
        modeInputs.forEach(function (input) { input.disabled = busy; });
        operationInputs.forEach(function (input) { input.disabled = busy; });
        presetInputs.forEach(function (input) { input.disabled = busy; });
        outputPreset.disabled = busy;
        resizeMode.disabled = busy;
    }

    function responseJson(response) {
        return response.text().then(function (body) {
            var data = null;
            try {
                data = body ? JSON.parse(body) : null;
            } catch (error) {
                var message = response.status === 504
                    ? '图片处理时间较长，网关已超时。任务可能仍在后台继续，请到任务记录查看。'
                    : '服务器返回了无法识别的响应（HTTP ' + response.status + '），请稍后重试。';
                throw new Error(message);
            }
            if (!response.ok || !data || !data.ok) {
                throw new Error(data && data.message ? data.message : '画布编辑请求失败');
            }
            return data;
        });
    }

    function pollJob(statusUrl, attempts, taskId, sourceAssetId) {
        if (!pendingTasks.has(taskId)) {
            return;
        }
        fetch(statusUrl, {credentials: 'same-origin', cache: 'no-store'})
            .then(responseJson)
            .then(function (data) {
                var job = data.job || {};
                var percent = job.status === 'completed' ? 100 : job.status === 'running' ? (job.stage === 'saving' ? 90 : 55) : 15;
                if (taskId === latestTaskId) {
                    setTaskProgress(job.message || job.statusLabel || '正在处理', percent, job.status !== 'completed' && job.status !== 'failed');
                    selectionState.textContent = job.message || job.statusLabel || '正在处理';
                    selectionState.classList.remove('error');
                }
                if (job.status === 'completed') {
                    pendingTasks.delete(taskId);
                    if (String(assetInput.value) === String(sourceAssetId) && taskId === latestTaskId) {
                        resultImage.src = safeImageUrl(job.imageUrl);
                        resultMeta.textContent = '已输出 ' + job.outputWidth + '×' + job.outputHeight + '，并归档到素材库';
                        editResult.href = '/workspace/canvas?asset_id=' + encodeURIComponent(job.assetId);
                        result.hidden = false;
                        selectionState.textContent = '编辑完成，结果已归档到素材库';
                        selectionState.classList.add('active');
                    }
                    return;
                }
                if (job.status === 'failed') {
                    pendingTasks.delete(taskId);
                    if (taskId === latestTaskId) {
                        selectionState.textContent = job.error || job.message || '画布编辑失败，未使用点数已退回';
                        selectionState.classList.add('error');
                    }
                    return;
                }
                if (attempts >= 240) {
                    pendingTasks.delete(taskId);
                    if (taskId === latestTaskId) {
                        selectionState.textContent = '任务仍在后台处理，可通过右下角任务胶囊继续查看';
                    }
                    return;
                }
                window.setTimeout(function () { pollJob(statusUrl, attempts + 1, taskId, sourceAssetId); }, 2500);
            })
            .catch(function (error) {
                if (attempts >= 3) {
                    pendingTasks.delete(taskId);
                    if (taskId === latestTaskId) {
                        selectionState.textContent = error.message || '无法获取画布任务进度，请到任务记录查看';
                        selectionState.classList.add('error');
                    }
                    return;
                }
                window.setTimeout(function () { pollJob(statusUrl, attempts + 1, taskId, sourceAssetId); }, 2500);
            });
    }

    image.addEventListener('load', prepareCanvas);
    image.addEventListener('error', function () {
        var fallback = image.getAttribute('data-fallback');
        if (fallback && image.getAttribute('data-fallback-used') !== '1') {
            image.setAttribute('data-fallback-used', '1');
            image.src = safeImageUrl(fallback);
        }
    });
    assetSelect.addEventListener('change', function () {
        window.location.assign('/workspace/canvas?asset_id=' + encodeURIComponent(assetSelect.value));
    });
    brush.addEventListener('input', function () {
        brushOutput.textContent = brush.value + ' px';
    });
    overlay.addEventListener('pointerdown', function (event) {
        if (currentMode() !== 'regional' || !overlay.width || !overlay.height) {
            return;
        }
        event.preventDefault();
        activeStroke = {size: brushSize(), points: [pointFromEvent(event)]};
        strokes.push(activeStroke);
        overlay.setPointerCapture(event.pointerId);
        redraw();
    });
    overlay.addEventListener('pointermove', function (event) {
        if (!activeStroke) {
            return;
        }
        activeStroke.points.push(pointFromEvent(event));
        redraw();
    });
    function finishStroke() {
        activeStroke = null;
    }
    overlay.addEventListener('pointerup', finishStroke);
    overlay.addEventListener('pointercancel', finishStroke);
    undo.addEventListener('click', function () {
        strokes.pop();
        redraw();
    });
    clear.addEventListener('click', function () {
        strokes = [];
        redraw();
    });
    modeInputs.forEach(function (input) {
        input.addEventListener('change', updateMode);
    });
    operationInputs.forEach(function (input) {
        input.addEventListener('change', updateOperation);
    });
    presetInputs.forEach(function (input) {
        input.addEventListener('change', function () {
            if (input.checked && input.value === 'custom') {
                instruction.focus();
            }
        });
    });
    outputPreset.addEventListener('change', updateOutputHint);
    form.addEventListener('submit', function (event) {
        event.preventDefault();
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
        selectionState.classList.remove('error');
        maskInput.value = operation === 'ai_edit' && mode === 'regional' ? buildMask() : '';
        setBusy(true);
        result.hidden = true;
        selectionState.textContent = operation === 'ai_edit' ? '正在创建 AI 编辑任务...' : '正在创建尺寸调整任务...';
        setTaskProgress('正在提交', 5, true);
        var payload = new FormData(form);
        fetch(editor.getAttribute('data-canvas-endpoint'), {method: 'POST', body: payload, credentials: 'same-origin'})
            .then(responseJson)
            .then(function (data) {
                var taskId = Number(data.jobId || Date.now());
                var sourceAssetId = String(assetInput.value || '');
                pendingTasks.set(taskId, {statusUrl: data.statusUrl, sourceAssetId: sourceAssetId});
                latestTaskId = taskId;
                setBusy(false);
                selectionState.textContent = (data.message || '编辑任务已进入队列') + ' 现在可以继续处理其他图片。';
                setTaskProgress('已进入处理队列', 12, true);
                pollJob(data.statusUrl, 0, taskId, sourceAssetId);
            })
            .catch(function (error) {
                selectionState.textContent = error.message || '画布编辑失败';
                selectionState.classList.add('error');
                setTaskProgress('提交失败', 0, false);
                setBusy(false);
            });
    });
    brushOutput.textContent = brush.value + ' px';
    updateOperation();
    updateOutputHint();
    if (image.complete && image.naturalWidth) {
        prepareCanvas();
    }
}

function optimizeSkuImageFile(file) {
    if (!file || file.size <= 8 * 1024 * 1024 || !window.createImageBitmap) {
        return Promise.resolve(file);
    }
    return window.createImageBitmap(file).then(function (bitmap) {
        var scale = Math.min(1, 2400 / Math.max(bitmap.width, bitmap.height));
        var width = Math.max(1, Math.round(bitmap.width * scale));
        var height = Math.max(1, Math.round(bitmap.height * scale));
        var canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        var context = canvas.getContext('2d');
        context.drawImage(bitmap, 0, 0, width, height);
        if (bitmap.close) {
            bitmap.close();
        }
        var outputType = file.type === 'image/jpeg' ? 'image/jpeg' : 'image/webp';
        return new Promise(function (resolve) {
            canvas.toBlob(function (blob) {
                if (!blob || blob.size >= file.size) {
                    resolve(file);
                    return;
                }
                var baseName = file.name.replace(/\.[^.]+$/, '') || 'product-image';
                var extension = outputType === 'image/jpeg' ? '.jpg' : '.webp';
                resolve(new File([blob], baseName + extension, {type: outputType, lastModified: Date.now()}));
            }, outputType, 0.86);
        });
    }).catch(function () {
        return file;
    });
}

function setupSkuAnalyzer() {
    var form = document.querySelector('[data-sku-analyzer]');
    if (!form) {
        return;
    }
    var button = form.querySelector('[data-analyze-product]');
    var identityButton = form.querySelector('[data-analyze-identity]');
    var status = form.querySelector('[data-analyze-status]');
    var fileInput = form.querySelector('#source_image');
    var nameInput = form.querySelector('#name');
    var categoryInput = form.querySelector('#category');
    var sellingPointsInput = form.querySelector('#selling_points');
    var creativeBriefInput = form.querySelector('#creative_brief');
    var productDescriptionInput = form.querySelector('#product_description');
    var searchTermsInput = form.querySelector('#search_terms');
    var preparedFile = null;
    var preparingImage = false;
    var imageTooLarge = false;
    var imagePreparation = Promise.resolve(null);

    fileInput.addEventListener('change', function () {
        var selectedFile = fileInput.files && fileInput.files[0];
        preparedFile = null;
        imageTooLarge = false;
        button.disabled = false;
        if (identityButton) { identityButton.disabled = false; }
        if (!selectedFile) {
            return;
        }
        if (selectedFile.size > 24 * 1024 * 1024) {
            imageTooLarge = true;
            button.disabled = true;
            if (identityButton) { identityButton.disabled = true; }
            status.textContent = '原图超过 24MB，请先压缩后再上传';
            status.className = 'analyze-status error';
            return;
        }
        preparingImage = true;
        button.disabled = true;
        if (identityButton) { identityButton.disabled = true; }
        status.textContent = selectedFile.size > 8 * 1024 * 1024 ? '正在优化手机大图...' : '';
        status.className = 'analyze-status';
        imagePreparation = optimizeSkuImageFile(selectedFile).then(function (optimizedFile) {
            preparedFile = optimizedFile;
            if (optimizedFile !== selectedFile && window.DataTransfer) {
                var transfer = new DataTransfer();
                transfer.items.add(optimizedFile);
                fileInput.files = transfer.files;
                status.textContent = '图片已自动优化，可继续识别或保存';
                status.className = 'analyze-status success';
            }
            return optimizedFile;
        }).finally(function () {
            preparingImage = false;
            button.disabled = false;
            if (identityButton) { identityButton.disabled = false; }
        });
    });

    form.addEventListener('submit', function (event) {
        if (imageTooLarge) {
            event.preventDefault();
            status.textContent = '原图超过 24MB，请先压缩后再上传';
            status.className = 'analyze-status error';
            return;
        }
        if (!preparingImage) {
            return;
        }
        event.preventDefault();
        var submitter = event.submitter;
        status.textContent = '正在完成图片优化，请稍候...';
        imagePreparation.finally(function () {
            if (form.requestSubmit) {
                form.requestSubmit(submitter || undefined);
            } else {
                form.submit();
            }
        });
    });

    function waitForVisionTask(result) {
        if (!result || !result.queued || !result.statusUrl) {
            return Promise.resolve(result);
        }
        var attempts = 0;
        status.textContent = result.message || '已进入视觉任务队列，正在等待处理...';
        return new Promise(function (resolve, reject) {
            function poll() {
                attempts += 1;
                fetch(result.statusUrl, {credentials: 'same-origin', cache: 'no-store'})
                    .then(function (response) {
                        return response.json().catch(function () {
                            throw new Error('视觉任务状态读取失败');
                        });
                    })
                    .then(function (task) {
                        if (task.status === 'completed' && task.ok) {
                            resolve(task);
                            return;
                        }
                        if (task.status === 'failed' || !task.ok) {
                            reject(new Error(task.message || '商品识别失败'));
                            return;
                        }
                        status.textContent = task.message || 'AI 正在后台识别商品...';
                        if (attempts >= 240) {
                            reject(new Error('识别仍在后台处理中，请稍后回到商品资料查看结果'));
                            return;
                        }
                        window.setTimeout(poll, 1500);
                    })
                    .catch(reject);
            }
            window.setTimeout(poll, 800);
        });
    }

    async function analyzeProduct(event) {
        var trigger = event.currentTarget;
        var identityOnly = trigger.hasAttribute('data-analyze-identity');
        if (preparingImage) {
            status.textContent = '正在完成图片优化，请稍候...';
            await imagePreparation;
        }
        var file = preparedFile || (fileInput.files && fileInput.files[0]);
        var skuId = form.getAttribute('data-sku-id');
        if (!file && !skuId) {
            status.textContent = '请先选择商品图片';
            status.className = 'analyze-status error';
            return;
        }
        var payload = new FormData();
        payload.append('_token', form.querySelector('input[name="_token"]').value);
        if (file) {
            payload.append('source_image', file);
        }
        if (skuId) {
            payload.append('sku_id', skuId);
        }
        payload.append('creative_brief', creativeBriefInput.value || '');
        payload.append('name', nameInput.value || '');
        payload.append('category', categoryInput.value || '');
        payload.append('selling_points', sellingPointsInput.value || '');
        payload.append('product_description', productDescriptionInput.value || '');
        if (searchTermsInput) {
            payload.append('search_terms', searchTermsInput.value || '');
        }
        if (identityOnly) {
            payload.append('purpose', 'market_query');
        }
        var marketToggle = form.querySelector('[data-market-research-toggle]');
        var marketQuery = form.querySelector('input[name="market_query"]');
        if (!identityOnly && marketToggle && marketToggle.checked) {
            payload.append('use_market_research', '1');
            payload.append('market_query', marketQuery ? marketQuery.value : '');
        }
        trigger.disabled = true;
        trigger.classList.add('is-loading');
        form.setAttribute('aria-busy', 'true');
        status.textContent = identityOnly ? '正在检查图片并识别商品名称与搜索词...' : '正在进行图片安全检查，通过后自动识别并补全资料...';
        status.className = 'analyze-status';
        fetch(form.getAttribute('data-analyze-url'), {method: 'POST', body: payload, credentials: 'same-origin'})
            .then(function (response) {
                return response.text().then(function (body) {
                    var result = null;
                    try {
                        result = body ? JSON.parse(body) : null;
                    } catch (error) {
                        var message = response.status === 413
                            ? '图片文件过大，服务器未接收，请压缩后重试'
                            : response.status === 504
                            ? 'AI 识别处理超时，请稍后重试'
                            : '服务器返回异常（HTTP ' + response.status + '），请稍后重试';
                        throw new Error(message);
                    }
                    if (!response.ok) {
                        throw new Error(result && result.message ? result.message : '识别失败');
                    }
                    return result;
                });
            })
            .then(waitForVisionTask)
            .then(function (result) {
                if (!result.ok) {
                    throw new Error(result.message || '识别失败');
                }
                nameInput.value = result.product.name || '';
                categoryInput.value = result.product.category || '';
                if (!identityOnly) {
                    sellingPointsInput.value = (result.product.sellingPoints || []).join('\n');
                    productDescriptionInput.value = result.product.productDescription || '';
                }
                if (searchTermsInput) {
                    searchTermsInput.value = (result.product.searchTerms || []).join('\n');
                }
                var foundationPayload = form.querySelector('[data-foundation-payload]');
                if (foundationPayload) {
                    foundationPayload.value = JSON.stringify(result.product || {});
                }
                status.textContent = identityOnly ? '名称、类目和搜索词已填写，请确认后保存。' : (result.message || '识别完成，请确认后保存');
                status.className = 'analyze-status success';
                if (!identityOnly) {
                    form.dispatchEvent(new CustomEvent('sku:analyzed', {detail: result}));
                }
            })
            .catch(function (error) {
                status.textContent = error.message || '识别失败';
                status.className = 'analyze-status error';
            })
            .finally(function () {
                trigger.disabled = false;
                trigger.classList.remove('is-loading');
                form.removeAttribute('aria-busy');
            });
    }
    button.addEventListener('click', analyzeProduct);
    if (identityButton) {
        identityButton.addEventListener('click', analyzeProduct);
    }
}

function setupSubmitLoading() {
    document.querySelectorAll('form[data-submit-loading]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (event.defaultPrevented) {
                return;
            }
            if (form.getAttribute('data-submitting') === '1') {
                event.preventDefault();
                return;
            }
            var launchAction = form.querySelector('[data-launch-action]');
            if (launchAction && event.submitter && event.submitter.hasAttribute('data-launch-action-value')) {
                launchAction.value = event.submitter.getAttribute('data-launch-action-value') || 'full';
            }
            form.setAttribute('data-submitting', '1');
            var message = form.getAttribute('data-loading-message') || '正在提交，请稍候...';
            var stages = (form.getAttribute('data-loading-stages') || message).split('|').filter(Boolean);
            var submitters = form.querySelectorAll('button[type="submit"], input[type="submit"]');
            submitters.forEach(function (button) {
                button.disabled = true;
                button.classList.add('is-loading');
            });
            if (form.hasAttribute('data-background-submit')) {
                return;
            }

            var overlay = document.querySelector('[data-submit-overlay]');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.className = 'submit-overlay';
                overlay.setAttribute('data-submit-overlay', '');
                overlay.setAttribute('role', 'status');
                overlay.setAttribute('aria-live', 'polite');
                overlay.innerHTML = '<div class="submit-overlay-content"><span class="loading-spinner" aria-hidden="true"></span><strong data-submit-overlay-message></strong><div class="submit-progress-track" aria-hidden="true"><span></span></div><small>正在处理，请勿重复提交</small></div>';
                document.body.appendChild(overlay);
            }
            var messageOutput = overlay.querySelector('[data-submit-overlay-message]');
            var stageIndex = 0;
            messageOutput.textContent = stages[stageIndex] || message;
            window.setInterval(function () {
                if (!document.body.contains(overlay) || stageIndex >= stages.length - 1) {
                    return;
                }
                stageIndex++;
                messageOutput.textContent = stages[stageIndex];
            }, 2200);
            window.requestAnimationFrame(function () {
                overlay.classList.add('visible');
            });
        });
    });
}

function setupAssetManager() {
    var form = document.querySelector('[data-asset-bulk-form]');
    var master = document.querySelector('[data-select-all-assets]');
    var checkboxes = document.querySelectorAll('[data-asset-checkbox]');
    var output = document.querySelector('[data-selected-assets]');
    var actions = document.querySelectorAll('[data-bulk-action]');
    var toolbar = document.querySelector('[data-asset-bulk-toolbar]');
    var downloadButtons = document.querySelectorAll('[data-download-assets]');
    var assignTarget = document.querySelector('[data-asset-assign-target]');
    if (!form || !master || !checkboxes.length || !output) {
        return;
    }

    function updateSelection() {
        var selected = Array.prototype.filter.call(checkboxes, function (checkbox) { return checkbox.checked; }).length;
        output.textContent = '已选择 ' + selected + ' 张';
        if (toolbar) {
            toolbar.setAttribute('data-selection-count', String(selected));
            toolbar.classList.toggle('has-selection', selected > 0);
        }
        master.checked = selected === checkboxes.length;
        master.indeterminate = selected > 0 && selected < checkboxes.length;
        actions.forEach(function (button) {
            var needsTarget = button.hasAttribute('data-requires-assign-target');
            button.disabled = selected === 0 || (needsTarget && (!assignTarget || assignTarget.value === ''));
        });
    }

    master.addEventListener('change', function () {
        checkboxes.forEach(function (checkbox) { checkbox.checked = master.checked; });
        updateSelection();
    });
    checkboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', updateSelection);
    });
    if (assignTarget) {
        assignTarget.addEventListener('change', updateSelection);
    }
    document.querySelectorAll('[data-select-asset-group]').forEach(function (button) {
        button.addEventListener('click', function () {
            var group = button.closest('[data-asset-batch]') || button.closest('.asset-group');
            var groupCheckboxes = group ? group.querySelectorAll('[data-asset-checkbox]') : [];
            var shouldSelect = Array.prototype.some.call(groupCheckboxes, function (checkbox) { return !checkbox.checked; });
            groupCheckboxes.forEach(function (checkbox) { checkbox.checked = shouldSelect; });
            var label = button.querySelector('span');
            if (label) {
                label.textContent = shouldSelect ? '取消此组' : '全选此组';
            }
            updateSelection();
        });
    });

    function normalizedAssetIds(values) {
        var seen = {};
        return values.map(function (value) { return parseInt(value, 10); }).filter(function (value) {
            if (!value || seen[value]) {
                return false;
            }
            seen[value] = true;
            return true;
        });
    }

    function selectedAssetIds() {
        return normalizedAssetIds(Array.prototype.filter.call(checkboxes, function (checkbox) {
            return checkbox.checked;
        }).map(function (checkbox) {
            return checkbox.value;
        }));
    }

    downloadButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var values = button.getAttribute('data-download-scope') === 'selected'
                    ? selectedAssetIds()
                    : normalizedAssetIds((button.getAttribute('data-download-asset-ids') || '').split(','));
            if (values.length === 0) {
                output.textContent = '请至少选择一张素材';
                return;
            }
            if (values.length > 100) {
                output.textContent = '一次最多打包下载 100 张素材，请缩小选择范围';
                return;
            }
            var downloadForm = document.createElement('form');
            downloadForm.method = 'post';
            downloadForm.action = '/workspace/assets/download-zip';
            downloadForm.hidden = true;
            var fields = [['_token', form.querySelector('input[name="_token"]').value], ['organization', 'structured'], ['include_manifest', '1']];
            values.forEach(function (id) { fields.push(['asset_ids[]', String(id)]); });
            fields.forEach(function (field) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = field[0];
                input.value = field[1];
                downloadForm.appendChild(input);
            });
            document.body.appendChild(downloadForm);
            downloadForm.submit();
            downloadForm.remove();
            output.textContent = '正在打包下载 ' + values.length + ' 张素材...';
        });
    });
    updateSelection();
}

function setupAssetLibrary() {
    var drawer = document.querySelector('[data-asset-filter-drawer]');
    var openButtons = document.querySelectorAll('[data-open-asset-filter]');
    var closeButton = drawer ? drawer.querySelector('[data-close-asset-filter]') : null;
    var treeSearch = document.querySelector('[data-asset-tree-search]');
    var treeItems = document.querySelectorAll('[data-asset-tree-item]');
    var treeEmpty = document.querySelector('[data-asset-tree-empty]');
    var batchDialog = document.querySelector('[data-asset-batch-dialog]');

    if (treeSearch && treeItems.length) {
        treeSearch.addEventListener('input', function () {
            var keyword = treeSearch.value.trim().toLocaleLowerCase();
            var visible = 0;
            treeItems.forEach(function (item) {
                var matched = keyword === '' || (item.getAttribute('data-search-text') || '').indexOf(keyword) !== -1;
                item.hidden = !matched;
                if (matched) {
                    visible++;
                }
            });
            if (treeEmpty) {
                treeEmpty.hidden = visible !== 0;
            }
        });
    }

    if (batchDialog) {
        var batchKey = batchDialog.querySelector('[data-asset-batch-key]');
        var batchLabel = batchDialog.querySelector('[data-asset-batch-label]');
        var batchDefault = batchDialog.querySelector('[data-asset-batch-default]');
        document.querySelectorAll('[data-asset-batch-rename]').forEach(function (button) {
            button.addEventListener('click', function () {
                batchKey.value = button.getAttribute('data-batch-key') || '';
                batchLabel.value = button.getAttribute('data-batch-label') || '';
                batchDefault.textContent = '默认名称：' + (button.getAttribute('data-batch-default-label') || '商品素材生成');
                if (!batchDialog.open) {
                    batchDialog.showModal();
                }
                window.requestAnimationFrame(function () { batchLabel.focus(); });
            });
        });
        batchDialog.querySelectorAll('[data-close-asset-batch-dialog]').forEach(function (button) {
            button.addEventListener('click', function () { batchDialog.close(); });
        });
        batchDialog.addEventListener('click', function (event) {
            if (event.target === batchDialog) {
                batchDialog.close();
            }
        });
    }

    if (drawer) {
        var sourceChoices = drawer.querySelectorAll('input[name="source"]');
        var sourceFields = drawer.querySelectorAll('[data-asset-source-fields]');
        function updateSourceFields() {
            var selected = drawer.querySelector('input[name="source"]:checked');
            var source = selected ? selected.value : '';
            sourceFields.forEach(function (section) {
                var active = section.getAttribute('data-asset-source-fields') === source;
                section.hidden = !active;
                section.querySelectorAll('select,input,textarea').forEach(function (control) { control.disabled = !active; });
            });
        }
        sourceChoices.forEach(function (choice) { choice.addEventListener('change', updateSourceFields); });
        drawer.querySelectorAll('[data-asset-category-select]').forEach(function (categorySelect) {
            var section = categorySelect.closest('[data-asset-source-fields]');
            var target = section ? section.querySelector('select[name="sku_id"], select[name="detail_project_id"]') : null;
            if (!target) {
                return;
            }
            function filterAssociations() {
                var category = categorySelect.value;
                Array.prototype.forEach.call(target.options, function (option, index) {
                    if (index === 0) {
                        option.hidden = false;
                        return;
                    }
                    option.hidden = category !== '' && option.getAttribute('data-category') !== category;
                    if (option.hidden && option.selected) {
                        target.selectedIndex = 0;
                    }
                });
            }
            categorySelect.addEventListener('change', filterAssociations);
            filterAssociations();
        });
        updateSourceFields();
    }

    if (drawer && openButtons.length) {
        openButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (!drawer.open) {
                    drawer.showModal();
                }
            });
        });
        if (closeButton) {
            closeButton.addEventListener('click', function () { drawer.close(); });
        }
        drawer.addEventListener('click', function (event) {
            if (event.target === drawer) {
                drawer.close();
            }
        });
    }
}

function setupConfirmForms() {
    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var submitter = event.submitter || null;
            var message = (submitter && submitter.getAttribute('data-confirm-message')) || form.getAttribute('data-confirm-message');
            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    });
}

function setupDetailPageCreate() {
    var input = document.querySelector('[data-detail-source-input]');
    if (!input) {
        return;
    }
    var empty = document.querySelector('[data-detail-upload-empty]');
    var preview = document.querySelector('[data-detail-upload-preview]');
    var image = document.querySelector('[data-detail-source-preview]');
    var name = document.querySelector('[data-detail-source-name]');
    var objectUrl = '';
    var form = input.closest('form');
    var suggestButton = form ? form.querySelector('[data-ai-market-query]') : null;
    var queryInput = form ? form.querySelector('input[name="market_query"]') : null;
    var draftToken = form ? form.querySelector('[data-analysis-draft-token]') : null;
    var suggestStatus = form ? form.querySelector('[data-market-query-status]') : null;
    var suggestions = form ? form.querySelector('[data-market-term-suggestions]') : null;
    input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
            objectUrl = '';
        }
        if (!file) {
            empty.hidden = false;
            preview.hidden = true;
            return;
        }
        objectUrl = URL.createObjectURL(file);
        image.src = safeImageUrl(objectUrl);
        name.textContent = file.name;
        empty.hidden = true;
        preview.hidden = false;
        if (draftToken) {
            draftToken.value = '';
        }
    });
    if (suggestButton && queryInput && form) {
        suggestButton.addEventListener('click', function () {
            var file = input.files && input.files[0];
            if (!file) {
                suggestStatus.textContent = '请先上传商品实拍图。';
                suggestStatus.className = 'error-text';
                return;
            }
            var payload = new FormData();
            payload.append('_token', form.querySelector('input[name="_token"]').value);
            payload.append('source_image', file);
            payload.append('purpose', 'market_query');
            payload.append('creative_brief', (form.querySelector('[name="creative_brief"]') || {}).value || '');
            suggestButton.disabled = true;
            suggestButton.classList.add('is-loading');
            suggestStatus.textContent = '正在检查图片并识别商品名称与搜索词...';
            suggestStatus.className = '';
            fetch(form.getAttribute('data-market-suggest-url'), {method: 'POST', body: payload, credentials: 'same-origin'})
                .then(function (response) { return response.json().catch(function () { return {}; }).then(function (result) {
                    if (!response.ok || !result.ok) {
                        throw new Error(result.message || 'AI 识图失败');
                    }
                    return result;
                }); })
                .then(function (result) {
                    var terms = result.product && result.product.searchTerms ? result.product.searchTerms : [];
                    queryInput.value = terms[0] || (result.product ? result.product.name : '') || '';
                    draftToken.value = result.draftToken || '';
                    suggestions.innerHTML = '';
                    terms.forEach(function (term) {
                        var button = document.createElement('button');
                        button.type = 'button';
                        button.textContent = term;
                        button.addEventListener('click', function () { queryInput.value = term; });
                        suggestions.appendChild(button);
                    });
                    suggestions.hidden = terms.length === 0;
                    suggestStatus.textContent = result.message || '识别完成，请确认搜索词。';
                    suggestStatus.className = 'success-text';
                })
                .catch(function (error) {
                    suggestStatus.textContent = error.message || 'AI 识图失败，请稍后重试。';
                    suggestStatus.className = 'error-text';
                })
                .finally(function () {
                    suggestButton.disabled = false;
                    suggestButton.classList.remove('is-loading');
                });
        });
    }
}

function setupDetailPageChat() {
    var shell = document.querySelector('[data-detail-report]');
    var form = document.querySelector('[data-detail-chat-form]');
    if (!shell || !form) {
        return;
    }
    var messages = document.querySelector('[data-detail-chat-messages]');
    var status = document.querySelector('[data-detail-chat-status]');
    var textarea = form.querySelector('textarea[name="message"]');
    var button = form.querySelector('button[type="submit"]');
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var message = textarea.value.trim();
        if (message.length < 2 || button.disabled) {
            return;
        }
        button.disabled = true;
        button.classList.add('is-loading');
        status.textContent = 'AI 正在结合实拍图更新商品资料与详情页脚本...';
        status.className = 'detail-chat-status loading';
        fetch(shell.getAttribute('data-chat-url'), {method: 'POST', body: new FormData(form), credentials: 'same-origin'})
            .then(function (response) { return response.json().catch(function () { return {}; }).then(function (result) {
                if (!response.ok || !result.ok) {
                    throw new Error(result.message || '报告修订失败');
                }
                return result;
            }); })
            .then(function (result) {
                var userMessage = document.createElement('div');
                userMessage.className = 'detail-chat-message user';
                userMessage.innerHTML = '<strong>你</strong><p></p>';
                userMessage.querySelector('p').textContent = message;
                messages.appendChild(userMessage);
                var assistantMessage = document.createElement('div');
                assistantMessage.className = 'detail-chat-message assistant';
                assistantMessage.innerHTML = '<strong>AI 策划</strong><p></p>';
                assistantMessage.querySelector('p').textContent = result.message || '商品资料与详情页脚本已更新。';
                messages.appendChild(assistantMessage);
                messages.scrollTop = messages.scrollHeight;
                status.textContent = '报告已更新，正在刷新最新内容...';
                status.className = 'detail-chat-status success';
                textarea.value = '';
                window.setTimeout(function () { window.location.reload(); }, 700);
            })
            .catch(function (error) {
                status.textContent = error.message || '报告修订失败';
                status.className = 'detail-chat-status error';
                button.disabled = false;
                button.classList.remove('is-loading');
            });
    });
}

function setupJobProgress() {
    var rows = document.querySelectorAll('[data-job-id]');
    if (!rows.length) {
        return;
    }
    var activeStatuses = ['pending_gateway', 'queued', 'running'];
    var pollTimer = null;

    function updateRow(job) {
        var row = document.querySelector('[data-job-id="' + job.id + '"]');
        if (!row) {
            return;
        }
        var previousStatus = row.getAttribute('data-job-status-value') || '';
        row.setAttribute('data-job-status-value', job.status);
        var status = row.querySelector('[data-job-status]');
        if (status) {
            status.className = 'badge ' + job.status;
            status.textContent = job.statusLabel;
        }

        var total = Math.max(0, parseInt(job.progressTotal || 0, 10));
        var current = Math.min(total, Math.max(0, parseInt(job.progressCurrent || 0, 10)));
        var percent = total > 0 ? Math.floor(current * 100 / total) : 0;
        var count = row.querySelector('[data-job-progress-count]');
        var percentLabel = row.querySelector('[data-job-progress-percent]');
        var bar = row.querySelector('[data-job-progress-bar]');
        if (count) { count.textContent = current + '/' + total + ' 张'; }
        if (percentLabel) { percentLabel.textContent = percent + '%'; }
        if (bar) { bar.style.width = percent + '%'; }
        var track = row.querySelector('[role="progressbar"]');
        if (track) {
            track.setAttribute('aria-valuemax', String(total));
            track.setAttribute('aria-valuenow', String(current));
            track.classList.toggle('is-active', activeStatuses.indexOf(job.status) !== -1 && current < total);
            track.classList.toggle('is-retrying', job.progressStage === 'retrying');
        }
        var message = row.querySelector('[data-job-progress-message]');
        var actualCredits = row.querySelector('[data-job-actual-credits]');
        if (message) { message.textContent = job.progressMessage || ''; }
        if (actualCredits) { actualCredits.textContent = String(job.actualCredits || 0); }

        var retryForm = row.querySelector('[data-job-retry-form]');
        var cancelForm = row.querySelector('[data-job-cancel-form]');
        if (retryForm) { retryForm.hidden = !job.canRetry; }
        if (cancelForm) { cancelForm.hidden = !job.canCancel; }
        if (activeStatuses.indexOf(previousStatus) !== -1 && activeStatuses.indexOf(job.status) === -1) {
            window.setTimeout(function () { window.location.reload(); }, 500);
        }
    }

    function hasActiveJobs() {
        return Array.prototype.some.call(document.querySelectorAll('[data-job-status-value]'), function (row) {
            return activeStatuses.indexOf(row.getAttribute('data-job-status-value')) !== -1;
        });
    }

    function schedule() {
        window.clearTimeout(pollTimer);
        if (hasActiveJobs()) {
            pollTimer = window.setTimeout(poll, 3000);
        }
    }

    function poll() {
        fetch('/workspace/jobs/status', {credentials: 'same-origin', cache: 'no-store'})
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('任务状态刷新失败');
                }
                return response.json();
            })
            .then(function (result) {
                (result.jobs || []).forEach(updateRow);
            })
            .catch(function () {
                // Keep the current page state and retry on the next interval.
            })
            .finally(schedule);
    }

    schedule();
}

function setupUpstreamCostConfig() {
    var container = document.querySelector('[data-upstream-cost-config]');
    var mode = container ? container.querySelector('[data-upstream-cost-mode]') : null;
    if (!container || !mode) {
        return;
    }
    var paidFields = container.querySelectorAll('[data-upstream-paid-field]');
    function update() {
        var isPaid = mode.value === 'paid';
        Array.prototype.forEach.call(paidFields, function (field) {
            field.classList.toggle('is-muted', !isPaid);
            Array.prototype.forEach.call(field.querySelectorAll('input'), function (input) {
                input.readOnly = !isPaid;
            });
        });
    }
    mode.addEventListener('change', update);
    update();
}

function setupAiModelsCenter() {
    var tabs = document.querySelectorAll('[data-ai-tab]');
    var panels = document.querySelectorAll('[data-ai-tab-panel]');
    if (tabs.length && panels.length) {
        var validTabs = ['accounts', 'catalog', 'routing', 'observability', 'pricing', 'gateway', 'workflows', 'security', 'violations'];

        function readTabFromHash() {
            var hash = (window.location.hash || '').replace(/^#/, '').split('?')[0];
            return validTabs.indexOf(hash) !== -1 ? hash : 'catalog';
        }

        function selectTab(tabName, updateUrl) {
            if (validTabs.indexOf(tabName) === -1) {
                tabName = 'catalog';
            }
            Array.prototype.forEach.call(tabs, function (tab) {
                var active = tab.getAttribute('data-ai-tab') === tabName;
                tab.classList.toggle('active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
                tab.setAttribute('tabindex', active ? '0' : '-1');
            });
            Array.prototype.forEach.call(panels, function (panel) {
                var active = panel.getAttribute('data-ai-tab-panel') === tabName;
                panel.hidden = !active;
                panel.classList.toggle('active', active);
            });
            if (updateUrl && window.history && window.history.replaceState) {
                window.history.replaceState(null, '', window.location.pathname + window.location.search + '#' + tabName);
            }
        }

        Array.prototype.forEach.call(tabs, function (tab) {
            tab.addEventListener('click', function () {
                selectTab(tab.getAttribute('data-ai-tab'), true);
            });
            tab.addEventListener('keydown', function (event) {
                if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight' && event.key !== 'Home' && event.key !== 'End') {
                    return;
                }
                event.preventDefault();
                var index = Array.prototype.indexOf.call(tabs, tab);
                if (event.key === 'Home') {
                    index = 0;
                } else if (event.key === 'End') {
                    index = tabs.length - 1;
                } else if (event.key === 'ArrowRight') {
                    index = (index + 1) % tabs.length;
                } else {
                    index = (index - 1 + tabs.length) % tabs.length;
                }
                var nextTab = tabs[index];
                selectTab(nextTab.getAttribute('data-ai-tab'), true);
                nextTab.focus();
            });
        });
        window.addEventListener('hashchange', function () {
            selectTab(readTabFromHash(), false);
        });
        selectTab(readTabFromHash(), false);
    }

    var modelRows = document.querySelectorAll('[data-ai-model-row]');
    var modelSearch = document.querySelector('[data-ai-model-search]');
    var modelCount = document.querySelector('[data-ai-model-count]');
    var modelEmpty = document.querySelector('[data-ai-model-empty]');
    var modelFilters = {
        provider: document.querySelector('[data-ai-model-filter="provider"]'),
        capability: document.querySelector('[data-ai-model-filter="capability"]'),
        release: document.querySelector('[data-ai-model-filter="release"]'),
        enabled: document.querySelector('[data-ai-model-filter="enabled"]'),
        selectable: document.querySelector('[data-ai-model-filter="selectable"]'),
    };

    function filterModels() {
        if (!modelRows.length) {
            return;
        }
        var search = modelSearch ? modelSearch.value.trim().toLowerCase() : '';
        var visible = 0;
        Array.prototype.forEach.call(modelRows, function (row) {
            var matches = (!search || (row.getAttribute('data-model-search') || '').indexOf(search) !== -1)
                && (!modelFilters.provider || !modelFilters.provider.value || row.getAttribute('data-model-provider') === modelFilters.provider.value)
                && (!modelFilters.capability || !modelFilters.capability.value || (' ' + (row.getAttribute('data-model-capabilities') || '') + ' ').indexOf(' ' + modelFilters.capability.value + ' ') !== -1)
                && (!modelFilters.release || !modelFilters.release.value || row.getAttribute('data-model-release') === modelFilters.release.value)
                && (!modelFilters.enabled || !modelFilters.enabled.value || row.getAttribute('data-model-enabled') === modelFilters.enabled.value)
                && (!modelFilters.selectable || !modelFilters.selectable.value || row.getAttribute('data-model-selectable') === modelFilters.selectable.value);
            row.hidden = !matches;
            if (matches) {
                visible += 1;
            }
        });
        if (modelCount) {
            modelCount.textContent = visible + ' 个模型';
        }
        if (modelEmpty) {
            modelEmpty.hidden = visible !== 0;
        }
    }

    if (modelSearch) {
        modelSearch.addEventListener('input', filterModels);
    }
    Object.keys(modelFilters).forEach(function (key) {
        if (modelFilters[key]) {
            modelFilters[key].addEventListener('change', filterModels);
        }
    });
    filterModels();

    var workflowSettings = document.querySelector('[data-workflow-settings-form]');
    if (workflowSettings) {
        var comfyEnabled = workflowSettings.querySelector('[data-comfyui-enabled]');
        var comfyMode = workflowSettings.querySelector('[data-comfyui-api-mode]');
        var comfyKeyField = workflowSettings.querySelector('[data-comfyui-key-field]');
        var comfyFields = workflowSettings.querySelector('.ai-workflow-fields');
        function updateWorkflowSettings() {
            var enabled = comfyEnabled && comfyEnabled.checked;
            var cloud = comfyMode && comfyMode.value === 'cloud';
            if (comfyFields) {
                comfyFields.classList.toggle('is-disabled', !enabled);
            }
            if (comfyKeyField) {
                comfyKeyField.hidden = !cloud;
            }
        }
        if (comfyEnabled) {
            comfyEnabled.addEventListener('change', updateWorkflowSettings);
        }
        if (comfyMode) {
            comfyMode.addEventListener('change', updateWorkflowSettings);
        }
        updateWorkflowSettings();
    }

    var pricePolicies = document.querySelectorAll('[data-ai-price-policy]');
    Array.prototype.forEach.call(pricePolicies, function (policy) {
        var strategy = policy.querySelector('[data-ai-price-strategy]');
        var customValue = policy.querySelector('[data-ai-price-custom-value]');
        var creditsInput = customValue ? customValue.querySelector('input[type="number"]') : null;
        var summary = policy.querySelector('[data-ai-price-summary]');
        var unitLabel = policy.getAttribute('data-unit-label') || '次';
        var baseCredits = policy.getAttribute('data-base-credits') || '0';

        function updatePricePolicy() {
            var selected = strategy ? strategy.value : 'inherit';
            if (customValue) {
                customValue.hidden = selected !== 'custom';
            }
            if (creditsInput) {
                creditsInput.readOnly = selected !== 'custom';
                creditsInput.required = selected === 'custom';
            }
            if (summary) {
                if (selected === 'custom') {
                    summary.textContent = (creditsInput && creditsInput.value !== '' ? creditsInput.value : '0') + ' 点 / ' + unitLabel + ' · 模型专属价';
                } else if (selected === 'platform') {
                    summary.textContent = '0 点 / ' + unitLabel + ' · 平台承担';
                } else {
                    summary.textContent = baseCredits + ' 点 / ' + unitLabel + ' · 跟随基础价';
                }
            }
            policy.classList.toggle('is-custom', selected === 'custom');
            policy.classList.toggle('is-platform', selected === 'platform');
        }

        if (strategy) {
            strategy.addEventListener('change', updatePricePolicy);
        }
        if (creditsInput) {
            creditsInput.addEventListener('input', updatePricePolicy);
        }
        updatePricePolicy();
    });

    var routeForm = document.querySelector('[data-ai-route-form]');
    if (routeForm) {
        var routeInputs = routeForm.querySelectorAll('select[name^="routes["], input[name^="routes["], select[name="execution_mode"], input[name="native_capabilities[]"]');
        var grayMerchantSelect = routeForm.querySelector('select[name="gray_merchant_ids[]"]');
        var routingMode = routeForm.querySelector('[data-ai-routing-mode]');
        var executionMode = routeForm.querySelector('[data-ai-execution-mode]');
        var nativeCapabilities = routeForm.querySelectorAll('[data-ai-native-capability]');
        var executionNote = routeForm.querySelector('[data-ai-execution-note] span');
        var savebar = routeForm.querySelector('[data-ai-route-savebar]');
        var dirty = routeForm.querySelector('[data-ai-route-dirty]');
        var dirtyText = routeForm.querySelector('[data-ai-route-dirty-text]');
        var modeLabel = document.querySelector('[data-ai-route-mode-label]');
        var modeCopy = routeForm.querySelector('[data-ai-route-mode-copy]');
        var warning = routeForm.querySelector('[data-ai-route-warning]');
        function routeState() {
            return Array.prototype.map.call(routeInputs, function (input) {
                var value = input.type === 'checkbox' || input.type === 'radio'
                    ? (input.checked ? input.value : '')
                    : input.value;
                return input.name + '=' + value;
            }).join('&');
        }
        function syncModelPool(group) {
            var choices = group.querySelectorAll('[data-ai-pool-choice]');
            var defaultSelect = group.querySelector('[data-ai-pool-default]');
            var selectedIds = [];
            Array.prototype.forEach.call(choices, function (choice) {
                choice.closest('.ai-pool-option').classList.toggle('selected', choice.checked);
                if (choice.checked) {
                    selectedIds.push(choice.value);
                }
            });
            if (defaultSelect) {
                Array.prototype.forEach.call(defaultSelect.options, function (option) {
                    if (option.value !== '0') {
                        option.disabled = selectedIds.indexOf(option.value) === -1;
                    }
                });
                if (selectedIds.indexOf(defaultSelect.value) === -1) {
                    defaultSelect.value = selectedIds[0] || '0';
                }
            }
            var count = group.querySelector('.ai-pool-group-head > span');
            if (count) {
                count.textContent = selectedIds.length + ' 个已开放';
            }
        }
        var poolGroups = routeForm.querySelectorAll('[data-ai-model-pool]');
        Array.prototype.forEach.call(poolGroups, function (group) {
            syncModelPool(group);
            group.addEventListener('change', function () {
                syncModelPool(group);
                updateRouteDirty();
            });
        });
        var initialRouteValues = routeState();
        var initialMode = routingMode ? routingMode.value : '';
        var initialGrayMerchants = grayMerchantSelect
            ? Array.prototype.map.call(grayMerchantSelect.selectedOptions, function (option) { return option.value; }).join(',')
            : '';

        function updateRouteMode() {
            var capability = routingMode && routingMode.value === 'capability';
            if (warning) {
                warning.hidden = !capability;
            }
            if (modeLabel) {
                modeLabel.textContent = capability ? '能力路由已启用' : '默认路由';
                modeLabel.classList.toggle('active', capability);
                modeLabel.classList.toggle('pending', !capability);
            }
            if (modeCopy) {
                modeCopy.textContent = capability
                    ? '图片创作模型池独立生效；识别、策划和审核也按下方主备路由执行。'
                    : '图片创作模型池独立生效；识别、策划和审核继续使用当前默认模型。';
            }
        }

        function updateExecutionMode() {
            var mode = executionMode ? executionMode.value : 'hybrid';
            if (mode === 'sub2api') {
                mode = 'hybrid';
            }
            Array.prototype.forEach.call(nativeCapabilities, function (input) {
                if (mode === 'native') {
                    input.checked = true;
                    input.disabled = true;
                } else {
                    input.disabled = false;
                }
                var label = input.closest('label');
                if (label) {
                    label.classList.toggle('selected', input.checked);
                    var state = label.querySelector('[data-ai-native-state]');
                    if (state) {
                        state.textContent = input.checked ? '账号池执行' : '当前默认路由';
                    }
                }
            });
            if (executionNote) {
                executionNote.textContent = mode === 'native'
                    ? '平台内部能力全部由 ThinkPHP 渠道账号池执行；不可用时按该能力的备用模型和账号继续调度。'
                    : '勾选的能力使用 ThinkPHP 渠道账号池；未勾选能力继续使用当前默认路由，便于逐项验证后切换。';
            }
        }

        function updateRouteDirty() {
            var changed = (routingMode ? routingMode.value : '') !== initialMode;
            if (routeState() !== initialRouteValues) {
                changed = true;
            }
            if (grayMerchantSelect) {
                var currentGrayMerchants = Array.prototype.map.call(grayMerchantSelect.selectedOptions, function (option) { return option.value; }).join(',');
                if (currentGrayMerchants !== initialGrayMerchants) {
                    changed = true;
                }
            }
            if (savebar) {
                savebar.classList.toggle('has-dirty', changed);
            }
            if (dirty) {
                dirty.classList.toggle('is-dirty', changed);
                dirty.setAttribute('aria-live', 'polite');
            }
            if (dirtyText) {
                dirtyText.textContent = changed ? '有未保存的路由调整' : '当前路由已保存';
            }
            return changed;
        }

        if (routingMode) {
            routingMode.addEventListener('change', function () {
                updateRouteMode();
                updateRouteDirty();
            });
        }
        if (executionMode) {
            executionMode.addEventListener('change', function () {
                updateExecutionMode();
                updateRouteDirty();
            });
        }
        Array.prototype.forEach.call(nativeCapabilities, function (input) {
            input.addEventListener('change', function () {
                updateExecutionMode();
                updateRouteDirty();
            });
        });
        if (grayMerchantSelect) {
            grayMerchantSelect.addEventListener('change', updateRouteDirty);
        }
        Array.prototype.forEach.call(routeInputs, function (input) {
            input.addEventListener('change', updateRouteDirty);
        });
        routeForm.addEventListener('submit', function () {
            initialMode = routingMode ? routingMode.value : '';
            initialRouteValues = routeState();
            updateRouteDirty();
        });
        window.addEventListener('beforeunload', function (event) {
            if (updateRouteDirty()) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
        updateRouteMode();
        updateExecutionMode();
        updateRouteDirty();
    }

    var violationRows = document.querySelectorAll('[data-ai-violation-row]');
    var violationCount = document.querySelector('[data-ai-violation-count]');
    var violationEmpty = document.querySelector('[data-ai-violation-empty]');
    var violationFilters = {
        scene: document.querySelector('[data-ai-violation-filter="scene"]'),
        type: document.querySelector('[data-ai-violation-filter="type"]'),
        mail: document.querySelector('[data-ai-violation-filter="mail"]'),
    };

    function filterViolations() {
        var visible = 0;
        Array.prototype.forEach.call(violationRows, function (row) {
            var matches = (!violationFilters.scene || !violationFilters.scene.value || row.getAttribute('data-violation-scene') === violationFilters.scene.value)
                && (!violationFilters.type || !violationFilters.type.value || row.getAttribute('data-violation-type') === violationFilters.type.value)
                && (!violationFilters.mail || !violationFilters.mail.value || row.getAttribute('data-violation-mail') === violationFilters.mail.value);
            row.hidden = !matches;
            if (matches) {
                visible += 1;
            }
        });
        if (violationCount) {
            violationCount.textContent = visible + ' 条记录';
        }
        if (violationEmpty) {
            violationEmpty.hidden = visible !== 0;
        }
    }

    Object.keys(violationFilters).forEach(function (key) {
        if (violationFilters[key]) {
            violationFilters[key].addEventListener('change', filterViolations);
        }
    });
    filterViolations();
}

function setupAiModelRelease() {
    var form = document.querySelector('[data-ai-model-release-form]');
    if (!form) {
        return;
    }
    var radios = form.querySelectorAll('input[name="release_stage"]');
    var grayMerchants = form.querySelector('[data-ai-release-gray-merchants]');
    var graySelect = grayMerchants ? grayMerchants.querySelector('select') : null;
    var help = form.querySelector('[data-ai-release-help]');
    var descriptions = {
        candidate: '候选模型仅供管理员配置与实测，不接收真实业务请求。',
        gray: '灰度模型只对指定体验商户开放；内部能力按灰度主路由执行，其他商户继续使用生产备用模型。',
        active: '生产模型进入正式路由；创作模型可按套餐开放并设为模型池默认项。',
    };

    function updateReleaseForm() {
        var checked = form.querySelector('input[name="release_stage"]:checked');
        var stage = checked ? checked.value : 'candidate';
        var isGray = stage === 'gray';
        if (grayMerchants) {
            grayMerchants.hidden = !isGray;
        }
        if (graySelect) {
            graySelect.disabled = !isGray;
            graySelect.required = isGray;
        }
        if (help) {
            help.textContent = descriptions[stage] || descriptions.candidate;
        }
        form.setAttribute('data-release-stage', stage);
    }

    Array.prototype.forEach.call(radios, function (radio) {
        radio.addEventListener('change', updateReleaseForm);
    });
    updateReleaseForm();
}

function setupProviderAccountForm() {
    var form = document.querySelector('[data-provider-account-form]');
    if (!form) {
        return;
    }
    var authType = form.querySelector('[data-provider-auth-type]');
    var channelSelect = form.querySelector('[data-provider-channel-select]');
    var apiKeyFields = form.querySelectorAll('[data-provider-credential="api-key"]');
    var akSkFields = form.querySelectorAll('[data-provider-credential="ak-sk"]');

    function toggleCredentials() {
        var selectedChannel = channelSelect && channelSelect.options[channelSelect.selectedIndex];
        var selectedAuthType = selectedChannel
            ? selectedChannel.getAttribute('data-auth-type')
            : '';
        var usesAkSk = (selectedAuthType || (authType ? authType.value : '')) === 'volc_ak_sk';
        Array.prototype.forEach.call(apiKeyFields, function (field) {
            field.hidden = usesAkSk;
            Array.prototype.forEach.call(field.querySelectorAll('input'), function (input) {
                input.disabled = usesAkSk;
            });
        });
        Array.prototype.forEach.call(akSkFields, function (field) {
            field.hidden = !usesAkSk;
            Array.prototype.forEach.call(field.querySelectorAll('input'), function (input) {
                input.disabled = !usesAkSk;
            });
        });
    }

    if (authType) {
        authType.addEventListener('change', toggleCredentials);
    }
    if (channelSelect) {
        channelSelect.addEventListener('change', toggleCredentials);
    }
    toggleCredentials();
}
