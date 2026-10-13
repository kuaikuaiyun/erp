(function () {
    'use strict';

    function replaceTokens(template, replacements) {
        var result = String(template || '');

        Object.keys(replacements).forEach(function (key) {
            result = result.replace(new RegExp('__' + key + '__', 'g'), String(replacements[key]));
        });

        return result;
    }

    function initializeSelection(configuration) {
        var form = document.getElementById('assistant-shopify-batch-refresh-form');

        if (!form) {
            return;
        }

        var selectAll = document.querySelector('[data-shopify-select-all]');
        var selectPage = document.querySelector('[data-shopify-select-page]');
        var selectedCount = document.querySelector('[data-shopify-selection-count]');
        var eligibleCount = document.querySelector('[data-shopify-eligible-count]');
        var batchRefresh = document.querySelector('[data-shopify-batch-refresh]');

        function candidates() {
            return Array.prototype.slice.call(document.querySelectorAll('[data-shopify-select]:not(:disabled)'));
        }

        function selected() {
            return candidates().filter(function (input) {
                return input.checked;
            });
        }

        function refreshSelectionState() {
            var available = candidates();
            var chosen = selected();
            var allSelected = available.length > 0 && chosen.length === available.length;

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
                selectedCount.textContent = replaceTokens(configuration.selectedCount, {count: chosen.length});
            }

            if (eligibleCount) {
                eligibleCount.textContent = replaceTokens(configuration.eligibleCount, {count: available.length});
            }

            if (batchRefresh) {
                batchRefresh.disabled = chosen.length === 0;
            }
        }

        function setAll(checked) {
            candidates().forEach(function (input) {
                input.checked = checked;
            });

            refreshSelectionState();
        }

        document.addEventListener('change', function (event) {
            if (event.target && event.target.matches('[data-shopify-select]')) {
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

    function initializeProgress(configuration) {
        var container = document.querySelector('[data-shopify-sync-progress]');
        var url = configuration.syncProgressUrl;

        if (!container || !url || !window.fetch) {
            return;
        }

        var templates = configuration.progressTemplates || {};
        var summary = container.querySelector('[data-shopify-progress-summary]');
        var bar = container.querySelector('[data-shopify-progress-bar]');
        var current = container.querySelector('[data-shopify-progress-current]');
        var issues = container.querySelector('[data-shopify-progress-issues]');
        var pollInterval = Math.max(2000, Number(configuration.syncPollInterval || 5000));

        function renderIssues(items) {
            if (!issues) {
                return;
            }

            issues.innerHTML = '';
            (items || []).forEach(function (item) {
                var row = document.createElement('div');
                var store = document.createElement('strong');
                var reason = document.createElement('span');

                row.className = 'assistant-shopify-sync-progress-issue';
                store.textContent = item.store_name || '';
                reason.textContent = item.reason || '';
                row.appendChild(store);
                row.appendChild(reason);
                issues.appendChild(row);
            });
            issues.hidden = !items || items.length === 0;
        }

        function render(payload) {
            if (summary) {
                summary.textContent = replaceTokens(templates.summary, {
                    processed: payload.processed || 0,
                    total: payload.total || 0,
                    success: payload.success || 0,
                    failed: payload.failed || 0,
                });
            }

            if (bar) {
                bar.style.width = Math.max(0, Math.min(100, Number(payload.percent || 0))) + '%';
            }

            if (current) {
                current.textContent = payload.completed
                    ? (templates.completed || '')
                    : (payload.current_store
                        ? replaceTokens(templates.current, {name: payload.current_store})
                        : (templates.waiting || ''));
            }

            renderIssues(payload.issues || []);
        }

        function poll() {
            window.fetch(url, {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Unable to read synchronization progress.');
                    }

                    return response.json();
                })
                .then(function (payload) {
                    render(payload);
                    if (!payload.completed) {
                        window.setTimeout(poll, pollInterval);
                    }
                })
                .catch(function () {
                    window.setTimeout(poll, pollInterval);
                });
        }

        window.setTimeout(poll, 500);
    }

    function initialize() {
        var configuration = window.ErpAssistantShopifyAnalyticsConfig || {};

        initializeSelection(configuration);
        initializeProgress(configuration);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
}());
