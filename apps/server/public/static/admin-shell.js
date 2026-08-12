(function () {
    'use strict';

    var shell = document.querySelector('.admin-shell');
    if (!shell) return;

    var backdrop = document.querySelector('[data-admin-shell-backdrop]');
    var mobileButton = document.querySelector('[data-admin-mobile-menu]');
    var mobileClose = document.querySelector('[data-admin-mobile-close]');
    var mobileDrawer = document.querySelector('[data-admin-mobile-drawer]');
    var navigationGroups = Array.prototype.slice.call(document.querySelectorAll('[data-admin-nav-group]'));
    var previousFocus = null;
    var hoverOpenTimer = null;
    var hoverCloseTimer = null;
    var desktopHover = window.matchMedia('(hover: hover) and (pointer: fine)');

    function clearNavigationTimers() {
        window.clearTimeout(hoverOpenTimer);
        window.clearTimeout(hoverCloseTimer);
        hoverOpenTimer = null;
        hoverCloseTimer = null;
    }

    function openNavigationGroup(group) {
        clearNavigationTimers();
        closeNavigationGroups(group);
        group.open = true;
    }

    function closeNavigationGroups(except) {
        navigationGroups.forEach(function (group) {
            if (group !== except) group.open = false;
        });
    }

    function closeMobile() {
        shell.classList.remove('is-mobile-open');
        document.body.classList.remove('admin-mobile-navigation-open');
        if (backdrop) backdrop.hidden = true;
        if (mobileButton) mobileButton.setAttribute('aria-expanded', 'false');
        if (mobileDrawer) mobileDrawer.setAttribute('aria-hidden', 'true');
        if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
        previousFocus = null;
    }

    function openMobile() {
        previousFocus = document.activeElement;
        closeNavigationGroups();
        shell.classList.add('is-mobile-open');
        document.body.classList.add('admin-mobile-navigation-open');
        if (backdrop) backdrop.hidden = false;
        if (mobileButton) mobileButton.setAttribute('aria-expanded', 'true');
        if (mobileDrawer) mobileDrawer.setAttribute('aria-hidden', 'false');
        if (mobileClose) mobileClose.focus();
    }

    navigationGroups.forEach(function (group) {
        var summary = group.querySelector('summary');
        group.addEventListener('toggle', function () {
            if (group.open) closeNavigationGroups(group);
        });
        group.addEventListener('mouseenter', function () {
            if (!desktopHover.matches) return;
            window.clearTimeout(hoverCloseTimer);
            hoverOpenTimer = window.setTimeout(function () {
                openNavigationGroup(group);
            }, 100);
        });
        group.addEventListener('mouseleave', function () {
            if (!desktopHover.matches) return;
            window.clearTimeout(hoverOpenTimer);
            hoverCloseTimer = window.setTimeout(function () {
                if (!group.contains(document.activeElement)) group.open = false;
            }, 200);
        });
        group.addEventListener('focusin', function () {
            window.clearTimeout(hoverCloseTimer);
        });
        group.addEventListener('focusout', function (event) {
            if (group.contains(event.relatedTarget)) return;
            hoverCloseTimer = window.setTimeout(function () {
                group.open = false;
            }, 200);
        });
        if (summary) {
            summary.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    group.open ? (group.open = false) : openNavigationGroup(group);
                }
                if (event.key === 'Escape') {
                    event.preventDefault();
                    group.open = false;
                    summary.focus();
                }
            });
        }
    });

    if (mobileButton) mobileButton.addEventListener('click', openMobile);
    if (mobileClose) mobileClose.addEventListener('click', closeMobile);
    if (backdrop) backdrop.addEventListener('click', closeMobile);
    if (mobileDrawer) {
        mobileDrawer.addEventListener('click', function (event) {
            if (event.target.closest('a')) closeMobile();
        });
    }

    var taskReminder = document.querySelector('[data-admin-task-reminder]');
    var taskReminderToggle = taskReminder ? taskReminder.querySelector('[data-admin-task-reminder-toggle]') : null;
    var taskReminderPanel = taskReminder ? taskReminder.querySelector('[data-admin-task-reminder-panel]') : null;
    var taskReminderClose = taskReminder ? taskReminder.querySelector('[data-admin-task-reminder-close]') : null;
    var taskReminderTitle = taskReminder ? taskReminder.querySelector('#admin-task-reminder-title') : null;
    var taskReminderPreviousFocus = null;
    var accountMenu = document.querySelector('.admin-account-menu');
    var accountSummary = accountMenu ? accountMenu.querySelector(':scope > summary') : null;

    function setTaskReminderOpen(open, restoreFocus) {
        if (!taskReminder || !taskReminderToggle || !taskReminderPanel) return;
        if (open) {
            closeNavigationGroups();
            if (accountMenu) accountMenu.open = false;
            taskReminderPreviousFocus = document.activeElement;
            taskReminder.classList.add('is-open');
            taskReminderPanel.hidden = false;
            taskReminderToggle.setAttribute('aria-expanded', 'true');
            window.requestAnimationFrame(function () {
                if (taskReminderTitle) taskReminderTitle.focus();
                else if (taskReminderClose) taskReminderClose.focus();
            });
            return;
        }
        taskReminder.classList.remove('is-open');
        taskReminderPanel.hidden = true;
        taskReminderToggle.setAttribute('aria-expanded', 'false');
        if (restoreFocus && taskReminderPreviousFocus && typeof taskReminderPreviousFocus.focus === 'function') {
            taskReminderPreviousFocus.focus();
        }
        taskReminderPreviousFocus = null;
    }

    function closeAccountMenu(restoreFocus) {
        if (!accountMenu || !accountMenu.open) return;
        accountMenu.open = false;
        if (restoreFocus && accountSummary) accountSummary.focus();
    }

    if (taskReminderToggle) {
        taskReminderToggle.addEventListener('click', function () {
            setTaskReminderOpen(!taskReminder.classList.contains('is-open'), true);
        });
    }
    if (taskReminderClose) {
        taskReminderClose.addEventListener('click', function () {
            setTaskReminderOpen(false, true);
        });
    }
    if (accountMenu && accountSummary) {
        accountMenu.addEventListener('toggle', function () {
            accountSummary.setAttribute('aria-expanded', accountMenu.open ? 'true' : 'false');
            if (accountMenu.open) setTaskReminderOpen(false, false);
        });
    }

    document.addEventListener('pointerdown', function (event) {
        if (taskReminder && taskReminder.classList.contains('is-open') && !taskReminder.contains(event.target)) {
            setTaskReminderOpen(false, false);
        }
        if (accountMenu && accountMenu.open && !accountMenu.contains(event.target)) {
            closeAccountMenu(false);
        }
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('[data-admin-nav-group]')) closeNavigationGroups();
    });
    document.addEventListener('keydown', function (event) {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            window.location.href = '/admin/object-search';
        }
        if (event.key === 'Escape') {
            if (taskReminder && taskReminder.classList.contains('is-open')) {
                event.preventDefault();
                setTaskReminderOpen(false, true);
                return;
            }
            if (accountMenu && accountMenu.open) {
                event.preventDefault();
                closeAccountMenu(true);
                return;
            }
            clearNavigationTimers();
            closeNavigationGroups();
            document.querySelectorAll('details[data-dismiss-on-outside][open]').forEach(function (details) {
                details.open = false;
            });
            if (shell.classList.contains('is-mobile-open')) closeMobile();
        }
    });

    var paidTestDialog = document.querySelector('[data-paid-test-dialog]');
    if (paidTestDialog && typeof paidTestDialog.showModal === 'function') {
        var paidTestCapability = paidTestDialog.querySelector('[data-paid-test-capability]');
        var paidTestTitle = paidTestDialog.querySelector('[data-paid-test-title]');
        var paidTestCost = paidTestDialog.querySelector('[data-paid-test-cost]');
        var paidTestPreviousFocus = null;

        function closePaidTestDialog() {
            if (paidTestDialog.open) paidTestDialog.close();
        }

        document.querySelectorAll('[data-open-paid-test]').forEach(function (button) {
            button.addEventListener('click', function () {
                paidTestPreviousFocus = button;
                paidTestCapability.value = button.getAttribute('data-test-capability') || '';
                paidTestTitle.textContent = '验证“' + (button.getAttribute('data-test-label') || '候选能力') + '”';
                paidTestCost.checked = false;
                paidTestDialog.showModal();
                document.body.classList.add('admin-dialog-open');
                window.setTimeout(function () { paidTestCost.focus(); }, 0);
            });
        });
        paidTestDialog.querySelectorAll('[data-close-paid-test]').forEach(function (button) {
            button.addEventListener('click', closePaidTestDialog);
        });
        paidTestDialog.addEventListener('click', function (event) {
            if (event.target === paidTestDialog) closePaidTestDialog();
        });
        paidTestDialog.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') return;
            event.preventDefault();
            closePaidTestDialog();
        });
        paidTestDialog.addEventListener('close', function () {
            document.body.classList.remove('admin-dialog-open');
            paidTestCost.checked = false;
            if (paidTestPreviousFocus) paidTestPreviousFocus.focus();
            paidTestPreviousFocus = null;
        });
    }

    var modelTestDrawer = document.querySelector('[data-model-test-drawer]');
    if (modelTestDrawer && typeof modelTestDrawer.showModal === 'function') {
        var modelTestContent = modelTestDrawer.querySelector('[data-model-test-drawer-content]');
        var modelTestPreviousFocus = null;

        function closeModelTestDrawer() {
            if (modelTestDrawer.open) modelTestDrawer.close();
        }

        document.querySelectorAll('[data-open-model-test]').forEach(function (button) {
            button.addEventListener('click', function () {
                var testId = button.getAttribute('data-open-model-test') || '';
                var template = document.querySelector('[data-model-test-template="' + testId.replace(/[^0-9]/g, '') + '"]');
                if (!template) return;
                modelTestPreviousFocus = button;
                modelTestContent.replaceChildren(template.content.cloneNode(true));
                modelTestDrawer.showModal();
                document.body.classList.add('admin-dialog-open');
                var closeButton = modelTestDrawer.querySelector('[data-close-model-test]');
                if (closeButton) closeButton.focus();
                if (window.lucide) window.lucide.createIcons();
            });
        });
        modelTestDrawer.querySelectorAll('[data-close-model-test]').forEach(function (button) {
            button.addEventListener('click', closeModelTestDrawer);
        });
        modelTestDrawer.addEventListener('click', function (event) {
            if (event.target === modelTestDrawer) closeModelTestDrawer();
        });
        modelTestDrawer.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') return;
            event.preventDefault();
            closeModelTestDrawer();
        });
        modelTestDrawer.addEventListener('close', function () {
            document.body.classList.remove('admin-dialog-open');
            modelTestContent.replaceChildren();
            if (modelTestPreviousFocus) modelTestPreviousFocus.focus();
            modelTestPreviousFocus = null;
        });
    }
}());
