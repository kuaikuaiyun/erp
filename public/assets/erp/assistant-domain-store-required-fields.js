(function () {
    'use strict';

    if (window.ErpAssistantStoreRequiredFieldsLoaded) return;
    window.ErpAssistantStoreRequiredFieldsLoaded = true;

    function config(form) {
        var node = form.querySelector('[data-store-validation-config]');
        try { return JSON.parse(node ? node.textContent : '{}'); } catch (error) { return {}; }
    }

    function fieldGroup(form, name) {
        var root = name.split('.')[0];
        var inputs = Array.prototype.slice.call(form.querySelectorAll('[name]'));
        var field = inputs.find(function (input) {
            return input.name === name || input.name === root || input.name === root + '[]';
        });
        if (field) return field.closest('.form-group');
        return Array.prototype.find.call(form.querySelectorAll('[data-store-required-field]'), function (group) {
            return group.getAttribute('data-store-required-field') === root;
        }) || null;
    }

    function clearValidation(form) {
        form.querySelectorAll('[data-store-validation-summary], [data-store-validation-error]').forEach(function (node) { node.remove(); });
        form.querySelectorAll('.assistant-modal-has-error').forEach(function (group) {
            group.classList.remove('has-error', 'assistant-modal-has-error');
            group.querySelectorAll('[aria-invalid="true"]').forEach(function (input) { input.removeAttribute('aria-invalid'); });
        });
    }

    function locate(group) {
        if (!group) return;
        var ancestor = group;
        while (ancestor && ancestor.tagName !== 'FORM') {
            if (ancestor.hidden) ancestor.hidden = false;
            ancestor = ancestor.parentElement;
        }
        var target = Array.prototype.find.call(group.querySelectorAll('input, select, textarea, button'), function (input) {
            return !input.disabled && input.type !== 'hidden' && input.getClientRects().length;
        });
        group.scrollIntoView({ block: 'center', behavior: 'smooth' });
        if (target) target.focus({ preventScroll: true });
    }

    function showErrors(form, errors, fallback) {
        clearValidation(form);
        var settings = config(form), entries = [], firstGroup = null;
        Object.keys(errors || {}).forEach(function (name) {
            var group = fieldGroup(form, name);
            var values = Array.isArray(errors[name]) ? errors[name] : [errors[name]];
            values.forEach(function (message) {
                if (typeof message !== 'string' || !message.trim()) return;
                entries.push({ message: message, group: group });
                if (group) {
                    group.classList.add('has-error', 'assistant-modal-has-error');
                    var note = document.createElement('span');
                    note.className = 'help-block assistant-modal-validation-error';
                    note.setAttribute('data-store-validation-error', '');
                    note.textContent = message;
                    group.appendChild(note);
                    group.querySelectorAll('input, select, textarea, button').forEach(function (input) {
                        if (!input.disabled) input.setAttribute('aria-invalid', 'true');
                    });
                    firstGroup = firstGroup || group;
                }
            });
        });
        var summary = document.createElement('div');
        summary.className = 'alert alert-danger assistant-modal-form-alert';
        summary.setAttribute('data-store-validation-summary', '');
        summary.setAttribute('role', 'alert');
        summary.tabIndex = -1;
        var title = document.createElement('strong');
        title.textContent = entries.length ? settings.title : fallback;
        summary.appendChild(title);
        if (entries.length) {
            var list = document.createElement('ul');
            entries.forEach(function (entry) {
                var item = document.createElement('li');
                var link = document.createElement(entry.group ? 'button' : 'span');
                if (entry.group) {
                    link.type = 'button';
                    link.className = 'btn btn-link btn-xs';
                    link.addEventListener('click', function () { locate(entry.group); });
                }
                link.textContent = entry.message;
                item.appendChild(link);
                list.appendChild(item);
            });
            summary.appendChild(list);
        }
        form.insertBefore(summary, form.firstChild);
        if (firstGroup) locate(firstGroup);
        else {
            summary.scrollIntoView({ block: 'start', behavior: 'smooth' });
            summary.focus({ preventScroll: true });
        }
    }

    function collectErrors(form) {
        var settings = config(form), errors = {};
        // Commit any pending tag entry before validating or serializing it.
        form.querySelectorAll('.assistant-tag-entry').forEach(function (input) { input.blur(); });
        form.querySelectorAll('[data-store-required-field]').forEach(function (group) {
            var field = group.getAttribute('data-store-required-field');
            var inputs = Array.prototype.filter.call(group.querySelectorAll('input, select'), function (input) { return !input.disabled; });
            if (!inputs.length) return;
            var valid = inputs.some(function (input) {
                var value = String(input.value || '');
                return field === 'team'
                    ? value.split(/[,，、;；\r\n]+/).some(function (part) { return part.trim() !== ''; })
                    : value.trim() !== '';
            });
            if (!valid) errors[field] = [group.getAttribute('data-store-required-message')];
        });
        form.querySelectorAll('input[name], select[name], textarea[name]').forEach(function (input) {
            if (input.disabled || input.type === 'hidden' || !input.willValidate || input.validity.valid) return;
            var field = input.name.replace(/\[.*$/, '');
            if (errors[field]) return;
            var group = input.closest('.form-group'), label = group ? group.querySelector('label') : null;
            var name = label ? label.textContent.replace(/\*/g, '').trim() : field;
            errors[field] = [(input.validity.valueMissing ? settings.required : settings.invalid).replace(':field', name)];
        });
        return errors;
    }

    function failure(form, xhr, status) {
        var settings = config(form), code = Number(xhr.status || 0), response = xhr.responseJSON;
        if (!response && typeof xhr.responseText === 'string') {
            try { response = JSON.parse(xhr.responseText); } catch (error) { response = null; }
        }
        if (code === 422) {
            showErrors(form, response && response.errors || {}, response && typeof response.message === 'string' ? response.message : settings.title);
            return;
        }
        var message = status === 'timeout' || code === 408 ? settings.timeout
            : code === 0 ? settings.network
            : code === 401 || code === 419 ? settings.session
            : code === 403 ? settings.permission
            : code === 404 ? settings.missing
            : code === 409 ? settings.conflict
            : code === 413 ? settings.tooLarge
            : code === 429 ? settings.tooMany
            : code >= 500 ? settings.server
            : settings.unexpected;
        var diagnosticId = response && response.diagnostic_id
            || (typeof xhr.getResponseHeader === 'function' ? xhr.getResponseHeader('X-Domain-Store-Diagnostic-Id') : '');
        if (typeof diagnosticId === 'string' && /^[a-f0-9]{16}$/.test(diagnosticId)) {
            if (code >= 500 && response) {
                var outcomes = { saved: settings.diagnosticSaved, not_saved: settings.diagnosticNotSaved, unconfirmed: settings.diagnosticUnconfirmed };
                message = outcomes[response.save_outcome] || message;
            }
            message += ' ' + (settings.diagnostic || ':id').replace(':id', diagnosticId);
            if (response && response.diagnostic_log_written === false) message += ' ' + settings.diagnosticLogUnavailable;
        }
        showErrors(form, {}, message);
    }

    window.ErpAssistantStoreValidation = { clear: clearValidation, show: showErrors, failure: failure };

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
        if (form.matches('.assistant-domain-store-form')) {
            var errors = collectErrors(form);
            if (Object.keys(errors).length) {
                event.preventDefault();
                event.stopImmediatePropagation();
                showErrors(form, errors);
            } else clearValidation(form);
            return;
        }
        var firstInvalid = null;
        form.querySelectorAll('[data-store-required-field]').forEach(function (group) {
            clearError(group);
            var field = group.getAttribute('data-store-required-field');
            var inputs = Array.prototype.filter.call(group.querySelectorAll('input, select'), function (input) { return !input.disabled; });
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
