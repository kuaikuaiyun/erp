(function ($) {
    'use strict';

    var translations = window.ErpAssistantDomainStoreBatchUpdateTranslations || {};
    var fieldGroups = window.ErpAssistantDomainStoreBatchUpdateFieldGroups || [];
    var modal = $('#assistant-batch-update-modal');
    var body = $('#assistant-batch-update-content');
    var footer = $('#assistant-batch-update-footer');
    var state = {
        trigger: null,
        request: null,
        requestVersion: 0,
        selectedIds: [],
        selectedFields: [],
        dirty: false,
        saving: false,
        allowClose: false,
    };

    function translate(key, replacements) {
        var message = translations[key] || key;

        Object.keys(replacements || {}).forEach(function (name) {
            message = message.split('__' + name + '__').join(String(replacements[name]));
        });

        return message;
    }

    function escapeHtml(value) {
        return $('<div>').text(value || '').html();
    }

    function selectedStoreIds() {
        return $('.assistant-row-selector.is-selected').map(function () {
            return Number($(this).data('store-id'));
        }).get();
    }

    function notify(type, message) {
        if (window.ErpAdminNotifier && typeof window.ErpAdminNotifier.notify === 'function') {
            window.ErpAdminNotifier.notify({
                type: type,
                title: translate(type === 'success' ? 'successTitle' : 'errorTitle'),
                message: message,
            });
            return;
        }

        window.alert(message);
    }

    function resetModal() {
        state.requestVersion += 1;

        if (state.request && state.request.readyState !== 4) {
            state.request.abort();
        }

        state.trigger = null;
        state.request = null;
        state.selectedIds = [];
        state.selectedFields = [];
        state.dirty = false;
        state.saving = false;
        body.empty();
        footer.empty();
        modal.removeClass('is-saving');
    }

    function selectedFieldKeys() {
        return body.find('[data-batch-field-choice]:checked').map(function () {
            return String($(this).val());
        }).get();
    }

    function updateFieldChoiceState() {
        state.selectedFields = selectedFieldKeys();
        body.find('[data-batch-field-choice]').each(function () {
            $(this).closest('.assistant-batch-field-selection-option').toggleClass('is-selected', this.checked);
        });
        body.find('[data-batch-field-selection-count]').text(translate('selectedFields', { count: state.selectedFields.length }));
        footer.find('[data-batch-editor-continue]').prop('disabled', state.selectedFields.length === 0);
    }

    function renderFieldSelectorFooter() {
        footer.html(
            '<button type="button" class="btn btn-default" data-batch-editor-cancel>' + escapeHtml(translate('cancel')) + '</button>' +
            '<button type="button" class="btn btn-primary" data-batch-editor-continue disabled><i class="fa fa-arrow-right"></i> ' + escapeHtml(translate('continueToEdit')) + '</button>'
        );
    }

    function renderFieldSelector() {
        var groups = fieldGroups.map(function (group) {
            var options = (group.fields || []).map(function (field) {
                var key = String(field.key || '');
                var label = String(field.label || key);
                return '<label class="assistant-batch-field-selection-option">' +
                    '<input type="radio" name="assistant_batch_field_choice" value="' + escapeHtml(key) + '" data-batch-field-choice>' +
                    '<span>' + escapeHtml(label) + '</span>' +
                    '</label>';
            }).join('');

            return '<section class="assistant-batch-field-selection-group">' +
                '<h5>' + escapeHtml(group.label || '') + '</h5>' +
                '<div class="assistant-batch-field-selection-options">' + options + '</div>' +
                '</section>';
        }).join('');

        body.html(
            '<div class="assistant-batch-field-selector" data-batch-field-selector>' +
                '<div class="assistant-batch-field-selector-intro">' + escapeHtml(translate('chooseFieldsNotice')) + '</div>' +
                '<div class="assistant-batch-field-selection-summary" data-batch-field-selection-count>' + escapeHtml(translate('selectedFields', { count: 0 })) + '</div>' +
                '<div class="assistant-batch-field-selection-groups">' + groups + '</div>' +
            '</div>'
        );
        renderFieldSelectorFooter();
        updateFieldChoiceState();
    }

    function startFieldSelection(trigger) {
        var selectedIds = selectedStoreIds();

        if (selectedIds.length === 0) {
            window.alert(translate('selectBeforeUpdate'));
            return false;
        }

        if (selectedIds.length > 20) {
            window.alert(translate('maxRecords'));
            return false;
        }

        resetModal();
        state.trigger = trigger;
        state.selectedIds = selectedIds;
        renderFieldSelector();

        return true;
    }

    function editorUrl(ids, fields) {
        var base = String(state.trigger.attr('data-batch-editor-url') || '');
        var separator = base.indexOf('?') === -1 ? '?' : '&';
        var selectedQuery = ids.map(function (id) {
            return 'selected_ids%5B%5D=' + encodeURIComponent(String(id));
        });
        var fieldQuery = fields.map(function (field) {
            return 'fields%5B%5D=' + encodeURIComponent(String(field));
        });

        return base + separator + selectedQuery.concat(fieldQuery).join('&');
    }

    function requestFailureMessage(xhr) {
        if (xhr.responseJSON && xhr.responseJSON.message) {
            return xhr.responseJSON.message;
        }

        if (window.console && typeof window.console.error === 'function') {
            window.console.error('Assistant batch editor load failed:', xhr.status, xhr.responseText);
        }

        return translate('loadFailed') + (xhr.status ? ' (HTTP ' + xhr.status + ')' : '');
    }

    function clearValidationErrors() {
        body.find('.assistant-batch-validation-error').remove();
        body.find('[data-batch-field]').removeClass('has-error');
        body.find('.assistant-batch-editor-alert').remove();
    }

    function multiValues(value) {
        var values = String(value || '').split(',').map(function (item) {
            return $.trim(item);
        }).filter(function (item) {
            return item !== '';
        });

        return values.filter(function (item, index) {
            return values.indexOf(item) === index;
        });
    }

    function syncPresetState(panel, field, isMulti) {
        var input = panel.find('[data-batch-input="' + field + '"]').first();

        if (!input.length) {
            return;
        }

        var values = isMulti ? multiValues(input.val()) : [String(input.val() || '')];
        panel.find('[data-batch-option-group="' + field + '"] [data-batch-option-value]').each(function () {
            $(this).toggleClass('is-selected', values.indexOf(String($(this).attr('data-batch-option-value') || '')) !== -1);
        });
    }

    function showValidationErrors(response) {
        var errors = response && response.errors ? response.errors : {};
        var summary = response && response.message ? response.message : translate('saveFailed');

        clearValidationErrors();
        Object.keys(errors).forEach(function (key) {
            var match = key.match(/^values\.([^\.]+)/);
            var message = Array.isArray(errors[key]) ? errors[key][0] : errors[key];

            if (!message) {
                return;
            }

            if (!match) {
                summary = message;
                return;
            }

            var field = match[1];
            var group = body.find('[data-batch-field="' + field + '"]').first();
            summary = message;

            if (group.length) {
                group.addClass('has-error');
                $('<span class="help-block assistant-batch-validation-error"></span>').text(message).appendTo(group);
            }
        });

        $('<div class="alert alert-danger assistant-batch-editor-alert" role="alert"></div>').text(summary).prependTo(body.find('.assistant-batch-edit-form'));
    }

    function setSaving(saving) {
        state.saving = saving;
        modal.toggleClass('is-saving', saving);
        footer.find('button').prop('disabled', saving);
        footer.find('[data-batch-editor-save]').html(saving
            ? '<i class="fa fa-spinner fa-spin"></i> ' + escapeHtml(translate('saving'))
            : '<i class="fa fa-save"></i> ' + escapeHtml(translate('saveAll')));
    }

    function refreshList() {
        return $.ajax({
            url: window.location.href,
            method: 'GET',
            dataType: 'html',
            cache: false,
        }).done(function (response) {
            var parsed = $('<div>').append($.parseHTML(response, document, false));
            var utility = parsed.find('.assistant-list-utility-bar').first();
            var list = parsed.find('.assistant-domain-stores-list').first();

            if (!utility.length || !list.length) {
                return;
            }

            $('.assistant-list-utility-bar').first().replaceWith(utility);
            $('.assistant-domain-stores-list').first().replaceWith(list);

            if (window.ErpAssistantDomainStores && typeof window.ErpAssistantDomainStores.refresh === 'function') {
                window.ErpAssistantDomainStores.refresh($('.assistant-list-utility-bar, .assistant-domain-stores-list'));
            }

            if (window.ErpAssistantDomainStoreColumnResize && typeof window.ErpAssistantDomainStoreColumnResize.initialize === 'function') {
                window.ErpAssistantDomainStoreColumnResize.initialize(document);
            }
        });
    }

    function renderEditorFooter() {
        footer.html(
            '<button type="button" class="btn btn-default" data-batch-editor-cancel>' + escapeHtml(translate('cancel')) + '</button>' +
            '<button type="button" class="btn btn-default" data-batch-editor-back><i class="fa fa-arrow-left"></i> ' + escapeHtml(translate('backToFields')) + '</button>' +
            '<button type="submit" form="assistant-batch-edit-form" class="btn btn-primary" data-batch-editor-save><i class="fa fa-save"></i> ' + escapeHtml(translate('saveAll')) + '</button>'
        );
    }

    function initializeEditor() {
        var form = body.find('[data-batch-edit-form]').first();

        if (!form.length) {
            body.html('<div class="alert alert-danger assistant-batch-editor-alert" role="alert">' + escapeHtml(translate('loadFailed')) + '</div>');
            footer.html('<button type="button" class="btn btn-default" data-batch-editor-back><i class="fa fa-arrow-left"></i> ' + escapeHtml(translate('backToFields')) + '</button>');
            return;
        }

        form.find('input[name="return_to"]').val(window.location.href);
        renderEditorFooter();

        form.on('input.assistantBatchEdit change.assistantBatchEdit', 'input, textarea, select', function () {
            state.dirty = true;

            var input = $(this);
            var field = String(input.attr('data-batch-input') || '');

            if (field !== '') {
                syncPresetState(input.closest('[data-batch-edit-form]'), field, field === 'team' || field === 'erp');
            }
        }).on('submit.assistantBatchEdit', function (event) {
            event.preventDefault();

            if (state.saving) {
                return;
            }

            clearValidationErrors();
            setSaving(true);
            $.ajax({
                url: form.attr('action'),
                method: 'POST',
                data: form.serialize(),
                dataType: 'json',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            }).done(function (response) {
                state.dirty = false;
                state.allowClose = true;
                modal.modal('hide');
                refreshList().always(function () {
                    notify(response && response.type ? response.type : 'success', response && response.message ? response.message : translate('successTitle'));
                });
            }).fail(function (xhr) {
                showValidationErrors(xhr.responseJSON || null);
            }).always(function () {
                setSaving(false);
            });
        });

        body.off('click.assistantBatchSingleOption', '[data-batch-single-option]')
            .on('click.assistantBatchSingleOption', '[data-batch-single-option]', function () {
                var button = $(this);
                var field = String(button.attr('data-batch-single-option') || '');
                var panel = button.closest('[data-batch-edit-form]');
                var input = panel.find('[data-batch-input="' + field + '"]').first();

                if (!input.length) {
                    return;
                }

                input.val(String(button.attr('data-batch-option-value') || '')).trigger('input');
            });

        body.off('click.assistantBatchMultiOption', '[data-batch-multi-option]')
            .on('click.assistantBatchMultiOption', '[data-batch-multi-option]', function () {
                var button = $(this);
                var field = String(button.attr('data-batch-multi-option') || '');
                var panel = button.closest('[data-batch-edit-form]');
                var input = panel.find('[data-batch-input="' + field + '"]').first();

                if (!input.length) {
                    return;
                }

                var value = String(button.attr('data-batch-option-value') || '');
                var values = multiValues(input.val());
                var index = values.indexOf(value);

                if (index === -1) {
                    values.push(value);
                } else {
                    values.splice(index, 1);
                }

                input.val(values.join(',')).trigger('input');
            });
    }

    function loadEditor() {
        if (state.selectedFields.length === 0) {
            window.alert(translate('chooseFields'));
            return;
        }

        state.requestVersion += 1;
        var requestVersion = state.requestVersion;
        body.html('<div class="assistant-batch-editor-loading"><i class="fa fa-spinner fa-spin"></i> ' + escapeHtml(translate('loading')) + '</div>');
        footer.empty();
        var request = $.ajax({
            url: editorUrl(state.selectedIds, state.selectedFields),
            method: 'GET',
            dataType: 'html',
            cache: false,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        }).done(function (response) {
            if (requestVersion !== state.requestVersion) {
                return;
            }

            body.html(response);
            initializeEditor();
            state.dirty = false;
        }).fail(function (xhr, status) {
            if (status === 'abort' || requestVersion !== state.requestVersion) {
                return;
            }

            body.html('<div class="alert alert-danger assistant-batch-editor-alert" role="alert">' + escapeHtml(requestFailureMessage(xhr)) + '</div>');
            footer.html('<button type="button" class="btn btn-default" data-batch-editor-back><i class="fa fa-arrow-left"></i> ' + escapeHtml(translate('backToFields')) + '</button>');
        }).always(function () {
            if (state.request === request) {
                state.request = null;
            }
        });
        state.request = request;
    }

    function backToFieldSelector() {
        if (state.saving || (state.dirty && !window.confirm(translate('discardChanges')))) {
            return;
        }

        var previousFields = state.selectedFields.slice();
        renderFieldSelector();
        previousFields.forEach(function (field) {
            body.find('[data-batch-field-choice][value="' + field + '"]').prop('checked', true);
        });
        updateFieldChoiceState();
        state.dirty = false;
    }

    $(function () {
        if (!modal.length) {
            return;
        }

        modal.on('show.bs.modal', function (event) {
            var trigger = $(event.relatedTarget);

            if (!trigger.length || !startFieldSelection(trigger)) {
                event.preventDefault();
            }
        }).on('hide.bs.modal', function (event) {
            if (state.allowClose) {
                return;
            }

            if (state.saving || (state.dirty && !window.confirm(translate('discardChanges')))) {
                event.preventDefault();
            }
        }).on('hidden.bs.modal', function () {
            resetModal();
            state.allowClose = false;
        });

        body.on('change', '[data-batch-field-choice]', updateFieldChoiceState);
        footer.on('click', '[data-batch-editor-cancel]', function () {
            modal.modal('hide');
        }).on('click', '[data-batch-editor-continue]', loadEditor)
            .on('click', '[data-batch-editor-back]', backToFieldSelector);
    });
})(jQuery);
