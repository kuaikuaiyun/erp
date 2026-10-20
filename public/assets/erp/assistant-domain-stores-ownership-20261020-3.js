(function (window, document) {
    'use strict';

    var config = window.ErpAssistantDomainStoreOwnership || {};
    var translations = config.translations || {};
    var ownerTrigger = document.getElementById('assistant-batch-owner-trigger');
    var ownerForm = document.getElementById('assistant-batch-owner-form');

    if (!ownerTrigger || !ownerForm) {
        return;
    }

    var ownerSelect = document.getElementById('assistant-owner-user-select');
    var ownerSubmit = ownerForm.querySelector('[data-owner-submit]');
    var ownerMethod = ownerForm.querySelector('[data-owner-method]');
    var assignFields = ownerForm.querySelector('[data-owner-assign-fields]');
    var warning = ownerForm.querySelector('[data-owner-warning]');
    var summary = ownerForm.querySelector('[data-owner-selection-summary]');
    var currentMode = 'assign';
    var outsideScopeCount = 0;

    function selectedIds() {
        return Array.prototype.slice.call(document.querySelectorAll('.assistant-row-selector[aria-pressed="true"]'))
            .map(function (button) { return String(button.getAttribute('data-store-id') || ''); })
            .filter(Boolean);
    }

    function interpolate(template, count) {
        return String(template || '').replace('__count__', String(count));
    }

    function syncTrigger() {
        ownerTrigger.hidden = selectedIds().length === 0;
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

    function syncOwnerSubmit() {
        var ids = selectedIds();
        var canAssign = ownerSelect
            && ownerSelect.getAttribute('data-candidates-ready') === '1'
            && Boolean(ownerSelect.value);

        ownerSubmit.disabled = ids.length === 0 || (currentMode === 'assign' && !canAssign);
    }

    function setOwnerMode(mode) {
        var clear = mode === 'clear';
        currentMode = clear ? 'clear' : 'assign';

        ownerForm.setAttribute('data-owner-mode', currentMode);
        ownerForm.action = clear
            ? ownerForm.getAttribute('data-clear-url')
            : ownerForm.getAttribute('data-assign-url');
        ownerMethod.disabled = !clear;
        assignFields.hidden = clear;
        ownerSelect.required = !clear;
        ownerSelect.disabled = clear || ownerSelect.getAttribute('data-candidates-ready') !== '1';

        Array.prototype.forEach.call(ownerForm.querySelectorAll('[data-owner-mode-choice]'), function (button) {
            var selected = button.getAttribute('data-owner-mode-choice') === currentMode;
            button.classList.toggle('is-selected', selected);
            button.classList.toggle('btn-success', selected && !clear);
            button.classList.toggle('btn-danger', selected && clear);
            button.classList.toggle('btn-default', !selected);
            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });

        ownerSubmit.classList.toggle('btn-success', !clear);
        ownerSubmit.classList.toggle('btn-danger', clear);
        ownerForm.querySelector('[data-owner-submit-icon]').className = clear
            ? 'fa fa-user-times'
            : 'fa fa-check';
        ownerForm.querySelector('[data-owner-submit-text]').textContent = clear
            ? ownerForm.getAttribute('data-clear-label')
            : ownerForm.getAttribute('data-assign-label');

        summary.textContent = clear
            ? interpolate(translations.clearConfirm, selectedIds().length)
            : interpolate(translations.selectionCount, selectedIds().length);
        warning.hidden = clear || outsideScopeCount === 0;
        syncOwnerSubmit();
    }

    function loadCandidates(ids) {
        var params = new URLSearchParams();

        ownerSelect.removeAttribute('data-candidates-ready');
        ownerSelect.innerHTML = '';
        ownerSelect.disabled = true;
        outsideScopeCount = 0;
        warning.hidden = true;
        warning.textContent = '';

        var loadingOption = document.createElement('option');
        loadingOption.value = '';
        loadingOption.textContent = translations.loading || 'Loading...';
        ownerSelect.appendChild(loadingOption);

        ids.forEach(function (id) { params.append('selected_ids[]', id); });
        syncOwnerSubmit();

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
            var users = payload.users || [];
            ownerSelect.innerHTML = '';

            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = users.length
                ? (translations.chooseOwner || '')
                : (translations.empty || '');
            ownerSelect.appendChild(placeholder);

            users.forEach(function (user) {
                var option = document.createElement('option');
                option.value = String(user.id);
                option.textContent = user.name
                    + (user.username ? ' (' + user.username + ')' : '')
                    + (user.departments ? ' - ' + user.departments : '');
                ownerSelect.appendChild(option);
            });

            if (users.length) {
                ownerSelect.setAttribute('data-candidates-ready', '1');
            }

            outsideScopeCount = Number(payload.outside_scope_count || 0);
            if (outsideScopeCount > 0) {
                warning.textContent = interpolate(translations.outsideScope, outsideScopeCount);
            }

            setOwnerMode(currentMode);
        }).catch(function (error) {
            ownerSelect.innerHTML = '';
            var option = document.createElement('option');
            option.value = '';
            option.textContent = error.message || translations.loadFailed || '';
            ownerSelect.appendChild(option);
            ownerSelect.removeAttribute('data-candidates-ready');
            setOwnerMode(currentMode);
        });
    }

    function initializeScopeTree() {
        Array.prototype.forEach.call(document.querySelectorAll('.assistant-owner-scope-card'), function (card) {
            var inputs = Array.prototype.slice.call(card.querySelectorAll('[data-owner-scope-checkbox]'));
            var inputById = {};

            inputs.forEach(function (input) {
                inputById[String(input.getAttribute('data-department-id') || '')] = input;
            });

            function childrenOf(input) {
                var id = String(input.getAttribute('data-department-id') || '');
                return inputs.filter(function (candidate) {
                    return String(candidate.getAttribute('data-parent-id') || '') === id;
                });
            }

            function setDescendants(input, checked) {
                childrenOf(input).forEach(function (child) {
                    child.checked = checked;
                    child.indeterminate = false;
                    setDescendants(child, checked);
                });
            }

            function updateAncestor(input) {
                var parent = inputById[String(input.getAttribute('data-parent-id') || '')];
                if (!parent) {
                    return;
                }

                var children = childrenOf(parent);
                var allChecked = children.length > 0 && children.every(function (child) {
                    return child.checked && !child.indeterminate;
                });
                var anyChecked = children.some(function (child) {
                    return child.checked || child.indeterminate;
                });

                parent.checked = allChecked;
                parent.indeterminate = !allChecked && anyChecked;
                updateAncestor(parent);
            }

            inputs.forEach(function (input) {
                if (input.checked) {
                    setDescendants(input, true);
                }
            });
            inputs.slice().reverse().forEach(updateAncestor);

            card.addEventListener('change', function (event) {
                var input = event.target.closest('[data-owner-scope-checkbox]');
                if (!input || !card.contains(input)) {
                    return;
                }

                input.indeterminate = false;
                setDescendants(input, input.checked);
                updateAncestor(input);
            });
        });

        var form = document.querySelector('[data-owner-scope-form]');
        if (!form) {
            return;
        }

        form.addEventListener('submit', function (event) {
            if (form.getAttribute('data-submitting') === '1') {
                event.preventDefault();
                return;
            }

            form.setAttribute('data-submitting', '1');
            var submit = form.querySelector('[data-owner-scope-submit]');
            var text = form.querySelector('[data-owner-scope-submit-text]');
            if (submit) {
                submit.disabled = true;
            }
            if (text) {
                text.textContent = translations.scopeSaving || 'Saving...';
            }
        });
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('.assistant-row-selector, .assistant-select-all')) {
            window.setTimeout(syncTrigger, 0);
        }
    });

    ownerForm.addEventListener('click', function (event) {
        var button = event.target.closest('[data-owner-mode-choice]');
        if (button && ownerForm.contains(button)) {
            setOwnerMode(button.getAttribute('data-owner-mode-choice'));
        }
    });

    ownerSelect.addEventListener('change', syncOwnerSubmit);

    ownerForm.addEventListener('submit', function (event) {
        fillSelectedInputs(ownerForm.querySelector('[data-owner-selected-inputs]'), selectedIds());
        syncOwnerSubmit();
        if (ownerSubmit.disabled) {
            event.preventDefault();
        }
    });

    if (window.jQuery) {
        window.jQuery('#assistant-batch-owner-modal').on('show.bs.modal', function () {
            var ids = selectedIds();
            fillSelectedInputs(ownerForm.querySelector('[data-owner-selected-inputs]'), ids);
            ownerSelect.value = '';
            setOwnerMode('assign');
            loadCandidates(ids);
        });
    }

    syncTrigger();
    initializeScopeTree();
})(window, document);
