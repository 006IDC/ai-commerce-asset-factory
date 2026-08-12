(function () {
    'use strict';

    var DRAFT_KEY = 'asset-factory-product-launch-v1';
    var MAX_REFERENCES = 6;
    var MAX_REFERENCE_BYTES = 24 * 1024 * 1024;
    var ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    function createIcons() {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons({attrs: {'aria-hidden': 'true', 'stroke-width': 1.8}});
        }
    }

    function setupMobileMenu() {
        var menu = document.querySelector('[data-public-mobile-menu]');
        if (!menu) {
            return;
        }
        menu.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                menu.open = false;
            });
        });
        document.addEventListener('click', function (event) {
            if (menu.open && !menu.contains(event.target)) {
                menu.open = false;
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && menu.open) {
                menu.open = false;
                var summary = menu.querySelector('summary');
                if (summary) {
                    summary.focus();
                }
            }
        });
    }

    function setupGuestCreation() {
        var form = document.querySelector('[data-guest-creation-console]');
        if (!form) {
            return;
        }
        var input = form.querySelector('[data-guest-image-input]');
        var uploadZone = form.querySelector('[data-guest-upload-zone]');
        var empty = form.querySelector('[data-guest-upload-empty]');
        var previews = form.querySelector('[data-guest-upload-previews]');
        var brief = form.querySelector('[data-guest-brief]');
        var count = form.querySelector('[data-guest-brief-count]');
        var plan = form.querySelector('[data-guest-plan]');
        var platform = form.querySelector('[data-guest-platform]');
        var language = form.querySelector('[data-guest-language]');
        var submit = form.querySelector('[data-guest-submit]');
        var message = form.querySelector('[data-guest-message]');
        var localHelper = form.querySelector('[data-guest-local-helper]');
        var selectedFiles = [];
        var previewUrls = [];
        var helperIndex = 0;
        var helperExamples = [
            '面向注重品质的年轻用户，画面简洁高级，优先突出商品外观、材质细节和日常使用价值。',
            '面向家庭用户，使用真实生活场景，重点说明核心功能与使用便利，文案自然可信。',
            '面向海外用户，整体明亮克制，突出商品结构、包装与送礼感，避免未经确认的参数和功效。',
        ];

        function setMessage(text, error) {
            message.textContent = text;
            message.classList.toggle('error', Boolean(error));
        }

        function signature(file) {
            return [file.name, file.size, file.lastModified].join(':');
        }

        function releasePreviewUrls() {
            previewUrls.forEach(function (url) {
                URL.revokeObjectURL(url);
            });
            previewUrls = [];
        }

        function updateState() {
            count.textContent = String(brief.value.length);
            submit.disabled = selectedFiles.length === 0;
            if (!submit.disabled) {
                setMessage('已选择 ' + selectedFiles.length + ' 张商品图。注册后重新选择图片，系统将直接开始整理商品资料。', false);
            } else {
                setMessage('请先添加至少一张清晰的商品图片。', false);
            }
        }

        function syncInput() {
            if (typeof DataTransfer !== 'function') {
                return;
            }
            var transfer = new DataTransfer();
            selectedFiles.forEach(function (file) {
                transfer.items.add(file);
            });
            input.files = transfer.files;
        }

        function moveFile(from, to) {
            if (to < 0 || to >= selectedFiles.length || from === to) {
                return;
            }
            var moved = selectedFiles.splice(from, 1)[0];
            selectedFiles.splice(to, 0, moved);
            syncInput();
            renderPreviews();
        }

        function renderPreviews() {
            releasePreviewUrls();
            previews.innerHTML = '';
            selectedFiles.forEach(function (file, index) {
                var item = document.createElement('div');
                item.className = 'landing-reference-item';
                var image = document.createElement('img');
                var url = URL.createObjectURL(file);
                previewUrls.push(url);
                image.src = url;
                image.alt = '商品图片 ' + (index + 1);

                var label = document.createElement('span');
                label.textContent = index === 0 ? '主参考' : String(index + 1);

                var actions = document.createElement('div');
                actions.className = 'landing-reference-actions';
                var moveLeft = document.createElement('button');
                moveLeft.type = 'button';
                moveLeft.disabled = index === 0;
                moveLeft.setAttribute('aria-label', '向前移动参考图 ' + (index + 1));
                moveLeft.innerHTML = '<i data-lucide="arrow-left"></i>';
                moveLeft.addEventListener('click', function () {
                    moveFile(index, index - 1);
                });
                var moveRight = document.createElement('button');
                moveRight.type = 'button';
                moveRight.disabled = index === selectedFiles.length - 1;
                moveRight.setAttribute('aria-label', '向后移动参考图 ' + (index + 1));
                moveRight.innerHTML = '<i data-lucide="arrow-right"></i>';
                moveRight.addEventListener('click', function () {
                    moveFile(index, index + 1);
                });
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.setAttribute('aria-label', '移除参考图 ' + (index + 1));
                remove.innerHTML = '<i data-lucide="x"></i>';
                remove.addEventListener('click', function () {
                    selectedFiles.splice(index, 1);
                    syncInput();
                    renderPreviews();
                });
                actions.appendChild(moveLeft);
                actions.appendChild(moveRight);
                actions.appendChild(remove);
                item.appendChild(image);
                item.appendChild(label);
                item.appendChild(actions);
                previews.appendChild(item);
            });
            previews.hidden = selectedFiles.length === 0;
            empty.hidden = selectedFiles.length > 0;
            createIcons();
            updateState();
        }

        function addFiles(fileList) {
            var incoming = Array.prototype.slice.call(fileList || []);
            var invalid = false;
            var duplicate = false;
            var limited = false;
            incoming.forEach(function (file) {
                if (ALLOWED_TYPES.indexOf(file.type) === -1 || file.size > MAX_REFERENCE_BYTES) {
                    invalid = true;
                    return;
                }
                if (selectedFiles.some(function (current) { return signature(current) === signature(file); })) {
                    duplicate = true;
                    return;
                }
                if (selectedFiles.length >= MAX_REFERENCES) {
                    limited = true;
                    return;
                }
                selectedFiles.push(file);
            });
            syncInput();
            renderPreviews();
            if (invalid) {
                setMessage('商品图仅支持 JPEG、PNG、WebP，且单张不能超过 24MB。', true);
            } else if (limited) {
                setMessage('单次最多保留 6 张商品图。', true);
            } else if (duplicate) {
                setMessage('重复商品图已自动忽略。', false);
            }
        }

        input.addEventListener('change', function () {
            addFiles(input.files);
        });
        brief.addEventListener('input', updateState);
        form.addEventListener('paste', function (event) {
            var files = Array.prototype.slice.call((event.clipboardData && event.clipboardData.files) || [])
                .filter(function (file) { return String(file.type || '').indexOf('image/') === 0; });
            if (files.length) {
                event.preventDefault();
                addFiles(files);
            }
        });
        ['dragenter', 'dragover'].forEach(function (name) {
            uploadZone.addEventListener(name, function (event) {
                event.preventDefault();
                form.classList.add('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function (name) {
            uploadZone.addEventListener(name, function (event) {
                event.preventDefault();
                form.classList.remove('is-dragover');
                if (name === 'drop' && event.dataTransfer) {
                    addFiles(event.dataTransfer.files);
                }
            });
        });

        document.querySelectorAll('[data-guest-example]').forEach(function (button) {
            button.addEventListener('click', function () {
                brief.value = button.getAttribute('data-guest-example') || '';
                updateState();
                brief.focus();
            });
        });
        localHelper.addEventListener('click', function () {
            brief.value = helperExamples[helperIndex % helperExamples.length];
            helperIndex += 1;
            updateState();
            setMessage('已补充一份创作示例，你可以继续修改。', false);
            brief.focus();
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (selectedFiles.length === 0) {
                updateState();
                return;
            }
            var draft = {
                schemaVersion: 1,
                creativeBrief: brief.value.slice(0, 1200),
                launchPlan: plan.value,
                platform: platform.value,
                language: language.value,
                referenceNames: selectedFiles.map(function (file) { return file.name.slice(0, 180); }),
                savedAt: new Date().toISOString(),
                source: 'landing_product_launch'
            };
            try {
                window.sessionStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
            } catch (error) {
                setMessage('浏览器无法保存创作草稿，请允许会话存储后再试。', true);
                return;
            }
            releasePreviewUrls();
            window.location.assign('/register?return_to=%2Fworkspace%2Fgenerate&source=landing_product_launch');
        });

        window.addEventListener('pagehide', releasePreviewUrls);
        updateState();
    }

    function setupReleaseDrawer() {
        var drawer = document.querySelector('[data-release-drawer]');
        if (!drawer || typeof drawer.showModal !== 'function') {
            return;
        }
        var openers = document.querySelectorAll('[data-release-drawer-open]');
        var closeButtons = drawer.querySelectorAll('[data-release-drawer-close]');
        var filters = drawer.querySelectorAll('[data-release-filter]');
        var releases = drawer.querySelectorAll('[data-release-category]');
        var previousFocus = null;

        function focusable() {
            return Array.prototype.slice.call(drawer.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )).filter(function (element) {
                return !element.hidden && element.offsetParent !== null;
            });
        }

        function closeDrawer() {
            if (drawer.open) {
                drawer.close();
            }
        }

        function openDrawer(event) {
            event.preventDefault();
            previousFocus = event.currentTarget;
            drawer.showModal();
            document.body.classList.add('release-drawer-open');
            var close = drawer.querySelector('[data-release-drawer-close]');
            if (close) {
                close.focus();
            }
        }

        openers.forEach(function (opener) {
            opener.addEventListener('click', openDrawer);
        });
        closeButtons.forEach(function (button) {
            button.addEventListener('click', closeDrawer);
        });
        drawer.addEventListener('click', function (event) {
            if (event.target === drawer) {
                closeDrawer();
            }
        });
        drawer.addEventListener('close', function () {
            document.body.classList.remove('release-drawer-open');
            if (previousFocus && typeof previousFocus.focus === 'function') {
                previousFocus.focus();
            }
            previousFocus = null;
        });
        drawer.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeDrawer();
                return;
            }
            if (event.key !== 'Tab') {
                return;
            }
            var items = focusable();
            if (!items.length) {
                event.preventDefault();
                return;
            }
            var first = items[0];
            var last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });

        filters.forEach(function (button) {
            button.addEventListener('click', function () {
                var filter = button.getAttribute('data-release-filter') || 'all';
                filters.forEach(function (item) {
                    item.setAttribute('aria-pressed', item === button ? 'true' : 'false');
                });
                releases.forEach(function (release) {
                    release.hidden = filter === 'milestones'
                        ? release.getAttribute('data-release-milestone') !== '1'
                        : filter !== 'all' && release.getAttribute('data-release-category') !== filter;
                });
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        createIcons();
        setupMobileMenu();
        setupGuestCreation();
        setupReleaseDrawer();
    });
}());
