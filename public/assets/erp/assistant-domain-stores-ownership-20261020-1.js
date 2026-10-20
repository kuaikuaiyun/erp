(function (window, document) {
    'use strict';

    var config = window.ErpAssistantDomainStoreOwnership || {};
    var translations = config.translations || {};
    var assignTrigger = document.getElementById('assistant-batch-owner-trigger');
    var clearTrigger = document.getElementById('assistant-batch-owner-clear-trigger');

    if (!assignTrigger || !clearTrigger) {
        return;
    }

    function selectedIds() {
        return Array.prototype.slice.call(document.querySelectorAll('.assistant-row-selector[aria-pressed="true"]'))
            .map(function (button) { return String(button.getAttribute('data-store-id') || ''); })
            .filter(Boolean);
    }

    function interpolate(template, count) {
        return String(template || '').replace('__count__', String(count));
    }

    function syncTriggers() {
        var hasSelection = selectedIds().length > 0;
        assignTrigger.hidden = !hasSelection;
        clearTrigger.hidden = !hasSelection;
    }

    function fillSelectedInputs(container, ids) {
        container.innerHTML = '';
        ids.forEach(function (id) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_ids[]';
            input.value = id;
            container.appendChild(input);
        });
    }

    function errorMessage(payload) {
        if (payload && payload.errors) {
            var keys = Object.keys(payload.errors);
            if (keys.length && payload.errors[keys[0]] && payload.errors[keys[0]][0]) {
                return payload.errors[keys[0]][0];
            }
        }

        return payload && payload.message ? payload.message : (translations.loadFailed || 'Unable to load owners.');
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('.assistant-row-selector, .assistant-select-all')) {
            window.setTimeout(syncTriggers, 0);
        }
    });

    if (window.jQuery) {
        window.jQuery('#assistant-batch-owner-modal').on('show.bs.modal', function () {
            var modal = this;
            var ids = selectedIds();
            var select = modal.querySelector('#assistant-owner-user-select');
            var submit = modal.querySelector('[data-owner-submit]');
            var summary = modal.querySelector('[data-owner-selection-summary]');
            var warning = modal.querySelector('[data-owner-warning]');
            var inputs = modal.querySelector('[data-owner-selected-inputs]');
            var params = new URLSearchParams();

            fillSelectedInputs(inputs, ids);
            summary.textContent = interpolate(translations.selectionCount, ids.length);
            warning.hidden = true;
            warning.textContent = '';
            select.innerHTML = '';
            select.disabled = true;
            submit.disabled = true;

            var loadingOption = document.createElement('option');
            loadingOption.value = '';
            loadingOption.textContent = translations.loading || 'Loading...';
            select.appendChild(loadingOption);

            ids.forEach(function (id) { params.append('selected_ids[]', id); });

            window.fetch(config.optionsUrl + '?' + params.toString(), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (payload) {
                    if (!response.ok) {
                        throw new Error(errorMessage(payload));
                    }
                    return payload;
                });
            }).then(function (payload) {
                select.innerHTML = '';
                var placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = translations.chooseOwner || '';
                select.appendChild(placeholder);

                (payload.users || []).forEach(function (user) {
                    var option = document.createElement('option');
                    option.value = String(user.id);
                    option.textContent = user.name + (user.username ? ' (' + user.username + ')' : '')
                        + (user.departments ? ' — ' + user.departments : '');
                    select.appendChild(option);
                });

                if (!(payload.users || []).length) {
                    placeholder.textContent = translations.empty || '';
                } else {
                    select.disabled = false;
                }

                if (Number(payload.outside_scope_count || 0) > 0) {
                    warning.textContent = interpolate(translations.outsideScope, Number(payload.outside_scope_count));
                    warning.hidden = false;
                }
            }).catch(function (error) {
                select.innerHTML = '';
                var option = document.createElement('option');
                option.value = '';
                option.textContent = error.message || translations.loadFailed || '';
                select.appendChild(option);
            });
        });

        window.jQuery('#assistant-batch-owner-clear-modal').on('show.bs.modal', function () {
            var ids = selectedIds();
            fillSelectedInputs(this.querySelector('[data-owner-clear-inputs]'), ids);
            this.querySelector('[data-owner-clear-summary]').textContent = interpolate(translations.clearConfirm, ids.length);
        });
    }

    var ownerSelect = document.getElementById('assistant-owner-user-select');
    var assignSubmit = document.querySelector('#assistant-batch-owner-form [data-owner-submit]');
    if (ownerSelect && assignSubmit) {
        ownerSelect.addEventListener('change', function () {
            assignSubmit.disabled = !ownerSelect.value;
        });
    }

    syncTriggers();
})(window, document);
