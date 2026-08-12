(function () {
    'use strict';

    function icons() {
        if (window.lucide) { window.lucide.createIcons(); }
    }

    function setupDeliveryTargets() {
        var form = document.querySelector('[data-delivery-target-form]');
        var template = document.querySelector('[data-delivery-target-template]');
        if (!form || !template) { return; }
        var rows = form.querySelector('[data-delivery-target-rows]');
        var add = form.querySelector('[data-add-delivery-target]');
        function bindRemove(button) {
            button.addEventListener('click', function () {
                var allRows = rows.querySelectorAll('.delivery-target-row');
                if (allRows.length <= 1) { return; }
                button.closest('.delivery-target-row').remove();
            });
        }
        rows.querySelectorAll('[data-remove-delivery-target]').forEach(bindRemove);
        add.addEventListener('click', function () {
            if (rows.querySelectorAll('.delivery-target-row').length >= 12) { return; }
            var fragment = template.content.cloneNode(true);
            var button = fragment.querySelector('[data-remove-delivery-target]');
            rows.appendChild(fragment);
            bindRemove(button);
            icons();
        });
    }

    function setupFactActions() {
        document.querySelectorAll('[data-fact-response-form]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                var submitter = event.submitter;
                if (!submitter || submitter.value !== 'unknown') { return; }
                var input = form.querySelector('[name="value"]');
                if (input) { input.value = ''; }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var workbench = document.querySelector('[data-one-flow-workbench]');
        if (!workbench) { return; }
        setupDeliveryTargets();
        setupFactActions();
        var legacyAnchor = workbench.getAttribute('data-legacy-anchor');
        if (!window.location.hash && legacyAnchor && document.getElementById(legacyAnchor)) {
            window.requestAnimationFrame(function () { document.getElementById(legacyAnchor).scrollIntoView(); });
        }
    });
})();
