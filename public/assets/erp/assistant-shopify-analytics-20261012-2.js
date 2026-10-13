(function () {
    'use strict';

    function initialize() {
        var configuration = window.ErpAssistantShopifyAnalyticsConfig || {};
        var form = document.getElementById('assistant-shopify-batch-refresh-form');

        if (!form) {
            return;
        }

        var selectAll = document.querySelector('[data-shopify-select-all]');
        var selectPage = document.querySelector('[data-shopify-select-page]');
        var selectedCount = document.querySelector('[data-shopify-selection-count]');
        var eligibleCount = document.querySelector('[data-shopify-eligible-count]');
        var batchRefresh = document.querySelector('[data-shopify-batch-refresh]');
        var maxSelection = Number(configuration.maxSelection || 20);

        function candidates() {
            return Array.prototype.slice.call(document.querySelectorAll('[data-shopify-select]:not(:disabled)'));
        }

        function selected() {
            return candidates().filter(function (input) {
                return input.checked;
            });
        }

        function replaceCount(template, count) {
            return String(template || '').replace('__count__', String(count));
        }

        function refreshSelectionState() {
            var available = candidates();
            var chosen = selected();
            var selectionTarget = Math.min(available.length, maxSelection);
            var allSelected = selectionTarget > 0 && chosen.length === selectionTarget;

            if (selectAll) {
                selectAll.disabled = available.length === 0;
                selectAll.checked = allSelected;
                selectAll.indeterminate = chosen.length > 0 && !allSelected;
            }

            if (selectPage) {
                selectPage.disabled = available.length === 0;
                selectPage.textContent = allSelected
                    ? (configuration.clearPage || '')
                    : (configuration.selectPage || '');
            }

            if (selectedCount) {
                selectedCount.textContent = replaceCount(configuration.selectedCount, chosen.length);
            }

            if (eligibleCount) {
                eligibleCount.textContent = replaceCount(configuration.eligibleCount, available.length);
            }

            if (batchRefresh) {
                batchRefresh.disabled = chosen.length === 0;
            }
        }

        function setAll(checked) {
            candidates().forEach(function (input, index) {
                input.checked = checked && index < maxSelection;
            });

            refreshSelectionState();
        }

        document.addEventListener('change', function (event) {
            if (event.target && event.target.matches('[data-shopify-select]')) {
                if (selected().length > maxSelection) {
                    event.target.checked = false;
                }

                refreshSelectionState();
            }
        });

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                setAll(selectAll.checked);
            });
        }

        if (selectPage) {
            selectPage.addEventListener('click', function () {
                setAll(selected().length !== candidates().length);
            });
        }

        refreshSelectionState();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
}());
