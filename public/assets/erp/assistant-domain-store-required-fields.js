(function () {
    'use strict';

    if (window.ErpAssistantStoreRequiredFieldsLoaded) return;
    window.ErpAssistantStoreRequiredFieldsLoaded = true;

    function clearError(group) {
        group.querySelectorAll('[data-store-required-error]').forEach(function (error) { error.remove(); });
        if (group.classList.contains('assistant-required-field-invalid')) {
            group.classList.remove('has-error', 'assistant-required-field-invalid');
            group.querySelectorAll('[aria-invalid="true"]').forEach(function (input) { input.removeAttribute('aria-invalid'); });
        }
    }

    // Capture before the modal and batch AJAX submit handlers serialize the form.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.matches('.assistant-domain-store-form, [data-batch-edit-form]')) return;
        var firstInvalid = null;
        form.querySelectorAll('[data-store-required-field]').forEach(function (group) {
            clearError(group);
            var field = group.getAttribute('data-store-required-field');
            var inputs = Array.prototype.filter.call(group.querySelectorAll('input'), function (input) { return !input.disabled; });
            if (!inputs.length) return;
            var valid = inputs.some(function (input) {
                var value = String(input.value || '');
                return field === 'team'
                    ? value.split(/[,，、;；\r\n]+/).some(function (item) { return item.trim() !== ''; })
                    : value.trim() !== '';
            });
            if (valid) return;
            var target = inputs.find(function (input) { return input.type !== 'hidden'; }) || inputs[0];
            var error = document.createElement('span');
            error.className = 'help-block';
            error.setAttribute('data-store-required-error', '');
            error.setAttribute('role', 'alert');
            error.textContent = group.getAttribute('data-store-required-message');
            group.appendChild(error);
            group.classList.add('has-error', 'assistant-required-field-invalid');
            target.setAttribute('aria-invalid', 'true');
            firstInvalid = firstInvalid || target;
        });
        if (firstInvalid) {
            event.preventDefault();
            event.stopImmediatePropagation();
            firstInvalid.focus();
        }
    }, true);

    ['input', 'change', 'click'].forEach(function (name) {
        document.addEventListener(name, function (event) {
            var group = event.target.closest('[data-store-required-field]');
            if (group) clearError(group);
        });
    });
})();
