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

    function safeAssetLinkUrl(value) {
        if (!String(value || '').trim()) return '#';
        try {
            var url = new URL(String(value || ''), window.location.href);
            if (url.protocol === 'http:' || url.protocol === 'https:') return url.href;
        } catch (error) {
            // Opening an asset must never execute a script or an active data document.
        }
        return '#';
    }

    function setupProgressiveOptions() {
        document.querySelectorAll('[data-progressive-options]').forEach(function (details) {
            var summaryText = details.querySelector('[data-option-summary]');
            var form = details.closest('form');
            if (!summaryText || !form) {
                return;
            }
            var selectors = Array.prototype.slice.call(details.querySelectorAll('select[data-summary-label], input[data-summary-label]'));
            function update() {
                var labels = selectors.map(function (control) {
                    if (control.type === 'checkbox') {
                        return control.checked ? control.getAttribute('data-summary-label') : '';
                    }
                    var option = control.options && control.options[control.selectedIndex];
                    return option ? option.textContent.trim().split(' · ')[0] : '';
                }).filter(Boolean);
                summaryText.textContent = labels.join(' · ');
            }
            selectors.forEach(function (control) {
                control.addEventListener('change', update);
            });
            update();
        });
    }

    function setupSkuViewToggle() {
        var catalog = document.querySelector('[data-sku-catalog]');
        var toggles = document.querySelectorAll('[data-sku-view]');
        if (!catalog || !toggles.length) {
            return;
        }
        var selected = window.innerWidth <= 620 ? 'list' : 'grid';
        try {
            selected = window.localStorage.getItem('asset-factory-sku-view') || selected;
        } catch (error) {
            selected = window.innerWidth <= 620 ? 'list' : 'grid';
        }
        function apply(mode) {
            mode = mode === 'list' ? 'list' : 'grid';
            catalog.setAttribute('data-view', mode);
            toggles.forEach(function (button) {
                var active = button.getAttribute('data-sku-view') === mode;
                button.classList.toggle('active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            try {
                window.localStorage.setItem('asset-factory-sku-view', mode);
            } catch (error) {
                // View preference is non-essential.
            }
        }
        toggles.forEach(function (button) {
            button.addEventListener('click', function () {
                apply(button.getAttribute('data-sku-view') || 'grid');
            });
        });
        apply(selected);
    }

    function setupAssetDetails() {
        var panel = document.querySelector('[data-asset-detail-panel]');
        if (!panel) {
            return;
        }
        var image = panel.querySelector('[data-asset-detail-image]');
        var title = panel.querySelector('[data-asset-detail-title]');
        var meta = panel.querySelector('[data-asset-detail-meta]');
        var openLink = panel.querySelector('[data-asset-detail-open]');
        var downloadLink = panel.querySelector('[data-asset-detail-download]');
        var close = panel.querySelector('[data-asset-detail-close]');
        document.querySelectorAll('[data-asset-detail]').forEach(function (button) {
            button.addEventListener('click', function () {
                var src = button.getAttribute('data-asset-detail-src') || '';
                var fallback = button.getAttribute('data-asset-detail-fallback') || '';
                image.src = safeImageUrl(src);
                image.setAttribute('data-fallback', fallback);
                title.textContent = button.getAttribute('data-asset-detail-title') || '素材详情';
                meta.textContent = button.getAttribute('data-asset-detail-meta') || '';
                openLink.href = safeAssetLinkUrl(src);
                downloadLink.href = safeAssetLinkUrl(button.getAttribute('data-asset-detail-download-url') || src);
                panel.hidden = false;
                panel.classList.add('open');
                close.focus();
            });
        });
        close.addEventListener('click', function () {
            panel.classList.remove('open');
            panel.hidden = true;
        });
    }

    function setupActionableErrors() {
        document.querySelectorAll('[data-error-guidance]').forEach(function (container) {
            var toggle = container.querySelector('[data-error-guidance-toggle]');
            var body = container.querySelector('[data-error-guidance-body]');
            if (!toggle || !body) {
                return;
            }
            toggle.addEventListener('click', function () {
                var hidden = body.hidden;
                body.hidden = !hidden;
                toggle.setAttribute('aria-expanded', hidden ? 'true' : 'false');
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        setupProgressiveOptions();
        setupSkuViewToggle();
        setupAssetDetails();
        setupActionableErrors();
    });
}());
