(function (window, document) {
    'use strict';

    var departmentSelect = document.querySelector('[data-owner-operation-department]');
    var ownerSelect = document.querySelector('[data-owner-user]');

    if (!departmentSelect || !ownerSelect) {
        return;
    }

    var config = window.ErpAssistantDomainStoreOwnerForm || {};
    var candidates = config.candidates || {};
    var translations = config.translations || {};
    var initialDepartmentId = String(departmentSelect.value || '');
    var initialOwnerId = config.selectedOwnerId === null || config.selectedOwnerId === undefined
        ? ''
        : String(config.selectedOwnerId);

    function appendOption(value, text, selected) {
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

    function populateOwners(departmentId, selectedOwnerId) {
        var rows = candidates[String(departmentId)] || [];

        ownerSelect.innerHTML = '';
        appendOption('', rows.length ? translations.chooseOwner : translations.empty, !selectedOwnerId);

        rows.forEach(function (candidate) {
            appendOption(candidate.id, candidateLabel(candidate), String(candidate.id) === String(selectedOwnerId || ''));
        });

        ownerSelect.disabled = !departmentId || rows.length === 0;
    }

    departmentSelect.addEventListener('change', function () {
        var departmentId = String(departmentSelect.value || '');
        var selectedOwnerId = departmentId === initialDepartmentId ? initialOwnerId : '';

        if (!departmentId) {
            ownerSelect.innerHTML = '';
            appendOption('', translations.chooseOwner, true);
            ownerSelect.disabled = true;
            return;
        }

        populateOwners(departmentId, selectedOwnerId);
    });
})(window, document);
