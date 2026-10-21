(function (window, document) {
    'use strict';

    function appendOption(ownerSelect, value, text, selected) {
        var option = document.createElement('option');
        option.value = String(value || '');
        option.textContent = text || '';
        option.selected = Boolean(selected);
        ownerSelect.appendChild(option);
    }

    function candidateLabel(candidate) {
        var label = String(candidate.name || candidate.username || '');

        if (candidate.username && String(candidate.username) !== label) {
            label += ' (' + candidate.username + ')';
        }

        if (candidate.departments) {
            label += ' - ' + candidate.departments;
        }

        return label;
    }

    function formConfig(form) {
        var configNode = form ? form.querySelector('[data-owner-form-config]') : null;

        if (configNode) {
            try {
                return JSON.parse(configNode.textContent || '{}');
            } catch (error) {
                return {};
            }
        }

        return window.ErpAssistantDomainStoreOwnerForm || {};
    }

    function populateOwners(form, departmentSelect, ownerSelect) {
        var config = formConfig(form);
        var candidates = config.candidates || {};
        var translations = config.translations || {};
        var departmentId = String(departmentSelect.value || '');
        var initialDepartmentId = config.selectedDepartmentId === null || config.selectedDepartmentId === undefined
            ? ''
            : String(config.selectedDepartmentId);
        var initialOwnerId = config.selectedOwnerId === null || config.selectedOwnerId === undefined
            ? ''
            : String(config.selectedOwnerId);
        var selectedOwnerId = departmentId === initialDepartmentId ? initialOwnerId : '';
        var rows = candidates[String(departmentId)] || [];

        ownerSelect.innerHTML = '';
        appendOption(ownerSelect, '', departmentId && !rows.length ? translations.empty : translations.chooseOwner, !selectedOwnerId);

        rows.forEach(function (candidate) {
            appendOption(ownerSelect, candidate.id, candidateLabel(candidate), String(candidate.id) === String(selectedOwnerId || ''));
        });

        ownerSelect.disabled = !departmentId || rows.length === 0;
    }

    document.addEventListener('change', function (event) {
        var departmentSelect = event.target.closest('[data-owner-operation-department]');

        if (!departmentSelect) {
            return;
        }

        var form = departmentSelect.closest('.assistant-domain-store-form');
        var ownerSelect = form ? form.querySelector('[data-owner-user]') : null;

        if (!form || !ownerSelect) {
            return;
        }

        populateOwners(form, departmentSelect, ownerSelect);
    });
})(window, document);
